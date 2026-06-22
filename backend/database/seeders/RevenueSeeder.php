<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class RevenueSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Reference catalog is loaded by migration (database/sql/reference_data.sql).');
    }
}
