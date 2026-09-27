<?php

declare(strict_types=1);

namespace App\Scraper;

use App\Core\Database;
use App\Core\Settings;
use App\Sources\Listing;
use App\Sources\Source;
use App\Support\Phone;
use App\Support\Url;

/**
 * Moves a lead through: listing → (detail page) → (website crawl) → qualified/rejected.
 */
final class LeadPipeline
{
    private int $campaignId;

    public function __construct(
        private readonly array $campaign,
        private readonly CampaignOptions $options,
        private readonly Source $source,
    ) {
        $this->campaignId = (int) $campaign['id'];
    }

    /**
     * Store a freshly scraped listing and schedule its next step.
     *
     * @return string "new" | "duplicate" | "skipped"
     */
    public function ingest(Listing $listing, string $keyword, string $location): string
    {
        $listing->normalize();
        if ($listing->name === '') {
            return 'skipped';
        }
        if ($this->options->skipDuplicates && $this->existsInOtherCampaign($listing)) {
            return 'duplicate';
        }
        $this->mask($listing);

        $now = Database::now();
        $id = Database::insert('leads', $this->row($listing) + [
            'campaign_id' => $this->campaignId,
            'source' => $this->source->key(),
            'source_ref' => $listing->ref,
            'status' => 'pending',
            'keyword' => mb_substr($keyword, 0, 190),
            'search_location' => mb_substr($location, 0, 190),
            'created_at' => $now,
            'updated_at' => $now,
        ], true);

        if ($id === 0) {
            return 'duplicate'; // already found by another query of this campaign
        }
        $this->advance($id, $listing, 'listing');
        return 'new';
    }

    /**
     * Decide the next step for a lead after a stage completes.
     *
     * @return string|null outcome if the lead was finalised ("qualified", "rejected", "limit")
     */
    public function advance(int $leadId, Listing $listing, string $stage): ?string
    {
        if ($stage === 'listing' && $this->source->needsDetail($listing, $this->options)) {
            $potential = $this->source->detailProvides();
            if ($this->options->crawlWebsite && ($listing->has('website') || in_array('website', $potential, true))) {
                $potential = array_merge($potential, ['email', 'socials', 'phone']);
            }
            if (LeadFilter::canStillQualify($listing, $this->options, $potential)) {
                Queue::push($this->campaignId, 'detail', ['lead_id' => $leadId]);
                return null;
            }
            return $this->finalize($leadId, $listing);
        }

        if ($stage !== 'enrich' && $this->shouldEnrich($listing)) {
            if (LeadFilter::canStillQualify($listing, $this->options, ['email', 'socials', 'phone'])) {
                Queue::push($this->campaignId, 'enrich', ['lead_id' => $leadId]);
                return null;
            }
        }

        return $this->finalize($leadId, $listing);
    }

    public function shouldEnrich(Listing $listing): bool
    {
        if (!$this->options->crawlWebsite || !$listing->has('website')) {
            return false;
        }
        return ($this->options->wants('email') && !$listing->has('email'))
            || ($this->options->wants('socials') && !$listing->has('socials'))
            || ($this->options->require['phone'] && !$listing->has('phone'));
    }

