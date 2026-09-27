<?php

declare(strict_types=1);

namespace App\Scraper;

/**
 * Finds and validates email addresses in HTML: mailto links, data
 * attributes, Cloudflare-protected addresses, JSON-LD and plain text
 * (including "name [at] domain [dot] com" obfuscation). Filters out the
 * junk that naive regexes pick up (image names, tracking IDs, placeholders).
 */
final class EmailExtractor
{
    private const PATTERN = '/[a-z0-9](?:[a-z0-9._%+\-]{0,62}[a-z0-9_])?@(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}(?![a-z0-9\-])/i';

    private const BLOCKED_DOMAINS = [
        'example.com', 'example.org', 'example.net', 'domain.com', 'yourdomain.com', 'yoursite.com', 'mysite.com',
        'website.com', 'yourwebsite.com', 'email.com', 'company.com', 'yourcompany.com', 'site.com', 'sample.com',
        'test.com', 'address.com', 'e-mail.com', 'placeholder.com', 'sentry.io', 'wixpress.com', 'sentry-next.wixpress.com',
        'latofonts.com', 'mystore.com', 'godaddy.com', 'email.tst', 'localhost', 'yourbusiness.com', 'businessname.com',
        'change.me', 'xxx.com', 'mail.example', 'companyname.com', 'domainname.com',
    ];

    private const BLOCKED_LOCALS = [
        'noreply', 'no-reply', 'no_reply', 'donotreply', 'do-not-reply', 'example', 'test', 'user', 'username',
        'name', 'email', 'your', 'yourname', 'youremail', 'your-email', 'your.name', 'firstname', 'first.last',
        'firstname.lastname', 'john.doe', 'jane.doe', 'johndoe', 'janedoe', 'someone', 'emailaddress', 'mailer-daemon',
        'postmaster', 'abuse', 'hostmaster', 'wordpress', 'changeme',
    ];

