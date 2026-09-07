<?php

namespace App\Models;

use App\Domain\Workflow\AmpIntegrationEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AmpIntegrationEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'event_type',
        'stage_run_id',
        'payload_hash',
        'status',
        'response',
        'occurred_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AmpIntegrationEventStatus::class,
            'response' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Amp integration events are append-only.'));
        static::deleting(fn () => throw new LogicException('Amp integration events are append-only.'));
    }

    public function stageRun(): BelongsTo
    {
        return $this->belongsTo(StageRun::class);
    }
}
