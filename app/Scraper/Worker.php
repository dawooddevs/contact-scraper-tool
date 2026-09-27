<?php

declare(strict_types=1);

namespace App\Scraper;

use App\Api\ApiException;
use App\Core\App;
use App\Core\Database;
use App\Core\Lock;
use App\Core\Logger;
use App\Core\Settings;
use App\Http\Connection;
use App\Http\Fetcher;
use App\Http\HttpResponse;
use App\Sources\Listing;
use App\Sources\Source;
use App\Sources\SourceRegistry;
use Throwable;

/**
 * Processes queued tasks within a time budget. Shared hosting has no
 * long-running processes, so work happens in short "ticks" triggered by
 * cron (bin/cron.php) and/or by the open dashboard (worker.tick API).
 * A MySQL lock guarantees only one tick runs at a time.
 */
final class Worker
{
    private const LEASE_SECONDS = 180;

    private Fetcher $fetcher;
    private float $deadline = 0.0;
    private array $stats = ['batches' => 0, 'tasks' => 0, 'qualified' => 0, 'requests' => 0];

    public function __construct(private readonly string $origin = 'web')
    {
        $this->fetcher = new Fetcher();
    }

    /** @return array{busy: bool, batches: int, tasks: int, qualified: int, requests: int, next_in: ?int, running: int} */
    public function run(int $budgetSeconds): array
    {
        if (!Lock::acquire('worker', 0)) {
            return ['busy' => true] + $this->stats + ['next_in' => 5, 'running' => $this->runningCount()];
        }
        try {
            try {
                Database::pdo()->exec('SET SESSION wait_timeout = 900');
            } catch (Throwable) {
                // Not critical.
            }
            $this->deadline = microtime(true) + max(3, $budgetSeconds);
            Settings::set($this->origin === 'cron' ? 'worker.last_cron_at' : 'worker.last_tick_at', Database::now());

            Queue::releaseExpired();
            $this->loop();
            CampaignService::completeFinished();
            $this->housekeeping();
        } finally {
            Lock::release('worker');
        }

        return ['busy' => false] + $this->stats + ['next_in' => Queue::secondsUntilNext(), 'running' => $this->runningCount()];
    }

    private function runningCount(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM campaigns WHERE status = 'running'");
    }

    private function loop(): void
    {
        while (true) {
            $remaining = $this->deadline - microtime(true);
            if ($remaining <= 1.0) {
                return;
            }
            $work = Queue::nextWork();
            if ($work === null) {
                return;
            }
            $campaign = CampaignService::find($work['campaign_id']);
            if ($campaign === null || $campaign['status'] !== 'running') {
                return;
            }
            if ($this->stats['batches'] > 0 && $remaining < $this->minimumTime($work['type'], $campaign)) {
                return; // not enough time left for another batch; the next tick continues
            }

            Database::update('campaigns', ['last_worked_at' => Database::now()], 'id = :id', ['id' => $campaign['id']]);

            $processed = 0;
            try {
                if (!SourceRegistry::has($campaign['source'])) {
                    throw new ApiException('The source "' . $campaign['source'] . '" is no longer installed.', 422, [], 'config');
                }
                $processed = match ($work['type']) {
                    'search' => $this->runSearch($campaign),
                    'detail' => $this->runDetail($campaign),
                    'enrich' => $this->runEnrich($campaign),
                    default => 0,
                };
            } catch (ApiException $e) {
                if ($e->errorCode === 'config') {
                    CampaignService::pause((int) $campaign['id'], $e->getMessage());
                    $processed = 1;
                } else {
                    Logger::error((int) $campaign['id'], $e->getMessage());
                }
            } catch (Throwable $e) {
                App::logException($e);
                Logger::error((int) $campaign['id'], 'Worker error: ' . $e->getMessage());
            }

            $this->stats['batches']++;
            $this->stats['tasks'] += $processed;
            Database::ensureAlive();
            if ($processed === 0) {
                return; // nothing could be processed; avoid spinning
            }
        }
    }

