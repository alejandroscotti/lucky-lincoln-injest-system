<?php

namespace App\Services;

use App\Support\QueryFilters;
use Illuminate\Support\Facades\DB;

class LocationsService
{
    public function getLocationOptions(): array
    {
        return DB::table('locations')
            ->select('location_id', 'location_name')
            ->orderBy('location_id')
            ->get()
            ->map(fn ($row) => [
                'location_id' => (string) $row->location_id,
                'location_name' => (string) $row->location_name,
            ])
            ->all();
    }

    /** @param array<string, mixed> $params */
    public function getLocationsPaginated(array $params = []): array
    {
        $limit = min((int) ($params['limit'] ?? 100), 100);
        $offset = (int) ($params['offset'] ?? 0);
        ['subquery' => $subquery, 'params' => $recParams] = $this->buildRecAggSubquery($params['from'] ?? null, $params['to'] ?? null);

        $qparams = $recParams;
        ['having' => $having, 'params' => $filterParams] = $this->buildLocationHaving($params, $qparams);

        $innerSelect = "
            SELECT l.location_id, l.location_name, l.addr, l.city, l.st, l.zip,
                   COUNT(DISTINCT m.machine_id) AS machine_count,
                   COALESCE(rec_agg.total_shortfall, 0) AS total_shortfall,
                   COALESCE(rec_agg.shortfall_count, 0) AS shortfall_count
            {$this->locationsFrom($subquery)}
            GROUP BY l.location_id, l.location_name, l.addr, l.city, l.st, l.zip,
                     rec_agg.total_shortfall, rec_agg.shortfall_count";

        $total = (int) DB::selectOne(
            "SELECT COUNT(*) AS cnt FROM ({$innerSelect}) loc WHERE 1=1{$having}",
            $filterParams,
        )->cnt;

        $summary = DB::selectOne(
            "SELECT
               COUNT(*) AS total_locations,
               SUM(CASE WHEN shortfall_count > 0 THEN 1 ELSE 0 END) AS shortfall_locations,
               SUM(machine_count) AS total_machines
             FROM ({$innerSelect}) loc
             WHERE 1=1{$having}",
            $filterParams,
        );

        $rows = DB::select(
            "SELECT * FROM ({$innerSelect}) loc
             WHERE 1=1{$having}
             ORDER BY shortfall_count DESC, location_id
             LIMIT ? OFFSET ?",
            [...$filterParams, $limit, $offset],
        );

        return [
            'records' => array_map(fn ($r) => $this->normalizeLocation($r), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'summary' => [
                'total_locations' => (int) ($summary->total_locations ?? 0),
                'shortfall_locations' => (int) ($summary->shortfall_locations ?? 0),
                'total_machines' => (int) ($summary->total_machines ?? 0),
            ],
        ];
    }

    private function buildRecAggSubquery(?string $from, ?string $to): array
    {
        $dateFilter = '';
        $params = [];
        if ($from) {
            $dateFilter .= ' AND report_date >= ?';
            $params[] = $from;
        }
        if ($to) {
            $dateFilter .= ' AND report_date <= ?';
            $params[] = $to;
        }

        $subquery = "
            SELECT location_id,
                   SUM(shortfall) AS total_shortfall,
                   SUM(CASE WHEN shortfall > 0.01 THEN 1 ELSE 0 END) AS shortfall_count
            FROM reconciliation_results
            WHERE 1=1{$dateFilter}
            GROUP BY location_id";

        return ['subquery' => $subquery, 'params' => $params];
    }

    private function locationsFrom(string $recSubquery): string
    {
        return "
            FROM locations l
            LEFT JOIN machines m ON m.location_id = l.location_id
            LEFT JOIN ({$recSubquery}) rec_agg ON rec_agg.location_id = l.location_id";
    }

    /** @param array<string, mixed> $params */
    private function buildLocationHaving(array $params, array $qparams): array
    {
        $having = '';
        if (! empty($params['shortfall_only'])) {
            $having .= ' AND shortfall_count > 0';
        }
        if (! empty($params['location_id'])) {
            $having .= ' AND location_id = ?';
            $qparams[] = $params['location_id'];
        }

        $f = $params['filters'] ?? [];
        foreach ([
            'location_id' => 'location_id',
            'location_name' => 'location_name',
            'addr' => 'addr',
            'city' => 'city',
            'st' => 'st',
            'zip' => 'zip',
        ] as $key => $column) {
            ['having' => $having, 'params' => $qparams] = $this->appendLikeOnHaving($having, $qparams, $column, $f[$key] ?? null);
        }
        foreach (['machine_count', 'shortfall_count', 'total_shortfall'] as $key) {
            ['having' => $having, 'params' => $qparams] = $this->appendCastLikeOnHaving($having, $qparams, $key, $f[$key] ?? null);
        }

        return ['having' => $having, 'params' => $qparams];
    }

    private function appendLikeOnHaving(string $having, array $params, string $column, ?string $value): array
    {
        if ($value === null || $value === '') {
            return ['having' => $having, 'params' => $params];
        }

        return [
            'having' => "{$having} AND LOWER({$column}) LIKE LOWER(?)",
            'params' => [...$params, QueryFilters::likePattern($value)],
        ];
    }

    private function appendCastLikeOnHaving(string $having, array $params, string $column, ?string $value): array
    {
        if ($value === null || $value === '') {
            return ['having' => $having, 'params' => $params];
        }

        return [
            'having' => "{$having} AND CAST({$column} AS CHAR) LIKE ?",
            'params' => [...$params, QueryFilters::likePattern($value)],
        ];
    }

    private function normalizeLocation(object $row): array
    {
        return [
            'location_id' => (string) $row->location_id,
            'location_name' => (string) $row->location_name,
            'addr' => (string) ($row->addr ?? ''),
            'city' => (string) ($row->city ?? ''),
            'st' => (string) ($row->st ?? ''),
            'zip' => (string) ($row->zip ?? ''),
            'machine_count' => (int) ($row->machine_count ?? 0),
            'total_shortfall' => (float) ($row->total_shortfall ?? 0),
            'shortfall_count' => (int) ($row->shortfall_count ?? 0),
        ];
    }
}
