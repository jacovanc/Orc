<?php

namespace App\Services;

use App\Domain\Workflow\AmpDeliveryStatus;
use App\Domain\Workflow\AmpIntegrationEventStatus;
use App\Domain\Workflow\AmpLaunchStatus;
use App\Domain\Workflow\CompletionSource;
use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Domain\Workflow\StageRunStatus;
use App\Domain\Workflow\StageType;
use App\Domain\Workflow\WorkflowStatus;
use App\Jobs\DeliverAmpCancellation;
use App\Jobs\DeliverAmpLaunch;
use App\Jobs\ReconcileAmpLaunch;
use App\Models\AmpIntegrationEvent;
use App\Models\AmpLaunch;
use App\Models\AmpProjectConnection;
use App\Models\Project;
use App\Models\StageRun;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowEngine
{
    public function __construct(
        private readonly WorkflowAttentionNotifier $attentionNotifier,
        private readonly StageTaskInstructionService $taskInstructions,
    ) {}

    public function start(
        User $actor,
        WorkflowDefinition $definition,
        Project $project,
        int $githubIssueNumber,
        string $githubIssueUrl,
    ): WorkflowRun {
        $githubRepository = $project->github_repository;
        $this->assertGitHubIssue($githubRepository, $githubIssueNumber, $githubIssueUrl);
        $connection = $this->assertAmpTargetAuthorized($actor, $project);

        return DB::transaction(function () use (
            $actor,
            $definition,
            $project,
            $connection,
            $githubRepository,
            $githubIssueNumber,
            $githubIssueUrl,
        ) {
            $lockedDefinition = WorkflowDefinition::query()
                ->lockForUpdate()
                ->findOrFail($definition->getKey());

            if (! $lockedDefinition->is_active) {
                throw new WorkflowConflict('This workflow definition is not available for new runs.');
            }
            if (
                config('services.amp.enabled')
                && $lockedDefinition->version >= 4
                && (! $connection || $connection->controller_protocol_version < ($lockedDefinition->version >= 5 ? 3 : 2))
            ) {
                throw new WorkflowConflict($lockedDefinition->version >= 5
                    ? 'This workflow requires an Amp controller with Explanation and task-instruction support. Pair an updated project connection first.'
                    : 'This workflow requires an Amp controller with Merge support. Pair an updated project connection first.');
            }

            $firstStage = $lockedDefinition->stages()->orderBy('position')->first();
            if (! $firstStage) {
                throw new WorkflowConflict('This workflow definition has no stages.');
            }

            $now = now();
            $run = WorkflowRun::query()->create([
                'user_id' => $actor->getKey(),
                'project_id' => $project->getKey(),
                'amp_project_connection_id' => $connection?->getKey(),
                'workflow_definition_id' => $lockedDefinition->getKey(),
                'github_repository' => $githubRepository,
                'github_issue_number' => $githubIssueNumber,
                'github_issue_url' => $githubIssueUrl,
                'status' => $firstStage->type === StageType::Terminal
                    ? WorkflowStatus::Completed
                    : WorkflowStatus::Running,
                'current_stage_id' => $firstStage->getKey(),
                'started_at' => $now,
                'completed_at' => $firstStage->type === StageType::Terminal ? $now : null,
            ]);
            $this->taskInstructions->snapshotForRun($run, $lockedDefinition);

            $attempt = $this->createStageAttempt($run, $firstStage, 1, $now);
            $this->recordEvent($run, null, 'workflow.started', $actor, [
                'definition_key' => $lockedDefinition->key,
                'definition_version' => $lockedDefinition->version,
            ]);
            $this->recordEvent(
                $run,
                $attempt,
                $firstStage->type === StageType::Terminal ? 'stage.entered' : 'stage.started',
                $actor,
                ['stage_key' => $firstStage->key, 'attempt_number' => 1],
            );

            $this->queueAmpLaunch($run, $attempt);

            if ($firstStage->type === StageType::Terminal) {
                $this->recordEvent($run, $attempt, 'workflow.completed', $actor);
            }

            return $run->fresh(['currentStage', 'activeStageRun']);
        }, 3);
    }

    public function simulateAgentCompletion(
        WorkflowRun $run,
        StageRun $attempt,
        string $outcome,
        User $actor,
    ): WorkflowRun {
        if (config('services.amp.enabled')) {
            throw new WorkflowConflict('Agent simulation is disabled while the Amp integration is enabled.');
        }

        return $this->completeAttempt(
            $run,
            $attempt,
            $outcome,
            CompletionSource::AgentSimulation,
            $actor,
        );
    }

    public function completeHumanAction(
        WorkflowRun $run,
        StageRun $attempt,
        string $outcome,
        User $actor,
    ): WorkflowRun {
        return $this->completeAttempt(
            $run,
            $attempt,
            $outcome,
            CompletionSource::HumanAction,
            $actor,
        );
    }

    public function pause(
        WorkflowRun $run,
        StageRun $expectedAttempt,
        User $actor,
    ): WorkflowRun {
        return DB::transaction(function () use ($run, $expectedAttempt, $actor) {
            $lockedRun = WorkflowRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $attempt = StageRun::query()
                ->with('stage')
                ->lockForUpdate()
                ->findOrFail($expectedAttempt->getKey());

            $this->assertOwnedBy($lockedRun, $actor);
            $this->assertAttemptBelongsToRun($lockedRun, $attempt);

            $existing = WorkflowEvent::query()
                ->where('stage_run_id', $attempt->getKey())
                ->where('type', 'workflow.paused')
                ->first();
            if ($lockedRun->status === WorkflowStatus::Paused && $existing) {
                return $lockedRun->fresh(['currentStage', 'activeStageRun']);
            }
            if ($lockedRun->status !== WorkflowStatus::Running) {
                throw new WorkflowConflict('Only a running workflow can be paused.');
            }
            $this->assertCurrentActiveAttempt($lockedRun, $attempt);
            if ($attempt->stage->type !== StageType::Agent) {
                throw new WorkflowConflict('Only an active agent attempt can be stopped and paused.');
            }

            $now = now();
            $this->stopAttempt($attempt, $now, 'workflow_paused', 'The current workflow attempt was paused by its owner.');
            $lockedRun->forceFill(['status' => WorkflowStatus::Paused])->save();
            $this->recordEvent($lockedRun, $attempt, 'workflow.paused', $actor, [
                'stage_key' => $attempt->stage->key,
                'attempt_number' => $attempt->attempt_number,
            ]);

            return $lockedRun->fresh(['currentStage', 'activeStageRun']);
        }, 3);
    }

    public function overrideStage(
        WorkflowRun $run,
        StageRun $expectedAttempt,
        int $destinationStageId,
        User $actor,
    ): WorkflowRun {
        return DB::transaction(function () use ($run, $expectedAttempt, $destinationStageId, $actor) {
            $lockedRun = WorkflowRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $attempt = StageRun::query()
                ->with('stage')
                ->lockForUpdate()
                ->findOrFail($expectedAttempt->getKey());

            $this->assertOwnedBy($lockedRun, $actor);
            $this->assertAttemptBelongsToRun($lockedRun, $attempt);

            $existing = WorkflowEvent::query()
                ->where('stage_run_id', $attempt->getKey())
                ->where('type', 'workflow.stage_overridden')
                ->first();
            if ($existing) {
                if ((int) ($existing->metadata['to_stage_id'] ?? 0) === $destinationStageId) {
                    return $lockedRun->fresh(['currentStage', 'activeStageRun']);
                }

                throw new WorkflowConflict('This attempt was already moved to a different stage.');
            }

            if (! in_array($lockedRun->status, [
                WorkflowStatus::Running,
                WorkflowStatus::Paused,
                WorkflowStatus::Failed,
            ], true)) {
                throw new WorkflowConflict('Only a running, paused, or failed workflow can be moved.');
            }

            $destination = WorkflowStage::query()->lockForUpdate()->find($destinationStageId);
            if (! $destination || $destination->workflow_definition_id !== $lockedRun->workflow_definition_id) {
                throw new WorkflowConflict('The selected stage does not belong to this workflow definition.');
            }
            if ($destination->type === StageType::Terminal) {
                throw new WorkflowConflict('Move to Human Review and use its approval action to complete the workflow.');
            }
            if ($destination->type === StageType::Agent) {
                $this->assertRunAmpAuthorized($lockedRun, $actor);
                $this->assertAgentDestinationReady($lockedRun, $destination);
            }

            if ($lockedRun->status === WorkflowStatus::Running) {
                $this->assertCurrentActiveAttempt($lockedRun, $attempt);
                $this->stopAttempt(
                    $attempt,
                    now(),
                    'stage_overridden',
                    'The current attempt was stopped by a manual stage override.',
                );
            } else {
                $this->assertLatestClosedAttempt($lockedRun, $attempt);
            }

            $now = now();
            $nextAttemptNumber = ((int) StageRun::query()
                ->where('workflow_run_id', $lockedRun->getKey())
                ->max('attempt_number')) + 1;

            $lockedRun->forceFill([
                'status' => WorkflowStatus::Running,
                'current_stage_id' => $destination->getKey(),
                'completed_at' => null,
                'cancelled_at' => null,
                'failed_at' => null,
            ])->save();

            $nextAttempt = $this->createStageAttempt($lockedRun, $destination, $nextAttemptNumber, $now);
            $metadata = [
                'from_stage_id' => $attempt->workflow_stage_id,
                'from_stage_key' => $attempt->stage->key,
                'from_attempt_number' => $attempt->attempt_number,
                'to_stage_id' => $destination->getKey(),
                'to_stage_key' => $destination->key,
                'to_attempt_number' => $nextAttempt->attempt_number,
            ];
            $this->recordEvent($lockedRun, $attempt, 'workflow.stage_overridden', $actor, $metadata);
            $this->recordEvent($lockedRun, $nextAttempt, 'stage.started', $actor, [
                'stage_key' => $destination->key,
                'attempt_number' => $nextAttempt->attempt_number,
                'source' => 'manual_override',
                'from_stage_key' => $attempt->stage->key,
                'from_attempt_number' => $attempt->attempt_number,
            ]);
            $this->queueAmpLaunch($lockedRun, $nextAttempt);

            return $lockedRun->fresh(['currentStage', 'activeStageRun']);
        }, 3);
    }

    /**
     * The sole transition primitive. The run lock serializes competing completions,
     * while the expected attempt ID prevents a late callback completing a newer stage.
     */
    public function completeAttempt(
        WorkflowRun $run,
        StageRun $expectedAttempt,
        string $outcome,
        CompletionSource $source,
        ?User $actor,
        ?string $githubReferenceUrl = null,
        ?string $ampThreadId = null,
        ?string $ampEventId = null,
    ): WorkflowRun {
        return DB::transaction(function () use (
            $run,
            $expectedAttempt,
            $outcome,
            $source,
            $actor,
            $githubReferenceUrl,
            $ampThreadId,
            $ampEventId,
        ) {
            $lockedRun = WorkflowRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $attempt = StageRun::query()
                ->with('stage')
                ->lockForUpdate()
                ->findOrFail($expectedAttempt->getKey());

            if ($source === CompletionSource::AmpAgent) {
                if (! $ampThreadId || $attempt->amp_thread_id !== $ampThreadId) {
                    throw new WorkflowConflict('The Amp callback does not match the thread bound to this attempt.');
                }
            } elseif ($actor) {
                $this->assertOwnedBy($lockedRun, $actor);
            } else {
                throw new WorkflowConflict('A user actor is required for this completion source.');
            }

            if ($attempt->workflow_run_id !== $lockedRun->getKey()) {
                throw new WorkflowConflict('That stage attempt does not belong to this workflow.');
            }

            if ($lockedRun->status === WorkflowStatus::Cancelled) {
                throw new WorkflowConflict('Cancelled workflows cannot accept completions.');
            }

            $this->assertSourceMatchesStage($source, $attempt->stage);

            if ($attempt->status === StageRunStatus::Completed) {
                if ($attempt->outcome === $outcome) {
                    return $lockedRun->fresh(['currentStage', 'activeStageRun']);
                }

                throw new WorkflowConflict('This attempt was already completed with a different outcome.');
            }

            if ($lockedRun->status !== WorkflowStatus::Running) {
                throw new WorkflowConflict('This workflow is no longer running.');
            }

            if (
                $attempt->active_slot !== 1
                || $lockedRun->current_stage_id !== $attempt->workflow_stage_id
            ) {
                throw new WorkflowConflict('This stage attempt is stale. Refresh the workflow and try again.');
            }

            $transition = WorkflowTransition::query()
                ->with('toStage')
                ->where('workflow_definition_id', $lockedRun->workflow_definition_id)
                ->where('from_stage_id', $attempt->workflow_stage_id)
                ->where('outcome', $outcome)
                ->first();

            if (! $transition) {
                throw new WorkflowConflict("Outcome '{$outcome}' is not permitted for {$attempt->stage->name}.");
            }

            $destination = $transition->toStage;
            if ($destination->workflow_definition_id !== $lockedRun->workflow_definition_id) {
                throw new WorkflowConflict('The workflow definition contains an invalid cross-definition transition.');
            }
            if ($source === CompletionSource::HumanAction && $destination->type === StageType::Agent) {
                $this->assertRunAmpAuthorized($lockedRun, $actor);
            }
            if ($destination->type === StageType::Agent) {
                $this->assertAgentDestinationReady($lockedRun, $destination);
            }

            $now = now();
            $attempt->forceFill([
                'status' => StageRunStatus::Completed,
                'outcome' => $outcome,
                'amp_event_id' => $ampEventId ?: $attempt->amp_event_id,
                'active_slot' => null,
                'completed_at' => $now,
            ])->save();

            $eventMetadata = [
                'stage_key' => $attempt->stage->key,
                'attempt_number' => $attempt->attempt_number,
                'outcome' => $outcome,
                'source' => $source->value,
            ];
            if ($source === CompletionSource::AmpAgent && $githubReferenceUrl) {
                $eventMetadata['github_report_url'] = $githubReferenceUrl;
                $eventMetadata['amp_thread_id'] = $ampThreadId;
                $eventMetadata['amp_event_id'] = $ampEventId;
            }
            $this->recordEvent($lockedRun, $attempt, 'stage.completed', $actor, $eventMetadata);

            $lockedRun->current_stage_id = $destination->getKey();

            if ($destination->type === StageType::Terminal) {
                $lockedRun->status = WorkflowStatus::Completed;
                $lockedRun->completed_at = $now;
                $lockedRun->save();

                $terminalAttempt = $this->createStageAttempt(
                    $lockedRun,
                    $destination,
                    $attempt->attempt_number + 1,
                    $now,
                );
                $this->recordEvent($lockedRun, $terminalAttempt, 'stage.entered', $actor, [
                    'stage_key' => $destination->key,
                    'attempt_number' => $terminalAttempt->attempt_number,
                ]);
                $this->recordEvent($lockedRun, $terminalAttempt, 'workflow.completed', $actor);
            } else {
                $lockedRun->save();
                $nextAttempt = $this->createStageAttempt(
                    $lockedRun,
                    $destination,
                    $attempt->attempt_number + 1,
                    $now,
                );
                $this->recordEvent($lockedRun, $nextAttempt, 'stage.started', $actor, [
                    'stage_key' => $destination->key,
                    'attempt_number' => $nextAttempt->attempt_number,
                ]);
                $this->queueAmpLaunch($lockedRun, $nextAttempt);
            }

            return $lockedRun->fresh(['currentStage', 'activeStageRun']);
        }, 3);
    }

    public function cancel(WorkflowRun $run, User $actor): WorkflowRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $lockedRun = WorkflowRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $this->assertOwnedBy($lockedRun, $actor);

            if ($lockedRun->status === WorkflowStatus::Cancelled) {
                return $lockedRun->fresh(['currentStage', 'activeStageRun']);
            }

            if (! in_array($lockedRun->status, [WorkflowStatus::Running, WorkflowStatus::Paused], true)) {
                throw new WorkflowConflict('Only a running or paused workflow can be cancelled.');
            }

            $attempt = $lockedRun->status === WorkflowStatus::Running
                ? StageRun::query()
                    ->with('stage')
                    ->where('workflow_run_id', $lockedRun->getKey())
                    ->where('active_slot', 1)
                    ->lockForUpdate()
                    ->first()
                : StageRun::query()
                    ->with('stage')
                    ->where('workflow_run_id', $lockedRun->getKey())
                    ->latest('attempt_number')
                    ->lockForUpdate()
                    ->first();

            if (! $attempt) {
                throw new WorkflowConflict('The workflow has no stage attempt to cancel.');
            }

            $now = now();
            if ($lockedRun->status === WorkflowStatus::Running) {
                $this->stopAttempt($attempt, $now, 'workflow_cancelled', 'The workflow was cancelled.');
            }
            $lockedRun->forceFill([
                'status' => WorkflowStatus::Cancelled,
                'cancelled_at' => $now,
            ])->save();

            $this->recordEvent($lockedRun, $attempt, 'workflow.cancelled', $actor, [
                'stage_key' => $attempt->stage()->value('key'),
                'attempt_number' => $attempt->attempt_number,
            ]);

            return $lockedRun->fresh(['currentStage', 'activeStageRun']);
        }, 3);
    }

    public function handleAmpCallback(
        array $payload,
        string $payloadHash,
        ?AmpProjectConnection $connection = null,
    ): array {
        if ($connection) {
            $this->assertPayloadConnection($payload, $connection);
            $this->assertCallbackLaunchConnection($payload, $connection);
        }
        try {
            return DB::transaction(function () use ($payload, $payloadHash) {
                $existing = AmpIntegrationEvent::query()
                    ->where('event_id', $payload['event_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($existing->payload_hash !== $payloadHash) {
                        throw new WorkflowConflict('An Amp event ID was reused with a different payload.');
                    }

                    return $existing->response;
                }

                $attemptId = null;
                try {
                    $response = match ($payload['type']) {
                        'launch.claim' => $this->claimAmpLaunch($payload),
                        'launch.acknowledged' => $this->acknowledgeAmpLaunch($payload),
                        'launch.ambiguous' => $this->acceptAmpAmbiguity($payload),
                        'stage.report_claimed' => $this->claimAmpReportPublication($payload),
                        'stage.reported' => $this->recordAmpReport($payload),
                        'stage.published' => $this->recordAmpPublication($payload),
                        'stage.merge_verified' => $this->recordAmpMerge($payload),
                        'stage.completed' => $this->completeAmpStage($payload),
                        'stage.failed' => $this->failAmpStage($payload),
                    };
                    $attemptId = $payload['stage_run_id'];
                    $status = AmpIntegrationEventStatus::Processed;
                } catch (WorkflowConflict $exception) {
                    $response = [
                        'accepted' => false,
                        'disposition' => 'rejected',
                        'reason' => $exception->getMessage(),
                    ];
                    $attemptId = StageRun::query()->whereKey($payload['stage_run_id'])->value('id');
                    $status = AmpIntegrationEventStatus::Rejected;
                }

                AmpIntegrationEvent::query()->create([
                    'event_id' => $payload['event_id'],
                    'event_type' => $payload['type'],
                    'stage_run_id' => $attemptId,
                    'payload_hash' => $payloadHash,
                    'status' => $status,
                    'response' => $response,
                    'occurred_at' => $payload['occurred_at'],
                    'processed_at' => now(),
                ]);

                return $response;
            }, 3);
        } catch (QueryException $exception) {
            $existing = AmpIntegrationEvent::query()->where('event_id', $payload['event_id'])->first();
            if (! $existing || $existing->payload_hash !== $payloadHash) {
                throw $exception;
            }

            return $existing->response;
        }
    }

    public function ampContext(
        string $threadId,
        ?AmpProjectConnection $connection = null,
        ?string $ampProjectId = null,
        ?string $connectionId = null,
    ): array {
        $attempt = StageRun::query()
            ->with(['stage.outgoingTransitions', 'workflowRun', 'ampLaunch'])
            ->where('amp_thread_id', $threadId)
            ->first();

        if (! $attempt || ! $attempt->ampLaunch) {
            throw new WorkflowConflict('This thread is not bound to an Orc stage attempt.');
        }
        if ($connection) {
            $this->assertLaunchConnection($attempt->ampLaunch, $connection, $ampProjectId, $connectionId);
        }
        $priorPublication = $this->priorPublication($attempt);
        $approvedQa = $this->latestApprovedQa($attempt);
        $mergeReviewCycles = $this->mergeReviewCycles($attempt);

        return [
            'schema_version' => 1,
            'project_id' => $attempt->workflowRun->project_id,
            'amp_project_id' => $attempt->ampLaunch->ampProjectConnection?->amp_project_id,
            'connection_id' => $attempt->ampLaunch->ampProjectConnection?->public_id,
            'idempotency_key' => $attempt->ampLaunch->idempotency_key,
            'launch_event_id' => $attempt->ampLaunch->event_id,
            'stage_run_id' => $attempt->getKey(),
            'workflow_run_id' => $attempt->workflow_run_id,
            'stage_key' => $attempt->stage->key,
            'stage_name' => $attempt->stage->name,
            'agent_mode' => $this->agentMode($attempt->stage),
            'attempt_number' => $attempt->attempt_number,
            'github_repository' => $attempt->workflowRun->github_repository,
            'github_issue_number' => $attempt->workflowRun->github_issue_number,
            'github_issue_url' => $attempt->workflowRun->github_issue_url,
            'report_nonce' => $attempt->ampLaunch->report_nonce,
            'github_report_url' => $attempt->github_report_url,
            'github_report_comment_id' => $attempt->github_report_comment_id,
            'github_report_kind' => $attempt->github_report_kind,
            'expected_branch' => $this->expectedBranch($attempt),
            'prior_github_branch' => $priorPublication?->github_branch,
            'prior_pull_request_number' => $priorPublication?->github_pull_request_number,
            'prior_pull_request_url' => $priorPublication?->github_pull_request_url,
            'github_branch' => $attempt->github_branch,
            'github_pull_request_number' => $attempt->github_pull_request_number,
            'github_pull_request_url' => $attempt->github_pull_request_url,
            'github_pull_request_head_sha' => $attempt->github_pull_request_head_sha,
            'github_merge_commit_sha' => $attempt->github_merge_commit_sha,
            'approved_pull_request_head_sha' => $approvedQa?->github_pull_request_head_sha,
            'merge_review_cycles' => $mergeReviewCycles,
            'allowed_outcomes' => $this->allowedOutcomes($attempt, $mergeReviewCycles),
            'completed' => $attempt->status === StageRunStatus::Completed,
            'outcome' => $attempt->outcome,
            'is_active' => $attempt->active_slot === 1
                && $attempt->workflowRun->status === WorkflowStatus::Running,
        ];
    }

    public function stageCapabilityContext(string $token, string $threadId, string $ampProjectId): array
    {
        $launch = $this->capabilityLaunch($token);
        $this->assertMatchingAmpThread($launch->stageRun, $threadId);
        $this->assertWorkerProject($launch, $ampProjectId);

        return $this->ampContext($threadId);
    }

    public function handleStageCapability(string $token, array $payload, string $payloadHash): array
    {
        $launch = $this->capabilityLaunch($token);
        $this->assertWorkerProject($launch, $payload['amp_project_id']);
        $type = match ($payload['action']) {
            'report' => 'stage.reported',
            'report_claim' => 'stage.report_claimed',
            'publication' => 'stage.published',
            'merge' => 'stage.merge_verified',
            'complete' => 'stage.completed',
            'fail' => 'stage.failed',
        };

        return $this->handleAmpCallback([
            ...$payload,
            'type' => $type,
            'idempotency_key' => $launch->idempotency_key,
            'launch_event_id' => $launch->event_id,
            'stage_run_id' => $launch->stage_run_id,
            'report_nonce' => $launch->report_nonce,
            'capability_authenticated' => true,
            'connection_id' => $launch->ampProjectConnection->public_id,
        ], $payloadHash, $launch->ampProjectConnection);
    }

    public function failAmpLaunchDelivery(
        AmpLaunch $launch,
        string $code,
        string $message,
        ?int $httpStatus = null,
    ): void {
        DB::transaction(function () use ($launch, $code, $message, $httpStatus) {
            $lockedLaunch = AmpLaunch::query()->lockForUpdate()->findOrFail($launch->getKey());
            if ($lockedLaunch->launch_status !== AmpLaunchStatus::Pending) {
                return;
            }

            $attempt = StageRun::query()->with('stage')->lockForUpdate()->findOrFail($lockedLaunch->stage_run_id);
            $run = WorkflowRun::query()->lockForUpdate()->findOrFail($attempt->workflow_run_id);
            $now = now();

            $lockedLaunch->forceFill([
                'delivery_status' => AmpDeliveryStatus::Failed,
                'launch_status' => AmpLaunchStatus::Failed,
                'last_http_status' => $httpStatus,
                'last_error_code' => $code,
                'last_error_message' => mb_substr($message, 0, 1000),
                'finished_at' => $now,
            ])->save();

            if ($run->status === WorkflowStatus::Running && $attempt->active_slot === 1) {
                $attempt->forceFill([
                    'status' => StageRunStatus::Failed,
                    'active_slot' => null,
                    'completed_at' => $now,
                ])->save();
                $run->forceFill([
                    'status' => WorkflowStatus::Failed,
                    'failed_at' => $now,
                ])->save();
                $this->recordEvent($run, $attempt, 'stage.launch_failed', null, [
                    'stage_key' => $attempt->stage->key,
                    'attempt_number' => $attempt->attempt_number,
                    'reason_code' => $code,
                ]);
            }
        }, 3);
    }

    public function markAmpLaunchAmbiguous(AmpLaunch $launch, string $code, string $message): void
    {
        DB::transaction(function () use ($launch, $code, $message) {
            $lockedLaunch = AmpLaunch::query()->lockForUpdate()->findOrFail($launch->getKey());
            if (in_array($lockedLaunch->launch_status, [
                AmpLaunchStatus::Ambiguous,
                AmpLaunchStatus::Completed,
                AmpLaunchStatus::Failed,
            ], true)) {
                return;
            }

            $lockedLaunch->forceFill([
                'delivery_status' => $lockedLaunch->launch_status === AmpLaunchStatus::Launched
                    ? AmpDeliveryStatus::Delivered
                    : AmpDeliveryStatus::Ambiguous,
                'launch_status' => AmpLaunchStatus::Ambiguous,
                'last_error_code' => $code,
                'last_error_message' => mb_substr($message, 0, 1000),
            ])->save();

            $attempt = StageRun::query()->with('stage')->find($lockedLaunch->stage_run_id);
            if ($attempt) {
                $this->recordEvent($attempt->workflowRun, $attempt, 'stage.launch_ambiguous', null, [
                    'stage_key' => $attempt->stage->key,
                    'attempt_number' => $attempt->attempt_number,
                    'reason_code' => $code,
                ]);
            }
        }, 3);
    }

    private function claimAmpLaunch(array $payload): array
    {
        $launch = $this->lockedAmpLaunch($payload);

        if ($launch->launch_status !== AmpLaunchStatus::Pending) {
            return [
                'accepted' => true,
                'disposition' => 'duplicate_claim',
                'launch' => false,
                'launch_status' => $launch->launch_status->value,
                'is_active' => $launch->stageRun->active_slot === 1
                    && $launch->stageRun->workflowRun->status === WorkflowStatus::Running,
                'thread_id' => $launch->stageRun->amp_thread_id,
            ];
        }

        $this->assertCurrentAmpAttempt($launch->stageRun);
        $launch->forceFill([
            'launch_status' => AmpLaunchStatus::Claimed,
            'claimed_at' => now(),
        ])->save();

        $this->recordEvent($launch->stageRun->workflowRun, $launch->stageRun, 'stage.launch_claimed', null, [
            'stage_key' => $launch->stageRun->stage->key,
            'attempt_number' => $launch->stageRun->attempt_number,
            'launch_event_id' => $launch->event_id,
        ]);

        return [
            'accepted' => true,
            'disposition' => 'claimed',
            'launch' => true,
        ];
    }

    private function acknowledgeAmpLaunch(array $payload): array
    {
        $threadId = $this->requiredThreadId($payload);
        $launch = $this->lockedAmpLaunch($payload);

        if ($launch->launch_status === AmpLaunchStatus::Completed) {
            $this->assertMatchingAmpThread($launch->stageRun, $threadId);

            return ['accepted' => true, 'disposition' => 'already_completed'];
        }

        if ($launch->launch_status === AmpLaunchStatus::Launched) {
            $this->assertMatchingAmpThread($launch->stageRun, $threadId);

            return ['accepted' => true, 'disposition' => 'already_launched'];
        }

        if (! in_array($launch->launch_status, [
            AmpLaunchStatus::Claimed,
            AmpLaunchStatus::Launched,
            AmpLaunchStatus::Ambiguous,
        ], true)) {
            throw new WorkflowConflict('This Amp launch was not claimed or can no longer be acknowledged.');
        }

        $this->assertCurrentAmpAttempt($launch->stageRun);
        $this->bindAmpThread($launch->stageRun, $threadId);
        $launch->forceFill([
            'launch_status' => AmpLaunchStatus::Launched,
            'launched_at' => $launch->launched_at ?: now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        $this->recordEvent($launch->stageRun->workflowRun, $launch->stageRun, 'stage.amp_launched', null, [
            'stage_key' => $launch->stageRun->stage->key,
            'attempt_number' => $launch->stageRun->attempt_number,
            'amp_thread_id' => $threadId,
        ]);
        ReconcileAmpLaunch::dispatch($launch->getKey(), 1)
            ->delay(now()->addMinutes(5))
            ->afterCommit();

        return ['accepted' => true, 'disposition' => 'launched'];
    }

    private function acceptAmpAmbiguity(array $payload): array
    {
        $launch = $this->lockedAmpLaunch($payload);
        if ($launch->launch_status === AmpLaunchStatus::Completed) {
            return ['accepted' => true, 'disposition' => 'already_completed'];
        }

        if ($launch->launch_status === AmpLaunchStatus::Failed) {
            throw new WorkflowConflict('This Amp launch has already failed definitively.');
        }

        if (! empty($payload['thread_id'])) {
            $this->bindAmpThread($launch->stageRun, $payload['thread_id']);
        }

        $launch->forceFill([
            'launch_status' => AmpLaunchStatus::Ambiguous,
            'last_error_code' => 'plugin_ambiguous',
            'last_error_message' => mb_substr(
                $payload['reason'] ?? 'The Amp plugin could not prove launch completion.',
                0,
                1000,
            ),
        ])->save();

        $this->recordEvent($launch->stageRun->workflowRun, $launch->stageRun, 'stage.launch_ambiguous', null, [
            'stage_key' => $launch->stageRun->stage->key,
            'attempt_number' => $launch->stageRun->attempt_number,
            'amp_thread_id' => $payload['thread_id'] ?? null,
            'reason_code' => 'plugin_ambiguous',
        ]);

        return ['accepted' => true, 'disposition' => 'ambiguous'];
    }

    private function completeAmpStage(array $payload): array
    {
        $threadId = $this->requiredThreadId($payload);
        $outcome = $payload['outcome'] ?? null;
        if (! is_string($outcome) || $outcome === '') {
            throw new WorkflowConflict('An Amp completion must include an outcome.');
        }

        $launch = $this->lockedAmpLaunch($payload);
        if ($launch->launch_status === AmpLaunchStatus::Pending) {
            throw new WorkflowConflict('This Amp launch was never claimed.');
        }
        if ($launch->launch_status === AmpLaunchStatus::Failed) {
            throw new WorkflowConflict('This Amp launch has already failed.');
        }

        $this->bindAmpThread($launch->stageRun, $threadId);
        $reportUrl = $launch->stageRun->github_report_url;
        if ($launch->report_nonce) {
            if (! $reportUrl || ! $launch->stageRun->github_report_comment_id) {
                throw new WorkflowConflict('Amp must attest its GitHub report before completing.');
            }
            if (($payload['github_report_url'] ?? null) !== $reportUrl) {
                throw new WorkflowConflict('The completion report does not match the attested GitHub report.');
            }
        } else {
            $reportUrl = $payload['github_report_url'] ?? null;
            $this->assertGitHubReport($launch->stageRun, $reportUrl);
        }

        $mode = $this->agentMode($launch->stageRun->stage);
        if (! in_array($outcome, $this->allowedOutcomes($launch->stageRun), true)) {
            throw new WorkflowConflict("Outcome '{$outcome}' is not permitted for this stage attempt.");
        }
        if ($mode === 'real_development' && $outcome === 'success') {
            if (
                $launch->stageRun->github_branch !== $this->expectedBranch($launch->stageRun)
                || ! $launch->stageRun->github_pull_request_number
                || ! $launch->stageRun->github_pull_request_url
            ) {
                throw new WorkflowConflict('Real Development success requires its deterministic branch and open pull request.');
            }
        }
        if (! empty($payload['capability_authenticated']) && $mode === 'real_development' && $launch->stageRun->github_report_kind !== $outcome) {
            throw new WorkflowConflict('The Development report kind must match its completion outcome.');
        }
        if (! empty($payload['capability_authenticated']) && $mode === 'real_qa' && $launch->stageRun->github_report_kind !== $outcome) {
            throw new WorkflowConflict('The QA report kind must match its completion outcome.');
        }
        if (! empty($payload['capability_authenticated']) && $mode === 'real_explanation' && $launch->stageRun->github_report_kind !== $outcome) {
            throw new WorkflowConflict('The Explanation report kind must match its completion outcome.');
        }
        if (! empty($payload['capability_authenticated']) && $mode === 'real_merge') {
            if ($launch->stageRun->github_report_kind !== $outcome) {
                throw new WorkflowConflict('The Merge report kind must match its completion outcome.');
            }
            if ($outcome === 'merged' && (
                ! $launch->stageRun->github_pull_request_head_sha
                || ! $launch->stageRun->github_merge_commit_sha
            )) {
                throw new WorkflowConflict('Merge completion requires verified pull-request head and merge commit evidence.');
            }
            if ($outcome === 'requires_review') {
                $approvedQa = $this->latestApprovedQa($launch->stageRun);
                if (
                    $this->mergeReviewCycles($launch->stageRun) >= 1
                    || ! $approvedQa?->github_pull_request_head_sha
                    || ! $launch->stageRun->github_pull_request_head_sha
                    || hash_equals($approvedQa->github_pull_request_head_sha, $launch->stageRun->github_pull_request_head_sha)
                ) {
                    throw new WorkflowConflict('Only the first material conflict resolved onto a new pull-request head may request another review cycle.');
                }
            }
        }
        if (! empty($payload['capability_authenticated']) && $mode === 'proof_qa' && $launch->stageRun->github_report_kind !== 'proof') {
            throw new WorkflowConflict('QA integration proof requires a proof-only report.');
        }

        $run = $this->completeAttempt(
            $launch->stageRun->workflowRun,
            $launch->stageRun,
            $outcome,
            CompletionSource::AmpAgent,
            null,
            $reportUrl,
            $threadId,
            $payload['event_id'],
        );

        $launch->refresh()->forceFill([
            'launch_status' => AmpLaunchStatus::Completed,
            'launched_at' => $launch->launched_at ?: now(),
            'finished_at' => now(),
        ])->save();

        return [
            'accepted' => true,
            'disposition' => 'completed',
            'workflow_status' => $run->status->value,
            'current_stage' => $run->currentStage->key,
        ];
    }

    private function recordAmpReport(array $payload): array
    {
        $threadId = $this->requiredThreadId($payload);
        $reportUrl = $payload['github_report_url'] ?? null;
        $commentId = $payload['github_report_comment_id'] ?? null;
        $nonce = $payload['report_nonce'] ?? null;
        $reportKind = $payload['github_report_kind'] ?? null;
        $launch = $this->lockedAmpLaunch($payload);

        if (! $launch->report_nonce || ! is_string($nonce) || ! hash_equals($launch->report_nonce, $nonce)) {
            throw new WorkflowConflict('The GitHub report attestation is invalid.');
        }

        $this->assertCurrentAmpAttempt($launch->stageRun);
        $this->bindAmpThread($launch->stageRun, $threadId);
        $this->assertGitHubReport($launch->stageRun, $reportUrl, $commentId);
        if (! empty($payload['capability_authenticated'])) {
            $allowedKinds = match ($this->agentMode($launch->stageRun->stage)) {
                'real_development' => ['success', 'blocked'],
                'real_qa' => ['pass', 'fail', 'blocked'],
                'real_explanation' => ['completed', 'blocked'],
                'real_merge' => ['merged', 'requires_review', 'blocked'],
                default => ['proof'],
            };
            if (! is_string($reportKind) || ! in_array($reportKind, $allowedKinds, true)) {
                throw new WorkflowConflict('The stage report kind is invalid for this agent mode.');
            }
            if (
                $this->agentMode($launch->stageRun->stage) === 'real_merge'
                && ! in_array($reportKind, $this->allowedOutcomes($launch->stageRun), true)
            ) {
                throw new WorkflowConflict('This Merge outcome is no longer permitted for the run.');
            }
        }

        $headSha = $payload['github_pull_request_head_sha'] ?? null;
        if (
            $this->agentMode($launch->stageRun->stage) === 'real_merge'
            || ($this->agentMode($launch->stageRun->stage) === 'real_qa' && $this->requiresMergeEvidence($launch->stageRun))
        ) {
            $this->assertGitCommitSha($headSha, 'The stage report requires the exact pull-request head SHA.');
        }

        if ($launch->stageRun->github_report_url || $launch->stageRun->github_report_comment_id) {
            if (
                $launch->stageRun->github_report_url !== $reportUrl
                || $launch->stageRun->github_report_comment_id !== $commentId
                || $launch->stageRun->github_report_kind !== $reportKind
                || $launch->stageRun->github_pull_request_head_sha !== $headSha
            ) {
                throw new WorkflowConflict('A different GitHub report is already attested for this attempt.');
            }

            return ['accepted' => true, 'disposition' => 'already_reported'];
        }

        $launch->stageRun->forceFill([
            'github_report_url' => $reportUrl,
            'github_report_comment_id' => $commentId,
            'github_report_kind' => $reportKind,
            'github_pull_request_head_sha' => $headSha,
        ])->save();
        $this->recordEvent($launch->stageRun->workflowRun, $launch->stageRun, 'stage.reported', null, [
            'stage_key' => $launch->stageRun->stage->key,
            'attempt_number' => $launch->stageRun->attempt_number,
            'github_report_url' => $reportUrl,
            'github_report_comment_id' => $commentId,
            'github_report_kind' => $reportKind,
            'github_pull_request_head_sha' => $headSha,
            'amp_thread_id' => $threadId,
        ]);

        return ['accepted' => true, 'disposition' => 'reported'];
    }

    private function claimAmpReportPublication(array $payload): array
    {
        $threadId = $this->requiredThreadId($payload);
        $launch = $this->lockedAmpLaunch($payload);

        $this->assertCurrentAmpAttempt($launch->stageRun);
        $this->bindAmpThread($launch->stageRun, $threadId);
        if ($launch->stageRun->github_report_url) {
            return ['accepted' => true, 'disposition' => 'already_reported', 'publish' => false];
        }
        if ($launch->report_claimed_at) {
            return [
                'accepted' => false,
                'disposition' => 'publication_ambiguous',
                'publish' => false,
                'reason' => 'Another report publication call already owns this attempt.',
            ];
        }

        $launch->forceFill(['report_claimed_at' => now()])->save();
        $this->recordEvent($launch->stageRun->workflowRun, $launch->stageRun, 'stage.report_claimed', null, [
            'stage_key' => $launch->stageRun->stage->key,
            'attempt_number' => $launch->stageRun->attempt_number,
            'amp_thread_id' => $threadId,
        ]);

        return ['accepted' => true, 'disposition' => 'report_claimed', 'publish' => true];
    }

    private function recordAmpPublication(array $payload): array
    {
        $threadId = $this->requiredThreadId($payload);
        $branch = $payload['github_branch'] ?? null;
        $pullRequestNumber = $payload['github_pull_request_number'] ?? null;
        $pullRequestUrl = $payload['github_pull_request_url'] ?? null;
        $pullRequestHeadSha = $payload['github_pull_request_head_sha'] ?? null;
        $launch = $this->lockedAmpLaunch($payload);

        $this->assertCurrentAmpAttempt($launch->stageRun);
        $this->bindAmpThread($launch->stageRun, $threadId);
        if ($this->agentMode($launch->stageRun->stage) !== 'real_development') {
            throw new WorkflowConflict('Only a real Development attempt can publish code.');
        }
        if (! is_string($branch) || $branch !== $this->expectedBranch($launch->stageRun)) {
            throw new WorkflowConflict('The published branch does not match this Development attempt.');
        }
        $this->assertGitHubPullRequest(
            $launch->stageRun->workflowRun,
            $pullRequestNumber,
            $pullRequestUrl,
        );
        if ($this->requiresMergeEvidence($launch->stageRun)) {
            $this->assertGitCommitSha($pullRequestHeadSha, 'Code publication requires the exact pull-request head SHA.');
        }

        if ($launch->stageRun->github_pull_request_number) {
            if (
                $launch->stageRun->github_branch !== $branch
                || $launch->stageRun->github_pull_request_number !== $pullRequestNumber
                || $launch->stageRun->github_pull_request_url !== $pullRequestUrl
                || $launch->stageRun->github_pull_request_head_sha !== $pullRequestHeadSha
            ) {
                throw new WorkflowConflict('A different code publication is already bound to this attempt.');
            }

            return ['accepted' => true, 'disposition' => 'already_published'];
        }

        $launch->stageRun->forceFill([
            'github_branch' => $branch,
            'github_pull_request_number' => $pullRequestNumber,
            'github_pull_request_url' => $pullRequestUrl,
            'github_pull_request_head_sha' => $pullRequestHeadSha,
        ])->save();
        $this->recordEvent($launch->stageRun->workflowRun, $launch->stageRun, 'stage.published', null, [
            'stage_key' => $launch->stageRun->stage->key,
            'attempt_number' => $launch->stageRun->attempt_number,
            'github_branch' => $branch,
            'github_pull_request_number' => $pullRequestNumber,
            'github_pull_request_url' => $pullRequestUrl,
            'github_pull_request_head_sha' => $pullRequestHeadSha,
            'amp_thread_id' => $threadId,
        ]);

        return ['accepted' => true, 'disposition' => 'published'];
    }

    private function recordAmpMerge(array $payload): array
    {
        $threadId = $this->requiredThreadId($payload);
        $pullRequestNumber = $payload['github_pull_request_number'] ?? null;
        $pullRequestUrl = $payload['github_pull_request_url'] ?? null;
        $pullRequestHeadSha = $payload['github_pull_request_head_sha'] ?? null;
        $mergeCommitSha = $payload['github_merge_commit_sha'] ?? null;
        $launch = $this->lockedAmpLaunch($payload);

        $this->assertCurrentAmpAttempt($launch->stageRun);
        $this->bindAmpThread($launch->stageRun, $threadId);
        if ($this->agentMode($launch->stageRun->stage) !== 'real_merge') {
            throw new WorkflowConflict('Only a Merge attempt can attest a completed merge.');
        }
        $this->assertGitHubPullRequest($launch->stageRun->workflowRun, $pullRequestNumber, $pullRequestUrl);
        $this->assertGitCommitSha($pullRequestHeadSha, 'Merge evidence requires the exact pull-request head SHA.');
        $this->assertGitCommitSha($mergeCommitSha, 'Merge evidence requires the exact GitHub merge commit SHA.');
        $prior = $this->priorPublication($launch->stageRun);
        if (
            ! $prior
            || $prior->github_pull_request_number !== $pullRequestNumber
            || $prior->github_pull_request_url !== $pullRequestUrl
        ) {
            throw new WorkflowConflict('The merge evidence does not match the pull request bound to this workflow.');
        }

        if ($launch->stageRun->github_merge_commit_sha) {
            if (
                $launch->stageRun->github_pull_request_number !== $pullRequestNumber
                || $launch->stageRun->github_pull_request_url !== $pullRequestUrl
                || $launch->stageRun->github_pull_request_head_sha !== $pullRequestHeadSha
                || $launch->stageRun->github_merge_commit_sha !== $mergeCommitSha
            ) {
                throw new WorkflowConflict('Different merge evidence is already bound to this attempt.');
            }

            return ['accepted' => true, 'disposition' => 'already_verified'];
        }

        $launch->stageRun->forceFill([
            'github_branch' => $prior->github_branch,
            'github_pull_request_number' => $pullRequestNumber,
            'github_pull_request_url' => $pullRequestUrl,
            'github_pull_request_head_sha' => $pullRequestHeadSha,
            'github_merge_commit_sha' => $mergeCommitSha,
        ])->save();
        $this->recordEvent($launch->stageRun->workflowRun, $launch->stageRun, 'stage.merge_verified', null, [
            'stage_key' => $launch->stageRun->stage->key,
            'attempt_number' => $launch->stageRun->attempt_number,
            'github_pull_request_url' => $pullRequestUrl,
            'github_pull_request_head_sha' => $pullRequestHeadSha,
            'github_merge_commit_sha' => $mergeCommitSha,
            'amp_thread_id' => $threadId,
        ]);

        return ['accepted' => true, 'disposition' => 'verified'];
    }

    private function failAmpStage(array $payload): array
    {
        $threadId = $this->requiredThreadId($payload);
        $launch = $this->lockedAmpLaunch($payload);
        $attempt = $launch->stageRun;
        $run = $attempt->workflowRun;

        if ($attempt->status === StageRunStatus::Failed && $attempt->amp_thread_id === $threadId) {
            return ['accepted' => true, 'disposition' => 'already_failed'];
        }

        $this->assertCurrentAmpAttempt($attempt);
        $this->bindAmpThread($attempt, $threadId);
        $now = now();

        $attempt->forceFill([
            'status' => StageRunStatus::Failed,
            'amp_event_id' => $payload['event_id'],
            'active_slot' => null,
            'completed_at' => $now,
        ])->save();
        $run->forceFill([
            'status' => WorkflowStatus::Failed,
            'failed_at' => $now,
        ])->save();
        $launch->forceFill([
            'launch_status' => AmpLaunchStatus::Failed,
            'finished_at' => $now,
            'last_error_code' => 'agent_ended',
            'last_error_message' => mb_substr($payload['reason'] ?? 'The Amp agent ended without completion.', 0, 1000),
        ])->save();

        $this->recordEvent($run, $attempt, 'stage.failed', null, [
            'stage_key' => $attempt->stage->key,
            'attempt_number' => $attempt->attempt_number,
            'amp_thread_id' => $threadId,
            'amp_event_id' => $payload['event_id'],
            'reason_code' => 'agent_ended',
        ]);

        return ['accepted' => true, 'disposition' => 'failed'];
    }

    private function lockedAmpLaunch(array $payload): AmpLaunch
    {
        $launch = AmpLaunch::query()
            ->with(['stageRun.stage', 'stageRun.workflowRun'])
            ->where('idempotency_key', $payload['idempotency_key'])
            ->where('event_id', $payload['launch_event_id'])
            ->where('stage_run_id', $payload['stage_run_id'])
            ->lockForUpdate()
            ->first();

        if (! $launch) {
            throw new WorkflowConflict('The Amp callback does not match a launch request.');
        }

        return $launch;
    }

    private function capabilityLaunch(string $token): AmpLaunch
    {
        if (strlen($token) < 32) {
            throw new WorkflowConflict('The stage capability is invalid.');
        }

        $launch = AmpLaunch::query()
            ->with(['stageRun.stage.outgoingTransitions', 'stageRun.workflowRun'])
            ->where('capability_hash', hash('sha256', $token))
            ->first();
        if (! $launch || ! $launch->capability_secret || ! hash_equals($launch->capability_secret, $token)) {
            throw new WorkflowConflict('The stage capability is invalid.');
        }

        return $launch;
    }

    private function assertCurrentAmpAttempt(StageRun $attempt): void
    {
        $run = $attempt->workflowRun;
        if ($run->status === WorkflowStatus::Cancelled) {
            throw new WorkflowConflict('Cancelled workflows reject Amp callbacks.');
        }
        if ($run->status !== WorkflowStatus::Running) {
            throw new WorkflowConflict('This workflow is no longer running.');
        }
        if ($attempt->active_slot !== 1 || $run->current_stage_id !== $attempt->workflow_stage_id) {
            throw new WorkflowConflict('This Amp stage attempt is stale.');
        }
        if ($attempt->stage->type !== StageType::Agent) {
            throw new WorkflowConflict('Only agent stages can be bound to Amp.');
        }
    }

    private function bindAmpThread(StageRun $attempt, string $threadId): void
    {
        if ($attempt->amp_thread_id && $attempt->amp_thread_id !== $threadId) {
            throw new WorkflowConflict('A different Amp thread is already bound to this attempt.');
        }

        if (! $attempt->amp_thread_id) {
            $attempt->forceFill(['amp_thread_id' => $threadId])->save();
        }
    }

    private function assertMatchingAmpThread(StageRun $attempt, string $threadId): void
    {
        if ($attempt->amp_thread_id !== $threadId) {
            throw new WorkflowConflict('The Amp callback does not match the thread bound to this attempt.');
        }
    }

    private function requiredThreadId(array $payload): string
    {
        if (empty($payload['thread_id'])) {
            throw new WorkflowConflict('This Amp callback requires a thread ID.');
        }

        return $payload['thread_id'];
    }

    private function assertGitHubReport(
        StageRun $attempt,
        mixed $reportUrl,
        mixed $commentId = null,
    ): void {
        if (! is_string($reportUrl) || ! $this->isGitHubUrl($reportUrl)) {
            throw new WorkflowConflict('Amp must publish its stage report on GitHub before completing.');
        }

        $run = $attempt->workflowRun;
        $path = strtolower(rtrim((string) parse_url($reportUrl, PHP_URL_PATH), '/'));
        if (in_array($this->agentMode($attempt->stage), ['real_qa', 'real_explanation', 'real_merge'], true)) {
            $publication = $this->priorPublication($attempt);
            if (! $publication?->github_pull_request_number) {
                throw new WorkflowConflict('This stage requires a verified Development pull request.');
            }
            $expectedPath = strtolower('/'.$run->github_repository.'/pull/'.$publication->github_pull_request_number);
            if ($path !== $expectedPath) {
                throw new WorkflowConflict('The stage report URL must belong to the pull request bound to this attempt.');
            }
        } else {
            $expectedPath = strtolower('/'.$run->github_repository.'/issues/'.$run->github_issue_number);
            if ($path !== $expectedPath) {
                throw new WorkflowConflict('The Amp report URL must belong to this workflow issue.');
            }
        }

        if ($commentId !== null) {
            $fragment = (string) parse_url($reportUrl, PHP_URL_FRAGMENT);
            if (! is_int($commentId) || $fragment !== 'issuecomment-'.$commentId) {
                throw new WorkflowConflict('The Amp report URL must match its GitHub comment ID.');
            }
        }
    }

    private function assertGitHubPullRequest(
        WorkflowRun $run,
        mixed $pullRequestNumber,
        mixed $pullRequestUrl,
    ): void {
        if (! is_int($pullRequestNumber) || $pullRequestNumber < 1) {
            throw new WorkflowConflict('A valid GitHub pull request number is required.');
        }
        if (! is_string($pullRequestUrl) || ! $this->isGitHubUrl($pullRequestUrl)) {
            throw new WorkflowConflict('A valid GitHub pull request URL is required.');
        }

        $path = strtolower(rtrim((string) parse_url($pullRequestUrl, PHP_URL_PATH), '/'));
        $expectedPath = strtolower('/'.$run->github_repository.'/pull/'.$pullRequestNumber);
        if ($path !== $expectedPath) {
            throw new WorkflowConflict('The pull request URL must belong to this workflow repository.');
        }
    }

    private function queueAmpLaunch(WorkflowRun $run, StageRun $attempt): void
    {
        if (! config('services.amp.enabled') || $attempt->stage->type !== StageType::Agent) {
            return;
        }

        $capability = Str::random(64);
        $launch = AmpLaunch::query()->firstOrCreate(
            ['stage_run_id' => $attempt->getKey()],
            [
                'amp_project_connection_id' => $run->amp_project_connection_id,
                'event_id' => (string) Str::uuid(),
                'idempotency_key' => (string) Str::uuid(),
                'report_nonce' => bin2hex(random_bytes(32)),
                'capability_secret' => $capability,
                'capability_hash' => hash('sha256', $capability),
                'delivery_status' => AmpDeliveryStatus::Pending,
                'launch_status' => AmpLaunchStatus::Pending,
            ],
        );

        if (! $launch->wasRecentlyCreated) {
            return;
        }

        $body = app(AmpLaunchPayload::class)->body($launch);
        $launch->forceFill([
            'payload_body' => $body,
            'payload_hash' => hash('sha256', $body),
        ])->save();

        $this->recordEvent($run, $attempt, 'stage.launch_queued', null, [
            'stage_key' => $attempt->stage->key,
            'attempt_number' => $attempt->attempt_number,
            'launch_event_id' => $launch->event_id,
        ]);
        DeliverAmpLaunch::dispatch($launch->getKey())->afterCommit();
    }

    private function stopAttempt(
        StageRun $attempt,
        mixed $stoppedAt,
        string $errorCode,
        string $errorMessage,
    ): void {
        $attempt->forceFill([
            'status' => StageRunStatus::Cancelled,
            'active_slot' => null,
            'completed_at' => $stoppedAt,
        ])->save();

        $launch = $attempt->ampLaunch()->lockForUpdate()->first();
        if (! $launch) {
            return;
        }

        $cancellationEventId = $attempt->amp_thread_id
            ? ($launch->cancellation_event_id ?: (string) Str::uuid())
            : null;
        $launch->forceFill([
            'delivery_status' => AmpDeliveryStatus::Failed,
            'launch_status' => AmpLaunchStatus::Failed,
            'last_error_code' => $errorCode,
            'last_error_message' => $errorMessage,
            'cancellation_event_id' => $cancellationEventId,
            'cancellation_status' => $cancellationEventId ? 'pending' : null,
            'finished_at' => $stoppedAt,
        ])->save();
        if ($cancellationEventId) {
            DeliverAmpCancellation::dispatch($launch->getKey())->afterCommit();
        }
    }

    private function assertAttemptBelongsToRun(WorkflowRun $run, StageRun $attempt): void
    {
        if ($attempt->workflow_run_id !== $run->getKey()) {
            throw new WorkflowConflict('That stage attempt does not belong to this workflow.');
        }
    }

    private function assertCurrentActiveAttempt(WorkflowRun $run, StageRun $attempt): void
    {
        if (
            $attempt->active_slot !== 1
            || $run->current_stage_id !== $attempt->workflow_stage_id
        ) {
            throw new WorkflowConflict('This stage attempt is stale. Refresh the workflow and try again.');
        }
    }

    private function assertLatestClosedAttempt(WorkflowRun $run, StageRun $attempt): void
    {
        $latestAttemptId = (int) StageRun::query()
            ->where('workflow_run_id', $run->getKey())
            ->orderByDesc('attempt_number')
            ->value('id');
        $validStatus = $run->status === WorkflowStatus::Paused
            ? $attempt->status === StageRunStatus::Cancelled
            : $attempt->status === StageRunStatus::Failed;

        if (
            $latestAttemptId !== $attempt->getKey()
            || $run->current_stage_id !== $attempt->workflow_stage_id
            || ! $validStatus
        ) {
            throw new WorkflowConflict('This stage attempt is stale. Refresh the workflow and try again.');
        }
    }

    private function assertAgentDestinationReady(WorkflowRun $run, WorkflowStage $destination): void
    {
        $mode = $this->agentMode($destination);
        $publication = StageRun::query()
            ->where('workflow_run_id', $run->getKey())
            ->whereNotNull('github_pull_request_number')
            ->whereNotNull('github_pull_request_url')
            ->latest('attempt_number')
            ->first();
        if (in_array($mode, ['real_qa', 'real_explanation'], true) && ! $publication) {
            throw new WorkflowConflict($mode === 'real_explanation'
                ? 'Explanation requires an existing Development pull request.'
                : 'Independent QA requires an existing Development pull request.');
        }
        if ($mode === 'real_merge') {
            if (! config('services.amp.enabled')) {
                return;
            }
            $latestAttemptNumber = ((int) StageRun::query()
                ->where('workflow_run_id', $run->getKey())
                ->max('attempt_number')) + 1;
            $prospective = new StageRun([
                'workflow_run_id' => $run->getKey(),
                'attempt_number' => $latestAttemptNumber,
            ]);
            if (! $publication || ! $this->latestApprovedQa($prospective)?->github_pull_request_head_sha) {
                throw new WorkflowConflict('Merge requires an exact pull request head from the latest passing independent QA attempt.');
            }
        }
    }

    private function createStageAttempt(
        WorkflowRun $run,
        WorkflowStage $stage,
        int $attemptNumber,
        mixed $startedAt,
    ): StageRun {
        $terminal = $stage->type === StageType::Terminal;

        $attempt = StageRun::query()->create([
            'workflow_run_id' => $run->getKey(),
            'workflow_stage_id' => $stage->getKey(),
            'attempt_number' => $attemptNumber,
            'status' => match ($stage->type) {
                StageType::Human => StageRunStatus::Waiting,
                StageType::Terminal => StageRunStatus::Completed,
                StageType::Agent => StageRunStatus::Running,
            },
            'active_slot' => $terminal ? null : 1,
            'started_at' => $startedAt,
            'completed_at' => $terminal ? $startedAt : null,
        ]);

        $this->attentionNotifier->schedule($run, $attempt, $stage);

        return $attempt;
    }

    private function recordEvent(
        WorkflowRun $run,
        ?StageRun $attempt,
        string $type,
        ?User $actor,
        array $metadata = [],
    ): void {
        WorkflowEvent::query()->create([
            'workflow_run_id' => $run->getKey(),
            'stage_run_id' => $attempt?->getKey(),
            'type' => $type,
            'actor_type' => $actor ? 'user' : 'amp',
            'actor_id' => $actor?->getKey(),
            'metadata' => $metadata ?: null,
            'happened_at' => now(),
        ]);
    }

    private function assertOwnedBy(WorkflowRun $run, User $actor): void
    {
        if ($run->user_id !== $actor->getKey()) {
            throw new WorkflowConflict('You do not own this workflow.');
        }
    }

    private function assertSourceMatchesStage(CompletionSource $source, WorkflowStage $stage): void
    {
        $matches = match ($source) {
            CompletionSource::AmpAgent, CompletionSource::AgentSimulation => $stage->type === StageType::Agent,
            CompletionSource::HumanAction => $stage->type === StageType::Human,
        };

        if (! $matches) {
            throw new WorkflowConflict('That completion action is not permitted for this stage type.');
        }
    }

    private function assertGitHubIssue(string $repository, int $issueNumber, string $issueUrl): void
    {
        if (! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository) || $issueNumber < 1) {
            throw new WorkflowConflict('A valid GitHub repository and issue number are required.');
        }

        $expectedPath = strtolower("/{$repository}/issues/{$issueNumber}");
        $actualPath = strtolower(rtrim((string) parse_url($issueUrl, PHP_URL_PATH), '/'));

        if (! $this->isGitHubUrl($issueUrl) || $actualPath !== $expectedPath) {
            throw new WorkflowConflict('The GitHub issue URL must match the repository and issue number.');
        }
    }

    private function assertAmpTargetAuthorized(User $actor, Project $project): ?AmpProjectConnection
    {
        if (! config('services.amp.enabled')) {
            if ($project->user_id !== $actor->id) {
                throw new WorkflowConflict('You do not own this project.');
            }

            return null;
        }
        if ($project->user_id !== $actor->id) {
            throw new WorkflowConflict('You do not own this project.');
        }
        if (! $actor->can_trigger_amp) {
            throw new WorkflowConflict('Your account is not authorized for Amp workflow execution.');
        }
        $connection = $project->currentConnection()->first();
        if (! $connection || ! $connection->isReady()) {
            throw new WorkflowConflict('This project does not have a verified Amp controller connection.');
        }
        if ($connection->amp_project_id !== $project->amp_project_id) {
            throw new WorkflowConflict('This project connection does not match the configured Amp project.');
        }

        return $connection;
    }

    private function assertRunAmpAuthorized(WorkflowRun $run, User $actor): void
    {
        if (! config('services.amp.enabled')) {
            return;
        }
        if (! $actor->can_trigger_amp) {
            throw new WorkflowConflict('Your account is not authorized for Amp workflow execution.');
        }
        $project = $run->project;
        $connection = $run->ampProjectConnection;
        if (
            ! $project
            || $project->user_id !== $actor->id
            || ! $connection
            || $connection->project_id !== $project->id
            || ! $connection->isReady()
        ) {
            throw new WorkflowConflict('This workflow is not bound to a verified project connection.');
        }
    }

    private function assertPayloadConnection(array $payload, AmpProjectConnection $connection): void
    {
        if (
            ($payload['connection_id'] ?? null) !== $connection->public_id
            || ($payload['amp_project_id'] ?? null) !== $connection->amp_project_id
        ) {
            throw new WorkflowConflict('The callback does not match this Amp project connection.');
        }
    }

    private function assertCallbackLaunchConnection(array $payload, AmpProjectConnection $connection): void
    {
        $launchConnectionId = AmpLaunch::query()
            ->where('idempotency_key', $payload['idempotency_key'])
            ->where('event_id', $payload['launch_event_id'])
            ->where('stage_run_id', $payload['stage_run_id'])
            ->value('amp_project_connection_id');

        if ($launchConnectionId !== null && (int) $launchConnectionId !== $connection->id) {
            throw new WorkflowConflict('The callback launch belongs to a different Amp project connection.');
        }
    }

    private function assertLaunchConnection(
        AmpLaunch $launch,
        AmpProjectConnection $connection,
        ?string $ampProjectId,
        ?string $connectionId,
    ): void {
        if (
            $launch->amp_project_connection_id !== $connection->id
            || $connectionId !== $connection->public_id
            || $ampProjectId !== $connection->amp_project_id
        ) {
            throw new WorkflowConflict('This thread is bound to a different Amp project connection.');
        }
    }

    private function assertWorkerProject(AmpLaunch $launch, string $ampProjectId): void
    {
        $connection = $launch->ampProjectConnection;
        if (! $connection || $connection->amp_project_id !== $ampProjectId) {
            throw new WorkflowConflict('This worker Orb does not belong to the project bound to the workflow.');
        }
    }

    private function isGitHubUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $scheme === 'https' && in_array($host, ['github.com', 'www.github.com'], true);
    }

    private function agentMode(WorkflowStage $stage): string
    {
        return $stage->config['agent_mode'] ?? 'proof_'.$stage->key;
    }

    private function expectedBranch(StageRun $attempt): string
    {
        if ($attempt->stage->config['reuse_prior_publication'] ?? false) {
            $prior = $this->priorPublication($attempt);
            if ($prior?->github_branch) {
                return $prior->github_branch;
            }
        }

        return "orc/stage-{$attempt->getKey()}-attempt-{$attempt->attempt_number}";
    }

    private function priorPublication(StageRun $attempt): ?StageRun
    {
        return StageRun::query()
            ->where('workflow_run_id', $attempt->workflow_run_id)
            ->where('attempt_number', '<', $attempt->attempt_number)
            ->whereNotNull('github_pull_request_number')
            ->latest('attempt_number')
            ->first();
    }

    private function latestApprovedQa(StageRun $attempt): ?StageRun
    {
        return StageRun::query()
            ->with('stage')
            ->where('workflow_run_id', $attempt->workflow_run_id)
            ->where('attempt_number', '<', $attempt->attempt_number)
            ->where('outcome', 'pass')
            ->whereNotNull('github_pull_request_head_sha')
            ->latest('attempt_number')
            ->get()
            ->first(fn (StageRun $candidate) => $this->agentMode($candidate->stage) === 'real_qa');
    }

    private function mergeReviewCycles(StageRun $attempt): int
    {
        return StageRun::query()
            ->with('stage')
            ->where('workflow_run_id', $attempt->workflow_run_id)
            ->where('attempt_number', '<', $attempt->attempt_number)
            ->where('outcome', 'requires_review')
            ->get()
            ->filter(fn (StageRun $candidate) => $this->agentMode($candidate->stage) === 'real_merge')
            ->count();
    }

    private function allowedOutcomes(StageRun $attempt, ?int $mergeReviewCycles = null): array
    {
        $outcomes = $attempt->stage->outgoingTransitions->pluck('outcome');
        if ($this->agentMode($attempt->stage) === 'real_merge' && ($mergeReviewCycles ?? $this->mergeReviewCycles($attempt)) >= 1) {
            $outcomes = $outcomes->reject(fn (string $outcome) => $outcome === 'requires_review');
        }

        return $outcomes->values()->all();
    }

    private function requiresMergeEvidence(StageRun $attempt): bool
    {
        return (int) $attempt->workflowRun->definition()->value('version') >= 4;
    }

    private function assertGitCommitSha(mixed $sha, string $message): void
    {
        if (! is_string($sha) || ! preg_match('/^[a-f0-9]{40}$/i', $sha)) {
            throw new WorkflowConflict($message);
        }
    }
}
