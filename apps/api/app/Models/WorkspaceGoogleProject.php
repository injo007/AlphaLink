<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceGoogleProject extends Model
{
    protected $fillable = ['workspace_id', 'name', 'service_account_json', 'is_active', 'synced_at', 'sync_error'];

    protected $hidden = ['service_account_json'];

    protected function casts(): array
    {
        return ['service_account_json' => 'encrypted', 'is_active' => 'boolean', 'synced_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(WorkspaceGoogleAccount::class, 'project_id');
    }
}
