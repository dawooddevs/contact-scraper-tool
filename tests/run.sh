#!/usr/bin/env bash
# Runs the full test-suite locally.
#
#   DB_NAME=scrap_test DB_USER=scrap DB_PASS=scrap_pass tests/run.sh
#
# Requires PHP 8.1+ with pdo_mysql + curl, and a MySQL/MariaDB database the
# user can drop/create tables in. WARNING: the database is wiped.

set -euo pipefail
cd "$(dirname "$0")/.."

DB_NAME="${DB_NAME:-scrap_test}"
DB_USER="${DB_USER:-scrap}"
DB_PASS="${DB_PASS:-scrap_pass}"
APP_PORT="${APP_PORT:-8088}"
MOCK_PORT=8099

echo "▶ Unit tests"
php tests/unit.php

echo "▶ Preparing database ${DB_NAME}"
php -r '
$pdo = new PDO("mysql:host=127.0.0.1;dbname=" . $argv[1] . ";charset=utf8mb4", $argv[2], $argv[3]);
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
' "$DB_NAME" "$DB_USER" "$DB_PASS"

# Keep any real config aside while testing.
if [ -f storage/config.php ]; then mv storage/config.php storage/config.php.bak; fi
cleanup() {
  kill "${APP_PID:-}" "${MOCK_PID:-}" 2>/dev/null || true
  rm -f storage/config.php storage/cookies/*.txt
  if [ -f storage/config.php.bak ]; then mv storage/config.php.bak storage/config.php; fi
}
trap cleanup EXIT

echo "▶ Starting mock internet on :${MOCK_PORT} and app on :${APP_PORT}"
PHP_CLI_SERVER_WORKERS=10 php -S 127.0.0.1:${MOCK_PORT} tests/mock/router.php >/tmp/scrap-mock.log 2>&1 &
MOCK_PID=$!
PHP_CLI_SERVER_WORKERS=4 php -d max_execution_time=120 -S 127.0.0.1:${APP_PORT} -t public >/tmp/scrap-app.log 2>&1 &
APP_PID=$!
sleep 1.5

echo "▶ Integration tests"
php tests/integration.php "http://127.0.0.1:${APP_PORT}" "$DB_NAME" "$DB_USER" "$DB_PASS"
