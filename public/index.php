<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;

if (!App::isInstalled()) {
    header('Location: install.php', true, 302);
    exit;
}

/** Cache-busting version: changes whenever any asset changes. */
function asset_version(): string
{
    $latest = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/assets', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $latest = max($latest, $file->getMTime());
    }
    return substr(md5(APP_VERSION . '|' . $latest), 0, 10);
}

$version = asset_version();
$name = 'Scrap';
try {
    $name = (string) App\Core\Settings::get('app.name', 'Scrap');
} catch (Throwable) {
    // Database unavailable: the SPA will show the error.
}

// Applies the saved theme before first paint (no light/dark flash).
$themeScript = "try{var t=JSON.parse(localStorage.getItem('scrap:theme'));if(t==='light'||t==='dark')document.documentElement.dataset.theme=t}catch(e){}";
$themeHash = base64_encode(hash('sha256', $themeScript, true));

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'sha256-{$themeHash}'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https://flagcdn.com; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#f5f6fa">
  <title><?= $e($name) ?></title>
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg?v=<?= $e($version) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&display=swap">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= $e($version) ?>">
  <script><?= $themeScript ?></script>
  <script type="module" src="assets/js/app.js?v=<?= $e($version) ?>"></script>
</head>
<body>
  <div class="boot" aria-hidden="true">
    <svg class="boot-mark" viewBox="0 0 40 40"><defs><linearGradient id="boot-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#5b5bf0"/><stop offset="1" stop-color="#a855f7"/></linearGradient></defs><rect width="40" height="40" rx="11" fill="url(#boot-g)"/><path d="M12.2 27.8a11 11 0 0 1 0-15.6" stroke="#fff" stroke-width="2.6" fill="none" stroke-linecap="round" opacity=".45"/><path d="M16.4 23.6a5 5 0 0 1 0-7.2" stroke="#fff" stroke-width="2.6" fill="none" stroke-linecap="round" opacity=".75"/><circle cx="21.5" cy="20" r="3.4" fill="#fff"/><path d="m24.6 23.1 5.2 5.2" stroke="#fff" stroke-width="2.8" stroke-linecap="round"/></svg>
  </div>
  <div id="app"></div>
  <noscript><p style="padding:40px;text-align:center;font-family:system-ui">Scrap needs JavaScript enabled.</p></noscript>
</body>
</html>
