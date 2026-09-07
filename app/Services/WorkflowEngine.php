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
use App\Jobs\DeliverAmpLaunch;
use App\Models\AmpIntegrationEvent;
use App\Models\AmpLaunch;
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
    public function start(
        User $actor,
        WorkflowDefinition $definition,
        string $githubRepository,
        int $githubIssueNumber,
        string $githubIssueUrl,
    ): WorkflowRun {
        $this->assertGitHubIssue($githubRepository, $githubIssueNumber, $githubIssueUrl);
        $this->assertAmpTargetAuthorized($actor, $githubRepository);

        return DB::transaction(function () use (
            $actor,
            $definition,
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

            $firstStage = $lockedDefinition->stages()->orderBy('position')->first();
            if (! $firstStage) {
                throw new WorkflowConflict('This workflow definition has no stages.');
            }

            $now = now();
            $run = WorkflowRun::query()->create([
                'user_id' => $actor->getKey(),
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
        ?string $githubFeedbackUrl = null,
    ): WorkflowRun {
        return $this->completeAttempt(
            $run,
            $attempt,
            $outcome,
            CompletionSource::HumanAction,
            $actor,
            $githubFeedbackUrl,
        );
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
            $feedbackUrl = $source === CompletionSource::HumanAction
                ? $this->validateFeedback($outcome, $githubReferenceUrl)
                : null;

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
            if ($feedbackUrl) {
                $eventMetadata['github_feedback_url'] = $feedbackUrl;
            }
            if ($source === CompletionSource::AmpAgent && $githubReferenceUrl) {
                $eventMetadata['github_report_url'] = $githubReferenceUrl;
                $eventMetadata['amp_thread_id'] = $ampThreadId;
                $eventMetadata['amp_event_id'] = $ampEventId;
            }
            $this->recordEvent($lockedRun, $attempt, 'stage.completed', $actor, $eventMetadata);

            $destination = $transition->toStage;
            if ($destination->workflow_definition_id !== $lockedRun->workflow_definition_id) {
                throw new WorkflowConflict('The workflow definition contains an invalid cross-definition transition.');
            }

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

            if ($lockedRun->status !== WorkflowStatus::Running) {
                throw new WorkflowConflict('Only a running workflow can be cancelled.');
            }

            $attempt = StageRun::query()
                ->where('workflow_run_id', $lockedRun->getKey())
                ->where('active_slot', 1)
                ->lockForUpdate()
                ->first();

            if (! $attempt) {
                throw new WorkflowConflict('The running workflow has no active stage attempt.');
            }

            $now = now();
            $attempt->forceFill([
                'status' => StageRunStatus::Cancelled,
                'active_slot' => null,
                'completed_at' => $now,
            ])->save();
            $attempt->ampLaunch()->update([
                'delivery_status' => AmpDeliveryStatus::Failed,
                'launch_status' => AmpLaunchStatus::Failed,
                'last_error_code' => 'workflow_cancelled',
                'last_error_message' => 'The workflow was cancelled.',
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
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

    public function handleAmpCallback(array $payload, string $payloadHash): array
    {
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
                        'stage.reported' => $this->recordAmpReport($payload),
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

    public function ampContext(string $threadId): array
    {
        $attempt = StageRun::query()
            ->with(['stage.outgoingTransitions', 'workflowRun', 'ampLaunch'])
            ->where('amp_thread_id', $threadId)
            ->first();

        if (! $attempt || ! $attempt->ampLaunch) {
            throw new WorkflowConflict('This thread is not bound to an Orc stage attempt.');
        }

        return [
            'schema_version' => 1,
            'idempotency_key' => $attempt->ampLaunch->idempotency_key,
            'launch_event_id' => $attempt->ampLaunch->event_id,
            'stage_run_id' => $attempt->getKey(),
            'workflow_run_id' => $attempt->workflow_run_id,
            'stage_key' => $attempt->stage->key,
            'stage_name' => $attempt->stage->name,
            'attempt_number' => $attempt->attempt_number,
            'github_repository' => $attempt->workflowRun->github_repository,
            'github_issue_number' => $attempt->workflowRun->github_issue_number,
            'github_issue_url' => $attempt->workflowRun->github_issue_url,
            'report_nonce' => $attempt->ampLaunch->report_nonce,
            'github_report_url' => $attempt->github_report_url,
            'github_report_comment_id' => $attempt->github_report_comment_id,
            'allowed_outcomes' => $attempt->stage->outgoingTransitions->pluck('outcome')->values()->all(),
            'is_active' => $attempt->active_slot === 1
                && $attempt->workflowRun->status === WorkflowStatus::Running,
        ];
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
            $this->assertGitHubReport($launch->stageRun->workflowRun, $reportUrl);
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
        $launch = $this->lockedAmpLaunch($payload);

        if (! $launch->report_nonce || ! is_string($nonce) || ! hash_equals($launch->report_nonce, $nonce)) {
            throw new WorkflowConflict('The GitHub report attestation is invalid.');
        }

        $this->assertCurrentAmpAttempt($launch->stageRun);
        $this->bindAmpThread($launch->stageRun, $threadId);
        $this->assertGitHubReport($launch->stageRun->workflowRun, $reportUrl, $commentId);

        if ($launch->stageRun->github_report_url || $launch->stageRun->github_report_comment_id) {
            if (
                $launch->stageRun->github_report_url !== $reportUrl
                || $launch->stageRun->github_report_comment_id !== $commentId
            ) {
                throw new WorkflowConflict('A different GitHub report is already attested for this attempt.');
            }

            return ['accepted' => true, 'disposition' => 'already_reported'];
        }

        $launch->stageRun->forceFill([
            'github_report_url' => $reportUrl,
            'github_report_comment_id' => $commentId,
        ])->save();
        $this->recordEvent($launch->stageRun->workflowRun, $launch->stageRun, 'stage.reported', null, [
            'stage_key' => $launch->stageRun->stage->key,
            'attempt_number' => $launch->stageRun->attempt_number,
            'github_report_url' => $reportUrl,
            'github_report_comment_id' => $commentId,
            'amp_thread_id' => $threadId,
        ]);

        return ['accepted' => true, 'disposition' => 'reported'];
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
        WorkflowRun $run,
        mixed $reportUrl,
        mixed $commentId = null,
    ): void {
        if (! is_string($reportUrl) || ! $this->isGitHubUrl($reportUrl)) {
            throw new WorkflowConflict('Amp must publish its stage report on GitHub before completing.');
        }

        $path = strtolower(rtrim((string) parse_url($reportUrl, PHP_URL_PATH), '/'));
        $expectedPrefix = strtolower('/'.$run->github_repository.'/issues/'.$run->github_issue_number);
        if ($path !== $expectedPrefix) {
            throw new WorkflowConflict('The Amp report URL must belong to this workflow issue.');
        }

        if ($commentId !== null) {
            $fragment = (string) parse_url($reportUrl, PHP_URL_FRAGMENT);
            if (! is_int($commentId) || $fragment !== 'issuecomment-'.$commentId) {
                throw new WorkflowConflict('The Amp report URL must match its GitHub comment ID.');
            }
        }
    }

    private function queueAmpLaunch(WorkflowRun $run, StageRun $attempt): void
    {
        if (! config('services.amp.enabled') || $attempt->stage->type !== StageType::Agent) {
            return;
        }

        $launch = AmpLaunch::query()->firstOrCreate(
            ['stage_run_id' => $attempt->getKey()],
            [
                'event_id' => (string) Str::uuid(),
                'idempotency_key' => (string) Str::uuid(),
                'report_nonce' => bin2hex(random_bytes(32)),
                'delivery_status' => AmpDeliveryStatus::Pending,
                'launch_status' => AmpLaunchStatus::Pending,
            ],
        );

        if (! $launch->wasRecentlyCreated) {
            return;
        }

        $this->recordEvent($run, $attempt, 'stage.launch_queued', null, [
            'stage_key' => $attempt->stage->key,
            'attempt_number' => $attempt->attempt_number,
            'launch_event_id' => $launch->event_id,
        ]);
        DeliverAmpLaunch::dispatch($launch->getKey())->afterCommit();
    }

    private function createStageAttempt(
        WorkflowRun $run,
        WorkflowStage $stage,
        int $attemptNumber,
        mixed $startedAt,
    ): StageRun {
        $terminal = $stage->type === StageType::Terminal;

        return StageRun::query()->create([
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

    private function validateFeedback(string $outcome, ?string $githubFeedbackUrl): ?string
    {
        if ($outcome !== 'request_changes') {
            return null;
        }

        if (! $githubFeedbackUrl || ! $this->isGitHubUrl($githubFeedbackUrl)) {
            throw new WorkflowConflict(
                'Requesting changes requires the URL of feedback already published on GitHub.'
            );
        }

        return $githubFeedbackUrl;
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

    private function assertAmpTargetAuthorized(User $actor, string $repository): void
    {
        if (! config('services.amp.enabled')) {
            return;
        }

        $repositories = collect(config('services.amp.allowed_repositories', []))
            ->map(fn (string $allowed): string => strtolower($allowed));
        $users = collect(config('services.amp.allowed_user_emails', []))
            ->map(fn (string $email): string => strtolower($email));

        if ($repositories->isEmpty() || ! $repositories->contains(strtolower($repository))) {
            throw new WorkflowConflict('This repository is not authorized for Amp workflow execution.');
        }

        if ($users->isEmpty() || ! $users->contains(strtolower($actor->email))) {
            throw new WorkflowConflict('Your account is not authorized for Amp workflow execution.');
        }
    }

    private function isGitHubUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $scheme === 'https' && in_array($host, ['github.com', 'www.github.com'], true);
    }
}
