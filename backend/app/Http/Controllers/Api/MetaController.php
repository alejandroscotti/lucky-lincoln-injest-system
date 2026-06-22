<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class MetaController extends Controller
{
    public function reverb()
    {
        if (config('broadcasting.default') !== 'reverb') {
            return response()->json(['enabled' => false]);
        }

        $key = (string) config('reverb.apps.apps.0.key');
        if ($key === '') {
            return response()->json(['enabled' => false]);
        }

        $appUrl = (string) config('app.url');
        $secure = request()->isSecure() || str_starts_with($appUrl, 'https://');
        $scheme = $secure ? 'https' : 'http';
        $port = $secure ? 443 : 80;

        return response()->json([
            'enabled' => true,
            'key' => $key,
            'host' => request()->getHost(),
            'port' => $port,
            'scheme' => $scheme,
        ]);
    }

    public function faultTypes()
    {
        $rows = DB::table('fault_types')->orderBy('sort_order')->get();
        $labels = [];
        $codes = [];
        foreach ($rows as $row) {
            $codes[] = $row->code;
            $labels[$row->code] = $row->label;
        }

        return response()->json([
            'fault_types' => $codes,
            'labels' => $labels,
        ]);
    }
}
