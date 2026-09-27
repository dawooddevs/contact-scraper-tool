<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A single outgoing HTTP request.
 *
 * kind:
 *  - "directory": a page on a lead source (Yellow Pages, Yelp, ...). Uses the
 *    configured scraping connection, cookie jar and bot-block detection.
 *  - "website":   a business's own website (email discovery). SSRF-guarded.
 *  - "api":       an official JSON API called directly (e.g. Yelp Fusion).
 */
final class HttpRequest
{
    public function __construct(
        public readonly string $url,
        public readonly string $kind = 'directory',
        public readonly array $headers = [],
        public readonly ?string $sourceKey = null,
        public readonly ?string $country = null,
        public readonly mixed $tag = null,
        public readonly ?int $timeout = null,
        public readonly bool $expectJson = false,
    ) {
    }

    public function withUrl(string $url): self
    {
        return new self($url, $this->kind, $this->headers, $this->sourceKey, $this->country, $this->tag, $this->timeout, $this->expectJson);
    }

    public function withTag(mixed $tag): self
    {
        return new self($this->url, $this->kind, $this->headers, $this->sourceKey, $this->country, $tag, $this->timeout, $this->expectJson);
    }
}
