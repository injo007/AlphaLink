<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CampaignRecipientSnapshotItemSource extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'snapshot_item_id', 'source_membership_id', 'source_list_id',
        'source_email_type', 'source_key', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Campaign recipient source provenance is append-only.'));
        static::deleting(fn () => throw new LogicException('Campaign recipient source provenance is append-only.'));
    }

    public function snapshotItem(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipientSnapshotItem::class, 'snapshot_item_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(ContactListMembership::class, 'source_membership_id');
    }
}
