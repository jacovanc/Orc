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
        'amp_project_connection_id',
        'event_id',
        'idempotency_key',
        'report_nonce',
        'capability_secret',
        'capability_hash',
        'payload_body',
        'payload_hash',
        'report_claimed_at',
        'cancellation_event_id',
        'cancellation_status',
        'cancellation_attempts',
        'cancellation_last_error',
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
            'capability_secret' => 'encrypted',
            'payload_body' => 'encrypted',
            'report_claimed_at' => 'datetime',
            'claimed_at' => 'datetime',
            'launched_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function stageRun(): BelongsTo
    {
        return $this->belongsTo(StageRun::class);
    }

    public function ampProjectConnection(): BelongsTo
    {
        return $this->belongsTo(AmpProjectConnection::class);
    }
}