    /**
     * Apply the campaign conditions and mark the lead qualified or rejected.
     * Idempotent: only a lead that is still "pending" changes state, so a
     * stop/complete racing with a running batch can't double count it.
     *
     * @return string "qualified" | "rejected" | "limit" | "skipped"
     */
    public function finalize(int $leadId, Listing $listing): string
    {
        $listing->normalize();
        if ($this->options->excludeFreeEmails && $listing->emails) {
            $listing->emails = array_values(array_filter($listing->emails, static fn ($e) => !EmailExtractor::isFreeProvider($e)));
            $listing->email = $listing->emails[0] ?? null;
        }
        $this->mask($listing);

        $reason = LeadFilter::evaluate($listing, $this->options);
        $now = Database::now();
        $pending = "id = :id AND status = 'pending'";

        if ($reason === null) {
            if ($this->options->maxLeads > 0) {
                $qualified = (int) Database::value('SELECT leads_qualified FROM campaigns WHERE id = :id', ['id' => $this->campaignId]);
                if ($qualified >= $this->options->maxLeads) {
                    Database::query("DELETE FROM leads WHERE {$pending}", ['id' => $leadId]);
                    return 'limit';
                }
            }
            $changed = Database::update('leads', $this->row($listing) + [
                'status' => 'qualified',
                'reject_reason' => null,
                'updated_at' => $now,
            ], $pending, ['id' => $leadId]);
            if ($changed === 0) {
                return 'skipped';
            }
            CampaignService::bump($this->campaignId, [
                'leads_qualified' => 1,
                'emails_found' => $listing->has('email') ? 1 : 0,
            ]);
            return 'qualified';
        }

        if (Settings::bool('data.keep_rejected')) {
            $changed = Database::update('leads', $this->row($listing) + [
                'status' => 'rejected',
                'reject_reason' => mb_substr($reason, 0, 255),
                'updated_at' => $now,
            ], $pending, ['id' => $leadId]);
        } else {
            $changed = Database::query("DELETE FROM leads WHERE {$pending}", ['id' => $leadId])->rowCount();
        }
        if ($changed === 0) {
            return 'skipped';
        }
        CampaignService::bump($this->campaignId, ['leads_rejected' => 1]);
        return 'rejected';
    }

    /** Persist updated fields without changing status. */
    public function save(int $leadId, Listing $listing): void
    {
        $listing->normalize();
        $this->mask($listing);
        Database::update('leads', $this->row($listing) + ['updated_at' => Database::now()], 'id = :id', ['id' => $leadId]);
    }

    public function loadListing(int $leadId): ?Listing
    {
        $row = Database::row('SELECT * FROM leads WHERE id = :id AND campaign_id = :c', ['id' => $leadId, 'c' => $this->campaignId]);
        return $row ? Listing::fromRow($row) : null;
    }

    /** Drop fields the campaign doesn't collect. */
    private function mask(Listing $l): void
    {
        $o = $this->options;
        if (!$o->wants('phone')) {
            $l->phone = null;
        }
        if (!$o->wants('email')) {
            $l->email = null;
            $l->emails = [];
        }
        if (!$o->wants('website')) {
            $l->website = null;
        }
        if (!$o->wants('address')) {
            $l->address = null;
            $l->postcode = null;
            $l->latitude = null;
            $l->longitude = null;
        }
        if (!$o->wants('socials')) {
            $l->socials = [];
        }
        if (!$o->wants('rating')) {
            $l->rating = null;
            $l->reviews = null;
        }
    }

    private function existsInOtherCampaign(Listing $l): bool
    {
        $params = ['c' => $this->campaignId, 's' => $this->source->key(), 'r' => $l->ref];
        $sql = "SELECT 1 FROM leads WHERE campaign_id <> :c AND status = 'qualified' AND ((source = :s AND source_ref = :r)";
        $digits = Phone::digits($l->phone, $l->country ?? ($this->campaign['country'] ?? null));
        if ($digits !== null && strlen($digits) >= 8) {
            $sql .= ' OR phone_digits = :pd';
            $params['pd'] = $digits;
        }
        $sql .= ') LIMIT 1';
        return Database::value($sql, $params) !== null;
    }

    private function row(Listing $l): array
    {
        $json = static fn ($v) => $v ? json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        return [
            'name' => mb_substr($l->name, 0, 255),
            'category' => $l->category,
            'phone' => $l->phone,
            'phone_digits' => Phone::digits($l->phone, $l->country ?? ($this->campaign['country'] ?? null)),
            'email' => $l->email,
            'emails' => $json($l->emails),
            'website' => $l->website,
            'domain' => $l->website ? mb_substr((string) Url::domain($l->website), 0, 190) : null,
            'address' => $l->address,
            'city' => $l->city,
            'state' => $l->state,
            'postcode' => $l->postcode,
            'country' => $l->country ?? ($this->campaign['country'] ?? null),
            'latitude' => $l->latitude !== null ? round($l->latitude, 7) : null,
            'longitude' => $l->longitude !== null ? round($l->longitude, 7) : null,
            'rating' => $l->rating !== null ? round($l->rating, 1) : null,
            'reviews' => $l->reviews,
            'socials' => $json($l->socials),
            'source_url' => $l->sourceUrl !== null ? mb_substr($l->sourceUrl, 0, 500) : null,
            'meta' => $json($l->meta),
        ];
    }
}
