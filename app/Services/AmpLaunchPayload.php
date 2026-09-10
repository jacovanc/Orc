<?php

namespace App\Services;

use App\Models\AmpLaunch;

class AmpLaunchPayload
{
    public function body(AmpLaunch $launch): string
    {
        return json_encode($this->make($launch), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function make(AmpLaunch $launch): array
    {
        $launch->loadMissing([
            'ampProjectConnection',
            'stageRun.stage.outgoingTransitions',
            'stageRun.workflowRun',
        ]);

        $attempt = $launch->stageRun;
        $run = $attempt->workflowRun;
        $priorPullRequest = $run->stageRuns()
            ->where('attempt_number', '<', $attempt->attempt_number)
            ->whereNotNull('github_pull_request_number')
            ->latest('attempt_number')
            ->first();
        $reusePriorPublication = ($attempt->stage->config['reuse_prior_publication'] ?? false)
            && $priorPullRequest?->github_branch;
        $approvedQa = $run->stageRuns()
            ->with('stage')
            ->where('attempt_number', '<', $attempt->attempt_number)
            ->where('outcome', 'pass')
            ->whereNotNull('github_pull_request_head_sha')
            ->latest('attempt_number')
            ->get()
            ->first(fn ($candidate) => ($candidate->stage->config['agent_mode'] ?? null) === 'real_qa');
        $mergeReviewCycles = $run->stageRuns()
            ->with('stage')
            ->where('attempt_number', '<', $attempt->attempt_number)
            ->where('outcome', 'requires_review')
            ->get()
            ->filter(fn ($candidate) => ($candidate->stage->config['agent_mode'] ?? null) === 'real_merge')
            ->count();
        $allowedOutcomes = $attempt->stage->outgoingTransitions
            ->pluck('outcome')
            ->when(
                ($attempt->stage->config['agent_mode'] ?? null) === 'real_merge' && $mergeReviewCycles >= 1,
                fn ($outcomes) => $outcomes->reject(fn ($outcome) => $outcome === 'requires_review'),
            )
            ->values()
            ->all();

        return [
            'schema_version' => 1,
            'project_id' => $run->project_id,
            'connection_id' => $launch->ampProjectConnection->public_id,
            'amp_project_id' => $launch->ampProjectConnection->amp_project_id,
            'controller_key' => $launch->ampProjectConnection->controller_key,
            'callback_url' => url('/api/integrations/amp/connections/'.$launch->ampProjectConnection->public_id),
            'event_id' => $launch->event_id,
            'idempotency_key' => $launch->idempotency_key,
            'stage_run_id' => $attempt->getKey(),
            'workflow_run_id' => $run->getKey(),
            'stage_key' => $attempt->stage->key,
            'stage_name' => $attempt->stage->name,
            'agent_mode' => $attempt->stage->config['agent_mode']
                ?? 'proof_'.$attempt->stage->key,
            'attempt_number' => $attempt->attempt_number,
            'github_repository' => $run->github_repository,
            'github_issue_number' => $run->github_issue_number,
            'github_issue_url' => $run->github_issue_url,
            'report_nonce' => $launch->report_nonce,
            'stage_capability_url' => url('/api/integrations/amp/stage-capability'),
            'stage_capability_token' => $launch->capability_secret,
            'expected_branch' => $reusePriorPublication
                ? $priorPullRequest->github_branch
                : "orc/stage-{$attempt->getKey()}-attempt-{$attempt->attempt_number}",
            'prior_github_branch' => $priorPullRequest?->github_branch,
            'prior_pull_request_number' => $priorPullRequest?->github_pull_request_number,
            'prior_pull_request_url' => $priorPullRequest?->github_pull_request_url,
            'approved_pull_request_head_sha' => $approvedQa?->github_pull_request_head_sha,
            'merge_review_cycles' => $mergeReviewCycles,
            'allowed_outcomes' => $allowedOutcomes,
        ];
    }
}
