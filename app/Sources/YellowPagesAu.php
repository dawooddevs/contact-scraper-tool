<?php

declare(strict_types=1);

namespace App\Sources;

use App\Http\HttpRequest;
use App\Http\HttpResponse;
use App\Scraper\CampaignOptions;
use App\Support\Html;
use App\Support\Phone;
use App\Support\Str;
use App\Support\Url;

/**
 * Yellow Pages Australia — https://www.yellowpages.com.au
 *
 * Search URL: /search/listings?clue=<what>&locationClue=<where>&pageNumber=<n>
 *
 * The results page is a React app that ships its data in
 * `window.__INITIAL_STATE__`. Listings live in `inAreaResultViews`; each has
 * name, contact number, primary email, website and address. We parse that
 * JSON first, and fall back to DOM (tel:/mailto: links) and JSON-LD, so a
 * markup change degrades gracefully instead of silently returning nothing.
 */
final class YellowPagesAu extends AbstractSource
{
    private const BASE = 'https://www.yellowpages.com.au';

    /** Keys that mark a JSON object as a business listing. */
    private const SIGNALS = [
        'listingId', 'callContactNumber', 'primaryEmail', 'primaryAddress', 'detailsLink', 'website', 'contactNumber',
        'primaryContactNumber', 'emailAddress',
    ];

    public function key(): string
    {
        return 'yellowpages_au';
    }

    public function info(): array
    {
        return [
            'name' => 'Yellow Pages Australia',
            'country' => 'AU',
            'domain' => 'yellowpages.com.au',
            'url' => self::BASE,
            'description' => "Australia's largest business directory. Listings usually include a phone number and website, and often a business email address.",
            'provides' => ['phone', 'email', 'website', 'address', 'rating'],
            'results_per_page' => 25,
            'max_pages' => 30,
            'location_hint' => 'Suburb or city plus state, e.g. "Brisbane QLD" or "Fortitude Valley QLD 4006".',
            'location_placeholder' => 'Brisbane QLD',
            'keyword_placeholder' => 'Restaurants',
            'notes' => 'Yellow Pages uses bot protection. If pages come back blocked, switch the connection to a scraping API in Settings → Connection.',
        ];
    }

    public function searchRequest(string $keyword, string $location, int $page): HttpRequest
    {
        $query = ['clue' => $keyword, 'locationClue' => $location];
        if ($page > 1) {
            $query['pageNumber'] = $page;
            $query['referredBy'] = 'www.yellowpages.com.au';
            $query['eventType'] = 'pagination';
        }
        return new HttpRequest(
            self::BASE . '/search/listings?' . http_build_query($query),
            'directory',
            ['Referer' => self::BASE . '/'],
            $this->key(),
            'AU',
        );
    }

