<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ManagedServer extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'name', 'provider', 'ip_address', 'ssh_port', 'ssh_username',
        'expected_host_fingerprint', 'observed_host_fingerprint', 'last_probe_at',
        'state', 'host_facts', 'idempotency_key',
        'request_fingerprint', 'created_by',
    ];

    protected $hidden = ['idempotency_key', 'request_fingerprint'];

    protected function casts(): array
    {
        return ['host_facts' => 'array', 'last_probe_at' => 'immutable_datetime'];
    }

    public function credential()
    {
        return $this->hasOne(ServerCredential::class);
    }
}
