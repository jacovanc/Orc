<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmpConnectionSetup extends Model
{
    protected $fillable = [
        'amp_project_connection_id',
        'public_id',
        'token',
        'token_hash',
        'status',
        'expires_at',
        'claimed_at',
        'claimed_thread_id',
        'claimed_amp_project_id',
        'controller_source_sha256',
        'webhook_url_hash',
        'last_error_code',
        'last_error_message',
        'completed_at',
        'revoked_at',
    ];

    protected $hidden = ['token', 'token_hash', 'webhook_url_hash'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AmpProjectConnection::class, 'amp_project_connection_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function displayStatus(): string
    {
        if ($this->status === 'pending' && $this->isExpired()) {
            return 'expired';
        }

        return $this->status;
    }
}
