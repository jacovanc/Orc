<?php

namespace Tests\Feature\Workflow;

use App\Jobs\SendWorkflowAttentionEmail;
use App\Models\User;
use App\Models\WorkflowAttentionDelivery;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Notifications\WorkflowNeedsAttention;
use App\Services\WorkflowEngine;
use Database\Seeders\DevelopmentWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkflowAttentionEmailTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowEngine $engine;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DevelopmentWorkflowSeeder::class);
        $this->engine = app(WorkflowEngine::class);
        $this->user = User::factory()->create(['email' => 'operator@example.com']);
        config([
            'services.amp.enabled' => false,
            'workflow.notifications.email_enabled' => true,
            'workflow.notifications.queue' => 'workflow-notifications',
        ]);
        Queue::fake();
    }

    public function test_human_review_queues_one_durable_email_delivery_after_the_transition(): void
    {
        $run = $this->simulate($this->startVersion(1), 'success');
        $qa = $run->activeStageRun;
        $run = $this->simulate($run, 'pass');

        $this->assertSame('human_review', $run->currentStage->key);
        $delivery = WorkflowAttentionDelivery::query()->sole();
        $this->assertSame($run->id, $delivery->workflow_run_id);
        $this->assertSame($run->activeStageRun->id, $delivery->stage_run_id);
        $this->assertSame('pending', $delivery->status);
        Queue::assertPushed(
            SendWorkflowAttentionEmail::class,
            fn (SendWorkflowAttentionEmail $job) => $job->deliveryId === $delivery->id
                && $job->queue === 'workflow-notifications',
        );

        $this->engine->simulateAgentCompletion($run, $qa, 'pass', $this->user);

        $this->assertDatabaseCount('workflow_attention_deliveries', 1);
        Queue::assertPushed(SendWorkflowAttentionEmail::class, 1);
    }

    public function test_blocked_human_stage_is_notified_but_agent_and_terminal_stages_are_not(): void
    {
        $run = $this->simulate($this->startVersion(2), 'blocked');

        $this->assertSame('development_blocked', $run->currentStage->key);
        $this->assertSame(
            'development_blocked',
            WorkflowAttentionDelivery::query()->sole()->stageRun->stage->key,
        );
        $this->assertDatabaseCount('workflow_attention_deliveries', 1);
    }

    public function test_notifications_remain_inert_until_explicitly_enabled(): void
    {
        config(['workflow.notifications.email_enabled' => false]);

        $run = $this->simulate($this->startVersion(1), 'success');
        $run = $this->simulate($run, 'pass');

        $this->assertSame('human_review', $run->currentStage->key);
        $this->assertDatabaseCount('workflow_attention_deliveries', 0);
        Queue::assertNotPushed(SendWorkflowAttentionEmail::class);
    }

    public function test_job_sends_to_the_workflow_owner_and_records_success(): void
    {
        Notification::fake();
        $run = $this->simulate($this->simulate($this->startVersion(1), 'success'), 'pass');
        $delivery = WorkflowAttentionDelivery::query()->sole();

        (new SendWorkflowAttentionEmail($delivery->id))->handle();

        Notification::assertSentTo(
            $this->user,
            WorkflowNeedsAttention::class,
            fn (WorkflowNeedsAttention $notification) => $notification->run->is($run)
                && $notification->attempt->is($run->activeStageRun),
        );
        $delivery->refresh();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->sent_at);

        (new SendWorkflowAttentionEmail($delivery->id))->handle();
        Notification::assertSentToTimes($this->user, WorkflowNeedsAttention::class, 1);
    }

    public function test_job_skips_a_human_attempt_that_no_longer_needs_attention(): void
    {
        Notification::fake();
        $run = $this->simulate($this->simulate($this->startVersion(1), 'success'), 'pass');
        $delivery = WorkflowAttentionDelivery::query()->sole();

        $this->engine->completeHumanAction($run, $run->activeStageRun, 'approve', $this->user);
        (new SendWorkflowAttentionEmail($delivery->id))->handle();

        Notification::assertNothingSent();
        $this->assertSame('skipped', $delivery->fresh()->status);
    }

    public function test_email_contains_orc_github_and_pull_request_links_without_claiming_approval(): void
    {
        $run = $this->simulate($this->startVersion(1), 'success');
        $run->stageRuns()->where('attempt_number', 1)->update([
            'github_pull_request_number' => 17,
            'github_pull_request_url' => 'https://github.com/acme/widgets/pull/17',
        ]);
        $run = $this->simulate($run, 'pass');
        $notification = new WorkflowNeedsAttention($run, $run->activeStageRun);
        $mail = $notification->toMail($this->user);
        $intro = implode("\n", array_map('strval', $mail->introLines));
        $outro = implode("\n", array_map('strval', $mail->outroLines));
        $lines = $intro."\n".$outro;

        $this->assertSame('Orc action required: Human Review · Widgets', $mail->subject);
        $this->assertSame(route('workflows.show', $run), $mail->actionUrl);
        $this->assertStringContainsString('acme/widgets#42', $lines);
        $this->assertStringContainsString('https://github.com/acme/widgets/issues/42', $lines);
        $this->assertStringContainsString('https://github.com/acme/widgets/pull/17', $lines);
        $this->assertStringContainsString('will not take the human action automatically', $outro);
        $this->assertStringNotContainsString('approved', strtolower($lines));
    }

    private function startVersion(int $version): WorkflowRun
    {
        $definition = WorkflowDefinition::query()->where('version', $version)->sole();
        $project = $this->workflowProject($this->user, 'acme/widgets');

        return $this->engine->start(
            $this->user,
            $definition,
            $project,
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
