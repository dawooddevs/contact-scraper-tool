<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * HTML parsing helpers built on DOMDocument, plus extraction of JSON that
 * sites embed in their pages (window.__STATE__ blobs, JSON-LD, ...).
 */
final class Html
{
    public static function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        if ($html === '') {
            $html = '<html></html>';
        }
        // Force UTF-8 interpretation regardless of meta tags.
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($doc);
    }

    /** Normalised visible text of a node. */
    public static function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        return Str::squish($node->textContent ?? '');
    }

    public static function first(DOMXPath $xp, string $query, ?DOMNode $context = null): ?DOMElement
    {
        $list = $context ? $xp->query($query, $context) : $xp->query($query);
        if ($list === false) {
            return null;
        }
        foreach ($list as $node) {
            if ($node instanceof DOMElement) {
                return $node;
            }
        }
        return null;
    }

    /** @return DOMElement[] */
    public static function all(DOMXPath $xp, string $query, ?DOMNode $context = null): array
    {
        $list = $context ? $xp->query($query, $context) : $xp->query($query);
        $out = [];
        if ($list !== false) {
            foreach ($list as $node) {
                if ($node instanceof DOMElement) {
                    $out[] = $node;
                }
            }
        }
        return $out;
    }

    /** XPath predicate that matches a CSS class token. */
    public static function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    /** Page <title>. */
    public static function title(DOMXPath $xp): string
    {
        return self::text(self::first($xp, '//title'));
    }

    /**
     * All JSON-LD blocks flattened into a list of objects (handles @graph and arrays).
     *
     * @return array<int, array>
     */
    public static function jsonLd(string $html): array
    {
        $out = [];
        if (!preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m)) {
            return $out;
        }
        foreach ($m[1] as $raw) {
            $raw = trim(html_entity_decode($raw, ENT_NOQUOTES | ENT_HTML5, 'UTF-8'));
            $raw = preg_replace('~^\s*<!--|-->\s*$~', '', $raw) ?? $raw;
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                // Some sites put raw newlines inside strings; try a lenient pass.
                $data = json_decode((string) preg_replace('/[\x00-\x1F]+/', ' ', $raw), true);
            }
            if (!is_array($data)) {
                continue;
            }
            $stack = array_is_list($data) ? $data : [$data];
            while ($stack) {
                $item = array_shift($stack);
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['@graph']) && is_array($item['@graph'])) {
                    foreach ($item['@graph'] as $g) {
                        $stack[] = $g;
                    }
                }
                if (array_is_list($item)) {
                    foreach ($item as $g) {
                        $stack[] = $g;
                    }
                    continue;
                }
                $out[] = $item;
            }
        }
        return $out;
    }

    /** True when a JSON-LD node's @type is (or includes) one of the given types. */
    public static function ldIsType(array $node, array $types): bool
    {
        $t = $node['@type'] ?? null;
        $list = is_array($t) ? $t : [$t];
        foreach ($list as $one) {
            if (is_string($one) && in_array(strtolower($one), array_map('strtolower', $types), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract the JSON value (object or array) that follows $marker in $html,
     * e.g. marker "window.__INITIAL_STATE__" for "window.__INITIAL_STATE__ = {...};".
     * Uses a string-aware bracket matcher, so it works on minified single-line scripts.
     */
    public static function jsonAfter(string $html, string $marker, int $offset = 0): ?array
    {
        $pos = strpos($html, $marker, $offset);
        if ($pos === false) {
            return null;
        }
        $start = strcspn($html, '{[', $pos + strlen($marker)) + $pos + strlen($marker);
        if ($start >= strlen($html)) {
            return null;
        }
        $json = self::balanced($html, $start);
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($data)) {
            // JS object literals may contain `undefined`; replace with null and retry.
            $data = json_decode((string) preg_replace('/(?<=[:\[,])\s*undefined\s*(?=[,}\]])/', 'null', $json), true, 512, JSON_BIGINT_AS_STRING);
        }
        return is_array($data) ? $data : null;
    }

    /** Return the balanced {...} or [...] substring starting at $start. */
    public static function balanced(string $s, int $start): ?string
    {
        $open = $s[$start] ?? '';
        if ($open !== '{' && $open !== '[') {
            return null;
        }
        $depth = 0;
        $inString = false;
        $quote = '';
        $len = strlen($s);
        for ($i = $start; $i < $len; $i++) {
            $c = $s[$i];
            if ($inString) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $inString = true;
                $quote = $c;
            } elseif ($c === '{' || $c === '[') {
                $depth++;
            } elseif ($c === '}' || $c === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }

    /**
     * JSON payloads inside <script type="application/json"> tags (optionally
     * wrapped in <!-- --> comments, as Yelp does).
     *
     * @return array<int, array>
     */
    public static function jsonScripts(string $html): array
    {
        $out = [];
        if (!preg_match_all('~<script[^>]+type=["\']application/json["\'][^>]*>(.*?)</script>~is', $html, $m)) {
            return $out;
        }
        foreach ($m[1] as $raw) {
            $raw = trim($raw);
            if (str_starts_with($raw, '<!--')) {
                $raw = trim(substr($raw, 4));
                if (str_ends_with($raw, '-->')) {
                    $raw = trim(substr($raw, 0, -3));
                }
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $data = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            }
            if (is_array($data)) {
                $out[] = $data;
            }
        }
        return $out;
    }

    /** Depth-first search for the first value stored under $key anywhere in $data. */
    public static function findKey(array $data, string $key, int $maxDepth = 12): mixed
    {
        if ($maxDepth < 0) {
            return null;
        }
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = self::findKey($value, $key, $maxDepth - 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /** Convert the body to UTF-8 using the Content-Type header or <meta charset>. */
    public static function toUtf8(string $body, string $contentType = ''): string
    {
        $charset = null;
        if (preg_match('/charset=["\']?([\w-]+)/i', $contentType, $m)) {
            $charset = $m[1];
        } elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($body, 0, 4096), $m)) {
            $charset = $m[1];
        }
        $charset = $charset ? strtoupper($charset) : null;
        if ($charset && $charset !== 'UTF-8' && $charset !== 'UTF8') {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            $converted = @mb_convert_encoding($body, 'UTF-8', 'Windows-1252');
            if (is_string($converted)) {
                return $converted;
            }
        }
        return $body;
    }
}
