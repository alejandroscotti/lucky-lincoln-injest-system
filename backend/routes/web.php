<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/{any?}', function () {
    $index = public_path('index.html');
    if (File::exists($index)) {
        return response()->file($index);
    }

    return response()->json([
        'service' => 'Revenue Reconciliation API (Laravel)',
        'health' => '/api/health?ready=1',
    ]);
})->where('any', '^(?!api).*$');
