<?php

namespace App\Services;

use App\Support\QueryFilters;
use App\Support\RevenueMath;
use Illuminate\Support\Facades\DB;

class ReconcileService
{
    public function recomputeReconciliation(string $locationId, string $reportDate): ?array
    {
        $expectedRow = DB::table('expected_totals')
            ->where('location_id', $locationId)
            ->where('report_date', $reportDate)
            ->first();

        if ($expectedRow === null) {
            return null;
        }

        $expected = (float) $expectedRow->expected_net_revenue;

        $actual = (float) DB::table('revenue_records as rr')
            ->join('machines as m', 'm.machine_id', '=', 'rr.machine_id')
            ->where('m.location_id', $locationId)
            ->where('rr.report_date', $reportDate)
            ->sum('rr.net_revenue');

        $variance = RevenueMath::round2($actual - $expected);
        $shortfall = RevenueMath::round2(max(0, $expected - $actual));
        $overage = RevenueMath::round2(max(0, $actual - $expected));
        $meets = $shortfall < 0.01 && $overage < 0.01;
        $status = $meets ? 'match' : 'mismatch';
        $tierAmount = $shortfall > 0 ? $shortfall : $overage;
        $varianceTier = $meets ? 'none' : RevenueMath::tierFromAmount($tierAmount);

        DB::table('reconciliation_results')->updateOrInsert(
            ['location_id' => $locationId, 'report_date' => $reportDate],
            [
                'expected_net_revenue' => $expected,
                'actual_net_revenue' => $actual,
                'variance' => $variance,
                'shortfall' => $shortfall,
                'overage' => $overage,
                'meets_expectation' => $meets ? 1 : 0,
                'status' => $status,
                'variance_tier' => $varianceTier,
                'computed_at' => now(),
            ],
        );

        $faultCount = (int) DB::table('transaction_faults as tf')
            ->join('revenue_records as rr', 'rr.id', '=', 'tf.revenue_record_id')
            ->join('machines as m', 'm.machine_id', '=', 'rr.machine_id')
            ->where('m.location_id', $locationId)
            ->where('rr.report_date', $reportDate)
            ->count();

        return [
            'location_id' => $locationId,
            'report_date' => $reportDate,
            'expected_net_revenue' => $expected,
            'actual_net_revenue' => $actual,
            'variance' => $variance,
            'shortfall' => $shortfall,
            'overage' => $overage,
            'meets_expectation' => $meets,
            'status' => $status,
            'variance_tier' => $varianceTier,
            'faulty_transaction_count' => $faultCount,
        ];
    }

    /** @param array<string, mixed> $query */
    public function getReconciliation(array $query = []): array
    {
        $this->ensureExpectedTotalsMaterialized();

        ['where' => $where, 'params' => $params] = $this->buildWhere($query);

        $from = $this->reconciliationFrom();

        $total = (int) DB::selectOne("SELECT COUNT(*) AS cnt {$from}{$where}", $params)->cnt;

        $summaryRow = DB::selectOne(
            "SELECT
               COUNT(*) AS total_rows,
               SUM(CASE WHEN (CASE WHEN rec.location_id IS NULL THEN 'pending' ELSE rec.status END) = 'match' THEN 1 ELSE 0 END) AS matches,
               SUM(CASE WHEN COALESCE(rec.shortfall, 0) > 0.01 THEN 1 ELSE 0 END) AS shortfalls
             {$from}{$where}",
            $params,
        );

        $sql = $this->reconciliationSelect().$from.$where.$this->buildOrder($query['sort'] ?? null);
        $dataParams = $params;

        $limit = array_key_exists('limit', $query) ? min((int) $query['limit'], 100) : null;
        $offset = (int) ($query['offset'] ?? 0);
        if ($limit !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
            $dataParams = [...$params, $limit, $offset];
        }

        $rows = DB::select($sql, $dataParams);
        $records = array_map(fn ($r) => $this->mapRow($r), $rows);

        return [
            'records' => $records,
            'total' => $total,
            'limit' => $limit ?? count($records),
            'offset' => $offset,
            'summary' => [
                'total' => (int) ($summaryRow->total_rows ?? 0),
                'matches' => (int) ($summaryRow->matches ?? 0),
                'shortfalls' => (int) ($summaryRow->shortfalls ?? 0),
            ],
        ];
    }

    public function recomputeAllReconciliation(): void
    {
        $pairs = DB::table('expected_totals')->select('location_id', 'report_date')->get();
        foreach ($pairs as $p) {
            $this->recomputeReconciliation(
                (string) $p->location_id,
                substr((string) $p->report_date, 0, 10),
            );
        }
    }

    private function ensureExpectedTotalsMaterialized(): void
    {
        if (DB::table('expected_totals')->exists()) {
            return;
        }

        if (! DB::table('revenue_records')->exists()) {
            return;
        }

        app(ExpectedTotalsService::class)->syncFromRevenue(true);
    }

