<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Request;
use App\Core\Database;
use App\Scraper\CampaignService;
use App\Sources\SourceRegistry;

final class DashboardController
{
    public function summary(Request $request, ?array $user): array
    {
        // Browser UTC offset in minutes (e.g. +600 for Brisbane) so days line up with local time.
        $offset = $request->int('tz_offset', 0, -840, 840);
        $days = 30;

        $totals = Database::row(
            "SELECT
                COUNT(*) AS leads,
                SUM(email IS NOT NULL) AS with_email,
                SUM(phone IS NOT NULL) AS with_phone,
                SUM(website IS NOT NULL) AS with_website,
                SUM(created_at >= :d7) AS last7,
                SUM(created_at >= :d14 AND created_at < :d7b) AS prev7
             FROM leads WHERE status = 'qualified'",
            [
                'd7' => gmdate('Y-m-d H:i:s', time() - 7 * 86400),
                'd7b' => gmdate('Y-m-d H:i:s', time() - 7 * 86400),
                'd14' => gmdate('Y-m-d H:i:s', time() - 14 * 86400),
            ]
        ) ?? [];

        $campaignCounts = [];
        foreach (Database::all('SELECT status, COUNT(*) AS n FROM campaigns GROUP BY status') as $row) {
            $campaignCounts[$row['status']] = (int) $row['n'];
        }

        // Daily series, filled with zeros for missing days.
        $since = gmdate('Y-m-d H:i:s', time() - ($days + 1) * 86400);
        $rows = Database::all(
            "SELECT DATE(DATE_ADD(created_at, INTERVAL :off MINUTE)) AS d,
                    COUNT(*) AS leads, SUM(email IS NOT NULL) AS emails
             FROM leads WHERE status = 'qualified' AND created_at >= :since
             GROUP BY d ORDER BY d",
            ['off' => $offset, 'since' => $since]
        );
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row['d']] = ['leads' => (int) $row['leads'], 'emails' => (int) $row['emails']];
        }
        $series = [];
        $localNow = time() + $offset * 60;
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', $localNow - $i * 86400);
            $series[] = ['date' => $d] + ($byDay[$d] ?? ['leads' => 0, 'emails' => 0]);
        }

        $sources = [];
        $sourceNames = [];
        foreach (SourceRegistry::all() as $key => $source) {
            $sourceNames[$key] = $source->info()['name'];
        }
        foreach (Database::all("SELECT source, country, COUNT(*) AS n FROM leads WHERE status = 'qualified' GROUP BY source, country ORDER BY n DESC") as $row) {
            $sources[] = [
                'source' => $row['source'],
                'name' => $sourceNames[$row['source']] ?? $row['source'],
                'country' => $row['country'],
                'count' => (int) $row['n'],
            ];
        }

        $active = array_map(
            static fn ($c) => CampaignService::present($c),
            Database::all("SELECT * FROM campaigns WHERE status IN ('running','paused') ORDER BY status = 'running' DESC, updated_at DESC LIMIT 6")
        );
        $recentCampaigns = array_map(
            static fn ($c) => CampaignService::present($c),
            Database::all("SELECT * FROM campaigns ORDER BY created_at DESC LIMIT 5")
        );

        $recentLeads = Database::all(
            "SELECT l.id, l.name, l.email, l.phone, l.website, l.city, l.state, l.country, l.created_at, c.name AS campaign
             FROM leads l JOIN campaigns c ON c.id = l.campaign_id
             WHERE l.status = 'qualified' ORDER BY l.id DESC LIMIT 8"
        );
        foreach ($recentLeads as &$lead) {
            $lead['id'] = (int) $lead['id'];
            $lead['created_at'] = CampaignService::iso($lead['created_at']);
        }
        unset($lead);

        $activity = Database::all(
            "SELECT g.id, g.campaign_id, g.level, g.message, g.created_at, c.name AS campaign
             FROM logs g LEFT JOIN campaigns c ON c.id = g.campaign_id
             ORDER BY g.id DESC LIMIT 14"
        );
        foreach ($activity as &$log) {
            $log['id'] = (int) $log['id'];
            $log['campaign_id'] = $log['campaign_id'] !== null ? (int) $log['campaign_id'] : null;
            $log['created_at'] = CampaignService::iso($log['created_at']);
        }
        unset($log);

        return [
            'totals' => [
                'leads' => (int) ($totals['leads'] ?? 0),
                'with_email' => (int) ($totals['with_email'] ?? 0),
                'with_phone' => (int) ($totals['with_phone'] ?? 0),
                'with_website' => (int) ($totals['with_website'] ?? 0),
                'last7' => (int) ($totals['last7'] ?? 0),
                'prev7' => (int) ($totals['prev7'] ?? 0),
            ],
            'campaigns' => [
                'total' => array_sum($campaignCounts),
                'running' => $campaignCounts['running'] ?? 0,
                'paused' => $campaignCounts['paused'] ?? 0,
                'completed' => $campaignCounts['completed'] ?? 0,
            ],
            'series' => $series,
            'sources' => $sources,
            'active' => $active,
            'recent_campaigns' => $recentCampaigns,
            'recent_leads' => $recentLeads,
            'activity' => $activity,
            'worker' => WorkerController::health(),
        ];
    }
}
