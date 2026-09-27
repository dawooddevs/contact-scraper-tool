<?php

declare(strict_types=1);

namespace App\Scraper;

use App\Api\ApiException;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Http\Connection;
use App\Sources\Listing;
use App\Sources\SourceRegistry;

/**
 * Campaign lifecycle: validate/create, start, pause, resume, stop, complete.
 */
final class CampaignService
{
    public const STATUSES = ['draft', 'running', 'paused', 'completed', 'stopped', 'failed'];
    public const MAX_QUERIES = 60;

    public static function find(int $id): ?array
    {
        return Database::row('SELECT * FROM campaigns WHERE id = :id', ['id' => $id]);
    }

    public static function findOrFail(int $id): array
    {
        $row = self::find($id);
        if ($row === null) {
            throw ApiException::notFound('Campaign');
        }
        return $row;
    }

    public static function options(array $campaign): CampaignOptions
    {
        return CampaignOptions::fromArray(json_decode((string) $campaign['options'], true) ?: []);
    }

    /** Split "a, b\nc" or ["a","b"] into a clean unique list. */
    public static function splitList(mixed $value, int $maxItems = 30): array
    {
        $items = is_array($value) ? $value : preg_split('/[\n;]+/', (string) $value);
        $out = [];
        foreach ($items ?: [] as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = trim(preg_replace('/\s+/', ' ', (string) $item) ?? '');
            if ($item !== '' && mb_strlen($item) <= 120 && !in_array(mb_strtolower($item), array_map('mb_strtolower', $out), true)) {
                $out[] = $item;
            }
        }
        return array_slice($out, 0, $maxItems);
    }

    /**
     * Validate wizard input.
     *
     * @return array{name: string, country: string, source: string, keywords: string[], locations: string[], options: CampaignOptions}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $country = strtoupper(trim((string) ($input['country'] ?? '')));
        $countries = SourceRegistry::countries();
        if (!isset($countries[$country])) {
            $errors['country'] = 'Choose a country.';
        }
        $source = (string) ($input['source'] ?? '');
        if (isset($countries[$country]) && ($source === '' || !in_array($source, $countries[$country]['sources'], true))) {
            $source = $countries[$country]['sources'][0] ?? '';
        }
        if ($source === '' || !SourceRegistry::has($source)) {
            $errors['source'] = 'Choose a data source.';
        } elseif (!Settings::source($source)['enabled']) {
            $errors['source'] = 'This source is disabled. Enable it on the Sources page.';
        }

        $keywords = self::splitList($input['keywords'] ?? []);
        $locations = self::splitList($input['locations'] ?? []);
        if (!$keywords) {
            $errors['keywords'] = 'Add at least one category or keyword.';
        }
        if (!$locations) {
            $errors['locations'] = 'Add at least one location.';
        }
        if ($keywords && $locations && count($keywords) * count($locations) > self::MAX_QUERIES) {
            $errors['locations'] = 'Too many combinations (' . count($keywords) * count($locations) . '). Keep categories × locations at ' . self::MAX_QUERIES . ' or fewer per campaign.';
        }

        $options = CampaignOptions::fromArray(is_array($input['options'] ?? null) ? $input['options'] : []);
        if (!isset($errors['source']) && SourceRegistry::has($source)) {
            $max = (int) SourceRegistry::get($source)->info()['max_pages'];
            if ($options->maxPages > $max) {
                $options->maxPages = $max;
            }
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' && $keywords && $locations) {
            $name = self::autoName($keywords, $locations);
        }
        if (mb_strlen($name) > 190) {
            $errors['name'] = 'Name is too long (max 190 characters).';
        }

        if ($errors) {
            throw ApiException::validation($errors);
        }

        return compact('name', 'country', 'source', 'keywords', 'locations', 'options');
    }

    public static function autoName(array $keywords, array $locations): string
    {
        $k = $keywords[0] . (count($keywords) > 1 ? ' +' . (count($keywords) - 1) : '');
        $l = $locations[0] . (count($locations) > 1 ? ' +' . (count($locations) - 1) : '');
        return mb_substr("{$k} in {$l}", 0, 190);
    }

    public static function create(array $input, bool $start): array
    {
        $v = self::validate($input);
        $now = Database::now();
        $id = Database::insert('campaigns', [
            'name' => $v['name'],
            'country' => $v['country'],
            'source' => $v['source'],
            'keywords' => json_encode($v['keywords'], JSON_UNESCAPED_UNICODE),
            'locations' => json_encode($v['locations'], JSON_UNESCAPED_UNICODE),
            'options' => json_encode($v['options']->toArray()),
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Logger::info($id, 'Campaign created: ' . count($v['keywords']) . ' categor' . (count($v['keywords']) === 1 ? 'y' : 'ies') . ' × ' . count($v['locations']) . ' location' . (count($v['locations']) === 1 ? '' : 's') . ' on ' . SourceRegistry::get($v['source'])->info()['name'] . '.');
        if ($start) {
            self::start($id);
        }
        return self::findOrFail($id);
    }

    /** Edit a campaign that hasn't started yet (or just its name/limits later). */
    public static function update(int $id, array $input): array
    {
        $campaign = self::findOrFail($id);
        if ($campaign['status'] === 'draft') {
            $v = self::validate($input + [
                'country' => $campaign['country'],
                'source' => $campaign['source'],
            ]);
            Database::update('campaigns', [
                'name' => $v['name'],
                'country' => $v['country'],
                'source' => $v['source'],
                'keywords' => json_encode($v['keywords'], JSON_UNESCAPED_UNICODE),
                'locations' => json_encode($v['locations'], JSON_UNESCAPED_UNICODE),
                'options' => json_encode($v['options']->toArray()),
                'updated_at' => Database::now(),
            ], 'id = :id', ['id' => $id]);
        } else {
            $name = trim((string) ($input['name'] ?? $campaign['name']));
            if ($name === '' || mb_strlen($name) > 190) {
                throw ApiException::validation(['name' => 'Enter a name up to 190 characters.']);
            }
            $options = self::options($campaign);
            if (isset($input['options']['max_leads'])) {
                $options->maxLeads = max(0, min(100000, (int) $input['options']['max_leads']));
            }
            Database::update('campaigns', [
                'name' => $name,
                'options' => json_encode($options->toArray()),
                'updated_at' => Database::now(),
            ], 'id = :id', ['id' => $id]);
        }
        return self::findOrFail($id);
    }

