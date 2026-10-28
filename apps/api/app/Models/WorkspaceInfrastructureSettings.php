<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceInfrastructureSettings extends Model
{
    protected $fillable = [
        'workspace_id',
        'pmta_port',
        'pmta_admin_port',
        'proxy_port',
        'apply_firewall',
        'allow_all_monitor',
        'dev_ips',
        'pmta_relay_ips',
        'pmta_relay_ips_auto_managed',
        'production_ips',
        'rdp_ips',
        'dkim_key',
        'webmail_username',
        'webmail_password',
        'proxy_username',
        'proxy_password',
        'mini_app_key',
        'cloud_servers_password',
        'cloud_ssh_key_pair',
        'pmta_domain_configs',
    ];

    protected $hidden = [
        'dkim_key',
        'webmail_username',
        'webmail_password',
        'proxy_username',
        'proxy_password',
        'mini_app_key',
        'cloud_servers_password',
        'cloud_ssh_key_pair',
        'dev_ips',
        'pmta_relay_ips',
        'pmta_relay_ips_auto_managed',
        'production_ips',
        'rdp_ips',
        'pmta_domain_configs',
    ];

    protected function casts(): array
    {
        return [
            'pmta_port' => 'integer',
            'pmta_admin_port' => 'integer',
            'proxy_port' => 'integer',
            'apply_firewall' => 'boolean',
            'allow_all_monitor' => 'boolean',
            'dev_ips' => 'encrypted:array',
            'pmta_relay_ips' => 'encrypted:array',
            'pmta_relay_ips_auto_managed' => 'boolean',
            'production_ips' => 'encrypted:array',
            'rdp_ips' => 'encrypted:array',
            'pmta_domain_configs' => 'encrypted:array',
            'dkim_key' => 'encrypted',
            'webmail_username' => 'encrypted',
            'webmail_password' => 'encrypted',
            'proxy_username' => 'encrypted',
            'proxy_password' => 'encrypted',
            'mini_app_key' => 'encrypted',
            'cloud_servers_password' => 'encrypted',
            'cloud_ssh_key_pair' => 'encrypted:array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
