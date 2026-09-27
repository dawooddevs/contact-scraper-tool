<?php

declare(strict_types=1);

/*
 * Application bootstrap: autoloader, configuration, error handling.
 * Every entry point (public/api.php, public/index.php, bin/cron.php, ...)
 * requires this file first.
 */

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Scrap requires PHP 8.1 or newer. You are running PHP ' . PHP_VERSION . '. Select a newer version in cPanel → MultiPHP Manager.');
}

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

App\Core\App::boot();
