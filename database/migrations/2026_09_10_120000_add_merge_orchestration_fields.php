<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amp_project_connections', function (Blueprint $table) {
            $table->unsignedSmallInteger('controller_protocol_version')->default(1)->after('controller_key');
        });

        Schema::table('stage_runs', function (Blueprint $table) {
            $table->char('github_pull_request_head_sha', 40)->nullable()->after('github_pull_request_url');
            $table->char('github_merge_commit_sha', 40)->nullable()->after('github_pull_request_head_sha');
        });
    }

    public function down(): void
    {
        Schema::table('stage_runs', function (Blueprint $table) {
            $table->dropColumn(['github_pull_request_head_sha', 'github_merge_commit_sha']);
        });

        Schema::table('amp_project_connections', function (Blueprint $table) {
            $table->dropColumn('controller_protocol_version');
        });
    }
};
