<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignDeliveryAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'send_run_id', 'snapshot_item_id', 'batch_id', 'attempt_number', 'state',
        'idempotency_key', 'worker_id', 'lease_token_sha256', 'transport_code', 'outcome_reference_sha256', 'response_sha256', 'error_code',
        'started_at', 'finished_at',
    ];

    protected $hidden = ['idempotency_key', 'worker_id', 'lease_token_sha256', 'outcome_reference_sha256', 'response_sha256'];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignSendRun::class, 'send_run_id');
    }

    public function snapshotItem(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipientSnapshotItem::class, 'snapshot_item_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CampaignSendBatch::class, 'batch_id');
    }
}
