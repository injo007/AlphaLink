<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CampaignRevision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'campaign_id', 'revision_number', 'payload', 'content_sha256',
        'created_by', 'created_at', 'idempotency_key', 'request_fingerprint',
    ];

    protected $hidden = ['idempotency_key', 'request_fingerprint'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Campaign revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Campaign revisions are immutable.'));
    }
}
