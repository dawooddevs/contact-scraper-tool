<?php

declare(strict_types=1);

/*
 * End-to-end test against a running app + mock internet. See tests/run.sh,
 * which starts MariaDB-backed app and mock servers, then runs this file:
 *
 *   php tests/integration.php http://127.0.0.1:8088 <db_name> <db_user> <db_pass>
 */

require __DIR__ . '/mock/generator.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8088', '/');
$db = ['db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => $argv[2] ?? 'scrap_test', 'db_user' => $argv[3] ?? 'scrap', 'db_pass' => $argv[4] ?? 'scrap_pass'];
$jar = tempnam(sys_get_temp_dir(), 'scrapjar');
$csrf = '';
$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } else {
        $failed++;
        echo "  \033[31m✗ {$name}\033[0m" . ($detail !== '' ? "\n      {$detail}" : '') . "\n";
    }
}

function eq(string $name, mixed $actual, mixed $expected): void
{
    check($name, $actual === $expected, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/** @return array{status: int, body: string, json: ?array, headers: array} */
function http(string $method, string $url, ?array $json = null, array $headers = []): array
{
    global $jar;
    $ch = curl_init($url);
    $h = [];
    if ($json !== null) {
        $h[] = 'Content-Type: application/json';
    }
    foreach ($headers as $k => $v) {
        $h[] = "{$k}: {$v}";
    }
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$respHeaders) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true), 'headers' => $respHeaders];
}

function api(string $method, string $route, array $params = [], bool $withCsrf = true): array
{
    global $base, $csrf;
    if ($method === 'GET') {
        $url = $base . '/api.php?' . http_build_query(['r' => $route] + $params);
        return http('GET', $url);
    }
    return http('POST', $base . '/api.php?r=' . $route, $params, $withCsrf ? ['X-CSRF-Token' => $csrf] : []);
}

function data(array $res): array
{
    return is_array($res['json']['data'] ?? null) ? $res['json']['data'] : [];
}

/** Run worker ticks until the campaign leaves "running" (or a limit is hit). */
function runUntilDone(int $id, int $maxTicks = 120): array
{
    $campaign = [];
    for ($i = 0; $i < $maxTicks; $i++) {
        $tick = api('POST', 'worker.tick');
        if ($tick['status'] !== 200) {
            echo "      tick failed: {$tick['body']}\n";
        }
        $campaign = data(api('GET', 'campaigns.get', ['id' => $id]));
        if (($campaign['status'] ?? '') !== 'running') {
            break;
        }
        $next = data($tick)['next_in'] ?? null;
        if (is_int($next) && $next > 0) {
            sleep(min($next, 3));
        }
    }
    return $campaign;
}

// ------------------------------------------------------------------ install
echo "\nInstaller\n";

$page = http('GET', $base . '/install.php');
check('installer page renders', $page['status'] === 200 && str_contains($page['body'], 'Install Scrap'));
$index = http('GET', $base . '/');
check('index redirects to installer before install', $index['status'] === 302 && str_contains($index['headers']['location'] ?? '', 'install.php'));
$req = http('POST', $base . '/install.php', ['action' => 'requirements']);
eq('requirements pass', $req['json']['ok'] ?? null, true);
$bad = http('POST', $base . '/install.php', ['action' => 'test_db'] + array_merge($db, ['db_pass' => 'wrong']));
check('wrong DB password is explained', ($bad['json']['ok'] ?? true) === false && str_contains($bad['json']['message'] ?? '', 'Access denied'), $bad['body']);
$good = http('POST', $base . '/install.php', ['action' => 'test_db'] + $db);
eq('DB test succeeds', $good['json']['ok'] ?? null, true);
$weak = http('POST', $base . '/install.php', ['action' => 'install', 'admin_name' => 'Dawood', 'admin_email' => 'nope', 'admin_password' => 'short'] + $db);
check('admin validation', $weak['status'] === 422 && isset($weak['json']['fields']['admin_email'], $weak['json']['fields']['admin_password']));
$inst = http('POST', $base . '/install.php', ['action' => 'install', 'admin_name' => 'Dawood Test', 'admin_email' => 'admin@example.test', 'admin_password' => 'correct-horse'] + $db);
eq('install succeeds', $inst['json']['ok'] ?? null, true);

