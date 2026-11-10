@extends('layouts.admin-shell')

@section('title', 'Bulk Install')

@push('styles')
    <style>
        .bulk-install-row-value { min-width: 8rem; }
        .bulk-install-row-value[readonly] { border-color: transparent; background: transparent; }
        .bulk-install-domain { min-width: 15rem; }
        .bulk-install-apply { white-space: nowrap; }
        .bulk-install-remove { margin-left: .35rem; }
    </style>
@endpush

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-tools"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">BATCH PROVISIONING // MULTI-NODE CLUSTER</div>
                    <h1 class="command-os-report-hero-title">BULK INSTALL SERVERS</h1>
                    <p class="command-os-report-hero-desc">Parallel PowerMTA deployment and DNS orchestration across multiple uninstalled servers simultaneously.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <a class="btn-command-action" href="{{ route('console.infrastructure.install-settings.edit', $workspace->id) }}">
                    <i class="fas fa-sliders-h mr-1"></i> INSTALLATION SETTINGS
                </a>
            </div>
        </header>

        <!-- 2. Table Card -->
        <div class="command-os-table-card p-4">
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
                    @foreach (['tracking' => 'TRACKING', 'proxy' => 'PROXY', 'imap' => 'IMAP', 'spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC', 'https' => 'HTTPS'] as $option => $label)
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="{{ $option }}" name="{{ $option }}" @checked(in_array($option, ['tracking', 'imap', 'spf', 'dkim', 'dmarc'], true))>
                            <label class="custom-control-label" for="{{ $option }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>
            </div>

            <p class="small text-muted mb-3"><i class="fas fa-info-circle mr-1 text-purple"></i> DNS records are auto-generated for each selected domain: TRACKING generates A/AAAA, IMAP generates MX, and SPF, DKIM and DMARC generate TXT records.</p>

            <div class="table-responsive">
                <table id="servers" class="table table-sm table-hover table-striped">
                    <thead class="thead-light">
                        <tr>
                            <th>SERVER NAME</th>
                            <th>SERVER ID</th>
                            <th>SERVER IP</th>
                            <th>SELECTED DOMAIN</th>
                            <th>APPLY TO ALL</th>
                            <th aria-label="Row actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($servers as $server)
                            <tr data-server-row>
                                <td><span style="font-weight: 700;">{{ $server->name }}</span></td>
                                <td><span class="command-mono-badge">#{{ $server->id }}</span></td>
                                <td><span style="font-family: monospace; font-weight: 600; color: #6366f1;">{{ $server->ip_address }}</span></td>
                                <td>
                                    <div class="command-select-wrapper">
                                        <select class="command-form-control command-select bulk-install-domain" data-domain-select aria-label="Selected domain for {{ $server->name }}" style="height: 34px; padding: 4px 8px; font-size: 0.8125rem;">
                                            <option value="">Select Sending Domain...</option>
                                            @foreach ($domains as $domain)
                                                <option value="{{ $domain['id'] }}">{{ $domain['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </td>
                                <td class="bulk-install-apply text-center">
                                    <div class="custom-control custom-checkbox d-inline-block">
                                        <input type="checkbox" class="custom-control-input" id="apply-domain-{{ $server->id }}" data-apply-domain aria-label="Apply this domain to all servers">
                                        <label class="custom-control-label" for="apply-domain-{{ $server->id }}"></label>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="command-action-btn action-danger bulk-install-remove" data-remove-row aria-label="Remove server from bulk installation"><i class="fa fa-times" aria-hidden="true"></i></button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <p class="mb-1 font-w600">No active, uninstalled servers are available for bulk installation.</p>
                                    <span class="text-muted" style="font-size: 0.8125rem;">Only active, uninstalled servers appear as candidates. Provision a new server or restore one from the Servers index.</span>
                                    <div class="mt-3">
                                        <a class="btn-command-primary mr-2" href="{{ route('console.infrastructure.servers.create', $workspace->id) }}">
                                            <i class="fa fa-plus mr-1"></i> Add Server
                                        </a>
                                        <a class="btn-command-action" href="{{ route('console.infrastructure.servers.index', $workspace->id) }}">
                                            <i class="fas fa-server mr-1"></i> Open Servers
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="command-modal-actions mt-3 justify-content-end">
                <button type="button" class="btn-command-primary" id="install" @disabled($servers->isEmpty()) data-install-url="{{ route('console.infrastructure.bulk-install.runs.store', $workspace->id) }}" data-log-url="{{ route('console.infrastructure.bulk-install.logs', ['workspace' => $workspace->id, 'runId' => '__token__']) }}">
                    <i class="fas fa-play mr-1"></i> START BULK INSTALLATION
                </button>
            </div>

            <div class="d-none mt-4" id="container_log_result">
                <h4 class="mb-2 font-w700" style="font-size: 0.9375rem;"><i class="fas fa-terminal mr-1 text-purple"></i> BULK INSTALLATION LOGS</h4>
                <div style="background: #090d16; border: 1px solid rgba(99, 102, 241, 0.25); border-radius: 12px; padding: 1rem; max-height: 420px; overflow-y: auto;">
                    <code id="log_result" style="white-space: pre-wrap; font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 0.8125rem; color: #4ade80; display: block;"></code>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script type="module" src="/alphalink/infrastructure/bulk-install.js"></script>
@endpush
