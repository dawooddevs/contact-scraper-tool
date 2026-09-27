<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Request;
use App\Core\App;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;
use App\Scraper\CampaignService;
use App\Scraper\Worker;

final class WorkerController
{
    /** Run one time-boxed worker tick on behalf of the open dashboard. */
    public function tick(Request $request, ?array $user): array
    {
        if (!Settings::bool('worker.browser_enabled')) {
            $running = (int) Database::value("SELECT COUNT(*) FROM campaigns WHERE status = 'running'");
            return ['disabled' => true, 'busy' => false, 'running' => $running, 'next_in' => null] + self::snapshot();
        }
        App::keepRunningIfDisconnected();
        $budget = Settings::int('worker.web_budget', 5, 120);
        $limit = App::extendTimeLimit($budget + 120);
        if ($limit > 0) {
            // The host enforces a hard limit: leave room for the slowest request.
            $budget = max(5, min($budget, $limit - 20));
        }
        $result = (new Worker('web'))->run($budget);
        return $result + ['disabled' => false] + self::snapshot();
    }

    public function status(Request $request, ?array $user): array
    {
        return self::health() + self::snapshot();
    }

    private static function snapshot(): array
    {
        $rows = Database::all("SELECT * FROM campaigns WHERE status IN ('running','paused') OR (finished_at IS NOT NULL AND finished_at >= :t)", [
            't' => gmdate('Y-m-d H:i:s', time() - 120),
        ]);
        return ['campaigns' => array_map(static fn ($c) => CampaignService::present($c), $rows)];
    }

    public static function health(): array
    {
        $lastCron = Settings::get('worker.last_cron_at');
        $lastTick = Settings::get('worker.last_tick_at');
        $cronActive = is_string($lastCron) && strtotime($lastCron . ' UTC') >= time() - 180;
        return [
            'last_cron_at' => is_string($lastCron) ? CampaignService::iso($lastCron) : null,
            'last_tick_at' => is_string($lastTick) ? CampaignService::iso($lastTick) : null,
            'cron_active' => $cronActive,
            'browser_enabled' => Settings::bool('worker.browser_enabled'),
            'running' => (int) Database::value("SELECT COUNT(*) FROM campaigns WHERE status = 'running'"),
            'queued_tasks' => (int) Database::value("SELECT COUNT(*) FROM tasks t JOIN campaigns c ON c.id = t.campaign_id WHERE t.status IN ('pending','running') AND c.status = 'running'"),
        ];
    }

    public static function cronInfo(): array
    {
        $script = APP_ROOT . '/bin/cron.php';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'your-domain');
        $scheme = Auth::isHttps() ? 'https' : 'http';
        // Use the public URL path (REQUEST_URI), not SCRIPT_NAME, which includes /public when rewritten.
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/api.php'), PHP_URL_PATH);
        $base = rtrim(str_replace('\\', '/', dirname($path === '' ? '/api.php' : $path)), '/');
        $webUrl = $scheme . '://' . $host . $base . '/cron.php?key=' . Settings::cronKey();
        return [
            'command' => '/usr/local/bin/php ' . $script . ' >/dev/null 2>&1',
            'schedule' => '* * * * *',
            'script' => $script,
            'web_url' => $webUrl,
            'web_command' => 'curl -s "' . $webUrl . '" >/dev/null 2>&1',
        ];
    }
}
