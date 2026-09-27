<?php

declare(strict_types=1);

/*
 * Background worker. Add to cPanel → Cron Jobs, running every minute:
 *
 *   * * * * *  /usr/local/bin/php /home/USER/scrap.dawood.top/bin/cron.php >/dev/null 2>&1
 *
 * Each run works for up to "Cron budget" seconds (Settings → Worker & cron)
 * and exits, so runs never overlap for long. A database lock guarantees
 * that only one worker is active at a time.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Migrator;
use App\Core\Settings;
use App\Scraper\Worker;

if (!App::isInstalled()) {
    fwrite(STDERR, "Scrap is not installed yet.\n");
    exit(1);
}

try {
    if (Migrator::needsMigration()) {
        Migrator::migrate();
    }
    $budget = Settings::int('worker.cron_budget', 10, 300);
    if (isset($argv[1]) && is_numeric($argv[1])) {
        $budget = max(1, (int) $argv[1]);
    }
    $result = (new Worker('cron'))->run($budget);
    if (in_array('-v', $argv, true) || in_array('--verbose', $argv, true)) {
        echo json_encode($result, JSON_PRETTY_PRINT), PHP_EOL;
    }
    exit(0);
} catch (Throwable $e) {
    App::logException($e);
    fwrite(STDERR, 'Worker error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
