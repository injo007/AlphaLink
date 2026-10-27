<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceSpfScan extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SCANNING = 'scanning';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_KILLED = 'killed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id', 'created_by', 'file_path', 'status', 'total_lines', 'progress',
        'batch_size', 'skip_existing', 'workers', 'pid', 'worker_pids', 'total_found',
        'started_at', 'ended_at', 'failure',
    ];

    protected function casts(): array
    {
        return [
            'skip_existing' => 'boolean', 'worker_pids' => 'array', 'started_at' => 'datetime',
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
}
