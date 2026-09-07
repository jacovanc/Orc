<?php

namespace App\Models;

use App\Domain\Workflow\AmpDeliveryStatus;
use App\Domain\Workflow\AmpLaunchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmpLaunch extends Model
{
    protected $fillable = [
        'stage_run_id',
        'event_id',
        'idempotency_key',
        'report_nonce',
        'payload_hash',
        'delivery_status',
        'launch_status',
        'delivery_attempts',
        'last_http_status',
        'last_error_code',
        'last_error_message',
        'claimed_at',
        'launched_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'delivery_status' => AmpDeliveryStatus::class,
            'launch_status' => AmpLaunchStatus::class,
            'claimed_at' => 'datetime',
            'launched_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function stageRun(): BelongsTo
    {
        return $this->belongsTo(StageRun::class);
    }
}
