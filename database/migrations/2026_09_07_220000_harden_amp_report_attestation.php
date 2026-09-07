<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amp_launches', function (Blueprint $table) {
            $table->char('report_nonce', 64)->nullable()->unique()->after('idempotency_key');
        });

        Schema::table('stage_runs', function (Blueprint $table) {
            $table->string('github_report_url')->nullable()->after('amp_event_id');
            $table->unsignedBigInteger('github_report_comment_id')->nullable()->unique()->after('github_report_url');
        });
    }

    public function down(): void
    {
        Schema::table('stage_runs', function (Blueprint $table) {
            $table->dropUnique(['github_report_comment_id']);
            $table->dropColumn(['github_report_url', 'github_report_comment_id']);
        });

        Schema::table('amp_launches', function (Blueprint $table) {
            $table->dropUnique(['report_nonce']);
            $table->dropColumn('report_nonce');
        });
    }
};
