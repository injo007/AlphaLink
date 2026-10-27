<?php

namespace App\Models;

use Database\Factories\WorkspaceGlobalTestLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceGlobalTestLog extends Model
{
    /** @use HasFactory<WorkspaceGlobalTestLogFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['workspace_id', 'actor_user_id', 'data', 'output'];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'output' => 'encrypted',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
