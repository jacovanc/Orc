<?php

namespace Tests\Feature\Workflow;

use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Domain\Workflow\StageRunStatus;
use App\Domain\Workflow\WorkflowStatus;
use App\Models\StageRun;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\WorkflowEngine;
use Database\Seeders\DevelopmentWorkflowSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $run = $this->engine->completeHumanAction(
            $run,
            $run->activeStageRun,
            'request_changes',
            $this->user,
            'https://github.com/acme/widgets/pull/8#discussion_r123',
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
        $this->assertSame(
            'https://github.com/acme/widgets/pull/8#discussion_r123',
            $event->metadata['github_feedback_url'],
        );
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

    public function test_human_action_requires_human_stage_and_github_feedback_for_changes(): void
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

        foreach ([null, 'https://example.com/review/1'] as $invalidUrl) {
            try {
                $this->engine->completeHumanAction(
                    $run,
                    $run->activeStageRun,
                    'request_changes',
                    $this->user,
                    $invalidUrl,
                );
                $this->fail('Request changes should require GitHub-published feedback.');
            } catch (WorkflowConflict $exception) {
                $this->assertStringContainsString('published on GitHub', $exception->getMessage());
            }
        }

        $this->assertSame('human_review', $run->fresh('currentStage')->currentStage->key);
        $this->assertSame(StageRunStatus::Waiting, $run->activeStageRun->fresh()->status);
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
            'acme/widgets',
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
