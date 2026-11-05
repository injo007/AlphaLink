<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarmupSendRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'campaign_id', 'revision_id', 'requested_by', 'send_type', 'state',
        'idempotency_key', 'request_fingerprint', 'execution_options', 'recipient_count',
        'next_position', 'accepted_count', 'rejected_count', 'uncertain_count', 'started_at', 'completed_at',
    ];

    protected $hidden = ['idempotency_key', 'request_fingerprint'];

    protected function casts(): array
    {
        return [
            'execution_options' => 'array',
            'recipient_count' => 'integer',
            'next_position' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
            'uncertain_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(WarmupSendRecipient::class, 'send_run_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }
}
