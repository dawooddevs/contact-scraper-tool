<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\FileResponse;
use App\Api\Request;
use App\Core\Database;
use App\Scraper\CampaignService;
use App\Scraper\EmailExtractor;
use App\Sources\SourceRegistry;
use App\Support\Phone;
use App\Support\Url;

final class LeadController
{
    private const SORTS = [
        'created_at' => 'l.id',
        'name' => 'l.name',
        'rating' => 'l.rating',
        'reviews' => 'l.reviews',
        'city' => 'l.city',
        'email' => 'l.email',
    ];

    public const EXPORT_COLUMNS = [
        'name' => 'Business name',
        'email' => 'Email',
        'emails' => 'All emails',
        'phone' => 'Phone',
        'website' => 'Website',
        'address' => 'Address',
        'city' => 'City / suburb',
        'state' => 'State',
        'postcode' => 'Postcode',
        'country' => 'Country',
        'category' => 'Category',
        'rating' => 'Rating',
        'reviews' => 'Reviews',
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'linkedin' => 'LinkedIn',
        'twitter' => 'X / Twitter',
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'source' => 'Source',
        'source_url' => 'Listing URL',
        'campaign' => 'Campaign',
        'keyword' => 'Search keyword',
        'search_location' => 'Search location',
        'status' => 'Status',
        'reject_reason' => 'Reject reason',
        'notes' => 'Notes',
        'created_at' => 'Collected at (UTC)',
    ];

