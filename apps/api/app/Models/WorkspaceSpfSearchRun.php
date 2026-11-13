<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceSpfSearchRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'workspace_id', 'created_by', 'ips', 'status', 'stage', 'total_roots', 'progress',
        'total_matches', 'dns_checks_limited', 'started_at', 'heartbeat_at', 'ended_at', 'failure',
    ];

    protected function casts(): array
    {
        return [
            'ips' => 'array',
            'dns_checks_limited' => 'boolean',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function results(): HasMany
    {
        return $this->hasMany(WorkspaceSpfSearchResult::class, 'search_run_id');
    }
}
