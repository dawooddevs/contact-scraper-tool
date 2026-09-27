<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Phone number clean-up. Keeps the human readable form for display and a
 * digits-only national form for duplicate detection.
 */
final class Phone
{
    /** Tidy a scraped phone number for display; null if it is not plausibly a phone number. */
    public static function clean(?string $phone): ?string
    {
        $phone = Str::squish(html_entity_decode((string) $phone, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $phone = preg_replace('~^(tel|phone|ph|call)[:.\s]*~i', '', $phone) ?? $phone;
        $phone = rawurldecode($phone);
        $phone = trim($phone, " \t\n\r\0\x0B,;/");
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 6 || strlen($digits) > 16) {
            return null;
        }
        // Only keep characters that belong in a phone number.
        $phone = preg_replace('/[^0-9+()\-.\s]/', '', $phone) ?? $phone;
        return Str::squish($phone) ?: null;
    }

    /**
     * National significant digits used for dedupe: strips country codes and
     * trunk prefixes so "+61 7 3123 4567" and "(07) 3123 4567" match.
     */
    public static function digits(?string $phone, ?string $country = null): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        $country = strtoupper((string) $country);
        if ($country === 'AU') {
            if (str_starts_with($digits, '61') && strlen($digits) >= 10) {
                $digits = substr($digits, 2);
            }
            $digits = ltrim($digits, '0');
        } elseif ($country === 'US' || $country === 'CA') {
            if (strlen($digits) === 11 && $digits[0] === '1') {
                $digits = substr($digits, 1);
            }
        } else {
            $digits = ltrim($digits, '0');
        }
        return $digits === '' ? null : substr($digits, -15);
    }
}
