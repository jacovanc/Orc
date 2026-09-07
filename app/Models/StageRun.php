<?php

namespace App\Models;

use App\Domain\Workflow\StageRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StageRun extends Model
{
    protected $fillable = [
        'workflow_run_id',
        'workflow_stage_id',
        'attempt_number',
        'status',
        'outcome',
        'amp_thread_id',
        'amp_event_id',
        'github_report_url',
        'github_report_comment_id',
        'active_slot',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StageRunStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkflowEvent::class);
    }

    public function ampLaunch(): HasOne
    {
        return $this->hasOne(AmpLaunch::class);
    }
}
