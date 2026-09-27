<?php

declare(strict_types=1);

namespace App\Sources;

use App\Api\ApiException;
use App\Core\Settings;
use App\Http\HttpRequest;
use App\Http\HttpResponse;
use App\Scraper\CampaignOptions;
use App\Support\Html;
use App\Support\Str;
use App\Support\Url;

/**
 * Yelp (United States) — https://www.yelp.com
 *
 * Two discovery methods:
 *  - "website" (default): Yelp's own search JSON endpoint
 *    /search/snippet?find_desc=<what>&find_loc=<where>&start=<offset>
 *    (10 results per page, Yelp stops at offset 230). Results for logged-out
 *    visitors are sometimes returned "un-hydrated" (only id + alias), so we
 *    fetch the business page (/biz/<alias>) when details are missing.
 *  - "api": the official Yelp Places (Fusion) API with your API key
 *    (50 per page, max 240 results per search). The API does not return the
 *    business website, so the business page is still fetched when websites
 *    or emails are wanted.
 *
 * Yelp never shows email addresses; emails come from crawling the business
 * website (see Enricher).
 */
final class Yelp extends AbstractSource
{
    private const BASE = 'https://www.yelp.com';
    private const API = 'https://api.yelp.com/v3/businesses/search';

    public function key(): string
    {
        return 'yelp';
    }

    private function useApi(): bool
    {
        return $this->sourceSetting('mode', 'website') === 'api';
    }

    public function info(): array
    {
        $api = $this->useApi();
        return [
            'name' => 'Yelp',
            'country' => 'US',
            'domain' => 'yelp.com',
            'url' => self::BASE,
            'description' => 'The leading US local business directory. Provides phone, address, rating and the business website; emails are discovered by crawling the website.',
            'provides' => ['phone', 'website', 'address', 'rating'],
            'results_per_page' => $api ? 50 : 10,
            'max_pages' => $api ? 5 : 24,
            'location_hint' => 'City and state, e.g. "Austin, TX", or a ZIP code.',
            'location_placeholder' => 'New York, NY',
            'keyword_placeholder' => 'Restaurants',
            'notes' => 'Yelp is protected by DataDome. From shared hosting you will almost certainly need a scraping API with premium proxies (Settings → Connection), or the official Yelp API key (Sources → Yelp).',
            'modes' => [
                'website' => 'Yelp website (scraping)',
                'api' => 'Official Yelp Places API (API key)',
            ],
        ];
    }

    public function searchRequest(string $keyword, string $location, int $page): HttpRequest
    {
        if ($this->useApi()) {
            $key = trim((string) Settings::get('scrape.yelp_api_key', ''));
            if ($key === '') {
                throw new ApiException('Yelp is set to use the official API but no Yelp API key is saved (Sources → Yelp).', 422, [], 'config');
            }
            $query = [
                'term' => $keyword,
                'location' => $location,
                'limit' => 50,
                'offset' => ($page - 1) * 50,
                'sort_by' => 'best_match',
            ];
            return new HttpRequest(
                self::API . '?' . http_build_query($query),
                'api',
                ['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'],
                $this->key(),
                'US',
                null,
                30,
                true,
            );
        }

        $query = [
            'find_desc' => $keyword,
            'find_loc' => $location,
            'start' => ($page - 1) * 10,
            'request_origin' => 'user',
        ];
        $referer = self::BASE . '/search?' . http_build_query(['find_desc' => $keyword, 'find_loc' => $location]);
        return new HttpRequest(
            self::BASE . '/search/snippet?' . http_build_query($query),
            'directory',
            ['Referer' => $referer, 'X-Requested-With' => 'XMLHttpRequest'],
            $this->key(),
            'US',
            null,
            null,
            true,
        );
    }

