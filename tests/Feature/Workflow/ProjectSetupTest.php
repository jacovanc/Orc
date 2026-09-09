<?php

namespace Tests\Feature\Workflow;

use App\Jobs\VerifyAmpProjectConnection;
use App\Models\Project;
use App\Models\User;
use App\Services\AmpConnectionUrlGuard;
use App\Services\AmpProjectConnectionService;
use App\Services\AmpSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['can_trigger_amp' => true]);
        config([
            'services.amp.enabled' => true,
            'services.amp.webhook_allowed_hosts' => ['hooks.ampcode.com'],
        ]);
        Queue::fake();
    }

    public function test_setup_runbook_is_publicly_available_from_the_orc_domain(): void
    {
        $this->get(route('docs.project-setup-v1'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSeeText('Orc Project setup protocol v1')
            ->assertSeeText('GitHub access invariant');
    }

    public function test_versioned_worker_is_a_public_immutable_three_tool_artifact(): void
    {
        $source = (string) file_get_contents(resource_path('amp/orc-worker-v1.ts'));
        $this->assertSame(3, substr_count($source, 'amp.registerTool({'));
        $this->assertStringNotContainsString('GH_TOKEN', $source);
        $this->assertStringNotContainsString('GITHUB_TOKEN', $source);
        $this->assertStringNotContainsString('ORC_GITHUB_TOKEN', $source);

        $this->get(route('integrations.amp.worker-plugin-v1'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
            ->assertHeader('ETag', '"'.hash('sha256', $source).'"')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('orc_setup_project', false)
            ->assertSee('workflow_verify_project_connection', false)
            ->assertSee('workflow_complete', false);
    }

    public function test_project_creation_preallocates_connection_and_copyable_setup_prompt(): void
    {
        $response = $this->actingAs($this->owner)->post(route('projects.store'), [
            'name' => 'Widgets',
            'github_repository' => 'Acme/Widgets',
        ]);

        $project = Project::query()->with('currentConnection.setups')->sole();
        $setup = $project->currentConnection->setups->sole();
        $response->assertRedirect(route('projects.settings', $project));
        $this->assertSame('acme/widgets', $project->github_repository);
        $this->assertNull($project->amp_project_id);
        $this->assertNull($project->currentConnection->amp_project_id);
        $this->assertSame('setup_pending', $project->currentConnection->status);
        $this->assertSame(
            'orc-stage-launch-v11-'.$project->currentConnection->public_id,
            $project->currentConnection->controller_key,
        );
        $this->assertNotNull($project->current_amp_project_connection_id);
        $raw = DB::table('amp_connection_setups')->where('id', $setup->id)->first();
        $this->assertNotSame($setup->token, $raw->token);

        $this->actingAs($this->owner)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('paste Orc’s setup prompt into the Amp project you want to link')
            ->assertDontSee('Amp project ID');

        $this->actingAs($this->owner)
            ->get(route('projects.settings', $project))
            ->assertOk()
            ->assertSee('Copy setup prompt')
            ->assertSee('orc_setup_project')
            ->assertSee('self-bootstrapping')
            ->assertSee('Personal User Plugins repository')
            ->assertSee('reload_plugins')
            ->assertSee('Only if no supported reload tool is available')
            ->assertSee(route('integrations.amp.worker-plugin-v1'), false)
            ->assertSee(hash('sha256', (string) file_get_contents(resource_path('amp/orc-worker-v1.ts'))))
            ->assertSee($setup->public_id)
            ->assertSee(route('docs.project-setup-v1'))
            ->assertDontSee('github.com/jacovanc/Orc/blob', false)
            ->assertDontSee('already-installed', false)
            ->assertDontSee('must already be active', false)
            ->assertSee('no ID, webhook URL, or signing secret needs to be copied by hand')
            ->assertSee('dedicated owner of the connection webhook')
            ->assertSee('Ordinary idle Orb sleep is expected')
            ->assertDontSee('launch_signing_secret', false)
            ->assertDontSee('github_feedback_confirmed', false);
    }

    public function test_first_setup_claim_binds_actual_amp_project_identity_atomically(): void
    {
        $project = Project::factory()->for($this->owner)->create(['amp_project_id' => null]);
        ['connection' => $connection, 'setup' => $setup] = app(AmpProjectConnectionService::class)
            ->issueSetup($project, $this->owner);

        $this->withToken($setup->token)->postJson('/api/integrations/amp/project-setup', [
            'schema_version' => 1,
            'action' => 'claim',
            'setup_id' => $setup->public_id,
            'thread_id' => 'T-'.str_repeat('9', 36),
            'amp_project_id' => 'actual-amp-project',
        ])->assertOk()
            ->assertJsonPath('amp_project_id', 'actual-amp-project');

        $this->assertSame('actual-amp-project', $project->fresh()->amp_project_id);
        $this->assertSame('actual-amp-project', $connection->fresh()->amp_project_id);

        $this->withToken($setup->token)->postJson('/api/integrations/amp/project-setup', [
            'schema_version' => 1,
            'action' => 'claim',
            'setup_id' => $setup->public_id,
            'thread_id' => 'T-'.str_repeat('9', 36),
            'amp_project_id' => 'different-amp-project',
        ])->assertConflict();
        $this->assertSame('actual-amp-project', $project->fresh()->amp_project_id);
    }

    public function test_setup_claim_is_project_and_thread_bound_and_completion_queues_verification_once(): void
    {
        ['project' => $project, 'setup' => $setup] = $this->pendingSetup();
        $thread = 'T-'.str_repeat('1', 36);
        $claimPayload = [
            'schema_version' => 1,
            'action' => 'claim',
            'setup_id' => $setup->public_id,
            'thread_id' => $thread,
            'amp_project_id' => 'amp-project-one',
        ];

        $claim = $this->withToken($setup->token)
            ->postJson('/api/integrations/amp/project-setup', $claimPayload)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('connection_id', $project->currentConnection->public_id)
            ->assertJsonPath('amp_project_id', 'amp-project-one');
        $this->assertStringNotContainsString($setup->token, $claim->getContent());
        $sourceHash = $claim->json('controller_source_sha256');
        $this->assertSame(hash('sha256', $claim->json('controller_source')), $sourceHash);

        $this->withToken($setup->token)
            ->postJson('/api/integrations/amp/project-setup', $claimPayload)
            ->assertOk()
            ->assertJsonPath('disposition', 'claimed');

        $this->withToken($setup->token)->postJson('/api/integrations/amp/project-setup', [
            ...$claimPayload,
            'thread_id' => 'T-'.str_repeat('2', 36),
        ])->assertConflict();

        $completePayload = [
            ...$claimPayload,
            'action' => 'complete',
            'launch_webhook_url' => 'https://hooks.ampcode.com/project-controller',
            'controller_source_sha256' => $sourceHash,
        ];
        $this->withToken($setup->token)
            ->postJson('/api/integrations/amp/project-setup', $completePayload)
            ->assertOk()
            ->assertJsonPath('disposition', 'completed')
            ->assertJsonPath('verification_status', 'verifying');
        $this->withToken($setup->token)
            ->postJson('/api/integrations/amp/project-setup', $completePayload)
            ->assertOk()
            ->assertJsonPath('disposition', 'already_completed');

        $this->assertSame('completed', $setup->fresh()->status);
        $this->assertSame('verifying', $project->currentConnection->fresh()->status);
        Queue::assertPushed(VerifyAmpProjectConnection::class, 1);

        $this->withToken($project->currentConnection->fresh()->verification_secret)
            ->postJson('/api/integrations/amp/connection-verification', [
                'schema_version' => 1,
                'event_id' => (string) Str::uuid(),
                'action' => 'claim',
                'controller_thread_id' => $thread,
                'amp_project_id' => 'amp-project-one',
                'github_repository' => 'acme/widgets',
            ])->assertOk()->assertJsonPath('disposition', 'claimed');
        $this->assertSame($thread, $project->currentConnection->fresh()->controller_thread_id);
        $this->assertNotNull($project->currentConnection->fresh()->controller_last_acknowledged_at);
    }

    public function test_setup_verification_rejects_an_unresolved_or_different_webhook_owner(): void
    {
        ['project' => $project, 'setup' => $setup] = $this->pendingSetup();
        $setupThread = 'T-'.str_repeat('5', 36);
        $claim = $this->withToken($setup->token)->postJson('/api/integrations/amp/project-setup', [
            'schema_version' => 1,
            'action' => 'claim',
            'setup_id' => $setup->public_id,
            'thread_id' => $setupThread,
            'amp_project_id' => 'amp-project-one',
        ])->assertOk();
        $this->withToken($setup->token)->postJson('/api/integrations/amp/project-setup', [
            'schema_version' => 1,
            'action' => 'complete',
            'setup_id' => $setup->public_id,
            'thread_id' => $setupThread,
            'amp_project_id' => 'amp-project-one',
            'launch_webhook_url' => 'https://hooks.ampcode.com/project-controller-owner-test',
            'controller_source_sha256' => $claim->json('controller_source_sha256'),
        ])->assertOk();
        $connection = $project->currentConnection->fresh();

        $this->withToken($connection->verification_secret)
            ->postJson('/api/integrations/amp/connection-verification', [
                'schema_version' => 1,
                'event_id' => (string) Str::uuid(),
                'action' => 'claim',
                'amp_project_id' => 'amp-project-one',
                'github_repository' => 'acme/widgets',
            ])->assertUnprocessable();
        $this->assertNull($connection->fresh()->controller_thread_id);

        $actualOwner = 'T-'.str_repeat('6', 36);
        $this->withToken($connection->verification_secret)
            ->postJson('/api/integrations/amp/connection-verification', [
                'schema_version' => 1,
                'event_id' => (string) Str::uuid(),
                'action' => 'claim',
                'controller_thread_id' => $actualOwner,
                'amp_project_id' => 'amp-project-one',
                'github_repository' => 'acme/widgets',
            ])->assertStatus(202)->assertJsonPath('disposition', 'controller_owner_mismatch');

        $connection->refresh();
        $this->assertSame('failed', $connection->status);
        $this->assertSame('controller_owner_mismatch', $connection->last_error_code);
        $this->assertSame($actualOwner, $connection->controller_thread_id);
        $this->assertNull($connection->verification_claimed_at);
    }

    public function test_completed_controller_securely_refreshes_a_rotated_webhook_idempotently(): void
    {
        ['project' => $project, 'connection' => $connection, 'setup' => $setup] = $this->pendingSetup();
        $setup->update(['status' => 'completed', 'completed_at' => now()]);
        $connection->update([
            'launch_webhook_url' => 'https://hooks.ampcode.com/old-capability',
            'status' => 'verified',
            'verified_at' => now(),
        ]);
        $eventId = (string) Str::uuid();
        $payload = [
            'schema_version' => 1,
            'event_id' => $eventId,
            'type' => 'controller.webhook_refreshed',
            'occurred_at' => now()->toISOString(),
            'connection_id' => $connection->public_id,
            'amp_project_id' => $connection->amp_project_id,
            'launch_webhook_url' => 'https://hooks.ampcode.com/rotated-capability',
        ];

        $this->postSignedConnectionJson($connection, 'webhook', $payload)
            ->assertOk()
            ->assertExactJson(['accepted' => true, 'disposition' => 'updated']);
        $this->postSignedConnectionJson($connection, 'webhook', $payload)
            ->assertOk()
            ->assertExactJson(['accepted' => true, 'disposition' => 'updated']);

        $this->assertSame('https://hooks.ampcode.com/rotated-capability', $connection->fresh()->launch_webhook_url);
        $this->assertSame('verified', $connection->fresh()->status);
        $this->assertDatabaseCount('amp_integration_events', 1);
        $this->assertDatabaseHas('amp_integration_events', [
            'event_id' => $eventId,
            'event_type' => 'controller.webhook_refreshed',
            'stage_run_id' => null,
            'status' => 'processed',
        ]);
        $raw = DB::table('amp_project_connections')->where('id', $connection->id)->sole();
        $this->assertNotSame('https://hooks.ampcode.com/rotated-capability', $raw->launch_webhook_url);

        $this->postSignedConnectionJson($connection, 'webhook', [
            ...$payload,
            'launch_webhook_url' => 'https://hooks.ampcode.com/reused-event',
        ])->assertConflict();
        $this->assertSame('https://hooks.ampcode.com/rotated-capability', $connection->fresh()->launch_webhook_url);
    }

    public function test_webhook_refresh_rejects_incomplete_setup_wrong_identity_and_revoked_operator(): void
    {
        ['connection' => $connection, 'setup' => $setup] = $this->pendingSetup();
        $connection->update(['launch_webhook_url' => 'https://hooks.ampcode.com/original']);
        $payload = [
            'schema_version' => 1,
            'event_id' => (string) Str::uuid(),
            'type' => 'controller.webhook_refreshed',
            'occurred_at' => now()->toISOString(),
            'connection_id' => $connection->public_id,
            'amp_project_id' => $connection->amp_project_id,
            'launch_webhook_url' => 'https://hooks.ampcode.com/replacement',
        ];

        $this->postSignedConnectionJson($connection, 'webhook', $payload)->assertConflict();
        $setup->update(['status' => 'completed', 'completed_at' => now()]);
        $this->postSignedConnectionJson($connection, 'webhook', [
            ...$payload,
            'event_id' => (string) Str::uuid(),
            'amp_project_id' => 'another-project',
        ])->assertConflict();
        $this->owner->update(['can_trigger_amp' => false]);
        $this->postSignedConnectionJson($connection, 'webhook', [
            ...$payload,
            'event_id' => (string) Str::uuid(),
        ])->assertConflict();

        $this->assertSame('https://hooks.ampcode.com/original', $connection->fresh()->launch_webhook_url);
        $this->assertDatabaseCount('amp_integration_events', 0);
    }

    public function test_verification_202_waits_for_owner_ack_and_404_is_unknown_infrastructure_failure(): void
    {
        ['project' => $project, 'connection' => $connection] = $this->pendingSetup();
        $connection->update(['launch_webhook_url' => 'https://hooks.ampcode.com/controller-lifecycle']);
        $connection = app(AmpProjectConnectionService::class)->beginVerification(
            $project,
            $connection,
            $this->owner,
        );

        Http::fakeSequence()->push('', 202)->push('', 404);
        (new VerifyAmpProjectConnection($connection->id))->handle(
            app(AmpSignature::class),
            app(AmpConnectionUrlGuard::class),
            app(AmpProjectConnectionService::class),
        );
        $connection->refresh();
        $this->assertSame('verifying', $connection->status);
        $this->assertNull($connection->controller_thread_id);
        $this->assertNull($connection->verification_thread_id);

        (new VerifyAmpProjectConnection($connection->id))->handle(
            app(AmpSignature::class),
            app(AmpConnectionUrlGuard::class),
            app(AmpProjectConnectionService::class),
        );
        $connection->refresh();
        $this->assertSame('failed', $connection->status);
        $this->assertSame('webhook_unavailable', $connection->last_error_code);
        $this->assertStringContainsString('cause is unknown', $connection->last_error_message);
    }

    public function test_wrong_project_expiry_replay_and_reissue_fail_closed(): void
    {
        ['project' => $project, 'setup' => $setup] = $this->pendingSetup();
        $payload = [
            'schema_version' => 1,
            'action' => 'claim',
            'setup_id' => $setup->public_id,
            'thread_id' => 'T-'.str_repeat('3', 36),
            'amp_project_id' => 'wrong-project',
        ];
        $this->withToken($setup->token)->postJson('/api/integrations/amp/project-setup', $payload)->assertConflict();
        $this->assertSame('pending', $setup->fresh()->status);

        $setup->update(['expires_at' => now()->subSecond()]);
        $this->withToken($setup->token)->postJson('/api/integrations/amp/project-setup', [
            ...$payload,
            'amp_project_id' => 'amp-project-one',
        ])->assertConflict();
        $this->assertSame('expired', $setup->fresh()->displayStatus());

        $service = app(AmpProjectConnectionService::class);
        $new = $service->issueSetup($project->fresh(), $this->owner);
        $this->assertNotSame($project->current_amp_project_connection_id, $new['connection']->id);
        $this->assertSame(2, $new['connection']->version);
        $this->assertNotSame($project->currentConnection->controller_key, $new['connection']->controller_key);
        $this->assertSame(
            'orc-stage-launch-v11-'.$new['connection']->public_id,
            $new['connection']->controller_key,
        );
        $this->assertSame('pending', $new['setup']->status);
        $this->assertSame('revoked', $setup->fresh()->status);
        $this->assertNull($setup->fresh()->token);
        $this->assertNotNull($setup->fresh()->revoked_at);
        $this->withToken($setup->token)->postJson('/api/integrations/amp/project-setup', [
            ...$payload,
            'amp_project_id' => 'amp-project-one',
        ])->assertConflict();

        $other = User::factory()->create(['can_trigger_amp' => true]);
        $this->actingAs($other)
            ->post(route('projects.connections.setup', $project))
            ->assertNotFound();
        $this->assertDatabaseCount('amp_project_connections', 2);
    }

    public function test_revoked_operator_cannot_finish_a_claimed_setup(): void
    {
        ['project' => $project, 'setup' => $setup] = $this->pendingSetup();
        $thread = 'T-'.str_repeat('4', 36);
        $payload = [
            'schema_version' => 1,
            'action' => 'claim',
            'setup_id' => $setup->public_id,
            'thread_id' => $thread,
            'amp_project_id' => 'amp-project-one',
        ];
        $claim = $this->withToken($setup->token)->postJson('/api/integrations/amp/project-setup', $payload)->assertOk();
        $this->owner->update(['can_trigger_amp' => false]);

        $this->withToken($setup->token)->postJson('/api/integrations/amp/project-setup', [
            ...$payload,
            'action' => 'complete',
            'launch_webhook_url' => 'https://hooks.ampcode.com/project-controller',
            'controller_source_sha256' => $claim->json('controller_source_sha256'),
        ])->assertConflict();

        $this->assertSame('claimed', $setup->fresh()->status);
        $this->assertSame('setup_pending', $project->currentConnection->fresh()->status);
        Queue::assertNotPushed(VerifyAmpProjectConnection::class);
    }

    public function test_revoked_operator_cannot_claim_or_bind_an_unclaimed_setup(): void
    {
        $project = Project::factory()->for($this->owner)->create(['amp_project_id' => null]);
        ['connection' => $connection, 'setup' => $setup] = app(AmpProjectConnectionService::class)
            ->issueSetup($project, $this->owner);
        $this->owner->update(['can_trigger_amp' => false]);

        $this->withToken($setup->token)->postJson('/api/integrations/amp/project-setup', [
            'schema_version' => 1,
            'action' => 'claim',
            'setup_id' => $setup->public_id,
            'thread_id' => 'T-'.str_repeat('8', 36),
            'amp_project_id' => 'must-not-bind',
        ])->assertConflict();

        $this->assertNull($project->fresh()->amp_project_id);
        $this->assertNull($connection->fresh()->amp_project_id);
        $this->assertSame('pending', $setup->fresh()->status);
    }

    public function test_settings_truthfully_render_claimed_expired_failed_and_verified_states(): void
    {
        ['project' => $project, 'setup' => $setup] = $this->pendingSetup();
        $setup->update(['status' => 'claimed', 'claimed_at' => now()]);
        $this->actingAs($this->owner)->get(route('projects.settings', $project))
            ->assertOk()->assertSee('setup claimed')->assertSee('plugins: reload');

        $setup->update(['status' => 'pending', 'expires_at' => now()->subSecond()]);
        $this->actingAs($this->owner)->get(route('projects.settings', $project))
            ->assertOk()->assertSee('setup expired')->assertSee('Generate new setup prompt');

        $project->currentConnection->update(['status' => 'failed', 'last_error_message' => 'Harmless verification failed.']);
        $this->actingAs($this->owner)->get(route('projects.settings', $project))
            ->assertOk()->assertSee('failed')->assertSee('Harmless verification failed.');

        $project->currentConnection->update(['status' => 'verified', 'verified_at' => now(), 'last_error_message' => null]);
        $project->currentConnection->update([
            'controller_thread_id' => 'T-controller-owner',
            'controller_last_acknowledged_at' => now(),
        ]);
        $this->actingAs($this->owner)->get(route('projects.settings', $project))
            ->assertOk()
            ->assertSee('verified')
            ->assertSee('Generate new setup prompt')
            ->assertSee('Dedicated controller thread')
            ->assertSee('https://ampcode.com/threads/T-controller-owner', false)
            ->assertSee('Normal idle Orb sleep is safe')
            ->assertSee('cause is not knowable from HTTP 404 alone');
    }

    private function pendingSetup(): array
    {
        $project = Project::factory()->for($this->owner)->create([
            'github_repository' => 'acme/widgets',
            'amp_project_id' => 'amp-project-one',
        ]);
        $issued = app(AmpProjectConnectionService::class)->issueSetup($project, $this->owner);

        return ['project' => $project->fresh('currentConnection'), ...$issued];
    }

    private function postSignedConnectionJson($connection, string $path, array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->timestamp;
        $signature = app(AmpSignature::class)->sign(
            $body,
            $payload['event_id'],
            $timestamp,
            $connection->callback_signing_secret,
        );

        return $this->call(
            'POST',
            '/api/integrations/amp/connections/'.$connection->public_id.'/'.$path,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_ORC_EVENT_ID' => $payload['event_id'],
                'HTTP_X_ORC_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_ORC_SIGNATURE' => $signature,
            ],
            $body,
        );
    }
}
