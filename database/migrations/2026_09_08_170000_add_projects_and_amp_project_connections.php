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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_trigger_amp')->default(false)->after('password');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('github_repository');
            $table->string('amp_project_id', 100);
            $table->timestamps();

            $table->unique(['user_id', 'github_repository']);
            $table->index(['user_id', 'name']);
        });

        Schema::create('amp_project_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->unsignedInteger('version');
            $table->string('amp_project_id', 100);
            $table->string('controller_key', 100);
            $table->text('launch_webhook_url')->nullable();
            $table->text('launch_signing_secret')->nullable();
            $table->text('callback_signing_secret')->nullable();
            $table->string('status', 32)->default('pending');
            $table->uuid('verification_event_id')->nullable()->unique();
            $table->text('verification_secret')->nullable();
            $table->char('verification_secret_hash', 64)->nullable()->unique();
            $table->longText('verification_payload')->nullable();
            $table->unsignedSmallInteger('verification_attempts')->default(0);
            $table->timestamp('verification_claimed_at')->nullable();
            $table->string('verification_thread_id')->nullable()->unique();
            $table->timestamp('verified_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'version']);
            $table->index(['project_id', 'status']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('current_amp_project_connection_id')
                ->nullable()
                ->after('amp_project_id')
                ->constrained('amp_project_connections')
                ->nullOnDelete();
        });

        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('amp_project_connection_id')
                ->nullable()
                ->after('project_id')
                ->constrained('amp_project_connections')
                ->restrictOnDelete();
        });

        Schema::table('amp_launches', function (Blueprint $table) {
            $table->foreignId('amp_project_connection_id')
                ->nullable()
                ->after('stage_run_id')
                ->constrained('amp_project_connections')
                ->restrictOnDelete();
        });

        $allowedEmails = collect(config('services.amp.legacy_operator_emails', []))
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->filter();
        DB::table('users')
            ->whereIn(DB::raw('LOWER(email)'), $allowedEmails->all())
            ->update(['can_trigger_amp' => true]);

        $legacyAmpProjectId = (string) config('services.amp.project_identity', 'legacy-unverified');
        $legacyUrl = (string) config('services.amp.launch_webhook_url', '');
        $legacyLaunchSecret = (string) config('services.amp.launch_signing_secret', '');
        $legacyCallbackSecret = (string) config('services.amp.callback_signing_secret', '');

        DB::table('workflow_runs')
            ->select(['user_id', 'github_repository'])
            ->distinct()
            ->orderBy('user_id')
            ->each(function (object $group) use ($legacyAmpProjectId, $legacyUrl, $legacyLaunchSecret, $legacyCallbackSecret) {
                $repository = strtolower($group->github_repository);
                $now = now();
                $projectId = DB::table('projects')->insertGetId([
                    'user_id' => $group->user_id,
                    'name' => Str::headline(Str::afterLast($repository, '/')),
                    'github_repository' => $repository,
                    'amp_project_id' => $legacyAmpProjectId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $configured = $legacyUrl !== '' && $legacyLaunchSecret !== '' && $legacyCallbackSecret !== '';
                $connectionId = DB::table('amp_project_connections')->insertGetId([
                    'project_id' => $projectId,
                    'public_id' => (string) Str::uuid(),
                    'version' => 1,
                    'amp_project_id' => $legacyAmpProjectId,
                    'controller_key' => 'orc-stage-launch-v9',
                    'launch_webhook_url' => $configured ? Crypt::encryptString($legacyUrl) : null,
                    'launch_signing_secret' => $configured ? Crypt::encryptString($legacyLaunchSecret) : null,
                    'callback_signing_secret' => $configured ? Crypt::encryptString($legacyCallbackSecret) : null,
                    'status' => $configured && $legacyAmpProjectId !== 'legacy-unverified' ? 'verified' : 'pending',
                    'verified_at' => $configured && $legacyAmpProjectId !== 'legacy-unverified' ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('projects')->where('id', $projectId)->update([
                    'current_amp_project_connection_id' => $connectionId,
                ]);
                DB::table('workflow_runs')
                    ->where('user_id', $group->user_id)
                    ->whereRaw('LOWER(github_repository) = ?', [$repository])
                    ->update([
                        'project_id' => $projectId,
                        'amp_project_connection_id' => $connectionId,
                    ]);
            });

        DB::table('amp_launches')
            ->select('amp_launches.id', 'workflow_runs.amp_project_connection_id')
            ->join('stage_runs', 'stage_runs.id', '=', 'amp_launches.stage_run_id')
            ->join('workflow_runs', 'workflow_runs.id', '=', 'stage_runs.workflow_run_id')
            ->orderBy('amp_launches.id')
            ->each(fn (object $launch) => DB::table('amp_launches')
                ->where('id', $launch->id)
                ->update(['amp_project_connection_id' => $launch->amp_project_connection_id]));
    }

    public function down(): void
    {
        Schema::table('amp_launches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('amp_project_connection_id');
        });
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('amp_project_connection_id');
            $table->dropConstrainedForeignId('project_id');
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_amp_project_connection_id');
        });
        Schema::dropIfExists('amp_project_connections');
        Schema::dropIfExists('projects');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_trigger_amp');
        });
    }
};
