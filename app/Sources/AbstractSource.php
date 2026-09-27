<?php

declare(strict_types=1);

namespace App\Sources;

use App\Core\Settings;
use App\Http\HttpRequest;
use App\Http\HttpResponse;
use App\Scraper\CampaignOptions;
use App\Scraper\EmailExtractor;
use App\Support\Html;
use App\Support\Str;
use App\Support\Url;

/**
 * Shared helpers for sources: tolerant field picking from JSON blobs,
 * JSON-LD business parsing and generic "find the listing array" logic so
 * small markup changes on the target site don't break the scraper.
 */
abstract class AbstractSource implements Source
{
    public function needsDetail(Listing $listing, CampaignOptions $options): bool
    {
        return false;
    }

    public function detailRequest(Listing $listing): ?HttpRequest
    {
        return null;
    }

    public function parseDetail(HttpResponse $response, Listing $listing): Listing
    {
        return $listing;
    }

    public function detailProvides(): array
    {
        return [];
    }

    /** Source specific setting, e.g. the Yelp discovery mode. */
    protected function sourceSetting(string $key, mixed $default = null): mixed
    {
        $all = Settings::get('sources', []);
        return $all[$this->key()][$key] ?? $default;
    }

    /**
     * Read the first non-empty value among dot-notation paths.
     * Example: $this->pick($row, ['callContactNumber.displayValue', 'phone']).
     */
    protected function pick(array $data, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = $data;
            foreach (explode('.', $path) as $segment) {
                if (is_array($value) && array_key_exists($segment, $value)) {
                    $value = $value[$segment];
                } elseif (is_array($value) && ctype_digit($segment) && array_key_exists((int) $segment, $value)) {
                    $value = $value[(int) $segment];
                } else {
                    $value = null;
                    break;
                }
            }
            if ($value !== null && $value !== '' && $value !== []) {
                return $value;
            }
        }
        return null;
    }

    protected function pickString(array $data, array $paths, int $max = 255): ?string
    {
        $value = $this->pick($data, $paths);
        if (is_array($value)) {
            // Common shapes: {"value": "..."} / {"displayValue": "..."} / ["..."]
            $value = $value['displayValue'] ?? $value['value'] ?? $value['text'] ?? $value['name'] ?? $value['href'] ?? $value['url'] ?? ($value[0] ?? null);
            if (is_array($value)) {
                $value = $value['value'] ?? $value['name'] ?? null;
            }
        }
        return Str::clean($value, $max);
    }

    protected function pickFloat(array $data, array $paths): ?float
    {
        $value = $this->pick($data, $paths);
        return is_numeric($value) ? (float) $value : null;
    }

    protected function pickInt(array $data, array $paths): ?int
    {
        $value = $this->pick($data, $paths);
        if (is_string($value)) {
            $value = preg_replace('/[^\d]/', '', $value);
        }
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Search nested data for string values under keys matching $keyPattern
     * and satisfying $accept.
     *
     * @return string[]
     */
    protected function deepStrings(array $data, string $keyPattern, callable $accept, int $depth = 5): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($value) && is_string($key) && preg_match($keyPattern, $key) && $accept($value)) {
                $out[] = $value;
            } elseif (is_array($value) && $depth > 0) {
                $matchesKey = is_string($key) && preg_match($keyPattern, $key);
                if ($matchesKey) {
                    foreach ($value as $inner) {
                        if (is_string($inner) && $accept($inner)) {
                            $out[] = $inner;
                        }
                    }
                }
                foreach ($this->deepStrings($value, $keyPattern, $accept, $depth - 1) as $found) {
                    $out[] = $found;
                }
                if ($matchesKey) {
                    foreach (['value', 'displayValue', 'href', 'url', 'address'] as $k) {
                        if (isset($value[$k]) && is_string($value[$k]) && $accept($value[$k])) {
                            $out[] = $value[$k];
                        }
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Find arrays of objects that look like business listings anywhere in a
     * decoded JSON document. Returns the largest candidate list.
     *
     * @param string[] $signalKeys keys that indicate a listing object (besides "name")
     * @return array<int, array>
     */
    protected function findListingArrays(array $data, array $signalKeys, int $depth = 14): array
    {
        $best = [];
        $walk = function (array $node, int $depth) use (&$walk, &$best, $signalKeys): void {
            if ($depth < 0) {
                return;
            }
            if (array_is_list($node) && $node && is_array($node[0] ?? null)) {
                $hits = 0;
                foreach ($node as $item) {
                    if (is_array($item) && $this->looksLikeListing($item, $signalKeys)) {
                        $hits++;
                    }
                }
                if ($hits > 0 && $hits >= count($node) / 2 && $hits > count($best)) {
                    $best = array_values(array_filter($node, fn ($i) => is_array($i) && $this->looksLikeListing($i, $signalKeys)));
                }
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value, $depth - 1);
                }
            }
        };
        $walk($data, $depth);
        return $best;
    }

    protected function looksLikeListing(array $item, array $signalKeys): bool
    {
        $name = $item['name'] ?? $item['businessName'] ?? $item['displayName'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return false;
        }
        foreach ($signalKeys as $key) {
            if (array_key_exists($key, $item)) {
                return true;
            }
        }
        return false;
    }

    /** Parse a schema.org LocalBusiness-like JSON-LD node into a Listing. */
    protected function listingFromJsonLd(array $node): Listing
    {
        $l = new Listing();
        $l->name = Str::clean($node['name'] ?? '') ?? '';
        $l->phone = Str::clean($node['telephone'] ?? null, 60);
        $email = $node['email'] ?? null;
        if (is_string($email)) {
            $l->emails[] = preg_replace('/^mailto:/i', '', $email);
        }
        $url = $node['url'] ?? null;
        $sameAs = $node['sameAs'] ?? [];
        foreach (is_array($sameAs) ? $sameAs : [$sameAs] as $link) {
            if (is_string($link) && ($net = Url::socialNetwork($link))) {
                $l->socials[$net] = $link;
            }
        }
        if (is_string($url) && !Url::isPlatform($url)) {
            $l->website = $url;
        }
        $addr = $node['address'] ?? null;
        if (is_array($addr)) {
            if (array_is_list($addr)) {
                $addr = $addr[0] ?? [];
            }
            $street = $addr['streetAddress'] ?? null;
            $l->address = Str::clean(is_array($street) ? implode(', ', $street) : $street);
            $l->city = Str::clean($addr['addressLocality'] ?? null, 120);
            $l->state = Str::clean($addr['addressRegion'] ?? null, 60);
            $l->postcode = Str::clean($addr['postalCode'] ?? null, 20);
            $country = $addr['addressCountry'] ?? null;
            if (is_array($country)) {
                $country = $country['name'] ?? null;
            }
            if (is_string($country) && strlen($country) === 2) {
                $l->country = strtoupper($country);
            }
        } elseif (is_string($addr)) {
            $l->address = Str::clean($addr);
        }
        $geo = $node['geo'] ?? null;
        if (is_array($geo) && is_numeric($geo['latitude'] ?? null) && is_numeric($geo['longitude'] ?? null)) {
            $l->latitude = (float) $geo['latitude'];
            $l->longitude = (float) $geo['longitude'];
        }
        $rating = $node['aggregateRating'] ?? null;
        if (is_array($rating)) {
            $l->rating = is_numeric($rating['ratingValue'] ?? null) ? (float) $rating['ratingValue'] : null;
            $count = $rating['reviewCount'] ?? $rating['ratingCount'] ?? null;
            $l->reviews = is_numeric($count) ? (int) $count : null;
        }
        return $l;
    }

    /** First JSON-LD node describing a business. */
    protected function businessFromJsonLd(string $html): ?Listing
    {
        foreach (Html::jsonLd($html) as $node) {
            if (!is_array($node) || empty($node['name'])) {
                continue;
            }
            if (isset($node['address']) || isset($node['telephone'])) {
                if (Html::ldIsType($node, ['BreadcrumbList', 'WebSite', 'WebPage', 'Organization', 'SearchAction'])
                    && !isset($node['telephone'])) {
                    continue;
                }
                return $this->listingFromJsonLd($node);
            }
        }
        return null;
    }

    /** Emails in mailto: links and data-email attributes of a DOM subtree/page. */
    protected function emailsFromHtml(string $html): array
    {
        return EmailExtractor::fromHtml($html, false);
    }
}
