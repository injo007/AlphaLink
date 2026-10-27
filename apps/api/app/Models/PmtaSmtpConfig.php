<?php

namespace App\Models;

use Database\Factories\PmtaSmtpConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmtaSmtpConfig extends Model
{
    /** @use HasFactory<PmtaSmtpConfigFactory> */
    use HasFactory;

    protected $fillable = ['workspace_id', 'managed_server_id', 'managed_server_ip_id', 'created_by', 'ip', 'dns', 'username', 'password', 'host', 'port', 'google_script', 'is_active'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'port' => 'integer', 'google_script' => 'boolean', 'is_active' => 'boolean'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }
}
