<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AmpProjectConnection extends Model
{
    protected $fillable = [
        'project_id',
        'public_id',
        'version',
        'amp_project_id',
        'controller_key',
        'launch_webhook_url',
        'launch_signing_secret',
        'callback_signing_secret',
        'status',
        'verification_event_id',
        'verification_secret',
        'verification_secret_hash',
        'verification_payload',
        'verification_attempts',
        'verification_claimed_at',
        'verification_thread_id',
        'verified_at',
        'last_error_code',
        'last_error_message',
    ];

    protected $hidden = [
        'launch_webhook_url',
        'launch_signing_secret',
        'callback_signing_secret',
        'verification_secret',
        'verification_secret_hash',
        'verification_payload',
    ];

    protected function casts(): array
    {
        return [
            'launch_webhook_url' => 'encrypted',
            'launch_signing_secret' => 'encrypted',
            'callback_signing_secret' => 'encrypted',
            'verification_secret' => 'encrypted',
            'verification_payload' => 'encrypted',
            'verification_claimed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workflowRuns(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    public function ampLaunches(): HasMany
    {
        return $this->hasMany(AmpLaunch::class);
    }

    public function setups(): HasMany
    {
        return $this->hasMany(AmpConnectionSetup::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'verified' && $this->verified_at !== null;
    }
}
