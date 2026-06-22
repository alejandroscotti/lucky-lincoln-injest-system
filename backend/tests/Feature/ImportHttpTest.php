<?php

namespace Tests\Feature;

use App\Support\Idempotency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportHttpTest extends TestCase
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

    public function test_import_rejects_envelope_date_mismatch_via_http(): void
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

        $response = $this->postJson('/api/revenue/import', $records, [
            'x-idempotency-key' => Idempotency::fileKey('LOC-001', $reportDate),
            'x-submission-kind' => 'daily',
            'x-expected-record-count' => '1',
            'x-location-id' => 'LOC-001',
            'x-report-date' => $reportDate,
            'x-source' => 'test',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('validation_failed', true)
            ->assertJsonPath('imported', 0);

        $this->assertSame(0, DB::table('revenue_records')->count());
    }
}
