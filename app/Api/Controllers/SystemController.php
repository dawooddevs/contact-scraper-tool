<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Request;
use App\Core\App;
use App\Core\Database;

final class SystemController
{
    public function info(Request $request, ?array $user): array
    {
        $extensions = [];
        foreach (['curl', 'pdo_mysql', 'mbstring', 'dom', 'json', 'openssl', 'intl', 'zip'] as $ext) {
            $extensions[$ext] = extension_loaded($ext);
        }
        $curl = function_exists('curl_version') ? curl_version() : [];
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        $dbSize = Database::value(
            'SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()'
        );
        $counts = [
            'campaigns' => (int) Database::value('SELECT COUNT(*) FROM campaigns'),
            'leads' => (int) Database::value('SELECT COUNT(*) FROM leads'),
            'logs' => (int) Database::value('SELECT COUNT(*) FROM logs'),
            'tasks' => (int) Database::value('SELECT COUNT(*) FROM tasks'),
        ];

        return [
            'app_version' => APP_VERSION,
            'php_version' => PHP_VERSION,
            'server' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
            'mysql_version' => (string) Database::value('SELECT VERSION()'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'set_time_limit' => function_exists('set_time_limit') && !in_array('set_time_limit', $disabled, true),
            'curl_version' => (string) ($curl['version'] ?? 'n/a'),
            'curl_http2' => isset($curl['features']) && defined('CURL_VERSION_HTTP2') && ($curl['features'] & CURL_VERSION_HTTP2),
            'extensions' => $extensions,
            'storage_writable' => is_writable(App::storagePath()),
            'db_size_bytes' => (int) ($dbSize ?? 0),
            'counts' => $counts,
        ];
    }
}
