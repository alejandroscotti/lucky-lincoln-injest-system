<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImportService;
use Illuminate\Http\Request;

class FaultsController extends Controller
{
    public function __construct(
        private readonly ImportService $import,
    ) {}

    public function index(Request $request)
    {
        return response()->json($this->import->getFaults([
            'limit' => (int) $request->query('limit', 100),
            'offset' => (int) $request->query('offset', 0),
            'fault_type' => $request->query('fault_type'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]));
    }
}
