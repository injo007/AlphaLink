<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignRecipientSnapshot extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'campaign_id', 'revision_id', 'offer_id', 'suppression_sha256',
        'selection', 'recipient_count', 'offer_suppressed_count', 'workspace_suppressed_count',
        'duplicate_count', 'start_from', 'recipients_sha256', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'selection' => 'array',
            'recipient_count' => 'integer',
            'offer_suppressed_count' => 'integer',
            'workspace_suppressed_count' => 'integer',
            'duplicate_count' => 'integer',
            'start_from' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CampaignRecipientSnapshotItem::class, 'snapshot_id')->orderBy('position');
    }
}
