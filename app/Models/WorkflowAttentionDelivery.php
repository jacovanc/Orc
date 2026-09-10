<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowAttentionDelivery extends Model
{
    protected $fillable = [
        'workflow_run_id',
        'stage_run_id',
        'channel',
        'status',
        'attempts',
        'last_error',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    public function stageRun(): BelongsTo
    {
        return $this->belongsTo(StageRun::class);
    }
}
