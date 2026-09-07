<?php

namespace App\Models;

use App\Domain\Workflow\StageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowStage extends Model
{
    protected $fillable = [
        'workflow_definition_id',
        'key',
        'name',
        'type',
        'config',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'type' => StageType::class,
            'config' => 'array',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function outgoingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'from_stage_id');
    }

    public function stageRuns(): HasMany
    {
        return $this->hasMany(StageRun::class);
    }
}
