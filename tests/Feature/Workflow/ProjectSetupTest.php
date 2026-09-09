<?php

namespace Tests\Feature\Workflow;

use App\Jobs\VerifyAmpProjectConnection;
use App\Models\Project;
use App\Models\User;
use App\Services\AmpProjectConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
            ->assertSee($setup->public_id)
            ->assertSee(route('docs.project-setup-v1'))
            ->assertDontSee('github.com/jacovanc/Orc/blob', false)
            ->assertSee('no ID, webhook URL, or signing secret needs to be copied by hand')
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
        $this->actingAs($this->owner)->get(route('projects.settings', $project))
            ->assertOk()->assertSee('verified')->assertSee('Generate new setup prompt');
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
}
