<?php

declare(strict_types=1);

/*
 * Installs a fresh copy through the web installer and points the scraper at
 * the mock internet, for browser (Playwright) tests.
 *
 *   php tests/setup-ui.php http://127.0.0.1:8088 <db> <user> <pass>
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8088', '/');
$db = ['db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => $argv[2] ?? 'scrap_test', 'db_user' => $argv[3] ?? 'scrap', 'db_pass' => $argv[4] ?? 'scrap_pass'];
$jar = tempnam(sys_get_temp_dir(), 'scrapui');

function post(string $url, array $body, array $headers = []): array
{
    global $jar;
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = "{$k}: {$v}";
    }
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $out = json_decode((string) curl_exec($ch), true) ?: [];
    curl_close($ch);
    return $out;
}

$res = post($base . '/install.php', ['action' => 'install', 'admin_name' => 'Dawood Ahmed', 'admin_email' => 'dawood@example.test', 'admin_password' => 'demo-password'] + $db);
if (empty($res['ok'])) {
    fwrite(STDERR, 'Install failed: ' . json_encode($res) . PHP_EOL);
    exit(1);
}
$configFile = dirname(__DIR__) . '/storage/config.php';
$config = require $configFile;
$config['allow_private_network'] = true;
file_put_contents($configFile, "<?php\nreturn " . var_export($config, true) . ";\n");

$ch = curl_init($base . '/api.php?r=auth.me');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
$me = json_decode((string) curl_exec($ch), true);
unset($ch); // flushes the cookie jar (curl_close() is a no-op since PHP 8)
$csrf = $me['data']['csrf'];
$login = post($base . '/api.php?r=auth.login', ['email' => 'dawood@example.test', 'password' => 'demo-password'], ['X-CSRF-Token' => $csrf]);
$csrf = $login['data']['csrf'];
post($base . '/api.php?r=settings.update', ['values' => [
    'connection.mode' => 'custom',
    'connection.custom_template' => 'http://127.0.0.1:8099/api?url={url}',
    'scrape.request_delay_ms' => 300,
    'enrich.timeout' => 10,
]], ['X-CSRF-Token' => $csrf]);
echo "ready\n";
