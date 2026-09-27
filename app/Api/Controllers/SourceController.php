<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Request;
use App\Core\Database;
use App\Core\Settings;
use App\Http\Connection;
use App\Http\Fetcher;
use App\Sources\SourceRegistry;

final class SourceController
{
    public function list(Request $request, ?array $user): array
    {
        $catalogue = SourceRegistry::catalogue();
        $stats = [];
        foreach (Database::all(
            "SELECT source, COUNT(*) AS leads, SUM(email IS NOT NULL) AS emails FROM leads WHERE status = 'qualified' GROUP BY source"
        ) as $row) {
            $stats[$row['source']] = ['leads' => (int) $row['leads'], 'emails' => (int) $row['emails']];
        }
        $campaigns = [];
        foreach (Database::all('SELECT source, COUNT(*) AS n FROM campaigns GROUP BY source') as $row) {
            $campaigns[$row['source']] = (int) $row['n'];
        }
        foreach ($catalogue['countries'] as &$country) {
            foreach ($country['sources'] as &$source) {
                $source['stats'] = ($stats[$source['key']] ?? ['leads' => 0, 'emails' => 0]) + ['campaigns' => $campaigns[$source['key']] ?? 0];
                $source['connection_label'] = Connection::forSource($source['key'])->label();
            }
        }
        unset($country, $source);
        $catalogue['connection_modes'] = Connection::MODES;
        $catalogue['yelp_api_key_set'] = trim((string) Settings::get('scrape.yelp_api_key', '')) !== '';
        return $catalogue;
    }

    public function update(Request $request, ?array $user): array
    {
        $key = $request->str('key', '', 60);
        if (!SourceRegistry::has($key)) {
            throw ApiException::notFound('Source');
        }
        $values = [];
        if ($request->has('enabled')) {
            $values['enabled'] = $request->bool('enabled');
        }
        if ($request->has('connection')) {
            $conn = $request->str('connection', 'default', 20);
            if ($conn !== 'default' && !isset(Connection::MODES[$conn])) {
                throw ApiException::validation(['connection' => 'Unknown connection type.']);
            }
            $values['connection'] = $conn;
        }
        if ($request->has('delay_ms')) {
            $raw = $request->raw('delay_ms');
            $values['delay_ms'] = ($raw === null || $raw === '') ? null : max(0, min(60000, (int) $raw));
        }
        if ($request->has('mode')) {
            $modes = SourceRegistry::get($key)->info()['modes'] ?? [];
            $mode = $request->str('mode', '', 20);
            if ($modes && !isset($modes[$mode])) {
                throw ApiException::validation(['mode' => 'Unknown discovery method.']);
            }
            $values['mode'] = $mode;
        }
        if ($request->has('yelp_api_key') && $key === 'yelp') {
            $apiKey = $request->str('yelp_api_key', '', 500);
            if ($apiKey !== '********') {
                Settings::set('scrape.yelp_api_key', $apiKey);
            }
        }
        if (($values['mode'] ?? null) === 'api' && trim((string) Settings::get('scrape.yelp_api_key', '')) === '') {
            throw ApiException::validation(['yelp_api_key' => 'Enter your Yelp API key to use the official API.']);
        }
        Settings::setSource($key, $values);
        return $this->list($request, $user);
    }

    /** Live test: fetch page 1 for a keyword/location and show what was parsed. */
    public function test(Request $request, ?array $user): array
    {
        $key = $request->str('key', '', 60);
        if (!SourceRegistry::has($key)) {
            throw ApiException::notFound('Source');
        }
        $keyword = $request->str('keyword', '', 120);
        $location = $request->str('location', '', 120);
        $errors = [];
        if ($keyword === '') {
            $errors['keyword'] = 'Enter a category or keyword.';
        }
        if ($location === '') {
            $errors['location'] = 'Enter a location.';
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }

        \App\Core\App::extendTimeLimit(150);
        $source = SourceRegistry::get($key);
        $httpRequest = $source->searchRequest($keyword, $location, 1);
        $response = (new Fetcher())->fetch($httpRequest);

        $result = null;
        $parseError = null;
        if ($response->error === null && !$response->blocked()) {
            try {
                $result = $source->parseSearch($response, 1);
            } catch (ApiException $e) {
                $parseError = $e->getMessage();
            }
        }

        $listings = [];
        foreach (array_slice($result['listings'] ?? [], 0, 25) as $l) {
            $l->normalize();
            $listings[] = [
                'name' => $l->name,
                'phone' => $l->phone,
                'email' => $l->email,
                'website' => $l->website,
                'address' => trim(implode(', ', array_filter([$l->address, $l->city, $l->state, $l->postcode]))),
                'rating' => $l->rating,
                'reviews' => $l->reviews,
                'needs_detail' => !empty($l->meta) && isset($l->meta['hydrated']) ? !$l->meta['hydrated'] : false,
            ];
        }

        $status = match (true) {
            $response->error !== null => 'error',
            $response->blocked() => 'blocked',
            $parseError !== null => 'error',
            $result !== null && !$result['recognized'] => 'unrecognized',
            $result !== null && $result['no_results'] => 'empty',
            default => 'ok',
        };

        return [
            'status' => $status,
            'message' => match ($status) {
                'error' => $parseError ?? ('Request failed: ' . $response->error),
                'blocked' => 'Blocked: ' . $response->blockReason . '. Configure a scraping API or proxy in Settings → Connection.',
                'unrecognized' => 'The page loaded (HTTP ' . $response->status . ') but no listings could be read. This usually means a bot-check page was served; try a scraping API with JavaScript rendering.',
                'empty' => 'The search worked but returned no results. Try a broader keyword or location.',
                default => 'Success — ' . count($result['listings']) . ' listings on page 1' . ($result['total'] ? ' of about ' . number_format($result['total']) : '') . '.',
            },
            'http_status' => $response->status,
            'time_ms' => $response->timeMs,
            'bytes' => strlen($response->body),
            'via' => $httpRequest->kind === 'api' ? 'Official API' : Connection::forSource($key)->label(),
            'url' => $httpRequest->url,
            'has_more' => (bool) ($result['has_more'] ?? false),
            'listings' => $listings,
        ];
    }
}