    /** @param array<string, mixed> $query */
    private function buildWhere(array $query): array
    {
        $where = ' WHERE 1=1';
        $params = [];

        if (! empty($query['from'])) {
            $where .= ' AND et.report_date >= ?';
            $params[] = $query['from'];
        }
        if (! empty($query['to'])) {
            $where .= ' AND et.report_date <= ?';
            $params[] = $query['to'];
        }
        if (! empty($query['location_id'])) {
            $where .= ' AND et.location_id = ?';
            $params[] = $query['location_id'];
        }
        if (! empty($query['shortfall_only'])) {
            $where .= ' AND COALESCE(rec.shortfall, 0) > 0.01';
        }
        if (! empty($query['overage_only'])) {
            $where .= ' AND COALESCE(rec.overage, 0) > 0.01';
        }
        if (! empty($query['status']) && $query['status'] !== 'all') {
            $where .= ' AND (CASE WHEN rec.location_id IS NULL THEN "pending" ELSE rec.status END) = ?';
            $params[] = $query['status'];
        }

        $f = $query['filters'] ?? [];
        ['where' => $where, 'params' => $params] = QueryFilters::appendLike($where, $params, 'et.location_id', $f['location_id'] ?? null);
        ['where' => $where, 'params' => $params] = QueryFilters::appendLike($where, $params, 'l.location_name', $f['location_name'] ?? null);
        ['where' => $where, 'params' => $params] = QueryFilters::appendCastLike($where, $params, 'et.report_date', $f['report_date'] ?? null);
        ['where' => $where, 'params' => $params] = QueryFilters::appendCastLike($where, $params, 'et.expected_net_revenue', $f['expected'] ?? null);
        ['where' => $where, 'params' => $params] = QueryFilters::appendCastLike($where, $params, 'COALESCE(rr.actual_net_revenue, 0)', $f['actual'] ?? null);
        ['where' => $where, 'params' => $params] = QueryFilters::appendCastLike($where, $params, 'COALESCE(rec.shortfall, 0)', $f['shortfall'] ?? null);
        ['where' => $where, 'params' => $params] = QueryFilters::appendCastLike($where, $params, 'COALESCE(rec.overage, 0)', $f['overage'] ?? null);
        ['where' => $where, 'params' => $params] = QueryFilters::appendLike(
            $where,
            $params,
            '(CASE WHEN rec.location_id IS NULL THEN "pending" ELSE rec.status END)',
            $f['status'] ?? null,
        );
        ['where' => $where, 'params' => $params] = QueryFilters::appendLike($where, $params, 'COALESCE(rec.variance_tier, "none")', $f['tier'] ?? null);
        ['where' => $where, 'params' => $params] = QueryFilters::appendLike($where, $params, 'COALESCE(et.notes, "")', $f['notes'] ?? null);

        return ['where' => $where, 'params' => $params];
    }

    private function buildOrder(?string $sort): string
    {
        return match ($sort) {
            'shortfall_desc' => ' ORDER BY COALESCE(rec.shortfall, 0) DESC, et.location_id, et.report_date',
            'date' => ' ORDER BY et.report_date DESC, et.location_id',
            'status' => ' ORDER BY (CASE WHEN rec.location_id IS NULL THEN "pending" ELSE rec.status END), et.location_id, et.report_date',
            default => ' ORDER BY et.location_id, et.report_date',
        };
    }

    private function reconciliationSelect(): string
    {
        return '
          SELECT et.location_id, l.location_name, et.report_date, et.expected_net_revenue, et.notes,
                 COALESCE(rr.actual_net_revenue, 0) AS actual_net_revenue,
                 COALESCE(rec.variance, rr.actual_net_revenue - et.expected_net_revenue) AS variance,
                 COALESCE(rec.shortfall, GREATEST(0, et.expected_net_revenue - COALESCE(rr.actual_net_revenue, 0))) AS shortfall,
                 COALESCE(rec.overage, GREATEST(0, COALESCE(rr.actual_net_revenue, 0) - et.expected_net_revenue)) AS overage,
                 CASE WHEN rec.location_id IS NULL THEN 0 ELSE rec.meets_expectation END AS meets_expectation,
                 CASE WHEN rec.location_id IS NULL THEN "pending" ELSE rec.status END AS status,
                 CASE WHEN rec.location_id IS NULL THEN "none" ELSE COALESCE(rec.variance_tier, "none") END AS variance_tier,
                 rr.submitted_at';
    }

    private function reconciliationFrom(): string
    {
        return '
          FROM expected_totals et
          JOIN locations l ON l.location_id = et.location_id
          LEFT JOIN reconciliation_results rec ON rec.location_id = et.location_id AND rec.report_date = et.report_date
          LEFT JOIN (
            SELECT m.location_id, rr.report_date, SUM(rr.net_revenue) AS actual_net_revenue,
                   MAX(rr.updated_at) AS submitted_at
            FROM revenue_records rr JOIN machines m ON m.machine_id = rr.machine_id
            GROUP BY m.location_id, rr.report_date
          ) rr ON rr.location_id = et.location_id AND rr.report_date = et.report_date';
    }

    private function mapRow(object $r): array
    {
        return [
            'location_id' => $r->location_id,
            'location_name' => $r->location_name,
            'report_date' => substr((string) $r->report_date, 0, 10),
            'submitted_at' => $r->submitted_at ? (string) $r->submitted_at : null,
            'expected_net_revenue' => (float) $r->expected_net_revenue,
            'notes' => $r->notes ?: null,
            'actual_net_revenue' => (float) $r->actual_net_revenue,
            'variance' => (float) $r->variance,
            'shortfall' => (float) $r->shortfall,
            'overage' => (float) $r->overage,
            'meets_expectation' => (bool) $r->meets_expectation,
            'status' => $r->status,
            'variance_tier' => $r->variance_tier,
        ];
    }
}
