<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceSpfScanEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['scan_id', 'level', 'event_type', 'message', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(WorkspaceSpfScan::class, 'scan_id');
    }
}
