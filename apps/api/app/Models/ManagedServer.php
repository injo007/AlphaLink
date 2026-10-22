<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ManagedServer extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id', 'name', 'provider', 'servers_provider_id', 'ip_address', 'ssh_port', 'ssh_username',
        'expire_at', 'login_type',
        'expected_host_fingerprint', 'observed_host_fingerprint', 'last_probe_at',
        'state', 'host_facts', 'idempotency_key',
        'request_fingerprint', 'created_by',
    ];

    protected $hidden = ['idempotency_key', 'request_fingerprint'];

    protected function casts(): array
    {
        return [
            'host_facts' => 'array',
            'last_probe_at' => 'immutable_datetime',
            'expire_at' => 'immutable_date',
        ];
    }

    public function credential()
    {
        return $this->hasOne(ServerCredential::class);
    }

    public function serverProvider()
    {
        return $this->belongsTo(ServerProvider::class, 'servers_provider_id');
    }
}
