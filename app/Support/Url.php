<?php

declare(strict_types=1);

namespace App\Support;

/**
 * URL helpers: normalisation, resolution of relative links, host checks.
 */
final class Url
{
    /** Hosts that are social profiles / platforms rather than a business's own website. */
    public const SOCIAL_HOSTS = [
        'facebook.com' => 'facebook',
        'fb.com' => 'facebook',
        'fb.me' => 'facebook',
        'instagram.com' => 'instagram',
        'linkedin.com' => 'linkedin',
        'twitter.com' => 'twitter',
        'x.com' => 'twitter',
        'youtube.com' => 'youtube',
        'youtu.be' => 'youtube',
        'tiktok.com' => 'tiktok',
        'pinterest.com' => 'pinterest',
        'pinterest.com.au' => 'pinterest',
    ];

    /** Directory / aggregator hosts that should never be treated as a business website. */
    private const PLATFORM_HOSTS = [
        'yellowpages.com.au', 'yelp.com', 'yelp.com.au', 'google.com', 'goo.gl', 'maps.app.goo.gl',
        'truelocal.com.au', 'hotfrog.com.au', 'tripadvisor.com', 'tripadvisor.com.au', 'ubereats.com',
        'doordash.com', 'menulog.com.au', 'grubhub.com', 'opentable.com', 'opentable.com.au',
        'linktr.ee', 'wa.me', 'whatsapp.com', 'apple.com', 'bing.com',
    ];

    /** Turn "example.com/page" into "https://example.com/page"; returns null when not a web URL. */
    public static function normalize(?string $url): ?string
    {
        $url = trim(html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:') || str_starts_with($url, 'javascript:')) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        if (!preg_match('~^https?://~i', $url)) {
            if (!preg_match('~^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+(:\d+)?(/.*)?$~i', $url)) {
                return null;
            }
            $url = 'http://' . $url;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || !str_contains($parts['host'], '.')) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return $scheme . '://' . $host . $port . ($path === '' ? '/' : $path) . $query;
    }

    public static function host(?string $url): ?string
    {
        if (!$url) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** Host without a leading "www." — used for dedupe and email/domain matching. */
    public static function domain(?string $url): ?string
    {
        $host = self::host($url);
        if ($host === null) {
            return null;
        }
        return preg_replace('/^www\d?\./', '', $host);
    }

    private static function hostMatches(string $host, string $candidate): bool
    {
        return $host === $candidate || str_ends_with($host, '.' . $candidate);
    }

    /** Returns the social network name (facebook, instagram, ...) or null. */
    public static function socialNetwork(?string $url): ?string
    {
        $host = self::host($url);
        if ($host === null) {
            return null;
        }
        foreach (self::SOCIAL_HOSTS as $candidate => $network) {
            if (self::hostMatches($host, $candidate)) {
                return $network;
            }
        }
        return null;
    }

    public static function isPlatform(?string $url): bool
    {
        $host = self::host($url);
        if ($host === null) {
            return false;
        }
        foreach (self::PLATFORM_HOSTS as $candidate) {
            if (self::hostMatches($host, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /** Resolve a (possibly relative) href against a base URL. */
    public static function resolve(string $base, string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || $href[0] === '#') {
            return null;
        }
        $href = (string) preg_replace('/#.*$/s', '', $href);
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) {
            return preg_match('~^https?:~i', $href) ? $href : null;
        }
        $b = parse_url($base);
        if (!$b || empty($b['host'])) {
            return null;
        }
        $scheme = $b['scheme'] ?? 'https';
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }
        $origin = $scheme . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if ($href[0] === '/') {
            return $origin . self::removeDotSegments($href);
        }
        if ($href[0] === '?') {
            return $origin . ($b['path'] ?? '/') . $href;
        }
        $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/');
        return $origin . self::removeDotSegments($dir . $href);
    }

    private static function removeDotSegments(string $path): string
    {
        $query = '';
        if (($pos = strpos($path, '?')) !== false) {
            $query = substr($path, $pos);
            $path = substr($path, 0, $pos);
        }
        if (($pos = strpos($path, '#')) !== false) {
            $path = substr($path, 0, $pos);
        }
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($out);
            } elseif ($seg !== '.') {
                $out[] = $seg;
            }
        }
        $result = implode('/', $out);
        if ($result === '' || $result[0] !== '/') {
            $result = '/' . $result;
        }
        return $result . $query;
    }

    /** Strip tracking parameters and fragments so websites compare cleanly. */
    public static function clean(?string $url): ?string
    {
        $url = self::normalize($url);
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $params);
            foreach (array_keys($params) as $k) {
                if (preg_match('/^(utm_|fbclid|gclid|msclkid|mc_|ref$|referrer$|source$)/i', (string) $k)) {
                    unset($params[$k]);
                }
            }
            $query = $params ? '?' . http_build_query($params) : '';
        }
        $path = $parts['path'] ?? '/';
        return strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path . $query;
    }

    /**
     * Directories wrap outbound links in click trackers
     * (e.g. /biz_redir?url=https%3A%2F%2Fexample.com). Return the real target.
     */
    public static function unwrapRedirect(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        for ($i = 0; $i < 3; $i++) {
            $query = parse_url($url, PHP_URL_QUERY);
            if (!is_string($query) || !self::isPlatform($url)) {
                break;
            }
            parse_str($query, $params);
            $next = null;
            foreach (['url', 'u', 'target', 'redirect', 'redirectUrl', 'dest', 'destination', 'website'] as $key) {
                if (isset($params[$key]) && is_string($params[$key]) && preg_match('~^(https?:)?//|^www\.~i', $params[$key])) {
                    $next = $params[$key];
                    break;
                }
            }
            if ($next === null) {
                break;
            }
            $url = $next;
        }
        return $url;
    }

    /** True when the host resolves only to public (non-private, non-reserved) IP addresses. */
    public static function isPublicHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        $ips = function_exists('gethostbynamel') ? @gethostbynamel($host) : false;
        if (!$ips) {
            return true; // Let cURL report DNS failures itself.
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }
}
