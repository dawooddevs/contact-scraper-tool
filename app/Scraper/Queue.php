<?php

declare(strict_types=1);

namespace App\Scraper;

use App\Core\Database;

/**
 * Persistent task queue (MySQL). Tasks are leased while running so a
 * crashed or killed worker never loses work: expired leases become
 * available again on the next tick.
 */
final class Queue
{
    public const PRIORITY = [
        'enrich' => 30,
        'detail' => 20,
        'search' => 10,
    ];

    public const MAX_ATTEMPTS = [
        'search' => 4,
        'detail' => 3,
        'enrich' => 2,
    ];

    public static function push(int $campaignId, string $type, array $payload, int $delaySeconds = 0): void
    {
        Database::insert('tasks', [
            'campaign_id' => $campaignId,
            'type' => $type,
            'priority' => self::PRIORITY[$type] ?? 0,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => gmdate('Y-m-d H:i:s', time() + max(0, $delaySeconds)),
            'created_at' => Database::now(),
        ]);
    }

    /** Make tasks whose lease expired (worker died) available again. */
    public static function releaseExpired(): int
    {
        return Database::query(
            "UPDATE tasks SET status = 'pending', locked_until = NULL
             WHERE status = 'running' AND locked_until < :now",
            ['now' => Database::now()]
        )->rowCount();
    }

    /**
     * The running campaign that should be served next (least recently worked)
     * together with the type of its highest-priority available task.
     *
     * @return array{campaign_id: int, type: string}|null
     */
    public static function nextWork(): ?array
    {
        $row = Database::row(
            "SELECT t.campaign_id, t.type
             FROM tasks t
             JOIN campaigns c ON c.id = t.campaign_id
             WHERE t.status = 'pending' AND t.available_at <= :now AND c.status = 'running'
             ORDER BY c.last_worked_at IS NOT NULL, c.last_worked_at ASC, t.priority DESC, t.id ASC
             LIMIT 1",
            ['now' => Database::now()]
        );
        if ($row === null) {
            return null;
        }
        // Within the chosen campaign, always serve the highest priority type first.
        $type = Database::value(
            "SELECT type FROM tasks WHERE campaign_id = :c AND status = 'pending' AND available_at <= :now
             ORDER BY priority DESC, id ASC LIMIT 1",
            ['c' => $row['campaign_id'], 'now' => Database::now()]
        );
        return ['campaign_id' => (int) $row['campaign_id'], 'type' => (string) ($type ?? $row['type'])];
    }

    /**
     * Lease up to $limit tasks of a type for a campaign.
     *
     * @return array<int, array> tasks with decoded payload
     */
    public static function claim(int $campaignId, string $type, int $limit, int $leaseSeconds): array
    {
        $ids = Database::column(
            "SELECT id FROM tasks
             WHERE campaign_id = :c AND type = :t AND status = 'pending' AND available_at <= :now
             ORDER BY priority DESC, id ASC LIMIT :lim",
            ['c' => $campaignId, 't' => $type, 'now' => Database::now(), 'lim' => max(1, $limit)]
        );
        if (!$ids) {
            return [];
        }
        $params = ['until' => gmdate('Y-m-d H:i:s', time() + $leaseSeconds)];
        $in = Database::in($ids, $params);
        Database::query(
            "UPDATE tasks SET status = 'running', attempts = attempts + 1, locked_until = :until
             WHERE id IN {$in} AND status = 'pending'",
            $params
        );
        $params = [];
        $in = Database::in($ids, $params);
        $rows = Database::all("SELECT * FROM tasks WHERE id IN {$in} AND status = 'running' ORDER BY id ASC", $params);
        foreach ($rows as &$row) {
            $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
            $row['attempts'] = (int) $row['attempts'];
            $row['id'] = (int) $row['id'];
        }
        return $rows;
    }

    public static function complete(int $taskId): void
    {
        Database::query('DELETE FROM tasks WHERE id = :id', ['id' => $taskId]);
    }

    /**
     * Schedule another attempt with exponential backoff.
     * Returns false (and marks the task failed) once attempts are exhausted.
     */
    public static function retry(array $task, string $error, int $baseDelay = 20): bool
    {
        $max = self::MAX_ATTEMPTS[$task['type']] ?? 3;
        if ((int) $task['attempts'] >= $max) {
            self::fail((int) $task['id'], $error);
            return false;
        }
        $delay = $baseDelay * (2 ** max(0, (int) $task['attempts'] - 1));
        Database::update('tasks', [
            'status' => 'pending',
            'locked_until' => null,
            'available_at' => gmdate('Y-m-d H:i:s', time() + $delay),
            'last_error' => mb_substr($error, 0, 1000),
        ], 'id = :id', ['id' => $task['id']]);
        return true;
    }

    /** Put a task back without counting the attempt (e.g. the tick ran out of time). */
    public static function release(array $task): void
    {
        Database::update('tasks', [
            'status' => 'pending',
            'locked_until' => null,
            'attempts' => max(0, (int) $task['attempts'] - 1),
        ], 'id = :id', ['id' => $task['id']]);
    }

    public static function fail(int $taskId, string $error): void
    {
        Database::update('tasks', [
            'status' => 'failed',
            'locked_until' => null,
            'last_error' => mb_substr($error, 0, 1000),
        ], 'id = :id', ['id' => $taskId]);
    }

    public static function clear(int $campaignId): void
    {
        Database::query("DELETE FROM tasks WHERE campaign_id = :c AND status <> 'running'", ['c' => $campaignId]);
    }

    public static function outstanding(int $campaignId): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM tasks WHERE campaign_id = :c AND status IN ('pending','running')",
            ['c' => $campaignId]
        );
    }

    /** @return array<string, int> type => pending count */
    public static function breakdown(int $campaignId): array
    {
        $out = ['search' => 0, 'detail' => 0, 'enrich' => 0];
        foreach (Database::all(
            "SELECT type, COUNT(*) AS n FROM tasks WHERE campaign_id = :c AND status IN ('pending','running') GROUP BY type",
            ['c' => $campaignId]
        ) as $row) {
            $out[$row['type']] = (int) $row['n'];
        }
        return $out;
    }

    /** Seconds until the next task of a running campaign becomes available (null if none waiting). */
    public static function secondsUntilNext(): ?int
    {
        $next = Database::value(
            "SELECT MIN(t.available_at) FROM tasks t JOIN campaigns c ON c.id = t.campaign_id
             WHERE t.status = 'pending' AND c.status = 'running'"
        );
        if ($next === null) {
            return null;
        }
        return max(0, strtotime($next . ' UTC') - time());
    }
}
