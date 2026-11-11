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
                            {{-- Row 1: Primary System Identifiers (4 columns) --}}
                            <div class="row">
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'app_name', 'label' => 'APP NAME', 'type' => 'text', 'secret' => false])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'optizmo_token', 'label' => 'OPTIZMO TOKEN', 'type' => 'text', 'secret' => true])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'tracking_ingest_key', 'label' => 'TRACKING APP KEY', 'type' => 'text', 'secret' => true])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'cloud_servers_password', 'label' => 'CLOUD SERVERS PASSWORD', 'type' => 'text', 'secret' => true])
                                </div>
                            </div>

                            <hr class="command-os-divider">

                            {{-- Row 2: API Keys & Verification Tokens (3 columns) --}}
                            <div class="row">
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'ip_api_key', 'label' => 'IP API TOKEN', 'type' => 'text', 'secret' => true])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'dkim_key', 'label' => 'APP DKIM KEY', 'type' => 'text', 'secret' => true])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'hash_token', 'label' => 'FOLDERS HASH TOKEN', 'type' => 'text', 'secret' => true])
                                </div>
                            </div>

                            <hr class="command-os-divider">

                            {{-- Row 3: Cadence & Platform Extensions (3 columns) --}}
                            <div class="row">
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'extension_id', 'label' => 'SPONSOR EXTENSION ID', 'type' => 'text', 'secret' => false])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'campaign_expire', 'label' => 'CAMPAIGN EXPIRE (DAYS)', 'type' => 'number', 'secret' => false])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'offer_suppression_update', 'label' => 'UPDATE SUPPRESSION AFTER', 'type' => 'number', 'secret' => false])
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
                            {{-- Master Switch & Fixed Redirect Target --}}
                            <div class="row">
                                <div class="col-sm-12 col-md-6">
                                    @include('console.app-settings.partials.boolean', ['field' => 'apply_firewall', 'label' => 'APPLY FIREWALL'])
                                </div>
                                <div class="col-sm-12 col-md-6">
                                    @include('console.app-settings.partials.field', ['field' => 'app_redirect', 'label' => 'REDIRECT UNKNOWNS TO', 'type' => 'url', 'secret' => false, 'readonly' => true])
                                </div>
                            </div>

                            <hr class="command-os-divider">

                            {{-- Management IP Whitelists (4 equal columns) --}}
                            <div class="row">
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.multi-value', ['field' => 'dev_ips', 'label' => 'DEV IPS'])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.multi-value', ['field' => 'pmta_relay_ips', 'label' => 'PMTA RELAY SOURCE IPS'])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.multi-value', ['field' => 'production_ips', 'label' => 'PRODUCTION IPS'])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.multi-value', ['field' => 'rdp_ips', 'label' => 'RDP IPS'])
                                </div>
                            </div>

                            <hr class="command-os-divider">

                            {{-- Application Traffic Access --}}
                            <div class="row">
                                <div class="col-12">
                                    @include('console.app-settings.partials.multi-value', ['field' => 'app_ips', 'label' => 'ALLOWED APP (IPS, DDNS)'])
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
                            {{-- Row 1: Active Filter Booleans (50/50 balanced) --}}
                            <div class="row">
                                <div class="col-sm-12 col-md-6">
                                    @include('console.app-settings.partials.boolean', ['field' => 'block_proxies', 'label' => 'BLOCK PROXY IPS'])
                                </div>
                                <div class="col-sm-12 col-md-6">
                                    @include('console.app-settings.partials.boolean', ['field' => 'filter_geo', 'label' => 'BLOCK GEO'])
                                </div>
                            </div>

                            <hr class="command-os-divider">

                            {{-- Row 2: Proxy String Filters (50/50 balanced) --}}
                            <div class="row">
                                <div class="col-sm-12 col-md-6">
                                    @include('console.app-settings.partials.multi-value', ['field' => 'allowed_proxies', 'label' => 'ALLOWED PROXY (CLICK / UNSUBSCRIBE) TRAFFIC'])
                                </div>
                                <div class="col-sm-12 col-md-6">
                                    @include('console.app-settings.partials.multi-value', ['field' => 'blocked_open_traffic', 'label' => 'BLOCKED PROXY (OPEN) TRAFFIC'])
                                </div>
                            </div>

                            <div class="command-os-info-banner mt-3 mb-3">
                                <div class="command-os-info-banner-icon">
                                    <i class="fas fa-tachometer-alt"></i>
                                </div>
                                <div class="command-os-info-banner-content">
                                    <strong>BLOCK EMAIL ACTIONS SURPASSING SPEED LIMIT:</strong> Speed-trap thresholds applied in real-time across link tracking endpoints.
                                </div>
                            </div>

                            {{-- Row 3: Speed-trap Action Limits (3 equal columns) --}}
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
                            {{-- Row 1: Ports & Monitor (3 equal columns) --}}
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
                                    <span>PMTA PORT is the mail listener. PMTA WEB MONITOR PUBLIC PORT is the only browser-facing monitor port (1213 by default). PMTA 4.5/5.0 use a private backend managed by AlphaLink behind Nginx; do not open a second public port. PMTA 4.0 uses the configured port directly. SHOW PMTA MONITOR TO ALL enables public read-only access. If both DEV IPS and PRODUCTION IPS are empty, installation enables the public read-only monitor automatically.</span>
                                </div>
                            </div>

                            <hr class="command-os-divider">

                            {{-- Row 2: Proxy Coordinates (3 equal columns) --}}
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

                            <hr class="command-os-divider">

                            {{-- Row 3: Dormant Cadence & Webmail (4 equal columns) --}}
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
                            {{-- Row 1: Backup Switch & Path (50/50 balanced) --}}
                            <div class="row">
                                <div class="col-sm-12 col-md-6">
                                    @include('console.app-settings.partials.boolean', ['field' => 'allow_backup', 'label' => 'ALLOW BACKUP'])
                                </div>
                                <div class="col-sm-12 col-md-6">
                                    @include('console.app-settings.partials.field', ['field' => 'backup_path', 'label' => 'BACKUP PATH', 'type' => 'text', 'secret' => false])
                                </div>
                            </div>

                            <hr class="command-os-divider">

                            {{-- Row 2: Remote Coordinates & Credentials (4 equal columns) --}}
                            <div class="row">
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'backup_server', 'label' => 'BACKUP SERVER', 'type' => 'text', 'secret' => false, 'placeholder' => '1.1.1.1'])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'backup_port', 'label' => 'BACKUP PORT', 'type' => 'number', 'secret' => false, 'placeholder' => '22'])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'backup_username', 'label' => 'BACKUP USERNAME', 'type' => 'text', 'secret' => false, 'placeholder' => 'root'])
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-3">
                                    @include('console.app-settings.partials.field', ['field' => 'backup_password', 'label' => 'BACKUP PASSWORD', 'type' => 'text', 'secret' => true])
                                </div>
                            </div>

                            <div class="d-flex flex-wrap align-items-center justify-content-between mt-3 pt-3 border-top command-os-backup-bar" style="gap: 12px;">
                                <div class="small text-muted">
                                    <i class="fas fa-clock mr-1 text-purple"></i> Daily backup schedule runs at <code>03:30 UTC</code> when Allow Backup is ON and notifies Telegram.
                                    <span id="backup-run-status" class="d-block mt-1 font-w600"></span>
                                </div>
                                <button type="button" class="btn-command-action" id="btn-run-workspace-backup" data-backup-url="{{ route('console.app-settings.backup.run', $workspace->getKey()) }}" @if(!$canManageGeneralSettings) disabled @endif>
                                    <i class="fas fa-play-circle mr-1"></i> RUN BACKUP NOW
                                </button>
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
                            {{-- Telegram Alerts (4 equal columns) --}}
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

                            @php
                                $tgCfg = $telegramConfig ?? app(\App\Services\Telegram\TelegramAlertService::class)->getConfigForWorkspace($workspace);
                                $tgEvents = $tgCfg['event_rules'] ?? [];
                            @endphp

                            <div class="command-os-tg-events-panel mt-3 p-3" id="telegram-event-notifications-panel"
                                 data-save-url="{{ route('console.app-settings.telegram.save', $workspace->getKey()) }}"
                                 data-test-url="{{ route('console.app-settings.telegram.test', $workspace->getKey()) }}">
                                <div class="d-flex flex-wrap align-items-center justify-content-between mb-3" style="gap: 10px;">
                                    <div>
                                        <div class="d-flex align-items-center" style="gap: 8px;">
                                            <span class="command-os-tg-icon-pill"><i class="fab fa-telegram-plane"></i></span>
                                            <strong style="font-size: 0.875rem; letter-spacing: 0.02em;">TELEGRAM EVENT NOTIFICATIONS &amp; CUSTOM MESSAGES</strong>
                                            @if (!empty($tgCfg['ready']))
                                                <span class="badge-command-pill" style="color: #10b981; font-weight: 700;">
                                                    <i class="fas fa-check-circle mr-1"></i> BOT &amp; CHANNEL READY
                                                </span>
                                            @else
                                                <span class="badge-command-pill" style="color: #f59e0b; font-weight: 700;">
                                                    <i class="fas fa-exclamation-triangle mr-1"></i> SAVE BOT TOKEN &amp; CHANNEL FIRST
                                                </span>
                                            @endif
                                        </div>
                                        <p class="text-muted small mb-0 mt-1">Enable or customize the exact Telegram message template sent for Login, Lead conversions, Backups, and Offer status changes.</p>
                                    </div>
                                    <button type="button" class="btn-command-primary" id="btn-save-tg-event-rules" style="height: 36px; padding: 0 16px; font-size: 0.78rem;" @if(!$canManageGeneralSettings) disabled @endif>
                                        <i class="fas fa-save mr-1"></i> SAVE ALERT RULES
                                    </button>
                                </div>

                                <div id="tg-events-status-banner" class="alert d-none mb-3 py-2 small" role="status"></div>

                                <div class="row">
                                    @foreach ($tgEvents as $eventKey => $eventRule)
                                        <div class="col-12 col-xl-6 mb-3">
                                            <div class="command-os-tg-event-card p-3 h-100 d-flex flex-column" data-tg-event-card="{{ $eventKey }}">
                                                <div class="d-flex align-items-start justify-content-between mb-2" style="gap: 10px;">
                                                    <div class="d-flex align-items-center" style="gap: 10px;">
                                                        <span class="command-os-tg-event-icon"><i class="{{ $eventRule['icon'] ?? 'fas fa-bell' }}"></i></span>
                                                        <div>
                                                            <div class="font-w700" style="font-size: 0.85rem;">{{ $eventRule['label'] }}</div>
                                                            <div class="text-muted" style="font-size: 0.73rem;">{{ $eventRule['description'] }}</div>
                                                        </div>
                                                    </div>
                                                    <div class="custom-control custom-switch flex-shrink-0">
                                                        <input type="hidden" name="event_alert_rules[{{ $eventKey }}][enabled]" value="0">
                                                        <input type="checkbox"
                                                               class="custom-control-input tg-event-enabled-toggle"
                                                               id="tg-event-enabled-{{ $eventKey }}"
                                                               name="event_alert_rules[{{ $eventKey }}][enabled]"
                                                               value="1"
                                                               data-tg-event-enabled="{{ $eventKey }}"
                                                               @checked(!empty($eventRule['enabled']))
                                                               @if(!$canManageGeneralSettings) disabled @endif>
                                                        <label class="custom-control-label small font-w700" for="tg-event-enabled-{{ $eventKey }}">
                                                            {{ !empty($eventRule['enabled']) ? 'ON' : 'OFF' }}
                                                        </label>
                                                    </div>
                                                </div>

                                                <div class="d-flex flex-wrap align-items-center justify-content-between mb-2" style="gap: 8px;">
                                                    <div class="d-flex align-items-center" style="gap: 6px;">
                                                        <label class="mb-0 small font-w600 text-muted" for="tg-event-channel-{{ $eventKey }}">Channel:</label>
                                                        <select id="tg-event-channel-{{ $eventKey }}"
                                                                name="event_alert_rules[{{ $eventKey }}][target_channel]"
                                                                class="form-control form-control-sm command-os-tg-select"
                                                                data-tg-event-channel="{{ $eventKey }}"
                                                                @if(!$canManageGeneralSettings) disabled @endif>
                                                            <option value="main" @selected(($eventRule['target_channel'] ?? 'main') === 'main')>Main Channel</option>
                                                            <option value="it" @selected(($eventRule['target_channel'] ?? 'main') === 'it')>IT Channel</option>
                                                            <option value="both" @selected(($eventRule['target_channel'] ?? 'main') === 'both')>Both Channels</option>
                                                        </select>
                                                    </div>
                                                    <div class="d-flex align-items-center" style="gap: 6px;">
                                                        <button type="button"
                                                                class="btn-command-action tg-event-test-btn"
                                                                style="height: 28px; padding: 0 10px; font-size: 0.72rem;"
                                                                data-tg-test-event="{{ $eventKey }}"
                                                                @if(!$canManageGeneralSettings) disabled @endif>
                                                            <i class="fas fa-paper-plane mr-1"></i> Test
                                                        </button>
                                                        <button type="button"
                                                                class="btn-command-action tg-event-reset-btn"
                                                                style="height: 28px; padding: 0 10px; font-size: 0.72rem;"
                                                                data-tg-reset-event="{{ $eventKey }}"
                                                                data-default-template="{{ $eventRule['default_template'] }}"
                                                                @if(!$canManageGeneralSettings) disabled @endif>
                                                            <i class="fas fa-undo mr-1"></i> Reset
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="mb-2 d-flex flex-wrap" style="gap: 4px;">
                                                    @foreach ($eventRule['placeholders'] ?? [] as $ph)
                                                        <button type="button"
                                                                class="command-os-tg-chip"
                                                                data-tg-insert-chip="{{ $ph }}"
                                                                data-tg-target="{{ $eventKey }}"
                                                                title="Insert {{ $ph }} into message template">{{ $ph }}</button>
                                                    @endforeach
                                                </div>

                                                <textarea id="tg-event-template-{{ $eventKey }}"
                                                          name="event_alert_rules[{{ $eventKey }}][custom_template]"
                                                          class="form-control form-control-sm command-os-tg-textarea mt-auto"
                                                          rows="5"
                                                          maxlength="1500"
                                                          data-tg-event-template="{{ $eventKey }}"
                                                          @if(!$canManageGeneralSettings) readonly @endif>{{ $eventRule['custom_template'] }}</textarea>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <hr class="command-os-divider">

                            {{-- Bitly Integration --}}
                            <div class="row align-items-center">
                                <div class="col-sm-12 col-md-4">
                                    <div class="command-os-api-service-badge">
                                        <div class="command-os-api-service-icon">
                                            <i class="fas fa-link"></i>
                                        </div>
                                        <div class="command-os-api-service-meta">
                                            <div class="command-os-api-service-name">BITLY API</div>
                                            <div class="command-os-api-service-status">URL SHORTENER SERVICE</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'bitly_client_id', 'label' => 'CLIENT ID', 'type' => 'text', 'secret' => true])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'bitly_secret_key', 'label' => 'SECRET KEY', 'type' => 'text', 'secret' => true])
                                </div>
                            </div>

                            <hr class="command-os-divider">

                            {{-- Imgur Integration --}}
                            <div class="row align-items-center">
                                <div class="col-sm-12 col-md-4">
                                    <div class="command-os-api-service-badge">
                                        <div class="command-os-api-service-icon">
                                            <i class="far fa-image"></i>
                                        </div>
                                        <div class="command-os-api-service-meta">
                                            <div class="command-os-api-service-name">IMGUR API</div>
                                            <div class="command-os-api-service-status">ASSET HOSTING SERVICE</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'imgur_client_id', 'label' => 'CLIENT ID', 'type' => 'text', 'secret' => true])
                                </div>
                                <div class="col-sm-12 col-md-4">
                                    @include('console.app-settings.partials.field', ['field' => 'imgur_secret_key', 'label' => 'SECRET KEY', 'type' => 'text', 'secret' => true])
                                </div>
                            </div>

                            <hr class="command-os-divider">

                            {{-- NS.HN Integration --}}
                            <div class="row align-items-center">
                                <div class="col-sm-12 col-md-4">
                                    <div class="command-os-api-service-badge">
                                        <div class="command-os-api-service-icon">
                                            <i class="fas fa-network-wired"></i>
                                        </div>
                                        <div class="command-os-api-service-meta">
                                            <div class="command-os-api-service-name">NS.HN API</div>
                                            <div class="command-os-api-service-status">DNS DELEGATION &amp; LOOKUP</div>
                                        </div>
                                    </div>
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

