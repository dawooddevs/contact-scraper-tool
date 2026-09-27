<?php

declare(strict_types=1);

namespace App\Sources;

use App\Support\Phone;
use App\Support\Str;
use App\Support\Url;

/**
 * A business found on a lead source. Sources fill what they can; the
 * pipeline normalises it and enrichment adds emails/socials later.
 */
final class Listing
{
    public string $ref = '';
    public string $name = '';
    public ?string $category = null;
    public ?string $phone = null;
    public ?string $email = null;
    /** @var string[] */
    public array $emails = [];
    public ?string $website = null;
    public ?string $address = null;
    public ?string $city = null;
    public ?string $state = null;
    public ?string $postcode = null;
    public ?string $country = null;
    public ?float $latitude = null;
    public ?float $longitude = null;
    public ?float $rating = null;
    public ?int $reviews = null;
    /** @var array<string, string> network => url */
    public array $socials = [];
    public ?string $sourceUrl = null;
    /** Source-specific extras (e.g. Yelp alias, "hydrated" flag). */
    public array $meta = [];

    public static function fromArray(array $data): self
    {
        $l = new self();
        foreach ($data as $key => $value) {
            if (property_exists($l, $key)) {
                $l->{$key} = $value;
            }
        }
        return $l;
    }

    /** Build from a `leads` table row. */
    public static function fromRow(array $row): self
    {
        $l = new self();
        $l->ref = (string) $row['source_ref'];
        $l->name = (string) $row['name'];
        $l->category = $row['category'];
        $l->phone = $row['phone'];
        $l->email = $row['email'];
        $l->emails = json_decode((string) ($row['emails'] ?? '[]'), true) ?: [];
        $l->website = $row['website'];
        $l->address = $row['address'];
        $l->city = $row['city'];
        $l->state = $row['state'];
        $l->postcode = $row['postcode'];
        $l->country = $row['country'];
        $l->latitude = $row['latitude'] !== null ? (float) $row['latitude'] : null;
        $l->longitude = $row['longitude'] !== null ? (float) $row['longitude'] : null;
        $l->rating = $row['rating'] !== null ? (float) $row['rating'] : null;
        $l->reviews = $row['reviews'] !== null ? (int) $row['reviews'] : null;
        $l->socials = json_decode((string) ($row['socials'] ?? '{}'), true) ?: [];
        $l->sourceUrl = $row['source_url'];
        $l->meta = json_decode((string) ($row['meta'] ?? '{}'), true) ?: [];
        return $l;
    }

    /** Fill empty fields from another listing (e.g. a detail page). */
    public function merge(self $other): self
    {
        foreach (get_object_vars($other) as $key => $value) {
            if ($key === 'emails') {
                $this->emails = array_values(array_unique(array_merge($this->emails, $value)));
            } elseif ($key === 'socials') {
                $this->socials = $this->socials + $value;
            } elseif ($key === 'meta') {
                $this->meta = array_merge($this->meta, $value);
            } elseif (($this->{$key} === null || $this->{$key} === '') && $value !== null && $value !== '') {
                $this->{$key} = $value;
            }
        }
        return $this;
    }

    /**
     * Clean every field: tidy phone, move social profile URLs out of the
     * website field, drop directory/platform links, validate emails.
     */
    public function normalize(): self
    {
        $this->name = Str::clean($this->name, 255) ?? '';
        $this->category = Str::clean($this->category, 190);
        $this->phone = Str::limit(Phone::clean($this->phone), 60);
        $this->address = Str::clean($this->address, 255);
        $this->city = Str::clean($this->city, 120);
        $this->state = Str::clean($this->state, 60);
        $this->postcode = Str::clean($this->postcode, 20);
        $this->country = $this->country ? strtoupper(substr($this->country, 0, 2)) : null;

        if ($this->website !== null) {
            $site = Url::clean($this->website);
            $network = $site ? Url::socialNetwork($site) : null;
            if ($network !== null) {
                $this->socials[$network] = $this->socials[$network] ?? $site;
                $site = null;
            } elseif ($site !== null && Url::isPlatform($site)) {
                $site = null;
            }
            $this->website = $site !== null ? Str::limit($site, 500) : null;
        }

        $emails = $this->emails;
        if ($this->email) {
            array_unshift($emails, $this->email);
        }
        $clean = [];
        foreach ($emails as $e) {
            $e = \App\Scraper\EmailExtractor::normalize((string) $e);
            if ($e !== null && !in_array($e, $clean, true)) {
                $clean[] = $e;
            }
        }
        $this->emails = array_slice($clean, 0, 10);
        $this->email = $this->emails[0] ?? null;

        if ($this->reviews !== null && $this->reviews < 0) {
            $this->reviews = null;
        }
        if ($this->rating !== null && ($this->rating < 0 || $this->rating > 10)) {
            $this->rating = null;
        }
        if ($this->latitude !== null && abs($this->latitude) > 90) {
            $this->latitude = null;
        }
        if ($this->longitude !== null && abs($this->longitude) > 180) {
            $this->longitude = null;
        }
        if ($this->ref === '') {
            $this->ref = substr(sha1(mb_strtolower($this->name . '|' . ($this->address ?? '') . '|' . ($this->phone ?? ''))), 0, 32);
        }
        $this->ref = Str::limit($this->ref, 190) ?? '';
        return $this;
    }

    public function has(string $field): bool
    {
        return match ($field) {
            'email' => $this->email !== null && $this->email !== '',
            'phone' => $this->phone !== null && $this->phone !== '',
            'website' => $this->website !== null && $this->website !== '',
            'address' => ($this->address !== null && $this->address !== '') || ($this->city !== null && $this->city !== ''),
            'socials' => $this->socials !== [],
            default => false,
        };
    }
}
