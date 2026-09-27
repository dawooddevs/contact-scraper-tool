<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Recognises anti-bot walls (Cloudflare, DataDome, Akamai, Imperva,
 * PerimeterX, reCAPTCHA pages) and scraping-API account errors, so the
 * worker can back off and tell the user what to change.
 */
final class BlockDetector
{
    private const MARKERS = [
        'captcha-delivery.com' => 'DataDome bot protection',
        'datadome' => 'DataDome bot protection',
        '_Incapsula_Resource' => 'Imperva/Incapsula bot protection',
        'Incapsula incident' => 'Imperva/Incapsula bot protection',
        'Pardon Our Interruption' => 'Imperva bot protection',
        'cf-browser-verification' => 'Cloudflare challenge',
        'challenge-platform' => 'Cloudflare challenge',
        'cf-chl-' => 'Cloudflare challenge',
        '<title>Just a moment...</title>' => 'Cloudflare challenge',
        'Attention Required! | Cloudflare' => 'Cloudflare block',
        'px-captcha' => 'PerimeterX bot protection',
        '_pxAppId' => 'PerimeterX bot protection',
        '/_sec/cp_challenge' => 'Akamai bot protection',
        'sec-if-cpt-container' => 'Akamai bot protection',
        'Access Denied</title>' => 'Access denied by the website firewall',
        'errors.edgesuite.net' => 'Akamai access denied',
        'unusual traffic' => 'Rate limited (unusual traffic)',
        'are you a robot' => 'Robot check',
        'verify you are human' => 'Human verification challenge',
        'Request unsuccessful' => 'Imperva/Incapsula bot protection',
    ];

    public static function inspect(HttpResponse $r): ?string
    {
        if ($r->error !== null) {
            return null; // network errors are reported separately
        }
        $viaApi = $r->via !== 'direct' && $r->via !== 'proxy';

        // A recognisable bot wall wins, whether we got it directly or a
        // scraping API passed the target's response through.
        $head = substr($r->body, 0, 60000);
        foreach (self::MARKERS as $needle => $reason) {
            if (stripos($head, $needle) !== false) {
                // Big pages that merely mention a vendor (e.g. a script tag) are not walls
                // unless the status code says so too.
                if (strlen($r->body) < 150000 || $r->status >= 400) {
                    return $reason . ($r->status >= 400 ? " (HTTP {$r->status})" : '') . ($viaApi ? ' — the scraping API did not get past it' : '');
                }
            }
        }

        if ($viaApi) {
            return $r->status >= 400 ? self::apiError($r) : null;
        }
        if ($r->status === 429) {
            return 'Rate limited by the website (HTTP 429)';
        }
        if ($r->status === 403 || $r->status === 401) {
            return "Access denied (HTTP {$r->status})";
        }
        if ($r->status === 503 && strlen($r->body) < 20000) {
            return 'Service unavailable / challenge page (HTTP 503)';
        }
        if (strlen($r->body) < 30000 && stripos($head, 'g-recaptcha') !== false && stripos($head, '<form') !== false) {
            return 'reCAPTCHA challenge';
        }
        return null;
    }

    /** Explain scraping-API error responses. */
    public static function apiError(HttpResponse $r): ?string
    {
        $snippet = trim(strip_tags(substr($r->body, 0, 300)));
        $snippet = $snippet !== '' ? ': ' . preg_replace('/\s+/', ' ', $snippet) : '';
        return match (true) {
            $r->status === 401 => 'Scraping API rejected the API key (HTTP 401)' . $snippet,
            $r->status === 402 => 'Scraping API: out of credits / payment required (HTTP 402)' . $snippet,
            $r->status === 403 => 'Scraping API: access forbidden or out of credits (HTTP 403)' . $snippet,
            $r->status === 404 => null, // target page genuinely missing
            $r->status === 429 => 'Scraping API: concurrency or rate limit reached (HTTP 429)' . $snippet,
            $r->status >= 500 => 'Scraping API could not fetch the page (HTTP ' . $r->status . ')' . $snippet,
            $r->status >= 400 => 'Scraping API error (HTTP ' . $r->status . ')' . $snippet,
            default => null,
        };
    }
}