    /** @return array{0: string, 1: array} */
    private function filters(Request $request): array
    {
        $where = ['1=1'];
        $params = [];

        $status = $request->str('status', 'qualified', 20);
        if (in_array($status, ['qualified', 'rejected', 'pending'], true)) {
            $where[] = 'l.status = :status';
            $params['status'] = $status;
        }
        $campaign = $request->int('campaign_id', 0, 0);
        if ($campaign > 0) {
            $where[] = 'l.campaign_id = :cid';
            $params['cid'] = $campaign;
        }
        $q = $request->str('q', '', 120);
        if ($q !== '') {
            $where[] = '(l.name LIKE :q OR l.email LIKE :q OR l.phone LIKE :q OR l.website LIKE :q OR l.city LIKE :q OR l.category LIKE :q OR l.address LIKE :q)';
            $params['q'] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $has = $request->raw('has', []);
        if (is_string($has)) {
            $has = array_filter(explode(',', $has));
        }
        foreach ((array) $has as $field) {
            match ((string) $field) {
                'email' => $where[] = 'l.email IS NOT NULL',
                'phone' => $where[] = 'l.phone IS NOT NULL',
                'website' => $where[] = 'l.website IS NOT NULL',
                'socials' => $where[] = "l.socials IS NOT NULL AND l.socials <> '[]' AND l.socials <> '{}'",
                default => null,
            };
        }
        $source = $request->str('source', '', 60);
        if ($source !== '') {
            $where[] = 'l.source = :source';
            $params['source'] = $source;
        }
        $country = strtoupper($request->str('country', '', 2));
        if ($country !== '') {
            $where[] = 'l.country = :country';
            $params['country'] = $country;
        }
        if ($request->bool('starred')) {
            $where[] = 'l.starred = 1';
        }
        return [implode(' AND ', $where), $params];
    }

    public function list(Request $request, ?array $user): array
    {
        [$where, $params] = $this->filters($request);
        $page = $request->int('page', 1, 1);
        $perPage = $request->int('per_page', 25, 10, 200);
        $sort = self::SORTS[$request->str('sort', 'created_at', 20)] ?? 'l.id';
        $dir = strtolower($request->str('dir', 'desc', 4)) === 'asc' ? 'ASC' : 'DESC';

        $total = (int) Database::value("SELECT COUNT(*) FROM leads l WHERE {$where}", $params);
        $rows = Database::all(
            "SELECT l.*, c.name AS campaign_name FROM leads l LEFT JOIN campaigns c ON c.id = l.campaign_id
             WHERE {$where} ORDER BY {$sort} IS NULL, {$sort} {$dir}, l.id DESC LIMIT :lim OFFSET :off",
            $params + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]
        );

        return [
            'items' => array_map([$this, 'present'], $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function get(Request $request, ?array $user): array
    {
        $row = Database::row(
            'SELECT l.*, c.name AS campaign_name FROM leads l LEFT JOIN campaigns c ON c.id = l.campaign_id WHERE l.id = :id',
            ['id' => $request->int('id')]
        );
        if ($row === null) {
            throw ApiException::notFound('Lead');
        }
        return $this->present($row);
    }

    public function update(Request $request, ?array $user): array
    {
        $id = $request->int('id');
        $row = Database::row('SELECT * FROM leads WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw ApiException::notFound('Lead');
        }
        $data = [];
        $errors = [];
        if ($request->has('starred')) {
            $data['starred'] = $request->bool('starred') ? 1 : 0;
        }
        if ($request->has('notes')) {
            $data['notes'] = $request->str('notes', '', 5000) ?: null;
        }
        if ($request->has('name')) {
            $name = $request->str('name', '', 255);
            if ($name === '') {
                $errors['name'] = 'Name cannot be empty.';
            }
            $data['name'] = $name;
        }
        if ($request->has('email')) {
            $raw = $request->str('email', '', 190);
            $email = $raw === '' ? null : EmailExtractor::normalize($raw);
            if ($raw !== '' && $email === null) {
                $errors['email'] = 'Enter a valid email address.';
            }
            $data['email'] = $email;
        }
        if ($request->has('phone')) {
            $raw = $request->str('phone', '', 60);
            $phone = $raw === '' ? null : Phone::clean($raw);
            if ($raw !== '' && $phone === null) {
                $errors['phone'] = 'Enter a valid phone number.';
            }
            $data['phone'] = $phone;
            $data['phone_digits'] = Phone::digits($phone, $row['country']);
        }
        if ($request->has('website')) {
            $raw = $request->str('website', '', 500);
            $site = $raw === '' ? null : Url::clean($raw);
            if ($raw !== '' && $site === null) {
                $errors['website'] = 'Enter a valid website URL.';
            }
            $data['website'] = $site;
            $data['domain'] = $site ? Url::domain($site) : null;
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
        if ($data) {
            $data['updated_at'] = Database::now();
            Database::update('leads', $data, 'id = :id', ['id' => $id]);
        }
        return $this->get(new Request(['id' => $id], 'GET'), $user);
    }

    /** Bulk actions on selected ids, or on everything matching the filters. */
    public function bulk(Request $request, ?array $user): array
    {
        $action = $request->str('action', '', 20);
        [$where, $params] = $this->scope($request);

        switch ($action) {
            case 'delete':
                $affected = $this->deleteLeads($where, $params);
                break;
            case 'star':
            case 'unstar':
                $affected = Database::query(
                    "UPDATE leads l SET l.starred = :s WHERE {$where}",
                    $params + ['s' => $action === 'star' ? 1 : 0]
                )->rowCount();
                break;
            default:
                throw new ApiException('Unknown bulk action.', 422);
        }
        return ['affected' => $affected];
    }

    private function deleteLeads(string $where, array $params): int
    {
        // Keep campaign counters in step with the rows that disappear.
        $counts = Database::all(
            "SELECT l.campaign_id, SUM(l.status = 'qualified') AS q, SUM(l.status = 'rejected') AS r, SUM(l.status = 'qualified' AND l.email IS NOT NULL) AS e
             FROM leads l WHERE {$where} GROUP BY l.campaign_id",
            $params
        );
        $affected = Database::query("DELETE l FROM leads l WHERE {$where}", $params)->rowCount();
        foreach ($counts as $c) {
            Database::query(
                'UPDATE campaigns SET
                    leads_qualified = GREATEST(CAST(leads_qualified AS SIGNED) - :q, 0),
                    leads_rejected = GREATEST(CAST(leads_rejected AS SIGNED) - :r, 0),
                    emails_found = GREATEST(CAST(emails_found AS SIGNED) - :e, 0)
                 WHERE id = :id',
                ['q' => (int) $c['q'], 'r' => (int) $c['r'], 'e' => (int) $c['e'], 'id' => (int) $c['campaign_id']]
            );
        }
        return $affected;
    }

    /** @return array{0: string, 1: array} */
    private function scope(Request $request): array
    {
        if ($request->bool('all_matching')) {
            $filters = $request->arr('filters');
            return $this->filters(new Request($filters, 'GET'));
        }
        $ids = $request->ids('ids');
        if (!$ids) {
            throw new ApiException('Nothing selected.', 422);
        }
        $params = [];
        $in = Database::in($ids, $params, 'id');
        return ["l.id IN {$in}", $params];
    }

    public function export(Request $request, ?array $user): FileResponse
    {
        [$where, $params] = $this->scope($request);
        $columns = array_values(array_filter(
            $request->arr('columns') ?: array_keys(self::EXPORT_COLUMNS),
            static fn ($c) => is_string($c) && isset(self::EXPORT_COLUMNS[$c])
        ));
        if (!$columns) {
            throw ApiException::validation(['columns' => 'Choose at least one column.']);
        }
        $count = (int) Database::value("SELECT COUNT(*) FROM leads l WHERE {$where}", $params);
        if ($count === 0) {
            throw new ApiException('No leads match this export.', 422);
        }

        $names = [];
        foreach (SourceRegistry::all() as $key => $source) {
            $names[$key] = $source->info()['name'];
        }
        $filename = 'leads-' . gmdate('Y-m-d-His') . '.csv';

        return new FileResponse($filename, 'text/csv; charset=utf-8', function ($out) use ($where, $params, $columns, $names): void {
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly
            fputcsv($out, array_map(static fn ($c) => self::EXPORT_COLUMNS[$c], $columns), ',', '"', '');
            $lastId = PHP_INT_MAX;
            while (true) {
                $rows = Database::all(
                    "SELECT l.*, c.name AS campaign_name FROM leads l LEFT JOIN campaigns c ON c.id = l.campaign_id
                     WHERE {$where} AND l.id < :last ORDER BY l.id DESC LIMIT 1000",
                    $params + ['last' => $lastId]
                );
                if (!$rows) {
                    break;
                }
                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    $socials = json_decode((string) ($row['socials'] ?? ''), true) ?: [];
                    $emails = json_decode((string) ($row['emails'] ?? ''), true) ?: [];
                    $line = [];
                    foreach ($columns as $col) {
                        $value = match ($col) {
                            'emails' => implode('; ', $emails),
                            'facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'tiktok' => $socials[$col] ?? '',
                            'source' => $names[$row['source']] ?? $row['source'],
                            'campaign' => $row['campaign_name'] ?? '',
                            default => $row[$col] ?? '',
                        };
                        $value = (string) $value;
                        // Neutralise spreadsheet formula injection.
                        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) && $col !== 'phone' && !is_numeric($value)) {
                            $value = "'" . $value;
                        }
                        $line[] = $value;
                    }
                    fputcsv($out, $line, ',', '"', '');
                }
                if (count($rows) < 1000) {
                    break;
                }
            }
        });
    }

    public function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'campaign_id' => (int) $row['campaign_id'],
            'campaign_name' => $row['campaign_name'] ?? null,
            'status' => $row['status'],
            'reject_reason' => $row['reject_reason'],
            'name' => $row['name'],
            'category' => $row['category'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'emails' => json_decode((string) ($row['emails'] ?? ''), true) ?: [],
            'website' => $row['website'],
            'domain' => $row['domain'],
            'address' => $row['address'],
            'city' => $row['city'],
            'state' => $row['state'],
            'postcode' => $row['postcode'],
            'country' => $row['country'],
            'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
            'rating' => $row['rating'] !== null ? (float) $row['rating'] : null,
            'reviews' => $row['reviews'] !== null ? (int) $row['reviews'] : null,
            'socials' => (object) (json_decode((string) ($row['socials'] ?? ''), true) ?: []),
            'source' => $row['source'],
            'source_ref' => $row['source_ref'],
            'source_url' => $row['source_url'],
            'keyword' => $row['keyword'],
            'search_location' => $row['search_location'],
            'starred' => (bool) $row['starred'],
            'notes' => $row['notes'],
            'created_at' => CampaignService::iso($row['created_at']),
            'updated_at' => CampaignService::iso($row['updated_at']),
        ];
    }
}
