<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CampaignEngagementEventSource extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'engagement_event_id', 'source_membership_id', 'source_list_id',
        'source_email_type', 'source_key', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Campaign event source provenance is append-only.'));
        static::deleting(fn () => throw new LogicException('Campaign event source provenance is append-only.'));
    }
}
