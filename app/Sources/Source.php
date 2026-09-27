<?php

declare(strict_types=1);

namespace App\Sources;

use App\Http\HttpRequest;
use App\Http\HttpResponse;
use App\Scraper\CampaignOptions;

/**
 * A lead source (business directory). To add a new website:
 *   1. Create a class in app/Sources extending AbstractSource.
 *   2. Register it in app/config/sources.php.
 *   3. Add its key to a country in app/config/countries.php.
 */
interface Source
{
    /** Unique machine key, e.g. "yellowpages_au". */
    public function key(): string;

    /**
     * Static description shown in the UI.
     *
     * @return array{name: string, country: string, domain: string, url: string, description: string,
     *               provides: string[], results_per_page: int, max_pages: int,
     *               location_hint: string, location_placeholder: string, notes?: string}
     */
    public function info(): array;

    public function searchRequest(string $keyword, string $location, int $page): HttpRequest;

    /**
     * @return array{listings: Listing[], has_more: bool, total: ?int, no_results: bool, recognized: bool}
     *   recognized=false means the page layout was not understood at all.
     */
    public function parseSearch(HttpResponse $response, int $page): array;

    /** Should a detail page be fetched for this listing? */
    public function needsDetail(Listing $listing, CampaignOptions $options): bool;

    public function detailRequest(Listing $listing): ?HttpRequest;

    public function parseDetail(HttpResponse $response, Listing $listing): Listing;

    /** Fields a detail page can add (used to decide early whether a lead can still qualify). */
    public function detailProvides(): array;
}
