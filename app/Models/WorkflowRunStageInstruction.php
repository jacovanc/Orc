<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowRunStageInstruction extends Model
{
    protected $fillable = ['workflow_run_id', 'agent_mode', 'source_version', 'source', 'body'];

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }
}