@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/select2/4.0.13/select2.min.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('vendor/select2/4.0.13/select2.min.js') }}"></script>
    <script>
        $(function () {
            // Segmented pill switch click handler
            $(document).on('click', '.command-os-seg-btn', function (e) {
                e.preventDefault();
                const btn = $(this);
                const wrap = btn.closest('.command-os-seg-toggle');
                const input = wrap.find('input[type="hidden"]');
                const val = String(btn.data('val'));

                wrap.find('.command-os-seg-btn').removeClass('is-active');
                btn.addClass('is-active');
                input.val(val).trigger('change');
            });

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

        // Command OS Telegram Event Notifications & Backup Runner
        (function () {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const tgPanel = document.getElementById('telegram-event-notifications-panel');
            const banner = document.getElementById('tg-events-status-banner');
            const backupBtn = document.getElementById('btn-run-workspace-backup');
            const backupStatus = document.getElementById('backup-run-status');

            const showTgBanner = (msg, ok = true) => {
                if (!banner) return;
                banner.textContent = msg;
                banner.className = `alert mb-3 py-2 small ${ok ? 'alert-success' : 'alert-danger'}`;
                banner.classList.remove('d-none');
            };

            document.querySelectorAll('.tg-event-enabled-toggle').forEach((toggle) => {
                toggle.addEventListener('change', () => {
                    const label = toggle.closest('.custom-switch')?.querySelector('.custom-control-label');
                    if (label) label.textContent = toggle.checked ? 'ON' : 'OFF';
                });
            });

            document.querySelectorAll('[data-tg-insert-chip]').forEach((chip) => {
                chip.addEventListener('click', () => {
                    const key = chip.dataset.tgTarget;
                    const ph = chip.dataset.tgInsertChip;
                    const textarea = document.querySelector(`[data-tg-event-template="${key}"]`);
                    if (!textarea) return;
                    const start = textarea.selectionStart ?? textarea.value.length;
                    const end = textarea.selectionEnd ?? textarea.value.length;
                    textarea.value = textarea.value.slice(0, start) + ph + textarea.value.slice(end);
                    textarea.focus();
                    textarea.selectionStart = textarea.selectionEnd = start + ph.length;
                });
            });

            document.querySelectorAll('[data-tg-reset-event]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const key = btn.dataset.tgResetEvent;
                    const textarea = document.querySelector(`[data-tg-event-template="${key}"]`);
                    if (textarea && btn.dataset.defaultTemplate) {
                        textarea.value = btn.dataset.defaultTemplate;
                    }
                });
            });

            const collectEventPayload = () => {
                const events = {};
                document.querySelectorAll('[data-tg-event-card]').forEach((card) => {
                    const key = card.dataset.tgEventCard;
                    events[key] = {
                        enabled: Boolean(card.querySelector(`[data-tg-event-enabled="${key}"]`)?.checked),
                        target_channel: card.querySelector(`[data-tg-event-channel="${key}"]`)?.value || 'main',
                        custom_template: card.querySelector(`[data-tg-event-template="${key}"]`)?.value || '',
                    };
                });
                return events;
            };

            document.getElementById('btn-save-tg-event-rules')?.addEventListener('click', async function () {
                if (!tgPanel) return;
                const btn = this;
                btn.disabled = true;
                try {
                    const res = await fetch(tgPanel.dataset.saveUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ events: collectEventPayload() }),
                    });
                    const body = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(body.message || 'Could not save Telegram notification rules.');
                    showTgBanner('Telegram notification rules and custom templates saved.', true);
                } catch (err) {
                    showTgBanner(err.message || 'Save failed.', false);
                } finally {
                    btn.disabled = false;
                }
            });

            document.querySelectorAll('[data-tg-test-event]').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    if (!tgPanel) return;
                    const key = btn.dataset.tgTestEvent;
                    const card = btn.closest('[data-tg-event-card]');
                    btn.disabled = true;
                    try {
                        const res = await fetch(tgPanel.dataset.testUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify({
                                event_type: key,
                                target_channel: card?.querySelector(`[data-tg-event-channel="${key}"]`)?.value || 'main',
                                custom_template: card?.querySelector(`[data-tg-event-template="${key}"]`)?.value || '',
                            }),
                        });
                        const body = await res.json().catch(() => ({}));
                        if (!res.ok || !body.success) {
                            throw new Error(body.error || body.message || 'Telegram test delivery failed.');
                        }
                        showTgBanner(`Sent "${key}" test alert to channel(s): ${(body.sent_channels || []).join(', ')}`, true);
                    } catch (err) {
                        showTgBanner(err.message || 'Test alert failed.', false);
                    } finally {
                        btn.disabled = false;
                    }
                });
            });

            backupBtn?.addEventListener('click', async () => {
                backupBtn.disabled = true;
                if (backupStatus) {
                    backupStatus.className = 'd-block mt-1 font-w600 text-info';
                    backupStatus.textContent = 'Running workspace backup and sending Telegram alert...';
                }
                try {
                    const res = await fetch(backupBtn.dataset.backupUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });
                    const body = await res.json().catch(() => ({}));
                    if (!res.ok || !body.success) {
                        throw new Error(body.message || 'Backup failed.');
                    }
                    if (backupStatus) {
                        backupStatus.className = 'd-block mt-1 font-w600 text-success';
                        backupStatus.textContent = body.message;
                    }
                } catch (err) {
                    if (backupStatus) {
                        backupStatus.className = 'd-block mt-1 font-w600 text-danger';
                        backupStatus.textContent = err.message || 'Backup failed.';
                    }
                } finally {
                    backupBtn.disabled = false;
                }
            });
        })();
    </script>
    <style>
        .command-os-tg-events-panel {
            background: linear-gradient(145deg, #fbfaff 0%, #f4f2ff 100%);
            border: 1px solid #dfd9f6;
            border-radius: 14px;
        }
        .command-os-tg-icon-pill {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(14, 165, 233, 0.14);
            color: #0284c7;
        }
        .command-os-tg-event-card {
            background: #ffffff;
            border: 1px solid #e2dcf5;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(74, 58, 160, 0.05);
        }
        .command-os-tg-event-icon {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, #f3e8ff, #e8edff);
            color: #792bfa;
        }
        .command-os-tg-chip {
            border: 1px solid rgba(124, 58, 237, 0.25);
            background: rgba(124, 58, 237, 0.07);
            color: #6d28d9;
            border-radius: 6px;
            padding: 1px 6px;
            font-family: ui-monospace, monospace;
            font-size: 0.7rem;
            font-weight: 700;
            cursor: pointer;
        }
        .command-os-tg-chip:hover {
            background: #7c3aed;
            color: #ffffff;
        }
        .command-os-tg-textarea {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.75rem;
            line-height: 1.45;
            border-radius: 9px;
        }
        .command-os-tg-select {
            width: auto;
            min-width: 136px;
            border-radius: 8px;
        }
        html[data-command-theme='dark'] .command-os-tg-events-panel {
            background: linear-gradient(145deg, #0f172a 0%, #131d3b 100%);
            border-color: #273861;
        }
        html[data-command-theme='dark'] .command-os-tg-event-card {
            background: #0d1428;
            border-color: #25345c;
        }
        html[data-command-theme='dark'] .command-os-tg-event-icon {
            background: rgba(139, 92, 246, 0.16);
            color: #c4b5fd;
        }
        html[data-command-theme='dark'] .command-os-tg-chip {
            background: rgba(139, 92, 246, 0.15);
            border-color: rgba(167, 139, 250, 0.35);
            color: #ddd6fe;
        }
        html[data-command-theme='dark'] .command-os-tg-textarea,
        html[data-command-theme='dark'] .command-os-tg-select {
            background: #090e1f;
            border-color: #26365f;
            color: #e2e8f0;
        }
        html[data-command-theme='dark'] .command-os-backup-bar {
            border-top-color: #26365f !important;
        }
    </style>
@endpush
