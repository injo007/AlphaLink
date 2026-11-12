<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ManagedServer extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id', 'name', 'provider', 'servers_provider_id', 'ip_address', 'country', 'ssh_port', 'ssh_username',
        'expire_at', 'login_type', 'is_active', 'is_installed', 'is_locked',
        'expected_host_fingerprint', 'observed_host_fingerprint', 'last_probe_at', 'install_parameters',
        'state', 'host_facts', 'idempotency_key',
        'request_fingerprint', 'created_by',
    ];

    protected $hidden = ['idempotency_key', 'request_fingerprint'];

    protected function casts(): array
    {
        return [
            'host_facts' => 'array',
            'install_parameters' => 'array',
            'is_active' => 'boolean',
            'is_installed' => 'boolean',
            'is_locked' => 'boolean',
            'last_probe_at' => 'immutable_datetime',
            'expire_at' => 'immutable_date',
        ];
    }

    public function credential()
    {
        return $this->hasOne(ServerCredential::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function ips(): HasMany
    {
        return $this->hasMany(ManagedServerIp::class);
    }

    public function mtas(): HasMany
    {
        return $this->hasMany(ManagedServerMta::class);
    }

    public function serverProvider()
    {
        return $this->belongsTo(ServerProvider::class, 'servers_provider_id');
    }

    public function allowedUsers()
    {
        return $this->belongsToMany(User::class, 'managed_server_user', 'managed_server_id', 'user_id')
            ->withPivot('workspace_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ManagedServerHistory::class);
    }

    public function history(): HasOne
    {
        return $this->hasOne(ManagedServerHistory::class)->latestOfMany();
    }

    /**
     * Resolve this server's primary/main active IP record (preferring install_parameters main=true, then server->ip_address).
     */
    public function resolveMainIp(?ManagedServerIp $fallback = null): ?ManagedServerIp
    {
        $activeIps = $this->relationLoaded('ips')
            ? $this->ips->where('is_active', true)->values()
            : ManagedServerIp::query()
                ->where('managed_server_id', $this->getKey())
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

        $rows = data_get($this->install_parameters, 'data', []);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && ($row['main'] ?? false) === true && is_string($row['ip'] ?? null) && $row['ip'] !== '') {
                    $matched = $activeIps->firstWhere('ip', trim($row['ip']));
                    if ($matched instanceof ManagedServerIp) {
                        return $matched;
                    }
                }
            }
        }

        $serverPrimary = $activeIps->firstWhere('ip', trim((string) $this->ip_address));
        if ($serverPrimary instanceof ManagedServerIp) {
            return $serverPrimary;
        }

        $firstActive = $activeIps->first();

        return $firstActive instanceof ManagedServerIp ? $firstActive : $fallback;
    }
}
