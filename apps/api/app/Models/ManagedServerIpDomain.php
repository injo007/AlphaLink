<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedServerIpDomain extends Model
{
    protected $fillable = [
        'workspace_id', 'managed_server_id', 'managed_server_ip_id', 'sending_domain_id',
        'hostname', 'tracking_host', 'dkim_selector', 'is_primary', 'is_active',
        'sending_domain_dkim_identity_id',
        'tracking_https_ready', 'dns_verified_at', 'authentication_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'tracking_https_ready' => 'boolean',
            'dns_verified_at' => 'immutable_datetime',
            'authentication_verified_at' => 'immutable_datetime',
        ];
    }

    public function scopeReadyForSending(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNotNull('dns_verified_at')
            ->whereNotNull('authentication_verified_at')
            ->whereNotNull('sending_domain_dkim_identity_id')
            ->whereHas('signingIdentity', fn (Builder $identities): Builder => $identities
                ->where('is_active', true)
                ->whereColumn('sending_domain_dkim_identities.hostname', 'managed_server_ip_domains.hostname')
                ->whereColumn('sending_domain_dkim_identities.sending_domain_id', 'managed_server_ip_domains.sending_domain_id'))
            ->where('tracking_https_ready', true)
            ->whereNotNull('tracking_host')
            ->whereHas('ip', fn (Builder $ips): Builder => $ips->where('is_active', true))
            ->whereHas('server', fn (Builder $servers): Builder => $servers
                ->where('is_active', true)
                ->where('is_installed', true))
            ->whereHas('domain', fn (Builder $domains): Builder => $domains
                ->where('is_active', true)
                ->where('state', 'verified')
                ->whereNotNull('verified_at'));
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    public function ip(): BelongsTo
    {
        return $this->belongsTo(ManagedServerIp::class, 'managed_server_ip_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(SendingDomain::class, 'sending_domain_id');
    }

    public function signingIdentity(): BelongsTo
    {
        return $this->belongsTo(SendingDomainDkimIdentity::class, 'sending_domain_dkim_identity_id');
    }
}
