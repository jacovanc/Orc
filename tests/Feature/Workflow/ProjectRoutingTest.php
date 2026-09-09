<?php

namespace Tests\Feature\Workflow;

use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Jobs\DeliverAmpLaunch;
use App\Jobs\VerifyAmpProjectConnection;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Services\AmpProjectConnectionService;
use App\Services\AmpSignature;
use App\Services\WorkflowEngine;
use Database\Seeders\DevelopmentWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DevelopmentWorkflowSeeder::class);
        $this->owner = User::factory()->create(['can_trigger_amp' => true]);
        config([
            'services.amp.enabled' => true,
            'services.amp.webhook_allowed_hosts' => ['ampcode.com'],
        ]);
        Queue::fake();
    }

    public function test_projects_are_owner_scoped_in_pages_and_actions(): void
    {
        $project = Project::factory()->for($this->owner)->configured()->create();
        $other = User::factory()->create(['can_trigger_amp' => true]);

        $this->actingAs($other)->get(route('projects.show', $project))->assertNotFound();
        $this->actingAs($other)->get(route('projects.settings', $project))->assertNotFound();
        $this->actingAs($other)->post(route('projects.connections.verify', $project))->assertNotFound();
        $this->actingAs($other)->post(route('projects.workflows.store', $project), [
            'workflow_definition_id' => WorkflowDefinition::query()->where('version', 3)->sole()->id,
            'github_issue_number' => 1,
            'github_issue_url' => 'https://github.com/'.$project->github_repository.'/issues/1',
        ])->assertNotFound();
    }

    public function test_account_without_launch_permission_cannot_create_a_project(): void
    {
        $user = User::factory()->create(['can_trigger_amp' => false]);

        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Denied',
            'github_repository' => 'acme/denied',
            'amp_project_id' => 'amp-denied',
        ])->assertForbidden();

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_historical_run_ownership_does_not_restore_revoked_launch_permission(): void
    {
        $project = Project::factory()->for($this->owner)->configured()->create([
            'github_repository' => 'acme/historical',
        ]);
        $definition = WorkflowDefinition::query()->where('version', 3)->sole();
        app(WorkflowEngine::class)->start(
            $this->owner,
            $definition,
            $project,
            1,
            'https://github.com/acme/historical/issues/1',
        );
        $this->owner->update(['can_trigger_amp' => false]);

        $this->actingAs($this->owner)->from(route('projects.show', $project))->post(route('projects.workflows.store', $project), [
            'workflow_definition_id' => $definition->id,
            'github_issue_number' => 2,
            'github_issue_url' => 'https://github.com/acme/historical/issues/2',
        ])->assertRedirect(route('projects.show', $project))
            ->assertSessionHasErrors('workflow');

        $this->assertDatabaseCount('workflow_runs', 1);
        $this->assertDatabaseCount('amp_launches', 1);
        Queue::assertPushed(DeliverAmpLaunch::class, 1);
    }

    public function test_two_projects_run_concurrently_on_their_own_snapshotted_connections(): void
    {
        $first = Project::factory()->for($this->owner)->configured()->create([
            'github_repository' => 'acme/first',
            'amp_project_id' => 'amp-first',
        ]);
        $second = Project::factory()->for($this->owner)->configured()->create([
            'github_repository' => 'acme/second',
            'amp_project_id' => 'amp-second',
        ]);
        $definition = WorkflowDefinition::query()->where('version', 3)->sole();
        $engine = app(WorkflowEngine::class);

        $runOne = $engine->start($this->owner, $definition, $first, 11, 'https://github.com/acme/first/issues/11');
        $runTwo = $engine->start($this->owner, $definition, $second, 12, 'https://github.com/acme/second/issues/12');

        $this->assertNotSame($runOne->project_id, $runTwo->project_id);
        $this->assertNotSame($runOne->amp_project_connection_id, $runTwo->amp_project_connection_id);
        $this->assertSame('amp-first', $runOne->activeStageRun->ampLaunch->ampProjectConnection->amp_project_id);
        $this->assertSame('amp-second', $runTwo->activeStageRun->ampLaunch->ampProjectConnection->amp_project_id);
        Queue::assertPushed(DeliverAmpLaunch::class, 2);
    }

    public function test_connection_edit_does_not_move_existing_run_and_new_run_uses_new_version(): void
    {
        $project = Project::factory()->for($this->owner)->configured()->create(['github_repository' => 'acme/widgets']);
        $engine = app(WorkflowEngine::class);
        $definition = WorkflowDefinition::query()->where('version', 3)->sole();
        $firstConnection = $project->currentConnection;
        $oldRun = $engine->start($this->owner, $definition, $project, 1, 'https://github.com/acme/widgets/issues/1');

        $newConnection = app(AmpProjectConnectionService::class)->configure($project, $this->owner, [
            'amp_project_id' => 'amp-project-reconfigured',
            'launch_webhook_url' => 'https://hooks.ampcode.com/project-two',
            'launch_signing_secret' => str_repeat('a', 40),
            'callback_signing_secret' => str_repeat('b', 40),
        ]);
        $newConnection->update(['status' => 'verified', 'verified_at' => now()]);
        $newRun = $engine->start($this->owner, $definition, $project->fresh(), 2, 'https://github.com/acme/widgets/issues/2');

        $this->assertSame($firstConnection->id, $oldRun->amp_project_connection_id);
        $this->assertSame($firstConnection->id, $oldRun->activeStageRun->ampLaunch->amp_project_connection_id);
        $this->assertSame($newConnection->id, $newRun->amp_project_connection_id);
        $this->assertSame(2, $newConnection->version);
    }

    public function test_connection_configuration_is_ssrf_guarded_and_secrets_are_encrypted(): void
    {
        $project = Project::factory()->for($this->owner)->create();
        $service = app(AmpProjectConnectionService::class);

        foreach (['http://ampcode.com/hook', 'https://127.0.0.1/hook', 'https://ampcode.com:444/hook', 'https://ampcode.com/hook?secret=x'] as $url) {
            try {
                $service->configure($project, $this->owner, [
                    'amp_project_id' => $project->amp_project_id,
                    'launch_webhook_url' => $url,
                    'launch_signing_secret' => str_repeat('l', 40),
                    'callback_signing_secret' => str_repeat('c', 40),
                ]);
                $this->fail('Unsafe controller URL was accepted: '.$url);
            } catch (WorkflowConflict) {
                $this->assertTrue(true);
            }
        }

        $connection = $service->configure($project, $this->owner, [
            'amp_project_id' => $project->amp_project_id,
            'launch_webhook_url' => 'https://hooks.ampcode.com/controller',
            'launch_signing_secret' => str_repeat('l', 40),
            'callback_signing_secret' => str_repeat('c', 40),
        ]);
        $raw = DB::table('amp_project_connections')->where('id', $connection->id)->first();
        $this->assertNotSame('https://hooks.ampcode.com/controller', $raw->launch_webhook_url);
        $this->assertNotSame(str_repeat('l', 40), $raw->launch_signing_secret);
        $this->assertNotSame(str_repeat('c', 40), $raw->callback_signing_secret);
    }

    public function test_email_change_cannot_acquire_immutable_launch_permission(): void
    {
        config(['services.amp.legacy_operator_emails' => ['operator@example.com']]);
        $user = User::factory()->create(['email' => 'someone@example.com', 'can_trigger_amp' => false]);

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => 'operator@example.com',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->can_trigger_amp);
    }

    public function test_verification_is_claimed_once_binds_child_and_idempotently_accepts_completion(): void
    {
        $project = Project::factory()->for($this->owner)->create([
            'github_repository' => 'acme/widgets',
            'amp_project_id' => 'amp-project-one',
        ]);
        $service = app(AmpProjectConnectionService::class);
        $connection = $service->configure($project, $this->owner, [
            'amp_project_id' => 'amp-project-one',
            'launch_webhook_url' => 'https://hooks.ampcode.com/controller',
            'launch_signing_secret' => str_repeat('l', 40),
            'callback_signing_secret' => str_repeat('c', 40),
        ]);
        $connection = $service->beginVerification($project->fresh(), $connection, $this->owner);
        Queue::assertPushed(VerifyAmpProjectConnection::class, 1);
        $token = $connection->verification_secret;
        $thread = 'T-'.str_repeat('1', 36);
        $base = [
            'amp_project_id' => 'amp-project-one',
            'github_repository' => 'acme/widgets',
            'controller_thread_id' => 'T-controller-owner',
        ];

        $claim = $service->handleVerification($token, [...$base, 'action' => 'claim']);
        $duplicateClaim = $service->handleVerification($token, [...$base, 'action' => 'claim']);
        $service->handleVerification($token, [...$base, 'action' => 'started', 'thread_id' => $thread]);
        $completed = $service->handleVerification($token, [...$base, 'action' => 'complete', 'thread_id' => $thread, 'native_github_access' => true]);
        $duplicateComplete = $service->handleVerification($token, [...$base, 'action' => 'complete', 'thread_id' => $thread, 'native_github_access' => true]);

        $this->assertTrue($claim['launch']);
        $this->assertFalse($duplicateClaim['launch']);
        $this->assertSame('verified', $completed['disposition']);
        $this->assertSame('already_verified', $duplicateComplete['disposition']);
        $this->assertTrue($connection->fresh()->isReady());
        $this->assertSame('T-controller-owner', $connection->fresh()->controller_thread_id);
        $this->assertNotNull($connection->fresh()->controller_last_acknowledged_at);
    }

    public function test_claimed_verification_failure_is_recorded_and_idempotent_without_retrying_an_orb(): void
    {
        $project = Project::factory()->for($this->owner)->create([
            'github_repository' => 'acme/widgets',
            'amp_project_id' => 'amp-project-one',
        ]);
        $service = app(AmpProjectConnectionService::class);
        $connection = $service->configure($project, $this->owner, [
            'amp_project_id' => 'amp-project-one',
            'launch_webhook_url' => 'https://hooks.ampcode.com/controller',
            'launch_signing_secret' => str_repeat('l', 40),
            'callback_signing_secret' => str_repeat('c', 40),
        ]);
        $connection = $service->beginVerification($project->fresh(), $connection, $this->owner);
        $token = $connection->verification_secret;
        $base = [
            'amp_project_id' => 'amp-project-one',
            'github_repository' => 'acme/widgets',
            'controller_thread_id' => 'T-controller-owner',
            'failure_code' => 'controller_thread_failed',
        ];

        $service->handleVerification($token, [...$base, 'action' => 'claim']);
        $failed = $service->handleVerification($token, [...$base, 'action' => 'failed']);
        $duplicate = $service->handleVerification($token, [...$base, 'action' => 'failed']);

        $this->assertSame('failed', $failed['disposition']);
        $this->assertSame('already_failed', $duplicate['disposition']);
        $this->assertSame('failed', $connection->fresh()->status);
        $this->assertSame('controller_thread_failed', $connection->fresh()->last_error_code);
        $this->assertNull($connection->fresh()->verification_thread_id);
        Queue::assertPushed(VerifyAmpProjectConnection::class, 1);
    }

    public function test_signed_callback_for_one_connection_is_rejected_by_another(): void
    {
        $first = Project::factory()->for($this->owner)->configured()->create(['github_repository' => 'acme/first']);
        $second = Project::factory()->for($this->owner)->configured()->create(['github_repository' => 'acme/second']);
        $second->currentConnection->update(['callback_signing_secret' => str_repeat('x', 40)]);
        $run = app(WorkflowEngine::class)->start(
            $this->owner,
            WorkflowDefinition::query()->where('version', 3)->sole(),
            $first,
            1,
            'https://github.com/acme/first/issues/1',
        );
        $launch = $run->activeStageRun->ampLaunch;
        $payload = [
            'schema_version' => 1,
            'event_id' => (string) Str::uuid(),
            'type' => 'launch.claim',
            'occurred_at' => now()->toISOString(),
            'idempotency_key' => $launch->idempotency_key,
            'launch_event_id' => $launch->event_id,
            'stage_run_id' => $launch->stage_run_id,
            'connection_id' => $first->currentConnection->public_id,
            'amp_project_id' => $first->amp_project_id,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->timestamp;
        $signature = app(AmpSignature::class)->sign($body, $payload['event_id'], $timestamp, $first->currentConnection->callback_signing_secret);

        $this->call('POST', '/api/integrations/amp/connections/'.$second->currentConnection->public_id.'/callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ORC_EVENT_ID' => $payload['event_id'],
            'HTTP_X_ORC_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_ORC_SIGNATURE' => $signature,
        ], $body)->assertUnauthorized();
    }

    public function test_validly_signed_connection_cannot_claim_another_connections_launch(): void
    {
        $first = Project::factory()->for($this->owner)->configured()->create(['github_repository' => 'acme/first']);
        $second = Project::factory()->for($this->owner)->configured()->create(['github_repository' => 'acme/second']);
        $run = app(WorkflowEngine::class)->start(
            $this->owner,
            WorkflowDefinition::query()->where('version', 3)->sole(),
            $first,
            1,
            'https://github.com/acme/first/issues/1',
        );
        $launch = $run->activeStageRun->ampLaunch;
        $payload = [
            'schema_version' => 1,
            'event_id' => (string) Str::uuid(),
            'type' => 'launch.claim',
            'occurred_at' => now()->toISOString(),
            'idempotency_key' => $launch->idempotency_key,
            'launch_event_id' => $launch->event_id,
            'stage_run_id' => $launch->stage_run_id,
            'connection_id' => $second->currentConnection->public_id,
            'amp_project_id' => $second->amp_project_id,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->timestamp;
        $signature = app(AmpSignature::class)->sign($body, $payload['event_id'], $timestamp, $second->currentConnection->callback_signing_secret);

        $this->call('POST', '/api/integrations/amp/connections/'.$second->currentConnection->public_id.'/callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ORC_EVENT_ID' => $payload['event_id'],
            'HTTP_X_ORC_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_ORC_SIGNATURE' => $signature,
        ], $body)->assertConflict();

        $this->assertSame('pending', $launch->fresh()->launch_status->value);
    }
}
