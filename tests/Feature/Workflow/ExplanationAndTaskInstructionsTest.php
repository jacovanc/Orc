<?php

namespace Tests\Feature\Workflow;

use App\Domain\Workflow\CompletionSource;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\AmpLaunchPayload;
use App\Services\StageTaskInstructionService;
use App\Services\WorkflowEngine;
use Database\Seeders\DevelopmentWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExplanationAndTaskInstructionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DevelopmentWorkflowSeeder::class);
        config(['services.amp.enabled' => true]);
        Queue::fake();
        $this->owner = User::factory()->create(['can_trigger_amp' => true]);
        $this->project = $this->workflowProject($this->owner, 'acme/widgets');
        $this->project->currentConnection->update([
            'controller_key' => 'orc-stage-launch-v13',
            'controller_protocol_version' => 3,
        ]);
        $this->engine = app(WorkflowEngine::class);
    }

    public function test_version_five_adds_repeatable_read_only_explanation_loop_without_mutating_v4(): void
    {
        $v4 = WorkflowDefinition::query()->where('version', 4)->with(['stages', 'transitions'])->sole();
        $v5 = WorkflowDefinition::query()->where('version', 5)->with(['stages', 'transitions'])->sole();

        $this->assertSame(8, $v4->stages->count());
        $this->assertNull($v4->stages->firstWhere('key', 'explanation'));
        $this->assertSame(
            ['development', 'qa', 'development_blocked', 'qa_blocked', 'human_review', 'explanation', 'merge', 'merge_blocked', 'done'],
            $v5->stages->pluck('key')->all(),
        );
        $this->assertSame(['request_changes', 'ask_questions', 'approve'], $v5->stages->firstWhere('key', 'human_review')->config['outcomes']);
        $this->assertSame(16, $v5->transitions->count());
        $this->assertSame(
            ['blocked', 'completed'],
            $v5->transitions->where('from_stage_id', $v5->stages->firstWhere('key', 'explanation')->id)->pluck('outcome')->sort()->values()->all(),
        );
    }

    public function test_human_questions_launch_exact_pr_explanation_and_both_outcomes_return_to_review(): void
    {
        foreach (['completed', 'blocked'] as $outcome) {
            $run = $this->toHumanReview($this->startRun());
            $reviewAttempt = $run->activeStageRun;
            $run = $this->engine->completeHumanAction($run, $reviewAttempt, 'ask_questions', $this->owner);
            $run->load('activeStageRun.ampLaunch');
            $explanation = $run->activeStageRun;
            $payload = app(AmpLaunchPayload::class)->make($explanation->ampLaunch);

            $this->assertSame('real_explanation', $payload['agent_mode']);
            $this->assertSame('https://github.com/acme/widgets/pull/17', $payload['prior_pull_request_url']);
            $this->assertSame(['completed', 'blocked'], $payload['allowed_outcomes']);
            $this->assertNotNull($payload['human_review_entered_at']);
            $this->assertSame(
                $run->stageInstructions->firstWhere('agent_mode', 'real_explanation')->body,
                $payload['task_instruction_body'],
            );
            $this->assertSame(2, $payload['task_instruction_version']);
            $this->assertStringContainsString("reply directly in that comment's existing review thread", $payload['task_instruction_body']);
            $this->assertStringContainsString('/comments/{comment_id}/replies', $payload['task_instruction_body']);
            $this->assertStringContainsString('Do not combine inline answers', $payload['task_instruction_body']);
            $this->assertStringContainsString('links to the individual answers without repeating their text', $payload['task_instruction_body']);

            $thread = 'T-'.str_pad((string) $explanation->id, 36, '0', STR_PAD_LEFT);
            $this->ampCallback($explanation->ampLaunch, 'launch.claim');
            $this->ampCallback($explanation->ampLaunch, 'launch.acknowledged', ['thread_id' => $thread]);
            $reportUrl = "https://github.com/acme/widgets/pull/17#issuecomment-{$explanation->id}";
            $this->postCapability($explanation->ampLaunch->capability_secret, [
                'action' => 'report',
                'thread_id' => $thread,
                'github_report_url' => $reportUrl,
                'github_report_comment_id' => $explanation->id,
                'github_report_kind' => $outcome,
            ])->assertOk();
            $this->postCapability($explanation->ampLaunch->capability_secret, [
                'action' => 'complete',
                'thread_id' => $thread,
                'outcome' => $outcome,
                'github_report_url' => $reportUrl,
            ])->assertOk()->assertJsonPath('current_stage', 'human_review');

            $run->refresh()->load(['activeStageRun', 'stageRuns']);
            $this->assertSame('human_review', $run->currentStage->key);
            $this->assertSame($reviewAttempt->attempt_number + 2, $run->activeStageRun->attempt_number);

            if ($outcome === 'completed') {
                $secondExplanation = $this->engine->completeHumanAction(
                    $run,
                    $run->activeStageRun,
                    'ask_questions',
                    $this->owner,
                );
                $this->assertSame('explanation', $secondExplanation->currentStage->key);
                $this->assertSame($reviewAttempt->attempt_number + 3, $secondExplanation->activeStageRun->attempt_number);
            }
        }
    }

    public function test_explanation_rejects_issue_or_foreign_pr_report_evidence(): void
    {
        $run = $this->toHumanReview($this->startRun());
        $run = $this->engine->completeHumanAction($run, $run->activeStageRun, 'ask_questions', $this->owner);
        $run->load('activeStageRun.ampLaunch');
        $launch = $run->activeStageRun->ampLaunch;
        $thread = 'T-'.str_repeat('8', 36);
        $this->ampCallback($launch, 'launch.claim');
        $this->ampCallback($launch, 'launch.acknowledged', ['thread_id' => $thread]);

        foreach (['https://github.com/acme/widgets/issues/42#issuecomment-801', 'https://github.com/acme/widgets/pull/99#issuecomment-802'] as $index => $url) {
            $this->postCapability($launch->capability_secret, [
                'action' => 'report',
                'thread_id' => $thread,
                'github_report_url' => $url,
                'github_report_comment_id' => 801 + $index,
                'github_report_kind' => 'completed',
            ])->assertAccepted()->assertJsonPath('accepted', false);
        }
    }

    public function test_task_body_edit_is_owner_scoped_escaped_and_snapshotted_for_new_runs_and_retries(): void
    {
        $firstBody = "Implement only docs.\n<script>alert('no')</script>";
        $this->actingAs($this->owner)->put(route('projects.stage-instructions.update', $this->project), [
            'agent_mode' => 'real_development',
            'body' => $firstBody,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $run = $this->startRun();
        $this->assertSame(
            ['real_development', 'real_explanation', 'real_merge', 'real_qa'],
            $run->stageInstructions->pluck('agent_mode')->sort()->values()->all(),
        );
        $payload = app(AmpLaunchPayload::class)->make($run->activeStageRun->ampLaunch);
        $this->assertSame($firstBody, $payload['task_instruction_body']);
        $this->assertSame(2, $payload['task_instruction_version']);

        $secondBody = 'A later task body for future workflows only.';
        $this->actingAs($this->owner)->put(route('projects.stage-instructions.update', $this->project), [
            'agent_mode' => 'real_development',
            'body' => $secondBody,
        ])->assertRedirect();

        $development = $run->activeStageRun;
        $development->update(['amp_thread_id' => 'T-'.str_repeat('3', 36)]);
        $run = $this->engine->completeAttempt($run, $development, 'blocked', CompletionSource::AmpAgent, null, null, $development->amp_thread_id);
        $run = $this->engine->completeHumanAction($run, $run->activeStageRun, 'retry', $this->owner);
        $run->load('activeStageRun.ampLaunch');
        $retryPayload = app(AmpLaunchPayload::class)->make($run->activeStageRun->ampLaunch);
        $this->assertSame($firstBody, $retryPayload['task_instruction_body']);
        $this->assertSame(2, $retryPayload['task_instruction_version']);

        $newRun = $this->startRun();
        $newPayload = app(AmpLaunchPayload::class)->make($newRun->activeStageRun->ampLaunch);
        $this->assertSame($secondBody, $newPayload['task_instruction_body']);
        $this->assertSame(3, $newPayload['task_instruction_version']);
        $this->assertStringNotContainsString('launch-test-secret', json_encode($newPayload, JSON_THROW_ON_ERROR));

        $this->actingAs($this->owner)->get(route('projects.settings', $this->project))
            ->assertOk()
            ->assertSee('Agent task bodies')
            ->assertSee('Task body v3')
            ->assertSee($secondBody)
            ->assertDontSee('<script>', false);
        $this->actingAs($this->owner)->get(route('workflows.show', $run))
            ->assertOk()->assertSee('Run task body snapshot v2')->assertSee($firstBody);

        $other = User::factory()->create(['can_trigger_amp' => true]);
        $this->actingAs($other)->put(route('projects.stage-instructions.update', $this->project), [
            'agent_mode' => 'real_development', 'body' => 'steal',
        ])->assertNotFound();
        $this->owner->update(['can_trigger_amp' => false]);
        $this->actingAs($this->owner)->put(route('projects.stage-instructions.update', $this->project), [
            'agent_mode' => 'real_development', 'body' => 'forbidden',
        ])->assertForbidden();
    }

    public function test_task_body_validation_rejects_blank_oversize_and_unknown_modes(): void
    {
        foreach ([
            ['agent_mode' => 'real_development', 'body' => ''],
            ['agent_mode' => 'real_development', 'body' => str_repeat('x', StageTaskInstructionService::MAX_BODY_LENGTH + 1)],
            ['agent_mode' => 'foreign_mode', 'body' => 'hello'],
        ] as $payload) {
            $this->actingAs($this->owner)
                ->from(route('projects.settings', $this->project))
                ->put(route('projects.stage-instructions.update', $this->project), $payload)
                ->assertRedirect(route('projects.settings', $this->project))
                ->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('project_stage_instruction_versions', 0);
    }

    public function test_first_explanation_override_follows_the_versioned_default(): void
    {
        $current = app(StageTaskInstructionService::class)->current($this->project, 'real_explanation');
        $this->assertSame(2, $current['version']);
        $this->assertSame('default', $current['source']);
        $this->actingAs($this->owner)->get(route('projects.settings', $this->project))
            ->assertOk()
            ->assertSee('Task body v2')
            ->assertSee('reply directly in that comment&#039;s existing review thread', false);

        $this->actingAs($this->owner)->put(route('projects.stage-instructions.update', $this->project), [
            'agent_mode' => 'real_explanation',
            'body' => 'Use one focused inline reply per review question.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $configured = app(StageTaskInstructionService::class)->current($this->project, 'real_explanation');
        $this->assertSame(3, $configured['version']);
        $this->assertSame('project', $configured['source']);
    }

    private function startRun(): WorkflowRun
    {
        return $this->engine->start(
            $this->owner,
            WorkflowDefinition::query()->where('version', 5)->sole(),
            $this->project,
            42,
            'https://github.com/acme/widgets/issues/42',
        )->load(['currentStage', 'activeStageRun.ampLaunch', 'stageInstructions']);
    }

    private function toHumanReview(WorkflowRun $run): WorkflowRun
    {
        $development = $run->activeStageRun;
        $development->update([
            'amp_thread_id' => 'T-'.str_pad((string) $development->id, 36, '1', STR_PAD_LEFT),
            'github_branch' => app(AmpLaunchPayload::class)->make($development->ampLaunch)['expected_branch'],
            'github_pull_request_number' => 17,
            'github_pull_request_url' => 'https://github.com/acme/widgets/pull/17',
        ]);
        $run = $this->engine->completeAttempt($run, $development, 'success', CompletionSource::AmpAgent, null, null, $development->amp_thread_id);
        $run->load('activeStageRun');
        $qa = $run->activeStageRun;
        $qa->update(['amp_thread_id' => 'T-'.str_pad((string) $qa->id, 36, '2', STR_PAD_LEFT)]);
        $run = $this->engine->completeAttempt($run, $qa, 'pass', CompletionSource::AmpAgent, null, null, $qa->amp_thread_id);

        return $run->load(['currentStage', 'activeStageRun', 'stageInstructions']);
    }

    private function ampCallback($launch, string $type, array $extra = []): array
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

        return $this->engine->handleAmpCallback($payload, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)));
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
}
