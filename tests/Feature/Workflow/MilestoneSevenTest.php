<?php

namespace Tests\Feature\Workflow;

use App\Domain\Workflow\AmpLaunchStatus;
use App\Domain\Workflow\StageRunStatus;
use App\Models\AmpLaunch;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\AmpLaunchPayload;
use App\Services\WorkflowEngine;
use Database\Seeders\DevelopmentWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MilestoneSevenTest extends TestCase
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
        $this->user->update(['can_trigger_amp' => true]);
        config([
            'services.amp.enabled' => true,
        ]);
        Queue::fake();
    }

    public function test_version_three_adds_real_qa_without_mutating_prior_definitions(): void
    {
        $definitions = WorkflowDefinition::query()
            ->with(['stages', 'transitions'])
            ->orderBy('version')
            ->get();

        $this->assertSame([1, 2, 3, 4, 5], $definitions->pluck('version')->all());
        $this->assertSame(['development', 'qa', 'human_review', 'done'], $definitions[0]->stages->pluck('key')->all());
        $this->assertSame(
            ['development', 'qa_proof', 'development_blocked', 'human_review', 'done'],
            $definitions[1]->stages->pluck('key')->all(),
        );

        $versionThree = $definitions[2];
        $this->assertSame(
            ['development', 'qa', 'development_blocked', 'qa_blocked', 'human_review', 'done'],
            $versionThree->stages->pluck('key')->all(),
        );
        $this->assertSame('real_qa', $versionThree->stages->firstWhere('key', 'qa')->config['agent_mode']);
        $this->assertTrue($versionThree->stages->firstWhere('key', 'development')->config['reuse_prior_publication']);
        $this->assertEqualsCanonicalizing(
            [
                'development:success',
                'development:blocked',
                'qa:pass',
                'qa:fail',
                'qa:blocked',
                'development_blocked:retry',
                'qa_blocked:retry',
                'human_review:request_changes',
                'human_review:approve',
            ],
            $versionThree->transitions->map(
                fn ($transition) => $versionThree->stages->firstWhere('id', $transition->from_stage_id)->key.':'.$transition->outcome
            )->all(),
        );
    }

    public function test_development_success_launches_real_qa_bound_to_exact_pull_request(): void
    {
        $run = $this->completeDevelopmentSuccess($this->startRun(), 1, 101);
        $qa = $run->activeStageRun;
        $payload = app(AmpLaunchPayload::class)->make($qa->ampLaunch);

        $this->assertSame('qa', $run->currentStage->key);
        $this->assertSame('real_qa', $payload['agent_mode']);
        $this->assertSame(['pass', 'fail', 'blocked'], $payload['allowed_outcomes']);
        $this->assertSame(17, $payload['prior_pull_request_number']);
        $this->assertSame('https://github.com/acme/widgets/pull/17', $payload['prior_pull_request_url']);
        $this->assertSame('orc/stage-'.$run->stageRuns->first()->id.'-attempt-1', $payload['prior_github_branch']);
        $this->assertNotSame($run->stageRuns->first()->ampLaunch->capability_hash, $qa->ampLaunch->capability_hash);
    }

    public function test_qa_requires_exact_pr_report_kind_and_rejects_code_publication(): void
    {
        $run = $this->completeDevelopmentSuccess($this->startRun(), 1, 111);
        $launch = $run->activeStageRun->ampLaunch;
        $thread = $this->threadId(2);
        $this->claimAndAcknowledge($launch, $thread);

        $this->postCapability($launch->capability_secret, [
            'action' => 'publication',
            'thread_id' => $thread,
            'github_branch' => 'orc/not-qa',
            'github_pull_request_number' => 17,
            'github_pull_request_url' => 'https://github.com/acme/widgets/pull/17',
        ])->assertAccepted()->assertJsonPath('accepted', false)
            ->assertJsonPath('reason', 'Only a real Development attempt can publish code.');

        foreach ([
            'https://github.com/acme/widgets/issues/42#issuecomment-201',
            'https://github.com/acme/widgets/pull/18#issuecomment-201',
        ] as $wrongUrl) {
            $this->postCapability($launch->capability_secret, [
                'action' => 'report',
                'thread_id' => $thread,
                'github_report_url' => $wrongUrl,
                'github_report_comment_id' => 201,
                'github_report_kind' => 'fail',
            ])->assertAccepted()->assertJsonPath('accepted', false);
        }

        $this->reportQa($launch, $thread, 201, 'fail');
        $this->postCapability($launch->capability_secret, [
            'action' => 'complete',
            'thread_id' => $thread,
            'outcome' => 'pass',
            'github_report_url' => 'https://github.com/acme/widgets/pull/17#issuecomment-201',
        ])->assertAccepted()->assertJsonPath('accepted', false)
            ->assertJsonPath('reason', 'The QA report kind must match its completion outcome.');

        $this->postCapability($launch->capability_secret, [
            'action' => 'complete',
            'thread_id' => $this->threadId(99),
            'outcome' => 'fail',
            'github_report_url' => 'https://github.com/acme/widgets/pull/17#issuecomment-201',
        ])->assertAccepted()->assertJsonPath('accepted', false);
    }

    public function test_qa_fail_creates_fresh_development_remediation_then_new_qa_can_pass(): void
    {
        $run = $this->completeDevelopmentSuccess($this->startRun(), 1, 301);
        $firstQa = $run->activeStageRun;
        $firstQaLaunch = $firstQa->ampLaunch;
        $qaThread = $this->threadId(2);
        $this->claimAndAcknowledge($firstQaLaunch, $qaThread);
        $this->reportQa($firstQaLaunch, $qaThread, 302, 'fail');
        $this->complete($firstQaLaunch, $qaThread, 'fail', 'https://github.com/acme/widgets/pull/17#issuecomment-302')
            ->assertOk()->assertJsonPath('current_stage', 'development');

        $run->refresh()->load(['currentStage', 'activeStageRun.ampLaunch', 'stageRuns.ampLaunch']);
        $remediation = $run->activeStageRun;
        $remediationPayload = app(AmpLaunchPayload::class)->make($remediation->ampLaunch);
        $this->assertSame(3, $remediation->attempt_number);
        $this->assertSame($run->stageRuns[0]->github_branch, $remediationPayload['expected_branch']);
        $this->assertSame(17, $remediationPayload['prior_pull_request_number']);
        $this->assertNotSame($firstQaLaunch->capability_hash, $remediation->ampLaunch->capability_hash);

        $run = $this->completeDevelopmentSuccess($run, 3, 303);
        $secondQa = $run->activeStageRun;
        $secondQaLaunch = $secondQa->ampLaunch;
        $secondQaThread = $this->threadId(4);
        $this->claimAndAcknowledge($secondQaLaunch, $secondQaThread);
        $this->reportQa($secondQaLaunch, $secondQaThread, 304, 'pass');
        $this->complete($secondQaLaunch, $secondQaThread, 'pass', 'https://github.com/acme/widgets/pull/17#issuecomment-304')
            ->assertOk()->assertJsonPath('current_stage', 'human_review');

        $run->refresh()->load(['currentStage', 'activeStageRun', 'stageRuns']);
        $this->assertSame('human_review', $run->currentStage->key);
        $this->assertSame(5, $run->activeStageRun->attempt_number);
        $this->assertSame(StageRunStatus::Waiting, $run->activeStageRun->status);
        $this->assertSame(
            [$this->threadId(1), $this->threadId(2), $this->threadId(3), $this->threadId(4)],
            $run->stageRuns->whereNotNull('amp_thread_id')->pluck('amp_thread_id')->all(),
        );
        $this->assertSame(['success', 'fail', 'success', 'pass'], $run->stageRuns->take(4)->pluck('outcome')->all());
    }

    public function test_qa_blocked_requires_operator_retry_and_allocates_fresh_qa_attempt(): void
    {
        $run = $this->completeDevelopmentSuccess($this->startRun(), 1, 401);
        $qaLaunch = $run->activeStageRun->ampLaunch;
        $thread = $this->threadId(2);
        $this->claimAndAcknowledge($qaLaunch, $thread);
        $this->reportQa($qaLaunch, $thread, 402, 'blocked');
        $this->complete($qaLaunch, $thread, 'blocked', 'https://github.com/acme/widgets/pull/17#issuecomment-402')
            ->assertOk()->assertJsonPath('current_stage', 'qa_blocked');

        $run->refresh()->load('activeStageRun');
        $blockedGate = $run->activeStageRun;
        $this->assertSame(StageRunStatus::Waiting, $blockedGate->status);
        $this->assertNull($blockedGate->ampLaunch);

        $run = $this->engine->completeHumanAction($run, $blockedGate, 'retry', $this->user);
        $run->load('activeStageRun.ampLaunch');
        $this->assertSame('qa', $run->currentStage->key);
        $this->assertSame(4, $run->activeStageRun->attempt_number);
        $this->assertSame('real_qa', $run->activeStageRun->stage->config['agent_mode']);
        $this->assertNotSame($qaLaunch->capability_hash, $run->activeStageRun->ampLaunch->capability_hash);
    }

    public function test_duplicate_and_racing_qa_completions_cannot_take_two_transitions(): void
    {
        $run = $this->completeDevelopmentSuccess($this->startRun(), 1, 501);
        $launch = $run->activeStageRun->ampLaunch;
        $thread = $this->threadId(2);
        $this->claimAndAcknowledge($launch, $thread);
        $this->reportQa($launch, $thread, 502, 'fail');

        $first = $this->complete($launch, $thread, 'fail', 'https://github.com/acme/widgets/pull/17#issuecomment-502');
        $duplicate = $this->complete($launch, $thread, 'fail', 'https://github.com/acme/widgets/pull/17#issuecomment-502');
        $race = $this->complete($launch, $thread, 'pass', 'https://github.com/acme/widgets/pull/17#issuecomment-502');

        $first->assertOk()->assertJsonPath('current_stage', 'development');
        $duplicate->assertOk()->assertJsonPath('current_stage', 'development');
        $race->assertAccepted()->assertJsonPath('accepted', false);
        $run->refresh();
        $this->assertSame(3, $run->stageRuns()->count());
        $this->assertSame(1, $run->events()->where('type', 'stage.completed')->where('stage_run_id', $launch->stage_run_id)->count());
    }

    public function test_cancelled_qa_rejects_late_report_and_completion(): void
    {
        $run = $this->completeDevelopmentSuccess($this->startRun(), 1, 601);
        $launch = $run->activeStageRun->ampLaunch;
        $thread = $this->threadId(2);
        $this->claimAndAcknowledge($launch, $thread);
        $this->engine->cancel($run, $this->user);

        $this->postCapability($launch->capability_secret, [
            'action' => 'report',
            'thread_id' => $thread,
            'github_report_url' => 'https://github.com/acme/widgets/pull/17#issuecomment-602',
            'github_report_comment_id' => 602,
            'github_report_kind' => 'pass',
        ])->assertAccepted()->assertJsonPath('accepted', false);
        $this->assertSame(AmpLaunchStatus::Failed, $launch->fresh()->launch_status);
        $this->assertSame('cancelled', $run->fresh()->status->value);
    }

    public function test_running_blocked_remediation_and_human_qa_states_render_truthfully(): void
    {
        $runningQa = $this->completeDevelopmentSuccess($this->startRun(), 1, 701);
        $this->claimAndAcknowledge($runningQa->activeStageRun->ampLaunch, $this->threadId(2));
        $runningQa->refresh()->load(['currentStage', 'activeStageRun.ampLaunch', 'stageRuns.ampLaunch']);
        $this->actingAs($this->user)->get(route('workflows.show', $runningQa))
            ->assertOk()
            ->assertSee('status-running', false)
            ->assertSee('Independent QA')
            ->assertSee('Substantive QA')
            ->assertSee('A fresh independent agent is inspecting and testing the bound pull request.')
            ->assertSee('Open PR #17')
            ->assertDontSee('Integration proof');

        $qaLaunch = $runningQa->activeStageRun->ampLaunch;
        $qaThread = $this->threadId(2);
        $this->claimAndAcknowledge($qaLaunch, $qaThread);
        $this->reportQa($qaLaunch, $qaThread, 702, 'fail');
        $this->complete($qaLaunch, $qaThread, 'fail', 'https://github.com/acme/widgets/pull/17#issuecomment-702');
        $this->actingAs($this->user)->get(route('workflows.show', $runningQa->fresh()))
            ->assertOk()
            ->assertSee('Fail')
            ->assertSee('Real Development');

        $blocked = $this->completeDevelopmentSuccess($this->startRun(), 10, 710);
        $blockedLaunch = $blocked->activeStageRun->ampLaunch;
        $blockedThread = $this->threadId(11);
        $this->claimAndAcknowledge($blockedLaunch, $blockedThread);
        $this->reportQa($blockedLaunch, $blockedThread, 711, 'blocked');
        $this->complete($blockedLaunch, $blockedThread, 'blocked', 'https://github.com/acme/widgets/pull/17#issuecomment-711');
        $this->actingAs($this->user)->get(route('workflows.show', $blocked->fresh()))
            ->assertOk()
            ->assertSee('needs attention')
            ->assertSee('status-waiting', false)
            ->assertDontSee('status-running', false)
            ->assertSee('QA Blocked Review')
            ->assertSee('Operator intervention required')
            ->assertSee('Review on GitHub')
            ->assertSee('Open pull request #17')
            ->assertSee('https://github.com/acme/widgets/pull/17', false)
            ->assertSee('Open latest agent report')
            ->assertSee('Retry Independent QA');

        $passing = $this->completeDevelopmentSuccess($this->startRun(), 20, 720);
        $passingLaunch = $passing->activeStageRun->ampLaunch;
        $passingThread = $this->threadId(21);
        $this->claimAndAcknowledge($passingLaunch, $passingThread);
        $this->reportQa($passingLaunch, $passingThread, 721, 'pass');
        $this->complete($passingLaunch, $passingThread, 'pass', 'https://github.com/acme/widgets/pull/17#issuecomment-721');
        $this->actingAs($this->user)->get(route('workflows.show', $passing->fresh()))
            ->assertOk()
            ->assertSee('Substantive QA passed')
            ->assertSee('Review on GitHub')
            ->assertSee('Open pull request #17')
            ->assertSee('data-review-evidence', false)
            ->assertSee('separate human release decision')
            ->assertDontSee('QA was an integration proof only');
    }

    private function completeDevelopmentSuccess(WorkflowRun $run, int $threadNumber, int $comment): WorkflowRun
    {
        $launch = $run->activeStageRun->ampLaunch;
        $thread = $this->threadId($threadNumber);
        $this->claimAndAcknowledge($launch, $thread);
        $this->postCapability($launch->capability_secret, [
            'action' => 'report',
            'thread_id' => $thread,
            'github_report_url' => "https://github.com/acme/widgets/issues/42#issuecomment-{$comment}",
            'github_report_comment_id' => $comment,
            'github_report_kind' => 'success',
        ])->assertOk();
        $payload = app(AmpLaunchPayload::class)->make($launch);
        $this->postCapability($launch->capability_secret, [
            'action' => 'publication',
            'thread_id' => $thread,
            'github_branch' => $payload['expected_branch'],
            'github_pull_request_number' => 17,
            'github_pull_request_url' => 'https://github.com/acme/widgets/pull/17',
        ])->assertOk();
        $this->complete(
            $launch,
            $thread,
            'success',
            "https://github.com/acme/widgets/issues/42#issuecomment-{$comment}",
        )->assertOk();

        return $run->refresh()->load(['currentStage', 'activeStageRun.ampLaunch', 'stageRuns.ampLaunch']);
    }

    private function reportQa(AmpLaunch $launch, string $thread, int $comment, string $kind): void
    {
        $this->postCapability($launch->capability_secret, [
            'action' => 'report',
            'thread_id' => $thread,
            'github_report_url' => "https://github.com/acme/widgets/pull/17#issuecomment-{$comment}",
            'github_report_comment_id' => $comment,
            'github_report_kind' => $kind,
        ])->assertOk();
    }

    private function complete(AmpLaunch $launch, string $thread, string $outcome, string $reportUrl)
    {
        return $this->postCapability($launch->capability_secret, [
            'action' => 'complete',
            'thread_id' => $thread,
            'outcome' => $outcome,
            'github_report_url' => $reportUrl,
        ]);
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
            'amp_project_id' => 'amp-project-test',
            ...$payload,
        ]);
    }

    private function startRun(): WorkflowRun
    {
        return $this->engine->start(
            $this->user,
            WorkflowDefinition::query()->where('version', 3)->sole(),
            $this->workflowProject($this->user, 'acme/widgets'),
            42,
            'https://github.com/acme/widgets/issues/42',
        );
    }

    private function threadId(int $number): string
    {
        return 'T-'.str_pad((string) $number, 36, '0', STR_PAD_LEFT);
    }
}
