<?php

namespace App\Models;

use App\Domain\Workflow\WorkflowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkflowRun extends Model
{
    protected $fillable = [
        'user_id',
        'workflow_definition_id',
        'github_repository',
        'github_issue_number',
        'github_issue_url',
        'status',
        'current_stage_id',
        'started_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'current_stage_id');
    }

    public function stageRuns(): HasMany
    {
        return $this->hasMany(StageRun::class)->orderBy('attempt_number');
    }

    public function activeStageRun(): HasOne
    {
        return $this->hasOne(StageRun::class)->where('active_slot', 1);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkflowEvent::class)->orderBy('happened_at')->orderBy('id');
    }
}
