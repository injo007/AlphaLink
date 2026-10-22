<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SendingDomain extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'name', 'state', 'verification_token', 'verified_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime'];
    }
}
