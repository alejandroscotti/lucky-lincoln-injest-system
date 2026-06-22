<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public function games()
    {
        $rows = DB::select('SELECT id, name FROM game_types ORDER BY name');

        return response()->json(array_map(fn ($r) => (array) $r, $rows));
    }

    public function machines()
    {
        $rows = DB::select(
            'SELECT m.machine_id, m.location_id, l.location_name, gt.name AS game_name
             FROM machines m
             JOIN locations l ON l.location_id = m.location_id
             JOIN game_types gt ON gt.id = m.game_type_id',
        );

        return response()->json(array_map(fn ($r) => (array) $r, $rows));
    }
}
