<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WorkflowEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'happened_at';

    protected $fillable = [
        'workflow_run_id',
        'stage_run_id',
        'type',
        'actor_type',
        'actor_id',
        'metadata',
        'happened_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'happened_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Workflow events are append-only.'));
        static::deleting(fn () => throw new LogicException('Workflow events are append-only.'));
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
