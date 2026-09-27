<?php

declare(strict_types=1);

namespace App\Scraper;

use App\Core\Settings;
use App\Http\Fetcher;
use App\Http\HttpRequest;
use App\Http\HttpResponse;
use App\Sources\Listing;
use App\Support\Html;
use App\Support\Phone;
use App\Support\Url;

/**
 * Visits business websites to discover email addresses, social profiles and
 * phone numbers. Works on a batch of sites in parallel, in two waves:
 *   1. home pages
 *   2. the most promising internal pages (contact, about, ...)
 */
final class Enricher
{
    private const CONTACT_PATTERNS = [
        '~contact|kontakt|get-?in-?touch|reach-?us|enquir|inquir|talk-to-us~i' => 10,
        '~about|our-?team|team|our-?story|who-?we-?are|staff|people~i' => 6,
        '~location|find-?us|visit-?us|directions|hours~i' => 4,
        '~book|reserv|appointment|quote~i' => 3,
        '~privacy|terms|legal|impressum~i' => 2,
    ];

    private const SKIP_LINK = '~\.(pdf|jpe?g|png|gif|webp|svg|zip|docx?|xlsx?|mp4|mp3|mov|ics)(\?|$)|/wp-(admin|login|json)|/cart|/checkout|/account|/login|/signin|/feed|/tag/|/category/|/author/|/page/\d+|replytocom|add-to-cart|/cdn-cgi/~i';

    public function __construct(private readonly Fetcher $fetcher)
    {
    }

    /**
     * @param array<int, Listing> $leads lead id => listing (must have a website)
     * @return array<int, array{emails: string[], socials: array<string,string>, phones: string[], pages: int, error: ?string}>
     */
    public function run(array $leads, ?string $country, float $deadline): array
    {
        $pagesPerSite = Settings::int('enrich.pages_per_site', 1, 10);
        $concurrency = Settings::int('enrich.concurrency', 1, 20);
        $timeout = Settings::int('enrich.timeout', 5, 60);
        $stopWhenFound = Settings::bool('enrich.stop_when_found');

        $results = [];
        $requests = [];
        foreach ($leads as $id => $listing) {
            $results[$id] = ['emails' => [], 'socials' => [], 'phones' => [], 'pages' => 0, 'error' => null, 'candidates' => [], 'home' => $listing->website];
            if ($listing->website) {
                $requests[] = new HttpRequest($listing->website, 'website', [], null, $country, $id);
            }
        }

        // Wave 1: home pages.
        $retry = [];
        foreach ($this->fetcher->fetchMany($requests, $concurrency) as $response) {
            $id = $response->request->tag;
            if (!$this->usable($response)) {
                $alt = $this->alternateUrl($response->request->url);
                if ($alt !== null && $response->status === 0) {
                    $retry[] = $response->request->withUrl($alt);
                }
                $results[$id]['error'] = $response->error ?? ('HTTP ' . $response->status);
                continue;
            }
            $this->absorb($results[$id], $response);
            $results[$id]['home'] = $response->finalUrl;
            $results[$id]['candidates'] = $this->candidateLinks($response);
        }

        // Some sites only answer on http:// or without "www." — one retry.
        if ($retry && microtime(true) + $timeout < $deadline + 5) {
            foreach ($this->fetcher->fetchMany($retry, $concurrency) as $response) {
                $id = $response->request->tag;
                if ($this->usable($response)) {
                    $results[$id]['error'] = null;
                    $this->absorb($results[$id], $response);
                    $results[$id]['home'] = $response->finalUrl;
                    $results[$id]['candidates'] = $this->candidateLinks($response);
                }
            }
        }

        // Wave 2: contact/about pages for sites that still lack an email.
        $wave2 = [];
        if ($pagesPerSite > 1) {
            foreach ($results as $id => $r) {
                if ($r['error'] !== null || $r['pages'] === 0) {
                    continue;
                }
                if ($stopWhenFound && $r['emails']) {
                    continue;
                }
                $links = $r['candidates'];
                if ($links === []) {
                    foreach (['/contact', '/contact-us'] as $guess) {
                        $u = Url::resolve((string) $r['home'], $guess);
                        if ($u !== null) {
                            $links[] = $u;
                        }
                    }
                }
                foreach (array_slice($links, 0, $pagesPerSite - 1) as $link) {
                    $wave2[] = new HttpRequest($link, 'website', [], null, $country, $id);
                }
            }
        }
        if ($wave2 && microtime(true) + min($timeout, 8) < $deadline + 10) {
            foreach ($this->fetcher->fetchMany($wave2, $concurrency) as $response) {
                if ($this->usable($response)) {
                    $this->absorb($results[$response->request->tag], $response);
                }
            }
        }

        foreach ($results as $id => &$r) {
            $r['emails'] = EmailExtractor::rank($r['emails'], Url::domain($r['home'] ?? $leads[$id]->website));
            unset($r['candidates'], $r['home']);
        }
        return $results;
    }