    /** Rough time a batch of this type needs, used to decide whether to start another. */
    private function minimumTime(string $type, array $campaign): float
    {
        if ($type === 'enrich') {
            return min(12, Settings::int('enrich.timeout', 5, 60) + 2);
        }
        $api = Connection::forSource($campaign['source'])->isApi();
        return $api ? 15 : 8;
    }

    private function source(array $campaign): Source
    {
        return SourceRegistry::get($campaign['source']);
    }

    private function delayFor(array $campaign): int
    {
        $override = Settings::source($campaign['source'])['delay_ms'];
        if ($override !== null) {
            return max(0, $override);
        }
        // Scraping APIs rotate IPs themselves; no need to slow down.
        return Connection::forSource($campaign['source'])->isApi() ? 0 : Settings::int('scrape.request_delay_ms', 0, 60000);
    }

    // ---------------------------------------------------------------- search

    private function runSearch(array $campaign): int
    {
        $cid = (int) $campaign['id'];
        $tasks = Queue::claim($cid, 'search', 1, self::LEASE_SECONDS);
        if (!$tasks) {
            return 0;
        }
        $task = $tasks[0];
        $source = $this->source($campaign);
        $options = CampaignService::options($campaign);
        $keyword = (string) ($task['payload']['keyword'] ?? '');
        $location = (string) ($task['payload']['location'] ?? '');
        $page = max(1, (int) ($task['payload']['page'] ?? 1));
        $label = "page {$page} of \"{$keyword}\" in {$location}";

        if (!Throttle::wait('src:' . $source->key(), $this->delayFor($campaign), $this->deadline)) {
            Queue::release($task);
            return 0;
        }

        try {
            $request = $source->searchRequest($keyword, $location, $page);
            $response = $this->fetcher->fetch($request);
        } catch (ApiException $e) {
            Queue::release($task);
            throw $e;
        }
        $this->stats['requests']++;
        CampaignService::bump($cid, ['requests' => 1]);

        if ($response->error !== null) {
            $this->failed($campaign, $task, ucfirst($label) . ': ' . $response->error, fn () => $this->searchExhausted($campaign, $task));
            return 1;
        }
        if ($response->blocked()) {
            $this->blocked($campaign, $task, $response, $label, fn () => $this->searchExhausted($campaign, $task));
            return 1;
        }

        try {
            $result = $source->parseSearch($response, $page);
        } catch (ApiException $e) {
            Queue::release($task);
            throw $e;
        }

        if (!$result['recognized']) {
            if ($response->status >= 400) {
                $this->failed($campaign, $task, ucfirst($label) . ": HTTP {$response->status}", fn () => $this->searchExhausted($campaign, $task));
            } else {
                $response->blockReason = 'Unrecognised page (a bot check or a layout change)';
                $this->blocked($campaign, $task, $response, $label, fn () => $this->searchExhausted($campaign, $task));
            }
            return 1;
        }

        Queue::complete($task['id']);
        Database::update('campaigns', ['blocked_streak' => 0], 'id = :id', ['id' => $cid]);

        $pipeline = new LeadPipeline($campaign, $options, $source);
        $new = $dup = 0;
        foreach ($result['listings'] as $listing) {
            $outcome = $pipeline->ingest($listing, $keyword, $location);
            if ($outcome === 'new') {
                $new++;
            } elseif ($outcome === 'duplicate') {
                $dup++;
            }
        }
        CampaignService::bump($cid, [
            'pages_done' => 1,
            'listings_found' => count($result['listings']),
            'duplicates' => $dup,
        ]);

        if ($result['no_results'] && $page === 1) {
            Logger::warning($cid, "No results for \"{$keyword}\" in {$location}.");
        } else {
            $msg = ucfirst($label) . ': ' . count($result['listings']) . ' listings, ' . $new . ' new';
            if ($dup) {
                $msg .= ", {$dup} duplicate" . ($dup === 1 ? '' : 's');
            }
            if ($page === 1 && $result['total']) {
                $msg .= ' (about ' . number_format($result['total']) . ' results in total)';
            }
            Logger::info($cid, $msg . '.');
        }

        $fresh = CampaignService::find($cid);
        $limitHit = $options->maxLeads > 0 && (int) ($fresh['leads_qualified'] ?? 0) >= $options->maxLeads;
        if ($result['has_more'] && $page < $options->maxPages && !$limitHit && ($fresh['status'] ?? '') === 'running') {
            Queue::push($cid, 'search', ['keyword' => $keyword, 'location' => $location, 'page' => $page + 1]);
        } else {
            CampaignService::shrinkPlan($cid, $options->maxPages - $page);
        }
        return 1;
    }

