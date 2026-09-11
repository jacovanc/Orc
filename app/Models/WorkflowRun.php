<?php

namespace App\Models;

use App\Domain\Workflow\StageType;
use App\Domain\Workflow\WorkflowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkflowRun extends Model
{
    protected $fillable = [
        'user_id',
        'project_id',
        'amp_project_connection_id',
        'workflow_definition_id',
        'github_repository',
        'github_issue_number',
        'github_issue_url',
        'status',
        'current_stage_id',
        'started_at',
        'completed_at',
        'cancelled_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function ampProjectConnection(): BelongsTo
    {
        return $this->belongsTo(AmpProjectConnection::class);
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

    public function stageInstructions(): HasMany
    {
        return $this->hasMany(WorkflowRunStageInstruction::class);
    }

    public function needsHumanAttention(): bool
    {
        if ($this->status !== WorkflowStatus::Running) {
            return false;
        }

        $attempt = $this->relationLoaded('activeStageRun')
            ? $this->activeStageRun
            : $this->activeStageRun()->with('stage')->first();
        if ($attempt && ! $attempt->relationLoaded('stage')) {
            $attempt->load('stage');
        }

        return $attempt?->stage?->type === StageType::Human;
    }

    public function displayStatus(): string
    {
        return $this->needsHumanAttention() ? 'needs attention' : $this->status->value;
    }

    public function displayStatusClass(): string
    {
        return $this->needsHumanAttention() ? 'waiting' : $this->status->value;
    }
}
