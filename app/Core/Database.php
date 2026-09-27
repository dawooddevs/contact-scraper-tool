<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Thin static PDO wrapper. All timestamps are stored in UTC.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(array $cfg): PDO
    {
        $host = (string) ($cfg['host'] ?? 'localhost');
        $port = (int) ($cfg['port'] ?? 3306);
        $name = (string) ($cfg['name'] ?? '');
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        if (!empty($cfg['socket'])) {
            $dsn = "mysql:unix_socket={$cfg['socket']};dbname={$name};charset=utf8mb4";
        }
        $pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $cfg = App::config('db');
            if (!is_array($cfg)) {
                throw new RuntimeException('Database is not configured. Run the installer first.');
            }
            self::$pdo = self::connect($cfg);
        }
        return self::$pdo;
    }

    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /** Drop the connection so the next call reconnects (used after long network waits). */
    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    /** Re-establish the connection if the server closed it (e.g. wait_timeout during a long crawl). */
    public static function ensureAlive(): void
    {
        if (self::$pdo === null) {
            return;
        }
        try {
            self::$pdo->query('SELECT 1');
        } catch (Throwable) {
            self::$pdo = null;
        }
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $param = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($param, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function column(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function insert(string $table, array $data, bool $ignore = false): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT %sINTO `%s` (%s) VALUES (%s)',
            $ignore ? 'IGNORE ' : '',
            $table,
            implode(', ', array_map(static fn ($c) => "`{$c}`", $cols)),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        $stmt = self::query($sql, $data);
        if ($ignore && $stmt->rowCount() === 0) {
            return 0;
        }
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (!$data) {
            return 0;
        }
        $sets = [];
        $params = [];
        foreach ($data as $col => $value) {
            $sets[] = "`{$col}` = :set_{$col}";
            $params['set_' . $col] = $value;
        }
        foreach ($whereParams as $k => $v) {
            $params[$k] = $v;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return self::query($sql, $params)->rowCount();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Build "IN (:p0, :p1, ...)" placeholders and merge params. */
    public static function in(array $values, array &$params, string $prefix = 'in'): string
    {
        $values = array_values($values);
        if (!$values) {
            return '(NULL)';
        }
        $keys = [];
        foreach ($values as $i => $v) {
            $key = $prefix . $i;
            $keys[] = ':' . $key;
            $params[$key] = $v;
        }
        return '(' . implode(', ', $keys) . ')';
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
