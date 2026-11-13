<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceSpfScanWorker extends Model
{
    protected $fillable = [
        'scan_id', 'worker_index', 'status', 'stage', 'current_domain', 'domains_processed',
        'spf_records_found', 'dns_errors', 'last_lookup_duration_ms', 'last_batch_duration_ms',
        'heartbeat_at', 'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'heartbeat_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(WorkspaceSpfScan::class, 'scan_id');
    }
}