// Tests talk to mock websites on 127.0.0.1, so allow private addresses.
$configFile = dirname(__DIR__) . '/storage/config.php';
$config = require $configFile;
$config['allow_private_network'] = true;
file_put_contents($configFile, "<?php\nreturn " . var_export($config, true) . ";\n");
if (function_exists('opcache_reset')) {
    opcache_reset();
}

$again = http('POST', $base . '/install.php', ['action' => 'requirements']);
eq('installer locked after install', $again['status'], 403);
check('installer page says installed', str_contains(http('GET', $base . '/install.php')['body'], 'Scrap is installed'));
$shell = http('GET', $base . '/');
check('app shell served with CSP', $shell['status'] === 200 && str_contains($shell['body'], 'assets/js/app.js?v=') && isset($shell['headers']['content-security-policy']));

// --------------------------------------------------------------------- auth
echo "\nAuthentication & security\n";

$me = data(api('GET', 'auth.me'));
check('anonymous me', array_key_exists('user', $me) && $me['user'] === null);
$csrf = (string) ($me['csrf'] ?? '');
check('csrf issued', strlen($csrf) === 64);
eq('protected route needs login', api('GET', 'campaigns.list')['status'], 401);
eq('POST without CSRF rejected', api('POST', 'auth.login', ['email' => 'admin@example.test', 'password' => 'correct-horse'], false)['status'], 419);
$wrong = api('POST', 'auth.login', ['email' => 'admin@example.test', 'password' => 'nope']);
check('wrong password', $wrong['status'] === 422 && isset($wrong['json']['error']['fields']['password']));
$login = api('POST', 'auth.login', ['email' => 'ADMIN@example.test', 'password' => 'correct-horse', 'remember' => true]);
eq('login ok (email case-insensitive)', data($login)['user']['name'] ?? null, 'Dawood Test');
$csrf = (string) (data($login)['csrf'] ?? $csrf);
eq('unknown route', api('GET', 'nope.nope')['status'], 404);
eq('wrong method', api('GET', 'campaigns.create')['status'], 405);

// ----------------------------------------------------------------- settings
echo "\nSettings & connection\n";

$save = api('POST', 'settings.update', ['values' => [
    'connection.mode' => 'custom',
    'connection.custom_template' => 'http://127.0.0.1:8099/api?url={url}',
    'scrape.request_delay_ms' => 0,
    'enrich.timeout' => 10,
    'worker.web_budget' => 25,
]]);
eq('settings saved', data($save)['values']['connection.mode'] ?? null, 'custom');
$invalid = api('POST', 'settings.update', ['values' => ['scrape.timeout' => 9999, 'connection.custom_template' => 'ftp://x']]);
check('settings validation', $invalid['status'] === 422 && isset($invalid['json']['error']['fields']['scrape.timeout'], $invalid['json']['error']['fields']['connection.custom_template']));
$secret = api('POST', 'settings.update', ['values' => ['connection.scraperapi_key' => 'sk_live_123']]);
eq('secrets are masked', data($secret)['values']['connection.scraperapi_key'] ?? null, '********');
$test = data(api('POST', 'settings.test_connection', ['target' => 'ip', 'mode' => 'custom']));
check('connection test through template', ($test['ok'] ?? false) && ($test['ip'] ?? '') === '203.0.113.7', json_encode($test));
$badTest = api('POST', 'settings.test_connection', ['target' => 'ip', 'mode' => 'scrapingbee']);
check('missing API key explained', $badTest['status'] === 422 && str_contains($badTest['json']['error']['message'] ?? '', 'no API key'), $badTest['body']);

// ------------------------------------------------------------------ sources
echo "\nSources\n";

