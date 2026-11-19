<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class CampaignRecipientSnapshotItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'snapshot_id', 'contact_id', 'position', 'email_lookup_hash',
        'source_list_id', 'source_email_type', 'recipient_first_name', 'recipient_last_name', 'created_at',
    ];

    protected $hidden = ['recipient_first_name', 'recipient_last_name'];

    protected function casts(): array
    {
        return [
            'recipient_first_name' => 'encrypted',
            'recipient_last_name' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Campaign recipient snapshot items are append-only.'));
        static::deleting(fn () => throw new LogicException('Campaign recipient snapshot items are append-only.'));
    }

    public function sources(): HasMany
    {
        return $this->hasMany(CampaignRecipientSnapshotItemSource::class, 'snapshot_item_id');
    }

    public function address(): HasOne
    {
        return $this->hasOne(CampaignRecipientSnapshotItemAddress::class, 'snapshot_item_id');
    }
}
