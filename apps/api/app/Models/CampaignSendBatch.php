<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignSendBatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'send_run_id', 'batch_number', 'claim_request_key', 'claim_request_fingerprint',
        'first_position', 'last_position', 'state',
        'lease_owner', 'lease_token', 'lease_expires_at', 'claim_count', 'accepted_count',
        'rejected_count', 'suppressed_count', 'uncertain_count', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'batch_number' => 'integer',
            'first_position' => 'integer',
            'last_position' => 'integer',
            'lease_expires_at' => 'immutable_datetime',
            'claim_count' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
            'suppressed_count' => 'integer',
            'uncertain_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    protected $hidden = ['claim_request_key', 'claim_request_fingerprint', 'lease_token'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignSendRun::class, 'send_run_id');
    }

    public function recipientClaims(): HasMany
    {
        return $this->hasMany(CampaignSendRecipientClaim::class, 'batch_id');
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(CampaignDeliveryAttempt::class, 'batch_id');
    }
}
