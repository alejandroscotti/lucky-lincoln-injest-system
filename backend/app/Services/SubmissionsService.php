<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SubmissionsService
{
    /** @var list<string> */
    public const SUBMISSION_KINDS = ['daily', 'resubmit', 'manual', 'api'];

    public static function parseSubmissionKind(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return in_array($value, self::SUBMISSION_KINDS, true) ? $value : null;
    }

    /** @param array<string, mixed> $params */
    public function getSubmissions(array $params = []): array
    {
        $limit = min((int) ($params['limit'] ?? 50), 100);
        $offset = (int) ($params['offset'] ?? 0);

        $where = ' WHERE 1=1';
        $qparams = [];

        if (! empty($params['location_id'])) {
            $where .= ' AND ib.location_id = ?';
            $qparams[] = $params['location_id'];
        }
        if (! empty($params['submission_kind'])) {
            $where .= ' AND ib.submission_kind = ?';
            $qparams[] = $params['submission_kind'];
        }
        if (! empty($params['source'])) {
            $where .= ' AND ib.source = ?';
            $qparams[] = $params['source'];
        }
        if (! empty($params['status'])) {
            $where .= ' AND ib.status = ?';
            $qparams[] = $params['status'];
        }
        if (! empty($params['from'])) {
            $where .= ' AND ib.report_date >= ?';
            $qparams[] = $params['from'];
        }
        if (! empty($params['to'])) {
            $where .= ' AND ib.report_date <= ?';
            $qparams[] = $params['to'];
        }

        $fromClause = '
            FROM import_batches ib
            LEFT JOIN locations l ON l.location_id = ib.location_id';

        $total = (int) DB::selectOne("SELECT COUNT(*) AS cnt {$fromClause}{$where}", $qparams)->cnt;

        $summary = DB::selectOne(
            "SELECT
               COUNT(*) AS total_submissions,
               SUM(CASE WHEN ib.submission_kind = 'daily' AND ib.status = 'completed' THEN 1 ELSE 0 END) AS daily_count,
               SUM(CASE WHEN ib.submission_kind = 'resubmit' AND ib.status = 'completed' THEN 1 ELSE 0 END) AS resubmit_count,
               SUM(CASE WHEN ib.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
               SUM(CASE WHEN ib.status != 'failed' THEN ib.imported_count ELSE 0 END) AS total_imported,
               SUM(CASE WHEN ib.status != 'failed' THEN ib.updated_count ELSE 0 END) AS total_updated,
               SUM(CASE WHEN ib.status != 'failed' THEN ib.skipped_count ELSE 0 END) AS total_skipped,
               SUM(ib.error_count) AS total_errors
             {$fromClause}{$where}",
            $qparams,
        );

        $rows = DB::select(
            "SELECT ib.id, ib.source, ib.location_id, l.location_name, ib.report_date,
                    ib.submission_kind, ib.idempotency_key, ib.status, ib.record_count,
                    ib.imported_count, ib.updated_count, ib.skipped_count, ib.error_count,
                    ib.created_at
             {$fromClause}{$where}
             ORDER BY ib.created_at DESC
             LIMIT ? OFFSET ?",
            [...$qparams, $limit, $offset],
        );

        return [
            'submissions' => array_map(fn ($r) => $this->normalizeSubmission($r), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'summary' => [
                'total_submissions' => (int) ($summary->total_submissions ?? 0),
                'daily_count' => (int) ($summary->daily_count ?? 0),
                'resubmit_count' => (int) ($summary->resubmit_count ?? 0),
                'failed_count' => (int) ($summary->failed_count ?? 0),
                'total_imported' => (int) ($summary->total_imported ?? 0),
                'total_updated' => (int) ($summary->total_updated ?? 0),
                'total_skipped' => (int) ($summary->total_skipped ?? 0),
                'total_errors' => (int) ($summary->total_errors ?? 0),
            ],
        ];
    }

    public function getSubmissionById(int $id): ?array
    {
        $row = DB::selectOne(
            'SELECT ib.id, ib.source, ib.location_id, l.location_name, ib.report_date,
                    ib.submission_kind, ib.idempotency_key, ib.status, ib.record_count,
                    ib.imported_count, ib.updated_count, ib.skipped_count, ib.error_count,
                    ib.created_at
             FROM import_batches ib
             LEFT JOIN locations l ON l.location_id = ib.location_id
             WHERE ib.id = ?',
            [$id],
        );

        if ($row === null) {
            return null;
        }

        $batch = $this->normalizeSubmission($row);

        $records = DB::select(
            'SELECT rr.machine_id, rr.report_date, rr.cash_in, rr.voucher_in, rr.voucher_out,
                    rr.net_revenue, rr.is_faulty, rr.updated_at, rr.import_batch_id
             FROM revenue_records rr
             WHERE rr.import_batch_id = ?
             ORDER BY rr.machine_id',
            [$id],
        );

        return [
            'submission' => $batch,
            'records' => array_map(fn ($r) => (array) $r, $records),
        ];
    }

    public function getCompletion(string $locationId, string $reportDate): array
    {
        $file = DB::table('location_daily_files')
            ->where('location_id', $locationId)
            ->where('report_date', $reportDate)
            ->first();

        $expected = $file
            ? (int) $file->expected_record_count
            : (int) DB::table('machines')->where('location_id', $locationId)->count();

        $present = (int) DB::selectOne(
            'SELECT COUNT(DISTINCT rr.machine_id) AS cnt
             FROM revenue_records rr
             JOIN machines m ON m.machine_id = rr.machine_id
             WHERE m.location_id = ? AND rr.report_date = ?',
            [$locationId, $reportDate],
        )->cnt;

        return [
            'location_id' => $locationId,
            'report_date' => $reportDate,
            'expected_record_count' => $expected,
            'present_machines' => $present,
            'is_complete' => ($file?->status === 'complete') && $present === $expected,
            'canonical_status' => $file?->status,
            'idempotency_key' => $file?->idempotency_key,
        ];
    }

    private function normalizeSubmission(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'source' => (string) $row->source,
            'location_id' => $row->location_id ? (string) $row->location_id : null,
            'location_name' => $row->location_name ? (string) $row->location_name : null,
            'report_date' => $row->report_date ? substr((string) $row->report_date, 0, 10) : null,
            'idempotency_key' => $row->idempotency_key ? (string) $row->idempotency_key : null,
            'submission_kind' => (string) $row->submission_kind,
            'status' => (string) $row->status,
            'record_count' => (int) ($row->record_count ?? 0),
            'imported_count' => (int) ($row->imported_count ?? 0),
            'updated_count' => (int) ($row->updated_count ?? 0),
            'skipped_count' => (int) ($row->skipped_count ?? 0),
            'error_count' => (int) ($row->error_count ?? 0),
            'created_at' => (string) $row->created_at,
        ];
    }
}
