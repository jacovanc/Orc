<?php

namespace Tests\Feature\Workflow;

use App\Domain\Workflow\AmpLaunchStatus;
use App\Domain\Workflow\StageRunStatus;
use App\Jobs\DeliverAmpLaunch;
use App\Models\AmpLaunch;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\AmpLaunchPayload;
use App\Services\WorkflowEngine;
use Database\Seeders\DevelopmentWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MilestoneSixTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowEngine $engine;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DevelopmentWorkflowSeeder::class);
        $this->engine = app(WorkflowEngine::class);
        $this->user = User::factory()->create();
        config([
            'services.amp.enabled' => true,
            'services.amp.allowed_repositories' => ['acme/widgets'],
            'services.amp.allowed_user_emails' => [$this->user->email],
        ]);
        Queue::fake();
    }

    public function test_version_two_is_immutable_real_development_with_proof_only_qa(): void
    {
        $definitions = WorkflowDefinition::query()
            ->with(['stages', 'transitions'])
            ->orderBy('version')
            ->get();

        $this->assertSame([1, 2], $definitions->pluck('version')->all());
        $this->assertSame(['development', 'qa', 'human_review', 'done'], $definitions[0]->stages->pluck('key')->all());

        $versionTwo = $definitions[1];
        $this->assertSame(
            ['development', 'qa_proof', 'development_blocked', 'human_review', 'done'],
            $versionTwo->stages->pluck('key')->all(),
        );
        $this->assertSame('real_development', $versionTwo->stages->firstWhere('key', 'development')->config['agent_mode']);
        $this->assertSame('proof_qa', $versionTwo->stages->firstWhere('key', 'qa_proof')->config['agent_mode']);
        $this->assertEqualsCanonicalizing(
            ['development:success', 'development:blocked', 'qa_proof:proof_complete', 'development_blocked:retry', 'human_review:request_changes', 'human_review:approve'],
            $versionTwo->transitions->map(
                fn ($transition) => $versionTwo->stages->firstWhere('id', $transition->from_stage_id)->key.':'.$transition->outcome
            )->all(),
        );
    }

    public function test_launch_contains_stable_narrow_capability_and_explicit_agent_mode(): void
    {
        $run = $this->startRun();
        $launch = $run->activeStageRun->ampLaunch;
        $payload = app(AmpLaunchPayload::class)->make($launch);

        $this->assertSame('real_development', $payload['agent_mode']);
        $this->assertSame(['success', 'blocked'], $payload['allowed_outcomes']);
        $this->assertSame($launch->capability_secret, $payload['stage_capability_token']);
        $this->assertSame(hash('sha256', $launch->capability_secret), $launch->capability_hash);
        $this->assertSame("orc/stage-{$launch->stage_run_id}-attempt-1", $payload['expected_branch']);
        $this->assertNotSame(
            $launch->capability_secret,
            DB::table('amp_launches')->where('id', $launch->id)->value('capability_secret'),
        );
        $this->assertStringEndsWith('/api/integrations/amp/stage-capability', $payload['stage_capability_url']);
        Queue::assertPushed(DeliverAmpLaunch::class, 1);
    }

    public function test_capability_requires_exact_token_and_bound_thread(): void
    {
        $launch = $this->startRun()->activeStageRun->ampLaunch;
        $this->claimAndAcknowledge($launch, $this->threadId(1));

        $this->postCapability('wrong-stage-capability-that-is-long-enough', [
            'action' => 'context',
            'thread_id' => $this->threadId(1),
        ])->assertConflict()->assertJsonPath('message', 'The stage capability is invalid.');

        $this->postCapability($launch->capability_secret, [
            'action' => 'context',
            'thread_id' => $this->threadId(2),
        ])->assertConflict()->assertJsonPath('message', 'The Amp callback does not match the thread bound to this attempt.');

        $this->postCapability($launch->capability_secret, [
            'action' => 'context',
            'thread_id' => $this->threadId(1),
        ])->assertOk()
            ->assertJsonPath('agent_mode', 'real_development')
            ->assertJsonPath('is_active', true);
    }

    public function test_real_development_success_requires_bound_pr_and_report_then_enters_proof_qa(): void
    {
        $run = $this->startRun();
        $launch = $run->activeStageRun->ampLaunch;
        $thread = $this->threadId(9);
        $this->claimAndAcknowledge($launch, $thread);
        $this->report($launch, $thread, 301);

        $this->postCapability($launch->capability_secret, [
            'action' => 'complete',
            'thread_id' => $thread,
            'outcome' => 'success',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-301',
        ])->assertAccepted()->assertJsonPath('accepted', false);

        $branch = "orc/stage-{$launch->stage_run_id}-attempt-1";
        $publication = [
            'action' => 'publication',
            'thread_id' => $thread,
            'github_branch' => $branch,
            'github_pull_request_number' => 17,
            'github_pull_request_url' => 'https://github.com/acme/widgets/pull/17',
        ];
        $this->postCapability($launch->capability_secret, $publication)
            ->assertOk()
            ->assertJsonPath('disposition', 'published');
        $this->postCapability($launch->capability_secret, $publication)
            ->assertOk()
            ->assertJsonPath('disposition', 'already_published');

        $this->postCapability($launch->capability_secret, [
            'action' => 'complete',
            'thread_id' => $thread,
            'outcome' => 'success',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-301',
        ])->assertOk()->assertJsonPath('current_stage', 'qa_proof');

        $run->refresh()->load(['currentStage', 'activeStageRun.ampLaunch']);
        $this->assertSame('qa_proof', $run->currentStage->key);
        $this->assertSame('proof_qa', $run->activeStageRun->stage->config['agent_mode']);
        $this->assertNotSame($launch->capability_hash, $run->activeStageRun->ampLaunch->capability_hash);
        $this->assertSame(AmpLaunchStatus::Completed, $launch->fresh()->launch_status);
    }

    public function test_report_publication_claim_is_persistent_and_exclusive(): void
    {
        $launch = $this->startRun()->activeStageRun->ampLaunch;
        $thread = $this->threadId(7);
        $this->claimAndAcknowledge($launch, $thread);

        $first = $this->postCapability($launch->capability_secret, [
            'action' => 'report_claim',
            'thread_id' => $thread,
        ])->assertOk();
        $second = $this->postCapability($launch->capability_secret, [
            'action' => 'report_claim',
            'thread_id' => $thread,
        ])->assertAccepted();

        $first->assertJsonPath('publish', true);
        $second->assertJsonPath('accepted', false)
            ->assertJsonPath('disposition', 'publication_ambiguous');
        $this->assertNotNull($launch->fresh()->report_claimed_at);
        $this->assertSame(1, $launch->stageRun->workflowRun->events()->where('type', 'stage.report_claimed')->count());
    }

    public function test_proof_qa_cannot_report_pass_and_reaches_human_review_only_as_proof_complete(): void
    {
        $run = $this->completeDevelopmentSuccess();
        $qa = $run->activeStageRun;
        $launch = $qa->ampLaunch;
        $thread = $this->threadId(2);
        $this->claimAndAcknowledge($launch, $thread);
        $this->report($launch, $thread, 302, 'proof');

        $this->postCapability($launch->capability_secret, [
            'action' => 'complete',
            'thread_id' => $thread,
            'outcome' => 'pass',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-302',
        ])->assertAccepted()->assertJsonPath('accepted', false);

        $this->postCapability($launch->capability_secret, [
            'action' => 'complete',
            'thread_id' => $thread,
            'outcome' => 'proof_complete',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-302',
        ])->assertOk()->assertJsonPath('current_stage', 'human_review');

        $run->refresh()->load(['currentStage', 'activeStageRun']);
        $this->assertSame('human_review', $run->currentStage->key);
        $this->assertSame(StageRunStatus::Waiting, $run->activeStageRun->status);
    }

    public function test_blocked_review_retry_allocates_a_new_attempt_branch_and_capability(): void
    {
        $run = $this->startRun();
        $first = $run->activeStageRun;
        $launch = $first->ampLaunch;
        $thread = $this->threadId(1);
        $this->claimAndAcknowledge($launch, $thread);
        $this->report($launch, $thread, 401, 'blocked');
        $this->postCapability($launch->capability_secret, [
            'action' => 'complete',
            'thread_id' => $thread,
            'outcome' => 'blocked',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-401',
        ])->assertOk()->assertJsonPath('current_stage', 'development_blocked');

        $run->refresh()->load('activeStageRun');
        $run = $this->engine->completeHumanAction($run, $run->activeStageRun, 'retry', $this->user);
        $run->load('activeStageRun.ampLaunch');

        $this->assertSame('development', $run->currentStage->key);
        $this->assertSame(3, $run->activeStageRun->attempt_number);
        $this->assertSame(
            "orc/stage-{$run->activeStageRun->id}-attempt-3",
            app(AmpLaunchPayload::class)->make($run->activeStageRun->ampLaunch)['expected_branch'],
        );
        $this->assertNotSame($launch->capability_hash, $run->activeStageRun->ampLaunch->capability_hash);
    }

    public function test_cancelled_stage_rejects_its_capability_without_transition(): void
    {
        $run = $this->startRun();
        $launch = $run->activeStageRun->ampLaunch;
        $thread = $this->threadId(1);
        $this->claimAndAcknowledge($launch, $thread);
        $this->engine->cancel($run, $this->user);

        $this->postCapability($launch->capability_secret, [
            'action' => 'report',
            'thread_id' => $thread,
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-501',
            'github_report_comment_id' => 501,
        ])->assertAccepted()->assertJsonPath('accepted', false);

        $this->assertSame('cancelled', $run->fresh()->status->value);
        $this->assertNull($run->fresh()->activeStageRun);
    }

    public function test_real_and_proof_modes_and_pull_request_links_render_clearly(): void
    {
        $run = $this->startRun();
        $this->actingAs($this->user)->get(route('workflows.show', $run))
            ->assertOk()
            ->assertSee('Real Development')
            ->assertSee('normal Amp tools')
            ->assertSee('never provisions or copies GitHub credentials');

        $run = $this->completeDevelopmentSuccess();
        $this->actingAs($this->user)->get(route('workflows.show', $run))
            ->assertOk()
            ->assertSee('QA Integration Proof — not validation')
            ->assertSee('Integration proof')
            ->assertSee('not code validation or approval')
            ->assertSee('https://github.com/acme/widgets/pull/17', false)
            ->assertSee('PR #17');
    }

    public function test_blocked_retry_and_human_release_gate_render_without_qa_approval_claim(): void
    {
        $blocked = $this->startRun();
        $launch = $blocked->activeStageRun->ampLaunch;
        $thread = $this->threadId(9);
        $this->claimAndAcknowledge($launch, $thread);
        $this->report($launch, $thread, 601, 'blocked');
        $this->postCapability($launch->capability_secret, [
            'action' => 'complete',
            'thread_id' => $thread,
            'outcome' => 'blocked',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-601',
        ])->assertOk();
        $this->actingAs($this->user)->get(route('workflows.show', $blocked->fresh()))
            ->assertOk()
            ->assertSee('Development Blocked Review')
            ->assertSee('Retry Development');

        $run = $this->completeDevelopmentSuccess();
        $qaLaunch = $run->activeStageRun->ampLaunch;
        $qaThread = $this->threadId(2);
        $this->claimAndAcknowledge($qaLaunch, $qaThread);
        $this->report($qaLaunch, $qaThread, 602, 'proof');
        $this->postCapability($qaLaunch->capability_secret, [
            'action' => 'complete',
            'thread_id' => $qaThread,
            'outcome' => 'proof_complete',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-602',
        ])->assertOk();

        $this->actingAs($this->user)->get(route('workflows.show', $run->fresh()))
            ->assertOk()
            ->assertSee('Human release gate')
            ->assertSee('no independent substantive code validation has occurred yet')
            ->assertDontSee('QA approved');
    }

    private function completeDevelopmentSuccess(): WorkflowRun
    {
        $run = $this->startRun();
        $launch = $run->activeStageRun->ampLaunch;
        $thread = $this->threadId(1);
        $this->claimAndAcknowledge($launch, $thread);
        $this->report($launch, $thread, 301);
        $this->postCapability($launch->capability_secret, [
            'action' => 'publication',
            'thread_id' => $thread,
            'github_branch' => "orc/stage-{$launch->stage_run_id}-attempt-1",
            'github_pull_request_number' => 17,
            'github_pull_request_url' => 'https://github.com/acme/widgets/pull/17',
        ])->assertOk();
        $this->postCapability($launch->capability_secret, [
            'action' => 'complete',
            'thread_id' => $thread,
            'outcome' => 'success',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-301',
        ])->assertOk();

        return $run->refresh()->load('activeStageRun.ampLaunch');
    }

    private function report(AmpLaunch $launch, string $thread, int $comment, string $kind = 'success'): void
    {
        $this->postCapability($launch->capability_secret, [
            'action' => 'report',
            'thread_id' => $thread,
            'github_report_url' => "https://github.com/acme/widgets/issues/42#issuecomment-{$comment}",
            'github_report_comment_id' => $comment,
            'github_report_kind' => $kind,
        ])->assertOk();
    }

    private function claimAndAcknowledge(AmpLaunch $launch, string $thread): void
    {
        $this->ampCallback($launch, 'launch.claim');
        $this->ampCallback($launch, 'launch.acknowledged', ['thread_id' => $thread]);
    }

    private function ampCallback(AmpLaunch $launch, string $type, array $extra = []): array
    {
        $payload = [
            'schema_version' => 1,
            'event_id' => (string) Str::uuid(),
            'type' => $type,
            'occurred_at' => now()->toISOString(),
            'idempotency_key' => $launch->idempotency_key,
            'launch_event_id' => $launch->event_id,
            'stage_run_id' => $launch->stage_run_id,
            ...$extra,
        ];

        return $this->engine->handleAmpCallback(
            $payload,
            hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        );
    }

    private function postCapability(string $token, array $payload)
    {
        return $this->withToken($token)->postJson('/api/integrations/amp/stage-capability', [
            'schema_version' => 1,
            'event_id' => (string) Str::uuid(),
            'occurred_at' => now()->toISOString(),
            ...$payload,
        ]);
    }

    private function startRun(): WorkflowRun
    {
        return $this->engine->start(
            $this->user,
            WorkflowDefinition::query()->where('version', 2)->sole(),
            'acme/widgets',
            42,
            'https://github.com/acme/widgets/issues/42',
        );
    }

    private function threadId(int $number): string
    {
        return 'T-'.str_pad((string) $number, 36, '0', STR_PAD_LEFT);
    }
}
