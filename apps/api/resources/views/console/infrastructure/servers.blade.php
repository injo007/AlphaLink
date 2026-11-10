@extends('layouts.admin-shell')

@section('title', 'Servers')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-server"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">INFRASTRUCTURE TELEMETRY // CORE FLEET</div>
                    <h1 class="command-os-report-hero-title">SERVERS</h1>
                    <p class="command-os-report-hero-desc">Dedicated MTA infrastructure, IP allocation matrices, and daemon lifecycle control.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <div class="command-mode-toggle-pill">
                        <span class="command-mode-label" id="label_returned">RETURNED</span>
                        <label class="command-switch">
                            <input type="checkbox" id="returned" aria-label="Show returned servers">
                            <span class="command-slider"></span>
                        </label>
                    </div>
                    <span class="command-os-live-badge">
                        <span class="command-os-live-dot"></span>
                        PMTA CLUSTER // FLEET TELEMETRY
                    </span>
                    <button type="button" class="command-os-btn-option ml-2" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                        <i class="si si-size-fullscreen"></i>
                    </button>
                </div>
            </div>
        </header>

        <!-- 2. Overview KPI Metrics -->
        <section class="command-os-all-stats-overview mb-4" aria-label="Servers Fleet Overview">
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-server"></i></span>
                <span class="all-stats-overview-copy">
                    <span class="all-stats-overview-label">TOTAL SERVERS</span>
                    <span class="all-stats-overview-value font-mono">{{ number_format($stats['total_servers'] ?? 0) }}</span>
                    <span class="all-stats-overview-sub">Registered server nodes</span>
                </span>
            </article>

            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-power-off"></i></span>
                <span class="all-stats-overview-copy">
                    <span class="all-stats-overview-label">ACTIVE STATUS</span>
                    <span class="all-stats-overview-value font-mono text-purple">{{ number_format($stats['active_servers'] ?? 0) }} ACTIVE</span>
                    <span class="all-stats-overview-sub">Online &amp; routing traffic</span>
                </span>
            </article>

            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-hammer"></i></span>
                <span class="all-stats-overview-copy">
                    <span class="all-stats-overview-label">PMTA ENGINES</span>
                    <span class="all-stats-overview-value font-mono text-purple">{{ number_format($stats['installed_servers'] ?? 0) }} INSTALLED</span>
                    <span class="all-stats-overview-sub">Daemon verified &amp; ready</span>
                </span>
            </article>

            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-network-wired"></i></span>
                <span class="all-stats-overview-copy">
                    <span class="all-stats-overview-label">IP FOOTPRINT</span>
                    <span class="all-stats-overview-value font-mono">{{ number_format($stats['total_ips'] ?? 0) }} IPs</span>
                    <span class="all-stats-overview-sub">Egress allocation pool</span>
                </span>
            </article>

            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-tasks"></i></span>
                <span class="all-stats-overview-copy">
                    <span class="all-stats-overview-label">PROVIDERS</span>
                    <span class="all-stats-overview-value font-mono">{{ number_format($stats['providers_count'] ?? 0) }}</span>
                    <span class="all-stats-overview-sub">Connected hosting networks</span>
                </span>
            </article>
        </section>

        <!-- 3. Table Card -->
        <div class="command-os-table-card">
            <div class="table-responsive">
                <table id="servers" class="table table-sm table-hover table-striped"
                       data-source-url="{{ $serversDataUrl }}"
                       data-create-url="{{ $createUrl }}"
                       data-edit-url-template="{{ $editUrlTemplate }}"
                       data-return-url="{{ route('console.infrastructure.servers.return', $workspace->id) }}"
                       data-bulk-users-url="{{ route('console.infrastructure.servers.bulk-users', $workspace->id) }}"
                       data-install-url="{{ route('console.infrastructure.install.index', $workspace->id) }}"
                       data-bulk-install-url="{{ route('console.infrastructure.bulk-install', $workspace->id) }}"
                       data-status-url-template="{{ route('console.infrastructure.servers.status', [$workspace->id, '__SERVER__']) }}"
                       data-lock-url-template="{{ route('console.infrastructure.servers.lock', [$workspace->id, '__SERVER__']) }}"
                       data-restore-url-template="{{ route('console.infrastructure.servers.restore', [$workspace->id, '__SERVER__']) }}"
                       data-history-url-template="{{ route('console.infrastructure.servers.history', [$workspace->id, '__SERVER__']) }}"
                       data-console-url-template="{{ route('console.infrastructure.servers.console', [$workspace->id, '__SERVER__']) }}"
                       data-ips-url-template="{{ route('console.infrastructure.servers.ips', [$workspace->id, '__SERVER__']) }}"
                       data-users-url-template="{{ route('console.infrastructure.servers.users', [$workspace->id, '__SERVER__']) }}"
                       data-can-manage="{{ $canManageServers ? 'true' : 'false' }}"
                       data-can-return="{{ $canReturnServers ? 'true' : 'false' }}"
                       data-returned="false"></table>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .server-frame-dialog {
            border: 0;
            background: transparent;
            height: min(78vh, 760px);
            max-height: calc(100dvh - 2rem);
            max-width: calc(100vw - 2rem);
            overflow: visible;
            padding: 0;
            width: min(1140px, calc(100vw - 2rem));
        }
        .server-frame-dialog::backdrop { background: rgb(0 0 0 / 45%); }
        .server-frame-dialog .modal-dialog { height: 100%; margin: 0; max-width: none; width: 100%; }
        .server-frame-dialog .modal-content { display: flex; height: 100%; overflow: hidden; }
        .server-frame-dialog .modal-body { flex: 1 1 auto; min-height: 0; overflow: auto; padding: 0; }
        .server-frame-dialog iframe { border: 0; display: block; height: 100%; min-height: 450px; width: 100%; }
        .server-frame-dialog .close { font-size: 1.5rem; }
        .bootbox .server-action-frame { border: 0; display: block; height: min(88vh, 840px); min-height: 640px; width: 100%; }
        @media (max-width: 575.98px) {
            .server-frame-dialog { height: calc(100dvh - 1rem); max-height: calc(100dvh - 1rem); max-width: calc(100vw - 1rem); width: calc(100vw - 1rem); }
            .server-frame-dialog iframe { min-height: 360px; }
            .bootbox .server-action-frame { height: 68vh; min-height: 360px; }
        }
        #servers { table-layout: fixed; width: 100% !important; }
        #servers th, #servers td { box-sizing: border-box; }
        #servers th { font-size: .72rem; padding-left: .2rem; padding-right: .2rem; white-space: nowrap; }
        #servers td { overflow-wrap: anywhere; }
        #servers td:nth-child(5) { font-size: .72rem; white-space: nowrap; }
    </style>
@endpush

@push('scripts')
    <script>
        window.addEventListener('message', function (event) {
            if (event.data && event.data.type === 'alphalink-dialog-resize' && event.data.height) {
                $('.command-modal-dialog iframe').css('height', event.data.height + 'px');
            }
        });
    </script>
@endpush
