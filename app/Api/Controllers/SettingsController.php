<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Request;
use App\Core\App;
use App\Core\Database;
use App\Core\Settings;
use App\Http\Connection;
use App\Http\Fetcher;
use App\Http\HttpRequest;

final class SettingsController
{
    private const MASK = '********';

    /** key => [type, min, max] for numeric; [type] otherwise. */
    private const SCHEMA = [
        'app.name' => ['string', 1, 40],
        'connection.mode' => ['enum', Connection::MODES],
        'connection.proxies' => ['text', 0, 20000],
        'connection.proxy_verify_ssl' => ['bool'],
        'connection.scraperapi_key' => ['secret'],
        'connection.scrapingbee_key' => ['secret'],
        'connection.zenrows_key' => ['secret'],
        'connection.scrapedo_key' => ['secret'],
        'connection.custom_template' => ['text', 0, 2000],
        'connection.render_js' => ['bool'],
        'connection.premium' => ['bool'],
        'connection.geo_target' => ['bool'],
        'connection.website_mode' => ['enum', ['direct' => 1, 'same' => 1]],
        'scrape.request_delay_ms' => ['int', 0, 60000],
        'scrape.timeout' => ['int', 5, 120],
        'scrape.api_timeout' => ['int', 20, 180],
        'scrape.block_pause_threshold' => ['int', 1, 50],
        'scrape.directory_concurrency' => ['int', 1, 10],
        'enrich.pages_per_site' => ['int', 1, 10],
        'enrich.concurrency' => ['int', 1, 20],
        'enrich.timeout' => ['int', 5, 60],
        'enrich.stop_when_found' => ['bool'],
        'worker.browser_enabled' => ['bool'],
        'worker.web_budget' => ['int', 5, 120],
        'worker.cron_budget' => ['int', 10, 300],
        'data.keep_rejected' => ['bool'],
        'data.log_retention_days' => ['int', 1, 3650],
    ];

    public function get(Request $request, ?array $user): array
    {
        $values = [];
        foreach (self::SCHEMA as $key => $rule) {
            $value = Settings::get($key);
            if ($rule[0] === 'secret') {
                $value = trim((string) $value) !== '' ? self::MASK : '';
            }
            $values[$key] = $value;
        }
        return [
            'values' => $values,
            'connection_modes' => Connection::MODES,
            'cron' => WorkerController::cronInfo(),
            'worker' => WorkerController::health(),
        ];
    }

    public function update(Request $request, ?array $user): array
    {
        $input = $request->arr('values');
        $errors = [];
        $clean = [];
        foreach ($input as $key => $value) {
            $rule = self::SCHEMA[$key] ?? null;
            if ($rule === null) {
                continue;
            }
            switch ($rule[0]) {
                case 'bool':
                    $clean[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'int':
                    if (!is_numeric($value)) {
                        $errors[$key] = 'Enter a number.';
                        break;
                    }
                    $int = (int) $value;
                    if ($int < $rule[1] || $int > $rule[2]) {
                        $errors[$key] = "Use a value between {$rule[1]} and {$rule[2]}.";
                        break;
                    }
                    $clean[$key] = $int;
                    break;
                case 'enum':
                    if (!is_string($value) || !isset($rule[1][$value])) {
                        $errors[$key] = 'Choose a valid option.';
                        break;
                    }
                    $clean[$key] = $value;
                    break;
                case 'secret':
                    $value = trim((string) $value);
                    if ($value !== self::MASK) {
                        $clean[$key] = $value;
                    }
                    break;
                case 'string':
                case 'text':
                    $value = $rule[0] === 'string' ? trim((string) $value) : str_replace("\r\n", "\n", (string) $value);
                    if (mb_strlen($value) < $rule[1] || mb_strlen($value) > $rule[2]) {
                        $errors[$key] = $rule[1] > 0 ? "Use {$rule[1]}–{$rule[2]} characters." : "Maximum {$rule[2]} characters.";
                        break;
                    }
                    $clean[$key] = $value;
                    break;
            }
        }

        $template = $clean['connection.custom_template'] ?? null;
        if ($template !== null && $template !== '' && (!preg_match('~^https?://~i', $template) || (!str_contains($template, '{url}') && !str_contains($template, '{url_raw}')))) {
            $errors['connection.custom_template'] = 'Must be an http(s) URL containing {url}.';
        }
        if (isset($clean['connection.proxies'])) {
            foreach (preg_split('/[\r\n,]+/', $clean['connection.proxies']) ?: [] as $i => $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] !== '#' && Connection::parseProxy($line) === null) {
                    $errors['connection.proxies'] = 'Line ' . ($i + 1) . ' is not a valid proxy (' . mb_substr($line, 0, 40) . ').';
                    break;
                }
            }
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
        Settings::setMany($clean);
        Settings::flush();
        return $this->get($request, $user);
    }

