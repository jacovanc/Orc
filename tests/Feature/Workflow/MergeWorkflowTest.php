<?php

namespace Tests\Feature\Workflow;

use App\Domain\Workflow\StageRunStatus;
use App\Domain\Workflow\WorkflowStatus;
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

class MergeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowEngine $engine;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DevelopmentWorkflowSeeder::class);
        $this->engine = app(WorkflowEngine::class);
        $this->user = User::factory()->create(['can_trigger_amp' => true]);
        config(['services.amp.enabled' => true]);
        Queue::fake();
    }

    public function test_version_four_adds_bounded_merge_graph_without_mutating_prior_versions(): void
    {
        $definitions = WorkflowDefinition::query()->with(['stages', 'transitions'])->orderBy('version')->get();

        $this->assertSame([1, 2, 3, 4], $definitions->pluck('version')->all());
        $this->assertSame(['development', 'qa', 'human_review', 'done'], $definitions[0]->stages->pluck('key')->all());
        $this->assertSame(
            ['development', 'qa', 'development_blocked', 'qa_blocked', 'human_review', 'done'],
            $definitions[2]->stages->pluck('key')->all(),
        );

        $versionFour = $definitions[3];
        $this->assertSame(
            ['development', 'qa', 'development_blocked', 'qa_blocked', 'human_review', 'merge', 'merge_blocked', 'done'],
            $versionFour->stages->pluck('key')->all(),
        );
        $this->assertSame('real_merge', $versionFour->stages->firstWhere('key', 'merge')->config['agent_mode']);
        $this->assertSame(13, $versionFour->transitions->count());
        $this->assertDatabaseHas('workflow_transitions', [
            'workflow_definition_id' => $versionFour->id,
            'from_stage_id' => $versionFour->stages->firstWhere('key', 'human_review')->id,
            'outcome' => 'approve',
            'to_stage_id' => $versionFour->stages->firstWhere('key', 'merge')->id,
        ]);
    }

    public function test_old_controller_cannot_start_or_select_version_four_but_existing_definitions_remain(): void
    {
        $project = $this->workflowProject($this->user, 'acme/widgets');
        $project->currentConnection->update(['controller_protocol_version' => 1]);
        $definition = WorkflowDefinition::query()->where('version', 4)->sole();

        $this->expectExceptionMessage('requires an Amp controller with Merge support');
        try {
            $this->engine->start($this->user, $definition, $project, 42, 'https://github.com/acme/widgets/issues/42');
        } finally {
            $this->actingAs($this->user)
                ->get(route('projects.workflows.create', $project))
                ->assertOk()
                ->assertSee('Merge workflow unavailable')
                ->assertDontSee('Version 4')
                ->assertSee('Version 3');
        }
    }

    public function test_human_approval_launches_fresh_merge_bound_to_exact_passing_qa_head(): void
    {
        $run = $this->toHumanReview($this->startRun(), $this->sha('a'));
        $human = $run->activeStageRun;

        $run = $this->engine->completeHumanAction($run, $human, 'approve', $this->user);
        $run->load(['activeStageRun.ampLaunch', 'stageRuns.ampLaunch']);
        $merge = $run->activeStageRun;
        $payload = app(AmpLaunchPayload::class)->make($merge->ampLaunch);

        $this->assertSame('merge', $run->currentStage->key);
        $this->assertSame('real_merge', $payload['agent_mode']);
        $this->assertSame($this->sha('a'), $payload['approved_pull_request_head_sha']);
        $this->assertSame(17, $payload['prior_pull_request_number']);
        $this->assertSame(0, $payload['merge_review_cycles']);
        $this->assertSame(['merged', 'requires_review', 'blocked'], $payload['allowed_outcomes']);
        $this->assertNotSame($run->stageRuns[1]->ampLaunch->capability_hash, $merge->ampLaunch->capability_hash);
        $this->claimAndAcknowledge($merge->ampLaunch, $this->threadId(3));
        $this->actingAs($this->user)->get(route('workflows.show', $run))
            ->assertOk()
            ->assertSee('Policy-bound merge')
            ->assertSee('QA-approved head')
            ->assertSee('Material conflict review cycle: 0/1')
            ->assertSee('Open PR #17');
    }

    public function test_verified_merge_completes_once_and_rejects_missing_or_conflicting_evidence(): void
    {
        $run = $this->toMerge($this->startRun(), $this->sha('a'));
        $merge = $run->activeStageRun;
        $launch = $merge->ampLaunch;
        $thread = $this->threadId(3);
        $this->claimAndAcknowledge($launch, $thread);

        $this->reportMerge($launch, $thread, 203, 'merged', $this->sha('a'));
        $this->complete($launch, $thread, 'merged', $this->reportUrl(203))
            ->assertAccepted()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('reason', 'Merge completion requires verified pull-request head and merge commit evidence.');

        $this->mergeEvidence($launch, $thread, $this->sha('a'), $this->sha('f'))->assertOk();
        $this->mergeEvidence($launch, $thread, $this->sha('b'), $this->sha('e'))
            ->assertAccepted()->assertJsonPath('accepted', false);
        $this->complete($launch, $thread, 'merged', $this->reportUrl(203))
            ->assertOk()->assertJsonPath('current_stage', 'done');

        $run->refresh()->load(['stageRuns', 'events']);
        $this->assertSame(WorkflowStatus::Completed, $run->status);
        $this->assertSame($this->sha('f'), $merge->fresh()->github_merge_commit_sha);
        $this->assertSame(1, $run->events->where('type', 'stage.merge_verified')->count());
        $this->assertSame(1, $run->events->where('type', 'workflow.completed')->count());
        $this->actingAs($this->user)->get(route('workflows.show', $run))
            ->assertOk()
            ->assertSee('Verified merged and marked Done')
            ->assertSee('Merge commit ffffffffffff');
    }

    public function test_one_material_conflict_cycle_returns_through_qa_and_second_cycle_is_blocked(): void
    {
        $run = $this->toMerge($this->startRun(), $this->sha('a'));
        $firstMerge = $run->activeStageRun;
        $firstLaunch = $firstMerge->ampLaunch;
        $firstThread = $this->threadId(3);
        $this->claimAndAcknowledge($firstLaunch, $firstThread);
        $this->reportMerge($firstLaunch, $firstThread, 303, 'requires_review', $this->sha('b'));
        $this->complete($firstLaunch, $firstThread, 'requires_review', $this->reportUrl(303))
            ->assertOk()->assertJsonPath('current_stage', 'qa');

        $run->refresh()->load(['activeStageRun.ampLaunch', 'stageRuns.ampLaunch']);
        $secondQa = $run->activeStageRun;
        $this->assertSame(5, $secondQa->attempt_number);
        $this->completeQa($run, $this->sha('b'), 304, 5, 'pass');
        $run->refresh()->load(['activeStageRun', 'stageRuns.ampLaunch']);
        $run = $this->engine->completeHumanAction($run, $run->activeStageRun, 'approve', $this->user);
        $run->load('activeStageRun.ampLaunch');
        $secondMerge = $run->activeStageRun;
        $secondPayload = app(AmpLaunchPayload::class)->make($secondMerge->ampLaunch);

        $this->assertSame(7, $secondMerge->attempt_number);
        $this->assertSame(1, $secondPayload['merge_review_cycles']);
        $this->assertSame(['merged', 'blocked'], $secondPayload['allowed_outcomes']);

        $secondThread = $this->threadId(7);
        $this->claimAndAcknowledge($secondMerge->ampLaunch, $secondThread);
        $this->postCapability($secondMerge->ampLaunch->capability_secret, [
            'action' => 'report',
            'thread_id' => $secondThread,
            'github_report_url' => $this->reportUrl(306),
            'github_report_comment_id' => 306,
            'github_report_kind' => 'requires_review',
            'github_pull_request_head_sha' => $this->sha('c'),
        ])->assertAccepted()->assertJsonPath('accepted', false)
            ->assertJsonPath('reason', 'This Merge outcome is no longer permitted for the run.');

        $this->reportMerge($secondMerge->ampLaunch, $secondThread, 307, 'blocked', $this->sha('b'));
        $this->complete($secondMerge->ampLaunch, $secondThread, 'blocked', $this->reportUrl(307))
            ->assertOk()->assertJsonPath('current_stage', 'merge_blocked');
        $run->refresh()->load('activeStageRun');
        $this->assertSame(StageRunStatus::Waiting, $run->activeStageRun->status);
    }

    public function test_merge_blocked_retry_gets_a_fresh_attempt_and_stale_or_cancelled_callbacks_fail(): void
    {
        $run = $this->toMerge($this->startRun(), $this->sha('a'));
        $firstMerge = $run->activeStageRun;
        $thread = $this->threadId(3);
        $this->claimAndAcknowledge($firstMerge->ampLaunch, $thread);
        $this->reportMerge($firstMerge->ampLaunch, $thread, 403, 'blocked', $this->sha('a'));
        $this->complete($firstMerge->ampLaunch, $thread, 'blocked', $this->reportUrl(403));

        $run->refresh()->load('activeStageRun');
        $blocked = $run->activeStageRun;
        $run = $this->engine->completeHumanAction($run, $blocked, 'retry', $this->user);
        $run->load('activeStageRun.ampLaunch');
        $retry = $run->activeStageRun;
        $this->assertSame('merge', $retry->stage->key);
        $this->assertSame(6, $retry->attempt_number);
        $this->assertNotSame($firstMerge->ampLaunch->capability_hash, $retry->ampLaunch->capability_hash);

        $this->postCapability($firstMerge->ampLaunch->capability_secret, [
            'action' => 'complete',
            'thread_id' => $thread,
            'outcome' => 'merged',
            'github_report_url' => $this->reportUrl(403),
        ])->assertAccepted()->assertJsonPath('accepted', false);

        $retryThread = $this->threadId(6);
        $this->claimAndAcknowledge($retry->ampLaunch, $retryThread);
        $this->engine->cancel($run, $this->user);
        $this->postCapability($retry->ampLaunch->capability_secret, [
            'action' => 'merge',
            'thread_id' => $retryThread,
            'github_pull_request_number' => 17,
            'github_pull_request_url' => 'https://github.com/acme/widgets/pull/17',
            'github_pull_request_head_sha' => $this->sha('a'),
            'github_merge_commit_sha' => $this->sha('f'),
        ])->assertAccepted()->assertJsonPath('accepted', false);
    }

    private function startRun(): WorkflowRun
    {
        return $this->engine->start(
            $this->user,
            WorkflowDefinition::query()->where('version', 4)->sole(),
            $this->workflowProject($this->user, 'acme/widgets'),
            42,
            'https://github.com/acme/widgets/issues/42',
        );
    }

    private function toHumanReview(WorkflowRun $run, string $qaHead): WorkflowRun
    {
        $this->completeDevelopment($run, 101, 1, $qaHead);
        $run->refresh()->load('activeStageRun.ampLaunch');
        $this->completeQa($run, $qaHead, 102, 2, 'pass');

        return $run->refresh()->load(['currentStage', 'activeStageRun', 'stageRuns.ampLaunch']);
    }

    private function toMerge(WorkflowRun $run, string $qaHead): WorkflowRun
    {
        $run = $this->toHumanReview($run, $qaHead);
        $run = $this->engine->completeHumanAction($run, $run->activeStageRun, 'approve', $this->user);

        return $run->load(['currentStage', 'activeStageRun.ampLaunch', 'stageRuns.ampLaunch']);
    }

    private function completeDevelopment(WorkflowRun $run, int $comment, int $threadNumber, string $head): void
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
            'github_pull_request_head_sha' => $head,
        ])->assertOk();
        $this->complete($launch, $thread, 'success', "https://github.com/acme/widgets/issues/42#issuecomment-{$comment}")->assertOk();
    }

    private function completeQa(WorkflowRun $run, string $head, int $comment, int $threadNumber, string $outcome): void
    {
        $launch = $run->activeStageRun->ampLaunch;
        $thread = $this->threadId($threadNumber);
        $this->claimAndAcknowledge($launch, $thread);
        $this->postCapability($launch->capability_secret, [
            'action' => 'report',
            'thread_id' => $thread,
            'github_report_url' => $this->reportUrl($comment),
            'github_report_comment_id' => $comment,
            'github_report_kind' => $outcome,
            'github_pull_request_head_sha' => $head,
        ])->assertOk();
        $this->complete($launch, $thread, $outcome, $this->reportUrl($comment))->assertOk();
    }

    private function reportMerge(AmpLaunch $launch, string $thread, int $comment, string $outcome, string $head): void
    {
        $this->postCapability($launch->capability_secret, [
            'action' => 'report',
            'thread_id' => $thread,
            'github_report_url' => $this->reportUrl($comment),
            'github_report_comment_id' => $comment,
            'github_report_kind' => $outcome,
            'github_pull_request_head_sha' => $head,
        ])->assertOk();
    }

    private function mergeEvidence(AmpLaunch $launch, string $thread, string $head, string $mergeCommit)
    {
        return $this->postCapability($launch->capability_secret, [
            'action' => 'merge',
            'thread_id' => $thread,
            'github_pull_request_number' => 17,
            'github_pull_request_url' => 'https://github.com/acme/widgets/pull/17',
            'github_pull_request_head_sha' => $head,
            'github_merge_commit_sha' => $mergeCommit,
        ]);
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

    private function reportUrl(int $comment): string
    {
        return "https://github.com/acme/widgets/pull/17#issuecomment-{$comment}";
    }

    private function threadId(int $number): string
    {
        return 'T-'.str_pad((string) $number, 36, '0', STR_PAD_LEFT);
    }

    private function sha(string $character): string
    {
        return str_repeat($character, 40);
    }
}
