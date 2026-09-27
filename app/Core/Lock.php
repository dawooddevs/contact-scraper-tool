<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Named mutex backed by MySQL GET_LOCK(). The lock is tied to the DB
 * connection, so it is released automatically if PHP dies mid-run.
 * Lock names are server-wide on shared MySQL, so they are namespaced
 * per installation.
 */
final class Lock
{
    private static function name(string $name): string
    {
        $ns = substr(md5(APP_ROOT . '|' . (string) App::config('db.name', '')), 0, 12);
        return 'scrap_' . $ns . '_' . $name;
    }

    public static function acquire(string $name, int $timeout = 0): bool
    {
        return (int) Database::value('SELECT GET_LOCK(:n, :t)', ['n' => self::name($name), 't' => $timeout]) === 1;
    }

    public static function release(string $name): void
    {
        try {
            Database::value('SELECT RELEASE_LOCK(:n)', ['n' => self::name($name)]);
        } catch (\Throwable) {
            // Connection gone: the server already released it.
        }
    }

    public static function isFree(string $name): bool
    {
        return (int) Database::value('SELECT IS_FREE_LOCK(:n)', ['n' => self::name($name)]) === 1;
    }
}