    public function testConnection(Request $request, ?array $user): array
    {
        $mode = $request->str('mode', (string) Settings::get('connection.mode'), 20);
        if (!isset(Connection::MODES[$mode])) {
            throw ApiException::validation(['connection.mode' => 'Choose a valid connection type.']);
        }
        // Test the values currently in the form, even if not saved yet.
        $overrides = [];
        foreach (['scraperapi_key', 'scrapingbee_key', 'zenrows_key', 'scrapedo_key', 'custom_template', 'proxies'] as $k) {
            if ($request->has($k)) {
                $v = (string) $request->raw($k);
                if ($v !== self::MASK) {
                    $overrides[$k] = $v;
                }
            }
        }
        foreach (['render_js', 'premium', 'geo_target'] as $k) {
            if ($request->has($k)) {
                $overrides[$k] = $request->bool($k);
            }
        }

        $targets = [
            'ip' => ['https://api.ipify.org/?format=json', null],
            'yellowpages_au' => ['https://www.yellowpages.com.au/', 'AU'],
            'yelp' => ['https://www.yelp.com/', 'US'],
        ];
        $targetKey = $request->str('target', 'ip', 30);
        [$url, $country] = $targets[$targetKey] ?? $targets['ip'];

        App::extendTimeLimit(200);
        $connection = Connection::make($mode, $overrides);
        $response = (new Fetcher($connection))->fetch(new HttpRequest($url, 'directory', [], $targetKey === 'ip' ? null : $targetKey, $country));

        $ip = null;
        if ($targetKey === 'ip' && $response->ok()) {
            $json = $response->json();
            $ip = is_array($json) ? ($json['ip'] ?? null) : trim(strip_tags($response->body));
        }
        $ok = $response->ok() && !$response->blocked();
        return [
            'ok' => $ok,
            'via' => $connection->label(),
            'http_status' => $response->status,
            'time_ms' => $response->timeMs,
            'bytes' => strlen($response->body),
            'ip' => $ip,
            'message' => $ok
                ? ($ip ? "Connected. Requests leave from IP {$ip}." : 'Connected — the page loaded without a bot wall.')
                : ($response->error ?? $response->blockReason ?? ('HTTP ' . $response->status)),
        ];
    }

    public function maintenance(Request $request, ?array $user): array
    {
        $action = $request->str('action', '', 30);
        switch ($action) {
            case 'clear_logs':
                $n = Database::query('DELETE FROM logs')->rowCount();
                return ['message' => "Deleted {$n} log entries."];
            case 'purge_rejected':
                $counts = Database::all("SELECT campaign_id, COUNT(*) AS n FROM leads WHERE status = 'rejected' GROUP BY campaign_id");
                $n = Database::query("DELETE FROM leads WHERE status = 'rejected'")->rowCount();
                foreach ($counts as $c) {
                    Database::query('UPDATE campaigns SET leads_rejected = GREATEST(CAST(leads_rejected AS SIGNED) - :n, 0) WHERE id = :id', ['n' => (int) $c['n'], 'id' => (int) $c['campaign_id']]);
                }
                return ['message' => "Deleted {$n} rejected leads."];
            case 'reset_cookies':
                $n = 0;
                foreach (glob(App::storagePath('cookies/*.txt')) ?: [] as $file) {
                    if (@unlink($file)) {
                        $n++;
                    }
                }
                return ['message' => "Cleared {$n} cookie jar" . ($n === 1 ? '' : 's') . '.'];
            default:
                throw new ApiException('Unknown maintenance action.', 422);
        }
    }
}
