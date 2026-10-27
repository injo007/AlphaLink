<?php

namespace App\Models;

use Database\Factories\PmtaScheduledActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmtaScheduledAction extends Model
{
    /** @use HasFactory<PmtaScheduledActionFactory> */
    use HasFactory;

    protected $fillable = ['workspace_id', 'created_by', 'server_ids', 'queue', 'tags', 'action', 'frequency', 'is_active', 'last_run_at'];

    protected function casts(): array
    {
        return ['server_ids' => 'array', 'tags' => 'array', 'is_active' => 'boolean', 'last_run_at' => 'immutable_datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
