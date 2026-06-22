<?php

namespace App\Services;

class SubmissionEnvelopeService
{
    public function __construct(
        private readonly ImportValidationService $validation,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, errors: list<array{index?: int, message: string, code: string}>, parsed?: array{idempotency_key: string, location_id: string, report_date: string, expected_record_count: int}}
     */
    public function validateSubmissionEnvelope(array $input): array
    {
        $errors = [];
        $idempotencyKey = isset($input['idempotency_key']) ? trim((string) $input['idempotency_key']) : '';

        if ($idempotencyKey === '') {
            return [
                'ok' => false,
                'errors' => [['message' => 'Missing x-idempotency-key', 'code' => 'INVALID_IDEMPOTENCY_KEY']],
            ];
        }

        $parsed = \App\Support\Idempotency::parseIdempotencyKey($idempotencyKey);
        if ($parsed === null) {
            return [
                'ok' => false,
                'errors' => [['message' => 'Invalid x-idempotency-key format', 'code' => 'INVALID_IDEMPOTENCY_KEY']],
            ];
        }

        $locationId = $input['location_id'] ?? null;
        if (! $locationId) {
            $errors[] = ['message' => 'Missing x-location-id', 'code' => 'LOCATION_KEY_MISMATCH'];
        } elseif ((string) $locationId !== $parsed['location_id']) {
            $errors[] = [
                'message' => "x-location-id {$locationId} does not match key {$parsed['location_id']}",
                'code' => 'LOCATION_KEY_MISMATCH',
            ];
        }

        $reportDate = $input['report_date'] ?? null;
        if (! $reportDate) {
            $errors[] = ['message' => 'Missing x-report-date', 'code' => 'REPORT_DATE_KEY_MISMATCH'];
        } elseif (substr((string) $reportDate, 0, 10) !== $parsed['report_date']) {
            $errors[] = [
                'message' => "x-report-date {$reportDate} does not match key {$parsed['report_date']}",
                'code' => 'REPORT_DATE_KEY_MISMATCH',
            ];
        }

        $records = $input['records'] ?? [];
        $expectedCount = $input['expected_record_count'] ?? null;
        if ($expectedCount === null || ! is_numeric($expectedCount)) {
            $errors[] = ['message' => 'Missing or invalid x-expected-record-count', 'code' => 'RECORD_COUNT_MISMATCH'];
        } elseif ((int) $expectedCount !== count($records)) {
            $errors[] = [
                'message' => 'x-expected-record-count '.$expectedCount.' does not match body length '.count($records),
                'code' => 'RECORD_COUNT_MISMATCH',
            ];
        }

        foreach ($records as $i => $raw) {
            if (! $this->validation->validateImportShape($raw)) {
                $errors[] = ['index' => $i, 'message' => 'Invalid record shape', 'code' => 'INVALID_RECORD'];

                continue;
            }
            $rd = substr((string) $raw['report_date'], 0, 10);
            if ($rd !== $parsed['report_date']) {
                $errors[] = [
                    'index' => $i,
                    'message' => "record report_date {$rd} does not match key date {$parsed['report_date']}",
                    'code' => 'RECORD_DATE_MISMATCH',
                ];
            }
            if ((string) $raw['location_id'] !== $parsed['location_id']) {
                $errors[] = [
                    'index' => $i,
                    'message' => "record location_id {$raw['location_id']} does not match key {$parsed['location_id']}",
                    'code' => 'RECORD_LOCATION_MISMATCH',
                ];
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return [
            'ok' => true,
            'errors' => [],
            'parsed' => [
                'idempotency_key' => $idempotencyKey,
                'location_id' => $parsed['location_id'],
                'report_date' => $parsed['report_date'],
                'expected_record_count' => (int) $expectedCount,
            ],
        ];
    }
}
