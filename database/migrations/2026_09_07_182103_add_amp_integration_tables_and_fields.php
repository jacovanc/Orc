<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->timestamp('failed_at')->nullable()->after('cancelled_at');
        });

        Schema::table('stage_runs', function (Blueprint $table) {
            $table->unique('amp_thread_id', 'stage_runs_amp_thread_unique');
            $table->unique('amp_event_id', 'stage_runs_amp_event_unique');
        });

        Schema::create('amp_launches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('event_id')->unique();
            $table->uuid('idempotency_key')->unique();
            $table->char('payload_hash', 64)->nullable();
            $table->string('delivery_status');
            $table->string('launch_status');
            $table->unsignedSmallInteger('delivery_attempts')->default(0);
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['delivery_status', 'launch_status']);
        });

        Schema::create('amp_integration_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100)->unique();
            $table->string('event_type', 64);
            $table->foreignId('stage_run_id')->nullable()->constrained()->nullOnDelete();
            $table->char('payload_hash', 64);
            $table->string('status');
            $table->json('response');
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at');

            $table->index(['stage_run_id', 'processed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amp_integration_events');
        Schema::dropIfExists('amp_launches');

        Schema::table('stage_runs', function (Blueprint $table) {
            $table->dropUnique('stage_runs_amp_thread_unique');
            $table->dropUnique('stage_runs_amp_event_unique');
        });

        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->dropColumn('failed_at');
        });
    }
};
