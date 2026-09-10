<?php

namespace Tests\Feature\Workflow;

use App\Domain\Workflow\StageRunStatus;
use App\Domain\Workflow\WorkflowStatus;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\WorkflowEngine;
use Database\Seeders\DevelopmentWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkflowPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DevelopmentWorkflowSeeder::class);
        $this->user = User::factory()->create();
        $this->engine = app(WorkflowEngine::class);
    }

    public function test_workflow_pages_require_authentication(): void
    {
        $this->get(route('workflows.index'))->assertRedirect(route('login'));
        $this->get(route('workflows.create'))->assertRedirect(route('login'));
    }

    public function test_user_can_start_and_view_workflow_with_github_identifiers(): void
    {
        $definition = WorkflowDefinition::query()->where('version', 1)->sole();

        $project = $this->workflowProject($this->user, 'acme/widgets');
        $response = $this->actingAs($this->user)->post(route('projects.workflows.store', $project), [
            'workflow_definition_id' => $definition->id,
            'github_issue_number' => 18,
        ]);

        $run = WorkflowRun::query()->sole();
        $response->assertRedirect(route('workflows.show', $run));
        $this->assertSame('https://github.com/acme/widgets/issues/18', $run->github_issue_url);

        $this->actingAs($this->user)
            ->get(route('workflows.show', $run))
            ->assertOk()
            ->assertSee('acme/widgets')
            ->assertSee('#18')
            ->assertSee('Development')
            ->assertSee('Simulation only.')
            ->assertSee('Success')
            ->assertDontSee('Request changes');

        $this->assertDatabaseMissing('workflow_runs', ['github_issue_url' => 'requirements text']);
    }

    public function test_start_form_needs_only_an_issue_number_and_ignores_injected_issue_urls(): void
    {
        $definition = WorkflowDefinition::query()->where('version', 1)->sole();
        $project = $this->workflowProject($this->user, 'acme/widgets');

        $this->actingAs($this->user)
            ->get(route('projects.workflows.create', $project))
            ->assertOk()
            ->assertSee('Orc derives the issue link automatically.')
            ->assertDontSee('github_issue_url', false)
            ->assertDontSee('Issue URL');

        $this->actingAs($this->user)
            ->post(route('projects.workflows.store', $project), [
                'workflow_definition_id' => $definition->id,
                'github_issue_number' => 18,
                'github_issue_url' => 'https://example.com/attacker/issues/999',
            ])->assertRedirect();

        $this->assertDatabaseHas('workflow_runs', [
            'github_repository' => 'acme/widgets',
            'github_issue_number' => 18,
            'github_issue_url' => 'https://github.com/acme/widgets/issues/18',
        ]);
    }

    public function test_owner_can_simulate_agent_completion_and_cancel(): void
    {
        $run = $this->startRun();
        $attempt = $run->activeStageRun;

        $this->actingAs($this->user)
            ->post(route('workflows.attempts.simulate', [$run, $attempt]), ['outcome' => 'success'])
            ->assertSessionHas('status', 'Simulated agent completion recorded.');

        $this->assertSame('qa', $run->fresh('currentStage')->currentStage->key);

        $this->actingAs($this->user)
            ->post(route('workflows.cancel', $run))
            ->assertSessionHas('status', 'Workflow cancelled.');

        $this->assertSame('cancelled', $run->fresh()->status->value);
    }

    public function test_human_review_actions_need_no_feedback_field_and_request_changes_routes_once(): void
    {
        $run = $this->startRun();
        $run = $this->engine->simulateAgentCompletion($run, $run->activeStageRun, 'success', $this->user);
        $run = $this->engine->simulateAgentCompletion($run, $run->activeStageRun, 'pass', $this->user);
        $reviewAttempt = $run->activeStageRun;

        $this->actingAs($this->user)
            ->get(route('workflows.show', $run))
            ->assertOk()
            ->assertSee('Human Review')
            ->assertSee('Request changes')
            ->assertSee('Approve')
            ->assertSee('The fresh Development agent will reread the bound pull request')
            ->assertDontSee('GitHub feedback URL')
            ->assertDontSee('github_feedback_confirmed', false);

        $this->actingAs($this->user)
            ->post(route('workflows.attempts.human-action', [$run, $reviewAttempt]), [
                'outcome' => 'request_changes',
            ])
            ->assertSessionHas('status', 'Review decision recorded.');

        $this->assertSame('development', $run->fresh('currentStage')->currentStage->key);
        $this->assertSame(4, $run->stageRuns()->count());

        $this->actingAs($this->user)
            ->post(route('workflows.attempts.human-action', [$run, $reviewAttempt]), [
                'outcome' => 'request_changes',
            ])
            ->assertSessionHas('status', 'Review decision recorded.');

        $this->assertSame(4, $run->stageRuns()->count());
        $this->assertSame(1, $run->stageRuns()->where('active_slot', 1)->count());
    }

    public function test_human_review_can_be_approved_without_feedback_fields(): void
    {
        $run = $this->startRun();
        $run = $this->engine->simulateAgentCompletion($run, $run->activeStageRun, 'success', $this->user);
        $run = $this->engine->simulateAgentCompletion($run, $run->activeStageRun, 'pass', $this->user);

        $this->actingAs($this->user)
            ->post(route('workflows.attempts.human-action', [$run, $run->activeStageRun]), [
                'outcome' => 'approve',
            ])
            ->assertSessionHas('status', 'Review decision recorded.');

        $this->assertSame('completed', $run->fresh()->status->value);
        $this->assertSame('done', $run->fresh('currentStage')->currentStage->key);
    }

    public function test_human_action_still_requires_an_outcome(): void
    {
        $run = $this->startRun();
        $run = $this->engine->simulateAgentCompletion($run, $run->activeStageRun, 'success', $this->user);
        $run = $this->engine->simulateAgentCompletion($run, $run->activeStageRun, 'pass', $this->user);

        $this->actingAs($this->user)
            ->post(route('workflows.attempts.human-action', [$run, $run->activeStageRun]))
            ->assertSessionHasErrors('outcome');

        $this->assertSame('human_review', $run->fresh('currentStage')->currentStage->key);
    }

    public function test_amp_enabled_page_shows_dispatch_thread_and_report_without_simulation_controls(): void
    {
        config([
            'services.amp.enabled' => true,
        ]);
        $this->user->update(['can_trigger_amp' => true]);
        Queue::fake();
        $run = $this->startRun();
        $attempt = $run->activeStageRun;
        $threadId = 'T-00000000-0000-0000-0000-000000000001';
        $reportUrl = 'https://github.com/acme/widgets/issues/18#issuecomment-101';
        $attempt->update(['amp_thread_id' => $threadId]);
        $run->events()->create([
            'stage_run_id' => $attempt->id,
            'type' => 'stage.completed',
            'actor_type' => 'amp_agent',
            'metadata' => [
                'amp_thread_id' => $threadId,
                'github_report_url' => $reportUrl,
            ],
            'happened_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->get(route('workflows.show', $run))
            ->assertOk()
            ->assertSee('Amp dispatch')
            ->assertSee('Open Amp thread')
            ->assertSee("https://ampcode.com/threads/{$threadId}", false)
            ->assertSee($reportUrl, false)
            ->assertSee('Report')
            ->assertDontSee('Simulation only.')
            ->assertDontSee('Record a simulated outcome');
    }

    public function test_other_users_cannot_view_or_mutate_a_run(): void
    {
        $run = $this->startRun();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('workflows.show', $run))->assertNotFound();
        $this->actingAs($other)->post(route('workflows.cancel', $run))->assertNotFound();
        $this->actingAs($other)->post(route('workflows.attempts.pause', [$run, $run->activeStageRun]))->assertNotFound();
        $this->actingAs($other)->post(route('workflows.attempts.override-stage', [$run, $run->activeStageRun]), [
            'target_stage_id' => $run->definition->stages()->where('key', 'human_review')->sole()->id,
        ])->assertNotFound();
    }

    public function test_manual_controls_render_and_support_pause_resume_and_validation(): void
    {
        $run = $this->startRun();
        $attempt = $run->activeStageRun;

        $this->actingAs($this->user)
            ->get(route('workflows.show', $run))
            ->assertOk()
            ->assertSee('Manual controls')
            ->assertSee('Stop current agent')
            ->assertSee('Stop &amp; move', false)
            ->assertSee('Cancel entire workflow')
            ->assertSee('Move to stage')
            ->assertDontSee('Done · Terminal');

        $this->actingAs($this->user)
            ->post(route('workflows.attempts.override-stage', [$run, $attempt]))
            ->assertSessionHasErrors('target_stage_id');

        $this->actingAs($this->user)
            ->post(route('workflows.attempts.pause', [$run, $attempt]))
            ->assertSessionHas('status', 'Current attempt stopped. Choose a stage when you are ready to resume.');

        $this->actingAs($this->user)
            ->get(route('workflows.show', $run->fresh()))
            ->assertOk()
            ->assertSee('Workflow paused')
            ->assertSee('Resume at stage')
            ->assertDontSee('Stop current agent');

        $review = $run->definition->stages()->where('key', 'human_review')->sole();
        $this->actingAs($this->user)
            ->post(route('workflows.attempts.override-stage', [$run, $attempt]), [
                'target_stage_id' => $review->id,
            ])
            ->assertSessionHas('status', 'Workflow moved to Human Review with a new audited attempt.');

        $this->actingAs($this->user)
            ->get(route('workflows.show', $run->fresh()))
            ->assertOk()
            ->assertSee('Manual review entry.')
            ->assertSee('No QA result is implied')
            ->assertSee('Approve');
    }

    public function test_manual_controls_are_hidden_after_workflow_completion(): void
    {
        $run = $this->startRun();
        $run = $this->engine->simulateAgentCompletion($run, $run->activeStageRun, 'success', $this->user);
        $run = $this->engine->simulateAgentCompletion($run, $run->activeStageRun, 'pass', $this->user);
        $run = $this->engine->completeHumanAction($run, $run->activeStageRun, 'approve', $this->user);

        $this->actingAs($this->user)
            ->get(route('workflows.show', $run))
            ->assertOk()
            ->assertDontSee('Manual controls')
            ->assertDontSee('Cancel entire workflow');
    }

    public function test_failed_workflow_renders_recovery_controls(): void
    {
        $run = $this->startRun();
        $run->activeStageRun->update([
            'status' => StageRunStatus::Failed,
            'active_slot' => null,
            'completed_at' => now(),
        ]);
        $run->update([
            'status' => WorkflowStatus::Failed,
            'failed_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->get(route('workflows.show', $run))
            ->assertOk()
            ->assertSee('Workflow failed')
            ->assertSee('Manual controls')
            ->assertSee('Resume at stage')
            ->assertDontSee('Cancel entire workflow');
    }

    private function startRun(): WorkflowRun
    {
        return $this->engine->start(
            $this->user,
            WorkflowDefinition::query()->where('version', 1)->sole(),
            $this->workflowProject($this->user, 'acme/widgets'),
            18,
            'https://github.com/acme/widgets/issues/18',
        );
    }
}
