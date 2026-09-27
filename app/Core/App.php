<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Global application state: configuration, paths, logging and error handling.
 */
final class App
{
    /** Bump when a new migration is added to database/migrations. */
    public const SCHEMA_VERSION = 1;

    private static array $config = [];
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        mb_internal_encoding('UTF-8');
        date_default_timezone_set('UTC');
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $severity)) {
                return false; // silenced with @
            }
            if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE], true)) {
                self::log('notice', $message . ' in ' . $file . ':' . $line);
                return true;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        $configFile = self::configPath();
        if (is_file($configFile)) {
            $config = require $configFile;
            self::$config = is_array($config) ? $config : [];
        }
    }

    public static function configPath(): string
    {
        return APP_ROOT . '/storage/config.php';
    }

    public static function isInstalled(): bool
    {
        return !empty(self::$config['db']) && !empty(self::$config['installed_at']);
    }

    /** Read a config value using dot notation, e.g. App::config('db.host'). */
    public static function config(string $key, mixed $default = null): mixed
    {
        $value = self::$config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    public static function storagePath(string $path = ''): string
    {
        $base = APP_ROOT . '/storage';
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }

    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    /** Append a line to storage/logs/app.log (rotated at ~5 MB). */
    public static function log(string $level, string $message, array $context = []): void
    {
        try {
            $dir = self::storagePath('logs');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $file = $dir . '/app.log';
            if (is_file($file) && filesize($file) > 5 * 1024 * 1024) {
                @rename($file, $dir . '/app-' . gmdate('Ymd-His') . '.log');
            }
            $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . strtoupper($level) . ': ' . $message;
            if ($context) {
                $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Logging must never break the request.
        }
    }

    public static function logException(Throwable $e): void
    {
        self::log('error', get_class($e) . ': ' . $e->getMessage(), [
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }

    /** Finish the current batch even if the browser/cron client disconnects. */
    public static function keepRunningIfDisconnected(): void
    {
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }

    /** Try to raise the execution time limit; returns the effective limit (0 = unlimited). */
    public static function extendTimeLimit(int $seconds): int
    {
        if (self::isCli()) {
            return 0;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (function_exists('set_time_limit') && !in_array('set_time_limit', $disabled, true)) {
            if (@set_time_limit($seconds)) {
                return $seconds;
            }
        }
        return (int) ini_get('max_execution_time');
    }
}