    public static function start(int $id): void
    {
        $campaign = self::findOrFail($id);
        if ($campaign['status'] !== 'draft') {
            throw new ApiException('Only draft campaigns can be started. Use resume for paused campaigns.', 409);
        }
        $source = SourceRegistry::get($campaign['source']);
        // Fail fast on missing API keys etc. before queueing anything.
        Connection::forSource($campaign['source'])->prepare('https://example.com/', $campaign['country']);
        $source->searchRequest('test', 'test', 1);
        if (!Settings::source($campaign['source'])['enabled']) {
            throw new ApiException('This source is disabled. Enable it on the Sources page first.', 409);
        }

        $options = self::options($campaign);
        $keywords = json_decode((string) $campaign['keywords'], true) ?: [];
        $locations = json_decode((string) $campaign['locations'], true) ?: [];

        Database::transaction(static function () use ($id, $keywords, $locations, $options): void {
            foreach ($locations as $location) {
                foreach ($keywords as $keyword) {
                    Queue::push($id, 'search', ['keyword' => $keyword, 'location' => $location, 'page' => 1]);
                }
            }
            $now = Database::now();
            Database::update('campaigns', [
                'status' => 'running',
                'pages_planned' => count($keywords) * count($locations) * $options->maxPages,
                'started_at' => $now,
                'last_activity_at' => $now,
                'last_error' => null,
                'blocked_streak' => 0,
                'updated_at' => $now,
            ], 'id = :id', ['id' => $id]);
        });
        Logger::info($id, 'Campaign started via ' . Connection::forSource($campaign['source'])->label() . ' on ' . $source->info()['name'] . '.');
    }

    public static function pause(int $id, ?string $reason = null): void
    {
        $campaign = self::findOrFail($id);
        if ($campaign['status'] !== 'running') {
            if ($reason === null) {
                throw new ApiException('Only running campaigns can be paused.', 409);
            }
            return;
        }
        Database::update('campaigns', [
            'status' => 'paused',
            'last_error' => $reason,
            'updated_at' => Database::now(),
        ], 'id = :id', ['id' => $id]);
        if ($reason !== null) {
            Logger::error($id, 'Paused: ' . $reason);
        } else {
            Logger::info($id, 'Campaign paused.');
        }
    }

