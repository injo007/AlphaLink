<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CampaignRecipientSnapshotItemAddress extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'snapshot_item_id';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['snapshot_item_id', 'workspace_id', 'email_lookup_hash', 'email', 'created_at'];

    protected $hidden = ['workspace_id', 'snapshot_item_id', 'email_lookup_hash', 'email'];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Campaign recipient snapshot addresses are append-only.'));
        static::deleting(fn () => throw new LogicException('Campaign recipient snapshot addresses are append-only.'));
    }
}
