<?php

namespace Tests\Feature\Workflow;

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
        $definition = WorkflowDefinition::query()->sole();

        $response = $this->actingAs($this->user)->post(route('workflows.store'), [
            'workflow_definition_id' => $definition->id,
            'github_repository' => 'acme/widgets',
            'github_issue_number' => 18,
            'github_issue_url' => 'https://github.com/acme/widgets/issues/18',
        ]);

        $run = WorkflowRun::query()->sole();
        $response->assertRedirect(route('workflows.show', $run));

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

    public function test_start_rejects_mismatched_or_non_github_issue_urls(): void
    {
        $definition = WorkflowDefinition::query()->sole();

        foreach (['https://example.com/acme/widgets/issues/18', 'https://github.com/acme/other/issues/18'] as $url) {
            $this->actingAs($this->user)
                ->from(route('workflows.create'))
                ->post(route('workflows.store'), [
                    'workflow_definition_id' => $definition->id,
                    'github_repository' => 'acme/widgets',
                    'github_issue_number' => 18,
                    'github_issue_url' => $url,
                ])
                ->assertRedirect(route('workflows.create'))
                ->assertSessionHasErrors('github_issue_url');
        }

        $this->assertDatabaseCount('workflow_runs', 0);
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

    public function test_human_review_page_only_exposes_permitted_actions_and_requires_feedback_link(): void
    {
        $run = $this->startRun();
        $run = $this->engine->simulateAgentCompletion($run, $run->activeStageRun, 'success', $this->user);
        $run = $this->engine->simulateAgentCompletion($run, $run->activeStageRun, 'pass', $this->user);

        $this->actingAs($this->user)
            ->get(route('workflows.show', $run))
            ->assertOk()
            ->assertSee('Human Review')
            ->assertSee('Request changes')
            ->assertSee('Approve')
            ->assertSee('GitHub feedback URL');

        $this->actingAs($this->user)
            ->post(route('workflows.attempts.human-action', [$run, $run->activeStageRun]), [
                'outcome' => 'request_changes',
            ])
            ->assertSessionHasErrors('github_feedback_url');

        $this->assertSame('human_review', $run->fresh('currentStage')->currentStage->key);
    }

    public function test_amp_enabled_page_shows_dispatch_thread_and_report_without_simulation_controls(): void
    {
        config([
            'services.amp.enabled' => true,
            'services.amp.allowed_repositories' => ['acme/widgets'],
            'services.amp.allowed_user_emails' => [$this->user->email],
        ]);
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
    }

    private function startRun(): WorkflowRun
    {
        return $this->engine->start(
            $this->user,
            WorkflowDefinition::query()->sole(),
            'acme/widgets',
            18,
            'https://github.com/acme/widgets/issues/18',
        );
    }
}
