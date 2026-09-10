<?php

namespace Tests\Feature\Workflow;

use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Domain\Workflow\StageRunStatus;
use App\Domain\Workflow\WorkflowStatus;
use App\Jobs\DeliverAmpCancellation;
use App\Models\StageRun;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\WorkflowEngine;
use Database\Seeders\DevelopmentWorkflowSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

class WorkflowEngineTest extends TestCase
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
    }

    public function test_seeded_definition_has_the_required_versioned_graph(): void
    {
        $definition = WorkflowDefinition::query()->where('version', 1)->with(['stages', 'transitions'])->sole();

        $this->assertSame('development', $definition->key);
        $this->assertSame(1, $definition->version);
        $this->assertTrue($definition->is_active);
        $this->assertSame(
            ['development', 'qa', 'human_review', 'done'],
            $definition->stages->pluck('key')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['development:success', 'qa:fail', 'qa:pass', 'human_review:request_changes', 'human_review:approve'],
            $definition->transitions->map(
                fn ($transition) => $definition->stages->firstWhere('id', $transition->from_stage_id)->key.':'.$transition->outcome
            )->all(),
        );
    }

    public function test_successful_run_transitions_through_qa_review_and_done(): void
    {
        $run = $this->startRun();

        $this->assertSame('development', $run->currentStage->key);
        $this->assertSame(StageRunStatus::Running, $run->activeStageRun->status);

        $run = $this->simulate($run, 'success');
        $this->assertSame('qa', $run->currentStage->key);
        $this->assertSame(2, $run->activeStageRun->attempt_number);

        $run = $this->simulate($run, 'pass');
        $this->assertSame('human_review', $run->currentStage->key);
        $this->assertSame(StageRunStatus::Waiting, $run->activeStageRun->status);

        $run = $this->engine->completeHumanAction(
            $run,
            $run->activeStageRun,
            'approve',
            $this->user,
        );

        $this->assertSame(WorkflowStatus::Completed, $run->status);
        $this->assertSame('done', $run->currentStage->key);
        $this->assertNull($run->activeStageRun);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(
            [1, 2, 3, 4],
            $run->stageRuns()->pluck('attempt_number')->all(),
        );
        $this->assertSame('done', $run->stageRuns()->with('stage')->get()->last()->stage->key);
    }

    public function test_qa_and_review_loops_allocate_monotonic_attempt_numbers(): void
    {
        $run = $this->simulate($this->startRun(), 'success');
        $run = $this->simulate($run, 'fail');
        $run = $this->simulate($run, 'success');
        $run = $this->simulate($run, 'pass');
        $run->stageRuns()->where('attempt_number', 1)->update([
            'github_pull_request_number' => 8,
            'github_pull_request_url' => 'https://github.com/acme/widgets/pull/8',
        ]);
        $run = $this->engine->completeHumanAction(
            $run,
            $run->activeStageRun,
            'request_changes',
            $this->user,
        );

        $attempts = $run->stageRuns()->with('stage')->get();

        $this->assertSame([1, 2, 3, 4, 5, 6], $attempts->pluck('attempt_number')->all());
        $this->assertSame(
            ['development', 'qa', 'development', 'qa', 'human_review', 'development'],
            $attempts->pluck('stage.key')->all(),
        );
        $this->assertSame('development', $run->currentStage->key);
        $this->assertSame(1, $attempts->whereNotNull('active_slot')->count());

        $event = $run->events()->where('type', 'stage.completed')->reorder()->latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('github_feedback_url', $event->metadata);
        $this->assertArrayNotHasKey('feedback', $event->metadata);
    }

    public function test_identical_duplicate_completion_is_idempotent(): void
    {
        $run = $this->startRun();
        $attempt = $run->activeStageRun;

        $first = $this->engine->simulateAgentCompletion($run, $attempt, 'success', $this->user);
        $attemptCount = $first->stageRuns()->count();
        $eventCount = $first->events()->count();

        $second = $this->engine->simulateAgentCompletion($run, $attempt, 'success', $this->user);

        $this->assertSame('qa', $second->currentStage->key);
        $this->assertSame($attemptCount, $second->stageRuns()->count());
        $this->assertSame($eventCount, $second->events()->count());
    }

    public function test_competing_completion_requests_are_serialized_to_one_transition(): void
    {
        $run = $this->simulate($this->startRun(), 'success');
        $qaAttemptFromRequestOne = $run->activeStageRun->replicate()->setRawAttributes(
            $run->activeStageRun->getAttributes(),
            true,
        );
        $qaAttemptFromRequestTwo = $run->activeStageRun->replicate()->setRawAttributes(
            $run->activeStageRun->getAttributes(),
            true,
        );
        $qaAttemptFromRequestOne->exists = true;
        $qaAttemptFromRequestTwo->exists = true;

        $result = $this->engine->simulateAgentCompletion($run, $qaAttemptFromRequestOne, 'pass', $this->user);

        try {
            $this->engine->simulateAgentCompletion($run, $qaAttemptFromRequestTwo, 'fail', $this->user);
            $this->fail('The second racing outcome should conflict.');
        } catch (WorkflowConflict $exception) {
            $this->assertStringContainsString('different outcome', $exception->getMessage());
        }

        $this->assertSame('human_review', $result->currentStage->key);
        $this->assertSame(3, $result->stageRuns()->count());
        $this->assertSame(1, $result->stageRuns()->where('active_slot', 1)->count());
    }

    public function test_invalid_outcome_leaves_current_attempt_unchanged(): void
    {
        $run = $this->startRun();
        $attempt = $run->activeStageRun;

        try {
            $this->engine->simulateAgentCompletion($run, $attempt, 'pass', $this->user);
            $this->fail('Development must not accept the QA pass outcome.');
        } catch (WorkflowConflict $exception) {
            $this->assertStringContainsString('not permitted', $exception->getMessage());
        }

        $this->assertSame(StageRunStatus::Running, $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->outcome);
        $this->assertSame(1, $run->stageRuns()->count());
    }

    public function test_stale_noncurrent_attempt_is_rejected(): void
    {
        $run = $this->startRun();
        $qa = WorkflowDefinition::query()->where('version', 1)->sole()->stages()->where('key', 'qa')->sole();
        $stale = StageRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_stage_id' => $qa->id,
            'attempt_number' => 99,
            'status' => StageRunStatus::Running,
            'active_slot' => null,
            'started_at' => now(),
        ]);

        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('stale');

        $this->engine->simulateAgentCompletion($run, $stale, 'pass', $this->user);
    }

    public function test_cancel_is_idempotent_and_cancelled_run_rejects_completion(): void
    {
        $run = $this->startRun();
        $attempt = $run->activeStageRun;

        $cancelled = $this->engine->cancel($run, $this->user);
        $cancelledAgain = $this->engine->cancel($run, $this->user);

        $this->assertSame(WorkflowStatus::Cancelled, $cancelledAgain->status);
        $this->assertNull($cancelledAgain->activeStageRun);
        $this->assertSame(StageRunStatus::Cancelled, $attempt->fresh()->status);
        $this->assertSame(1, $cancelled->events()->where('type', 'workflow.cancelled')->count());

        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('Cancelled workflows');
        $this->engine->simulateAgentCompletion($run, $attempt, 'success', $this->user);
    }

    public function test_accidental_review_change_request_can_be_stopped_moved_back_and_approved(): void
    {
        $run = $this->simulate($this->startRun(), 'success');
        $run = $this->simulate($run, 'pass');
        $run = $this->engine->completeHumanAction(
            $run,
            $run->activeStageRun,
            'request_changes',
            $this->user,
        );
        $unnecessaryDevelopment = $run->activeStageRun;

        $run = $this->engine->pause($run, $unnecessaryDevelopment, $this->user);

        $this->assertSame(WorkflowStatus::Paused, $run->status);
        $this->assertSame(StageRunStatus::Cancelled, $unnecessaryDevelopment->fresh()->status);
        $this->assertNull($run->activeStageRun);

        $humanReview = $run->definition->stages()->where('key', 'human_review')->sole();
        $run = $this->engine->overrideStage(
            $run,
            $unnecessaryDevelopment,
            $humanReview->id,
            $this->user,
        );

        $this->assertSame(WorkflowStatus::Running, $run->status);
        $this->assertSame('human_review', $run->currentStage->key);
        $this->assertSame(5, $run->activeStageRun->attempt_number);
        $this->assertSame(StageRunStatus::Waiting, $run->activeStageRun->status);
        $this->assertSame(
            [1, 2, 3, 4, 5],
            $run->stageRuns()->pluck('attempt_number')->all(),
        );

        $override = $run->events()->where('type', 'workflow.stage_overridden')->sole();
        $this->assertSame('development', $override->metadata['from_stage_key']);
        $this->assertSame('human_review', $override->metadata['to_stage_key']);
        $this->assertSame('manual_override', $run->events()->where('stage_run_id', $run->activeStageRun->id)->where('type', 'stage.started')->sole()->metadata['source']);

        $run = $this->engine->completeHumanAction($run, $run->activeStageRun, 'approve', $this->user);
        $this->assertSame(WorkflowStatus::Completed, $run->status);
        $this->assertSame('done', $run->currentStage->key);
        $this->assertSame(6, $run->stageRuns()->max('attempt_number'));
    }

    public function test_pause_and_manual_move_are_idempotent_and_allocate_one_new_attempt(): void
    {
        $run = $this->startRun();
        $attempt = $run->activeStageRun;

        $firstPause = $this->engine->pause($run, $attempt, $this->user);
        $secondPause = $this->engine->pause($run, $attempt, $this->user);

        $this->assertSame(WorkflowStatus::Paused, $firstPause->status);
        $this->assertSame(WorkflowStatus::Paused, $secondPause->status);
        $this->assertSame(1, $run->events()->where('type', 'workflow.paused')->count());

        $review = $run->definition->stages()->where('key', 'human_review')->sole();
        $firstMove = $this->engine->overrideStage($run, $attempt, $review->id, $this->user);
        $secondMove = $this->engine->overrideStage($run, $attempt, $review->id, $this->user);

        $this->assertSame($firstMove->current_stage_id, $secondMove->current_stage_id);
        $this->assertSame(2, $run->stageRuns()->count());
        $this->assertSame(1, $run->events()->where('type', 'workflow.stage_overridden')->count());

        $qa = $run->definition->stages()->where('key', 'qa')->sole();
        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('different stage');
        $this->engine->overrideStage($run, $attempt, $qa->id, $this->user);
    }

    public function test_late_completion_of_a_paused_attempt_is_rejected(): void
    {
        $run = $this->startRun();
        $attempt = $run->activeStageRun;
        $this->engine->pause($run, $attempt, $this->user);

        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('no longer running');
        $this->engine->simulateAgentCompletion($run, $attempt, 'success', $this->user);
    }

    public function test_human_attempt_cannot_use_agent_pause_control(): void
    {
        $run = $this->simulate($this->startRun(), 'success');
        $run = $this->simulate($run, 'pass');

        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('active agent attempt');
        $this->engine->pause($run, $run->activeStageRun, $this->user);
    }

    public function test_failed_workflow_can_resume_at_a_new_stage_with_monotonic_numbering(): void
    {
        $run = $this->startRun();
        $attempt = $run->activeStageRun;
        $attempt->update([
            'status' => StageRunStatus::Failed,
            'active_slot' => null,
            'completed_at' => now(),
        ]);
        $run->update(['status' => WorkflowStatus::Failed, 'failed_at' => now()]);
        $qa = $run->definition->stages()->where('key', 'qa')->sole();

        $run = $this->engine->overrideStage($run, $attempt, $qa->id, $this->user);

        $this->assertSame(WorkflowStatus::Running, $run->status);
        $this->assertNull($run->failed_at);
        $this->assertSame('qa', $run->currentStage->key);
        $this->assertSame(2, $run->activeStageRun->attempt_number);
    }

    public function test_manual_move_rejects_terminal_cross_definition_and_stale_attempts(): void
    {
        $run = $this->startRun();
        $attempt = $run->activeStageRun;
        $done = $run->definition->stages()->where('key', 'done')->sole();

        try {
            $this->engine->overrideStage($run, $attempt, $done->id, $this->user);
            $this->fail('Direct terminal moves must be rejected.');
        } catch (WorkflowConflict $exception) {
            $this->assertStringContainsString('Human Review', $exception->getMessage());
        }

        $foreignStage = WorkflowDefinition::query()->where('version', '>', 1)->firstOrFail()->stages()->firstOrFail();
        try {
            $this->engine->overrideStage($run, $attempt, $foreignStage->id, $this->user);
            $this->fail('Cross-definition moves must be rejected.');
        } catch (WorkflowConflict $exception) {
            $this->assertStringContainsString('does not belong', $exception->getMessage());
        }

        $run = $this->simulate($run, 'success');
        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('stale');
        $this->engine->overrideStage(
            $run,
            $attempt,
            $run->definition->stages()->where('key', 'human_review')->sole()->id,
            $this->user,
        );
    }

    public function test_agent_stage_manual_move_rechecks_account_and_bound_connection_authority(): void
    {
        $run = $this->startRun();
        $attempt = $run->activeStageRun;
        $this->engine->pause($run, $attempt, $this->user);
        config(['services.amp.enabled' => true]);
        Queue::fake();
        $qa = $run->definition->stages()->where('key', 'qa')->sole();

        try {
            $this->engine->overrideStage($run, $attempt, $qa->id, $this->user);
            $this->fail('An account without immutable launch permission must be rejected.');
        } catch (WorkflowConflict $exception) {
            $this->assertStringContainsString('not authorized', $exception->getMessage());
        }

        $this->user->update(['can_trigger_amp' => true]);
        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('verified project connection');
        $this->engine->overrideStage($run, $attempt, $qa->id, $this->user);
    }

    public function test_pausing_a_bound_agent_queues_exactly_one_thread_cancellation(): void
    {
        config(['services.amp.enabled' => true]);
        $this->user->update(['can_trigger_amp' => true]);
        Queue::fake();
        $run = $this->startRun();
        $attempt = $run->activeStageRun;
        $attempt->update(['amp_thread_id' => 'T-00000000-0000-0000-0000-000000000099']);

        $this->engine->pause($run, $attempt, $this->user);
        $this->engine->pause($run, $attempt, $this->user);

        Queue::assertPushed(DeliverAmpCancellation::class, 1);
        $this->assertSame('pending', $attempt->ampLaunch->fresh()->cancellation_status);
        $this->assertNotNull($attempt->ampLaunch->fresh()->cancellation_event_id);
    }

    public function test_manual_move_to_real_qa_requires_an_existing_bound_pull_request(): void
    {
        $definition = WorkflowDefinition::query()->where('version', 3)->sole();
        $run = $this->engine->start(
            $this->user,
            $definition,
            $this->workflowProject($this->user, 'acme/widgets'),
            42,
            'https://github.com/acme/widgets/issues/42',
        );
        $attempt = $run->activeStageRun;
        $this->engine->pause($run, $attempt, $this->user);
        $qa = $definition->stages()->where('key', 'qa')->sole();

        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('existing Development pull request');
        $this->engine->overrideStage($run, $attempt, $qa->id, $this->user);
    }

    public function test_completed_and_cancelled_workflows_cannot_be_manually_moved(): void
    {
        $cancelled = $this->startRun();
        $cancelledAttempt = $cancelled->activeStageRun;
        $this->engine->cancel($cancelled, $this->user);
        $review = $cancelled->definition->stages()->where('key', 'human_review')->sole();

        try {
            $this->engine->overrideStage($cancelled, $cancelledAttempt, $review->id, $this->user);
            $this->fail('Cancelled workflows must remain final.');
        } catch (WorkflowConflict $exception) {
            $this->assertStringContainsString('running, paused, or failed', $exception->getMessage());
        }

        $completed = $this->simulate($this->startRun(), 'success');
        $completed = $this->simulate($completed, 'pass');
        $reviewAttempt = $completed->activeStageRun;
        $completed = $this->engine->completeHumanAction($completed, $reviewAttempt, 'approve', $this->user);

        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('running, paused, or failed');
        $this->engine->overrideStage($completed, $reviewAttempt, $review->id, $this->user);
    }

    public function test_human_action_requires_a_human_stage_but_not_feedback_for_changes(): void
    {
        $run = $this->startRun();

        try {
            $this->engine->completeHumanAction($run, $run->activeStageRun, 'success', $this->user);
            $this->fail('A human action must not complete an agent stage.');
        } catch (WorkflowConflict $exception) {
            $this->assertStringContainsString('stage type', $exception->getMessage());
        }

        $run = $this->simulate($run, 'success');
        $run = $this->simulate($run, 'pass');
        $review = $run->activeStageRun;
        $run = $this->engine->completeHumanAction($run, $review, 'request_changes', $this->user);

        $this->assertSame('development', $run->currentStage->key);
        $this->assertSame(StageRunStatus::Running, $run->activeStageRun->status);
        $this->assertSame(4, $run->activeStageRun->attempt_number);

        $duplicate = $this->engine->completeHumanAction($run, $review, 'request_changes', $this->user);
        $this->assertSame(4, $duplicate->stageRuns()->count());
    }

    public function test_database_constraint_prevents_two_active_attempts(): void
    {
        $run = $this->startRun();
        $qa = WorkflowDefinition::query()->where('version', 1)->sole()->stages()->where('key', 'qa')->sole();

        $this->expectException(QueryException::class);

        StageRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_stage_id' => $qa->id,
            'attempt_number' => 2,
            'status' => StageRunStatus::Running,
            'active_slot' => 1,
            'started_at' => now(),
        ]);
    }

    public function test_workflow_events_reject_updates_and_deletes(): void
    {
        $event = $this->startRun()->events()->firstOrFail();

        try {
            $event->update(['type' => 'tampered']);
            $this->fail('An event update should be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('Workflow events are append-only.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    private function startRun(): WorkflowRun
    {
        return $this->engine->start(
            $this->user,
            WorkflowDefinition::query()->where('version', 1)->sole(),
            $this->workflowProject($this->user, 'acme/widgets'),
            42,
            'https://github.com/acme/widgets/issues/42',
        );
    }

    private function simulate(WorkflowRun $run, string $outcome): WorkflowRun
    {
        return $this->engine->simulateAgentCompletion(
            $run,
            $run->activeStageRun,
            $outcome,
            $this->user,
        );
    }
}
