<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardApiTest extends TestCase
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

    public function test_dashboard_excludes_test_report_dates_and_counts_distinct_faulty_records(): void
    {
        $prodId = DB::table('revenue_records')->insertGetId([
            'machine_id' => 'VGT-1001',
            'report_date' => '2026-06-01',
            'cash_in' => 100,
            'voucher_in' => 0,
            'voucher_out' => 0,
            'net_revenue' => 100,
            'computed_net_revenue' => 100,
            'is_faulty' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('transaction_faults')->insert([
            [
                'revenue_record_id' => $prodId,
                'fault_type' => 'arithmetic_mismatch',
                'severity' => 'severe',
                'delta' => 10,
                'description' => 'Arithmetic mismatch',
            ],
            [
                'revenue_record_id' => $prodId,
                'fault_type' => 'underreported_net',
                'severity' => 'moderate',
                'delta' => 5,
                'description' => 'Under-reported net',
            ],
        ]);

        DB::table('revenue_records')->insert([
            'machine_id' => 'VGT-1001',
            'report_date' => '2099-01-01',
            'cash_in' => 999,
            'voucher_in' => 0,
            'voucher_out' => 0,
            'net_revenue' => 999,
            'computed_net_revenue' => 999,
            'is_faulty' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/revenue/dashboard');
        $response->assertOk();

        $kpis = $response->json('kpis');
        $this->assertSame(1, $kpis['total_records']);
        $this->assertSame(100.0, (float) $kpis['total_net_revenue']);
        $this->assertSame(1, $kpis['faulty_count']);

        $faultStats = collect($response->json('fault_stats'))->keyBy('fault_type');
        $this->assertSame(1, $faultStats['arithmetic_mismatch']['count']);
        $this->assertSame(1, $faultStats['underreported_net']['count']);

        $byDate = $response->json('by_date');
        $this->assertCount(1, $byDate);
        $this->assertSame('2026-06-01', $byDate[0]['report_date']);
    }
}
