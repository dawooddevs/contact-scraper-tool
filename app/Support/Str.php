<?php

declare(strict_types=1);

namespace App\Support;

final class Str
{
    /** Collapse all whitespace (including &nbsp;) to single spaces and trim. */
    public static function squish(?string $value): string
    {
        $value = str_replace(["\u{00A0}", "\u{200B}", "\u{FEFF}"], ' ', (string) $value);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** Decode HTML entities and squish; returns null for empty strings. */
    public static function clean(mixed $value, int $max = 255): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = self::squish(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $max);
    }

    public static function limit(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    public static function slug(string $value): string
    {
        $value = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value));
        return trim($value, '-');
    }

    /** First non-empty scalar among the arguments. */
    public static function firstOf(mixed ...$values): ?string
    {
        foreach ($values as $v) {
            if (is_scalar($v) && trim((string) $v) !== '') {
                return trim((string) $v);
            }
        }
        return null;
    }
}
