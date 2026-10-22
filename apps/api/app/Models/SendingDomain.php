<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SendingDomain extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'name', 'state', 'verification_token', 'verified_at', 'created_by',
        'domains_provider_id', 'status', 'is_our', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'immutable_datetime',
            'is_our' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function domainProvider()
    {
        return $this->belongsTo(DomainProvider::class, 'domains_provider_id');
    }
}
