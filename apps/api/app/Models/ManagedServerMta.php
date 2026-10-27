<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedServerMta extends Model
{
    protected $fillable = [
        'workspace_id',
        'managed_server_id',
        'managed_server_ip_id',
        'ip',
        'dns',
        'ip_type',
        'mta_type',
        'username',
        'name',
        'pmta_virtual_mta_id',
        'is_active',
        'user_id',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    public function ip(): BelongsTo
    {
        return $this->belongsTo(ManagedServerIp::class, 'managed_server_ip_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function virtualMta(): BelongsTo
    {
        return $this->belongsTo(PmtaVirtualMta::class, 'pmta_virtual_mta_id');
    }
}
