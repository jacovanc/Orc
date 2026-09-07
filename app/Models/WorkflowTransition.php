<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTransition extends Model
{
    protected $fillable = [
        'workflow_definition_id',
        'from_stage_id',
        'outcome',
        'to_stage_id',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'to_stage_id');
    }
}
