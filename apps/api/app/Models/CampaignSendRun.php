<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignSendRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'campaign_id', 'revision_id', 'recipient_snapshot_id', 'requested_by',
        'send_type', 'state', 'idempotency_key', 'request_fingerprint', 'execution_options',
        'recipient_count', 'start_position', 'next_position', 'accepted_count', 'rejected_count',
        'suppressed_count', 'uncertain_count', 'started_at', 'completed_at',
    ];

    protected $hidden = ['idempotency_key', 'request_fingerprint', 'execution_options'];

    protected function casts(): array
    {
        return [
            'execution_options' => 'array',
            'recipient_count' => 'integer',
            'start_position' => 'integer',
            'next_position' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
            'suppressed_count' => 'integer',
            'uncertain_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CampaignRevision::class, 'revision_id');
    }

    public function recipientSnapshot(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipientSnapshot::class, 'recipient_snapshot_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(CampaignSendBatch::class, 'send_run_id')->orderBy('batch_number');
    }

    public function recipientClaims(): HasMany
    {
        return $this->hasMany(CampaignSendRecipientClaim::class, 'send_run_id');
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(CampaignDeliveryAttempt::class, 'send_run_id');
    }
}