$sources = data(api('GET', 'sources.list'));
$codes = array_column($sources['countries'] ?? [], 'code');
eq('countries AU + US', $codes, ['AU', 'US']);
$ypTest = data(api('POST', 'sources.test', ['key' => 'yellowpages_au', 'keyword' => 'Restaurants', 'location' => 'Brisbane QLD']));
check('YP live test parses 25 listings', ($ypTest['status'] ?? '') === 'ok' && count($ypTest['listings'] ?? []) === 25, json_encode(array_diff_key($ypTest, ['listings' => 1])));
$yelpTest = data(api('POST', 'sources.test', ['key' => 'yelp', 'keyword' => 'Restaurants', 'location' => 'New York, NY']));
check('Yelp live test parses 10 listings', ($yelpTest['status'] ?? '') === 'ok' && count($yelpTest['listings'] ?? []) === 10);
$blockTest = data(api('POST', 'sources.test', ['key' => 'yellowpages_au', 'keyword' => 'blockme', 'location' => 'Brisbane QLD']));
check('blocked page detected in test', ($blockTest['status'] ?? '') === 'blocked' && str_contains($blockTest['message'] ?? '', 'DataDome'), json_encode($blockTest));
$v = api('POST', 'sources.test', ['key' => 'yellowpages_au', 'keyword' => '', 'location' => '']);
check('source test validation', $v['status'] === 422 && isset($v['json']['error']['fields']['keyword']));

// ------------------------------------------------- campaign: Yellow Pages AU
echo "\nCampaign — Yellow Pages AU (email + phone + website required)\n";

$invalidCampaign = api('POST', 'campaigns.create', ['country' => 'AU', 'keywords' => [], 'locations' => []]);
check('wizard validation', $invalidCampaign['status'] === 422 && isset($invalidCampaign['json']['error']['fields']['keywords'], $invalidCampaign['json']['error']['fields']['locations']));

$require = ['email' => true, 'phone' => true, 'website' => true];
$created = api('POST', 'campaigns.create', [
    'country' => 'AU',
    'keywords' => ['Restaurants', 'Cafes'],
    'locations' => ['Brisbane QLD'],
    'start' => true,
    'options' => ['require' => $require, 'match' => 'all', 'max_pages' => 5, 'max_leads' => 0],
]);
$c1 = data($created);
eq('campaign created & running', $c1['status'] ?? null, 'running');
eq('auto name', $c1['name'] ?? null, 'Restaurants +1 in Brisbane QLD');
eq('pages planned', $c1['stats']['pages_planned'] ?? null, 10);

$done = runUntilDone((int) $c1['id']);
$expected = count(array_filter(range(1, Mock::YP_TOTAL), [Mock::class, 'ypQualifiesAll']));
eq('campaign completed', $done['status'] ?? null, 'completed');
eq('qualified leads = exact expected', $done['stats']['leads_qualified'] ?? null, $expected);
eq('listings found (2 keywords × 60)', $done['stats']['listings_found'] ?? null, 120);
eq('cross-keyword duplicates merged', $done['stats']['duplicates'] ?? null, 60);
eq('pages scraped (3 per keyword)', $done['stats']['pages_done'] ?? null, 6);
eq('rejected + qualified = unique listings', ($done['stats']['leads_rejected'] ?? 0) + ($done['stats']['leads_qualified'] ?? 0), 60);
eq('progress 100%', $done['progress'] ?? null, 1);
eq('no pending leads left', $done['lead_counts']['pending'] ?? null, 0);

$leads = data(api('GET', 'leads.list', ['campaign_id' => $c1['id'], 'per_page' => 200]));
$allHave = true;
$emailsOk = true;
foreach ($leads['items'] ?? [] as $lead) {
    $allHave = $allHave && $lead['email'] && $lead['phone'] && $lead['website'];
    $i = (int) $lead['source_ref'] - 1000000;
    $b = Mock::ypBusiness($i);
    $expectedEmail = $b['primaryEmail'] ?? Mock::siteEmail($i);
    if ($lead['email'] !== $expectedEmail) {
        $emailsOk = false;
        echo "      lead {$i}: got {$lead['email']}, expected {$expectedEmail}\n";
    }
}
check('every qualified lead has email, phone and website', $allHave && count($leads['items'] ?? []) === $expected);
check('emails found via listing, mailto, contact page, Cloudflare and [at]-obfuscation', $emailsOk);
$withFb = array_filter($leads['items'] ?? [], static fn ($l) => isset($l['socials']['facebook']));
check('social profiles collected', count($withFb) > 0);
$l0 = $leads['items'][0] ?? [];
check('address & geo stored', ($l0['city'] ?? '') === 'Brisbane City' && ($l0['postcode'] ?? '') === '4000' && is_float($l0['latitude'] ?? null));

