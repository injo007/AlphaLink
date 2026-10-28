<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignSendRecipientClaim extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'send_run_id', 'recipient_snapshot_id', 'snapshot_item_id', 'batch_id', 'position', 'state',
        'lease_token', 'lease_expires_at', 'claim_count', 'accepted_at', 'last_transition_at',
    ];

    protected $hidden = ['lease_token'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'lease_expires_at' => 'immutable_datetime',
            'claim_count' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'last_transition_at' => 'immutable_datetime',
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
