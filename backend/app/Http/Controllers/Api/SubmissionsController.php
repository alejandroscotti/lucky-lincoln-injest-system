<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SubmissionsService;
use Illuminate\Http\Request;

class SubmissionsController extends Controller
{
    public function __construct(
        private readonly SubmissionsService $submissions,
    ) {}

    public function completion(Request $request)
    {
        $locationId = $request->query('location_id');
        $reportDate = $request->query('report_date');
        if (! $locationId || ! $reportDate) {
            return response()->json(['error' => 'location_id and report_date required'], 400);
        }

        return response()->json($this->submissions->getCompletion((string) $locationId, (string) $reportDate));
    }

    public function index(Request $request)
    {
        return response()->json($this->submissions->getSubmissions([
            'limit' => (int) $request->query('limit', 50),
            'offset' => (int) $request->query('offset', 0),
            'location_id' => $request->query('location_id'),
            'submission_kind' => $request->query('submission_kind'),
            'source' => $request->query('source'),
            'status' => $request->query('status'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]));
    }

    public function show(int $id)
    {
        $detail = $this->submissions->getSubmissionById($id);
        if ($detail === null) {
            return response()->json(['error' => 'Submission not found'], 404);
        }

        return response()->json($detail);
    }
}
