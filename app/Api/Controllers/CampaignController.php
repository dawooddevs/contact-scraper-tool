<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Request;
use App\Core\Database;
use App\Core\Logger;
use App\Scraper\CampaignService;

final class CampaignController
{
    public function list(Request $request, ?array $user): array
    {
        $status = $request->str('status', 'all', 20);
        $q = $request->str('q', '', 100);
        $page = $request->int('page', 1, 1);
        $perPage = $request->int('per_page', 20, 5, 100);

        $where = ['1=1'];
        $params = [];
        if ($status !== 'all' && in_array($status, CampaignService::STATUSES, true)) {
            $where[] = 'status = :status';
            $params['status'] = $status;
        } elseif ($status === 'active') {
            $where[] = "status IN ('running','paused')";
        }
        if ($q !== '') {
            $where[] = '(name LIKE :q OR keywords LIKE :q OR locations LIKE :q)';
            $params['q'] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) Database::value("SELECT COUNT(*) FROM campaigns WHERE {$whereSql}", $params);
        $rows = Database::all(
            "SELECT * FROM campaigns WHERE {$whereSql} ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off",
            $params + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]
        );

        $counts = ['all' => 0];
        foreach (Database::all('SELECT status, COUNT(*) AS n FROM campaigns GROUP BY status') as $row) {
            $counts[$row['status']] = (int) $row['n'];
            $counts['all'] += (int) $row['n'];
        }

        return [
            'items' => array_map(static fn ($c) => CampaignService::present($c), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'counts' => $counts,
        ];
    }

    public function get(Request $request, ?array $user): array
    {
        $campaign = CampaignService::findOrFail($request->int('id'));
        $data = CampaignService::present($campaign, true);

        $data['lead_counts'] = ['qualified' => 0, 'rejected' => 0, 'pending' => 0];
        foreach (Database::all('SELECT status, COUNT(*) AS n FROM leads WHERE campaign_id = :id GROUP BY status', ['id' => $campaign['id']]) as $row) {
            $data['lead_counts'][$row['status']] = (int) $row['n'];
        }
        $data['reject_reasons'] = array_map(
            static fn ($r) => ['reason' => $r['reject_reason'], 'count' => (int) $r['n']],
            Database::all(
                "SELECT reject_reason, COUNT(*) AS n FROM leads WHERE campaign_id = :id AND status = 'rejected'
                 GROUP BY reject_reason ORDER BY n DESC LIMIT 6",
                ['id' => $campaign['id']]
            )
        );
        return $data;
    }

    /** Lightweight polling endpoint: current state of the given (or all active) campaigns. */
    public function live(Request $request, ?array $user): array
    {
        $ids = $request->ids('ids');
        if ($ids) {
            $params = [];
            $in = Database::in(array_slice($ids, 0, 100), $params);
            $rows = Database::all("SELECT * FROM campaigns WHERE id IN {$in}", $params);
        } else {
            $rows = Database::all("SELECT * FROM campaigns WHERE status IN ('running','paused')");
        }
        return [
            'items' => array_map(static fn ($c) => CampaignService::present($c, $request->bool('queue')), $rows),
            'running' => (int) Database::value("SELECT COUNT(*) FROM campaigns WHERE status = 'running'"),
        ];
    }

    public function logs(Request $request, ?array $user): array
    {
        $id = $request->int('id');
        $after = $request->int('after', 0, 0);
        $limit = $request->int('limit', 150, 1, 500);
        if ($after > 0) {
            $rows = Database::all(
                'SELECT id, level, message, created_at FROM logs WHERE campaign_id = :c AND id > :a ORDER BY id ASC LIMIT :lim',
                ['c' => $id, 'a' => $after, 'lim' => $limit]
            );
        } else {
            $rows = array_reverse(Database::all(
                'SELECT id, level, message, created_at FROM logs WHERE campaign_id = :c ORDER BY id DESC LIMIT :lim',
                ['c' => $id, 'lim' => $limit]
            ));
        }
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['created_at'] = CampaignService::iso($row['created_at']);
        }
        return ['items' => $rows];
    }

    public function create(Request $request, ?array $user): array
    {
        $campaign = CampaignService::create($request->all(), $request->bool('start', true));
        return CampaignService::present($campaign, true);
    }

    public function update(Request $request, ?array $user): array
    {
        $campaign = CampaignService::update($request->int('id'), $request->all());
        return CampaignService::present($campaign, true);
    }

    public function action(Request $request, ?array $user): array
    {
        $id = $request->int('id');
        $action = $request->str('action', '', 20);
        switch ($action) {
            case 'start':
                CampaignService::start($id);
                break;
            case 'pause':
                CampaignService::pause($id);
                break;
            case 'resume':
                CampaignService::resume($id);
                break;
            case 'stop':
                CampaignService::stop($id);
                break;
            case 'duplicate':
                $source = CampaignService::findOrFail($id);
                $now = Database::now();
                $newId = Database::insert('campaigns', [
                    'name' => mb_substr($source['name'] . ' (copy)', 0, 190),
                    'country' => $source['country'],
                    'source' => $source['source'],
                    'keywords' => $source['keywords'],
                    'locations' => $source['locations'],
                    'options' => $source['options'],
                    'status' => 'draft',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                Logger::info($newId, 'Duplicated from "' . $source['name'] . '".');
                return CampaignService::present(CampaignService::findOrFail($newId), true);
            default:
                throw new ApiException('Unknown action.', 422);
        }
        return CampaignService::present(CampaignService::findOrFail($id), true);
    }

    public function delete(Request $request, ?array $user): array
    {
        $ids = $request->ids('ids');
        if (!$ids) {
            throw new ApiException('Nothing selected.', 422);
        }
        return ['deleted' => CampaignService::delete($ids)];
    }
}
