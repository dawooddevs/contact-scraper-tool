<?php

declare(strict_types=1);

/*
 * Web cron endpoint for hosts/services that can only call a URL:
 *   https://your-domain/cron.php?key=YOUR_CRON_KEY
 * The key is shown in Settings → Worker & cron. Prefer the CLI cron
 * (bin/cron.php) when your host supports it.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Settings;
use App\Scraper\Worker;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!App::isInstalled()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'not installed']);
    exit;
}

try {
    $key = (string) ($_GET['key'] ?? '');
    if ($key === '' || !hash_equals(Settings::cronKey(), $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'invalid key']);
        exit;
    }
    App::keepRunningIfDisconnected();
    $budget = Settings::int('worker.cron_budget', 10, 300);
    $limit = App::extendTimeLimit($budget + 120);
    if ($limit > 0) {
        $budget = max(5, min($budget, $limit - 20));
    }
    $result = (new Worker('cron'))->run($budget);
    echo json_encode(['ok' => true] + $result);
} catch (Throwable $e) {
    App::logException($e);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'worker error']);
}
