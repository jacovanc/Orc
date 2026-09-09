<?php

namespace App\Services;

use App\Domain\Workflow\AmpIntegrationEventStatus;
use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Jobs\VerifyAmpProjectConnection;
use App\Models\AmpConnectionSetup;
use App\Models\AmpIntegrationEvent;
use App\Models\AmpProjectConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AmpProjectConnectionService
{
    private const CONTROLLER_KEY_PREFIX = 'orc-stage-launch-v11';

    private const SETUP_TTL_MINUTES = 20;

    public function __construct(private readonly AmpConnectionUrlGuard $urlGuard) {}

    /**
     * Allocate the connection identity before the controller webhook exists.
     * Existing runs retain their connection snapshot when a newer setup is issued.
     *
     * @return array{connection: AmpProjectConnection, setup: AmpConnectionSetup}
     */
    public function issueSetup(Project $project, User $actor): array
    {
        $this->assertCanManage($project, $actor);

        return DB::transaction(function () use ($project) {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($lockedProject->amp_project_id === null && $lockedProject->connections()->whereNotNull('amp_project_id')->exists()) {
                throw new WorkflowConflict('This Project has inconsistent Amp identity history and cannot issue setup safely.');
            }
            AmpConnectionSetup::query()
                ->whereHas('connection', fn ($query) => $query->where('project_id', $lockedProject->id))
                ->whereIn('status', ['pending', 'claimed'])
                ->lockForUpdate()
                ->get()
                ->each(fn (AmpConnectionSetup $setup) => $setup->forceFill([
                    'status' => 'revoked',
                    'token' => null,
                    'revoked_at' => now(),
                ])->save());

            $publicId = (string) Str::uuid();
            $connection = AmpProjectConnection::query()->create([
                'project_id' => $lockedProject->id,
                'public_id' => $publicId,
                'version' => ((int) $lockedProject->connections()->max('version')) + 1,
                'amp_project_id' => $lockedProject->amp_project_id,
                'controller_key' => $this->controllerKey($publicId),
                'launch_signing_secret' => Str::random(64),
                'callback_signing_secret' => Str::random(64),
                'status' => 'setup_pending',
            ]);
            $token = Str::random(64);
            $setup = $connection->setups()->create([
                'public_id' => (string) Str::uuid(),
                'token' => $token,
                'token_hash' => hash('sha256', $token),
                'status' => 'pending',
                'expires_at' => now()->addMinutes(self::SETUP_TTL_MINUTES),
            ]);
            $lockedProject->forceFill(['current_amp_project_connection_id' => $connection->id])->save();

            return compact('connection', 'setup');
        }, 3);
    }

    public function setupPrompt(AmpConnectionSetup $setup): string
    {
        return implode("\n", [
            'Set up this existing Orc Project from this exact Amp project.',
            '',
            'Consent and scope: you may install/update only the personal Orc worker integration and this project’s .amp/plugins/orc-integration controller, register its webhook, and run one harmless fresh-Orb placement/native repository-read verification. Do not change code, start a workflow, create a branch or pull request, publish GitHub content, merge anything, or alter unrelated plugins.',
            '',
            'Use the already-installed `orc_setup_project` tool with the fields below. Do not print, quote, summarize, or place the capability in shell commands/files. The tool exchanges controller material over HTTPS and writes only owner-readable gitignored runtime configuration.',
            '',
            'setup_url: '.url('/api/integrations/amp/project-setup'),
            'setup_id: '.$setup->public_id,
            'setup_capability: '.$setup->token,
            '',
            'This is Orc setup protocol v1. Public authoritative runbook: '.route('docs.project-setup-v1'),
            'If the tool reports that Amp must reload plugins, ask me to run “plugins: reload” once, then call `orc_setup_project` again with the same fields. Do not claim setup or verification succeeded until the tool confirms it.',
        ]);
    }

    public function claimSetup(string $token, array $payload): array
    {
        $source = (string) file_get_contents(base_path('.amp/plugins/orc-integration/index.ts'));
        $sourceHash = hash('sha256', $source);

        return DB::transaction(function () use ($token, $payload, $source, $sourceHash) {
            $setup = $this->lockedSetup($token, $payload['setup_id']);
            $connection = $setup->connection()->with('project.user')->firstOrFail();
            $this->assertCanManage($connection->project, $connection->project->user);

            if ($setup->status === 'completed') {
                $this->assertSetupIdentity($setup, $connection, $payload);

                return ['accepted' => true, 'disposition' => 'already_completed', 'verification_status' => $connection->status];
            }
            if ($setup->status === 'revoked') {
                throw new WorkflowConflict('This project setup capability was revoked.');
            }
            if ($setup->isExpired()) {
                $setup->forceFill(['status' => 'expired', 'token' => null])->save();
                throw new WorkflowConflict('This project setup capability expired. Generate a new prompt in Orc.');
            }
            if ($setup->claimed_thread_id && $setup->claimed_thread_id !== $payload['thread_id']) {
                throw new WorkflowConflict('This project setup capability is already bound to another Amp thread.');
            }
            $this->bindSetupIdentity($connection, $payload['amp_project_id']);
            $this->assertSetupIdentity($setup, $connection, $payload);

            $setup->forceFill([
                'status' => 'claimed',
                'claimed_at' => $setup->claimed_at ?: now(),
                'claimed_thread_id' => $payload['thread_id'],
                'claimed_amp_project_id' => $payload['amp_project_id'],
                'controller_source_sha256' => $sourceHash,
            ])->save();

            return [
                'accepted' => true,
                'disposition' => 'claimed',
                'protocol_version' => 1,
                'connection_id' => $connection->public_id,
                'amp_project_id' => $connection->amp_project_id,
                'github_repository' => $connection->project->github_repository,
                'controller_key' => $connection->controller_key,
                'controller_source' => $source,
                'controller_source_sha256' => $sourceHash,
                'runtime_config' => [
                    'connectionId' => $connection->public_id,
                    'ampProjectId' => $connection->amp_project_id,
                    'launchSigningSecret' => $connection->launch_signing_secret,
                    'callbackSigningSecret' => $connection->callback_signing_secret,
                    'connectionCallbackUrl' => url('/api/integrations/amp/connections/'.$connection->public_id),
                ],
            ];
        }, 3);
    }

    public function completeSetup(string $token, array $payload): array
    {
        $shouldVerify = false;
        $connection = DB::transaction(function () use ($token, $payload, &$shouldVerify) {
            $setup = $this->lockedSetup($token, $payload['setup_id']);
            $connection = $setup->connection()->with('project.user')->firstOrFail();
            $this->assertCanManage($connection->project, $connection->project->user);
            $this->assertSetupIdentity($setup, $connection, $payload);

            if ($setup->status === 'completed') {
                return $connection;
            }
            if ($setup->status !== 'claimed' || $setup->isExpired()) {
                throw new WorkflowConflict('This project setup capability is not active. Generate a new prompt in Orc.');
            }
            if (! hash_equals((string) $setup->controller_source_sha256, $payload['controller_source_sha256'])) {
                throw new WorkflowConflict('The installed controller does not match the setup artifact issued by Orc.');
            }
            $this->urlGuard->assertAllowed($payload['launch_webhook_url']);
            $connection->forceFill([
                'launch_webhook_url' => trim($payload['launch_webhook_url']),
                'status' => 'pending',
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();
            $setup->forceFill([
                'status' => 'completed',
                'webhook_url_hash' => hash('sha256', trim($payload['launch_webhook_url'])),
                'completed_at' => now(),
            ])->save();
            $shouldVerify = true;

            return $connection;
        }, 3);

        if ($shouldVerify) {
            $connection = $this->beginVerification($connection->project, $connection, $connection->project->user);
        }

        return [
            'accepted' => true,
            'disposition' => $shouldVerify ? 'completed' : 'already_completed',
            'verification_status' => $connection->fresh()->status,
        ];
    }

    public function refreshWebhook(AmpProjectConnection $connection, array $payload, string $payloadHash): array
    {
        if (
            $payload['connection_id'] !== $connection->public_id
            || $payload['amp_project_id'] !== $connection->amp_project_id
        ) {
            throw new WorkflowConflict('This webhook refresh belongs to a different Amp project connection.');
        }
        $this->urlGuard->assertAllowed($payload['launch_webhook_url']);

        try {
            return DB::transaction(function () use ($connection, $payload, $payloadHash) {
                $existing = AmpIntegrationEvent::query()
                    ->where('event_id', $payload['event_id'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if ($existing->payload_hash !== $payloadHash) {
                        throw new WorkflowConflict('An Amp event ID was reused with a different payload.');
                    }

                    return $existing->response;
                }

                $locked = AmpProjectConnection::query()
                    ->with('project.user')
                    ->lockForUpdate()
                    ->findOrFail($connection->id);
                $this->assertCanManage($locked->project, $locked->project->user);
                if (! $locked->setups()->where('status', 'completed')->exists()) {
                    throw new WorkflowConflict('The project controller must finish setup before refreshing its webhook.');
                }
                if (
                    $payload['connection_id'] !== $locked->public_id
                    || $payload['amp_project_id'] !== $locked->amp_project_id
                ) {
                    throw new WorkflowConflict('This webhook refresh belongs to a different Amp project connection.');
                }

                $changed = trim($payload['launch_webhook_url']) !== (string) $locked->launch_webhook_url;
                if ($changed) {
                    $locked->forceFill(['launch_webhook_url' => trim($payload['launch_webhook_url'])])->save();
                }
                $response = [
                    'accepted' => true,
                    'disposition' => $changed ? 'updated' : 'unchanged',
                ];
                AmpIntegrationEvent::query()->create([
                    'event_id' => $payload['event_id'],
                    'event_type' => $payload['type'],
                    'stage_run_id' => null,
                    'payload_hash' => $payloadHash,
                    'status' => AmpIntegrationEventStatus::Processed,
                    'response' => $response,
                    'occurred_at' => $payload['occurred_at'],
                    'processed_at' => now(),
                ]);

                return $response;
            }, 3);
        } catch (QueryException $exception) {
            $existing = AmpIntegrationEvent::query()->where('event_id', $payload['event_id'])->first();
            if (! $existing || $existing->payload_hash !== $payloadHash) {
                throw $exception;
            }

            return $existing->response;
        }
    }

    public function markWebhookUnavailable(AmpProjectConnection $connection, string $attemptedUrl): bool
    {
        return DB::transaction(function () use ($connection, $attemptedUrl) {
            $locked = AmpProjectConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if (! hash_equals((string) $locked->launch_webhook_url, $attemptedUrl)) {
                return false;
            }
            $locked->forceFill([
                'status' => 'failed',
                'last_error_code' => 'webhook_unavailable',
                'last_error_message' => 'The Amp controller webhook is unavailable. Generate a new setup prompt from Project settings and pair it in the same Amp project.',
            ])->save();

            return true;
        }, 3);
    }

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
            $publicId = (string) Str::uuid();
            $connection = AmpProjectConnection::query()->create([
                'project_id' => $lockedProject->id,
                'public_id' => $publicId,
                'version' => $version,
                'amp_project_id' => $attributes['amp_project_id'],
                'controller_key' => $this->controllerKey($publicId),
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

    private function controllerKey(string $connectionPublicId): string
    {
        // Amp shares a durable webhook registration by plugin key across project
        // threads. A connection-scoped key prevents a replacement connection
        // from inheriting a handler owned by an older or disconnected thread.
        return self::CONTROLLER_KEY_PREFIX.'-'.$connectionPublicId;
    }

    private function lockedSetup(string $token, string $publicId): AmpConnectionSetup
    {
        $setup = AmpConnectionSetup::query()
            ->where('public_id', $publicId)
            ->where('token_hash', hash('sha256', $token))
            ->lockForUpdate()
            ->first();
        if (! $setup || ! $setup->token || ! hash_equals((string) $setup->token, $token)) {
            throw new WorkflowConflict('This project setup capability is invalid.');
        }

        return $setup;
    }

    private function assertSetupIdentity(AmpConnectionSetup $setup, AmpProjectConnection $connection, array $payload): void
    {
        if (
            $payload['amp_project_id'] !== $connection->amp_project_id
            || ($setup->claimed_amp_project_id && $setup->claimed_amp_project_id !== $payload['amp_project_id'])
            || ($setup->claimed_thread_id && $setup->claimed_thread_id !== $payload['thread_id'])
        ) {
            throw new WorkflowConflict('This setup prompt belongs to a different Amp project or thread.');
        }
    }

    private function bindSetupIdentity(AmpProjectConnection $connection, string $ampProjectId): void
    {
        if ($connection->amp_project_id !== null) {
            return;
        }
        if ($connection->project->amp_project_id !== null && $connection->project->amp_project_id !== $ampProjectId) {
            throw new WorkflowConflict('This setup prompt belongs to a different Amp project.');
        }

        $connection->forceFill(['amp_project_id' => $ampProjectId])->save();
        if ($connection->project->amp_project_id === null) {
            if ($connection->project->connections()->whereKeyNot($connection->id)->whereNotNull('amp_project_id')->exists()) {
                throw new WorkflowConflict('This setup cannot change an existing Project Amp identity.');
            }
            $connection->project->forceFill(['amp_project_id' => $ampProjectId])->save();
        }
    }
}
