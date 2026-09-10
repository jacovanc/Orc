<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'github_repository',
        'amp_project_id',
        'current_amp_project_connection_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(AmpProjectConnection::class)->orderByDesc('version');
    }

    public function currentConnection(): BelongsTo
    {
        return $this->belongsTo(AmpProjectConnection::class, 'current_amp_project_connection_id');
    }

    public function workflowRuns(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    public function stageInstructionVersions(): HasMany
    {
        return $this->hasMany(ProjectStageInstructionVersion::class);
    }
}
