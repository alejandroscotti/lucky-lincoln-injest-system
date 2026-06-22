<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportsService;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function __construct(
        private readonly ReportsService $reports,
    ) {}

    public function reconciliationXlsx(Request $request)
    {
        return $this->reports->reconciliationXlsx($request->query());
    }

    public function transactionFaultsXlsx(Request $request)
    {
        return $this->reports->transactionFaultsXlsx($request->query());
    }

    public function dailyRevenueXlsx()
    {
        return $this->reports->dailyRevenueXlsx();
    }

    public function locationSummaryXlsx()
    {
        return $this->reports->locationSummaryXlsx();
    }

    public function machineDetailXlsx()
    {
        return $this->reports->machineDetailXlsx();
    }
}
