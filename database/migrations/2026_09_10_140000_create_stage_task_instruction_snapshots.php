<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_stage_instruction_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('agent_mode', 64);
            $table->unsignedInteger('version');
            $table->text('body');
            $table->timestamps();

            $table->unique(['project_id', 'agent_mode', 'version'], 'project_stage_instruction_version_unique');
            $table->index(['project_id', 'agent_mode']);
        });

        Schema::create('workflow_run_stage_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();
            $table->string('agent_mode', 64);
            $table->unsignedInteger('source_version');
            $table->string('source', 16);
            $table->text('body');
            $table->timestamps();

            $table->unique(['workflow_run_id', 'agent_mode'], 'workflow_run_stage_instruction_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_stage_instructions');
        Schema::dropIfExists('project_stage_instruction_versions');
    }
};