$rejected = data(api('GET', 'leads.list', ['campaign_id' => $c1['id'], 'status' => 'rejected', 'per_page' => 200]));
eq('rejected leads kept with reasons', count(array_filter($rejected['items'] ?? [], static fn ($l) => str_starts_with((string) $l['reject_reason'], 'Missing'))), 60 - $expected);
$detail = data(api('GET', 'campaigns.get', ['id' => $c1['id']]));
check('reject reasons summarised', count($detail['reject_reasons'] ?? []) > 0);
$logs = data(api('GET', 'campaigns.logs', ['id' => $c1['id']]));
check('activity log written', count($logs['items'] ?? []) > 5);
$after = data(api('GET', 'campaigns.logs', ['id' => $c1['id'], 'after' => end($logs['items'])['id']]));
eq('incremental log polling', count($after['items'] ?? [1]), 0);

// Filters / search / sort
$f = data(api('GET', 'leads.list', ['campaign_id' => $c1['id'], 'q' => 'Test Business 1', 'per_page' => 200]));
check('search filter', ($f['total'] ?? 0) >= 1 && count(array_filter($f['items'], static fn ($l) => !str_contains($l['name'], 'Test Business 1'))) === 0);
$sorted = data(api('GET', 'leads.list', ['campaign_id' => $c1['id'], 'sort' => 'name', 'dir' => 'asc', 'per_page' => 200]));
$names = array_column($sorted['items'] ?? [], 'name');
$copy = $names;
sort($copy, SORT_STRING);
eq('sort by name', $names, $copy);
$paged = data(api('GET', 'leads.list', ['campaign_id' => $c1['id'], 'per_page' => 10, 'page' => 2]));
eq('pagination', [count($paged['items'] ?? []), $paged['page'] ?? null], [min(10, max(0, $expected - 10)), 2]);

// Update, star, bulk
$firstId = $leads['items'][0]['id'];
$upd = data(api('POST', 'leads.update', ['id' => $firstId, 'starred' => true, 'notes' => 'Call Monday']));
eq('star + note', [$upd['starred'] ?? null, $upd['notes'] ?? null], [true, 'Call Monday']);
$badUpd = api('POST', 'leads.update', ['id' => $firstId, 'email' => 'not-an-email']);
check('lead edit validation', $badUpd['status'] === 422 && isset($badUpd['json']['error']['fields']['email']));
$starred = data(api('GET', 'leads.list', ['starred' => 1]));
eq('starred filter', $starred['total'] ?? null, 1);

// Export
$export = http('POST', $base . '/api.php?r=leads.export', ['all_matching' => true, 'filters' => ['campaign_id' => $c1['id'], 'status' => 'qualified'], 'columns' => ['name', 'email', 'phone', 'website', 'facebook']], ['X-CSRF-Token' => $csrf]);
$lines = array_values(array_filter(explode("\n", trim($export['body']))));
check('CSV export content type', str_contains($export['headers']['content-type'] ?? '', 'text/csv'));
check('CSV has BOM + header', str_starts_with($export['body'], "\xEF\xBB\xBF\"Business name\",Email,Phone,Website,Facebook\n"), substr($export['body'], 0, 60));
eq('CSV row count', count($lines), $expected + 1);
$emptyExport = http('POST', $base . '/api.php?r=leads.export', ['all_matching' => true, 'filters' => ['q' => 'zzz-nothing']], ['X-CSRF-Token' => $csrf]);
eq('empty export returns error JSON', $emptyExport['status'], 422);

// ---------------------------------------------------------- campaign: Yelp
echo "\nCampaign — Yelp US (unhydrated results, business pages, website crawl)\n";

