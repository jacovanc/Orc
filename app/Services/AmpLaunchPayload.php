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

        return [
            'schema_version' => 1,
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
            'allowed_outcomes' => $attempt->stage->outgoingTransitions
                ->pluck('outcome')
                ->values()
                ->all(),
        ];
    }
}
