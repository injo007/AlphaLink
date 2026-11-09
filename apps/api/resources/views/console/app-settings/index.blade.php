@extends('layouts.admin-shell')

@section('title', 'Settings')

@section('content')
<div class="command-os-settings-page">
    {{-- Flagship Visionary Hero Header Card --}}
    <div class="command-os-hero-card">
        <div class="command-os-hero-card-left">
            <div class="command-os-hero-mark">
                <i class="fas fa-sliders-h"></i>
            </div>
            <div class="command-os-hero-copy">
                <span class="command-os-hero-eyebrow">SYSTEM CONFIGURATION // LEVEL-0 INFRASTRUCTURE</span>
                <h1 class="command-os-hero-title">WORKSPACE SETTINGS</h1>
            </div>
        </div>
        <div class="command-os-hero-card-right">
            <div class="command-os-status-pill">
                <span class="command-os-pulse-dot"></span>
                <span>CLUSTER: ACTIVE // NODE-01</span>
            </div>
            <button type="button" class="command-os-btn-option ml-2" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                <i class="si si-size-fullscreen"></i>
            </button>
        </div>
    </div>

    {{-- Main Settings Block with Tabs --}}
    <div class="block command-os-settings-block">
        <div class="command-os-settings-nav-bar">
            <ul class="nav nav-tabs command-os-settings-tabs align-items-center" data-toggle="tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="settings_tab" href="#settings" role="tab" aria-controls="settings" aria-selected="true">
                        <i class="fas fa-sliders-h mr-2"></i> {{ $canManageGeneralSettings ? 'GENERAL SETTINGS' : 'SERVER / PMTA SETTINGS' }}
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="pmta_domains_tab" href="#pmta_domains" role="tab" aria-controls="pmta_domains" aria-selected="false">
                        <i class="fas fa-globe mr-2"></i> PMTA DOMAINS SETTINGS
                    </a>
                </li>
            </ul>
        </div>

        <div class="block-content tab-content">
            <div class="tab-pane active" id="settings" role="tabpanel" aria-labelledby="settings_tab">
                @if ($canManageGeneralSettings)
                @if (session('status'))
                    <div class="alert alert-success alert-dismissible fade show" role="status">
                        <i class="fas fa-check-circle mr-2"></i> {{ session('status') }}
                    </div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-danger" role="alert">
                        <div class="font-w700 mb-1"><i class="fas fa-exclamation-triangle mr-1"></i> Submission errors encountered:</div>
                        <ul class="mb-0 pl-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('console.app-settings.update', $workspace->id) }}" method="POST" novalidate>
                    @csrf

                    {{-- 1. Core Tokens & Identifiers --}}
                    <div class="command-os-settings-card">
                        <div class="command-os-settings-card-header">
                            <div class="command-os-settings-card-title-group">
                                <div class="command-os-settings-card-icon">
                                    <i class="fas fa-key"></i>
                                </div>
                                <div class="command-os-settings-card-titles">
                                    <h3>CORE TOKENS &amp; IDENTIFIERS</h3>
                                    <p>Primary API keys, Optizmo suppression tokens, and system authentication credentials</p>
                                </div>
                            </div>
                            <div class="command-os-settings-card-tag">10 PARAMETERS</div>
                        </div>
                        <div class="command-os-settings-card-body">
                            <div class="row">
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'app_name', 'label' => 'APP NAME', 'type' => 'text', 'secret' => false])
                                    @include('console.app-settings.partials.field', ['field' => 'optizmo_token', 'label' => 'OPTIZMO TOKEN', 'type' => 'text', 'secret' => true])
                                    @include('console.app-settings.partials.field', ['field' => 'tracking_ingest_key', 'label' => 'TRACKING APP KEY', 'type' => 'text', 'secret' => true])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'ip_api_key', 'label' => 'IP API TOKEN', 'type' => 'text', 'secret' => true])
                                    @include('console.app-settings.partials.field', ['field' => 'offer_suppression_update', 'label' => 'UPDATE OFFER SUPPRESSION AFTER', 'type' => 'number', 'secret' => false])
                                    @include('console.app-settings.partials.field', ['field' => 'hash_token', 'label' => 'FOLDERS HASH TOKEN', 'type' => 'text', 'secret' => true])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'dkim_key', 'label' => 'APP DKIM KEY', 'type' => 'text', 'secret' => true])
                                    @include('console.app-settings.partials.field', ['field' => 'extension_id', 'label' => 'SPONSOR EXTENSION ID', 'type' => 'text', 'secret' => false])
                                    @include('console.app-settings.partials.field', ['field' => 'campaign_expire', 'label' => 'Campaign Expire (days)', 'type' => 'number', 'secret' => false])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'cloud_servers_password', 'label' => 'Cloud Servers Password', 'type' => 'text', 'secret' => true])
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 2. Firewall & Access Control --}}
                    <div class="command-os-settings-card">
                        <div class="command-os-settings-card-header">
                            <div class="command-os-settings-card-title-group">
                                <div class="command-os-settings-card-icon">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div class="command-os-settings-card-titles">
                                    <h3>FIREWALL &amp; ACCESS CONTROL</h3>
                                    <p>Network boundary enforcement, authorized management IPs, and DDNS allowlists</p>
                                </div>
                            </div>
                            <div class="command-os-settings-card-tag">NETWORK POLICIES</div>
                        </div>
                        <div class="command-os-settings-card-body">
                            <div class="row">
                                <div class="col-sm-12 col-md-6 col-lg-4">
                                    @include('console.app-settings.partials.boolean', ['field' => 'apply_firewall', 'label' => 'APPLY FIREWALL'])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-4">
                                    @include('console.app-settings.partials.multi-value', ['field' => 'dev_ips', 'label' => 'DEV IPS'])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-4">
                                    @include('console.app-settings.partials.multi-value', ['field' => 'pmta_relay_ips', 'label' => 'PMTA RELAY SOURCE IPS'])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-6">
                                    @include('console.app-settings.partials.multi-value', ['field' => 'production_ips', 'label' => 'PRODUCTION IPS'])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-6">
                                    @include('console.app-settings.partials.multi-value', ['field' => 'rdp_ips', 'label' => 'RDP IPS'])
                                </div>
                            </div>

                            <hr style="border-color: rgba(139, 92, 246, 0.12); margin: 20px 0;">

                            <div class="row">
                                <div class="col-sm-12 col-md-6">
                                    @include('console.app-settings.partials.multi-value', ['field' => 'app_ips', 'label' => 'ALLOWED APP (IPS, DDNS)'])
                                </div>
                                <div class="col-sm-12 col-md-6">
                                    @include('console.app-settings.partials.field', ['field' => 'app_redirect', 'label' => 'REDIRECT UNKNOWNS TO', 'type' => 'url', 'secret' => false, 'readonly' => true])
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 3. Traffic & Anti-Bot Defense --}}
                    <div class="command-os-settings-card">
                        <div class="command-os-settings-card-header">
                            <div class="command-os-settings-card-title-group">
                                <div class="command-os-settings-card-icon">
                                    <i class="fas fa-user-shield"></i>
                                </div>
                                <div class="command-os-settings-card-titles">
                                    <h3>TRAFFIC &amp; ANTI-BOT DEFENSE</h3>
                                    <p>Real-time proxy filtering, geo-blocking, and automated bot mitigation thresholds</p>
                                </div>
                            </div>
                            <div class="command-os-settings-card-tag">FILTER RULES</div>
                        </div>
                        <div class="command-os-settings-card-body">
                            <div class="row">
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.boolean', ['field' => 'block_proxies', 'label' => 'Block Proxy Ips'])
                                    @include('console.app-settings.partials.boolean', ['field' => 'filter_geo', 'label' => 'Block Geo'])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-9">
                                    @include('console.app-settings.partials.multi-value', ['field' => 'allowed_proxies', 'label' => 'Allowed Proxy (CLICK - UNSUBSCRIBE) Traffic (org string)'])
                                    @include('console.app-settings.partials.multi-value', ['field' => 'blocked_open_traffic', 'label' => 'Blocked Proxy (OPEN) Traffic (org string)'])
                                </div>
                            </div>

                            <div class="command-os-info-banner mt-3 mb-3">
                                <div class="command-os-info-banner-icon">
                                    <i class="fas fa-tachometer-alt"></i>
                                </div>
                                <div class="command-os-info-banner-content">
                                    <strong>BLOCK EMAIL THAT MAKE MORE X ACTION IN MINUTE:</strong> Speed-trap limits applied across link tracking endpoints.
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'bot_open', 'label' => 'CATCH BOTS OPEN', 'type' => 'number', 'secret' => false])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'bot_click', 'label' => 'CATCH BOTS CLICK', 'type' => 'number', 'secret' => false])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'bot_unsubscribe', 'label' => 'CATCH BOTS UNSUBSCRIBE', 'type' => 'number', 'secret' => false])
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 4. PMTA & Mail Routing --}}
                    <div class="command-os-settings-card">
                        <div class="command-os-settings-card-header">
                            <div class="command-os-settings-card-title-group">
                                <div class="command-os-settings-card-icon">
                                    <i class="fas fa-server"></i>
                                </div>
                                <div class="command-os-settings-card-titles">
                                    <h3>PMTA &amp; MAIL ROUTING</h3>
                                    <p>Listener ports, web monitor policy, dormant email automation, and webmail access</p>
                                </div>
                            </div>
                            <div class="command-os-settings-card-tag">DELIVERY ENGINE</div>
                        </div>
                        <div class="command-os-settings-card-body">
                            <div class="row">
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'pmta_port', 'label' => 'PMTA PORT', 'type' => 'number', 'secret' => false])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'pmta_admin_port', 'label' => 'PMTA WEB MONITOR PUBLIC PORT', 'type' => 'number', 'secret' => false])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.boolean', ['field' => 'allow_all_monitor', 'label' => 'SHOW PMTA MONITOR TO ALL'])
                                </div>
                            </div>

                            <div class="command-os-info-banner mt-2 mb-3">
                                <div class="command-os-info-banner-icon">
                                    <i class="fas fa-info-circle"></i>
                                </div>
                                <div class="command-os-info-banner-content">
                                    <small class="form-text text-muted mb-0">PMTA PORT is the mail listener. PMTA WEB MONITOR PUBLIC PORT is the only browser-facing monitor port (1213 by default). PMTA 4.5/5.0 use a private backend managed by AlphaLink behind Nginx; do not open a second public port. PMTA 4.0 uses the configured port directly. SHOW PMTA MONITOR TO ALL enables public read-only access. If both DEV IPS and PRODUCTION IPS are empty, installation enables the public read-only monitor automatically; administrator commands remain restricted to AlphaLink's relay source/local proxy. PMTA RELAY SOURCE IPS is detected from this AlphaLink host's DigitalOcean/Linode metadata when available, otherwise from independent outbound IPv4 checks, during setup and deployment. Manually saving this field switches to your own address list. Saving settings affects future installs and generated links, but does not rewrite an installed server.</small>
                                </div>
                            </div>

                            <hr style="border-color: rgba(139, 92, 246, 0.12); margin: 20px 0;">

                            <div class="row">
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'proxy_port', 'label' => 'PROXY PORT', 'type' => 'number', 'secret' => false])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'proxy_username', 'label' => 'PROXY USERNAME', 'type' => 'text', 'secret' => true, 'placeholder' => 'optional'])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'proxy_password', 'label' => 'PROXY PASSWORD', 'type' => 'text', 'secret' => true, 'placeholder' => 'optional'])
                                </div>
                            </div>

                            <hr style="border-color: rgba(139, 92, 246, 0.12); margin: 20px 0;">

                            <div class="row">
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.boolean', ['field' => 'move_emails_to_dormant', 'label' => 'MOVE TO DORMANT'])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'move_emails_to_dormant_interval', 'label' => 'MOVE INTERVAL IN MONTH', 'type' => 'number', 'secret' => false])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'webmail_username', 'label' => 'WEBMAIL USERNAME', 'type' => 'text', 'secret' => true])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'webmail_password', 'label' => 'WEBMAIL PASSWORD', 'type' => 'text', 'secret' => true])
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 5. Automated Backup & Storage --}}
                    <div class="command-os-settings-card">
                        <div class="command-os-settings-card-header">
                            <div class="command-os-settings-card-title-group">
                                <div class="command-os-settings-card-icon">
                                    <i class="fas fa-database"></i>
                                </div>
                                <div class="command-os-settings-card-titles">
                                    <h3>AUTOMATED BACKUP &amp; STORAGE</h3>
                                    <p>Remote host synchronization parameters and backup destination credentials</p>
                                </div>
                            </div>
                            <div class="command-os-settings-card-tag">REDUNDANCY</div>
                        </div>
                        <div class="command-os-settings-card-body">
                            <div class="row">
                                <div class="col-sm-12 col-md-6 col-lg-4">
                                    @include('console.app-settings.partials.boolean', ['field' => 'allow_backup', 'label' => 'ALLOW BACKUP'])
                                    @include('console.app-settings.partials.field', ['field' => 'backup_path', 'label' => 'BACKUP PATH', 'type' => 'text', 'secret' => false])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-4">
                                    @include('console.app-settings.partials.field', ['field' => 'backup_server', 'label' => 'BACKUP SERVER', 'type' => 'text', 'secret' => false, 'placeholder' => '1.1.1.1'])
                                    @include('console.app-settings.partials.field', ['field' => 'backup_username', 'label' => 'BACKUP USERNAME', 'type' => 'text', 'secret' => false, 'placeholder' => 'root'])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-4">
                                    @include('console.app-settings.partials.field', ['field' => 'backup_port', 'label' => 'BACKUP PORT', 'type' => 'number', 'secret' => false, 'placeholder' => '22'])
                                    @include('console.app-settings.partials.field', ['field' => 'backup_password', 'label' => 'BACKUP PASSWORD', 'type' => 'text', 'secret' => true])
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 6. Third-Party Integrations & APIs --}}
                    <div class="command-os-settings-card">
                        <div class="command-os-settings-card-header">
                            <div class="command-os-settings-card-title-group">
                                <div class="command-os-settings-card-icon">
                                    <i class="fas fa-plug"></i>
                                </div>
                                <div class="command-os-settings-card-titles">
                                    <h3>THIRD-PARTY INTEGRATIONS &amp; APIS</h3>
                                    <p>Telegram alerts, Bitly link shortener, Imgur image hosting, and NS.HN API</p>
                                </div>
                            </div>
                            <div class="command-os-settings-card-tag">CONNECTIVITY</div>
                        </div>
                        <div class="command-os-settings-card-body">
                            <div class="row">
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'telegram_bot_name', 'label' => 'TELEGRAM BOT NAME', 'type' => 'text', 'secret' => false])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'telegram_bot_token', 'label' => 'TELEGRAM BOT TOKEN', 'type' => 'text', 'secret' => true])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'telegram_channel', 'label' => 'TELEGRAM CHANNEL ID', 'type' => 'text', 'secret' => false])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'telegram_channel_it', 'label' => 'TELEGRAM IT CHANNEL ID', 'type' => 'text', 'secret' => false])
                                </div>
                            </div>

                            <hr style="border-color: rgba(139, 92, 246, 0.12); margin: 20px 0;">

                            <div class="row">
                                <div class="col-sm-12 col-md-4">
                                    <p class="font-w700 mb-1"><i class="fas fa-link mr-1 text-primary"></i> BITLY API:</p>
                                    <small class="text-muted d-block">CALLBACK URL: AlphaLink callback not implemented.</small>
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'bitly_client_id', 'label' => 'CLIENT ID', 'type' => 'text', 'secret' => true])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'bitly_secret_key', 'label' => 'SECRET KEY', 'type' => 'text', 'secret' => true])
                                </div>
                            </div>

                            <hr style="border-color: rgba(139, 92, 246, 0.12); margin: 20px 0;">

                            <div class="row">
                                <div class="col-sm-12 col-md-4">
                                    <p class="font-w700 mb-1"><i class="far fa-image mr-1 text-primary"></i> IMGUR API:</p>
                                    <small class="text-muted d-block">CALLBACK URL: AlphaLink callback not implemented.</small>
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'imgur_client_id', 'label' => 'CLIENT ID', 'type' => 'text', 'secret' => true])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'imgur_secret_key', 'label' => 'SECRET KEY', 'type' => 'text', 'secret' => true])
                                </div>
                            </div>

                            <hr style="border-color: rgba(139, 92, 246, 0.12); margin: 20px 0;">

                            <div class="row align-items-center">
                                <div class="col-sm-12 col-md-4">
                                    <p class="font-w700 mb-1"><i class="fas fa-network-wired mr-1 text-primary"></i> NS.HN API:</p>
                                    <small class="text-muted d-block">Nameserver delegation and lookup integration key.</small>
                                </div>
                                <div class="col-sm-12 col-md-8">
                                    @include('console.app-settings.partials.field', ['field' => 'ns_hn_api', 'label' => 'API KEY', 'type' => 'text', 'secret' => true])
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Sticky Floating Save Settings Bar --}}
                    <div class="command-os-settings-footer-bar">
                        <div class="command-os-footer-status">
                            <span class="command-os-pulse-dot"></span>
                            <span>READY // BUFFERED LOCALLY</span>
                        </div>
                        <button type="submit" class="btn btn-primary command-os-btn-save-settings">
                            <i class="fas fa-check mr-2"></i> SAVE SETTINGS
                        </button>
                    </div>
                </form>
                @else
                    @include('console.app-settings.partials.server-settings')
                @endif
            </div>

            @include('console.app-settings.partials.pmta-domains')
        </div>
    </div>
