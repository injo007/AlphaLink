<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceOfferSuppressionDownload extends Model
{
    protected $fillable = [
        'workspace_id', 'offer_id', 'requested_by', 'status', 'downloaded_bytes',
        'total_bytes', 'sha256', 'entry_count', 'processed_entries', 'format', 'failure_message',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'downloaded_bytes' => 'integer',
            'total_bytes' => 'integer',
            'entry_count' => 'integer',
            'processed_entries' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(WorkspaceOffer::class, 'offer_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
