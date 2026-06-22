<?php

namespace Tests\Feature;

use App\Services\ImportService;
use App\Support\Idempotency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('game_types')->insert(['id' => 1, 'name' => 'Test Game']);
        DB::table('locations')->insert([
            'location_id' => 'LOC-001',
            'location_name' => 'Test Location',
            'addr' => '1 Main',
            'city' => 'Austin',
            'st' => 'TX',
            'zip' => '78701',
        ]);
        DB::table('machines')->insert([
            'machine_id' => 'VGT-1001',
            'location_id' => 'LOC-001',
            'game_type_id' => 1,
        ]);
    }

    public function test_identical_resubmit_is_skipped(): void
    {
        $reportDate = '2099-01-02';
        $record = [
            'location_id' => 'LOC-001',
            'location_name' => 'Test Location',
            'machine_id' => 'VGT-1001',
            'cash_in' => 100,
            'voucher_in' => 50,
            'voucher_out' => 20,
            'net_revenue' => 130,
            'report_date' => $reportDate,
        ];

        $ctx = [
            'source' => 'test',
            'submission_kind' => 'daily',
            'location_id' => 'LOC-001',
            'report_date' => $reportDate,
            'idempotency_key' => Idempotency::fileKey('LOC-001', $reportDate),
            'expected_record_count' => 1,
        ];

        $import = app(ImportService::class);
        $first = $import->importRevenue([$record], $ctx);
        $this->assertSame(1, $first['imported']);

        $second = $import->importRevenue([$record], [...$ctx, 'submission_kind' => 'resubmit']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(0, $second['imported']);

        $count = DB::table('revenue_records')
            ->where('machine_id', 'VGT-1001')
            ->where('report_date', '2099-01-02')
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_import_does_not_clear_location_address(): void
    {
        $reportDate = '2099-01-03';
        $record = [
            'location_id' => 'LOC-001',
            'location_name' => 'Test Location',
            'machine_id' => 'VGT-1001',
            'cash_in' => 100,
            'voucher_in' => 50,
            'voucher_out' => 20,
            'net_revenue' => 130,
            'report_date' => $reportDate,
        ];

        app(ImportService::class)->importRevenue([$record], [
            'source' => 'test',
            'submission_kind' => 'daily',
            'location_id' => 'LOC-001',
            'report_date' => $reportDate,
            'idempotency_key' => Idempotency::fileKey('LOC-001', $reportDate),
            'expected_record_count' => 1,
        ]);

        $location = DB::table('locations')->where('location_id', 'LOC-001')->first();
        $this->assertSame('1 Main', $location->addr);
        $this->assertSame('Austin', $location->city);
        $this->assertSame('TX', $location->st);
        $this->assertSame('78701', $location->zip);
    }

    public function test_envelope_rejects_record_date_mismatch(): void
    {
        $reportDate = '2099-06-20';
        $records = [[
            'location_id' => 'LOC-001',
            'location_name' => 'Test Location',
            'machine_id' => 'VGT-1001',
            'cash_in' => 100,
            'voucher_in' => 50,
            'voucher_out' => 20,
            'net_revenue' => 130,
            'report_date' => '2099-06-19',
        ]];

        $import = app(ImportService::class);
        $summary = $import->importRevenue($records, [
            'source' => 'LOC-001',
            'submission_kind' => 'daily',
            'location_id' => 'LOC-001',
            'report_date' => $reportDate,
            'idempotency_key' => Idempotency::fileKey('LOC-001', $reportDate),
            'expected_record_count' => count($records),
        ]);

        $this->assertTrue($summary['validation_failed'] ?? false);
        $this->assertSame(0, $summary['imported']);
    }
}
