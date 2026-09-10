<?php

namespace App\Services;

use App\Domain\Workflow\StageType;
use App\Jobs\SendWorkflowAttentionEmail;
use App\Models\StageRun;
use App\Models\WorkflowAttentionDelivery;
use App\Models\WorkflowRun;
use App\Models\WorkflowStage;

class WorkflowAttentionNotifier
{
    public function schedule(WorkflowRun $run, StageRun $attempt, WorkflowStage $stage): ?WorkflowAttentionDelivery
    {
        if (! config('workflow.notifications.email_enabled') || $stage->type !== StageType::Human) {
            return null;
        }

        $delivery = WorkflowAttentionDelivery::query()->firstOrCreate([
            'workflow_run_id' => $run->getKey(),
            'stage_run_id' => $attempt->getKey(),
            'channel' => 'mail',
        ]);

        if ($delivery->wasRecentlyCreated) {
            SendWorkflowAttentionEmail::dispatch($delivery->getKey())->afterCommit();
        }

        return $delivery;
    }
}