    private function searchExhausted(array $campaign, array $task): void
    {
        $options = CampaignService::options($campaign);
        $page = max(1, (int) ($task['payload']['page'] ?? 1));
        CampaignService::shrinkPlan((int) $campaign['id'], $options->maxPages - $page + 1);
    }

    // ---------------------------------------------------------------- detail

    private function runDetail(array $campaign): int
    {
        $cid = (int) $campaign['id'];
        $concurrency = Connection::forSource($campaign['source'])->isApi()
            ? Settings::int('scrape.directory_concurrency', 1, 10)
            : 1;
        $tasks = Queue::claim($cid, 'detail', $concurrency, self::LEASE_SECONDS);
        if (!$tasks) {
            return 0;
        }
        $source = $this->source($campaign);
        $pipeline = new LeadPipeline($campaign, CampaignService::options($campaign), $source);

        $jobs = [];
        foreach ($tasks as $task) {
            $leadId = (int) ($task['payload']['lead_id'] ?? 0);
            $listing = $pipeline->loadListing($leadId);
            if ($listing === null) {
                Queue::complete($task['id']);
                continue;
            }
            $request = $source->detailRequest($listing);
            if ($request === null) {
                Queue::complete($task['id']);
                $pipeline->advance($leadId, $listing, 'detail');
                continue;
            }
            $jobs[] = [$task, $leadId, $listing, $request->withTag(count($jobs))];
        }
        if (!$jobs) {
            return count($tasks);
        }

        if (!Throttle::wait('src:' . $source->key(), $this->delayFor($campaign), $this->deadline)) {
            foreach ($jobs as [$task]) {
                Queue::release($task);
            }
            return 0;
        }

        $responses = $this->fetcher->fetchMany(array_column($jobs, 3), $concurrency);
        $this->stats['requests'] += count($responses);
        CampaignService::bump($cid, ['requests' => count($responses)]);

        foreach ($jobs as $i => [$task, $leadId, $listing]) {
            $response = $responses[$i];
            $proceed = function () use ($pipeline, $leadId, $listing): void {
                $pipeline->advance($leadId, $listing, 'detail'); // continue with what we have
            };
            if ($response->error !== null) {
                $this->failed($campaign, $task, 'Business page for ' . $listing->name . ': ' . $response->error, $proceed, false);
                continue;
            }
            if ($response->blocked()) {
                $this->blocked($campaign, $task, $response, 'business page for ' . $listing->name, $proceed);
                continue;
            }
            Database::update('campaigns', ['blocked_streak' => 0], 'id = :id', ['id' => $cid]);
            Queue::complete($task['id']);
            if ($response->status >= 400) {
                $proceed();
                continue;
            }
            $listing = $source->parseDetail($response, $listing);
            $pipeline->save($leadId, $listing);
            $outcome = $pipeline->advance($leadId, $listing, 'detail');
            if ($outcome === 'qualified') {
                $this->logQualified($cid, $listing);
            }
        }
        $this->checkLimit($campaign);
        return count($tasks);
    }

