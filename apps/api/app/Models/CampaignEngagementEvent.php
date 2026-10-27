<?php

namespace App\Models;

use Database\Factories\CampaignEngagementEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CampaignEngagementEvent extends Model
{
    /** @use HasFactory<CampaignEngagementEventFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'campaign_id', 'revision_id', 'recipient_snapshot_id',
        'snapshot_item_id', 'contact_id', 'audience_list_id', 'source_email_type',
        'action', 'send_id', 'isp_id', 'client_ip', 'user_agent', 'country', 'state', 'city',
        'device', 'platform', 'browser', 'is_mobile', 'dimension_ids',
        'token_sha256', 'created_at',
    ];

    protected $hidden = ['token_sha256'];

    protected function casts(): array
    {
        return [
            'dimension_ids' => 'array',
            'is_mobile' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Campaign engagement events are append-only.'));
        static::deleting(fn () => throw new LogicException('Campaign engagement events are append-only.'));
    }
}
