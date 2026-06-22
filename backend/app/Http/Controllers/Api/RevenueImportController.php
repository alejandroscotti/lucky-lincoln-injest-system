<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exceptions\ImportConflictException;
use App\Services\ImportService;
use App\Services\SubmissionsService;
use Illuminate\Http\Request;

class RevenueImportController extends Controller
{
    public function __construct(
        private readonly ImportService $import,
    ) {}

    public function import(Request $request)
    {
        $body = $request->json()->all();
        $records = is_array($body) && array_is_list($body) ? $body : ($body['records'] ?? null);

        if (! is_array($records)) {
            return response()->json([
                'error' => 'Expected JSON array of import records',
                'code' => 'INVALID_BODY',
            ], 400);
        }

        $rawKind = $request->header('x-submission-kind');
        $parsedKind = SubmissionsService::parseSubmissionKind($rawKind);
        if ($rawKind && ! $parsedKind) {
            return response()->json([
                'error' => 'Invalid x-submission-kind. Allowed: '.implode(', ', SubmissionsService::SUBMISSION_KINDS),
                'code' => 'INVALID_SUBMISSION_KIND',
            ], 400);
        }

        $expectedRaw = $request->header('x-expected-record-count');
        $expectedCount = ($expectedRaw !== null && $expectedRaw !== '') ? (int) $expectedRaw : null;

        try {
            $summary = $this->import->importRevenue($records, [
                'source' => $request->header('x-source', 'api'),
                'submission_kind' => $parsedKind ?? 'manual',
                'location_id' => $request->header('x-location-id'),
                'report_date' => $request->header('x-report-date'),
                'idempotency_key' => $request->header('x-idempotency-key'),
                'expected_record_count' => is_numeric($expectedCount) ? $expectedCount : null,
            ]);
        } catch (ImportConflictException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => $e->errorCode,
            ], 409);
        }

        if (! empty($summary['validation_failed'])) {
            $firstError = $summary['errors'][0] ?? [];

            return response()->json([
                'error' => $firstError['message'] ?? 'Submission validation failed',
                'code' => $firstError['code'] ?? 'VALIDATION_FAILED',
                ...$summary,
            ], 400);
        }

        return response()->json($summary);
    }
}