    private function usable(HttpResponse $r): bool
    {
        return $r->error === null && $r->status >= 200 && $r->status < 400 && $r->body !== '' && $r->isHtml();
    }

    private function alternateUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return null;
        }
        $host = $parts['host'];
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
        $scheme = ($parts['scheme'] ?? 'http') === 'https' ? 'http' : 'https';
        return $scheme . '://' . $host . ($parts['path'] ?? '/');
    }

    /** Merge emails, socials and phones from one page into the result. */
    private function absorb(array &$result, HttpResponse $response): void
    {
        $html = Html::toUtf8($response->body, $response->header('content-type'));
        $result['pages']++;

        foreach (EmailExtractor::fromHtml($html) as $email) {
            if (!in_array($email, $result['emails'], true)) {
                $result['emails'][] = $email;
            }
        }

        if (preg_match_all('~href\s*=\s*["\']([^"\']+)["\']~i', $html, $m)) {
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (stripos($href, 'tel:') === 0) {
                    $phone = Phone::clean(rawurldecode(substr($href, 4)));
                    if ($phone !== null && !in_array($phone, $result['phones'], true)) {
                        $result['phones'][] = $phone;
                    }
                    continue;
                }
                $network = Url::socialNetwork($href);
                if ($network !== null && !isset($result['socials'][$network])) {
                    $profile = $this->socialProfile($href, $network);
                    if ($profile !== null) {
                        $result['socials'][$network] = $profile;
                    }
                }
            }
        }
    }

    /** Canonical profile URL, or null for share buttons, plugins and bare domains. */
    private function socialProfile(string $url, string $network): ?string
    {
        $url = Url::normalize($url);
        if ($url === null) {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $trimmed = trim($path, '/');
        $blockedSegment = '~/(sharer|share|intent|plugins|dialog|login|signup|hashtag|policies|privacy|help|legal|embed|search|explore|tr|l|home|watch|p|reel|status|posts|photos|events|groups|stories)(/|\.php|$)~i';
        if ($trimmed === '' || preg_match($blockedSegment, '/' . $trimmed . '/')) {
            return null;
        }
        if ($network === 'youtube' && !preg_match('~^(channel/|c/|user/|@)~', $trimmed)) {
            return null;
        }
        if ($network === 'linkedin' && !preg_match('~^(company|in|school)/~', $trimmed)) {
            return null;
        }
        $keepQuery = $network === 'facebook' && str_contains($path, 'profile.php');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $host = preg_replace('/^(m|mobile|web|business|l|lm)\./', 'www.', $host) ?? $host;
        return 'https://' . $host . rtrim($path, '/') . ($keepQuery && ($q = parse_url($url, PHP_URL_QUERY)) ? '?' . $q : '');
    }

    /**
     * Internal links most likely to contain contact details, best first.
     *
     * @return string[]
     */
    private function candidateLinks(HttpResponse $response): array
    {
        $base = $response->finalUrl;
        $baseDomain = Url::domain($base);
        if ($baseDomain === null) {
            return [];
        }
        $xp = Html::dom($response->body);
        $scored = [];
        foreach (Html::all($xp, '//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || $href[0] === '#' || preg_match('~^(mailto|tel|javascript|sms|whatsapp):~i', $href)) {
                continue;
            }
            $url = Url::resolve($base, $href);
            if ($url === null || Url::domain($url) !== $baseDomain || preg_match(self::SKIP_LINK, $url) || strlen($url) > 300) {
                continue;
            }
            $url = strtok($url, '#') ?: $url;
            if (rtrim($url, '/') === rtrim($base, '/')) {
                continue;
            }
            $haystack = strtolower((string) parse_url($url, PHP_URL_PATH) . ' ' . Html::text($a));
            $score = 0;
            foreach (self::CONTACT_PATTERNS as $pattern => $points) {
                if (preg_match($pattern, $haystack)) {
                    $score = max($score, $points);
                }
            }
            if ($score > 0 && (!isset($scored[$url]) || $scored[$url] < $score)) {
                $scored[$url] = $score;
            }
        }
        arsort($scored);
        return array_keys($scored);
    }
}