$c2 = data(api('POST', 'campaigns.create', [
    'country' => 'US', 'keywords' => ['Restaurants'], 'locations' => ['New York, NY'], 'start' => true,
    'options' => ['require' => $require, 'max_pages' => 24],
]));
eq('yelp campaign running', $c2['status'] ?? null, 'running');
$yelpDone = runUntilDone((int) $c2['id']);
$expectedYelp = count(array_filter(range(1, Mock::YELP_TOTAL), [Mock::class, 'yelpQualifiesAll']));
eq('yelp completed', $yelpDone['status'] ?? null, 'completed');
eq('yelp qualified = exact expected', $yelpDone['stats']['leads_qualified'] ?? null, $expectedYelp);
eq('yelp listings (ads skipped)', $yelpDone['stats']['listings_found'] ?? null, 25);
$yl = data(api('GET', 'leads.list', ['campaign_id' => $c2['id'], 'per_page' => 100]));
$hydratedNames = count(array_filter($yl['items'] ?? [], static fn ($l) => preg_match('/^Yelp Biz \d+$/', $l['name'])));
eq('unhydrated names replaced from business page', $hydratedNames, $expectedYelp);

// ------------------------------------------------ duplicates across campaigns
echo "\nDuplicates across campaigns, limits, blocks, lifecycle\n";

$c3 = data(api('POST', 'campaigns.create', [
    'country' => 'AU', 'keywords' => ['Restaurants'], 'locations' => ['Brisbane QLD'], 'start' => true,
    'options' => ['require' => $require, 'max_pages' => 5, 'skip_duplicates' => true],
]));
$dupDone = runUntilDone((int) $c3['id']);
eq('previously collected leads skipped', $dupDone['stats']['leads_qualified'] ?? null, 0);
eq('…and counted as duplicates', $dupDone['stats']['duplicates'] ?? null, $expected);

// Target limit
$c4 = data(api('POST', 'campaigns.create', [
    'country' => 'AU', 'keywords' => ['Plumbers'], 'locations' => ['Sydney NSW'], 'start' => true,
    'options' => ['max_leads' => 5, 'skip_duplicates' => false],
]));
$limitDone = runUntilDone((int) $c4['id']);
eq('stops exactly at target', [$limitDone['status'] ?? null, $limitDone['stats']['leads_qualified'] ?? null], ['completed', 5]);
$limitLeads = data(api('GET', 'leads.list', ['campaign_id' => $c4['id'], 'status' => 'all', 'per_page' => 200]));
eq('no pending leads left behind', count(array_filter($limitLeads['items'] ?? [], static fn ($l) => $l['status'] === 'pending')), 0);

// Block handling → auto pause
api('POST', 'settings.update', ['values' => ['scrape.block_pause_threshold' => 1]]);
$c5 = data(api('POST', 'campaigns.create', ['country' => 'AU', 'keywords' => ['blockme'], 'locations' => ['Brisbane QLD'], 'start' => true]));
$blocked = runUntilDone((int) $c5['id'], 5);
check('auto-paused when blocked, with advice', ($blocked['status'] ?? '') === 'paused' && str_contains((string) ($blocked['last_error'] ?? ''), 'Settings → Connection'), json_encode([$blocked['status'] ?? null, $blocked['last_error'] ?? null]));
$resumed = data(api('POST', 'campaigns.action', ['id' => $c5['id'], 'action' => 'resume']));
eq('resume', $resumed['status'] ?? null, 'running');
$stopped = data(api('POST', 'campaigns.action', ['id' => $c5['id'], 'action' => 'stop']));
eq('stop', $stopped['status'] ?? null, 'stopped');
eq('cannot resume stopped', api('POST', 'campaigns.action', ['id' => $c5['id'], 'action' => 'resume'])['status'], 409);