    // ---------------------------------------------------------------- enrich

    private function runEnrich(array $campaign): int
    {
        $cid = (int) $campaign['id'];
        $tasks = Queue::claim($cid, 'enrich', Settings::int('enrich.concurrency', 1, 20), self::LEASE_SECONDS);
        if (!$tasks) {
            return 0;
        }
        $source = $this->source($campaign);
        $pipeline = new LeadPipeline($campaign, CampaignService::options($campaign), $source);

        $leads = [];
        $taskFor = [];
        foreach ($tasks as $task) {
            $leadId = (int) ($task['payload']['lead_id'] ?? 0);
            $listing = $pipeline->loadListing($leadId);
            if ($listing === null) {
                Queue::complete($task['id']);
                continue;
            }
            if (!$listing->has('website')) {
                Queue::complete($task['id']);
                $pipeline->finalize($leadId, $listing);
                continue;
            }
            $leads[$leadId] = $listing;
            $taskFor[$leadId] = $task;
        }
        if (!$leads) {
            return count($tasks);
        }

        $results = (new Enricher($this->fetcher))->run($leads, $campaign['country'], $this->deadline);

        $qualified = $rejected = $emails = $unreachable = 0;
        $reasons = [];
        foreach ($results as $leadId => $r) {
            $listing = $leads[$leadId];
            $this->stats['requests'] += max(1, $r['pages']);
            if ($r['error'] !== null && $r['pages'] === 0) {
                $unreachable++;
                $listing->meta['website_error'] = $r['error'];
            }
            $listing->emails = array_values(array_unique(array_merge($listing->emails, $r['emails'])));
            if (!$listing->has('email') && $r['emails']) {
                $listing->email = $r['emails'][0];
            }
            $listing->socials = $listing->socials + $r['socials'];
            if (!$listing->has('phone') && $r['phones']) {
                $listing->phone = $r['phones'][0];
            }
            if ($r['emails']) {
                $emails++;
            }
            Queue::complete($taskFor[$leadId]['id']);
            $outcome = $pipeline->finalize($leadId, $listing);
            if ($outcome === 'qualified') {
                $qualified++;
                $this->logQualified($cid, $listing);
            } elseif ($outcome === 'rejected') {
                $rejected++;
                $reason = \App\Scraper\LeadFilter::evaluate($listing, CampaignService::options($campaign));
                if ($reason !== null) {
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                }
            }
        }
        CampaignService::bump($cid, [
            'websites_crawled' => count($results),
            'requests' => array_sum(array_map(static fn ($r) => max(1, $r['pages']), $results)),
        ]);

        $msg = 'Crawled ' . count($results) . ' website' . (count($results) === 1 ? '' : 's') . ": emails on {$emails}";
        if ($unreachable) {
            $msg .= ", {$unreachable} unreachable";
        }
        if ($rejected) {
            arsort($reasons);
            $msg .= ", {$rejected} rejected (" . implode('; ', array_map(static fn ($k, $v) => "{$v}× " . lcfirst($k), array_keys($reasons), $reasons)) . ')';
        }
        Logger::info($cid, $msg . '.');
        $this->checkLimit($campaign);
        return count($tasks);
    }

    // --------------------------------------------------------------- helpers

    private function logQualified(int $cid, Listing $listing): void
    {
        $this->stats['qualified']++;
        $bits = array_filter([$listing->email, $listing->phone, $listing->website ? preg_replace('~^https?://(www\.)?~', '', rtrim($listing->website, '/')) : null]);
        Logger::success($cid, 'New lead: ' . $listing->name . ($bits ? ' — ' . implode(' · ', array_slice($bits, 0, 2)) : ''));
    }

