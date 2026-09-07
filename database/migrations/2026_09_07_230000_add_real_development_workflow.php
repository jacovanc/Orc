<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amp_launches', function (Blueprint $table) {
            $table->text('capability_secret')->nullable()->after('report_nonce');
            $table->char('capability_hash', 64)->nullable()->unique()->after('capability_secret');
            $table->longText('payload_body')->nullable()->after('capability_hash');
            $table->timestamp('report_claimed_at')->nullable()->after('payload_hash');
            $table->uuid('cancellation_event_id')->nullable()->unique()->after('report_claimed_at');
            $table->string('cancellation_status')->nullable()->after('cancellation_event_id');
            $table->unsignedSmallInteger('cancellation_attempts')->default(0)->after('cancellation_status');
            $table->text('cancellation_last_error')->nullable()->after('cancellation_attempts');
        });

        DB::table('amp_launches')->whereNull('capability_hash')->orderBy('id')->chunkById(100, function ($launches) {
            foreach ($launches as $launch) {
                $capability = Str::random(64);
                DB::table('amp_launches')->where('id', $launch->id)->update([
                    'capability_secret' => Crypt::encryptString($capability),
                    'capability_hash' => hash('sha256', $capability),
                ]);
            }
        });

        Schema::table('stage_runs', function (Blueprint $table) {
            $table->string('github_report_kind', 32)->nullable()->after('github_report_comment_id');
            $table->string('github_branch')->nullable()->after('github_report_kind');
            $table->unsignedBigInteger('github_pull_request_number')->nullable()->after('github_branch');
            $table->string('github_pull_request_url', 2048)->nullable()->after('github_pull_request_number');
        });
    }

    public function down(): void
    {
        Schema::table('stage_runs', function (Blueprint $table) {
            $table->dropColumn([
                'github_branch',
                'github_pull_request_number',
                'github_pull_request_url',
                'github_report_kind',
            ]);
        });

        Schema::table('amp_launches', function (Blueprint $table) {
            $table->dropUnique(['cancellation_event_id']);
            $table->dropUnique(['capability_hash']);
            $table->dropColumn([
                'capability_secret',
                'capability_hash',
                'payload_body',
                'report_claimed_at',
                'cancellation_event_id',
                'cancellation_status',
                'cancellation_attempts',
                'cancellation_last_error',
            ]);
        });
    }
};
