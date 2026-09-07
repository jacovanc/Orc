<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('name');
            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['key', 'version']);
            $table->index(['key', 'is_active']);
        });

        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('type');
            $table->json('config')->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['workflow_definition_id', 'key']);
            $table->unique(['workflow_definition_id', 'position']);
        });

        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_stage_id')->constrained('workflow_stages')->cascadeOnDelete();
            $table->string('outcome');
            $table->foreignId('to_stage_id')->constrained('workflow_stages')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['workflow_definition_id', 'from_stage_id', 'outcome'],
                'workflow_transitions_deterministic_unique'
            );
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('workflow_definition_id')->constrained()->restrictOnDelete();
            $table->string('github_repository');
            $table->unsignedBigInteger('github_issue_number');
            $table->string('github_issue_url');
            $table->string('status');
            $table->foreignId('current_stage_id')->nullable()->constrained('workflow_stages')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['github_repository', 'github_issue_number']);
        });

        Schema::create('stage_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_stage_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status');
            $table->string('outcome')->nullable();
            $table->string('amp_thread_id')->nullable();
            $table->string('amp_event_id')->nullable();
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_run_id', 'attempt_number']);
            // Every database supported by Orc permits many NULLs but only one value of 1.
            $table->unique(['workflow_run_id', 'active_slot'], 'stage_runs_one_active_per_workflow');
        });

        Schema::create('workflow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('happened_at');

            $table->index(['workflow_run_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_events');
        Schema::dropIfExists('stage_runs');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_stages');
        Schema::dropIfExists('workflow_definitions');
    }
};
