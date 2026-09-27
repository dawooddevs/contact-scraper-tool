<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session authentication with "remember me" tokens, CSRF protection and
 * brute-force throttling.
 */
final class Auth
{
    private const SESSION_NAME = 'scrap_sid';
    private const REMEMBER_COOKIE = 'scrap_remember';
    private const REMEMBER_DAYS = 30;
    private const MAX_ATTEMPTS = 8;
    private const ATTEMPT_WINDOW_MIN = 15;

    private static ?array $user = null;
    private static bool $started = false;

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    public static function startSession(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) (60 * 60 * 12));
        session_start();
        self::$started = true;
    }

    /** Release the session file lock so parallel AJAX requests are not serialised. */
    public static function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        self::startSession();
        $id = (int) ($_SESSION['uid'] ?? 0);
        if ($id > 0) {
            $user = Database::row('SELECT id, name, email, last_login_at, created_at FROM users WHERE id = :id', ['id' => $id]);
            if ($user) {
                return self::$user = $user;
            }
            unset($_SESSION['uid']);
        }
        return self::$user = self::loginFromRememberCookie();
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::startSession();
        $expected = (string) ($_SESSION['csrf'] ?? '');
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    public static function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    public static function tooManyAttempts(): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::ATTEMPT_WINDOW_MIN * 60);
        $count = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND created_at >= :since',
            ['ip' => self::clientIp(), 'since' => $since]
        );
        return $count >= self::MAX_ATTEMPTS;
    }

    /** @return array{ok: bool, error?: string} */
    public static function attempt(string $email, string $password, bool $remember): array
    {
        if (self::tooManyAttempts()) {
            return ['ok' => false, 'error' => 'Too many failed attempts. Please wait ' . self::ATTEMPT_WINDOW_MIN . ' minutes and try again.'];
        }
        $user = Database::row('SELECT * FROM users WHERE email = :e', ['e' => mb_strtolower(trim($email))]);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            Database::insert('login_attempts', [
                'ip' => self::clientIp(),
                'email' => mb_substr($email, 0, 190),
                'created_at' => Database::now(),
            ]);
            return ['ok' => false, 'error' => 'These credentials do not match our records.'];
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $user['id']]);
        }

        self::loginUser((int) $user['id']);
        Database::query('DELETE FROM login_attempts WHERE ip = :ip', ['ip' => self::clientIp()]);
        if ($remember) {
            self::issueRememberToken((int) $user['id']);
        }
        return ['ok' => true];
    }

    public static function loginUser(int $userId): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Database::update('users', ['last_login_at' => Database::now()], 'id = :id', ['id' => $userId]);
        self::$user = null;
    }

    public static function logout(): void
    {
        self::startSession();
        $cookie = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if (str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            Database::query('DELETE FROM auth_tokens WHERE selector = :s', ['s' => $selector]);
        }
        self::setCookie(self::REMEMBER_COOKIE, '', time() - 3600);
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }

    private static function issueRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + self::REMEMBER_DAYS * 86400;
        Database::insert('auth_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => gmdate('Y-m-d H:i:s', $expires),
            'created_at' => Database::now(),
        ]);
        Database::query('DELETE FROM auth_tokens WHERE expires_at < :now', ['now' => Database::now()]);
        self::setCookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, $expires);
    }

    private static function loginFromRememberCookie(): ?array
    {
        $cookie = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $cookie, $m)) {
            return null;
        }
        $token = Database::row(
            'SELECT * FROM auth_tokens WHERE selector = :s AND expires_at > :now',
            ['s' => $m[1], 'now' => Database::now()]
        );
        if (!$token || !hash_equals((string) $token['validator_hash'], hash('sha256', $m[2]))) {
            self::setCookie(self::REMEMBER_COOKIE, '', time() - 3600);
            return null;
        }
        // The token is intentionally not rotated on use: the SPA may fire several
        // requests at once after the session expires and they must all succeed.
        self::loginUser((int) $token['user_id']);
        return Database::row('SELECT id, name, email, last_login_at, created_at FROM users WHERE id = :id', ['id' => $token['user_id']]);
    }

    private static function setCookie(string $name, string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
