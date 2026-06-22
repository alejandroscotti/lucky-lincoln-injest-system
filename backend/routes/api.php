<?php

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DiagramsController;
use App\Http\Controllers\Api\FaultsController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LocationsController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\RevenueController;
use App\Http\Controllers\Api\RevenueImportController;
use App\Http\Controllers\Api\SubmissionsController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/meta/fault-types', [MetaController::class, 'faultTypes']);
Route::get('/meta/reverb', [MetaController::class, 'reverb']);

Route::post('/revenue/import', [RevenueImportController::class, 'import']);

Route::get('/submissions/completion', [SubmissionsController::class, 'completion']);
Route::get('/submissions', [SubmissionsController::class, 'index']);
Route::get('/submissions/{id}', [SubmissionsController::class, 'show'])->whereNumber('id');

Route::get('/revenue/recent', [RevenueController::class, 'recent']);
Route::get('/revenue/dashboard', [RevenueController::class, 'dashboard']);
Route::get('/revenue/reconcile', [RevenueController::class, 'reconcile']);

Route::get('/locations/options', [LocationsController::class, 'options']);
Route::get('/locations', [LocationsController::class, 'index']);
Route::get('/locations/{location_id}/machines', [LocationsController::class, 'machines']);

Route::get('/games', [CatalogController::class, 'games']);
Route::get('/machines', [CatalogController::class, 'machines']);

Route::get('/transactions/faults', [FaultsController::class, 'index']);

Route::get('/diagrams/mermaid', [DiagramsController::class, 'mermaid']);

Route::get('/reports/reconciliation.xlsx', [ReportsController::class, 'reconciliationXlsx']);
Route::get('/reports/transaction-faults.xlsx', [ReportsController::class, 'transactionFaultsXlsx']);
Route::get('/reports/daily-revenue.xlsx', [ReportsController::class, 'dailyRevenueXlsx']);
Route::get('/reports/location-summary.xlsx', [ReportsController::class, 'locationSummaryXlsx']);
Route::get('/reports/machine-detail.xlsx', [ReportsController::class, 'machineDetailXlsx']);
