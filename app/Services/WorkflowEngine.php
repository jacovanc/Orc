<?php

namespace App\Services;

use App\Domain\Workflow\CompletionSource;
use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Domain\Workflow\StageRunStatus;
use App\Domain\Workflow\StageType;
use App\Domain\Workflow\WorkflowStatus;
use App\Models\StageRun;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use Illuminate\Support\Facades\DB;

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
        User $actor,
        ?string $githubFeedbackUrl = null,
    ): WorkflowRun {
        return DB::transaction(function () use (
            $run,
            $expectedAttempt,
            $outcome,
            $source,
            $actor,
            $githubFeedbackUrl,
        ) {
            $lockedRun = WorkflowRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $attempt = StageRun::query()
                ->with('stage')
                ->lockForUpdate()
                ->findOrFail($expectedAttempt->getKey());

            $this->assertOwnedBy($lockedRun, $actor);

            if ($attempt->workflow_run_id !== $lockedRun->getKey()) {
                throw new WorkflowConflict('That stage attempt does not belong to this workflow.');
            }

            if ($lockedRun->status === WorkflowStatus::Cancelled) {
                throw new WorkflowConflict('Cancelled workflows cannot accept completions.');
            }

            $this->assertSourceMatchesStage($source, $attempt->stage);
            $feedbackUrl = $this->validateFeedback($outcome, $githubFeedbackUrl);

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
        User $actor,
        array $metadata = [],
    ): void {
        WorkflowEvent::query()->create([
            'workflow_run_id' => $run->getKey(),
            'stage_run_id' => $attempt?->getKey(),
            'type' => $type,
            'actor_type' => 'user',
            'actor_id' => $actor->getKey(),
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
            CompletionSource::AgentSimulation => $stage->type === StageType::Agent,
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

    private function isGitHubUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $scheme === 'https' && in_array($host, ['github.com', 'www.github.com'], true);
    }
}
