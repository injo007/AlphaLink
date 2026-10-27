@extends('layouts.dms-admin')

@section('title', 'App Settings - DMS')

@section('content')
    <div class="block block-rounded">
        <ul class="nav nav-tabs nav-tabs-block align-items-center" data-toggle="tabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="settings_tab" href="#settings" role="tab" aria-controls="settings" aria-selected="true">{{ $canManageGeneralSettings ? 'GENERAL SETTINGS' : 'SERVER / PMTA SETTINGS' }}</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="pmta_domains_tab" href="#pmta_domains" role="tab" aria-controls="pmta_domains" aria-selected="false">PMTA DOMAINS SETTINGS</a>
            </li>
            <li class="nav-item ml-auto">
                <div class="block-options pl-3 pr-2">
                    <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
                </div>
            </li>
        </ul>
        <div class="block-content tab-content p-4">
            <div class="tab-pane active" id="settings" role="tabpanel" aria-labelledby="settings_tab">
                @if ($canManageGeneralSettings)
                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('console.app-settings.update', $workspace->id) }}" method="POST" novalidate>
                    @csrf
                    <div class="row">
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'app_name', 'label' => 'APP NAME', 'type' => 'text', 'secret' => false])
                            @include('console.app-settings.partials.field', ['field' => 'optizmo_token', 'label' => 'OPTIZMO TOKEN', 'type' => 'text', 'secret' => true])
                            @include('console.app-settings.partials.field', ['field' => 'mini_app_key', 'label' => 'TRACKING APP KEY', 'type' => 'text', 'secret' => true])
                            @include('console.app-settings.partials.field', ['field' => 'ip_api_key', 'label' => 'IP API TOKEN', 'type' => 'text', 'secret' => true])
                            @include('console.app-settings.partials.field', ['field' => 'offer_suppression_update', 'label' => 'UPDATE OFFER SUPPRESSION AFTER', 'type' => 'number', 'secret' => false])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'hash_token', 'label' => 'FOLDERS HASH TOKEN', 'type' => 'text', 'secret' => true])
                            @include('console.app-settings.partials.field', ['field' => 'dkim_key', 'label' => 'APP DKIM KEY', 'type' => 'text', 'secret' => true])
                            @include('console.app-settings.partials.field', ['field' => 'extension_id', 'label' => 'SPONSOR EXTENSION ID', 'type' => 'text', 'secret' => false])
                            @include('console.app-settings.partials.field', ['field' => 'campaign_expire', 'label' => 'Campaign Expire (days)', 'type' => 'number', 'secret' => false])
                            @include('console.app-settings.partials.field', ['field' => 'cloud_servers_password', 'label' => 'Cloud Servers Password', 'type' => 'text', 'secret' => true])
                        </div>
                        <div class="col-sm-12 col-md-6">
                            @include('console.app-settings.partials.boolean', ['field' => 'apply_firewall', 'label' => 'APPLY FIREWALL'])
                            @include('console.app-settings.partials.multi-value', ['field' => 'dev_ips', 'label' => 'DEV IPS'])
                            @include('console.app-settings.partials.multi-value', ['field' => 'pmta_relay_ips', 'label' => 'PMTA RELAY SOURCE IPS'])
                            @include('console.app-settings.partials.multi-value', ['field' => 'production_ips', 'label' => 'PRODUCTION IPS'])
                            @include('console.app-settings.partials.multi-value', ['field' => 'rdp_ips', 'label' => 'RDP IPS'])
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3"><p>APP FIREWALL SETTINGS:</p></div>
                        <div class="col-sm-12 col-md-9">
                            @include('console.app-settings.partials.multi-value', ['field' => 'app_ips', 'label' => 'ALLOWED APP (IPS, DDNS)'])
                            @include('console.app-settings.partials.field', ['field' => 'app_redirect', 'label' => 'REDIRECT UNKNOWNS TO', 'type' => 'text', 'secret' => false])
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3"><p>TRAFFIC SETTINGS:</p></div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.boolean', ['field' => 'block_proxies', 'label' => 'Block Proxy Ips'])
                            @include('console.app-settings.partials.boolean', ['field' => 'filter_geo', 'label' => 'Block Geo'])
                        </div>
                        <div class="col-sm-12 col-md-6">
                            @include('console.app-settings.partials.multi-value', ['field' => 'allowed_proxies', 'label' => 'Allowed Proxy (CLICK - UNSUBSCRIBE) Traffic (org string)'])
                            @include('console.app-settings.partials.multi-value', ['field' => 'blocked_open_traffic', 'label' => 'Blocked Proxy (OPEN) Traffic (org string)'])
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3"><p>PMTA SETTINGS:</p></div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'pmta_port', 'label' => 'PMTA PORT', 'type' => 'number', 'secret' => false])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'pmta_admin_port', 'label' => 'PMTA WEB MONITOR PUBLIC PORT', 'type' => 'number', 'secret' => false])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.boolean', ['field' => 'allow_all_monitor', 'label' => 'SHOW PMTA MONITOR TO ALL'])
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <small class="form-text text-muted">PMTA PORT is the mail listener. PMTA WEB MONITOR PUBLIC PORT is the AlphaLink monitor link port (1213 by default). PMTA 4.5/5.0 listen internally on twice that port (1213 maps to 2426) behind Nginx; PMTA 4.0 uses the configured port directly. SHOW PMTA MONITOR TO ALL enables public read-only access. PMTA RELAY SOURCE IPS must contain AlphaLink's stable public IPv4 egress address. Installs and reinstalls use it for SMTP relay and restrict PMTA admin commands to that app egress, while the 4.5/5.0 backend grants admin only to the local proxy. DEV IPS controls additional monitor access and PMTA 4.0 admin access. Saving settings affects future installs and generated links, but does not rewrite an installed server.</small>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3"><p>PROXY SETTINGS:</p></div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'proxy_port', 'label' => 'PROXY PORT', 'type' => 'number', 'secret' => false])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'proxy_username', 'label' => 'PROXY USERNAME', 'type' => 'text', 'secret' => true, 'placeholder' => 'optional'])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'proxy_password', 'label' => 'PROXY PASSWORD', 'type' => 'text', 'secret' => true, 'placeholder' => 'optional'])
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3"><p>BLOCK EMAIL THAT MAKE MORE X ACTION IN MINUTE</p></div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'bot_open', 'label' => 'CATCH BOTS OPEN', 'type' => 'number', 'secret' => false])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'bot_click', 'label' => 'CATCH BOTS CLICK', 'type' => 'number', 'secret' => false])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'bot_unsubscribe', 'label' => 'CATCH BOTS UNSUBSCRIBE', 'type' => 'number', 'secret' => false])
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3"><p>MOVE EMAILS TO DORMANTS:</p></div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.boolean', ['field' => 'move_emails_to_dormant', 'label' => 'MOVE TO DORMANT'])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'move_emails_to_dormant_interval', 'label' => 'MOVE INTERVAL IN MONTH', 'type' => 'number', 'secret' => false])
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3"><p>WEBMAIL SETTINGS:</p></div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'webmail_username', 'label' => 'WEBMAIL USERNAME', 'type' => 'text', 'secret' => true])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'webmail_password', 'label' => 'WEBMAIL PASSWORD', 'type' => 'text', 'secret' => true])
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3"><p>BACKUP SETTINGS:</p></div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.boolean', ['field' => 'allow_backup', 'label' => 'ALLOW BACKUP'])
                            @include('console.app-settings.partials.field', ['field' => 'backup_path', 'label' => 'BACKUP PATH', 'type' => 'text', 'secret' => false])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'backup_server', 'label' => 'BACKUP SERVER', 'type' => 'text', 'secret' => false, 'placeholder' => '1.1.1.1'])
                            @include('console.app-settings.partials.field', ['field' => 'backup_username', 'label' => 'BACKUP USERNAME', 'type' => 'text', 'secret' => false, 'placeholder' => 'root'])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'backup_port', 'label' => 'BACKUP PORT', 'type' => 'number', 'secret' => false, 'placeholder' => '22'])
                            @include('console.app-settings.partials.field', ['field' => 'backup_password', 'label' => 'BACKUP PASSWORD', 'type' => 'text', 'secret' => true])
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'telegram_bot_name', 'label' => 'TELEGRAM BOT NAME', 'type' => 'text', 'secret' => false])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'telegram_bot_token', 'label' => 'TELEGRAM BOT TOKEN', 'type' => 'text', 'secret' => true])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'telegram_channel', 'label' => 'TELEGRAM CHANNEL ID', 'type' => 'text', 'secret' => false])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'telegram_channel_it', 'label' => 'TELEGRAM IT CHANNEL ID', 'type' => 'text', 'secret' => false])
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3">
                            <p>BITLY API:</p>
                            <small>CALLBACK URL: AlphaLink callback not implemented.</small>
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'bitly_client_id', 'label' => 'CLIENT ID', 'type' => 'text', 'secret' => true])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'bitly_secret_key', 'label' => 'SECRET KEY', 'type' => 'text', 'secret' => true])
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3">
                            <p>IMGUR API:</p>
                            <small>CALLBACK URL: AlphaLink callback not implemented.</small>
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'imgur_client_id', 'label' => 'CLIENT ID', 'type' => 'text', 'secret' => true])
                        </div>
                        <div class="col-sm-12 col-md-3">
                            @include('console.app-settings.partials.field', ['field' => 'imgur_secret_key', 'label' => 'SECRET KEY', 'type' => 'text', 'secret' => true])
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 col-md-3"><p>NS.HN API:</p></div>
                        <div class="col-sm-12 col-md-6">
                            @include('console.app-settings.partials.field', ['field' => 'ns_hn_api', 'label' => 'API KEY', 'type' => 'text', 'secret' => true])
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-12 text-md-right">
                            <button type="submit" class="btn btn-primary">SAVE SETTINGS</button>
                        </div>
                    </div>
                </form>
                @else
                    @include('console.app-settings.partials.server-settings')
                @endif
            </div>
            @include('console.app-settings.partials.pmta-domains')
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
    </script>
@endpush
