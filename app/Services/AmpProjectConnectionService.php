<?php

namespace App\Services;

use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Jobs\VerifyAmpProjectConnection;
use App\Models\AmpProjectConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AmpProjectConnectionService
{
    public function __construct(private readonly AmpConnectionUrlGuard $urlGuard) {}

    public function configure(Project $project, User $actor, array $attributes): AmpProjectConnection
    {
        $this->assertCanManage($project, $actor);
        $this->urlGuard->assertAllowed($attributes['launch_webhook_url']);
        foreach (['launch_signing_secret', 'callback_signing_secret'] as $field) {
            if (strlen($attributes[$field]) < 32) {
                throw new WorkflowConflict('Each directional signing secret must contain at least 32 characters.');
            }
        }

        return DB::transaction(function () use ($project, $attributes) {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $version = ((int) $lockedProject->connections()->max('version')) + 1;
            $connection = AmpProjectConnection::query()->create([
                'project_id' => $lockedProject->id,
                'public_id' => (string) Str::uuid(),
                'version' => $version,
                'amp_project_id' => $attributes['amp_project_id'],
                'controller_key' => 'orc-stage-launch-v9',
                'launch_webhook_url' => trim($attributes['launch_webhook_url']),
                'launch_signing_secret' => $attributes['launch_signing_secret'],
                'callback_signing_secret' => $attributes['callback_signing_secret'],
                'status' => 'pending',
            ]);
            $lockedProject->forceFill([
                'amp_project_id' => $connection->amp_project_id,
                'current_amp_project_connection_id' => $connection->id,
            ])->save();

            return $connection;
        }, 3);
    }

    public function beginVerification(Project $project, AmpProjectConnection $connection, User $actor): AmpProjectConnection
    {
        $this->assertCanManage($project, $actor);
        if ($connection->project_id !== $project->id || $project->current_amp_project_connection_id !== $connection->id) {
            throw new WorkflowConflict('Only the current connection version can be verified.');
        }

        $connection = DB::transaction(function () use ($connection) {
            $locked = AmpProjectConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $this->urlGuard->assertAllowed((string) $locked->launch_webhook_url);
            if (! $locked->launch_signing_secret || ! $locked->callback_signing_secret) {
                throw new WorkflowConflict('The controller connection is incomplete.');
            }

            $eventId = (string) Str::uuid();
            $token = Str::random(64);
            $payload = json_encode([
                'schema_version' => 1,
                'command' => 'verify_connection',
                'event_id' => $eventId,
                'idempotency_key' => $eventId,
                'connection_id' => $locked->public_id,
                'expected_amp_project_id' => $locked->amp_project_id,
                'github_repository' => $locked->project()->value('github_repository'),
                'verification_url' => url('/api/integrations/amp/connection-verification'),
                'verification_token' => $token,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $locked->forceFill([
                'status' => 'verifying',
                'verification_event_id' => $eventId,
                'verification_secret' => $token,
                'verification_secret_hash' => hash('sha256', $token),
                'verification_payload' => $payload,
                'verification_attempts' => 0,
                'verification_claimed_at' => null,
                'verification_thread_id' => null,
                'verified_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            VerifyAmpProjectConnection::dispatch($locked->id)->afterCommit();

            return $locked;
        }, 3);

        return $connection->fresh();
    }

    public function handleVerification(string $token, array $payload): array
    {
        return DB::transaction(function () use ($token, $payload) {
            $connection = AmpProjectConnection::query()
                ->with('project')
                ->where('verification_secret_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();
            if (! $connection || ! hash_equals((string) $connection->verification_secret, $token)) {
                throw new WorkflowConflict('This connection verification capability is invalid.');
            }
            if ($connection->status === 'verified' && $payload['action'] === 'complete') {
                return ['accepted' => true, 'disposition' => 'already_verified'];
            }
            if ($connection->status === 'failed' && $payload['action'] === 'failed') {
                return ['accepted' => true, 'disposition' => 'already_failed'];
            }
            if ($connection->status !== 'verifying') {
                throw new WorkflowConflict('This connection is not awaiting verification.');
            }
            if (
                $payload['amp_project_id'] !== $connection->amp_project_id
                || strtolower($payload['github_repository']) !== strtolower($connection->project->github_repository)
            ) {
                $this->markFailed($connection, 'identity_mismatch', 'The controller or child Orb did not match this project mapping.');

                throw new WorkflowConflict('The reported Amp project or GitHub repository does not match this connection.');
            }

            return match ($payload['action']) {
                'claim' => $this->claimVerification($connection),
                'started' => $this->startVerification($connection, $payload['thread_id'] ?? null),
                'complete' => $this->completeVerification(
                    $connection,
                    $payload['thread_id'] ?? null,
                    (bool) $payload['native_github_access'],
                ),
                'failed' => $this->failVerification(
                    $connection,
                    $payload['thread_id'] ?? null,
                    $payload['failure_code'],
                ),
            };
        }, 3);
    }

    public function markDeliveryFailed(AmpProjectConnection $connection, string $code, string $message): void
    {
        DB::transaction(function () use ($connection, $code, $message) {
            $locked = AmpProjectConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($locked->status === 'verifying') {
                $this->markFailed($locked, $code, $message);
            }
        }, 3);
    }

    private function claimVerification(AmpProjectConnection $connection): array
    {
        if ($connection->verification_claimed_at) {
            return [
                'accepted' => true,
                'launch' => false,
                'thread_id' => $connection->verification_thread_id,
                'disposition' => $connection->verification_thread_id ? 'already_started' : 'ambiguous_claim',
            ];
        }
        $connection->forceFill(['verification_claimed_at' => now()])->save();

        return ['accepted' => true, 'launch' => true, 'disposition' => 'claimed'];
    }

    private function startVerification(AmpProjectConnection $connection, ?string $threadId): array
    {
        if (! $connection->verification_claimed_at) {
            throw new WorkflowConflict('Connection verification must be claimed before a child thread starts.');
        }
        if (! $threadId) {
            throw new WorkflowConflict('Connection verification requires its child thread ID.');
        }
        if ($connection->verification_thread_id && $connection->verification_thread_id !== $threadId) {
            throw new WorkflowConflict('A different child thread is already bound to this verification.');
        }
        $connection->forceFill(['verification_thread_id' => $threadId])->save();

        return ['accepted' => true, 'disposition' => 'started'];
    }

    private function completeVerification(AmpProjectConnection $connection, ?string $threadId, bool $nativeAccess): array
    {
        if (! $threadId || $threadId !== $connection->verification_thread_id) {
            throw new WorkflowConflict('The verification result came from an unbound child thread.');
        }
        if (! $nativeAccess) {
            $this->markFailed($connection, 'native_github_access_missing', 'The fresh Orb could not read the canonical repository with native GitHub authentication.');

            return ['accepted' => false, 'disposition' => 'native_access_missing'];
        }
        $connection->forceFill([
            'status' => 'verified',
            'verified_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        return ['accepted' => true, 'disposition' => 'verified'];
    }

    private function failVerification(AmpProjectConnection $connection, ?string $threadId, string $code): array
    {
        if (! $connection->verification_claimed_at) {
            throw new WorkflowConflict('Connection verification must be claimed before it can fail.');
        }
        if ($connection->verification_thread_id && $threadId !== $connection->verification_thread_id) {
            throw new WorkflowConflict('The verification failure came from an unbound child thread.');
        }
        if ($threadId && ! $connection->verification_thread_id) {
            $connection->forceFill(['verification_thread_id' => $threadId])->save();
        }
        $this->markFailed(
            $connection,
            $code,
            'The trusted controller could not start the fresh verification Orb safely.',
        );

        return ['accepted' => true, 'disposition' => 'failed'];
    }

    private function markFailed(AmpProjectConnection $connection, string $code, string $message): void
    {
        $connection->forceFill([
            'status' => 'failed',
            'last_error_code' => $code,
            'last_error_message' => $message,
        ])->save();
    }

    private function assertCanManage(Project $project, User $actor): void
    {
        if ($project->user_id !== $actor->id || ! $actor->can_trigger_amp) {
            throw new WorkflowConflict('Your account is not permitted to manage this Amp project connection.');
        }
    }
}
