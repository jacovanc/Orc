<?php

namespace App\Services;

use App\Models\AmpLaunch;

class AmpLaunchPayload
{
    public function make(AmpLaunch $launch): array
    {
        $launch->loadMissing([
            'stageRun.stage.outgoingTransitions',
            'stageRun.workflowRun',
        ]);

        $attempt = $launch->stageRun;
        $run = $attempt->workflowRun;

        return [
            'schema_version' => 1,
            'event_id' => $launch->event_id,
            'idempotency_key' => $launch->idempotency_key,
            'stage_run_id' => $attempt->getKey(),
            'workflow_run_id' => $run->getKey(),
            'stage_key' => $attempt->stage->key,
            'stage_name' => $attempt->stage->name,
            'attempt_number' => $attempt->attempt_number,
            'github_repository' => $run->github_repository,
            'github_issue_number' => $run->github_issue_number,
            'github_issue_url' => $run->github_issue_url,
            'allowed_outcomes' => $attempt->stage->outgoingTransitions
                ->pluck('outcome')
                ->values()
                ->all(),
        ];
    }
}
