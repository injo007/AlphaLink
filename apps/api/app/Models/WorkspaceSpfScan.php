<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceSpfScan extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SCANNING = 'scanning';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_KILLED = 'killed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id', 'created_by', 'file_path', 'status', 'total_lines', 'valid_domains', 'progress',
        'batch_size', 'skip_existing', 'workers', 'partition_count', 'partition_paths', 'partition_offsets', 'worker_offsets',
        'file_size_bytes', 'preparation_bytes', 'pid', 'worker_pids', 'total_found',
        'stage', 'current_domain', 'started_at', 'heartbeat_at', 'progress_at', 'ended_at', 'failure',
    ];

    protected function casts(): array
    {
        return [
            'skip_existing' => 'boolean', 'worker_pids' => 'array', 'partition_paths' => 'array',
            'partition_offsets' => 'array', 'worker_offsets' => 'array', 'started_at' => 'datetime',
            'heartbeat_at' => 'datetime', 'progress_at' => 'datetime', 'ended_at' => 'datetime',
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

    public function workerActivity(): HasMany
    {
        return $this->hasMany(WorkspaceSpfScanWorker::class, 'scan_id')->orderBy('worker_index');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkspaceSpfScanEvent::class, 'scan_id');
    }
}
