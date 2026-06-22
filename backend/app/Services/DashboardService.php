<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DashboardService
{
    /** Exclude far-future rows used by import/locations-feed tests from operator-facing aggregates. */
    private const TEST_REPORT_DATE_CUTOFF = '2099-01-01';

    /** @param array<string, mixed> $params */
    public function getDashboard(array $params = []): array
    {
        ['rrFilter' => $rrFilter, 'recFilter' => $recFilter, 'rrParams' => $rrParams, 'recParams' => $recParams] = $this->buildDateFilters($params);
        ['filter' => $todayFilter, 'params' => $todayParams] = $this->buildTodayFilter($params);

        $byDate = DB::select(
            "SELECT rr.report_date, SUM(rr.net_revenue) AS net_revenue
             FROM revenue_records rr JOIN machines m ON m.machine_id = rr.machine_id
             WHERE 1=1 {$rrFilter} GROUP BY rr.report_date ORDER BY rr.report_date",
            $rrParams,
        );

        $topShortfalls = DB::select(
            "SELECT rec.location_id, l.location_name, rec.report_date, rec.shortfall, rec.overage, rec.variance_tier
             FROM reconciliation_results rec JOIN locations l ON l.location_id = rec.location_id
             WHERE rec.shortfall > 0 {$recFilter}
             ORDER BY rec.shortfall DESC LIMIT 10",
            $recParams,
        );

        $faultStats = DB::select(
            "SELECT tf.fault_type, COUNT(DISTINCT tf.revenue_record_id) AS count
             FROM transaction_faults tf
             JOIN revenue_records rr ON rr.id = tf.revenue_record_id
             JOIN machines m ON m.machine_id = rr.machine_id
             WHERE 1=1 {$rrFilter}
             GROUP BY tf.fault_type",
            $rrParams,
        );

        $faultByTier = DB::select(
            "SELECT tf.severity, COUNT(DISTINCT tf.revenue_record_id) AS count
             FROM transaction_faults tf
             JOIN revenue_records rr ON rr.id = tf.revenue_record_id
             JOIN machines m ON m.machine_id = rr.machine_id
             WHERE 1=1 {$rrFilter}
             GROUP BY tf.severity",
            $rrParams,
        );

        $kpiParams = [
            ...$rrParams, ...$rrParams, ...$rrParams, ...$rrParams,
            ...$recParams, ...$recParams, ...$recParams,
            ...$rrParams, ...$todayParams,
        ];

        $k = DB::selectOne(
            "SELECT
              (SELECT COUNT(*) FROM revenue_records rr
               JOIN machines m ON m.machine_id = rr.machine_id
               WHERE 1=1 {$rrFilter}) AS total_records,
              (SELECT COUNT(*) FROM revenue_records rr
               JOIN machines m ON m.machine_id = rr.machine_id
               WHERE rr.is_faulty = 0 {$rrFilter}) AS clean_records,
              (SELECT COUNT(*) FROM revenue_records rr
               JOIN machines m ON m.machine_id = rr.machine_id
               WHERE rr.is_faulty = 1 {$rrFilter}) AS faulty_count,
              (SELECT COALESCE(SUM(tf.delta), 0) FROM transaction_faults tf
               JOIN revenue_records rr ON rr.id = tf.revenue_record_id
               JOIN machines m ON m.machine_id = rr.machine_id
               WHERE 1=1 {$rrFilter}) AS total_delta,
              (SELECT COUNT(*) FROM reconciliation_results rec
               WHERE rec.shortfall > 0.01 {$recFilter}) AS shortfall_locations,
              (SELECT COUNT(*) FROM reconciliation_results rec
               WHERE rec.meets_expectation = 1 {$recFilter}) AS matched_locations,
              (SELECT COUNT(*) FROM reconciliation_results rec
               WHERE 1=1 {$recFilter}) AS reconciliation_total,
              (SELECT COALESCE(SUM(rr.net_revenue), 0) FROM revenue_records rr
               JOIN machines m ON m.machine_id = rr.machine_id
               WHERE 1=1 {$rrFilter}) AS total_net_revenue,
              (SELECT COALESCE(SUM(rr.net_revenue), 0) FROM revenue_records rr
               JOIN machines m ON m.machine_id = rr.machine_id
               WHERE rr.report_date = ? {$todayFilter}) AS net_revenue_today",
            $kpiParams,
        );

        $totalRecords = (int) ($k->total_records ?? 0);
        $cleanRecords = (int) ($k->clean_records ?? 0);

        $enrichedKpis = [
            'total_records' => $totalRecords,
            'clean_records' => $cleanRecords,
            'clean_pct' => $totalRecords ? round(($cleanRecords / $totalRecords) * 1000) / 10 : 100,
            'matched_locations' => (int) ($k->matched_locations ?? 0),
            'shortfall_locations' => (int) ($k->shortfall_locations ?? 0),
            'reconciliation_total' => (int) ($k->reconciliation_total ?? 0),
            'total_net_revenue' => (float) ($k->total_net_revenue ?? 0),
            'net_revenue_today' => (float) ($k->net_revenue_today ?? 0),
            'faulty_count' => (int) ($k->faulty_count ?? 0),
            'total_delta' => (float) ($k->total_delta ?? 0),
        ];

        $byGame = DB::select(
            "SELECT gt.name AS game_name, SUM(rr.net_revenue) AS net_revenue
             FROM revenue_records rr
             JOIN machines m ON m.machine_id = rr.machine_id
             JOIN game_types gt ON gt.id = m.game_type_id
             WHERE 1=1 {$rrFilter}
             GROUP BY gt.name ORDER BY net_revenue DESC",
            $rrParams,
        );

        return [
            'by_date' => array_map(fn ($r) => [
                'report_date' => substr((string) $r->report_date, 0, 10),
                'net_revenue' => (float) $r->net_revenue,
            ], $byDate),
            'top_shortfalls' => array_map(fn ($r) => (array) $r, $topShortfalls),
            'fault_stats' => array_map(fn ($r) => (array) $r, $faultStats),
            'fault_by_tier' => array_map(fn ($r) => (array) $r, $faultByTier),
            'kpis' => $enrichedKpis,
            'by_game' => array_map(fn ($r) => [
                'game_name' => trim(str_replace('**', '', (string) $r->game_name)),
                'net_revenue' => (float) $r->net_revenue,
            ], $byGame),
        ];
    }

    /** @param array<string, mixed> $params */
    private function buildDateFilters(array $params): array
    {
        $rrFilter = '';
        $recFilter = '';
        $rrParams = [];
        $recParams = [];

        if (! empty($params['from'])) {
            $rrFilter .= ' AND rr.report_date >= ?';
            $recFilter .= ' AND rec.report_date >= ?';
            $rrParams[] = $params['from'];
            $recParams[] = $params['from'];
        }
        if (! empty($params['to'])) {
            $rrFilter .= ' AND rr.report_date <= ?';
            $recFilter .= ' AND rec.report_date <= ?';
            $rrParams[] = $params['to'];
            $recParams[] = $params['to'];
        }
        if (! empty($params['location_id'])) {
            $rrFilter .= ' AND m.location_id = ?';
            $recFilter .= ' AND rec.location_id = ?';
            $rrParams[] = $params['location_id'];
            $recParams[] = $params['location_id'];
        }

        $rrFilter .= ' AND rr.report_date < ?';
        $recFilter .= ' AND rec.report_date < ?';
        $rrParams[] = self::TEST_REPORT_DATE_CUTOFF;
        $recParams[] = self::TEST_REPORT_DATE_CUTOFF;

        return compact('rrFilter', 'recFilter', 'rrParams', 'recParams');
    }

    /** @param array<string, mixed> $params */
    private function buildTodayFilter(array $params): array
    {
        $filter = '';
        $qparams = [now()->toDateString()];
        if (! empty($params['location_id'])) {
            $filter .= ' AND m.location_id = ?';
            $qparams[] = $params['location_id'];
        }

        return ['filter' => $filter, 'params' => $qparams];
    }
}
