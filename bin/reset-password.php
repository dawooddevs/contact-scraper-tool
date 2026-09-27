<?php

declare(strict_types=1);

/*
 * Reset (or create) an admin account from the command line / cPanel Terminal:
 *
 *   php bin/reset-password.php you@example.com "new password"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;

if (!App::isInstalled()) {
    fwrite(STDERR, "Scrap is not installed yet.\n");
    exit(1);
}

$email = mb_strtolower(trim((string) ($argv[1] ?? '')));
$password = (string) ($argv[2] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 8) {
    fwrite(STDERR, "Usage: php bin/reset-password.php <email> <new password (8+ chars)>\n");
    exit(1);
}

$now = Database::now();
$hash = password_hash($password, PASSWORD_DEFAULT);
$id = Database::value('SELECT id FROM users WHERE email = :e', ['e' => $email]);
if ($id) {
    Database::update('users', ['password_hash' => $hash, 'updated_at' => $now], 'id = :id', ['id' => $id]);
    Database::query('DELETE FROM auth_tokens WHERE user_id = :id', ['id' => $id]);
    Database::query('DELETE FROM login_attempts');
    echo "Password updated for {$email}.\n";
} else {
    Database::insert('users', ['name' => 'Admin', 'email' => $email, 'password_hash' => $hash, 'created_at' => $now, 'updated_at' => $now]);
    echo "Created admin account {$email}.\n";
}
