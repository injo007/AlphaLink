<form action="{{ route('console.infrastructure.install-settings.update', $workspace->id) }}" method="POST" novalidate>
    @csrf

    <div class="command-os-settings-card">
        <div class="command-os-settings-card-header">
            <div class="command-os-settings-card-title-group">
                <div class="command-os-settings-card-icon">
                    <i class="fas fa-server"></i>
                </div>
                <div class="command-os-settings-card-titles">
                    <h3>SERVER / PMTA INFRASTRUCTURE</h3>
                    <p>Server ports, proxy config, and firewall policies for this workspace</p>
                </div>
            </div>
            <div class="command-os-settings-card-tag">OPERATOR ACCESS</div>
        </div>
        <div class="command-os-settings-card-body">
            <div class="row">
                <div class="col-sm-12 col-md-3">
                    @include('console.app-settings.partials.field', ['field' => 'pmta_port', 'label' => 'PMTA PORT', 'type' => 'number', 'secret' => false])
                </div>
                <div class="col-sm-12 col-md-3">
                    @include('console.app-settings.partials.field', ['field' => 'pmta_admin_port', 'label' => 'PMTA WEB MONITOR PUBLIC PORT', 'type' => 'number', 'secret' => false])
                </div>
                <div class="col-sm-12 col-md-3">
                    @include('console.app-settings.partials.boolean', ['field' => 'allow_all_monitor', 'label' => 'SHOW PMTA MONITOR TO ALL'])
                    @include('console.app-settings.partials.boolean', ['field' => 'apply_firewall', 'label' => 'APPLY FIREWALL'])
                </div>
                <div class="col-sm-12 col-md-3">
                    @include('console.app-settings.partials.field', ['field' => 'proxy_port', 'label' => 'PROXY PORT', 'type' => 'number', 'secret' => false])
                </div>
            </div>
            <div class="row">
                <div class="col-sm-12 col-md-4">
                    @include('console.app-settings.partials.multi-value', ['field' => 'dev_ips', 'label' => 'DEV IPS'])
                </div>
                <div class="col-sm-12 col-md-4">
                    @include('console.app-settings.partials.multi-value', ['field' => 'production_ips', 'label' => 'PRODUCTION IPS'])
                </div>
                <div class="col-sm-12 col-md-4">
                    @include('console.app-settings.partials.multi-value', ['field' => 'rdp_ips', 'label' => 'RDP IPS'])
                </div>
            </div>
            <small class="form-text text-muted d-block mb-2">DEV IPS grants PMTA administrator access to listed IPv4 addresses. Enable SHOW PMTA MONITOR TO ALL for public read-only monitor access; administrator actions still require a DEV IP.</small>
            <small class="form-text text-muted d-block mb-0">PMTA WEB MONITOR PUBLIC PORT is the only browser-facing link port (1213 by default). PMTA 4.5/5.0 use a private backend managed by AlphaLink behind Nginx; do not open a second public port. PMTA 4.0 uses the configured port directly.</small>
        </div>
    </div>

    <div class="command-os-settings-card">
        <div class="command-os-settings-card-header">
            <div class="command-os-settings-card-title-group">
                <div class="command-os-settings-card-icon">
                    <i class="fas fa-key"></i>
                </div>
                <div class="command-os-settings-card-titles">
                    <h3>INSTALLATION CREDENTIALS</h3>
                    <p>DKIM selector, webmail, and remote cloud host credentials</p>
                </div>
            </div>
            <div class="command-os-settings-card-tag">SYSTEM SECRETS</div>
        </div>
        <div class="command-os-settings-card-body">
            <div class="row">
                <div class="col-sm-12 col-md-4">
                    @include('console.app-settings.partials.field', ['field' => 'dkim_key', 'label' => 'DKIM KEY', 'type' => 'text', 'secret' => true])
                </div>
                <div class="col-sm-12 col-md-4">
                    @include('console.app-settings.partials.field', ['field' => 'webmail_username', 'label' => 'WEBMAIL USERNAME', 'type' => 'text', 'secret' => true])
                    @include('console.app-settings.partials.field', ['field' => 'webmail_password', 'label' => 'WEBMAIL PASSWORD', 'type' => 'text', 'secret' => true])
                </div>
                <div class="col-sm-12 col-md-4">
                    @include('console.app-settings.partials.field', ['field' => 'proxy_username', 'label' => 'PROXY USERNAME', 'type' => 'text', 'secret' => true])
                    @include('console.app-settings.partials.field', ['field' => 'proxy_password', 'label' => 'PROXY PASSWORD', 'type' => 'text', 'secret' => true])
                </div>
                <div class="col-sm-12 col-md-6">
                    @include('console.app-settings.partials.field', ['field' => 'tracking_ingest_key', 'label' => 'TRACKING APP KEY', 'type' => 'text', 'secret' => true])
                </div>
                <div class="col-sm-12 col-md-6">
                    @include('console.app-settings.partials.field', ['field' => 'cloud_servers_password', 'label' => 'CLOUD SERVERS PASSWORD', 'type' => 'text', 'secret' => true])
                </div>
            </div>
        </div>
    </div>

    <div class="command-os-settings-footer-bar">
        <div class="command-os-footer-status">
            <span class="command-os-pulse-dot"></span>
            <span>Server Directives Ready</span>
        </div>
        <button type="submit" class="btn btn-primary command-os-btn-save-settings">
            <i class="fas fa-save mr-2"></i> SAVE SERVER SETTINGS
        </button>
    </div>
</form>
