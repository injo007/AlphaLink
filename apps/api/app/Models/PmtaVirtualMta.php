<?php

namespace App\Models;

use Database\Factories\PmtaVirtualMtaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmtaVirtualMta extends Model
{
    /** @use HasFactory<PmtaVirtualMtaFactory> */
    use HasFactory;

    protected $fillable = ['workspace_id', 'managed_server_id', 'managed_server_ip_id', 'created_by', 'ip', 'domain', 'name', 'duplicate_index', 'route', 'is_active'];

    protected function casts(): array
    {
        return ['duplicate_index' => 'integer', 'route' => 'boolean', 'is_active' => 'boolean'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    public function ipRecord(): BelongsTo
    {
        return $this->belongsTo(ManagedServerIp::class, 'managed_server_ip_id');
    }
}
