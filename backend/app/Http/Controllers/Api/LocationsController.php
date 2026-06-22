<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LocationsService;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationsController extends Controller
{
    public function __construct(
        private readonly LocationsService $locations,
    ) {}

    public function options()
    {
        return response()->json($this->locations->getLocationOptions());
    }

    public function index(Request $request)
    {
        return response()->json($this->locations->getLocationsPaginated([
            'limit' => (int) $request->query('limit', 100),
            'offset' => (int) $request->query('offset', 0),
            'shortfall_only' => $request->query('shortfall_only') === 'true',
            'location_id' => $request->query('location_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'filters' => QueryFilters::parseFilterQuery($request->query()),
        ]));
    }

    public function machines(string $locationId)
    {
        $rows = DB::select(
            'SELECT m.machine_id, m.location_id, gt.name AS game_name
             FROM machines m JOIN game_types gt ON gt.id = m.game_type_id
             WHERE m.location_id = ?',
            [$locationId],
        );

        return response()->json(array_map(fn ($r) => (array) $r, $rows));
    }
}
