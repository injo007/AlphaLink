<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ManagedServerIp extends Model
{
    protected $fillable = [
        'workspace_id',
        'managed_server_id',
        'sending_domain_id',
        'ip',
        'type',
        'dns',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(SendingDomain::class, 'sending_domain_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ManagedServerIpHistory::class);
    }

    public function history(): HasOne
    {
        return $this->hasOne(ManagedServerIpHistory::class)->latestOfMany();
    }
}
