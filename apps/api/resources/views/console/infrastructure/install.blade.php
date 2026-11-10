@extends('layouts.admin-shell')

@section('title', 'Install Server')

@push('styles')
    <style>
        #single-install-ips .single-install-ip-value[readonly] { border-color: transparent; background: transparent; }
        #single-install-ips .single-install-subdomain { min-width: 12rem; }
        #single-install-ips .single-install-domain { min-width: 13rem; }
        #single-install-ips .single-install-remove { white-space: nowrap; }
    </style>
@endpush

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-hammer"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">MTA PROVISIONING ENGINE // AUTOMATED DEPLOYMENT</div>
                    <h1 class="command-os-report-hero-title">INSTALL SERVER</h1>
                    <p class="command-os-report-hero-desc">Compile and deploy PowerMTA, DNS records, HTTPS certificates, and virtual MTA pools on target servers.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <a class="btn-command-action" href="{{ route('console.infrastructure.install-settings.edit', $workspace->id) }}">
                    <i class="fas fa-sliders-h mr-1"></i> INSTALLATION SETTINGS
                </a>
            </div>
        </header>

        <!-- 2. Form & Workspace Card -->
        <div class="command-os-table-card p-4">
            @if ($servers->isEmpty())
                <div class="alert alert-info" role="status" style="border-radius: 12px; font-size: 0.8125rem;">
                    <i class="fas fa-info-circle mr-1"></i> No active, uninstalled servers are available for installation.
                </div>
            @elseif ($domains->isEmpty())
                <div class="alert alert-info" role="status" style="border-radius: 12px; font-size: 0.8125rem;">
                    <i class="fas fa-info-circle mr-1"></i> No active free or new sending domains with an active provider are available.
                </div>
            @endif

            <form id="single-install-form"
                  data-load-url="{{ route('console.infrastructure.install.load-ips', $workspace->id) }}"
                  data-install-url="{{ route('console.infrastructure.install.runs.store', $workspace->id) }}"
                  data-log-url="{{ route('console.infrastructure.bulk-install.logs', ['workspace' => $workspace->id, 'runId' => '__token__']) }}">
                <div class="row justify-content-center push">
                    <div class="col-sm-12 col-md-8 col-lg-6">
                        <div class="command-form-group">
                            <label class="command-form-label" for="single-server">
                                <i class="fas fa-server mr-1 text-purple"></i> TARGET SERVER
                            </label>
                            <div class="command-select-wrapper">
                                <select class="command-form-control command-select" id="single-server" required @disabled($servers->isEmpty() || $domains->isEmpty())>
                                    <option value="">Select Target Server ...</option>
                                    @foreach ($servers as $server)
                                        <option value="{{ $server->id }}">{{ $server->name }}{{ $server->id }} | {{ $server->ip_address }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="command-form-group">
                            <label class="command-form-label" for="single-domains">
                                <i class="fas fa-globe mr-1 text-purple"></i> SENDING DOMAINS
                            </label>
                            <select class="command-form-control" id="single-domains" multiple required @disabled($servers->isEmpty() || $domains->isEmpty()) style="min-height: 120px;">
                                @foreach ($domains as $domain)
                                    <option value="{{ $domain['id'] }}">{{ $domain['label'] }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted mt-1 d-block" style="font-size: 0.75rem;">Select domains in the order they should receive IP groups.</small>
                        </div>

                        <div class="command-form-group">
                            <div class="custom-control custom-switch custom-control-primary mb-1">
                                <input type="checkbox" class="custom-control-input" id="single-with-ipv6" checked>
                                <label class="custom-control-label" for="single-with-ipv6" style="font-size: 0.8125rem; font-weight: 600;">WITH IPV6 SUPPORT</label>
                            </div>
                        </div>

                        <div class="command-modal-actions mt-3">
                            <button class="btn-command-primary w-100" id="single-load-ips" type="button" @disabled($servers->isEmpty() || $domains->isEmpty())>
                                <i class="fas fa-sync mr-1"></i> LOAD IP ALLOCATION MAP
                            </button>
                        </div>
                    </div>
                </div>

                <div class="row push d-none mt-4" id="single-install-holder">
                    <div class="col-sm-12">
                        <div class="command-trigger-bar mb-3">
                            <div class="d-flex align-items-center" style="gap: 8px;">
                                <label class="mb-0 font-w600" for="pmta_version" style="font-size: 0.8125rem;">PowerMTA Engine:</label>
                                <div class="command-select-wrapper" style="width: 140px;">
                                    <select id="pmta_version" class="command-form-control command-select" style="height: 34px; padding: 4px 8px; font-size: 0.8125rem;">
                                        <option value="40">PMTA 4.0</option>
                                        <option value="45" selected>PMTA 4.5</option>
                                        <option value="50">PMTA 5.0</option>
                                    </select>
                                </div>
                            </div>
                            <div class="command-trigger-group">
                                @foreach (['tracking' => 'TRACKING', 'https' => 'HTTPS', 'proxy' => 'PROXY', 'imap' => 'IMAP', 'spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $option => $label)
                                    <div class="custom-control custom-switch">
                                        <input type="checkbox" class="custom-control-input" id="{{ $option }}" @checked(in_array($option, ['tracking', 'imap', 'spf', 'dkim', 'dmarc'], true))>
                                        <label class="custom-control-label" for="{{ $option }}">{{ $label }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-hover table-striped" id="single-install-ips">
                                <thead class="thead-light">
                                    <tr>
                                        <th>IP</th>
                                        <th>TYPE</th>
                                        <th>MAIN</th>
                                        <th>SUB DOMAIN</th>
                                        <th>DOMAIN</th>
                                        <th aria-label="Row actions"></th>
                                    </tr>
                                </thead>
                                <tbody id="single-install-ip-rows"></tbody>
                            </table>
                        </div>

                        <div class="command-modal-actions mt-3 justify-content-end" style="gap: 8px;">
                            <button class="btn-command-action" id="single-config-ips" type="button" disabled aria-haspopup="dialog" aria-controls="single-install-ip-config-dialog">
                                <i class="fas fa-sliders-h mr-1"></i> CONFIG IPS
                            </button>
                            <button class="btn-command-primary" id="single-install-submit" type="submit" disabled>
                                <i class="fas fa-play mr-1"></i> START INSTALLATION
                            </button>
                        </div>

                        <div class="alert alert-warning d-none mt-3" id="single-install-config-ips-note" role="status" style="border-radius: 12px; font-size: 0.8125rem;">
                            <i class="fas fa-exclamation-triangle mr-1"></i> CONFIG IPS modifies server network interfaces. Permanent mode replaces IPv4 alias files and restarts networking.
                        </div>
                        <div class="alert alert-danger d-none mt-3" id="single-install-error" role="alert" aria-live="polite" style="border-radius: 12px; font-size: 0.8125rem;"></div>

                        <div class="d-none mt-4" id="single-install-logs">
                            <h4 class="mb-2 font-w700" style="font-size: 0.9375rem;"><i class="fas fa-terminal mr-1 text-purple"></i> INSTALLATION TELEMETRY & LOGS</h4>
                            <div style="background: #090d16; border: 1px solid rgba(99, 102, 241, 0.25); border-radius: 12px; padding: 1rem; max-height: 420px; overflow-y: auto;">
                                <code id="single-install-log" style="white-space: pre-wrap; font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 0.8125rem; color: #4ade80; display: block;" aria-live="polite"></code>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            @include('console.infrastructure.partials.server-ip-config-dialog', [
                'idPrefix' => 'single-install',
                'configUrlTemplate' => route('console.infrastructure.install.config-ips', [$workspace->id, '__SERVER__']),
                'serverSelector' => '#single-server',
                'logSelector' => '#single-install-log',
                'refreshSelector' => '#single-load-ips',
            ])
        </div>
    </div>
@endsection

@push('scripts')
    <script type="module" src="/alphalink/infrastructure/single-install.js?v={{ filemtime(public_path('alphalink/infrastructure/single-install.js')) }}"></script>
    <script type="module" src="/alphalink/infrastructure/server-ip-config-dialog.js"></script>
@endpush
