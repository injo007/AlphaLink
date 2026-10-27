<?php

namespace App\Models;

use Database\Factories\WorkspaceSeedListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceSeedList extends Model
{
    /** @use HasFactory<WorkspaceSeedListFactory> */
    use HasFactory;

    protected $fillable = ['workspace_id', 'user_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(WorkspaceSeedEmail::class, 'workspace_seed_list_id');
    }
}
