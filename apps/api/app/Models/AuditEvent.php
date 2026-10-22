<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'actor_user_id', 'action', 'subject_type',
        'subject_id', 'request_id', 'properties', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['properties' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit events are append-only.'));
    }
}
