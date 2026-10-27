<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CampaignRecipientSnapshotItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'snapshot_id', 'contact_id', 'position', 'email_lookup_hash',
        'source_list_id', 'source_email_type', 'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Campaign recipient snapshot items are append-only.'));
        static::deleting(fn () => throw new LogicException('Campaign recipient snapshot items are append-only.'));
    }
}
