<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_ready_endpoint(): void
    {
        $response = $this->getJson('/api/health?ready=1');

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'ready' => true]);
    }
}
