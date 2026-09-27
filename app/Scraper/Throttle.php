<?php

declare(strict_types=1);

namespace App\Scraper;

use App\Core\Database;

/**
 * Keeps a minimum delay between requests to the same directory, across
 * worker runs (cron and browser ticks share the same clock in the DB).
 */
final class Throttle
{
    /**
     * Sleep until $delayMs has passed since the bucket's last request.
     * Returns false without sleeping if that would pass the deadline.
     */
    public static function wait(string $bucket, int $delayMs, float $deadline): bool
    {
        if ($delayMs <= 0) {
            return true;
        }
        $last = (float) (Database::value('SELECT last_at FROM throttle WHERE bucket = :b', ['b' => $bucket]) ?? 0);
        // Randomise ±25% so request timing doesn't look robotic.
        $jitter = $delayMs * (random_int(-25, 25) / 100);
        $readyAt = $last + ($delayMs + $jitter) / 1000;
        $now = microtime(true);
        if ($readyAt > $now) {
            if ($readyAt > $deadline) {
                return false;
            }
            usleep((int) (($readyAt - $now) * 1_000_000));
        }
        self::touch($bucket);
        return true;
    }

    public static function touch(string $bucket): void
    {
        Database::query(
            'INSERT INTO throttle (bucket, last_at) VALUES (:b, :t) ON DUPLICATE KEY UPDATE last_at = VALUES(last_at)',
            ['b' => $bucket, 't' => microtime(true)]
        );
    }
}
