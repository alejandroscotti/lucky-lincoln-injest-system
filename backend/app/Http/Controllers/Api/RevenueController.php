<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Services\ImportService;
use App\Services\ReconcileService;
use App\Support\QueryFilters;
use Illuminate\Http\Request;

class RevenueController extends Controller
{
    public function __construct(
        private readonly ImportService $import,
        private readonly DashboardService $dashboard,
        private readonly ReconcileService $reconcile,
    ) {}

    public function recent(Request $request)
    {
        return response()->json($this->import->getRecentRecords([
            'limit' => (int) $request->query('limit', 100),
            'offset' => (int) $request->query('offset', 0),
            'faulty_only' => $request->query('faulty_only') === 'true',
            'location_id' => $request->query('location_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'filters' => QueryFilters::parseFilterQuery($request->query()),
        ]));
    }

    public function dashboard(Request $request)
    {
        return response()->json($this->dashboard->getDashboard([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'location_id' => $request->query('location_id'),
        ]));
    }

    public function reconcile(Request $request)
    {
        return response()->json($this->reconcile->getReconciliation([
            'shortfall_only' => $request->query('shortfall_only') === 'true',
            'overage_only' => $request->query('overage_only') === 'true',
            'status' => $request->query('status'),
            'sort' => $request->query('sort', 'location_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'location_id' => $request->query('location_id'),
            'limit' => $request->query('limit') !== null ? (int) $request->query('limit') : 100,
            'offset' => (int) $request->query('offset', 0),
            'filters' => QueryFilters::parseFilterQuery($request->query()),
        ]));
    }
}
