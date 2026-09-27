<?php

declare(strict_types=1);

/*
 * One-time web installer. Checks requirements, tests the MySQL database
 * created in cPanel, writes storage/config.php, creates the tables and the
 * admin account. Disabled automatically once installed.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Settings;

function install_requirements(): array
{
    $storage = App::storagePath();
    foreach (['cache', 'logs', 'cookies'] as $dir) {
        if (!is_dir($storage . '/' . $dir)) {
            @mkdir($storage . '/' . $dir, 0775, true);
        }
    }
    return [
        ['label' => 'PHP 8.1 or newer', 'ok' => PHP_VERSION_ID >= 80100, 'detail' => 'You have PHP ' . PHP_VERSION],
        ['label' => 'PDO MySQL extension', 'ok' => extension_loaded('pdo_mysql'), 'detail' => 'Enable pdo_mysql in cPanel → Select PHP Version'],
        ['label' => 'cURL extension', 'ok' => extension_loaded('curl'), 'detail' => 'Needed to fetch pages'],
        ['label' => 'DOM / XML extension', 'ok' => extension_loaded('dom'), 'detail' => 'Needed to read pages'],
        ['label' => 'mbstring extension', 'ok' => extension_loaded('mbstring'), 'detail' => 'Needed for text handling'],
        ['label' => 'storage/ folder writable', 'ok' => is_writable($storage), 'detail' => 'Set permissions of ' . $storage . ' to 755 (or 775)'],
    ];
}

function install_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function install_db_config(array $in): array
{
    return [
        'host' => trim((string) ($in['db_host'] ?? 'localhost')) ?: 'localhost',
        'port' => (int) ($in['db_port'] ?? 3306) ?: 3306,
        'name' => trim((string) ($in['db_name'] ?? '')),
        'user' => trim((string) ($in['db_user'] ?? '')),
        'pass' => (string) ($in['db_pass'] ?? ''),
    ];
}

/** @return array{ok: bool, message: string, pdo?: PDO} */
function install_connect(array $db): array
{
    if ($db['name'] === '' || $db['user'] === '') {
        return ['ok' => false, 'message' => 'Enter the database name and user you created in cPanel → MySQL® Databases.'];
    }
    try {
        $pdo = Database::connect($db);
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $pdo->exec('CREATE TABLE IF NOT EXISTS `scrap_install_check` (`id` INT) ENGINE=InnoDB');
        $pdo->exec('DROP TABLE `scrap_install_check`');
        return ['ok' => true, 'message' => 'Connected to MySQL ' . $version . '.', 'pdo' => $pdo];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Access denied')) {
            $hint = 'Check the username/password, and that the user is added to the database with ALL PRIVILEGES (cPanel → MySQL® Databases → Add User To Database).';
        } elseif (str_contains($msg, 'Unknown database')) {
            $hint = 'The database does not exist. On cPanel its full name usually starts with your account prefix, e.g. cpaneluser_scrap.';
        } elseif (str_contains($msg, 'getaddrinfo') || str_contains($msg, 'Connection refused') || str_contains($msg, "Can't connect")) {
            $hint = 'Could not reach the database server. On cPanel the host is almost always "localhost".';
        } else {
            $hint = 'Check the details and try again.';
        }
        return ['ok' => false, 'message' => $hint . ' (' . $msg . ')'];
    }
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    if (App::isInstalled()) {
        install_json(['ok' => false, 'message' => 'Scrap is already installed.'], 403);
    }
    $raw = file_get_contents('php://input') ?: '';
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        install_json(['ok' => false, 'message' => 'Malformed request.'], 400);
    }
    $action = (string) ($in['action'] ?? '');

    if ($action === 'requirements') {
        $checks = install_requirements();
        install_json(['ok' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks]);
    }

    if ($action === 'test_db') {
        $result = install_connect(install_db_config($in));
        unset($result['pdo']);
        install_json($result);
    }

    if ($action === 'install') {
        $checks = install_requirements();
        if (in_array(false, array_column($checks, 'ok'), true)) {
            install_json(['ok' => false, 'message' => 'Some requirements are not met.'], 422);
        }
        $db = install_db_config($in);
        $conn = install_connect($db);
        if (!$conn['ok']) {
            install_json(['ok' => false, 'message' => $conn['message'], 'step' => 'database'], 422);
        }

        $name = trim((string) ($in['admin_name'] ?? ''));
        $email = mb_strtolower(trim((string) ($in['admin_email'] ?? '')));
        $password = (string) ($in['admin_password'] ?? '');
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['admin_name'] = 'Enter your name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = 'Enter a valid email address.';
        }
        if (mb_strlen($password) < 8) {
            $errors['admin_password'] = 'Use at least 8 characters.';
        }
        if ($errors) {
            install_json(['ok' => false, 'message' => 'Please fix the highlighted fields.', 'fields' => $errors, 'step' => 'admin'], 422);
        }

        try {
            Database::setPdo($conn['pdo']);
            App::setConfig(['db' => $db]);
            Migrator::migrate();

            $now = Database::now();
            $existing = Database::value('SELECT id FROM users WHERE email = :e', ['e' => $email]);
            if ($existing) {
                Database::update('users', ['name' => $name, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'updated_at' => $now], 'id = :id', ['id' => $existing]);
            } else {
                Database::insert('users', [
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            Settings::flush();
            Settings::cronKey();

            $config = [
                'db' => $db,
                'app_key' => bin2hex(random_bytes(32)),
                'installed_at' => gmdate('c'),
                'allow_private_network' => false,
            ];
            $php = "<?php\n\n// Generated by the Scrap installer. Keep this file private.\n\nreturn " . var_export($config, true) . ";\n";
            $file = App::configPath();
            if (@file_put_contents($file, $php, LOCK_EX) === false) {
                install_json(['ok' => false, 'message' => 'Could not write ' . $file . '. Make the storage folder writable and try again.'], 500);
            }
            @chmod($file, 0640);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }

            install_json([
                'ok' => true,
                'message' => 'Installed.',
                'cron' => '/usr/local/bin/php ' . APP_ROOT . '/bin/cron.php >/dev/null 2>&1',
            ]);
        } catch (Throwable $e) {
            App::logException($e);
            install_json(['ok' => false, 'message' => 'Installation failed: ' . $e->getMessage()], 500);
        }
    }

    install_json(['ok' => false, 'message' => 'Unknown action.'], 400);
}

