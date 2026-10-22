<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ConsentRecord extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'contact_id', 'action', 'purpose', 'channel',
        'basis', 'source', 'evidence_reference', 'policy_version',
        'occurred_at', 'expires_at', 'actor_user_id', 'metadata', 'idempotency_key',
        'request_fingerprint',
    ];

    protected $hidden = ['idempotency_key', 'request_fingerprint'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consent records are append-only.'));
        static::deleting(fn () => throw new LogicException('Consent records are append-only.'));
    }
}