    public static function resume(int $id): void
    {
        $campaign = self::findOrFail($id);
        if (!in_array($campaign['status'], ['paused', 'failed'], true)) {
            throw new ApiException('Only paused campaigns can be resumed.', 409);
        }
        Connection::forSource($campaign['source'])->prepare('https://example.com/', $campaign['country']);
        SourceRegistry::get($campaign['source'])->searchRequest('test', 'test', 1);
        $now = Database::now();
        // Give failed tasks one more chance and make everything available right away.
        Database::query(
            "UPDATE tasks SET status = 'pending', attempts = 0, available_at = :now, locked_until = NULL
             WHERE campaign_id = :id AND status IN ('pending','failed')",
            ['id' => $id, 'now' => $now]
        );
        Database::update('campaigns', [
            'status' => 'running',
            'last_error' => null,
            'blocked_streak' => 0,
            'last_activity_at' => $now,
            'updated_at' => $now,
        ], 'id = :id', ['id' => $id]);
        Logger::info($id, 'Campaign resumed.');
    }

    public static function stop(int $id): void
    {
        $campaign = self::findOrFail($id);
        if (!in_array($campaign['status'], ['running', 'paused', 'failed'], true)) {
            throw new ApiException('This campaign is not active.', 409);
        }
        Queue::clear($id);
        self::finalizePending($campaign);
        $now = Database::now();
        Database::update('campaigns', [
            'status' => 'stopped',
            'finished_at' => $now,
            'updated_at' => $now,
        ], 'id = :id', ['id' => $id]);
        Logger::info($id, 'Campaign stopped by user.');
    }

    public static function complete(int $id, string $message): void
    {
        $campaign = self::find($id);
        if ($campaign === null || !in_array($campaign['status'], ['running', 'paused'], true)) {
            return;
        }
        Queue::clear($id);
        // Leads still waiting for a detail page/crawl when the target was hit are discarded.
        Database::query("DELETE FROM leads WHERE campaign_id = :id AND status = 'pending'", ['id' => $id]);
        $now = Database::now();
        Database::update('campaigns', [
            'status' => 'completed',
            'pages_planned' => (int) $campaign['pages_done'],
            'finished_at' => $now,
            'updated_at' => $now,
        ], 'id = :id', ['id' => $id]);
        Logger::success($id, $message);
    }

    /** Complete running campaigns that have nothing left to do. */
    public static function completeFinished(): void
    {
        $rows = Database::all(
            "SELECT c.* FROM campaigns c
             WHERE c.status = 'running'
               AND NOT EXISTS (SELECT 1 FROM tasks t WHERE t.campaign_id = c.id AND t.status IN ('pending','running'))"
        );
        foreach ($rows as $campaign) {
            $failed = (int) Database::value("SELECT COUNT(*) FROM tasks WHERE campaign_id = :id AND status = 'failed'", ['id' => $campaign['id']]);
            self::finalizePending($campaign);
            $fresh = self::find((int) $campaign['id']);
            $qualified = (int) ($fresh['leads_qualified'] ?? 0);
            if ($qualified === 0 && (int) $campaign['pages_done'] === 0 && $failed > 0) {
                Database::update('campaigns', [
                    'status' => 'failed',
                    'last_error' => $campaign['last_error'] ?: 'No page could be fetched. Check the activity log and Settings → Connection.',
                    'finished_at' => Database::now(),
                    'updated_at' => Database::now(),
                ], 'id = :id', ['id' => $campaign['id']]);
                Logger::error((int) $campaign['id'], 'Campaign failed: no page could be fetched.');
                continue;
            }
            self::complete((int) $campaign['id'], "Campaign completed — {$qualified} qualified lead" . ($qualified === 1 ? '' : 's') . '.');
        }
    }

    /** Evaluate leads that were still waiting for a detail page or crawl. */
    public static function finalizePending(array $campaign): void
    {
        $rows = Database::all("SELECT * FROM leads WHERE campaign_id = :id AND status = 'pending'", ['id' => $campaign['id']]);
        if (!$rows || !SourceRegistry::has($campaign['source'])) {
            return;
        }
        $pipeline = new LeadPipeline($campaign, self::options($campaign), SourceRegistry::get($campaign['source']));
        foreach ($rows as $row) {
            $pipeline->finalize((int) $row['id'], Listing::fromRow($row));
        }
    }