    private const FILE_TLDS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tif', 'tiff', 'css', 'js', 'mp4', 'mov', 'pdf', 'avif', 'heic', 'woff', 'woff2', 'ttf',
    ];

    public const FREE_PROVIDERS = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.com.au', 'yahoo.co.uk', 'ymail.com', 'rocketmail.com',
        'hotmail.com', 'hotmail.com.au', 'hotmail.co.uk', 'outlook.com', 'outlook.com.au', 'live.com', 'live.com.au',
        'msn.com', 'aol.com', 'icloud.com', 'me.com', 'mac.com', 'bigpond.com', 'bigpond.net.au', 'bigpond.com.au',
        'optusnet.com.au', 'iinet.net.au', 'tpg.com.au', 'internode.on.net', 'westnet.com.au', 'dodo.com.au',
        'gmx.com', 'gmx.net', 'protonmail.com', 'proton.me', 'zoho.com', 'mail.com', 'yandex.com', 'comcast.net',
        'verizon.net', 'att.net', 'sbcglobal.net', 'cox.net', 'charter.net', 'bellsouth.net', 'earthlink.net',
    ];

    private const ROLE_PREFIXES = [
        'info', 'contact', 'hello', 'enquiries', 'enquiry', 'inquiries', 'inquiry', 'admin', 'office', 'sales',
        'bookings', 'booking', 'reservations', 'reception', 'mail', 'team', 'support', 'service', 'accounts', 'studio',
    ];

    /** Validate and canonicalise one address; null when it is junk. */
    public static function normalize(string $email): ?string
    {
        $email = trim(html_entity_decode($email, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $email = preg_replace('/^mailto:/i', '', $email) ?? $email;
        $email = rawurldecode(explode('?', $email, 2)[0]);
        $email = strtolower(trim($email, " \t\n\r\0\x0B.,;:<>()[]{}\"'"));

        if (strlen($email) > 190 || substr_count($email, '@') !== 1) {
            return null;
        }
        if (!preg_match(self::PATTERN, $email, $m) || $m[0] !== $email) {
            return null;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        [$local, $domain] = explode('@', $email);
        $tld = substr((string) strrchr($domain, '.'), 1);
        if (in_array($tld, self::FILE_TLDS, true)) {
            return null;
        }
        if (in_array($local, self::BLOCKED_LOCALS, true)) {
            return null;
        }
        foreach (self::BLOCKED_DOMAINS as $blocked) {
            if ($domain === $blocked || str_ends_with($domain, '.' . $blocked)) {
                return null;
            }
        }
        // Tracking IDs / hashes (e.g. Sentry DSNs) are not contact addresses.
        if (preg_match('/^[a-f0-9]{20,}$/', $local) || preg_match('/^\d+x$/', $local) || str_contains($email, '..')) {
            return null;
        }
        if (preg_match('/(^|\.)(png|jpg|jpeg|gif|webp|svg)@/', $email)) {
            return null;
        }
        return $email;
    }

    /**
     * @param bool $includeText also scan visible text/scripts (not just links)
     * @return string[] unique valid addresses in discovery order
     */
    public static function fromHtml(string $html, bool $includeText = true): array
    {
        $found = [];

        // mailto: links (href may be entity- or percent-encoded)
        if (preg_match_all('~mailto:([^"\'\s>]+)~i', $html, $m)) {
            foreach ($m[1] as $raw) {
                $found[] = $raw;
            }
        }
        // data-email="...", data-mail="..."
        if (preg_match_all('~data-(?:email|mail|e-mail)\s*=\s*["\']([^"\']+)["\']~i', $html, $m)) {
            foreach ($m[1] as $raw) {
                $found[] = $raw;
            }
        }
        // Cloudflare email obfuscation
        if (preg_match_all('~data-cfemail\s*=\s*["\']([a-f0-9]+)["\']~i', $html, $m)) {
            foreach ($m[1] as $hex) {
                $found[] = self::decodeCloudflare($hex);
            }
        }
        if (preg_match_all('~/cdn-cgi/l/email-protection#([a-f0-9]+)~i', $html, $m)) {
            foreach ($m[1] as $hex) {
                $found[] = self::decodeCloudflare($hex);
            }
        }

        if ($includeText) {
            $text = self::deobfuscate($html);
            if (preg_match_all(self::PATTERN, $text, $m)) {
                foreach ($m[0] as $raw) {
                    $found[] = $raw;
                }
            }
        }

        $out = [];
        foreach ($found as $raw) {
            $email = self::normalize((string) $raw);
            if ($email !== null && !in_array($email, $out, true)) {
                $out[] = $email;
            }
        }
        return $out;
    }

    /** Undo common encodings so the plain-text regex can see addresses. */
    private static function deobfuscate(string $html): string
    {
        // JSON unicode escapes (@ = @, . = .)
        $text = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static function ($m) {
            $code = hexdec($m[1]);
            return $code < 128 ? chr($code) : ' ';
        }, $html) ?? $html;
        $text = str_ireplace(['%40', '%20', '&#64;', '&#x40;', '&commat;', '&#46;', '&#x2e;', '&period;'], ['@', ' ', '@', '@', '@', '.', '.', '.'], $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Separate tags from text so "a</b>info@x.com" doesn't glue together.
        $text = str_replace(['<', '>'], [' <', '> '], $text);
        // name [at] domain [dot] com / name (at) domain (dot) com / name{at}domain{dot}com
        $text = preg_replace('/\s*[\[\(\{]\s*at\s*[\]\)\}]\s*/i', '@', $text) ?? $text;
        $text = preg_replace('/\s*[\[\(\{]\s*dot\s*[\]\)\}]\s*/i', '.', $text) ?? $text;
        return $text;
    }

    public static function decodeCloudflare(string $hex): string
    {
        if (strlen($hex) < 4 || strlen($hex) % 2 !== 0) {
            return '';
        }
        $key = hexdec(substr($hex, 0, 2));
        $out = '';
        for ($i = 2; $i < strlen($hex); $i += 2) {
            $out .= chr(hexdec(substr($hex, $i, 2)) ^ $key);
        }
        return $out;
    }

    public static function isFreeProvider(string $email): bool
    {
        $domain = strtolower(substr((string) strrchr($email, '@'), 1));
        return in_array($domain, self::FREE_PROVIDERS, true);
    }

    /**
     * Best first: addresses on the business's own domain, then role
     * addresses (info@, contact@...), then everything else.
     *
     * @param string[] $emails
     * @return string[]
     */
    public static function rank(array $emails, ?string $websiteDomain): array
    {
        $websiteDomain = $websiteDomain ? preg_replace('/^www\d?\./', '', strtolower($websiteDomain)) : null;
        $scored = [];
        foreach (array_values(array_unique($emails)) as $i => $email) {
            [$local, $domain] = explode('@', $email) + [1 => ''];
            $score = 0;
            if ($websiteDomain && ($domain === $websiteDomain || str_ends_with($domain, '.' . $websiteDomain) || str_ends_with($websiteDomain, '.' . $domain))) {
                $score += 50;
            }
            foreach (self::ROLE_PREFIXES as $role) {
                if ($local === $role || str_starts_with($local, $role . '.') || str_starts_with($local, $role . '_')) {
                    $score += 20;
                    break;
                }
            }
            if (self::isFreeProvider($email)) {
                $score += 5;
            }
            $scored[] = [$score, $i, $email];
        }
        usort($scored, static fn ($a, $b) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);
        return array_column($scored, 2);
    }
}
