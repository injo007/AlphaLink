<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'event_type', 'payload', 'idempotency_key',
        'available_at', 'published_at', 'attempts', 'last_error', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