    public static function delete(array $ids): int
    {
        $count = 0;
        foreach ($ids as $id) {
            Database::transaction(static function () use ($id, &$count): void {
                Database::query('DELETE FROM tasks WHERE campaign_id = :id', ['id' => $id]);
                Database::query('DELETE FROM leads WHERE campaign_id = :id', ['id' => $id]);
                Database::query('DELETE FROM logs WHERE campaign_id = :id', ['id' => $id]);
                $count += Database::query('DELETE FROM campaigns WHERE id = :id', ['id' => $id])->rowCount();
            });
        }
        return $count;
    }

    /** Atomically increment counters, e.g. bump($id, ['pages_done' => 1]). */
    public static function bump(int $id, array $counters): void
    {
        $sets = [];
        $params = ['id' => $id];
        foreach ($counters as $column => $n) {
            if ((int) $n === 0 || !preg_match('/^[a-z_]+$/', $column)) {
                continue;
            }
            $sets[] = "`{$column}` = `{$column}` + :{$column}";
            $params[$column] = (int) $n;
        }
        if (!$sets) {
            return;
        }
        $sets[] = '`last_activity_at` = :now';
        $params['now'] = Database::now();
        Database::query('UPDATE campaigns SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    /** A query stopped before max_pages: shrink the plan so progress stays honest. */
    public static function shrinkPlan(int $id, int $pages): void
    {
        if ($pages <= 0) {
            return;
        }
        Database::query(
            'UPDATE campaigns SET pages_planned = GREATEST(CAST(pages_planned AS SIGNED) - :n, CAST(pages_done AS SIGNED), 0) WHERE id = :id',
            ['id' => $id, 'n' => $pages]
        );
    }

    /** Shape a DB row for the API. */
    public static function present(array $c, bool $withQueue = false): array
    {
        $options = self::options($c);
        $keywords = json_decode((string) $c['keywords'], true) ?: [];
        $locations = json_decode((string) $c['locations'], true) ?: [];
        $planned = max(1, (int) $c['pages_planned']);
        $progress = min(1.0, (int) $c['pages_done'] / $planned);
        if ($options->maxLeads > 0) {
            $progress = max($progress, min(1.0, (int) $c['leads_qualified'] / $options->maxLeads));
        }
        if ($c['status'] === 'completed') {
            $progress = 1.0;
        } elseif ($c['status'] === 'draft') {
            $progress = 0.0;
        } elseif ($progress >= 1.0) {
            $progress = 0.99;
        }
        $source = SourceRegistry::has($c['source']) ? SourceRegistry::get($c['source'])->info() : ['name' => $c['source'], 'domain' => ''];

        $out = [
            'id' => (int) $c['id'],
            'name' => $c['name'],
            'country' => $c['country'],
            'source' => $c['source'],
            'source_name' => $source['name'],
            'source_domain' => $source['domain'],
            'keywords' => $keywords,
            'locations' => $locations,
            'options' => $options->toArray(),
            'status' => $c['status'],
            'progress' => round($progress, 4),
            'stats' => [
                'pages_planned' => (int) $c['pages_planned'],
                'pages_done' => (int) $c['pages_done'],
                'listings_found' => (int) $c['listings_found'],
                'leads_qualified' => (int) $c['leads_qualified'],
                'leads_rejected' => (int) $c['leads_rejected'],
                'duplicates' => (int) $c['duplicates'],
                'websites_crawled' => (int) $c['websites_crawled'],
                'emails_found' => (int) $c['emails_found'],
                'requests' => (int) $c['requests'],
                'errors' => (int) $c['errors'],
            ],
            'last_error' => $c['last_error'],
            'last_activity_at' => self::iso($c['last_activity_at']),
            'started_at' => self::iso($c['started_at']),
            'finished_at' => self::iso($c['finished_at']),
            'created_at' => self::iso($c['created_at']),
            'updated_at' => self::iso($c['updated_at']),
        ];
        if ($withQueue) {
            $out['queue'] = Queue::breakdown((int) $c['id']);
            $out['pending_leads'] = (int) Database::value("SELECT COUNT(*) FROM leads WHERE campaign_id = :id AND status = 'pending'", ['id' => $c['id']]);
        }
        return $out;
    }

    public static function iso(?string $datetime): ?string
    {
        return $datetime ? str_replace(' ', 'T', $datetime) . 'Z' : null;
    }
}
