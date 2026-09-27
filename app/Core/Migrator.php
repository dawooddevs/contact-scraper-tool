<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Applies numbered migrations from database/migrations and records the
 * current version in the settings table.
 */
final class Migrator
{
    public static function currentVersion(): int
    {
        try {
            $value = Database::value("SELECT `value` FROM `settings` WHERE `key` = 'schema_version'");
            return $value === null ? 0 : (int) json_decode((string) $value, true);
        } catch (Throwable) {
            return 0; // settings table does not exist yet
        }
    }

    public static function needsMigration(): bool
    {
        return self::currentVersion() < App::SCHEMA_VERSION;
    }

    /** @return string[] names of the migrations that ran */
    public static function migrate(): array
    {
        $current = self::currentVersion();
        $files = glob(APP_ROOT . '/database/migrations/*.php') ?: [];
        sort($files, SORT_NATURAL);
        $ran = [];

        foreach ($files as $file) {
            $number = (int) basename($file);
            if ($number <= $current) {
                continue;
            }
            $statements = require $file;
            foreach ((array) $statements as $sql) {
                if (is_callable($sql)) {
                    $sql();
                } else {
                    Database::pdo()->exec($sql);
                }
            }
            Database::query(
                "INSERT INTO `settings` (`key`, `value`, `updated_at`) VALUES ('schema_version', :v, :t)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`)",
                ['v' => json_encode($number), 't' => Database::now()]
            );
            $ran[] = basename($file);
        }

        return $ran;
    }
}