// Draft → edit → start; pause
$draft = data(api('POST', 'campaigns.create', ['country' => 'AU', 'keywords' => ['Dentists'], 'locations' => ['Perth WA'], 'start' => false]));
eq('draft saved', $draft['status'] ?? null, 'draft');
$edited = data(api('POST', 'campaigns.update', ['id' => $draft['id'], 'name' => 'Perth dentists', 'country' => 'AU', 'keywords' => ['Dentists', 'Orthodontists'], 'locations' => ['Perth WA']]));
eq('draft edited', [$edited['name'] ?? null, count($edited['keywords'] ?? [])], ['Perth dentists', 2]);
$started = data(api('POST', 'campaigns.action', ['id' => $draft['id'], 'action' => 'start']));
eq('draft started', $started['status'] ?? null, 'running');
$paused = data(api('POST', 'campaigns.action', ['id' => $draft['id'], 'action' => 'pause']));
eq('paused', $paused['status'] ?? null, 'paused');
$dup = data(api('POST', 'campaigns.action', ['id' => $draft['id'], 'action' => 'duplicate']));
eq('duplicate creates draft copy', [$dup['status'] ?? null, $dup['name'] ?? null], ['draft', 'Perth dentists (copy)']);

// Config error surfaces before queueing
api('POST', 'settings.update', ['values' => ['connection.mode' => 'zenrows', 'connection.zenrows_key' => '']]);
$cfgErr = api('POST', 'campaigns.action', ['id' => $dup['id'], 'action' => 'start']);
check('missing API key blocks start with clear message', $cfgErr['status'] === 422 && str_contains($cfgErr['json']['error']['message'] ?? '', 'ZenRows'), $cfgErr['body']);
api('POST', 'settings.update', ['values' => ['connection.mode' => 'custom']]);

// Lists & dashboard
$list = data(api('GET', 'campaigns.list', ['status' => 'completed']));
eq('campaign status filter', $list['total'] ?? null, 4);
$dash = data(api('GET', 'dashboard.summary', ['tz_offset' => 600]));
eq('dashboard totals', $dash['totals']['leads'] ?? null, $expected + $expectedYelp + 5);
eq('dashboard 30-day series', count($dash['series'] ?? []), 30);
check('system info', isset(data(api('GET', 'system.info'))['php_version']));

// Bulk delete keeps counters honest
$del = data(api('POST', 'leads.bulk', ['action' => 'delete', 'ids' => [$firstId]]));
eq('bulk delete', $del['affected'] ?? null, 1);
$afterDel = data(api('GET', 'campaigns.get', ['id' => $c1['id']]));
eq('campaign counter decremented', $afterDel['stats']['leads_qualified'] ?? null, $expected - 1);

// Delete campaign removes everything
$gone = data(api('POST', 'campaigns.delete', ['ids' => [$c5['id']]]));
eq('campaign deleted', $gone['deleted'] ?? null, 1);
eq('deleted campaign 404', api('GET', 'campaigns.get', ['id' => $c5['id']])['status'], 404);

// CLI cron
$out = [];
exec(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/cron.php') . ' 3 -v 2>&1', $out, $code);
$cron = json_decode(implode("\n", $out), true);
check('CLI cron runs', $code === 0 && is_array($cron) && array_key_exists('busy', $cron), implode("\n", $out));
$status = data(api('GET', 'worker.status'));
eq('cron detected', $status['cron_active'] ?? null, true);

// Profile & logout
$prof = api('POST', 'auth.profile', ['name' => 'Dawood', 'email' => 'admin@example.test', 'current_password' => '', 'new_password' => 'another-pass']);
check('password change needs current password', $prof['status'] === 422 && isset($prof['json']['error']['fields']['current_password']));
$prof2 = data(api('POST', 'auth.profile', ['name' => 'Dawood', 'email' => 'admin@example.test']));
eq('profile name updated', $prof2['user']['name'] ?? null, 'Dawood');
$out = api('POST', 'auth.logout');
eq('logout', $out['status'], 200);
$csrf = (string) (data($out)['csrf'] ?? '');
eq('logged out', api('GET', 'campaigns.list')['status'], 401);

echo "\n" . ($failed ? "\033[31m{$failed} failed\033[0m, " : '') . "\033[32m{$passed} passed\033[0m\n";
@unlink($jar);
exit($failed ? 1 : 0);
