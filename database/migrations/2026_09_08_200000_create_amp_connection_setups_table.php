<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amp_connection_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('amp_project_connection_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->text('token')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 24)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_thread_id')->nullable();
            $table->string('claimed_amp_project_id', 100)->nullable();
            $table->char('controller_source_sha256', 64)->nullable();
            $table->char('webhook_url_hash', 64)->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['amp_project_connection_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amp_connection_setups');
    }
};