    private function checkLimit(array $campaign): void
    {
        $options = CampaignService::options($campaign);
        if ($options->maxLeads <= 0) {
            return;
        }
        $qualified = (int) Database::value('SELECT leads_qualified FROM campaigns WHERE id = :id', ['id' => $campaign['id']]);
        if ($qualified >= $options->maxLeads) {
            CampaignService::complete((int) $campaign['id'], "Target reached — {$qualified} qualified leads collected.");
        }
    }

    /** A request failed at the network level: retry with backoff, then give up gracefully. */
    private function failed(array $campaign, array $task, string $message, callable $onExhausted, bool $countError = true): void
    {
        $cid = (int) $campaign['id'];
        if ($countError) {
            CampaignService::bump($cid, ['errors' => 1]);
        }
        if (Queue::retry($task, $message, 20)) {
            Logger::warning($cid, $message . ' — will retry.');
            return;
        }
        Logger::error($cid, $message . ' — giving up after ' . $task['attempts'] . ' attempts.');
        Database::update('campaigns', ['last_error' => mb_substr($message, 0, 1000)], 'id = :id', ['id' => $cid]);
        $onExhausted();
    }

    /** The directory served a bot wall. Back off; pause the campaign if it keeps happening. */
    private function blocked(array $campaign, array $task, HttpResponse $response, string $label, callable $onExhausted): void
    {
        $cid = (int) $campaign['id'];
        CampaignService::bump($cid, ['errors' => 1, 'blocked_streak' => 1]);
        $streak = (int) Database::value('SELECT blocked_streak FROM campaigns WHERE id = :id', ['id' => $cid]);
        $threshold = Settings::int('scrape.block_pause_threshold', 1, 50);
        $connection = Connection::forSource($campaign['source']);
        $reason = (string) $response->blockReason;
        Logger::warning($cid, 'Blocked on ' . $label . ': ' . $reason . ' (via ' . $connection->label() . ').');

        if ($streak >= $threshold) {
            // Keep the task for when the campaign is resumed.
            Database::update('tasks', [
                'status' => 'pending',
                'attempts' => 0,
                'locked_until' => null,
                'available_at' => Database::now(),
                'last_error' => mb_substr($reason, 0, 1000),
            ], 'id = :id', ['id' => $task['id']]);
            $advice = $connection->isApi()
                ? 'Try enabling premium proxies and/or JavaScript rendering in Settings → Connection, then resume.'
                : 'This site blocks requests from your server. Configure a scraping API or proxies in Settings → Connection, then resume.';
            CampaignService::pause($cid, "{$streak} blocked requests in a row ({$reason}). {$advice}");
            return;
        }
        if (!Queue::retry($task, $reason, 45)) {
            Logger::error($cid, 'Giving up on ' . $label . ' after repeated blocks.');
            $onExhausted();
        }
    }

    /** Hourly clean-up of old logs, expired tokens and orphaned tasks. */
    private function housekeeping(): void
    {
        $last = (string) Settings::get('worker.last_housekeeping_at', '');
        if ($last !== '' && strtotime($last . ' UTC') > time() - 3600) {
            return;
        }
        Settings::set('worker.last_housekeeping_at', Database::now());
        $days = Settings::int('data.log_retention_days', 1, 3650);
        Database::query('DELETE FROM logs WHERE created_at < :t', ['t' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        Database::query('DELETE FROM login_attempts WHERE created_at < :t', ['t' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        Database::query('DELETE FROM auth_tokens WHERE expires_at < :t', ['t' => Database::now()]);
        Database::query('DELETE FROM tasks WHERE campaign_id NOT IN (SELECT id FROM campaigns)');
        Database::query('DELETE FROM leads WHERE campaign_id NOT IN (SELECT id FROM campaigns)');
        // Stragglers queued by a batch that was still running when a campaign was stopped/completed.
        Database::query("DELETE t FROM tasks t JOIN campaigns c ON c.id = t.campaign_id WHERE c.status IN ('completed','stopped') AND t.status <> 'running'");
    }
}
