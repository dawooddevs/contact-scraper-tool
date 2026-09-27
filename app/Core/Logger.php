<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Activity log shown live in the UI (per campaign or global).
 */
final class Logger
{
    public static function info(?int $campaignId, string $message): void
    {
        self::write($campaignId, 'info', $message);
    }

    public static function success(?int $campaignId, string $message): void
    {
        self::write($campaignId, 'success', $message);
    }

    public static function warning(?int $campaignId, string $message): void
    {
        self::write($campaignId, 'warning', $message);
    }

    public static function error(?int $campaignId, string $message): void
    {
        self::write($campaignId, 'error', $message);
    }

    public static function write(?int $campaignId, string $level, string $message): void
    {
        try {
            Database::insert('logs', [
                'campaign_id' => $campaignId,
                'level' => $level,
                'message' => mb_substr($message, 0, 1000),
                'created_at' => Database::now(),
            ]);
        } catch (Throwable $e) {
            App::logException($e);
        }
    }
}