    public function parseSearch(HttpResponse $response, int $page): array
    {
        $data = $response->json();
        if ($data === null && preg_match('~<pre[^>]*>(.*?)</pre>~is', $response->body, $pre)) {
            // Scraping APIs with JS rendering wrap JSON responses in a browser <pre> block.
            $decoded = json_decode(html_entity_decode($pre[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            $data = is_array($decoded) ? $decoded : null;
        }

        if (is_array($data) && isset($data['businesses']) && is_array($data['businesses'])) {
            return $this->parseApi($data, $page);
        }
        if (is_array($data) && isset($data['error']['description'])) {
            throw new ApiException('Yelp API: ' . $data['error']['description'], 422, [], 'config');
        }

        $documents = [];
        if (is_array($data)) {
            $documents[] = $data;
        } else {
            foreach (Html::jsonScripts($response->body) as $doc) {
                $documents[] = $doc;
            }
        }

        $listings = [];
        $recognized = false;
        $total = null;
        $seen = [];
        foreach ($documents as $doc) {
            $items = Html::findKey($doc, 'mainContentComponentsListProps');
            if (!is_array($items)) {
                continue;
            }
            $recognized = true;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $listing = $this->fromSnippetItem($item);
                if ($listing !== null && !isset($seen[$listing->ref])) {
                    $seen[$listing->ref] = true;
                    $listings[] = $listing;
                }
            }
            $pagination = Html::findKey($doc, 'paginationInfo') ?? Html::findKey($doc, 'pagination');
            if (is_array($pagination)) {
                $total = $this->pickInt($pagination, ['totalResults', 'total', 'resultsCount']);
            }
            if ($total === null) {
                $t = Html::findKey($doc, 'totalResults');
                $total = is_numeric($t) ? (int) $t : null;
            }
        }

        // Last resort: plain /biz/ links in server-rendered HTML.
        if ($listings === [] && !is_array($data) && $response->isHtml()) {
            $xp = Html::dom($response->body);
            foreach (Html::all($xp, "//a[starts-with(@href,'/biz/')]") as $a) {
                $alias = $this->aliasFromUrl($a->getAttribute('href'));
                $name = Html::text($a);
                if ($alias === null || isset($seen[$alias]) || $name === '' || mb_strlen($name) > 120 || preg_match('/^(more|read more|\d+ reviews?)$/i', $name)) {
                    continue;
                }
                $seen[$alias] = true;
                $recognized = true;
                $l = new Listing();
                $l->ref = $alias;
                $l->name = $name;
                $l->country = 'US';
                $l->sourceUrl = self::BASE . '/biz/' . $alias;
                $l->meta = ['alias' => $alias, 'hydrated' => false];
                $listings[] = $l;
            }
        }

        $cap = min($total ?? PHP_INT_MAX, 240);
        $start = ($page - 1) * 10;
        $hasMore = $listings !== [] && ($total !== null ? $start + 10 < $cap : count($listings) >= 8) && $start + 10 <= 230;

        return [
            'listings' => $listings,
            'has_more' => $hasMore,
            'total' => $total,
            'no_results' => $total === 0 || ($recognized && $listings === []),
            'recognized' => $recognized,
        ];
    }

    private function fromSnippetItem(array $item): ?Listing
    {
        $biz = is_array($item['searchResultBusiness'] ?? null) ? $item['searchResultBusiness'] : [];
        if (!empty($biz['isAd']) || !empty($item['isAd']) || isset($item['adLoggingInfo'])) {
            return null; // sponsored results repeat businesses from elsewhere
        }
        $id = Str::firstOf($item['bizId'] ?? null, $item['business_id'] ?? null, $item['businessId'] ?? null, $biz['bizId'] ?? null, $biz['id'] ?? null);
        $alias = Str::firstOf($biz['alias'] ?? null, $item['alias'] ?? null, $item['business_alias'] ?? null)
            ?? $this->aliasFromUrl((string) ($biz['businessUrl'] ?? $item['businessUrl'] ?? ''));
        if ($id === null && $alias === null) {
            return null;
        }

        $l = new Listing();
        $l->ref = $id ?? $alias;
        $l->country = 'US';
        $name = Str::clean($biz['name'] ?? $item['name'] ?? null);
        $l->name = $name ?? ($alias ? $this->nameFromAlias($alias) : '');
        $l->phone = Str::clean($biz['phone'] ?? $biz['displayPhone'] ?? null, 60);
        $address = $biz['formattedAddress'] ?? null;
        if ($address === null && isset($biz['addressLines']) && is_array($biz['addressLines'])) {
            $address = implode(', ', array_filter($biz['addressLines'], 'is_string'));
        }
        $l->address = Str::clean($address);
        $neighborhoods = $biz['neighborhoods'] ?? null;
        if (is_array($neighborhoods) && $neighborhoods && is_string($neighborhoods[0] ?? null)) {
            $l->city = Str::clean($neighborhoods[0], 120);
        }
        $l->rating = is_numeric($biz['rating'] ?? null) ? (float) $biz['rating'] : null;
        $l->reviews = is_numeric($biz['reviewCount'] ?? null) ? (int) $biz['reviewCount'] : null;
        if (isset($biz['categories']) && is_array($biz['categories'])) {
            $titles = [];
            foreach ($biz['categories'] as $cat) {
                $t = is_array($cat) ? ($cat['title'] ?? null) : $cat;
                if (is_string($t)) {
                    $titles[] = $t;
                }
            }
            $l->category = Str::clean(implode(', ', $titles), 190);
        }
        $website = $this->pick($biz, ['website.href', 'website.url', 'bizSiteUrl', 'website', 'businessWebsite.href', 'externalUrl']);
        if (is_string($website)) {
            $l->website = Url::unwrapRedirect(str_starts_with($website, '/') ? self::BASE . $website : $website);
        }
        $l->latitude = $this->pickFloat($biz, ['coordinates.latitude', 'latitude', 'location.latitude']);
        $l->longitude = $this->pickFloat($biz, ['coordinates.longitude', 'longitude', 'location.longitude']);
        if ($alias !== null) {
            $l->sourceUrl = self::BASE . '/biz/' . $alias;
        }
        $l->meta = ['alias' => $alias, 'hydrated' => $name !== null];
        return $l;
    }

    private function parseApi(array $data, int $page): array
    {
        $listings = [];
        foreach ($data['businesses'] as $b) {
            if (!is_array($b) || empty($b['id'])) {
                continue;
            }
            $l = new Listing();
            $l->ref = (string) $b['id'];
            $l->name = Str::clean($b['name'] ?? '') ?? '';
            $l->phone = Str::clean(($b['display_phone'] ?? '') ?: ($b['phone'] ?? null), 60);
            $loc = is_array($b['location'] ?? null) ? $b['location'] : [];
            $display = $loc['display_address'] ?? null;
            $l->address = Str::clean(is_array($display) ? implode(', ', $display) : ($loc['address1'] ?? null));
            $l->city = Str::clean($loc['city'] ?? null, 120);
            $l->state = Str::clean($loc['state'] ?? null, 60);
            $l->postcode = Str::clean($loc['zip_code'] ?? null, 20);
            $l->country = is_string($loc['country'] ?? null) ? $loc['country'] : 'US';
            $l->latitude = is_numeric($b['coordinates']['latitude'] ?? null) ? (float) $b['coordinates']['latitude'] : null;
            $l->longitude = is_numeric($b['coordinates']['longitude'] ?? null) ? (float) $b['coordinates']['longitude'] : null;
            $l->rating = is_numeric($b['rating'] ?? null) ? (float) $b['rating'] : null;
            $l->reviews = is_numeric($b['review_count'] ?? null) ? (int) $b['review_count'] : null;
            $titles = [];
            foreach ((array) ($b['categories'] ?? []) as $cat) {
                if (is_array($cat) && is_string($cat['title'] ?? null)) {
                    $titles[] = $cat['title'];
                }
            }
            $l->category = Str::clean(implode(', ', $titles), 190);
            $alias = is_string($b['alias'] ?? null) ? $b['alias'] : null;
            $l->sourceUrl = $alias ? self::BASE . '/biz/' . $alias : (is_string($b['url'] ?? null) ? strtok($b['url'], '?') : null);
            $l->meta = ['alias' => $alias, 'hydrated' => true];
            $listings[] = $l;
        }
        $total = is_numeric($data['total'] ?? null) ? (int) $data['total'] : null;
        $cap = min($total ?? 240, 240);
        return [
            'listings' => $listings,
            'has_more' => $listings !== [] && $page * 50 < $cap,
            'total' => $total,
            'no_results' => $listings === [],
            'recognized' => true,
        ];
    }

    private function aliasFromUrl(string $url): ?string
    {
        if (preg_match('~/biz/([^/?#"\s]+)~', $url, $m)) {
            return rawurldecode($m[1]);
        }
        return null;
    }

    private function nameFromAlias(string $alias): string
    {
        $name = preg_replace('/-\d+$/', '', $alias) ?? $alias;
        return ucwords(str_replace('-', ' ', $name));
    }

    public function needsDetail(Listing $listing, CampaignOptions $options): bool
    {
        if ($listing->sourceUrl === null) {
            return false;
        }
        if (empty($listing->meta['hydrated'])) {
            return true;
        }
        if (($options->wants('website') || $options->wants('email')) && !$listing->has('website')) {
            return true;
        }
        return $options->wants('phone') && !$listing->has('phone');
    }

    public function detailRequest(Listing $listing): ?HttpRequest
    {
        $alias = $listing->meta['alias'] ?? $this->aliasFromUrl((string) $listing->sourceUrl);
        if (!is_string($alias) || $alias === '') {
            return null;
        }
        return new HttpRequest(self::BASE . '/biz/' . rawurlencode($alias), 'directory', ['Referer' => self::BASE . '/search'], $this->key(), 'US');
    }

    public function parseDetail(HttpResponse $response, Listing $listing): Listing
    {
        $html = $response->body;
        $ld = $this->businessFromJsonLd($html);
        if ($ld !== null) {
            if (empty($listing->meta['hydrated']) && $ld->name !== '') {
                $listing->name = $ld->name; // replace the alias-derived placeholder
            }
            $ld->website = null;
            $listing->merge($ld);
        }

        if (!$listing->has('website')) {
            if (preg_match_all('~biz_redir\?(?:[^"\'\s>]*?&(?:amp;)?)?url=([^&"\'\s>\\\\]+)~i', $html, $m)) {
                foreach ($m[1] as $raw) {
                    $target = $raw;
                    for ($i = 0; $i < 3 && preg_match('/%[0-9a-f]{2}/i', $target); $i++) {
                        $target = rawurldecode($target);
                    }
                    $target = html_entity_decode($target, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (preg_match('~^https?://~i', $target) && !Url::isPlatform($target)) {
                        $listing->website = $target;
                        break;
                    }
                }
            }
        }
        if (!$listing->has('website')) {
            // "Business website" block: the link text is the bare domain.
            $xp = Html::dom($html);
            foreach (Html::all($xp, "//p[contains(., 'Business website')]/following-sibling::*[1]//a | //p[contains(., 'Business website')]/following-sibling::p//a") as $a) {
                $candidate = Url::normalize(Html::text($a)) ?? Url::unwrapRedirect($a->getAttribute('href'));
                if ($candidate && !Url::isPlatform($candidate)) {
                    $listing->website = $candidate;
                    break;
                }
            }
        }
        if (!$listing->has('phone')) {
            if (preg_match('~Phone number</p>\s*<p[^>]*>([^<]{7,30})</p>~i', $html, $m)) {
                $listing->phone = $m[1];
            } elseif (preg_match('~href=["\']tel:([^"\']+)["\']~i', $html, $m)) {
                $listing->phone = $m[1];
            }
        }
        if ($listing->name !== '') {
            $listing->meta['hydrated'] = true;
        }
        return $listing;
    }

    public function detailProvides(): array
    {
        return ['phone', 'website', 'address'];
    }
}
