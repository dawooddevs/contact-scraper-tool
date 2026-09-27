<?php

declare(strict_types=1);

namespace App\Scraper;

/**
 * What a campaign collects and which conditions a lead must meet.
 */
final class CampaignOptions
{
    public const FIELDS = ['phone', 'email', 'website', 'address', 'socials', 'rating'];
    public const REQUIRABLE = ['email', 'phone', 'website', 'address', 'socials'];

    /** @var array<string, bool> */
    public array $collect = [
        'phone' => true,
        'email' => true,
        'website' => true,
        'address' => true,
        'socials' => true,
        'rating' => true,
    ];

    /** @var array<string, bool> */
    public array $require = [
        'email' => false,
        'phone' => false,
        'website' => false,
        'address' => false,
        'socials' => false,
    ];

    /** "all": every required field must be present; "any": at least one. */
    public string $match = 'all';
    public int $maxLeads = 0;
    public int $maxPages = 10;
    public bool $crawlWebsite = true;
    public bool $skipDuplicates = true;
    public bool $excludeFreeEmails = false;
    public float $minRating = 0.0;
    public int $minReviews = 0;

    public static function fromArray(array $data): self
    {
        $o = new self();
        foreach (self::FIELDS as $f) {
            if (isset($data['collect'][$f])) {
                $o->collect[$f] = (bool) $data['collect'][$f];
            }
        }
        foreach (self::REQUIRABLE as $f) {
            if (isset($data['require'][$f])) {
                $o->require[$f] = (bool) $data['require'][$f];
            }
        }
        $o->match = ($data['match'] ?? 'all') === 'any' ? 'any' : 'all';
        $o->maxLeads = max(0, min(100000, (int) ($data['max_leads'] ?? 0)));
        $o->maxPages = max(1, min(100, (int) ($data['max_pages'] ?? 10)));
        $o->crawlWebsite = (bool) ($data['crawl_website'] ?? true);
        $o->skipDuplicates = (bool) ($data['skip_duplicates'] ?? true);
        $o->excludeFreeEmails = (bool) ($data['exclude_free_emails'] ?? false);
        $o->minRating = max(0.0, min(5.0, (float) ($data['min_rating'] ?? 0)));
        $o->minReviews = max(0, (int) ($data['min_reviews'] ?? 0));

        // A required field is always collected.
        foreach ($o->require as $f => $required) {
            if ($required) {
                $o->collect[$f] = true;
            }
        }
        // Emails come from websites, so collecting emails needs the website.
        if ($o->collect['email'] || $o->collect['socials']) {
            $o->collect['website'] = true;
        }
        if ($o->minRating > 0 || $o->minReviews > 0) {
            $o->collect['rating'] = true;
        }
        return $o;
    }

    public function toArray(): array
    {
        return [
            'collect' => $this->collect,
            'require' => $this->require,
            'match' => $this->match,
            'max_leads' => $this->maxLeads,
            'max_pages' => $this->maxPages,
            'crawl_website' => $this->crawlWebsite,
            'skip_duplicates' => $this->skipDuplicates,
            'exclude_free_emails' => $this->excludeFreeEmails,
            'min_rating' => $this->minRating,
            'min_reviews' => $this->minReviews,
        ];
    }

    /** @return string[] */
    public function requiredFields(): array
    {
        return array_keys(array_filter($this->require));
    }

    public function wants(string $field): bool
    {
        return (bool) ($this->collect[$field] ?? false);
    }
}
