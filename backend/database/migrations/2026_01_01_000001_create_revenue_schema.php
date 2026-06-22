<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seed_runs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamp('completed_at')->useCurrent();
        });

        Schema::create('fault_types', function (Blueprint $table) {
            $table->string('code', 64)->primary();
            $table->string('label');
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        DB::table('fault_types')->insert([
            ['code' => 'underreported_net', 'label' => 'Under-reported net', 'sort_order' => 1],
            ['code' => 'overreported_net', 'label' => 'Over-reported net', 'sort_order' => 2],
            ['code' => 'arithmetic_mismatch', 'label' => 'Arithmetic mismatch', 'sort_order' => 3],
            ['code' => 'component_swap', 'label' => 'Component swap', 'sort_order' => 4],
            ['code' => 'voucher_out_exceeds_inflow', 'label' => 'Voucher out exceeds inflow', 'sort_order' => 5],
            ['code' => 'negative_component', 'label' => 'Negative component', 'sort_order' => 6],
            ['code' => 'rounding_drift', 'label' => 'Rounding drift', 'sort_order' => 7],
            ['code' => 'zero_net_with_activity', 'label' => 'Zero net with activity', 'sort_order' => 8],
            ['code' => 'location_name_mismatch', 'label' => 'Location name mismatch', 'sort_order' => 9],
        ]);

        Schema::create('game_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->string('location_id', 16)->primary();
            $table->string('location_name');
            $table->string('addr');
            $table->string('city', 128);
            $table->char('st', 2);
            $table->string('zip', 16);
        });

        Schema::create('machines', function (Blueprint $table) {
            $table->string('machine_id', 32)->primary();
            $table->string('location_id', 16);
            $table->unsignedBigInteger('game_type_id');
            $table->foreign('location_id')->references('location_id')->on('locations');
            $table->foreign('game_type_id')->references('id')->on('game_types');
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64);
            $table->string('location_id', 16)->nullable();
            $table->date('report_date')->nullable();
            $table->enum('submission_kind', ['daily', 'resubmit', 'manual', 'api'])->default('api');
            $table->string('idempotency_key', 128)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->enum('status', ['completed', 'partial', 'failed'])->default('completed');
            $table->integer('record_count')->default(0);
            $table->integer('imported_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('location_id')->references('location_id')->on('locations');
            $table->index(['location_id', 'report_date'], 'idx_import_batches_location_date');
            $table->index('created_at', 'idx_import_batches_created');
            $table->index('submission_kind', 'idx_import_batches_kind');
        });

        Schema::create('revenue_records', function (Blueprint $table) {
            $table->id();
            $table->string('machine_id', 32);
            $table->date('report_date');
            $table->decimal('cash_in', 12, 2);
            $table->decimal('voucher_in', 12, 2);
            $table->decimal('voucher_out', 12, 2);
            $table->decimal('net_revenue', 12, 2);
            $table->decimal('computed_net_revenue', 12, 2);
            $table->boolean('is_faulty')->default(false);
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['machine_id', 'report_date'], 'uk_machine_date');
            $table->foreign('machine_id')->references('machine_id')->on('machines');
            $table->foreign('import_batch_id')->references('id')->on('import_batches');
            $table->index('report_date', 'idx_revenue_records_report_date');
        });

        Schema::create('transaction_faults', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('revenue_record_id');
            $table->string('fault_type', 64);
            $table->enum('severity', ['minor', 'moderate', 'severe']);
            $table->decimal('expected_value', 12, 2)->nullable();
            $table->decimal('reported_value', 12, 2)->nullable();
            $table->decimal('delta', 12, 2);
            $table->text('description');
            $table->timestamp('detected_at')->useCurrent();
            $table->foreign('revenue_record_id')->references('id')->on('revenue_records')->cascadeOnDelete();
            $table->foreign('fault_type')->references('code')->on('fault_types');
        });

        Schema::create('expected_totals', function (Blueprint $table) {
            $table->id();
            $table->string('location_id', 16);
            $table->date('report_date');
            $table->decimal('expected_net_revenue', 12, 2);
            $table->text('notes')->nullable();
            $table->unique(['location_id', 'report_date'], 'uk_location_date');
            $table->foreign('location_id')->references('location_id')->on('locations');
            $table->index(['location_id', 'report_date'], 'idx_expected_totals_location_date');
        });

        Schema::create('reconciliation_results', function (Blueprint $table) {
            $table->id();
            $table->string('location_id', 16);
            $table->date('report_date');
            $table->decimal('expected_net_revenue', 12, 2);
            $table->decimal('actual_net_revenue', 12, 2);
            $table->decimal('variance', 12, 2);
            $table->decimal('shortfall', 12, 2)->default(0);
            $table->decimal('overage', 12, 2)->default(0);
            $table->boolean('meets_expectation')->default(false);
            $table->enum('status', ['match', 'mismatch']);
            $table->enum('variance_tier', ['none', 'minor', 'moderate', 'severe'])->default('none');
            $table->timestamp('computed_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['location_id', 'report_date'], 'uk_recon_location_date');
            $table->foreign('location_id')->references('location_id')->on('locations');
            $table->index('shortfall', 'idx_reconciliation_shortfall');
            $table->index(['location_id', 'report_date'], 'idx_reconciliation_location_date');
        });

        Schema::create('location_daily_files', function (Blueprint $table) {
            $table->string('location_id', 16);
            $table->date('report_date');
            $table->string('idempotency_key', 128);
            $table->char('payload_hash', 64)->nullable();
            $table->enum('status', ['in_progress', 'complete'])->default('in_progress');
            $table->integer('expected_record_count')->default(0);
            $table->primary(['location_id', 'report_date']);
            $table->unique('idempotency_key', 'uk_file_idempotency_key');
            $table->foreign('location_id')->references('location_id')->on('locations');
        });

        $this->seedReferenceData();
    }

    private function seedReferenceData(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $path = database_path('sql/reference_data.sql');
        if (! is_readable($path)) {
            throw new \RuntimeException("Missing reference data SQL: {$path}");
        }

        if (DB::table('locations')->exists()) {
            if (! DB::table('expected_totals')->exists()) {
                $sql = (string) file_get_contents($path);
                if (preg_match('/INSERT INTO expected_totals\b.*?;/s', $sql, $match)) {
                    DB::unprepared($match[0]);
                }
            }

            return;
        }

        DB::unprepared((string) file_get_contents($path));
    }

    public function down(): void
    {
        Schema::dropIfExists('location_daily_files');
        Schema::dropIfExists('reconciliation_results');
        Schema::dropIfExists('expected_totals');
        Schema::dropIfExists('transaction_faults');
        Schema::dropIfExists('revenue_records');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('machines');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('game_types');
        Schema::dropIfExists('fault_types');
        Schema::dropIfExists('seed_runs');
    }
};
