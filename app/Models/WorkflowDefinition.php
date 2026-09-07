<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowDefinition extends Model
{
    protected $fillable = ['key', 'name', 'version', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(WorkflowStage::class)->orderBy('position');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }
}
