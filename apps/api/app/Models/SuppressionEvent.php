<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class SuppressionEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'contact_id', 'event_type', 'reason', 'scope_type',
        'scope_key', 'source', 'source_reference', 'actor_user_id',
        'effective_at', 'metadata', 'idempotency_key', 'request_fingerprint',
    ];

    protected $hidden = ['idempotency_key', 'request_fingerprint'];

    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Suppression events are append-only.'));
        static::deleting(fn () => throw new LogicException('Suppression events are append-only.'));
    }
}