</div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/select2@4.0.13/dist/js/select2.min.js"></script>
    <script>
        $(function () {
            const placeholders = {
                app_ips: 'ADD APP IPS',
                dev_ips: 'ADD DEV IPS',
                production_ips: 'ADD PRODUCTION IPS',
                rdp_ips: 'ADD RDP IPS',
                blocked_open_traffic: 'BLOCKED OPEN TRAFFIC',
                allowed_proxies: 'CHECK ALLOWED PROXIES',
            };

            $('.app-settings-tag-list').each(function () {
                $(this).select2({ allowClear: true, placeholder: placeholders[this.id], tags: true, width: '100%' });
            });

            if (window.location.hash === '#pmta_domains') {
                $('#pmta_domains_tab').tab('show');
            }
        });

        document.addEventListener('click', async function (event) {
            const revealButton = event.target.closest('[data-secret-reveal]');
            if (revealButton) {
                const field = revealButton.dataset.secretReveal;
                const input = document.querySelector(`[data-secret-input="${field}"]`);
                const error = document.querySelector(`[data-secret-error="${field}"]`);
                const copyButton = document.querySelector(`[data-secret-copy="${field}"]`);
                if (!input) return;
                error?.classList.add('d-none');

                if (input.type === 'text') {
                    input.type = 'password';
                    revealButton.textContent = 'REVEAL';
                    copyButton?.classList.add('d-none');
                    return;
                }

                revealButton.disabled = true;
                try {
                    if (input.dataset.secretLoaded !== 'true') {
                        const response = await fetch(input.dataset.secretUrl, {
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin',
                            cache: 'no-store',
                        });
                        if (!response.ok) throw new Error('Saved value unavailable');
                        const data = await response.json();
                        if (data.field !== field || typeof data.value !== 'string' || data.value === '') {
                            throw new Error('Saved value unavailable');
                        }
                        input.value = data.value;
                        input.dataset.secretLoaded = 'true';
                    }
                    input.type = 'text';
                    revealButton.textContent = 'HIDE';
                    copyButton?.classList.remove('d-none');
                } catch (errorCause) {
                    if (error) {
                        error.textContent = 'Saved value could not be retrieved. Sign in with an owner or admin account and check that the key is configured.';
                        error.classList.remove('d-none');
                    }
                } finally {
                    revealButton.disabled = false;
                }
                return;
            }

            const copyButton = event.target.closest('[data-secret-copy]');
            if (copyButton) {
                const field = copyButton.dataset.secretCopy;
                const input = document.querySelector(`[data-secret-input="${field}"]`);
                const error = document.querySelector(`[data-secret-error="${field}"]`);
                if (!input || input.type !== 'text') return;
                try {
                    await navigator.clipboard.writeText(input.value);
                    copyButton.textContent = 'COPIED';
                    window.setTimeout(() => { copyButton.textContent = 'COPY'; }, 1500);
                } catch (errorCause) {
                    if (error) {
                        error.textContent = 'Clipboard access was denied. Select the revealed value and copy it manually.';
                        error.classList.remove('d-none');
                    }
                }
            }
        });
    </script>
@endpush
