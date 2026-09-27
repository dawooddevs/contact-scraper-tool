<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Key/value settings persisted in the `settings` table (JSON encoded).
 * Defaults live here so a fresh install works without any configuration.
 */
final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        // Connection used for directory pages (Yellow Pages, Yelp, ...).
        'connection.mode' => 'direct', // direct|proxy|scraperapi|scrapingbee|zenrows|scrapedo|custom
        'connection.proxies' => '',
        'connection.proxy_verify_ssl' => true,
        'connection.scraperapi_key' => '',
        'connection.scrapingbee_key' => '',
        'connection.zenrows_key' => '',
        'connection.scrapedo_key' => '',
        'connection.custom_template' => '',
        'connection.render_js' => false,
        'connection.premium' => false,
        'connection.geo_target' => true,
        // Connection used for business websites (email discovery).
        'connection.website_mode' => 'direct', // direct|same

        // Scraping behaviour.
        'scrape.request_delay_ms' => 1500,
        'scrape.timeout' => 30,
        'scrape.api_timeout' => 70,
        'scrape.retries' => 2,
        'scrape.block_pause_threshold' => 3,
        'scrape.directory_concurrency' => 1,
        'scrape.yelp_api_key' => '',

        // Website crawling for emails & socials.
        'enrich.pages_per_site' => 4,
        'enrich.concurrency' => 8,
        'enrich.timeout' => 15,
        'enrich.stop_when_found' => true,
        'enrich.skip_free_email' => false,

        // Worker.
        'worker.browser_enabled' => true,
        'worker.web_budget' => 20,
        'worker.cron_budget' => 50,
        'worker.cron_key' => '',
        'worker.last_cron_at' => null,
        'worker.last_tick_at' => null,

        // Data.
        'data.keep_rejected' => true,
        'data.log_retention_days' => 30,

        // Per-source overrides: sources.<key> => ['enabled' => bool, 'connection' => 'default'|mode, 'delay_ms' => int|null]
        'sources' => [],

        'app.name' => 'Scrap',
    ];

    /** Keys whose values must never be sent to the browser in clear text. */
    public const SECRET_KEYS = [
        'connection.scraperapi_key',
        'connection.scrapingbee_key',
        'connection.zenrows_key',
        'connection.scrapedo_key',
        'scrape.yelp_api_key',
    ];

    private static function load(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::all('SELECT `key`, `value` FROM `settings`') as $row) {
                self::$cache[$row['key']] = json_decode((string) $row['value'], true);
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::load();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        return self::DEFAULTS[$key] ?? $default;
    }

    public static function int(string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        return max($min, min($max, (int) self::get($key)));
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    public static function set(string $key, mixed $value): void
    {
        Database::query(
            'INSERT INTO `settings` (`key`, `value`, `updated_at`) VALUES (:k, :v, :t)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`)',
            ['k' => $key, 'v' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 't' => Database::now()]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            self::set((string) $key, $value);
        }
    }

    /** All known settings merged with defaults. */
    public static function all(): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key(self::load(), self::DEFAULTS));
    }

    public static function source(string $key): array
    {
        $sources = self::get('sources', []);
        $cfg = is_array($sources[$key] ?? null) ? $sources[$key] : [];
        return [
            'enabled' => (bool) ($cfg['enabled'] ?? true),
            'connection' => (string) ($cfg['connection'] ?? 'default'),
            'delay_ms' => isset($cfg['delay_ms']) && $cfg['delay_ms'] !== '' ? (int) $cfg['delay_ms'] : null,
            'mode' => (string) ($cfg['mode'] ?? ''),
        ];
    }

    public static function setSource(string $key, array $values): void
    {
        $sources = self::get('sources', []);
        if (!is_array($sources)) {
            $sources = [];
        }
        $sources[$key] = array_merge(self::source($key), $values);
        self::set('sources', $sources);
    }

    /** Seed the cache without touching the database (tests, installer). */
    public static function preload(array $values): void
    {
        self::$cache = $values;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function cronKey(): string
    {
        $key = (string) self::get('worker.cron_key', '');
        if ($key === '') {
            $key = bin2hex(random_bytes(16));
            self::set('worker.cron_key', $key);
        }
        return $key;
    }
}
