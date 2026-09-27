#!/usr/bin/env bash
# Browser test: resets the DB, installs via the web installer, runs Playwright.
# Starts the app (:8088) and mock internet (:8099) if they are not running.
set -euo pipefail
cd "$(dirname "$0")/.."
DB_NAME="${DB_NAME:-scrap_test}"; DB_USER="${DB_USER:-scrap}"; DB_PASS="${DB_PASS:-scrap_pass}"
OUT="${1:-tests/output}"

php -r '
$pdo = new PDO("mysql:host=127.0.0.1;dbname=" . $argv[1] . ";charset=utf8mb4", $argv[2], $argv[3]);
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
' "$DB_NAME" "$DB_USER" "$DB_PASS"
rm -f storage/config.php storage/cookies/*.txt

curl -s -o /dev/null http://127.0.0.1:8099/ || { PHP_CLI_SERVER_WORKERS=10 php -S 127.0.0.1:8099 tests/mock/router.php >/tmp/scrap-mock.log 2>&1 & sleep 1; }
curl -s -o /dev/null http://127.0.0.1:8088/ || { PHP_CLI_SERVER_WORKERS=6 php -d max_execution_time=120 -S 127.0.0.1:8088 -t public >/tmp/scrap-app.log 2>&1 & sleep 1; }

php tests/setup-ui.php http://127.0.0.1:8088 "$DB_NAME" "$DB_USER" "$DB_PASS"
NODE_PATH="$(npm root -g)" node tests/ui.cjs "$OUT"
