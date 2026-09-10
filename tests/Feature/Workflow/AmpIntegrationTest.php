<?php

namespace Tests\Feature\Workflow;

use App\Domain\Workflow\AmpDeliveryStatus;
use App\Domain\Workflow\AmpIntegrationEventStatus;
use App\Domain\Workflow\AmpLaunchStatus;
use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Domain\Workflow\StageRunStatus;
use App\Domain\Workflow\WorkflowStatus;
use App\Jobs\DeliverAmpCancellation;
use App\Jobs\DeliverAmpLaunch;
use App\Jobs\ReconcileAmpLaunch;
use App\Models\AmpIntegrationEvent;
use App\Models\AmpLaunch;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\AmpConnectionUrlGuard;
use App\Services\AmpLaunchPayload;
use App\Services\AmpProjectConnectionService;
use App\Services\AmpSignature;
use App\Services\WorkflowEngine;
use Database\Seeders\DevelopmentWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AmpIntegrationTest extends TestCase
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
            'services.amp.launch_webhook_url' => 'https://amp.test/webhook',
            'services.amp.launch_signing_secret' => 'launch-test-secret',
            'services.amp.callback_signing_secret' => 'callback-test-secret',
            'services.amp.signature_tolerance_seconds' => 300,
        ]);
        Queue::fake();
    }

    public function test_agent_attempt_queues_one_durable_launch_with_stable_keys(): void
    {
        $run = $this->startRun();
        $launch = $run->activeStageRun->ampLaunch;

        $this->assertNotNull($launch);
        $this->assertTrue(Str::isUuid($launch->event_id));
        $this->assertTrue(Str::isUuid($launch->idempotency_key));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $launch->report_nonce);
        $this->assertNotNull($launch->payload_body);
        $this->assertSame(hash('sha256', $launch->payload_body), $launch->payload_hash);
        $this->assertNotSame(
            $launch->payload_body,
            DB::table('amp_launches')->where('id', $launch->id)->value('payload_body'),
        );
        $this->assertSame(AmpDeliveryStatus::Pending, $launch->delivery_status);
        $this->assertSame(AmpLaunchStatus::Pending, $launch->launch_status);
        Queue::assertPushed(DeliverAmpLaunch::class, 1);
        $this->assertSame(1, $run->events()->where('type', 'stage.launch_queued')->count());
    }

    public function test_amp_launches_require_immutable_account_permission_and_owned_verified_project(): void
    {
        $this->user->update(['can_trigger_amp' => false]);

        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('account is not authorized');
        $this->startRun();
    }

    public function test_simulation_cannot_bypass_an_enabled_amp_integration(): void
    {
        $run = $this->startRun();

        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('simulation is disabled');

        $this->engine->simulateAgentCompletion($run, $run->activeStageRun, 'success', $this->user);
    }

    public function test_launch_delivery_retry_reuses_exact_body_event_and_idempotency_keys(): void
    {
        $launch = $this->startRun()->activeStageRun->ampLaunch;
        $attempt = 0;
        $requests = [];
        Http::fake(function ($request) use (&$attempt, &$requests) {
            $requests[] = $request;

            if (++$attempt === 1) {
                throw new ConnectionException('timeout');
            }

            return Http::response('', 202);
        });

        $job = new DeliverAmpLaunch($launch->id);
        try {
            $job->handle(app(AmpLaunchPayload::class), app(AmpSignature::class), $this->engine, app(AmpConnectionUrlGuard::class));
            $this->fail('The first network attempt should be retried by the queue.');
        } catch (RuntimeException) {
            // Expected transient delivery failure.
        }
        $job->handle(app(AmpLaunchPayload::class), app(AmpSignature::class), $this->engine, app(AmpConnectionUrlGuard::class));

        $this->assertCount(2, $requests);
        $this->assertSame($requests[0]->body(), $requests[1]->body());
        $this->assertSame($launch->event_id, $requests[0]->header('X-Orc-Event-Id')[0]);
        $this->assertSame($launch->idempotency_key, $requests[0]->header('Idempotency-Key')[0]);
        $this->assertSame(
            $requests[0]->header('Idempotency-Key')[0],
            $requests[1]->header('Idempotency-Key')[0],
        );
        $this->assertSame(AmpDeliveryStatus::Delivered, $launch->fresh()->delivery_status);
        $this->assertSame(AmpLaunchStatus::Pending, $launch->fresh()->launch_status);
        $this->assertSame(2, $launch->fresh()->delivery_attempts);
        $this->assertSame(hash('sha256', $requests[0]->body()), $launch->fresh()->payload_hash);
        $this->actingAs($this->user)
            ->get(route('workflows.show', $launch->stageRun->workflowRun))
            ->assertOk()
            ->assertSee('HTTP 202 does not mean an Orb was launched')
            ->assertSee('No agent is running yet')
            ->assertSee('waiting for controller')
            ->assertSee('Stage Activated');
    }

    public function test_signed_controller_callback_records_actual_owner_and_rejects_owner_change(): void
    {
        $run = $this->startRun();
        $launch = $run->activeStageRun->ampLaunch;
        $owner = 'T-'.str_repeat('7', 36);
        $this->postRawCallback($this->payload($launch, 'launch.claim', [
            'controller_thread_id' => $owner,
        ]))->assertOk();

        $connection = $launch->ampProjectConnection->fresh();
        $this->assertSame($owner, $connection->controller_thread_id);
        $this->assertNotNull($connection->controller_last_acknowledged_at);

        $this->postRawCallback($this->payload($launch, 'launch.acknowledged', [
            'controller_thread_id' => 'T-'.str_repeat('8', 36),
            'thread_id' => $this->threadId(1),
        ]))->assertConflict();
        $this->assertNull($launch->stageRun->fresh()->amp_thread_id);
        $this->assertSame($owner, $connection->fresh()->controller_thread_id);
    }

    public function test_retry_after_a_persisted_claim_replays_the_same_launch_instead_of_silently_stopping(): void
    {
        $launch = $this->startRun()->activeStageRun->ampLaunch;
        $requests = 0;
        Http::fake(function () use ($launch, &$requests) {
            $requests++;
            if ($requests === 1) {
                $this->ampCallback($launch, 'launch.claim');
                throw new ConnectionException('response lost after claim');
            }

            return Http::response('', 202);
        });

        $job = new DeliverAmpLaunch($launch->id);
        try {
            $job->handle(app(AmpLaunchPayload::class), app(AmpSignature::class), $this->engine, app(AmpConnectionUrlGuard::class));
        } catch (RuntimeException) {
            // The durable queue retries this exact launch.
        }
        $job->handle(app(AmpLaunchPayload::class), app(AmpSignature::class), $this->engine, app(AmpConnectionUrlGuard::class));

        $this->assertSame(2, $requests);
        $this->assertSame(AmpLaunchStatus::Claimed, $launch->fresh()->launch_status);
        $this->assertSame(AmpDeliveryStatus::Delivered, $launch->fresh()->delivery_status);
    }

    public function test_signed_callback_middleware_rejects_invalid_and_expired_signatures(): void
    {
        $launch = $this->startRun()->activeStageRun->ampLaunch;
        $payload = $this->payload($launch, 'launch.claim');

        $this->postRawCallback($payload, signature: 'sha256=invalid')->assertUnauthorized();
        $this->postRawCallback($payload, timestamp: now()->subMinutes(10)->timestamp)->assertUnauthorized();

        $this->assertDatabaseCount('amp_integration_events', 0);
        $this->assertSame(AmpLaunchStatus::Pending, $launch->fresh()->launch_status);
    }

    public function test_persistent_claim_and_callback_event_dedup_prevent_duplicate_launches(): void
    {
        $launch = $this->startRun()->activeStageRun->ampLaunch;
        $firstPayload = $this->payload($launch, 'launch.claim');

        $first = $this->postRawCallback($firstPayload)->assertOk()->json();
        $sameEvent = $this->postRawCallback($firstPayload)->assertOk()->json();
        $secondEvent = $this->postRawCallback($this->payload($launch, 'launch.claim'))->assertOk()->json();

        $this->assertTrue($first['launch']);
        $this->assertSame($first, $sameEvent);
        $this->assertFalse($secondEvent['launch']);
        $this->assertSame('duplicate_claim', $secondEvent['disposition']);
        $this->assertDatabaseCount('amp_integration_events', 2);
        $this->assertSame(1, $launch->stageRun->workflowRun->events()->where('type', 'stage.launch_claimed')->count());
    }

    public function test_completion_may_arrive_before_launch_http_response_and_starts_fresh_qa_launch(): void
    {
        $run = $this->startRun();
        $development = $run->activeStageRun;
        $launch = $development->ampLaunch;
        $launch->update(['delivery_status' => AmpDeliveryStatus::Delivering]);

        $this->ampCallback($launch, 'launch.claim');
        $this->attestReport(
            $launch,
            $this->threadId(1),
            'https://github.com/acme/widgets/issues/42#issuecomment-101',
            101,
        );
        $this->ampCallback($launch, 'stage.completed', [
            'thread_id' => $this->threadId(1),
            'outcome' => 'success',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-101',
        ]);

        $run->refresh()->load(['currentStage', 'activeStageRun.ampLaunch']);
        $this->assertSame('qa', $run->currentStage->key);
        $this->assertSame($this->threadId(1), $development->fresh()->amp_thread_id);
        $this->assertSame(AmpLaunchStatus::Completed, $launch->fresh()->launch_status);
        $this->assertNotSame($launch->idempotency_key, $run->activeStageRun->ampLaunch->idempotency_key);
        $this->assertNotSame($launch->event_id, $run->activeStageRun->ampLaunch->event_id);
        Queue::assertPushed(DeliverAmpLaunch::class, 2);

        Http::fake();
        (new DeliverAmpLaunch($launch->id))->handle(
            app(AmpLaunchPayload::class),
            app(AmpSignature::class),
            $this->engine,
            app(AmpConnectionUrlGuard::class),
        );
        Http::assertNothingSent();
        $this->assertSame(AmpDeliveryStatus::Delivered, $launch->fresh()->delivery_status);
    }

    public function test_amp_development_and_qa_callbacks_reach_human_review_with_distinct_threads(): void
    {
        $run = $this->startRun();
        $developmentLaunch = $run->activeStageRun->ampLaunch;
        $this->ampCallback($developmentLaunch, 'launch.claim');
        $this->ampCallback($developmentLaunch, 'launch.acknowledged', ['thread_id' => $this->threadId(1)]);
        $this->attestReport(
            $developmentLaunch,
            $this->threadId(1),
            'https://github.com/acme/widgets/issues/42#issuecomment-101',
            101,
        );
        $this->ampCallback($developmentLaunch, 'stage.completed', [
            'thread_id' => $this->threadId(1),
            'outcome' => 'success',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-101',
        ]);

        $run->refresh()->load('activeStageRun.ampLaunch');
        $qaLaunch = $run->activeStageRun->ampLaunch;
        $this->ampCallback($qaLaunch, 'launch.claim');
        $this->ampCallback($qaLaunch, 'launch.acknowledged', ['thread_id' => $this->threadId(2)]);
        $this->attestReport(
            $qaLaunch,
            $this->threadId(2),
            'https://github.com/acme/widgets/issues/42#issuecomment-102',
            102,
        );
        $result = $this->ampCallback($qaLaunch, 'stage.completed', [
            'thread_id' => $this->threadId(2),
            'outcome' => 'pass',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-102',
        ]);

        $run->refresh()->load(['currentStage', 'activeStageRun']);
        $this->assertTrue($result['accepted']);
        $this->assertSame('human_review', $run->currentStage->key);
        $this->assertSame(StageRunStatus::Waiting, $run->activeStageRun->status);
        $this->assertSame(
            [$this->threadId(1), $this->threadId(2)],
            $run->stageRuns()->whereNotNull('amp_thread_id')->pluck('amp_thread_id')->all(),
        );
        $this->assertSame(2, $run->events()->where('type', 'stage.amp_launched')->count());
    }

    public function test_foreign_thread_conflicting_and_reused_event_callbacks_are_rejected(): void
    {
        $run = $this->startRun();
        $launch = $run->activeStageRun->ampLaunch;
        $this->ampCallback($launch, 'launch.claim');
        $this->ampCallback($launch, 'launch.acknowledged', ['thread_id' => $this->threadId(1)]);

        $foreign = $this->ampCallback($launch, 'stage.completed', [
            'thread_id' => $this->threadId(2),
            'outcome' => 'success',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-101',
        ]);
        $this->assertFalse($foreign['accepted']);
        $this->assertStringContainsString('different Amp thread', $foreign['reason']);
        $this->assertSame(StageRunStatus::Running, $run->activeStageRun->fresh()->status);

        $payload = $this->payload($launch, 'launch.acknowledged', ['thread_id' => $this->threadId(1)]);
        $this->postRawCallback($payload)->assertOk();
        $payload['thread_id'] = $this->threadId(3);
        $this->postRawCallback($payload)->assertConflict();
    }

    public function test_completion_rejects_reports_from_another_github_issue(): void
    {
        $run = $this->startRun();
        $launch = $run->activeStageRun->ampLaunch;
        $this->ampCallback($launch, 'launch.claim');

        $result = $this->ampCallback($launch, 'stage.reported', [
            'thread_id' => $this->threadId(1),
            'github_report_url' => 'https://github.com/acme/widgets/issues/420#issuecomment-101',
            'github_report_comment_id' => 101,
            'report_nonce' => $launch->report_nonce,
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('this workflow issue', $result['reason']);
        $this->assertSame(StageRunStatus::Running, $run->activeStageRun->fresh()->status);
    }

    public function test_completion_requires_nonce_bound_durable_report_attestation(): void
    {
        $run = $this->startRun();
        $launch = $run->activeStageRun->ampLaunch;
        $this->ampCallback($launch, 'launch.claim');

        $completion = $this->ampCallback($launch, 'stage.completed', [
            'thread_id' => $this->threadId(1),
            'outcome' => 'success',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-101',
        ]);
        $this->assertFalse($completion['accepted']);
        $this->assertStringContainsString('attest its GitHub report', $completion['reason']);

        $wrongNonce = $this->ampCallback($launch, 'stage.reported', [
            'thread_id' => $this->threadId(1),
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-101',
            'github_report_comment_id' => 101,
            'report_nonce' => str_repeat('0', 64),
        ]);
        $this->assertFalse($wrongNonce['accepted']);
        $this->assertStringContainsString('attestation is invalid', $wrongNonce['reason']);

        $first = $this->attestReport(
            $launch,
            $this->threadId(1),
            'https://github.com/acme/widgets/issues/42#issuecomment-101',
            101,
        );
        $duplicate = $this->attestReport(
            $launch,
            $this->threadId(1),
            'https://github.com/acme/widgets/issues/42#issuecomment-101',
            101,
        );

        $this->assertSame('reported', $first['disposition']);
        $this->assertSame('already_reported', $duplicate['disposition']);
        $this->assertSame(101, $launch->stageRun->fresh()->github_report_comment_id);
        $this->assertSame(1, $run->events()->where('type', 'stage.reported')->count());
    }

    public function test_signed_report_attestation_callback_is_validated_and_persisted(): void
    {
        $run = $this->startRun();
        $launch = $run->activeStageRun->ampLaunch;
        $this->ampCallback($launch, 'launch.claim');
        $payload = $this->payload($launch, 'stage.reported', [
            'thread_id' => $this->threadId(1),
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-201',
            'github_report_comment_id' => 201,
            'report_nonce' => $launch->report_nonce,
        ]);

        $this->postRawCallback($payload)
            ->assertOk()
            ->assertJson([
                'accepted' => true,
                'disposition' => 'reported',
            ]);

        $attempt = $run->activeStageRun->fresh();
        $this->assertSame(201, $attempt->github_report_comment_id);
        $this->assertSame(
            'https://github.com/acme/widgets/issues/42#issuecomment-201',
            $attempt->github_report_url,
        );
    }

    public function test_cancelled_and_stale_attempt_callbacks_are_persistently_rejected(): void
    {
        $run = $this->startRun();
        $launch = $run->activeStageRun->ampLaunch;
        $this->ampCallback($launch, 'launch.claim');
        $this->engine->cancel($run, $this->user);

        $result = $this->ampCallback($launch, 'stage.completed', [
            'thread_id' => $this->threadId(1),
            'outcome' => 'success',
            'github_report_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-101',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('failed', $result['reason']);
        $this->assertSame(WorkflowStatus::Cancelled, $run->fresh()->status);
        $this->assertSame(AmpIntegrationEventStatus::Rejected, AmpIntegrationEvent::query()->latest('id')->first()->status);
    }

    public function test_cancelling_a_bound_attempt_queues_and_delivers_an_exact_signed_thread_cancel(): void
    {
        $run = $this->startRun();
        $launch = $run->activeStageRun->ampLaunch;
        $thread = $this->threadId(8);
        $this->ampCallback($launch, 'launch.claim');
        $this->ampCallback($launch, 'launch.acknowledged', ['thread_id' => $thread]);

        $this->engine->cancel($run, $this->user);
        Queue::assertPushed(DeliverAmpCancellation::class, 1);
        Queue::assertPushed(ReconcileAmpLaunch::class, 1);

        Http::fake(['*' => Http::response('', 202)]);
        (new DeliverAmpCancellation($launch->id))->handle(app(AmpSignature::class), app(AmpConnectionUrlGuard::class));

        Http::assertSent(function ($request) use ($launch, $thread) {
            $payload = $request->data();
            $timestamp = (int) $request->header('X-Orc-Timestamp')[0];

            return $payload['command'] === 'cancel'
                && $payload['thread_id'] === $thread
                && $request->header('X-Orc-Event-Id')[0] === $launch->fresh()->cancellation_event_id
                && app(AmpSignature::class)->verify(
                    $request->body(),
                    $launch->fresh()->cancellation_event_id,
                    $timestamp,
                    $request->header('X-Orc-Signature')[0],
                    config('services.amp.launch_signing_secret'),
                );
        });
        $this->assertSame('delivered', $launch->fresh()->cancellation_status);
    }

    public function test_agent_end_failure_closes_attempt_and_workflow_without_transition(): void
    {
        $run = $this->startRun();
        $launch = $run->activeStageRun->ampLaunch;
        $this->ampCallback($launch, 'launch.claim');

        $result = $this->ampCallback($launch, 'stage.failed', [
            'thread_id' => $this->threadId(1),
            'reason' => 'Guarded agent.end exhausted its corrective turn.',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(WorkflowStatus::Failed, $run->fresh()->status);
        $this->assertSame(StageRunStatus::Failed, $run->activeStageRun->fresh()->status);
        $this->assertNull($run->fresh()->activeStageRun);
        $this->assertSame(AmpLaunchStatus::Failed, $launch->fresh()->launch_status);
    }

    public function test_permanent_delivery_failure_and_ambiguous_network_outcome_are_distinct(): void
    {
        $failedRun = $this->startRun();
        $failedLaunch = $failedRun->activeStageRun->ampLaunch;
        Http::fake(['*' => Http::response('no', 401)]);
        (new DeliverAmpLaunch($failedLaunch->id))->handle(
            app(AmpLaunchPayload::class),
            app(AmpSignature::class),
            $this->engine,
            app(AmpConnectionUrlGuard::class),
        );

        $this->assertSame(AmpDeliveryStatus::Failed, $failedLaunch->fresh()->delivery_status);
        $this->assertSame(AmpLaunchStatus::Failed, $failedLaunch->fresh()->launch_status);
        $this->assertSame(WorkflowStatus::Failed, $failedRun->fresh()->status);

        $ambiguousRun = $this->startRun();
        $ambiguousLaunch = $ambiguousRun->activeStageRun->ampLaunch;
        (new DeliverAmpLaunch($ambiguousLaunch->id))->failed(new ConnectionException('timed out'));

        $this->assertSame(AmpDeliveryStatus::Ambiguous, $ambiguousLaunch->fresh()->delivery_status);
        $this->assertSame(AmpLaunchStatus::Ambiguous, $ambiguousLaunch->fresh()->launch_status);
        $this->assertSame(WorkflowStatus::Running, $ambiguousRun->fresh()->status);

        Http::fake();
        (new DeliverAmpLaunch($ambiguousLaunch->id))->handle(
            app(AmpLaunchPayload::class),
            app(AmpSignature::class),
            $this->engine,
            app(AmpConnectionUrlGuard::class),
        );
        Http::assertNothingSent();
    }

    public function test_unavailable_webhook_fails_launch_and_connection_while_a_concurrent_refresh_is_retried(): void
    {
        $failedRun = $this->startRun();
        $failedLaunch = $failedRun->activeStageRun->ampLaunch;
        Http::fake(['*' => Http::response('gone', 404)]);
        (new DeliverAmpLaunch($failedLaunch->id))->handle(
            app(AmpLaunchPayload::class),
            app(AmpSignature::class),
            $this->engine,
            app(AmpConnectionUrlGuard::class),
            app(AmpProjectConnectionService::class),
        );

        $this->assertSame(AmpDeliveryStatus::Failed, $failedLaunch->fresh()->delivery_status);
        $this->assertSame(AmpLaunchStatus::Failed, $failedLaunch->fresh()->launch_status);
        $this->assertSame(WorkflowStatus::Failed, $failedRun->fresh()->status);
        $this->assertSame('failed', $failedLaunch->ampProjectConnection->fresh()->status);
        $this->assertSame('webhook_unavailable', $failedLaunch->ampProjectConnection->fresh()->last_error_code);
        $this->assertStringContainsString('cause is unknown', $failedLaunch->fresh()->last_error_message);
        $this->assertStringContainsString('No Orb was launched', $failedLaunch->fresh()->last_error_message);

        $failedLaunch->ampProjectConnection->update([
            'status' => 'verified',
            'verified_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ]);
        $racingRun = $this->startRun();
        $racingLaunch = $racingRun->activeStageRun->ampLaunch;
        $connections = $this->mock(AmpProjectConnectionService::class);
        $connections->shouldReceive('markWebhookUnavailable')->once()->andReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refreshed Amp controller webhook');
        (new DeliverAmpLaunch($racingLaunch->id))->handle(
            app(AmpLaunchPayload::class),
            app(AmpSignature::class),
            $this->engine,
            app(AmpConnectionUrlGuard::class),
            $connections,
        );
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

    private function ampCallback(AmpLaunch $launch, string $type, array $extra = []): array
    {
        $payload = $this->payload($launch, $type, $extra);
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->engine->handleAmpCallback($payload, hash('sha256', $body));
    }

    private function attestReport(
        AmpLaunch $launch,
        string $threadId,
        string $reportUrl,
        int $commentId,
    ): array {
        return $this->ampCallback($launch, 'stage.reported', [
            'thread_id' => $threadId,
            'github_report_url' => $reportUrl,
            'github_report_comment_id' => $commentId,
            'report_nonce' => $launch->report_nonce,
        ]);
    }

    private function payload(AmpLaunch $launch, string $type, array $extra = []): array
    {
        return [
            'schema_version' => 1,
            'event_id' => (string) Str::uuid(),
            'type' => $type,
            'occurred_at' => now()->toISOString(),
            'idempotency_key' => $launch->idempotency_key,
            'launch_event_id' => $launch->event_id,
            'stage_run_id' => $launch->stage_run_id,
            'connection_id' => $launch->ampProjectConnection->public_id,
            'amp_project_id' => $launch->ampProjectConnection->amp_project_id,
            ...$extra,
        ];
    }

    private function postRawCallback(
        array $payload,
        ?int $timestamp = null,
        ?string $signature = null,
    ) {
        $launch = AmpLaunch::query()->where('idempotency_key', $payload['idempotency_key'])->firstOrFail();
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp ??= now()->timestamp;
        $signature ??= app(AmpSignature::class)->sign(
            $body,
            $payload['event_id'],
            $timestamp,
            $launch->ampProjectConnection->callback_signing_secret,
        );

        return $this->call(
            'POST',
            '/api/integrations/amp/connections/'.$launch->ampProjectConnection->public_id.'/callback',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_ORC_EVENT_ID' => $payload['event_id'],
                'HTTP_X_ORC_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_ORC_SIGNATURE' => $signature,
            ],
            $body,
        );
    }

    private function threadId(int $number): string
    {
        return 'T-'.str_pad((string) $number, 36, '0', STR_PAD_LEFT);
    }
}
