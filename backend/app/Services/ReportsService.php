<?php

namespace App\Services;

use App\Support\QueryFilters;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportsService
{
    public function __construct(
        private readonly ReconcileService $reconcile,
        private readonly ImportService $import,
    ) {}

    /** @param array<string, string|null> $query */
    public function reconciliationXlsx(array $query): Response
    {
        $scopeAll = ($query['scope'] ?? null) === 'all';
        $filters = $scopeAll ? [] : QueryFilters::parseFilterQuery($query);

        $result = $this->reconcile->getReconciliation([
            'shortfall_only' => $scopeAll ? false : ($query['shortfall_only'] ?? null) === 'true',
            'overage_only' => $scopeAll ? false : ($query['overage_only'] ?? null) === 'true',
            'status' => $scopeAll ? null : ($query['status'] ?? null),
            'sort' => $query['sort'] ?? 'location_id',
            'from' => $scopeAll ? null : ($query['from'] ?? null),
            'to' => $scopeAll ? null : ($query['to'] ?? null),
            'location_id' => $scopeAll ? null : ($query['location_id'] ?? null),
            'filters' => $scopeAll ? [] : $filters,
        ]);

        $headers = ['location_id', 'location_name', 'report_date', 'expected_net_revenue', 'actual_net_revenue', 'shortfall', 'overage', 'variance', 'meets_expectation', 'variance_tier', 'status', 'notes'];
        $rows = array_map(fn ($r) => array_map(fn ($h) => $r[$h] ?? '', $headers), $result['records']);
        $suffix = $scopeAll ? 'all' : 'filtered';

        return $this->streamWorkbook($headers, $rows, "reconciliation-{$suffix}.xlsx");
    }

    /** @param array<string, string|null> $query */
    public function transactionFaultsXlsx(array $query = []): Response
    {
        $rows = $this->import->getFaults([
            'limit' => 10000,
            'from' => $query['from'] ?? null,
            'to' => $query['to'] ?? null,
            'fault_type' => $query['fault_type'] ?? null,
        ]);
        $headers = ['location_id', 'location_name', 'machine_id', 'report_date', 'cash_in', 'voucher_in', 'voucher_out', 'net_revenue', 'computed_net_revenue', 'fault_type', 'delta', 'severity', 'description'];
        $data = array_map(fn ($r) => array_map(fn ($h) => $r[$h] ?? '', $headers), $rows);

        return $this->streamWorkbook($headers, $data, 'transaction_faults.xlsx');
    }

    public function dailyRevenueXlsx(): Response
    {
        $rows = DB::select(
            'SELECT l.location_id, l.location_name, rr.report_date,
                    SUM(rr.net_revenue) AS net_revenue, SUM(rr.cash_in) AS cash_in,
                    SUM(rr.voucher_in) AS voucher_in, SUM(rr.voucher_out) AS voucher_out
             FROM revenue_records rr
             JOIN machines m ON m.machine_id = rr.machine_id
             JOIN locations l ON l.location_id = m.location_id
             GROUP BY l.location_id, l.location_name, rr.report_date
             ORDER BY rr.report_date DESC',
        );
        $headers = ['location_id', 'location_name', 'report_date', 'net_revenue', 'cash_in', 'voucher_in', 'voucher_out'];
        $data = array_map(fn ($r) => array_map(fn ($h) => $r->$h ?? '', $headers), $rows);

        return $this->streamWorkbook($headers, $data, 'daily_revenue.xlsx');
    }

    public function locationSummaryXlsx(): Response
    {
        $rows = DB::select(
            'SELECT l.location_id, l.location_name, l.city, l.st,
                    COUNT(DISTINCT m.machine_id) AS machine_count,
                    COALESCE(rev_agg.net_revenue, 0) AS net_revenue,
                    COALESCE(rec_agg.shortfall_count, 0) AS shortfall_count
             FROM locations l
             LEFT JOIN machines m ON m.location_id = l.location_id
             LEFT JOIN (
               SELECT m2.location_id, SUM(rr.net_revenue) AS net_revenue
               FROM revenue_records rr
               JOIN machines m2 ON m2.machine_id = rr.machine_id
               GROUP BY m2.location_id
             ) rev_agg ON rev_agg.location_id = l.location_id
             LEFT JOIN (
               SELECT location_id, SUM(CASE WHEN shortfall > 0.01 THEN 1 ELSE 0 END) AS shortfall_count
               FROM reconciliation_results
               GROUP BY location_id
             ) rec_agg ON rec_agg.location_id = l.location_id
             GROUP BY l.location_id, l.location_name, l.city, l.st, rev_agg.net_revenue, rec_agg.shortfall_count
             ORDER BY shortfall_count DESC',
        );
        $headers = ['location_id', 'location_name', 'city', 'st', 'machine_count', 'net_revenue', 'shortfall_count'];
        $data = array_map(fn ($r) => array_map(fn ($h) => $r->$h ?? '', $headers), $rows);

        return $this->streamWorkbook($headers, $data, 'location_summary.xlsx');
    }

    public function machineDetailXlsx(): Response
    {
        $result = $this->import->getRecentRecords(['limit' => 100, 'offset' => 0]);
        $headers = ['location_id', 'machine_id', 'report_date', 'cash_in', 'voucher_in', 'voucher_out', 'net_revenue', 'is_faulty'];
        $data = array_map(fn ($r) => array_map(fn ($h) => $r[$h] ?? '', $headers), $result['records']);

        return $this->streamWorkbook($headers, $data, 'machine_detail.xlsx');
    }

    /**
     * Build the workbook fully in memory and return it as a finite response with
     * an explicit Content-Length. Reports here are small and fully known, so a
     * chunked StreamedResponse (no Content-Length) buys nothing — and it leaves
     * browsers unable to tell when the download is complete, which surfaces as a
     * stuck "still downloading" indicator and a perpetual progress cursor.
     *
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     */
    private function streamWorkbook(array $headers, array $rows, string $filename): Response
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($spreadsheet))->save($tmp);
        $binary = (string) file_get_contents($tmp);
        @unlink($tmp);
        $spreadsheet->disconnectWorksheets();

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'no-store, must-revalidate',
        ]);
    }
}
