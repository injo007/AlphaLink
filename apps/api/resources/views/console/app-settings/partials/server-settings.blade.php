<form action="{{ route('console.infrastructure.install-settings.update', $workspace->id) }}" method="POST" novalidate>
    @csrf
    <p class="text-muted">Server and PMTA settings for this workspace.</p>
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
    <small class="form-text text-muted d-block mb-3">DEV IPS grants PMTA administrator access to listed IPv4 addresses. Enable SHOW PMTA MONITOR TO ALL for public read-only monitor access; administrator actions still require a DEV IP.</small>
    <small class="form-text text-muted d-block mb-3">PMTA WEB MONITOR PUBLIC PORT is the link port (1213 by default). PMTA 4.5/5.0 listen internally on twice that port (2426 by default) behind Nginx. PMTA 4.0 uses the configured port directly.</small>
    <hr>
    <p>INSTALLATION CREDENTIALS</p>
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
            @include('console.app-settings.partials.field', ['field' => 'mini_app_key', 'label' => 'TRACKING APP KEY', 'type' => 'text', 'secret' => true])
        </div>
        <div class="col-sm-12 col-md-6">
            @include('console.app-settings.partials.field', ['field' => 'cloud_servers_password', 'label' => 'CLOUD SERVERS PASSWORD', 'type' => 'text', 'secret' => true])
        </div>
    </div>
    <div class="row">
        <div class="col-sm-12 text-md-right">
            <button type="submit" class="btn btn-primary">SAVE SERVER SETTINGS</button>
        </div>
    </div>
</form>
