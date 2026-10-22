<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ContactListMembershipEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'audience_list_id', 'contact_id', 'action', 'source',
        'idempotency_key', 'request_fingerprint', 'actor_user_id',
        'occurred_at', 'metadata', 'created_at',
    ];

    protected $hidden = ['idempotency_key', 'request_fingerprint'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Membership events are append-only.'));
        static::deleting(fn () => throw new LogicException('Membership events are append-only.'));
    }
}
