<?php

declare(strict_types=1);

namespace App\Scraper;

use App\Sources\Listing;

/**
 * Campaign conditions: "a lead must have an email, phone and website",
 * "at least one of ...", minimum rating/reviews.
 */
final class LeadFilter
{
    private const LABELS = [
        'email' => 'email',
        'phone' => 'phone',
        'website' => 'website',
        'address' => 'address',
        'socials' => 'social profile',
    ];

    /** Returns null when the lead qualifies, otherwise a human-readable reason. */
    public static function evaluate(Listing $lead, CampaignOptions $o): ?string
    {
        return self::check($lead, $o, []);
    }

    /**
     * Could the lead still qualify if the pending steps fill $potential fields?
     * Used to skip pointless detail requests and website crawls.
     *
     * @param string[] $potential
     */
    public static function canStillQualify(Listing $lead, CampaignOptions $o, array $potential): bool
    {
        return self::check($lead, $o, $potential, true) === null;
    }

    private static function check(Listing $lead, CampaignOptions $o, array $potential, bool $optimistic = false): ?string
    {
        if (trim($lead->name) === '') {
            return 'Missing business name';
        }

        $required = $o->requiredFields();
        if ($required) {
            $missing = [];
            foreach ($required as $field) {
                if (!$lead->has($field) && !in_array($field, $potential, true)) {
                    $missing[] = self::LABELS[$field] ?? $field;
                }
            }
            if ($o->match === 'all' && $missing) {
                return 'Missing ' . self::humanList($missing, 'and');
            }
            if ($o->match === 'any' && count($missing) === count($required)) {
                return 'Missing ' . self::humanList($missing, 'or');
            }
        }

        if (!$optimistic || $lead->rating !== null) {
            if ($o->minRating > 0 && ($lead->rating === null || $lead->rating < $o->minRating)) {
                return 'Rating below ' . rtrim(rtrim(number_format($o->minRating, 1), '0'), '.');
            }
        }
        if (!$optimistic || $lead->reviews !== null) {
            if ($o->minReviews > 0 && ($lead->reviews === null || $lead->reviews < $o->minReviews)) {
                return 'Fewer than ' . $o->minReviews . ' reviews';
            }
        }
        return null;
    }

    private static function humanList(array $items, string $joiner): string
    {
        if (count($items) <= 1) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' ' . $joiner . ' ' . $last;
    }
}
