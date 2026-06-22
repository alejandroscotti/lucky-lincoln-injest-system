<?php

namespace Tests\Feature;

use App\Services\LocationsFeedApiClient;
use App\Support\Idempotency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocationsFeedApiClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('locations-feed.api_base_url', 'http://locations-feed.test');
    }

    public function test_post_import_sends_location_id_as_x_source(): void
    {
        Http::fake([
            'http://locations-feed.test/api/revenue/import' => Http::response([
                'imported' => 1,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [],
                'completion' => ['is_complete' => true],
            ], 200),
        ]);

        $records = [[
            'location_id' => 'LOC-001',
            'location_name' => 'Test Location',
            'machine_id' => 'VGT-1001',
            'cash_in' => 100,
            'voucher_in' => 0,
            'voucher_out' => 0,
            'net_revenue' => 100,
            'report_date' => '2026-06-21',
        ]];

        $client = app(LocationsFeedApiClient::class);
        $result = $client->postImport($records, [
            'submission_kind' => 'daily',
            'location_id' => 'LOC-001',
            'report_date' => '2026-06-21',
            'idempotency_key' => Idempotency::fileKey('LOC-001', '2026-06-21'),
            'expected_record_count' => 1,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);

        Http::assertSent(function ($request) use ($records) {
            return $request->url() === 'http://locations-feed.test/api/revenue/import'
                && $request->method() === 'POST'
                && $request->header('x-source')[0] === 'LOC-001'
                && $request->header('x-submission-kind')[0] === 'daily'
                && $request->header('x-location-id')[0] === 'LOC-001'
                && $request->header('x-report-date')[0] === '2026-06-21'
                && $request->header('x-expected-record-count')[0] === '1'
                && $request->data() === $records;
        });
    }
}
