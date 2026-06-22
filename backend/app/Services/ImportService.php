<?php

namespace App\Services;

use App\Support\LiveUpdateNotifier;
use App\Exceptions\ImportConflictException;
use App\Support\Idempotency;
use App\Support\LocationSource;
use App\Support\QueryFilters;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class ImportService
{
    public function __construct(
        private readonly SubmissionEnvelopeService $envelope,
        private readonly ImportValidationService $validation,
        private readonly ReconcileService $reconcile,
    ) {}

    /**
     * @param  list<mixed>  $records
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    public function importRevenue(array $records, array $ctx = []): array
    {
        $summary = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'submission_kind' => $ctx['submission_kind'] ?? null,
        ];

        if ($records === []) {
            return $summary;
        }

        $meta = $this->inferBatchMeta($records, $ctx);
        $envelopeParsed = null;
        $payloadHash = null;

        if ($this->usesEnvelope($ctx)) {
            $envelope = $this->envelope->validateSubmissionEnvelope([
                'idempotency_key' => $ctx['idempotency_key'] ?? null,
                'location_id' => $ctx['location_id'] ?? null,
                'report_date' => $ctx['report_date'] ?? null,
                'expected_record_count' => $ctx['expected_record_count'] ?? null,
                'records' => $records,
            ]);

            if (! $envelope['ok'] || ! isset($envelope['parsed'])) {
                $batchId = $this->insertFailedBatch($meta, $ctx, count($records), $envelope['errors']);
                $summary['batch_id'] = $batchId;
                $summary['errors'] = $this->mapEnvelopeErrors($envelope['errors']);
                $summary['status'] = 'failed';
                $summary['validation_failed'] = true;
                $summary['idempotency_key'] = $ctx['idempotency_key'] ?? null;

                LiveUpdateNotifier::importCompleted($meta['location_id'] ?? null);

                return $summary;
            }

            $envelopeParsed = $envelope['parsed'];
            $meta['location_id'] = $envelopeParsed['location_id'];
            $meta['report_date'] = $envelopeParsed['report_date'];
            $ctx['location_id'] = $envelopeParsed['location_id'];
            $ctx['report_date'] = $envelopeParsed['report_date'];

            $shaped = array_values(array_filter($records, fn ($r) => $this->validation->validateImportShape($r)));
            $payloadHash = Idempotency::hashPayload($shaped);

            $this->ensureCanonicalFile(
                $envelopeParsed,
                $payloadHash,
                (string) ($ctx['submission_kind'] ?? 'daily'),
            );

            $summary['idempotency_key'] = $envelopeParsed['idempotency_key'];
            $summary['expected_record_count'] = $envelopeParsed['expected_record_count'];
        }

        $batchId = DB::table('import_batches')->insertGetId([
            'source' => $ctx['source'] ?? 'api',
            'location_id' => $meta['location_id'],
            'report_date' => $meta['report_date'],
            'submission_kind' => $meta['submission_kind'],
            'idempotency_key' => $envelopeParsed['idempotency_key'] ?? $ctx['idempotency_key'] ?? null,
            'payload_hash' => $payloadHash,
            'status' => 'partial',
            'record_count' => count($records),
        ]);
        $summary['batch_id'] = $batchId;

        $affected = [];
        $machineIds = [];

        foreach ($records as $i => $raw) {
            if (! $this->validation->validateImportShape($raw)) {
                $summary['errors'][] = ['index' => $i, 'message' => 'Invalid record shape'];

                continue;
            }

            $machineIds[] = $raw['machine_id'];

            try {
                $result = $this->processOneRecord($raw, $batchId);
                if ($result['action'] === 'imported') {
                    $summary['imported']++;
                } elseif ($result['action'] === 'updated') {
                    $summary['updated']++;
                } elseif ($result['action'] === 'skipped') {
                    $summary['skipped']++;
                }

                if ($result['action'] !== 'skipped') {
                    $affected["{$raw['location_id']}|{$raw['report_date']}"] = true;
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = ['index' => $i, 'message' => $e->getMessage()];
            }
        }

        foreach (array_keys($affected) as $key) {
            [$loc, $date] = explode('|', $key, 2);
            app(ExpectedTotalsService::class)->ensureForLocationDate($loc, $date);
            $this->reconcile->recomputeReconciliation($loc, $date);
        }

        $processed = $summary['imported'] + $summary['updated'] + $summary['skipped'];
        if (count($summary['errors']) > 0 && $processed === 0) {
            $status = 'failed';
        } elseif (count($summary['errors']) > 0) {
            $status = 'partial';
        } else {
            $status = 'completed';
        }
        $summary['status'] = $status;

        if ($envelopeParsed !== null && $payloadHash !== null) {
            $present = $this->countPresentMachines(
                $envelopeParsed['location_id'],
                $envelopeParsed['report_date'],
                $machineIds,
            );
            $isComplete = $present === $envelopeParsed['expected_record_count']
                && count($summary['errors']) === 0
                && $status === 'completed';

            $summary['completion'] = [
                'expected_record_count' => $envelopeParsed['expected_record_count'],
                'received_record_count' => count($records),
                'present_machines' => $present,
                'is_complete' => $isComplete,
            ];

            $this->updateCanonicalStatus(
                $envelopeParsed['location_id'],
                $envelopeParsed['report_date'],
                $isComplete ? 'complete' : 'in_progress',
                $payloadHash,
            );
        }

        DB::table('import_batches')->where('id', $batchId)->update([
            'status' => $status,
            'record_count' => $processed,
            'imported_count' => $summary['imported'],
            'updated_count' => $summary['updated'],
            'skipped_count' => $summary['skipped'],
            'error_count' => count($summary['errors']),
        ]);

        LiveUpdateNotifier::importCompleted($meta['location_id'] ?? null);

        return $summary;
    }

    /** @param array<string, mixed> $params */
    public function getRecentRecords(array $params = []): array
    {
        $limit = min((int) ($params['limit'] ?? 50), 100);
        $offset = (int) ($params['offset'] ?? 0);

        $where = ' WHERE 1=1';
        $qparams = [];

        if (! empty($params['faulty_only'])) {
            $where .= ' AND rr.is_faulty = 1';
        }
        if (! empty($params['location_id'])) {
            $where .= ' AND l.location_id = ?';
            $qparams[] = $params['location_id'];
        }
        if (! empty($params['from'])) {
            $where .= ' AND rr.report_date >= ?';
            $qparams[] = $params['from'];
        }
        if (! empty($params['to'])) {
            $where .= ' AND rr.report_date <= ?';
            $qparams[] = $params['to'];
        }

        $f = $params['filters'] ?? [];
        $status = isset($f['status']) ? strtolower((string) $f['status']) : null;
        if ($status === 'fault' || $status === 'faulty') {
            $where .= ' AND rr.is_faulty = 1';
        } elseif ($status === 'ok' || $status === 'clean') {
            $where .= ' AND rr.is_faulty = 0';
        } elseif ($status) {
            ['where' => $where, 'params' => $qparams] = QueryFilters::appendLike(
                $where,
                $qparams,
                '(CASE WHEN rr.is_faulty = 1 THEN "fault" ELSE "ok" END)',
                $status,
            );
        }

        if (! empty($f['location'])) {
            $where .= ' AND (LOWER(l.location_id) LIKE LOWER(?) OR LOWER(l.location_name) LIKE LOWER(?))';
            $pattern = QueryFilters::likePattern((string) $f['location']);
            $qparams[] = $pattern;
            $qparams[] = $pattern;
        }

        foreach ([
            'machine' => 'rr.machine_id',
            'date' => 'rr.report_date',
            'cash_in' => 'rr.cash_in',
            'voucher_in' => 'rr.voucher_in',
            'voucher_out' => 'rr.voucher_out',
            'net_revenue' => 'rr.net_revenue',
            'computed' => 'rr.computed_net_revenue',
            'delta' => 'tf.delta',
        ] as $filterKey => $expr) {
            ['where' => $where, 'params' => $qparams] = QueryFilters::appendCastLike($where, $qparams, $expr, $f[$filterKey] ?? null);
        }

        $fromClause = '
            FROM revenue_records rr
            JOIN machines m ON m.machine_id = rr.machine_id
            JOIN locations l ON l.location_id = m.location_id
            LEFT JOIN transaction_faults tf ON tf.id = (
              SELECT tf2.id FROM transaction_faults tf2
              WHERE tf2.revenue_record_id = rr.id
              ORDER BY ABS(tf2.delta) DESC, tf2.id ASC
              LIMIT 1
            )';

        $total = (int) DB::selectOne("SELECT COUNT(*) AS cnt {$fromClause}{$where}", $qparams)->cnt;

        $rows = DB::select(
            "SELECT l.location_id, l.location_name, rr.machine_id, rr.report_date,
                    rr.cash_in, rr.voucher_in, rr.voucher_out, rr.net_revenue, rr.computed_net_revenue,
                    rr.is_faulty, rr.updated_at AS submitted_at, tf.fault_type, tf.delta
             {$fromClause}{$where}
             ORDER BY rr.is_faulty DESC, rr.updated_at DESC
             LIMIT ? OFFSET ?",
            [...$qparams, $limit, $offset],
        );

        return [
            'records' => array_map(fn ($r) => (array) $r, $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /** @param array<string, mixed> $params */
    public function getFaults(array $params = []): array
    {
        $sql = '
            SELECT l.location_id, l.location_name, rr.machine_id, rr.report_date,
                   rr.cash_in, rr.voucher_in, rr.voucher_out, rr.net_revenue, rr.computed_net_revenue,
                   rr.updated_at AS submitted_at,
                   tf.fault_type, tf.severity, tf.delta, tf.description, tf.expected_value, tf.reported_value
            FROM transaction_faults tf
            JOIN revenue_records rr ON rr.id = tf.revenue_record_id
            JOIN machines m ON m.machine_id = rr.machine_id
            JOIN locations l ON l.location_id = m.location_id
            WHERE 1=1';
        $qparams = [];

        if (! empty($params['fault_type'])) {
            $sql .= ' AND tf.fault_type = ?';
            $qparams[] = $params['fault_type'];
        }
        if (! empty($params['from'])) {
            $sql .= ' AND rr.report_date >= ?';
            $qparams[] = $params['from'];
        }
        if (! empty($params['to'])) {
            $sql .= ' AND rr.report_date <= ?';
            $qparams[] = $params['to'];
        }

        $sql .= ' ORDER BY tf.delta DESC LIMIT ? OFFSET ?';
        $qparams[] = (int) ($params['limit'] ?? 100);
        $qparams[] = (int) ($params['offset'] ?? 0);

        return array_map(fn ($r) => (array) $r, DB::select($sql, $qparams));
    }

    /** @param list<mixed> $records */
    private function inferBatchMeta(array $records, array $ctx): array
    {
        $first = null;
        foreach ($records as $r) {
            if ($this->validation->validateImportShape($r)) {
                $first = $r;
                break;
            }
        }

        return [
            'location_id' => $ctx['location_id'] ?? $first['location_id'] ?? null,
            'report_date' => $ctx['report_date'] ?? $first['report_date'] ?? null,
            'submission_kind' => $ctx['submission_kind'] ?? 'api',
        ];
    }

    private function usesEnvelope(array $ctx): bool
    {
        return ! empty($ctx['idempotency_key']) || LocationSource::isLocationId($ctx['source'] ?? null);
    }

    /** @param list<array<string, mixed>> $errors */
    private function mapEnvelopeErrors(array $errors): array
    {
        return array_map(fn ($e, $i) => [
            'index' => $e['index'] ?? $i,
            'message' => $e['message'],
            'code' => $e['code'],
        ], $errors, array_keys($errors));
    }

    /** @param list<array<string, mixed>> $errors */
    private function insertFailedBatch(array $meta, array $ctx, int $recordCount, array $errors): int
    {
        return (int) DB::table('import_batches')->insertGetId([
            'source' => $ctx['source'] ?? 'api',
            'location_id' => $meta['location_id'],
            'report_date' => $meta['report_date'],
            'submission_kind' => $meta['submission_kind'],
            'idempotency_key' => $ctx['idempotency_key'] ?? null,
            'status' => 'failed',
            'record_count' => $recordCount,
            'imported_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'error_count' => count($errors),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function getCanonicalFile(string $locationId, string $reportDate): ?array
    {
        $row = DB::table('location_daily_files')
            ->where('location_id', $locationId)
            ->where('report_date', $reportDate)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'location_id' => (string) $row->location_id,
            'report_date' => substr((string) $row->report_date, 0, 10),
            'idempotency_key' => (string) $row->idempotency_key,
            'payload_hash' => $row->payload_hash ? (string) $row->payload_hash : null,
            'status' => (string) $row->status,
            'expected_record_count' => (int) $row->expected_record_count,
        ];
    }

    /** @param array<string, mixed> $parsed */
    private function ensureCanonicalFile(array $parsed, string $payloadHash, string $kind): array
    {
        return DB::transaction(function () use ($parsed, $payloadHash, $kind) {
            $row = DB::table('location_daily_files')
                ->where('location_id', $parsed['location_id'])
                ->where('report_date', $parsed['report_date'])
                ->lockForUpdate()
                ->first();

            $existing = $row === null ? null : [
                'location_id' => (string) $row->location_id,
                'report_date' => substr((string) $row->report_date, 0, 10),
                'idempotency_key' => (string) $row->idempotency_key,
                'payload_hash' => $row->payload_hash ? (string) $row->payload_hash : null,
                'status' => (string) $row->status,
                'expected_record_count' => (int) $row->expected_record_count,
            ];

            if ($existing === null) {
                DB::table('location_daily_files')->insert([
                    'location_id' => $parsed['location_id'],
                    'report_date' => $parsed['report_date'],
                    'idempotency_key' => $parsed['idempotency_key'],
                    'payload_hash' => $payloadHash,
                    'status' => 'in_progress',
                    'expected_record_count' => $parsed['expected_record_count'],
                ]);

                return [
                    ...$parsed,
                    'payload_hash' => $payloadHash,
                    'status' => 'in_progress',
                ];
            }

            if ($existing['status'] === 'complete') {
                if ($existing['payload_hash'] === $payloadHash) {
                    return $existing;
                }
                if ($kind === 'daily') {
                    throw new ImportConflictException('Daily file already accepted for this location and date', 'DAILY_FILE_ALREADY_ACCEPTED');
                }
                throw new ImportConflictException('Resubmit payload does not match accepted file', 'RESUBMIT_PAYLOAD_CONFLICT');
            }

            if ($existing['payload_hash'] && $existing['payload_hash'] !== $payloadHash) {
                throw new ImportConflictException('Payload conflicts with in-progress submission', 'FILE_PAYLOAD_CONFLICT');
            }

            if (! $existing['payload_hash']) {
                DB::table('location_daily_files')
                    ->where('location_id', $parsed['location_id'])
                    ->where('report_date', $parsed['report_date'])
                    ->update([
                        'payload_hash' => $payloadHash,
                        'expected_record_count' => $parsed['expected_record_count'],
                    ]);
                $existing['payload_hash'] = $payloadHash;
                $existing['expected_record_count'] = $parsed['expected_record_count'];
            }

            return $existing;
        });
    }

    /** @param list<string> $machineIds */
    private function countPresentMachines(string $locationId, string $reportDate, array $machineIds): int
    {
        if ($machineIds === []) {
            return 0;
        }

        return (int) DB::selectOne(
            'SELECT COUNT(DISTINCT rr.machine_id) AS cnt
             FROM revenue_records rr
             JOIN machines m ON m.machine_id = rr.machine_id
             WHERE m.location_id = ? AND rr.report_date = ? AND rr.machine_id IN ('.implode(',', array_fill(0, count($machineIds), '?')).')',
            [$locationId, $reportDate, ...$machineIds],
        )->cnt;
    }

    private function updateCanonicalStatus(string $locationId, string $reportDate, string $status, string $payloadHash): void
    {
        DB::table('location_daily_files')
            ->where('location_id', $locationId)
            ->where('report_date', $reportDate)
            ->update(['status' => $status, 'payload_hash' => $payloadHash]);
    }

    /** @param array<string, mixed> $record */
    private function processOneRecord(array $record, int $batchId): array
    {
        return DB::transaction(function () use ($record, $batchId) {
            DB::table('locations')->updateOrInsert(
                ['location_id' => $record['location_id']],
                ['location_name' => $record['location_name']],
            );

            $locRow = DB::table('locations')->where('location_id', $record['location_id'])->first();
            $machineExists = DB::table('machines')
                ->where('machine_id', $record['machine_id'])
                ->where('location_id', $record['location_id'])
                ->exists();

            if (! $machineExists) {
                throw new \RuntimeException("Unknown machine_id {$record['machine_id']} for {$record['location_id']}");
            }

            $validated = $this->validation->validateImportRecord($record, $locRow?->location_name);
            $computed = $validated['computed_net_revenue'];
            $faults = $validated['faults'];
            $isFaulty = count($faults) > 0;

            $existing = $this->selectRevenueRowForUpdate($record['machine_id'], $record['report_date']);

            if ($existing !== null && $this->valuesUnchanged($existing, $record, $computed, $isFaulty)) {
                return ['action' => 'skipped', 'faults' => [], 'computed_net_revenue' => $computed];
            }

            return $this->writeRevenueAndFaults(
                $record,
                $batchId,
                $computed,
                $faults,
                $isFaulty,
                $existing,
            );
        });
    }

    private function valuesUnchanged(object $row, array $record, float $computed, bool $isFaulty): bool
    {
        return (float) $row->cash_in === (float) $record['cash_in']
            && (float) $row->voucher_in === (float) $record['voucher_in']
            && (float) $row->voucher_out === (float) $record['voucher_out']
            && (float) $row->net_revenue === (float) $record['net_revenue']
            && (float) $row->computed_net_revenue === $computed
            && (int) $row->is_faulty === ($isFaulty ? 1 : 0);
    }

    /** @param list<array<string, mixed>> $faults */
    private function writeRevenueAndFaults(
        array $record,
        int $batchId,
        float $computedNetRevenue,
        array $faults,
        bool $isFaulty,
        ?object $existing,
    ): array {
        if ($existing !== null) {
            $revenueId = (int) $existing->id;
            DB::table('revenue_records')->where('id', $revenueId)->update([
                'cash_in' => $record['cash_in'],
                'voucher_in' => $record['voucher_in'],
                'voucher_out' => $record['voucher_out'],
                'net_revenue' => $record['net_revenue'],
                'computed_net_revenue' => $computedNetRevenue,
                'is_faulty' => $isFaulty ? 1 : 0,
                'import_batch_id' => $batchId,
                'updated_at' => now(),
            ]);
            DB::table('transaction_faults')->where('revenue_record_id', $revenueId)->delete();
            $action = 'updated';
        } else {
            try {
                $revenueId = (int) DB::table('revenue_records')->insertGetId([
                    'machine_id' => $record['machine_id'],
                    'report_date' => $record['report_date'],
                    'cash_in' => $record['cash_in'],
                    'voucher_in' => $record['voucher_in'],
                    'voucher_out' => $record['voucher_out'],
                    'net_revenue' => $record['net_revenue'],
                    'computed_net_revenue' => $computedNetRevenue,
                    'is_faulty' => $isFaulty ? 1 : 0,
                    'import_batch_id' => $batchId,
                ]);
                $action = 'imported';
            } catch (QueryException $e) {
                if (! $this->isDupEntryError($e)) {
                    throw $e;
                }
                $dup = $this->selectRevenueRowForUpdate($record['machine_id'], $record['report_date']);
                if ($dup === null) {
                    throw $e;
                }
                if ($this->valuesUnchanged($dup, $record, $computedNetRevenue, $isFaulty)) {
                    return ['action' => 'skipped', 'faults' => [], 'computed_net_revenue' => $computedNetRevenue];
                }

                return $this->writeRevenueAndFaults($record, $batchId, $computedNetRevenue, $faults, $isFaulty, $dup);
            }
        }

        foreach ($faults as $f) {
            DB::table('transaction_faults')->insert([
                'revenue_record_id' => $revenueId,
                'fault_type' => $f['fault_type'],
                'severity' => $f['severity'],
                'expected_value' => $f['expected_value'],
                'reported_value' => $f['reported_value'],
                'delta' => $f['delta'],
                'description' => $f['description'],
            ]);
        }

        return ['action' => $action, 'faults' => $faults, 'computed_net_revenue' => $computedNetRevenue];
    }

    private function selectRevenueRowForUpdate(string $machineId, string $reportDate): ?object
    {
        $sql = 'SELECT id, cash_in, voucher_in, voucher_out, net_revenue, computed_net_revenue, is_faulty
                FROM revenue_records WHERE machine_id = ? AND report_date = ?';
        if (DB::connection()->getDriverName() === 'mysql') {
            $sql .= ' FOR UPDATE';
        }

        return DB::selectOne($sql, [$machineId, $reportDate]);
    }

    private function isDupEntryError(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'Duplicate entry') || $e->getCode() === '23000';
    }
}
