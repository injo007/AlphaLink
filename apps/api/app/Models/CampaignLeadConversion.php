<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CampaignLeadConversion extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'campaign_id', 'revision_id', 'recipient_snapshot_id',
        'snapshot_item_id', 'contact_id', 'audience_list_id', 'sponsor_id',
        'offer_id', 'provider_type', 'provider_conversion_id', 'revenue',
        'conversion_ip', 'country', 'state', 'city', 'isp_id', 'dimension_ids', 'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'revenue' => 'decimal:4',
            'dimension_ids' => 'array',
            'converted_at' => 'immutable_datetime',
        ];
    }
}
