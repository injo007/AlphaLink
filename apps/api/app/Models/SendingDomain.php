<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class SendingDomain extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id', 'name', 'state', 'verification_token', 'verified_at', 'created_by',
        'domains_provider_id', 'provider_domain_id', 'status', 'is_our', 'is_active',
        'have_who_is', 'have_renew', 'expire_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'immutable_datetime',
            'is_our' => 'boolean',
            'is_active' => 'boolean',
            'have_who_is' => 'boolean',
            'have_renew' => 'boolean',
            'expire_at' => 'date:Y-m-d',
        ];
    }

    public function domainProvider(): BelongsTo
    {
        return $this->belongsTo(DomainProvider::class, 'domains_provider_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SendingDomainHistory::class);
    }

    public function serverIps(): HasMany
    {
        return $this->hasMany(ManagedServerIp::class);
    }

    public function history(): HasOne
    {
        return $this->hasOne(SendingDomainHistory::class)->latestOfMany();
    }
}