$installed = App::isInstalled();
$version = substr(md5((string) @filemtime(__DIR__ . '/assets/css/app.css') . (string) @filemtime(__DIR__ . '/assets/js/install.js')), 0, 10);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Install Scrap</title>
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&display=swap">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= htmlspecialchars($version) ?>">
  <?php if (!$installed): ?><script type="module" src="assets/js/install.js?v=<?= htmlspecialchars($version) ?>"></script><?php endif; ?>
</head>
<body>
  <div class="auth">
    <div class="auth-bg"><div class="blob b1"></div><div class="blob b2"></div><div class="blob b3"></div><div class="auth-grid"></div></div>
    <div class="auth-card" style="width:min(560px,100%)" id="installer">
      <div class="brand"><img src="assets/img/favicon.svg" class="brand-mark" alt=""></div>
      <?php if ($installed): ?>
        <h1>Scrap is installed</h1>
        <p class="sub">The installer is disabled. To reinstall, delete <code>storage/config.php</code>.</p>
        <a class="btn btn-primary btn-lg btn-block" href="./">Open Scrap</a>
      <?php else: ?>
        <h1>Install Scrap</h1>
        <p class="sub">Three quick steps and you’re collecting leads.</p>
        <div id="install-root"><div class="skeleton sk-block"></div></div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
