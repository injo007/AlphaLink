<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceGoogleProject extends Model
{
    protected $fillable = ['workspace_id', 'name', 'service_account_json', 'allowed_users', 'is_active', 'synced_at', 'sync_error'];

    protected $hidden = ['service_account_json'];

    protected function casts(): array
    {
        return [
            'service_account_json' => 'encrypted',
            'allowed_users' => 'array',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeAvailableTo(Builder $query, Workspace $workspace, User $user): Builder
    {
        $query->where('workspace_id', $workspace->getKey());
        if (in_array($workspace->pivot?->role, ['owner', 'admin'], true)) {
            return $query;
        }

        return $query->where(function (Builder $projects) use ($user): void {
            // Rows created before project access was introduced remain visible to
            // the workspace members who already shared the Workspace page.
            $projects->whereNull('allowed_users')
                ->orWhereJsonContains('allowed_users', (int) $user->getKey());
        });
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