    public function parseSearch(HttpResponse $response, int $page): array
    {
        $html = $response->body;
        $listings = [];
        $recognized = false;
        $total = null;
        $hasMore = null;

        $state = Html::jsonAfter($html, 'window.__INITIAL_STATE__') ?? Html::jsonAfter($html, '__INITIAL_STATE__');
        if ($state !== null) {
            $recognized = true;
            $rows = Html::findKey($state, 'inAreaResultViews');
            if (!is_array($rows) || $rows === []) {
                $rows = $this->findListingArrays($state, self::SIGNALS);
            }
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $listing = $this->fromJson($row);
                    if ($listing->name !== '') {
                        $listings[] = $listing;
                    }
                }
            }
            [$total, $hasMore] = $this->pagination($state, $page, count($listings));
        }

        if ($listings === []) {
            $fromDom = $this->parseDom($html);
            if ($fromDom !== []) {
                $recognized = true;
                $listings = $fromDom;
            }
        }

        if ($listings === []) {
            foreach (Html::jsonLd($html) as $node) {
                if (Html::ldIsType($node, ['ItemList']) && is_array($node['itemListElement'] ?? null)) {
                    $recognized = true;
                    foreach ($node['itemListElement'] as $el) {
                        $item = is_array($el['item'] ?? null) ? $el['item'] : $el;
                        if (is_array($item) && !empty($item['name'])) {
                            $l = $this->listingFromJsonLd($item);
                            $l->sourceUrl = isset($item['url']) && is_string($item['url']) ? $item['url'] : null;
                            $l->website = null;
                            $l->country = 'AU';
                            $listings[] = $l;
                        }
                    }
                }
            }
        }

        $noResults = $total === 0 || ($listings === [] && preg_match(
            '/(we couldn.t find any|no results found|did not match any|0 results for|sorry, we couldn.t find)/i',
            strip_tags(substr($html, 0, 400000))
        ) === 1);
        if ($noResults) {
            $recognized = true;
        }

        if ($hasMore === null) {
            $linksToNextPage = preg_match('/pageNumber(=|%3D|\\\\u003d)' . ($page + 1) . '\b/i', $html) === 1;
            $hasMore = !$noResults && ($linksToNextPage || count($listings) >= 20);
        }

        return [
            'listings' => $listings,
            'has_more' => (bool) $hasMore && $listings !== [],
            'total' => $total,
            'no_results' => $noResults,
            'recognized' => $recognized,
        ];
    }

    /** @return array{0: ?int, 1: ?bool} */
    private function pagination(array $state, int $page, int $count): array
    {
        $total = null;
        foreach (['totalResults', 'totalListings', 'resultCount', 'searchResultsCount', 'numberOfResults'] as $key) {
            $v = Html::findKey($state, $key);
            if (is_numeric($v)) {
                $total = (int) $v;
                break;
            }
        }
        $hasMore = null;
        $p = Html::findKey($state, 'pagination');
        if (is_array($p)) {
            $current = $this->pickInt($p, ['currentPage', 'pageNumber', 'page']) ?? $page;
            $last = $this->pickInt($p, ['lastPage', 'totalPages', 'numberOfPages', 'pageCount', 'lastPageNumber']);
            if ($last !== null) {
                $hasMore = $current < $last;
            } elseif (isset($p['nextPage']) || isset($p['nextPageNumber'])) {
                $hasMore = !empty($p['nextPage'] ?? $p['nextPageNumber']);
            }
            if ($total === null) {
                $total = $this->pickInt($p, ['totalResults', 'total', 'totalItems']);
            }
        }
        if ($hasMore === null && $total !== null && $count > 0) {
            $hasMore = $page * 25 < $total;
        }
        return [$total, $hasMore];
    }

    private function fromJson(array $row): Listing
    {
        $l = new Listing();
        $l->country = 'AU';
        $l->ref = (string) ($this->pickString($row, ['listingId', 'id', 'uniqueId', 'listingID'], 190) ?? '');
        $l->name = $this->pickString($row, ['name', 'businessName', 'displayName']) ?? '';

        $l->phone = $this->pickString($row, [
            'callContactNumber.displayValue', 'callContactNumber.value',
            'primaryContactNumber.displayValue', 'primaryContactNumber.value',
            'contactNumber.displayValue', 'contactNumber.value', 'contactNumber',
            'phoneNumber', 'phone', 'telephone',
        ], 60);
        if ($l->phone === null) {
            $phones = $this->deepStrings($row, '/phone|contactnumber|telephone|mobile/i', static fn ($v) => Phone::clean($v) !== null);
            $l->phone = $phones[0] ?? null;
        }

        $email = $this->pickString($row, ['primaryEmail', 'email', 'emailAddress', 'contactEmail', 'primaryEmailAddress'], 190);
        if ($email !== null) {
            $l->emails[] = $email;
        }
        foreach ($this->deepStrings($row, '/e-?mail/i', static fn ($v) => str_contains($v, '@')) as $e) {
            $l->emails[] = $e;
        }

        $website = $this->pickString($row, ['website', 'websiteUrl', 'websiteLink', 'primaryWebsite', 'website.url', 'urls.website', 'webSite', 'primaryWebsiteUrl'], 500);
        if ($website === null) {
            $sites = $this->deepStrings($row, '/web(site|url|link)|homepage/i', static fn ($v) => (bool) preg_match('~^(https?://|www\.)~i', $v));
            $website = $sites[0] ?? null;
        }
        $l->website = Url::unwrapRedirect($website);

        $addr = $row['primaryAddress'] ?? $row['address'] ?? null;
        if (is_string($addr)) {
            $l->address = Str::clean($addr);
        }
        $l->address ??= $this->pickString($row, [
            'primaryAddress.addressLine', 'primaryAddress.streetAddress', 'primaryAddress.addressLine1',
            'address.addressLine', 'address.streetAddress', 'addressLine', 'streetAddress',
        ]);
        $l->city = $this->pickString($row, ['primaryAddress.suburb', 'primaryAddress.locality', 'address.suburb', 'suburb', 'locality'], 120);
        $l->state = $this->pickString($row, ['primaryAddress.state', 'address.state', 'state'], 60);
        $l->postcode = $this->pickString($row, ['primaryAddress.postCode', 'primaryAddress.postcode', 'address.postCode', 'postCode', 'postcode'], 20);
        $l->latitude = $this->pickFloat($row, ['primaryAddress.latitude', 'primaryAddress.geoLocation.latitude', 'latitude', 'geo.latitude', 'location.latitude']);
        $l->longitude = $this->pickFloat($row, ['primaryAddress.longitude', 'primaryAddress.geoLocation.longitude', 'longitude', 'geo.longitude', 'location.longitude']);

        $l->category = $this->pickString($row, ['category.name', 'categoryName', 'primaryCategory.name', 'primaryCategory', 'heading', 'classificationName', 'categories.0.name'], 190);
        $l->rating = $this->pickFloat($row, ['averageRating', 'rating.average', 'ratings.averageRating', 'reviewSummary.averageRating', 'rating', 'reviewRating']);
        $l->reviews = $this->pickInt($row, ['numberOfReviews', 'reviewCount', 'ratings.totalReviews', 'reviewSummary.totalReviews', 'totalReviews']);

        $link = $this->pickString($row, ['detailsLink', 'detailsUrl', 'detailLink', 'listingUrl', 'url', 'link'], 500);
        if ($link !== null) {
            $l->sourceUrl = Url::resolve(self::BASE . '/', $link);
        }
        return $l;
    }

    /** Fallback for server-rendered markup: one card per link to a "-listing.html" page. */
    private function parseDom(string $html): array
    {
        $xp = Html::dom($html);
        $links = Html::all($xp, "//a[contains(@href,'-listing.html') or contains(@href,'/listing/') or " . Html::hasClass('listing-name') . ']');
        $seen = [];
        $out = [];
        foreach ($links as $a) {
            $href = $a->getAttribute('href');
            $abs = Url::resolve(self::BASE . '/', $href);
            if ($abs === null || isset($seen[$abs])) {
                continue;
            }
            $seen[$abs] = true;
            $name = Html::text($a);
            if ($name === '' || mb_strlen($name) > 150 || preg_match('/^(more info|view|details|read more)$/i', $name)) {
                continue;
            }

            // Climb to the smallest ancestor that holds contact details for this listing only.
            $card = $a;
            for ($i = 0; $i < 8 && $card->parentNode instanceof \DOMElement; $i++) {
                $card = $card->parentNode;
                $hasContact = $xp->query(".//a[starts-with(@href,'tel:') or starts-with(@href,'mailto:') or @data-email]", $card)->length > 0;
                $otherListings = 0;
                foreach (Html::all($xp, ".//a[contains(@href,'-listing.html') or contains(@href,'/listing/')]", $card) as $other) {
                    if (Url::resolve(self::BASE . '/', $other->getAttribute('href')) !== $abs) {
                        $otherListings++;
                    }
                }
                if ($otherListings > 0) {
                    $card = $a;
                    break;
                }
                if ($hasContact) {
                    break;
                }
            }

            $l = new Listing();
            $l->country = 'AU';
            $l->name = $name;
            $l->sourceUrl = $abs;
            if (preg_match('/-(\d{5,})-listing\.html/', $abs, $m)) {
                $l->ref = $m[1];
            }
            $tel = Html::first($xp, ".//a[starts-with(@href,'tel:')]", $card);
            if ($tel) {
                $l->phone = substr($tel->getAttribute('href'), 4);
            }
            $cardHtml = $card->ownerDocument?->saveHTML($card) ?: '';
            $l->emails = $this->emailsFromHtml($cardHtml);
            foreach (Html::all($xp, './/a[@href]', $card) as $link) {
                $target = Url::unwrapRedirect($link->getAttribute('href'));
                $class = strtolower($link->getAttribute('class') . ' ' . Html::text($link));
                if ($target && preg_match('~^https?://~i', $target) && !Url::isPlatform($target)
                    && (str_contains($class, 'website') || str_contains($class, 'contact-url') || str_contains($class, 'web'))) {
                    $l->website = $target;
                    break;
                }
            }
            $addr = Html::first($xp, ".//*[contains(@class,'address') or contains(@class,'Address')]", $card);
            if ($addr) {
                $l->address = Html::text($addr);
            }
            $out[] = $l;
        }
        return $out;
    }

    public function needsDetail(Listing $listing, CampaignOptions $options): bool
    {
        // Search results already carry phone/email/website. Only listings that
        // came back without any contact details are worth a detail request.
        return $listing->sourceUrl !== null
            && !$listing->has('phone') && !$listing->has('email') && !$listing->has('website')
            && ($options->wants('phone') || $options->wants('email') || $options->wants('website'));
    }

    public function detailRequest(Listing $listing): ?HttpRequest
    {
        if ($listing->sourceUrl === null || Url::host($listing->sourceUrl) === null || !str_contains((string) Url::host($listing->sourceUrl), 'yellowpages.com.au')) {
            return null;
        }
        return new HttpRequest($listing->sourceUrl, 'directory', ['Referer' => self::BASE . '/search/listings'], $this->key(), 'AU');
    }

    public function parseDetail(HttpResponse $response, Listing $listing): Listing
    {
        $html = $response->body;
        $state = Html::jsonAfter($html, 'window.__INITIAL_STATE__') ?? Html::jsonAfter($html, '__INITIAL_STATE__');
        if ($state !== null) {
            $best = null;
            $bestScore = 0;
            $walk = function (array $node, int $depth) use (&$walk, &$best, &$bestScore): void {
                if ($depth < 0) {
                    return;
                }
                if (!array_is_list($node) && $this->looksLikeListing($node, self::SIGNALS)) {
                    $score = count(array_intersect(array_keys($node), self::SIGNALS));
                    if ($score > $bestScore) {
                        $best = $node;
                        $bestScore = $score;
                    }
                }
                foreach ($node as $v) {
                    if (is_array($v)) {
                        $walk($v, $depth - 1);
                    }
                }
            };
            $walk($state, 12);
            if ($best !== null) {
                $listing->merge($this->fromJson($best));
            }
        }
        $ld = $this->businessFromJsonLd($html);
        if ($ld !== null) {
            $ld->website = null; // JSON-LD "url" on YP points at the listing itself
            $listing->merge($ld);
        }
        foreach ($this->emailsFromHtml($html) as $email) {
            // Skip the directory's own addresses (footer, advertiser links, ...).
            if (!preg_match('/@([a-z0-9.-]+\.)?(yellowpages|sensis|thryv|whitepages)\./i', $email)) {
                $listing->emails[] = $email;
            }
        }
        if (!$listing->has('phone') && preg_match('~href=["\']tel:([^"\']+)["\']~i', $html, $m)) {
            $listing->phone = $m[1];
        }
        if (!$listing->has('website')) {
            $xp = Html::dom($html);
            foreach (Html::all($xp, "//a[contains(@class,'contact-url') or contains(@class,'website') or contains(translate(., 'WEBSIT', 'websit'), 'website')]") as $a) {
                $target = Url::unwrapRedirect($a->getAttribute('href'));
                if ($target && preg_match('~^https?://~i', $target) && !Url::isPlatform($target)) {
                    $listing->website = $target;
                    break;
                }
            }
        }
        return $listing;
    }

    public function detailProvides(): array
    {
        return ['phone', 'email', 'website', 'address'];
    }
}
