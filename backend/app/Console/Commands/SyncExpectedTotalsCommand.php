<?php

namespace App\Console\Commands;

use App\Services\ExpectedTotalsService;
use Illuminate\Console\Command;

class SyncExpectedTotalsCommand extends Command
{
    protected $signature = 'expected-totals:sync {--recompute : Recompute reconciliation_results after sync}';

    protected $description = 'Ensure expected_totals rows exist for imported revenue (locations-feed simulation)';

    public function handle(ExpectedTotalsService $expectedTotals): int
    {
        $seeded = $expectedTotals->seedReferenceExpectedTotalsIfEmpty();
        if ($seeded > 0) {
            $this->info("Seeded {$seeded} reference expected_totals row(s).");
        }

        $result = $expectedTotals->syncFromRevenue($this->option('recompute'));
        $this->info("Inserted {$result['inserted']} expected total(s), refreshed {$result['refreshed']}; reconciliation rows: {$result['recomputed']}.");

        return self::SUCCESS;
    }
}
