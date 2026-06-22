<?php

namespace App\Console\Commands;

use App\Services\LocationsFeedApiClient;
use App\Support\FaultSimulation;
use App\Support\Idempotency;
use App\Support\RevenueMath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class LocationsFeedCommand extends Command
{
    protected $signature = 'locations-feed:run {--daily : Run daily submission cycle for all persisted locations} {--resubmit : Run one resubmit cycle}';

    protected $description = 'Each persisted location submits nightly revenue files via POST /api/revenue/import';

    private const CACHE_PREFIX = 'locations-feed:';

    private int $dailyStaggerMs;

    private float $invalidResubmitRate;

    private int $seedBase;

    public function handle(LocationsFeedApiClient $api): int
    {
        $this->dailyStaggerMs = (int) config('locations-feed.daily_stagger_ms', 3000);
        $this->invalidResubmitRate = (float) config('locations-feed.invalid_resubmit_rate', 0.1);
        $this->seedBase = (int) env('SEED_RANDOM', 42);

        try {
            $groups = $api->getLocationGroups();
        } catch (\Throwable $e) {
            $this->error('Locations feed could not load persisted locations from API: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($groups === []) {
            $this->error('No locations in database — run migrations first (reference_data.sql).');

            return self::FAILURE;
        }

        $runDaily = $this->option('daily') || (! $this->option('daily') && ! $this->option('resubmit'));
        $runResubmit = $this->option('resubmit') || (! $this->option('daily') && ! $this->option('resubmit'));

        if ($runDaily) {
            $this->scheduleDailySubmissions($groups, $api);
        }

        if ($runResubmit) {
            $this->runResubmitCycle($groups, $api);
        }

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $groups */
    private function scheduleDailySubmissions(array $groups, LocationsFeedApiClient $api): void
    {
        $reportDate = $this->today();
        $this->resetForNewDay($reportDate);
        $this->hydrateStateFromApi($reportDate, $groups, $api);

        $dailySubmitted = $this->getDailySubmitted($reportDate);
        $pending = array_values(array_filter($groups, fn ($g) => ! in_array($g['location_id'], $dailySubmitted, true)));

        if ($pending === []) {
            $this->info("[locations-feed] Daily cycle already complete for {$reportDate} (".count($groups).' locations)');

            return;
        }

        $this->info("[locations-feed] Daily submissions: ".count($pending).' pending, '.count($dailySubmitted)." already done (stagger {$this->dailyStaggerMs}ms, report_date={$reportDate})");

        foreach ($pending as $i => $group) {
            $this->submitDailyUntilComplete($group, $reportDate, $api);
            if ($i < count($pending) - 1) {
                usleep($this->dailyStaggerMs * 1000);
            }
        }

        $this->info("[locations-feed] Daily cycle complete for {$reportDate}");
    }

    /** @param array<string, mixed> $group */
    private function submitDailyUntilComplete(array $group, string $reportDate, LocationsFeedApiClient $api): void
    {
        $records = $this->buildLocationBatch($group, $reportDate);
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = $this->postLocationBatch($group, $records, 'daily', $api);
            if (! $result['ok']) {
                sleep(2 * $attempt);

                continue;
            }

            if (! empty($result['summary']['completion']['is_complete'])) {
                $dailySubmitted = $this->getDailySubmitted($reportDate);
                $dailySubmitted[] = $group['location_id'];
                $this->putDailySubmitted($reportDate, $dailySubmitted);

                return;
            }

            if (! empty($result['summary']['errors'])) {
                $this->warn("[locations-feed] daily partial {$group['location_id']} attempt {$attempt}, retrying...");
            }
            sleep(2 * $attempt);
        }

        $this->error("[locations-feed] daily {$group['location_id']} did not complete after {$maxAttempts} attempts");
    }

    /** @param list<array<string, mixed>> $groups */
    private function runResubmitCycle(array $groups, LocationsFeedApiClient $api): void
    {
        $reportDate = $this->today();
        $this->resetForNewDay($reportDate);

        $cache = $this->getSubmissionCache($reportDate);
        if ($cache === []) {
            $this->hydrateStateFromApi($reportDate, $groups, $api);
            $cache = $this->getSubmissionCache($reportDate);
        }

        $eligible = array_values(array_filter($cache, fn ($s) => $s['report_date'] === $reportDate));

        if ($eligible === []) {
            $this->info('[locations-feed] Resubmit skipped — no daily submissions cached for today');

            return;
        }

        $pick = $eligible[array_rand($eligible)];
        $group = [
            'location_id' => $pick['location_id'],
            'location_name' => $pick['location_name'],
            'machines' => array_map(fn ($r) => [
                'machine_id' => $r['machine_id'],
                'location_id' => $r['location_id'],
                'location_name' => $r['location_name'],
            ], $pick['records']),
        ];

        $injectInvalid = lcg_value() < $this->invalidResubmitRate;
        if ($injectInvalid) {
            $this->info("[locations-feed] Random invalid-date resubmit → {$pick['location_id']}");
            $this->postLocationBatch($group, $pick['records'], 'resubmit', $api, invalidDates: true);

            return;
        }

        $this->info("[locations-feed] Random resubmit → {$pick['location_id']} (".count($pick['records']).' machines)');
        $this->postLocationBatch($group, $pick['records'], 'resubmit', $api);
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  list<array<string, mixed>>  $records
     * @return array{ok: bool, summary: array<string, mixed>, status: int}
     */
    private function postLocationBatch(
        array $group,
        array $records,
        string $kind,
        LocationsFeedApiClient $api,
        bool $invalidDates = false,
    ): array {
        $reportDate = $records[0]['report_date'] ?? $this->today();
        $body = $invalidDates ? $this->buildInvalidDatePayload($records, $reportDate) : $records;
        $idempotencyKey = Idempotency::fileKey($group['location_id'], $reportDate);

        $result = $api->postImport($body, [
            'submission_kind' => $kind,
            'location_id' => $group['location_id'],
            'report_date' => $reportDate,
            'idempotency_key' => $idempotencyKey,
            'expected_record_count' => count($body),
        ]);

        $status = $result['status'];
        $summary = $result['summary'];

        if (! $result['ok']) {
            if ($invalidDates) {
                $this->info("[locations-feed] invalid-date resubmit {$group['location_id']} → rejected (status={$status}, errors=".count($summary['errors'] ?? []).')');

                return $result;
            }
            $this->error('Locations feed '.$kind.' POST /api/revenue/import failed for '.$group['location_id']." {$status}: ".substr(json_encode($summary), 0, 200));

            return $result;
        }

        if (! $invalidDates) {
            $cache = $this->getSubmissionCache($reportDate);
            $cache[$group['location_id']] = [
                'location_id' => $group['location_id'],
                'location_name' => $group['location_name'],
                'report_date' => $reportDate,
                'records' => $records,
                'idempotency_key' => $idempotencyKey,
                'submitted_at' => time(),
            ];
            $this->putSubmissionCache($reportDate, $cache);
        }

        $completion = $summary['completion']['is_complete'] ?? 'n/a';
        $this->info(
            '[locations-feed] '.($invalidDates ? 'invalid-date ' : '')."{$kind} {$group['location_id']} (".count($body).' machines) → '.
            "imported={$summary['imported']} updated={$summary['updated']} skipped={$summary['skipped']} ".
            'errors='.count($summary['errors'] ?? [])." complete={$completion}",
        );

        return $result;
    }

    /** @param array<string, mixed> $group @return list<array<string, mixed>> */
    private function buildLocationBatch(array $group, string $reportDate): array
    {
        $rng = Idempotency::seededRngForLocationDate($group['location_id'], $reportDate, $this->seedBase);
        $records = [];
        foreach ($group['machines'] as $machine) {
            $records[] = $this->buildRecord($machine, $reportDate, $rng);
        }

        return $records;
    }

    /** @param array<string, mixed> $machine */
    private function buildRecord(array $machine, string $reportDate, callable $rng): array
    {
        $cashIn = RevenueMath::round2(200 + $rng() * 3300);
        $voucherIn = RevenueMath::round2($rng() * 700);
        $voucherOut = RevenueMath::round2($rng() * 1100);
        $record = [
            'location_id' => $machine['location_id'],
            'location_name' => $machine['location_name'],
            'machine_id' => $machine['machine_id'],
            'cash_in' => $cashIn,
            'voucher_in' => $voucherIn,
            'voucher_out' => $voucherOut,
            'net_revenue' => RevenueMath::round2($cashIn + $voucherIn - $voucherOut),
            'report_date' => $reportDate,
        ];
        $mode = FaultSimulation::pickFaultMode($rng);
        if ($mode !== 'clean') {
            $record = FaultSimulation::corruptRecord($record, $mode, $rng);
        }

        return $record;
    }

    /** @param list<array<string, mixed>> $records @return list<array<string, mixed>> */
    private function buildInvalidDatePayload(array $records, string $reportDate): array
    {
        $badDate = Idempotency::shiftReportDate($reportDate, -1);
        $copy = $records;
        $idx = random_int(0, count($copy) - 1);
        $copy[$idx] = [...$copy[$idx], 'report_date' => $badDate];

        return $copy;
    }

    /** @param list<array<string, mixed>> $groups */
    private function hydrateStateFromApi(string $reportDate, array $groups, LocationsFeedApiClient $api): void
    {
        $offset = 0;
        $all = [];
        do {
            $page = $api->listSubmissions([
                'submission_kind' => 'daily',
                'status' => 'completed',
                'from' => $reportDate,
                'to' => $reportDate,
                'limit' => 100,
                'offset' => $offset,
            ]);
            $all = [...$all, ...$page['submissions']];
            $offset += 100;
        } while ($offset < $page['total'] && $page['submissions'] !== []);

        $hydrated = 0;
        $dailySubmitted = [];
        $cache = [];

        foreach ($groups as $group) {
            $completion = $api->getCompletion($group['location_id'], $reportDate);
            if (empty($completion['is_complete'])) {
                continue;
            }

            $dailySubmitted[] = $group['location_id'];

            $subs = array_values(array_filter($all, fn ($s) => $s['location_id'] === $group['location_id']));
            usort($subs, fn ($a, $b) => $b['id'] <=> $a['id']);
            $sub = $subs[0] ?? null;

            if ($sub !== null) {
                $detail = $api->getSubmission((int) $sub['id']);
                if ($detail !== null) {
                    $records = array_map(fn ($r) => [
                        'location_id' => $group['location_id'],
                        'location_name' => $group['location_name'],
                        'machine_id' => $r['machine_id'],
                        'cash_in' => (float) $r['cash_in'],
                        'voucher_in' => (float) $r['voucher_in'],
                        'voucher_out' => (float) $r['voucher_out'],
                        'net_revenue' => (float) $r['net_revenue'],
                        'report_date' => substr((string) $r['report_date'], 0, 10),
                    ], $detail['records'] ?? []);
                } else {
                    $records = $this->buildLocationBatch($group, $reportDate);
                }
            } else {
                $records = $this->buildLocationBatch($group, $reportDate);
            }

            $cache[$group['location_id']] = [
                'location_id' => $group['location_id'],
                'location_name' => $group['location_name'],
                'report_date' => $reportDate,
                'records' => $records,
                'idempotency_key' => Idempotency::fileKey($group['location_id'], $reportDate),
                'submitted_at' => isset($sub['created_at']) ? strtotime($sub['created_at']) : time(),
            ];
            $hydrated++;
        }

        $this->putDailySubmitted($reportDate, $dailySubmitted);
        $this->putSubmissionCache($reportDate, $cache);
        $this->info("[locations-feed] Hydrated {$hydrated}/".count($groups)." complete daily files for {$reportDate}");
    }

    private function resetForNewDay(string $reportDate): void
    {
        $current = Cache::get(self::CACHE_PREFIX.'current_report_date');
        if ($current === $reportDate) {
            return;
        }
        Cache::put(self::CACHE_PREFIX.'current_report_date', $reportDate);
        Cache::forget(self::CACHE_PREFIX."daily_submitted:{$reportDate}");
        Cache::forget(self::CACHE_PREFIX."submission_cache:{$reportDate}");
        $this->info("[locations-feed] New report date: {$reportDate}");
    }

    /** @return list<string> */
    private function getDailySubmitted(string $reportDate): array
    {
        return Cache::get(self::CACHE_PREFIX."daily_submitted:{$reportDate}", []);
    }

    /** @param list<string> $ids */
    private function putDailySubmitted(string $reportDate, array $ids): void
    {
        Cache::put(self::CACHE_PREFIX."daily_submitted:{$reportDate}", array_values(array_unique($ids)), now()->addDay());
    }

    /** @return array<string, array<string, mixed>> */
    private function getSubmissionCache(string $reportDate): array
    {
        return Cache::get(self::CACHE_PREFIX."submission_cache:{$reportDate}", []);
    }

    /** @param array<string, array<string, mixed>> $cache */
    private function putSubmissionCache(string $reportDate, array $cache): void
    {
        Cache::put(self::CACHE_PREFIX."submission_cache:{$reportDate}", $cache, now()->addDay());
    }

    private function today(): string
    {
        return gmdate('Y-m-d');
    }
}
