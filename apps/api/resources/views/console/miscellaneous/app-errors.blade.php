@extends('layouts.admin-shell')

@section('title', 'App Errors - Error Intelligence Center')

@section('content')
    <div class="command-os-entity-page command-os-report-page" id="app-errors-root">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4" aria-labelledby="app-errors-hero-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas fa-shield-virus"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">OPERATIONS TOOLKIT // APP ERRORS</p>
                <h1 id="app-errors-hero-title" class="command-os-report-hero-title">APP ERROR MONITOR</h1>
                <p class="command-os-report-hero-desc">OpenObserve-powered Error Intelligence Center. Real-time exception exploration, structured telemetry, queue failures, and correlation diagnostics.</p>
            </div>
            <div class="command-os-hero-actions" style="flex-wrap: wrap;">
                @if (($intelligence['status']['mode'] ?? '') === 'openobserve')
                    <span class="badge-status-live" title="Direct telemetry ingestion active">
                        <span class="pulse-indicator pulse-green"></span>
                        <span class="status-label">OPENOBSERVE LIVE</span>
                        <span class="status-sub">({{ $intelligence['status']['stream'] ?? 'alphalink_errors' }})</span>
                    </span>
                @else
                    <span class="badge-status-fallback" title="OpenObserve offline or disabled - streaming from local logs & failed jobs">
                        <span class="pulse-indicator pulse-amber"></span>
                        <span class="status-label">LOCAL LOG FALLBACK</span>
                    </span>
                @endif

                <!-- Read-Only Auto-Refresh Selector -->
                <div class="auto-refresh-wrap d-inline-flex align-items-center" title="Read-only background polling">
                    <i class="fas fa-satellite-dish text-purple mr-1" style="font-size: 0.75rem;"></i>
                    <select id="select-auto-refresh" class="auto-refresh-select" aria-label="Auto-refresh interval">
                        <option value="0">Live: Off</option>
                        <option value="15">Live: 15s</option>
                        <option value="30">Live: 30s</option>
                        <option value="60">Live: 60s</option>
                    </select>
                    <span id="auto-refresh-countdown" class="auto-refresh-badge d-none">15s</span>
                </div>

                <button type="button" class="btn-command-action" id="btn-telegram-rules" title="Configure Telegram automated threshold alerts & custom message templates">
                    <i class="fab fa-telegram-plane mr-1" style="color: #0ea5e9;"></i>
                    <span class="d-none d-xl-inline">TELEGRAM ALERTS</span><span class="d-xl-none">ALERTS</span>
                    @if (!empty($telegramConfig['rules']['enabled']))
                        <span class="badge-tg-status-on ml-1">ON</span>
                    @endif
                </button>
                <button type="button" class="btn-command-action" id="btn-archive-logs" title="Archive active log to compressed backup (keeps last 5) and rotate cleanly">
                    <i class="fas fa-archive mr-1"></i> <span class="d-none d-xl-inline">ARCHIVE LOGS</span><span class="d-xl-none">ARCHIVE</span>
                </button>
                <button type="button" class="btn-command-action" id="btn-sync-openobserve" title="Ingest recent local logs and failed queue jobs into OpenObserve">
                    <i class="fas fa-cloud-upload-alt mr-1"></i> <span class="d-none d-xl-inline">SYNC OPENOBSERVE</span><span class="d-xl-none">SYNC</span>
                </button>
                <button type="button" class="command-os-btn-option" id="btn-refresh-errors" title="Refresh error logs">
                    <i class="fas fa-sync-alt" aria-hidden="true"></i>
                </button>
            </div>
        </header>

        <!-- Diagnostics Sub-nav Tabs -->
        <nav class="d-flex align-items-center mb-4 pb-2 border-bottom command-os-subnav" style="gap: 10px;" aria-label="System Diagnostics Navigation">
            <a href="{{ route('console.miscellaneous.app-errors', $workspace->getKey()) }}" class="btn-command-primary" aria-current="page" style="height: 36px; padding: 0 16px; font-size: 0.8125rem;">
                <i class="fas fa-bug mr-1"></i> APP ERRORS
            </a>
            <a href="{{ route('console.miscellaneous.app-health', $workspace->getKey()) }}" class="btn-command-action" style="height: 36px; padding: 0 16px; font-size: 0.8125rem;">
                <i class="fas fa-heartbeat mr-1"></i> APP HEALTH
            </a>
            <a href="{{ route('console.miscellaneous.horizon', $workspace->getKey()) }}" class="btn-command-action" style="height: 36px; padding: 0 16px; font-size: 0.8125rem;">
                <i class="fas fa-balance-scale mr-1"></i> HORIZON
            </a>
            <a href="{{ route('console.app-settings.index', $workspace->getKey()) }}" class="btn-command-action" style="height: 36px; padding: 0 16px; font-size: 0.8125rem;">
                <i class="fas fa-cogs mr-1"></i> SETTINGS
            </a>
        </nav>

        <!-- 2. Operational KPI Cards -->
        <section class="command-os-all-stats-overview mb-4" aria-label="Error Intelligence Metrics">
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-bug"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Total Exceptions</span>
                    <strong id="kpi-total-errors">{{ number_format($intelligence['total_errors'] ?? 0) }}</strong>
                    <small>Time window: {{ strtoupper($filters['time_range'] ?? '24h') }}</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-red" aria-hidden="true"><i class="fas fa-fire"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Critical / Fatal</span>
                    <strong id="kpi-critical-errors" style="color: #ef4444;">{{ number_format($intelligence['critical_count'] ?? 0) }}</strong>
                    <small>Emergency or alert level</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-amber" aria-hidden="true"><i class="fas fa-layer-group"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Failed Queue Jobs</span>
                    <strong id="kpi-failed-jobs">{{ number_format($intelligence['failed_jobs_count'] ?? 0) }}</strong>
                    <small><a href="{{ route('console.miscellaneous.horizon', $workspace->getKey()) }}" class="link-subtle">View in Horizon <i class="fas fa-arrow-right ml-1"></i></a></small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-blue" aria-hidden="true"><i class="fas fa-cubes"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Top Affected Module</span>
                    <strong id="kpi-top-module" style="font-size: 1.15rem; text-transform: uppercase;">{{ $intelligence['top_module'] ?? 'SYSTEM' }}</strong>
                    <small>Highest incident cluster</small>
                </span>
            </article>
        </section>

        <!-- 3. Trend Visualizer & Module Distribution -->
        <section class="command-os-card mb-4 p-4 error-trend-section">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom" style="gap: 12px;">
                <div>
                    <h3 class="trend-section-title mb-0"><i class="fas fa-chart-area mr-2 text-purple"></i> Incident Telemetry & Frequency Trend</h3>
                    <p class="text-muted small mb-0">Distribution of exceptions over time across core subsystem modules</p>
                </div>
                <!-- Module Breakdown Pills -->
                <div class="d-flex flex-wrap align-items-center module-pills" style="gap: 6px;">
                    <span class="module-chip module-chip-all" data-filter="all">All ({{ $intelligence['total_errors'] ?? 0 }})</span>
                    @foreach (($intelligence['module_breakdown'] ?? []) as $mod => $cnt)
                        <span class="module-chip module-chip-{{ $mod }}" data-filter="{{ $mod }}">
                            <span class="chip-dot"></span>
                            <span class="chip-name">{{ strtoupper($mod) }}</span>
                            <span class="chip-count">{{ $cnt }}</span>
                        </span>
                    @endforeach
                </div>
            </div>

            <!-- CSS Bar Trend Visualizer -->
            <div class="trend-bars-container" id="trend-bars-wrapper" aria-label="Incident frequency histogram">
                @php
                    $trendPoints = $intelligence['trend'] ?? [];
                    $maxCount = 1;
                    foreach ($trendPoints as $tp) {
                        if (($tp['count'] ?? 0) > $maxCount) {
                            $maxCount = $tp['count'];
                        }
                    }
                @endphp
                @if (empty($trendPoints))
                    <div class="text-center py-4 text-muted small">No incident frequency data in the selected range.</div>
                @else
                    <div class="trend-bars-grid">
                        @foreach ($trendPoints as $tp)
                            @php
                                $heightPct = max(4, round((($tp['count'] ?? 0) / $maxCount) * 100));
                                $hasCritical = ($tp['critical'] ?? 0) > 0;
                            @endphp
                            <div class="trend-bar-column" title="{{ $tp['label'] ?? '' }}: {{ $tp['count'] ?? 0 }} errors ({{ $tp['critical'] ?? 0 }} critical)">
                                <div class="trend-bar-track">
                                    <div class="trend-bar-fill {{ $hasCritical ? 'is-critical' : '' }}" style="height: {{ $heightPct }}%;">
                                        @if (($tp['count'] ?? 0) > 0)
                                            <span class="trend-bar-tooltip">{{ $tp['count'] }}</span>
                                        @endif
                                    </div>
                                </div>
                                <span class="trend-bar-label">{{ $tp['label'] ?? '' }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <!-- 4. Advanced Filter Toolbar -->
        <section class="command-os-card mb-4 p-3 error-filter-toolbar">
            <form id="filter-form" method="GET" action="{{ route('console.miscellaneous.app-errors', $workspace->getKey()) }}" class="filter-flex-form">
                <!-- Search input -->
                <div class="filter-item filter-item-search">
                    <div class="input-with-icon">
                        <i class="fas fa-search input-icon"></i>
                        <input type="text" name="search" id="input-search" class="form-control form-control-sm command-input" placeholder="Search message, request ID, file..." value="{{ $filters['search'] ?? '' }}">
                        @if (!empty($filters['search']))
                            <button type="button" class="btn-clear-search" id="btn-clear-search" aria-label="Clear search">&times;</button>
                        @endif
                    </div>
                </div>

                <!-- Severity Level -->
                <div class="filter-item filter-item-select">
                    <select name="level" id="select-level" class="form-control form-control-sm command-select">
                        <option value="">All Severities</option>
                        <option value="CRITICAL" @selected(($filters['level'] ?? '') === 'CRITICAL')>Critical / Fatal</option>
                        <option value="ERROR" @selected(($filters['level'] ?? '') === 'ERROR')>Error</option>
                        <option value="WARNING" @selected(($filters['level'] ?? '') === 'WARNING')>Warning</option>
                        <option value="NOTICE" @selected(($filters['level'] ?? '') === 'NOTICE')>Notice</option>
                        <option value="INFO" @selected(($filters['level'] ?? '') === 'INFO')>Info</option>
                        <option value="DEBUG" @selected(($filters['level'] ?? '') === 'DEBUG')>Debug</option>
                    </select>
                </div>

                <!-- Subsystem Module -->
                <div class="filter-item filter-item-select">
                    <select name="module" id="select-module" class="form-control form-control-sm command-select">
                        <option value="">All Modules</option>
                        <option value="horizon" @selected(($filters['module'] ?? '') === 'horizon')>Horizon Queue</option>
                        <option value="api" @selected(($filters['module'] ?? '') === 'api')>API & Webhooks</option>
                        <option value="campaign" @selected(($filters['module'] ?? '') === 'campaign')>Campaign Studio</option>
                        <option value="infrastructure" @selected(($filters['module'] ?? '') === 'infrastructure')>Mail Infrastructure</option>
                        <option value="tracking" @selected(($filters['module'] ?? '') === 'tracking')>Tracking & Redirects</option>
                        <option value="system" @selected(($filters['module'] ?? '') === 'system')>System Runtime</option>
                    </select>
                </div>

                <!-- Time Range -->
                <div class="filter-item filter-item-select">
                    <select name="time_range" id="select-time-range" class="form-control form-control-sm command-select">
                        <option value="" @selected(empty($filters['time_range']))>All Time (Recent)</option>
                        <option value="1h" @selected(($filters['time_range'] ?? '') === '1h')>Last 1 Hour</option>
                        <option value="24h" @selected(($filters['time_range'] ?? '') === '24h')>Last 24 Hours</option>
                        <option value="7d" @selected(($filters['time_range'] ?? '') === '7d')>Last 7 Days</option>
                        <option value="30d" @selected(($filters['time_range'] ?? '') === '30d')>Last 30 Days</option>
                    </select>
                </div>

                <!-- Clustering / Grouping Mode -->
                <div class="filter-item filter-item-select">
                    <select name="grouped" id="select-grouped" class="form-control form-control-sm command-select" title="Group recurring identical errors by fingerprint">
                        <option value="1" @selected(($filters['grouped'] ?? '1') !== '0')>Clustered Issues</option>
                        <option value="0" @selected(($filters['grouped'] ?? '1') === '0')>Raw Event Stream</option>
                    </select>
                </div>

                <!-- Filter Actions + Sanitized Export -->
                <div class="filter-item filter-item-actions">
                    <button type="submit" class="btn-command-primary" style="height: 34px; padding: 0 16px; font-size: 0.75rem;">
                        <i class="fas fa-filter mr-1"></i> APPLY
                    </button>
                    <a href="{{ route('console.miscellaneous.app-errors', $workspace->getKey()) }}" class="btn-command-action" style="height: 34px; padding: 0 10px; font-size: 0.75rem;" title="Reset filters">
                        <i class="fas fa-undo"></i>
                    </a>
                    <div class="dropdown">
                        <button type="button" class="btn-command-action dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="height: 34px; padding: 0 10px; font-size: 0.75rem;" title="Export sanitized telemetry">
                            <i class="fas fa-download"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-right">
                            <button type="button" class="dropdown-item small" id="btn-export-json"><i class="fas fa-code mr-2 text-purple"></i> Export Sanitized JSON</button>
                            <button type="button" class="dropdown-item small" id="btn-export-csv"><i class="fas fa-file-csv mr-2 text-success"></i> Export Sanitized CSV</button>
                        </div>
                    </div>
                </div>
            </form>
        </section>

        <!-- 5. Error Table Card / Explorer -->
        <div class="command-os-table-card p-4">
            @php
                $hits = $intelligence['hits'] ?? [];
            @endphp

            @if (empty($hits))
                <div class="text-center py-5 text-muted">
                    <div style="font-size: 2.5rem; color: #10b981; margin-bottom: 12px;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h4 class="font-w700 mb-1" style="font-size: 1rem; color: var(--co-ink, #1e293b);">No Application Errors Found</h4>
                    <p class="small text-muted mb-0">The application telemetry reports zero errors matching your selected criteria.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover command-os-table mb-0" id="table-error-intelligence">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 15%;">TIMESTAMP</th>
                                <th scope="col" style="width: 10%;">LEVEL</th>
                                <th scope="col" style="width: 12%;">MODULE</th>
                                <th scope="col" style="width: 38%;">MESSAGE & SOURCE</th>
                                <th scope="col" style="width: 15%;">CORRELATION ID</th>
                                <th scope="col" style="width: 10%; text-align: right;">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($hits as $hit)
                                @php
                                    $hitId = $hit['id'] ?? md5($hit['date'].$hit['summary']);
                                    $lvl = strtoupper($hit['level'] ?? 'ERROR');
                                    $isCrit = in_array($lvl, ['CRITICAL', 'ALERT', 'EMERGENCY', 'FATAL'], true);
                                    $mod = strtolower($hit['module'] ?? 'system');
                                @endphp
                                <tr class="error-row {{ $isCrit ? 'row-critical' : '' }}" id="row-{{ $hitId }}">
                                    <!-- Timestamp & Relative Age -->
                                    <td>
                                        <div class="d-flex flex-column">
                                            <span class="cell-date">{{ $hit['date'] ?? '' }}</span>
                                            <span class="cell-relative text-muted small">{{ $hit['relative_time'] ?? '' }}</span>
                                        </div>
                                    </td>

                                    <!-- Severity Level Pill -->
                                    <td>
                                        <span class="badge-level badge-level-{{ strtolower($lvl) }}">
                                            @if ($isCrit)
                                                <i class="fas fa-exclamation-circle mr-1"></i>
                                            @endif
                                            {{ $lvl }}
                                        </span>
                                    </td>

                                    <!-- Subsystem Module Pill -->
                                    <td>
                                        <span class="badge-module badge-module-{{ $mod }}">
                                            {{ strtoupper($mod) }}
                                        </span>
                                    </td>

                                    <!-- Message & Source Summary -->
                                    <td>
                                        <div class="cell-message-wrap">
                                            <div class="cell-message-title">
                                                @if (!empty($hit['is_failed_job']))
                                                    <span class="badge-tag-job mr-1"><i class="fas fa-layer-group"></i> QUEUE JOB</span>
                                                @endif
                                                @if (($hit['occurrences_count'] ?? 1) > 1)
                                                    <span class="badge-cluster-count mr-1" title="First: {{ $hit['first_seen'] ?? '' }} | Last: {{ $hit['last_seen'] ?? '' }}">
                                                        <i class="fas fa-layer-group mr-1"></i>Seen {{ $hit['occurrences_count'] }}x
                                                    </span>
                                                @endif
                                                <span class="message-text">{{ $hit['summary'] ?? $hit['message'] ?? 'Error' }}</span>
                                            </div>
                                            <div class="cell-source-file mt-1">
                                                <i class="far fa-file-code mr-1 text-muted"></i>
                                                <code class="file-code">{{ $hit['file'] ?? 'unknown' }}@if(!empty($hit['line'])):{{ $hit['line'] }}@endif</code>
                                                @if (!empty($hit['first_seen']) && $hit['first_seen'] !== ($hit['last_seen'] ?? ''))
                                                    <span class="text-muted small ml-2"><i class="far fa-clock mr-1"></i>Active {{ $hit['first_seen'] }} &rarr; {{ $hit['last_seen'] }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Correlation / Request ID -->
                                    <td>
                                        @if (!empty($hit['request_id']))
                                            <div class="correlation-wrap" title="Click to copy correlation ID">
                                                <code class="correlation-code">{{ \Illuminate\Support\Str::limit($hit['request_id'], 18) }}</code>
                                                <button type="button" class="btn-copy-id" data-copy="{{ $hit['request_id'] }}" title="Copy Request ID">
                                                    <i class="far fa-copy"></i>
                                                </button>
                                            </div>
                                        @else
                                            <span class="text-muted small">&mdash;</span>
                                        @endif
                                    </td>

                                    @php
                                        $tgPayload = json_encode([
                                            'summary' => $hit['summary'] ?? $hit['message'] ?? 'Application Error',
                                            'level' => $lvl,
                                            'module' => $mod,
                                            'file' => ($hit['file'] ?? 'unknown').(!empty($hit['line']) ? ':'.$hit['line'] : ''),
                                            'request_id' => $hit['request_id'] ?? '',
                                            'fingerprint' => $hit['fingerprint'] ?? '',
                                            'occurrences_count' => $hit['occurrences_count'] ?? 1,
                                        ]);
                                    @endphp

                                    <!-- Action Drawer Toggle & Telegram Dispatch -->
                                    <td style="text-align: right;">
                                        <div class="d-inline-flex align-items-center" style="gap: 5px;">
                                            <button type="button" class="btn-tg-icon btn-open-telegram-modal" data-error-payload="{{ $tgPayload }}" title="Send custom Telegram alert for this issue" aria-label="Send Telegram alert">
                                                <i class="fab fa-telegram-plane"></i>
                                            </button>
                                            <button type="button" class="btn-toggle-trace" data-target="trace-{{ $hitId }}" aria-expanded="false" title="Inspect stack trace & context">
                                                <span>Trace</span>
                                                <i class="fas fa-chevron-down ml-1 arrow-icon"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Expandable Stack Trace Drawer Row -->
                                <tr class="trace-drawer-row d-none" id="trace-{{ $hitId }}">
                                    <td colspan="6" class="p-0">
                                        <div class="trace-drawer-container p-3">
                                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 pb-2 border-bottom" style="gap: 10px;">
                                                <div class="d-flex align-items-center" style="gap: 8px;">
                                                    <span class="font-w700 text-purple" style="font-size: 0.8125rem;">
                                                        <i class="fas fa-bug mr-1"></i> {{ $hit['exception']['class'] ?? 'Exception Details' }}
                                                    </span>
                                                    @if (!empty($hit['horizon_url']))
                                                        <a href="{{ $hit['horizon_url'] }}" target="_blank" rel="noopener" class="btn-horizon-link">
                                                            <i class="fas fa-external-link-alt mr-1"></i> VIEW FAILED JOB IN HORIZON
                                                        </a>
                                                    @endif
                                                    @if (($hit['occurrences_count'] ?? 1) > 1)
                                                        <span class="badge-cluster-count">
                                                            <i class="fas fa-history mr-1"></i> {{ $hit['occurrences_count'] }} Incidents Recorded
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="d-flex align-items-center flex-wrap" style="gap: 8px;">
                                                    @if (!empty($hit['request_id']))
                                                        <span class="badge-corr-pill">
                                                            <i class="fas fa-fingerprint mr-1"></i> REQ: {{ $hit['request_id'] }}
                                                        </span>
                                                    @endif
                                                    <button type="button" class="btn-tg-drawer btn-open-telegram-modal" data-error-payload="{{ $tgPayload }}">
                                                        <i class="fab fa-telegram-plane mr-1"></i> Alert Telegram
                                                    </button>
                                                    @php $frames = $hit['exception']['frames'] ?? []; @endphp
                                                    @if (!empty($frames))
                                                        <button type="button" class="btn-toggle-vendor-frames" data-target="frames-list-{{ $hitId }}">
                                                            <i class="fas fa-filter mr-1"></i> Hide Framework Frames
                                                        </button>
                                                    @endif
                                                    <button type="button" class="btn-copy-trace" data-trace-id="trace-content-{{ $hitId }}">
                                                        <i class="far fa-copy mr-1"></i> Copy Trace
                                                    </button>
                                                </div>
                                            </div>

                                            @if (!empty($hit['first_seen']) && $hit['first_seen'] !== ($hit['last_seen'] ?? ''))
                                                <div class="incident-timeline-bar mb-2 p-2 rounded small text-muted d-flex align-items-center justify-content-between">
                                                    <span><i class="fas fa-hourglass-start mr-1 text-purple"></i> First detected: <strong>{{ $hit['first_seen'] }}</strong></span>
                                                    <span><i class="fas fa-hourglass-end mr-1 text-danger"></i> Most recent: <strong>{{ $hit['last_seen'] }}</strong></span>
                                                </div>
                                            @endif

                                            <!-- Context Metadata (if any) -->
                                            @if (!empty($hit['context']))
                                                <div class="mb-2">
                                                    <div class="text-muted small font-w600 mb-1">EXECUTION CONTEXT:</div>
                                                    <pre class="trace-context-box"><code>{{ json_encode($hit['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                                                </div>
                                            @endif

                                            <!-- Structured Frames List -->
                                            @if (!empty($frames))
                                                <div class="frames-container mb-3" id="frames-list-{{ $hitId }}">
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <span class="text-muted small font-w600">CALL STACK FRAMES ({{ count($frames) }}):</span>
                                                        <span class="small text-muted"><span class="badge-app-pill mr-1">APP</span> Application Code &nbsp;&bull;&nbsp; Framework Code</span>
                                                    </div>
                                                    <div class="frames-list">
                                                        @foreach ($frames as $frame)
                                                            <div class="frame-item {{ $frame['is_app'] ? 'frame-is-app' : 'frame-is-vendor' }}">
                                                                <div class="frame-num">#{{ $frame['index'] }}</div>
                                                                <div class="frame-body">
                                                                    <div class="frame-headline">
                                                                        @if ($frame['is_app'])
                                                                            <span class="badge-app-pill mr-1">APP CODE</span>
                                                                        @endif
                                                                        <strong class="frame-file-name">{{ $frame['short_file'] }}</strong>@if ($frame['line'])<span class="frame-line-num">:{{ $frame['line'] }}</span>@endif
                                                                        @if ($frame['call'])
                                                                            <span class="frame-call-text ml-2">&rarr; {{ $frame['call'] }}</span>
                                                                        @endif
                                                                    </div>
                                                                    <div class="frame-full-path">{{ $frame['file'] }}</div>
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif

                                            <!-- Formatted Stack Trace Box (Raw) -->
                                            <div class="trace-box-wrapper">
                                                <div class="text-muted small font-w600 mb-1">RAW EXCEPTION TRACE:</div>
                                                <pre class="trace-code-box" id="trace-content-{{ $hitId }}"><code>{{ $hit['exception']['trace'] ?? 'No additional stack trace captured.' }}</code></pre>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                @php
                    $pagination = $intelligence['pagination'] ?? ['page' => 1, 'total_pages' => 1, 'total' => 0];
                @endphp
                @if (($pagination['total_pages'] ?? 1) > 1)
                    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 pt-3 border-top pagination-footer" style="gap: 12px;">
                        <span class="text-muted small">
                            Showing page {{ $pagination['page'] }} of {{ $pagination['total_pages'] }} ({{ number_format($pagination['total']) }} total records)
                        </span>
                        <div class="btn-group btn-group-sm">
                            @if ($pagination['page'] > 1)
                                <a href="{{ request()->fullUrlWithQuery(['page' => $pagination['page'] - 1]) }}" class="btn-command-action" style="padding: 4px 12px;">
                                    <i class="fas fa-chevron-left mr-1"></i> Previous
                                </a>
                            @endif
                            @if ($pagination['page'] < $pagination['total_pages'])
                                <a href="{{ request()->fullUrlWithQuery(['page' => $pagination['page'] + 1]) }}" class="btn-command-action ml-1" style="padding: 4px 12px;">
                                    Next <i class="fas fa-chevron-right ml-1"></i>
                                </a>
                            @endif
                        </div>
                    </div>
                @endif
            @endif

            <!-- Security & Compliance Footer -->
            <p class="text-muted small mt-3 mb-0">
                <i class="fas fa-shield-alt mr-1"></i> Only error-level and critical summaries from AlphaLink Laravel log files and OpenObserve telemetry are shown. Stack traces and common secrets, email addresses, IP addresses, and URLs are redacted.
            </p>
        </div>
    </div>

    <!-- Command OS Dialog 1: Per-Error Custom Telegram Alert Composer -->
    <dialog id="dialog-telegram-send" class="command-os-modal" aria-labelledby="tg-send-title">
        <div class="command-os-modal-panel">
            <header class="command-os-modal-header">
                <div class="d-flex align-items-center" style="gap: 12px;">
                    <span class="modal-icon-badge modal-icon-tg"><i class="fab fa-telegram-plane"></i></span>
                    <div>
                        <p class="command-os-modal-eyebrow">INCIDENT DISPATCH // SANITIZED ALERT</p>
                        <h2 id="tg-send-title" class="command-os-modal-title">SEND TELEGRAM ALERT</h2>
                    </div>
                </div>
                <button type="button" class="command-os-modal-close" id="btn-close-tg-send" aria-label="Close modal">&times;</button>
            </header>

            <div class="command-os-modal-body">
                @if (!($telegramConfig['ready'] ?? false))
                    <div class="command-os-alert-warn mb-3">
                        <div class="d-flex align-items-start" style="gap: 10px;">
                            <i class="fas fa-exclamation-triangle mt-1"></i>
                            <div class="flex-1">
                                <strong>Telegram Bot Credentials Missing</strong>
                                <p class="small mb-2 mt-1">Configure your Bot Token and Channel ID in Workspace Settings before dispatching alerts.</p>
                                <a href="{{ route('console.app-settings.index', $workspace->getKey()) }}" class="btn-command-action" style="height: 30px; padding: 0 12px; font-size: 0.72rem;">
                                    CONFIGURE IN SETTINGS <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="tg-issue-preview-card mb-3">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <div class="d-flex align-items-center" style="gap: 6px;">
                            <span id="tg-preview-level" class="badge-level badge-level-error">ERROR</span>
                            <span id="tg-preview-module" class="badge-module badge-module-api">API</span>
                        </div>
                        <span id="tg-preview-occ" class="badge-cluster-count d-none">1x</span>
                    </div>
                    <div id="tg-preview-summary" class="tg-preview-summary-text">Loading issue summary...</div>
                    <div class="small text-muted mt-1"><i class="far fa-file-code mr-1"></i> <code id="tg-preview-file">unknown</code></div>
                </div>

                <form id="form-telegram-send">
                    <input type="hidden" id="tg-field-summary">
                    <input type="hidden" id="tg-field-level">
                    <input type="hidden" id="tg-field-module">
                    <input type="hidden" id="tg-field-file">
                    <input type="hidden" id="tg-field-request-id">
                    <input type="hidden" id="tg-field-fingerprint">
                    <input type="hidden" id="tg-field-occurrences" value="1">

                    <div class="form-group mb-3">
                        <label class="command-field-label" for="tg-select-channel">TARGET TELEGRAM CHANNEL</label>
                        <select id="tg-select-channel" class="form-control form-control-sm command-select">
                            <option value="it" @selected(($telegramConfig['has_it_channel'] ?? false))>
                                IT / DevOps Channel ({{ ($telegramConfig['channel_it_masked'] ?? '') ?: 'Not configured' }})
                            </option>
                            <option value="main" @selected(!($telegramConfig['has_it_channel'] ?? false) && ($telegramConfig['has_main_channel'] ?? false))>
                                General Channel ({{ ($telegramConfig['channel_main_masked'] ?? '') ?: 'Not configured' }})
                            </option>
                            <option value="both">Both IT &amp; General Channels</option>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="command-field-label mb-0" for="tg-custom-message">CUSTOM ALERT MESSAGE / NOTE</label>
                            <span class="small text-muted">Typed message appears at the top of the Telegram alert</span>
                        </div>
                        <textarea id="tg-custom-message" rows="3" class="form-control command-textarea" placeholder="Write a custom message for your team (e.g., Investigating regression on Campaign Logs after deploy)..."></textarea>
                        <div class="d-flex flex-wrap mt-2" style="gap: 6px;">
                            <button type="button" class="tg-preset-chip" data-text="Investigating this issue now — will update once patched.">Investigating now</button>
                            <button type="button" class="tg-preset-chip" data-text="URGENT: Production impact detected. Please check Horizon and server status immediately.">Urgent escalation</button>
                            <button type="button" class="tg-preset-chip" data-text="Observed after latest deployment. Rolling out fix shortly.">Deployment notice</button>
                            <button type="button" class="tg-preset-chip" data-text="Needs infrastructure / PMTA check on sending nodes.">Needs infra check</button>
                        </div>
                    </div>

                    <div class="tg-options-grid mb-3">
                        <label class="tg-checkbox-pill">
                            <input type="checkbox" id="tg-opt-summary" checked>
                            <span>Include Redacted Summary</span>
                        </label>
                        <label class="tg-checkbox-pill">
                            <input type="checkbox" id="tg-opt-link" checked>
                            <span>Include Error Center Link</span>
                        </label>
                        <label class="tg-checkbox-pill">
                            <input type="checkbox" id="tg-opt-reqid">
                            <span>Include Correlation ID</span>
                        </label>
                    </div>

                    <div class="tg-security-notice mb-3">
                        <i class="fas fa-shield-alt mr-1 text-success"></i> Stack traces, API tokens, passwords, emails, and IP addresses are strictly stripped before leaving AlphaLink.
                    </div>
                </form>
            </div>

            <footer class="command-os-modal-footer">
                <button type="button" class="btn-command-action" id="btn-cancel-tg-send">CANCEL</button>
                <button type="button" class="btn-command-primary" id="btn-submit-tg-send" @disabled(!($telegramConfig['ready'] ?? false))>
                    <i class="fab fa-telegram-plane mr-1"></i> SEND ALERT NOW
                </button>
            </footer>
        </div>
    </dialog>

    <!-- Command OS Dialog 2: Automated Telegram Alert Rules & Custom Message Templates -->
    <dialog id="dialog-telegram-rules" class="command-os-modal" aria-labelledby="tg-rules-title">
        <div class="command-os-modal-panel" style="max-width: 660px;">
            <header class="command-os-modal-header">
                <div class="d-flex align-items-center" style="gap: 12px;">
                    <span class="modal-icon-badge modal-icon-purple"><i class="fas fa-bell"></i></span>
                    <div>
                        <p class="command-os-modal-eyebrow">AUTOMATION &amp; SAFETY // THRESHOLD ALERTS</p>
                        <h2 id="tg-rules-title" class="command-os-modal-title">TELEGRAM ALERT RULES &amp; TEMPLATES</h2>
                    </div>
                </div>
                <button type="button" class="command-os-modal-close" id="btn-close-tg-rules" aria-label="Close modal">&times;</button>
            </header>

            <div class="command-os-modal-body">
                @if (!($telegramConfig['ready'] ?? false))
                    <div class="command-os-alert-warn mb-3">
                        <div class="d-flex align-items-start" style="gap: 10px;">
                            <i class="fas fa-exclamation-triangle mt-1"></i>
                            <div class="flex-1">
                                <strong>Bot Credentials Needed</strong>
                                <p class="small mb-2 mt-1">Configure your Telegram Bot Token and Channel ID in Workspace Settings to enable delivery.</p>
                                <a href="{{ route('console.app-settings.index', $workspace->getKey()) }}" class="btn-command-action" style="height: 30px; padding: 0 12px; font-size: 0.72rem;">
                                    OPEN SETTINGS <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                @endif

                <form id="form-telegram-rules">
                    <div class="tg-rule-toggle-card mb-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <strong style="font-size: 0.875rem; color: var(--co-ink);">Enable Automated Threshold Alerts</strong>
                                <p class="small text-muted mb-0">Rate-limited alerts per error fingerprint (zero spam bursts).</p>
                            </div>
                            <label class="command-switch mb-0">
                                <input type="checkbox" id="rule-enabled" @checked(!empty($telegramConfig['rules']['enabled']))>
                                <span class="command-switch-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="row m-0 mb-3" style="gap: 10px;">
                        <div class="col p-0" style="min-width: 160px;">
                            <label class="command-field-label" for="rule-channel">DESTINATION CHANNEL</label>
                            <select id="rule-channel" class="form-control form-control-sm command-select">
                                <option value="it" @selected(($telegramConfig['rules']['target_channel'] ?? 'it') === 'it')>IT Channel ({{ ($telegramConfig['channel_it_masked'] ?? '') ?: 'Unset' }})</option>
                                <option value="main" @selected(($telegramConfig['rules']['target_channel'] ?? 'it') === 'main')>General Channel ({{ ($telegramConfig['channel_main_masked'] ?? '') ?: 'Unset' }})</option>
                                <option value="both" @selected(($telegramConfig['rules']['target_channel'] ?? 'it') === 'both')>Both Channels</option>
                            </select>
                        </div>
                        <div class="col p-0" style="min-width: 150px;">
                            <label class="command-field-label" for="rule-threshold">SEVERITY THRESHOLD</label>
                            <select id="rule-threshold" class="form-control form-control-sm command-select">
                                <option value="CRITICAL" @selected(($telegramConfig['rules']['severity_threshold'] ?? 'CRITICAL') === 'CRITICAL')>CRITICAL / FATAL Only</option>
                                <option value="ERROR" @selected(($telegramConfig['rules']['severity_threshold'] ?? 'CRITICAL') === 'ERROR')>ERROR &amp; Above</option>
                            </select>
                        </div>
                        <div class="col p-0" style="min-width: 140px;">
                            <label class="command-field-label" for="rule-cooldown">FINGERPRINT COOLDOWN</label>
                            <select id="rule-cooldown" class="form-control form-control-sm command-select">
                                <option value="15" @selected((int)($telegramConfig['rules']['cooldown_minutes'] ?? 30) === 15)>15 Minutes</option>
                                <option value="30" @selected((int)($telegramConfig['rules']['cooldown_minutes'] ?? 30) === 30)>30 Minutes</option>
                                <option value="60" @selected((int)($telegramConfig['rules']['cooldown_minutes'] ?? 30) === 60)>1 Hour</option>
                                <option value="180" @selected((int)($telegramConfig['rules']['cooldown_minutes'] ?? 30) === 180)>3 Hours</option>
                                <option value="360" @selected((int)($telegramConfig['rules']['cooldown_minutes'] ?? 30) === 360)>6 Hours</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="command-field-label" for="rule-custom-prefix">DEFAULT CUSTOM PREFIX / CALLOUT</label>
                        <input type="text" id="rule-custom-prefix" class="form-control form-control-sm command-input-plain" placeholder="Optional banner note (e.g., Production Node Alert — On-call team check logs)" value="{{ $telegramConfig['rules']['custom_prefix'] ?? '' }}">
                    </div>

                    <div class="form-group mb-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="command-field-label mb-0" for="rule-custom-template">CUSTOM MESSAGE TEMPLATE</label>
                            <button type="button" id="btn-reset-template" class="btn-link-subtle small">Reset Default</button>
                        </div>
                        <textarea id="rule-custom-template" rows="4" class="form-control command-textarea" style="font-family: ui-monospace, monospace; font-size: 0.78rem;">{{ $telegramConfig['rules']['custom_template'] ?? \App\Services\Telegram\TelegramAlertService::DEFAULT_TEMPLATE }}</textarea>
                        <div class="d-flex flex-wrap align-items-center mt-2" style="gap: 5px;">
                            <span class="small text-muted mr-1">Insert token:</span>
                            <button type="button" class="tg-token-chip" data-token="{app_name}">{app_name}</button>
                            <button type="button" class="tg-token-chip" data-token="{level}">{level}</button>
                            <button type="button" class="tg-token-chip" data-token="{module}">{module}</button>
                            <button type="button" class="tg-token-chip" data-token="{summary}">{summary}</button>
                            <button type="button" class="tg-token-chip" data-token="{file}">{file}</button>
                            <button type="button" class="tg-token-chip" data-token="{occurrences}">{occurrences}</button>
                            <button type="button" class="tg-token-chip" data-token="{request_id}">{request_id}</button>
                            <button type="button" class="tg-token-chip" data-token="{custom_note}">{custom_note}</button>
                        </div>
                    </div>
                </form>
            </div>

            <footer class="command-os-modal-footer justify-content-between">
                <button type="button" class="btn-command-action" id="btn-test-telegram" @disabled(!($telegramConfig['ready'] ?? false))>
                    <i class="fas fa-paper-plane mr-1"></i> TEST CONNECTION
                </button>
                <div class="d-flex" style="gap: 8px;">
                    <button type="button" class="btn-command-action" id="btn-cancel-tg-rules">CANCEL</button>
                    <button type="button" class="btn-command-primary" id="btn-save-tg-rules">
                        <i class="fas fa-save mr-1"></i> SAVE ALERT RULES
                    </button>
                </div>
            </footer>
        </div>
    </dialog>

    <!-- Scoped Styles for Error Intelligence Center -->
    <style>
        /* Pulse Indicators */
        .pulse-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
            vertical-align: middle;
            animation: pulse-ring 2s infinite ease-in-out;
        }
        .pulse-green { background-color: #10b981; box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        .pulse-amber { background-color: #f59e0b; box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7); }
        @keyframes pulse-ring {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .badge-status-live {
            display: inline-flex;
            align-items: center;
            height: 34px;
            padding: 0 12px;
            border-radius: 9px;
            font-size: 0.75rem;
            font-weight: 750;
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }
        .badge-status-live .status-sub {
            margin-left: 5px;
            font-size: 0.7rem;
            opacity: 0.85;
            font-family: monospace;
        }
        .badge-status-fallback {
            display: inline-flex;
            align-items: center;
            height: 34px;
            padding: 0 12px;
            border-radius: 9px;
            font-size: 0.75rem;
            font-weight: 750;
            background: rgba(245, 158, 11, 0.12);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.25);
        }

        /* Auto-Refresh Badge & Select */
        .auto-refresh-wrap {
            background: var(--co-surface-muted, #f8fafc);
            border: 1px solid var(--co-border, #e2e8f0);
            border-radius: 9px;
            padding: 0 8px;
            height: 34px;
        }
        .auto-refresh-select {
            background: transparent;
            border: 0;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--co-ink, #0f172a);
            outline: none;
            cursor: pointer;
        }
        .auto-refresh-badge {
            font-size: 0.65rem;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 4px;
            background: rgba(124, 58, 237, 0.12);
            color: var(--co-purple, #792bfa);
            margin-left: 4px;
            font-family: monospace;
        }

        /* Cluster / Fingerprint Badge */
        .badge-cluster-count {
            display: inline-flex;
            align-items: center;
            padding: 2px 7px;
            border-radius: 5px;
            font-size: 0.6875rem;
            font-weight: 800;
            background: rgba(124, 58, 237, 0.12);
            color: var(--co-purple, #6d28d9);
            border: 1px solid rgba(124, 58, 237, 0.25);
        }
        .incident-timeline-bar {
            background: rgba(0, 0, 0, 0.03);
            border: 1px dashed var(--co-border, #e2e8f0);
        }

        /* Structured Stack Frames */
        .frames-container {
            border: 1px solid var(--co-border, #e2e8f0);
            border-radius: 8px;
            background: var(--co-surface, #ffffff);
            padding: 10px;
        }
        .frames-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
            max-height: 380px;
            overflow-y: auto;
        }
        .frame-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 0.725rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            background: var(--co-surface-muted, #f8fafc);
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }
        .frame-item:hover {
            border-color: var(--co-border, #cbd5e1);
        }
        .frame-is-app {
            background: rgba(124, 58, 237, 0.06);
            border-color: rgba(124, 58, 237, 0.25);
        }
        .frame-is-app .frame-file-name {
            color: var(--co-purple, #6d28d9);
            font-weight: 750;
        }
        .frame-is-vendor {
            opacity: 0.75;
        }
        .frames-container.hide-vendor .frame-is-vendor {
            display: none !important;
        }
        .frame-num {
            font-size: 0.6875rem;
            font-weight: 800;
            color: var(--co-muted, #94a3b8);
            width: 28px;
            flex-shrink: 0;
        }
        .frame-body {
            flex: 1;
            min-width: 0;
            overflow: hidden;
        }
        .frame-headline {
            word-break: break-all;
        }
        .badge-app-pill {
            display: inline-block;
            font-size: 0.6rem;
            font-weight: 800;
            padding: 1px 4px;
            border-radius: 3px;
            background: #7c3aed;
            color: #ffffff;
            letter-spacing: 0.04em;
        }
        .frame-full-path {
            font-size: 0.65rem;
            color: var(--co-muted, #94a3b8);
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .frame-call-text {
            color: var(--co-ink, #1e293b);
            font-weight: 600;
        }
        .btn-toggle-vendor-frames {
            background: transparent;
            border: 1px solid var(--co-border, #cbd5e1);
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 0.7rem;
            font-weight: 650;
            color: var(--co-ink, #334155);
            cursor: pointer;
        }
        .btn-toggle-vendor-frames:hover {
            border-color: var(--co-purple, #792bfa);
            color: var(--co-purple, #792bfa);
        }

        /* Module Chips */
        .module-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 650;
            background: var(--co-surface-muted, #f1f5f9);
            color: var(--co-ink, #334155);
            border: 1px solid var(--co-border, #e2e8f0);
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .module-chip:hover {
            border-color: var(--co-purple, #792bfa);
            transform: translateY(-1px);
        }
        .module-chip .chip-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--co-purple, #792bfa);
        }
        .module-chip-horizon .chip-dot { background: #8b5cf6; }
        .module-chip-api .chip-dot { background: #3b82f6; }
        .module-chip-campaign .chip-dot { background: #ec4899; }
        .module-chip-infrastructure .chip-dot { background: #f59e0b; }
        .module-chip-tracking .chip-dot { background: #06b6d4; }
        .module-chip .chip-count {
            background: rgba(0, 0, 0, 0.08);
            padding: 1px 6px;
            border-radius: 12px;
            font-size: 0.7rem;
        }

        /* Trend Bars */
        .trend-section-title {
            font-size: 0.9375rem;
            font-weight: 750;
            color: var(--co-ink, #0f172a);
        }
        .trend-bars-grid {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            height: 120px;
            padding-top: 15px;
            padding-bottom: 5px;
            overflow-x: auto;
        }
        .trend-bar-column {
            flex: 1;
            min-width: 24px;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            position: relative;
        }
        .trend-bar-track {
            width: 100%;
            max-width: 32px;
            height: 90px;
            display: flex;
            align-items: flex-end;
            border-radius: 6px;
            background: rgba(148, 163, 184, 0.1);
        }
        .trend-bar-fill {
            width: 100%;
            border-radius: 6px;
            background: linear-gradient(180deg, #a855f7 0%, #7c3aed 100%);
            position: relative;
            transition: height 0.3s ease;
        }
        .trend-bar-fill.is-critical {
            background: linear-gradient(180deg, #f87171 0%, #dc2626 100%);
        }
        .trend-bar-column:hover .trend-bar-fill {
            filter: brightness(1.15);
        }
        .trend-bar-tooltip {
            position: absolute;
            top: -22px;
            left: 50%;
            transform: translateX(-50%);
            background: #0f172a;
            color: #fff;
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 700;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.15s ease;
        }
        .trend-bar-column:hover .trend-bar-tooltip {
            opacity: 1;
        }
        .trend-bar-label {
            font-size: 0.65rem;
            color: var(--co-muted, #64748b);
            margin-top: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 48px;
            text-align: center;
        }

        /* Filter Controls */
        .input-with-icon {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-with-icon .input-icon {
            position: absolute;
            left: 10px;
            color: var(--co-muted, #94a3b8);
            font-size: 0.8125rem;
            pointer-events: none;
        }
        .input-with-icon .command-input {
            padding-left: 32px;
            padding-right: 28px;
            height: 34px;
            border-radius: 8px;
            background: var(--co-surface-muted, #f8fafc);
            border: 1px solid var(--co-border, #e2e8f0);
            color: var(--co-ink, #0f172a);
        }
        .btn-clear-search {
            position: absolute;
            right: 8px;
            background: transparent;
            border: 0;
            font-size: 1.1rem;
            color: var(--co-muted, #94a3b8);
            cursor: pointer;
            line-height: 1;
        }
        .command-select {
            height: 34px;
            border-radius: 8px;
            background: var(--co-surface-muted, #f8fafc);
            border: 1px solid var(--co-border, #e2e8f0);
            color: var(--co-ink, #0f172a);
            font-size: 0.8125rem;
            width: 100%;
        }

        .filter-flex-form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        .filter-item-search {
            flex: 2 1 220px;
            min-width: 180px;
        }
        .filter-item-select {
            flex: 1 1 130px;
            min-width: 120px;
        }
        .filter-item-actions {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Responsive Table Wrapping (Prevents Desktop Horizontal Clipping) */
        @media (min-width: 992px) {
            #table-error-intelligence {
                table-layout: fixed;
                width: 100%;
            }
        }
        #table-error-intelligence th,
        #table-error-intelligence td {
            white-space: normal;
            vertical-align: middle;
        }
        .cell-message-wrap,
        .cell-message-title,
        .cell-source-file {
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        /* Table Badges & Styles */
        .badge-level {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.03em;
        }
        .badge-level-critical, .badge-level-emergency, .badge-level-alert, .badge-level-fatal {
            background: rgba(239, 68, 68, 0.12);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.25);
        }
        .badge-level-error {
            background: rgba(249, 115, 22, 0.12);
            color: #ea580c;
            border: 1px solid rgba(249, 115, 22, 0.25);
        }
        .badge-level-warning {
            background: rgba(245, 158, 11, 0.12);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.25);
        }
        .badge-level-info, .badge-level-notice {
            background: rgba(59, 130, 246, 0.12);
            color: #2563eb;
            border: 1px solid rgba(59, 130, 246, 0.25);
        }
        .badge-level-debug {
            background: rgba(148, 163, 184, 0.12);
            color: #64748b;
            border: 1px solid rgba(148, 163, 184, 0.25);
        }

        .badge-module {
            display: inline-flex;
            padding: 2px 7px;
            border-radius: 5px;
            font-size: 0.6875rem;
            font-weight: 750;
            letter-spacing: 0.04em;
            background: rgba(124, 58, 237, 0.08);
            color: #7c3aed;
            border: 1px solid rgba(124, 58, 237, 0.2);
        }
        .badge-module-horizon { background: rgba(139, 92, 246, 0.1); color: #8b5cf6; }
        .badge-module-api { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .badge-module-campaign { background: rgba(236, 72, 153, 0.1); color: #ec4899; }
        .badge-module-infrastructure { background: rgba(245, 158, 11, 0.1); color: #d97706; }
        .badge-module-tracking { background: rgba(6, 182, 212, 0.1); color: #0891b2; }

        .cell-date {
            font-size: 0.8125rem;
            font-weight: 650;
            color: var(--co-ink, #0f172a);
        }
        .cell-relative {
            font-size: 0.7rem;
        }
        .cell-message-title {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--co-ink, #0f172a);
            line-height: 1.35;
        }
        .badge-tag-job {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 750;
            background: #ede9fe;
            color: #6d28d9;
        }
        .file-code {
            font-size: 0.75rem;
            color: var(--co-purple, #6d28d9);
            background: rgba(124, 58, 237, 0.06);
            padding: 2px 6px;
            border-radius: 4px;
        }

        .correlation-wrap {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--co-surface-muted, #f8fafc);
            padding: 2px 6px;
            border-radius: 6px;
            border: 1px solid var(--co-border, #e2e8f0);
        }
        .correlation-code {
            font-size: 0.725rem;
            color: var(--co-ink, #334155);
        }
        .btn-copy-id {
            background: transparent;
            border: 0;
            padding: 0;
            color: var(--co-muted, #94a3b8);
            cursor: pointer;
            font-size: 0.75rem;
        }
        .btn-copy-id:hover {
            color: var(--co-purple, #792bfa);
        }

        .btn-toggle-trace {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 7px;
            font-size: 0.75rem;
            font-weight: 700;
            background: var(--co-surface-muted, #f1f5f9);
            color: var(--co-ink, #334155);
            border: 1px solid var(--co-border, #e2e8f0);
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-toggle-trace:hover {
            border-color: var(--co-purple, #792bfa);
            color: var(--co-purple, #792bfa);
        }
        .btn-toggle-trace[aria-expanded="true"] .arrow-icon {
            transform: rotate(180deg);
        }

        /* Expandable Drawer */
        .trace-drawer-container {
            background: var(--co-surface-muted, #f8fafc);
            border-top: 1px dashed var(--co-border, #e2e8f0);
            border-bottom: 1px solid var(--co-border, #e2e8f0);
        }
        .btn-horizon-link {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 750;
            background: rgba(124, 58, 237, 0.12);
            color: #7c3aed;
            border: 1px solid rgba(124, 58, 237, 0.25);
            text-decoration: none;
        }
        .btn-horizon-link:hover {
            text-decoration: none;
            background: rgba(124, 58, 237, 0.2);
            color: #6d28d9;
        }
        .badge-corr-pill {
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 6px;
            background: rgba(0, 0, 0, 0.05);
            color: var(--co-muted, #64748b);
            font-family: monospace;
        }
        .btn-copy-trace {
            background: transparent;
            border: 1px solid var(--co-border, #e2e8f0);
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 0.7rem;
            font-weight: 650;
            color: var(--co-ink, #334155);
            cursor: pointer;
        }
        .btn-copy-trace:hover {
            border-color: var(--co-purple, #792bfa);
            color: var(--co-purple, #792bfa);
        }
        .trace-context-box, .trace-code-box {
            background: #0f172a;
            color: #f1f5f9;
            padding: 12px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            max-height: 280px;
            overflow-y: auto;
            margin-bottom: 0;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .row-critical {
            background: rgba(239, 68, 68, 0.02);
        }

        /* Dark Theme Adaptations */
        html[data-command-theme="dark"] .badge-status-live {
            background: rgba(16, 185, 129, 0.18);
            color: #34d399;
            border-color: rgba(16, 185, 129, 0.35);
        }
        html[data-command-theme="dark"] .badge-status-fallback {
            background: rgba(245, 158, 11, 0.18);
            color: #fbbf24;
            border-color: rgba(245, 158, 11, 0.35);
        }
        html[data-command-theme="dark"] .module-chip {
            background: var(--co-surface-muted, #141b2d);
            color: #cbd5e1;
            border-color: var(--co-border, #242f49);
        }
        html[data-command-theme="dark"] .trend-bar-track {
            background: rgba(255, 255, 255, 0.05);
        }
        html[data-command-theme="dark"] .command-input,
        html[data-command-theme="dark"] .command-select {
            background: var(--co-surface-muted, #141b2d);
            border-color: var(--co-border, #242f49);
            color: #f1f5f9;
        }
        html[data-command-theme="dark"] .correlation-wrap {
            background: var(--co-surface-muted, #141b2d);
            border-color: var(--co-border, #242f49);
        }
        html[data-command-theme="dark"] .correlation-code {
            color: #e2e8f0;
        }
        html[data-command-theme="dark"] .btn-toggle-trace {
            background: var(--co-surface-muted, #141b2d);
            border-color: var(--co-border, #242f49);
            color: #e2e8f0;
        }
        html[data-command-theme="dark"] .trace-drawer-container {
            background: #0b1120;
            border-top-color: #1e293b;
            border-bottom-color: #1e293b;
        }
        html[data-command-theme="dark"] .trace-context-box,
        html[data-command-theme="dark"] .trace-code-box {
            background: #030712;
            color: #e2e8f0;
            border: 1px solid #1f2937;
        }
        html[data-command-theme="dark"] .badge-corr-pill {
            background: rgba(255, 255, 255, 0.08);
            color: #94a3b8;
        }
        html[data-command-theme="dark"] .auto-refresh-wrap {
            background: var(--co-surface-muted, #141b2d);
            border-color: var(--co-border, #242f49);
        }
        html[data-command-theme="dark"] .auto-refresh-select {
            color: #f1f5f9;
        }
        html[data-command-theme="dark"] .auto-refresh-select option {
            background: #141b2d;
            color: #f1f5f9;
        }
        html[data-command-theme="dark"] .frames-container {
            background: #0f172a;
            border-color: #1e293b;
        }
        html[data-command-theme="dark"] .frame-item {
            background: #111827;
            color: #cbd5e1;
        }
        html[data-command-theme="dark"] .frame-is-app {
            background: rgba(124, 58, 237, 0.14);
            border-color: rgba(139, 92, 246, 0.35);
        }
        html[data-command-theme="dark"] .frame-call-text {
            color: #f1f5f9;
        }
        html[data-command-theme="dark"] .btn-toggle-vendor-frames {
            border-color: #242f49;
            color: #cbd5e1;
        }
        html[data-command-theme="dark"] .incident-timeline-bar {
            background: rgba(255, 255, 255, 0.04);
            border-color: #1e293b;
        }

        /* Telegram Integration UI (Command OS New Theme Only) */
        .badge-tg-status-on {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 0.625rem;
            font-weight: 800;
            background: rgba(16, 185, 129, 0.16);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .btn-tg-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 28px;
            border-radius: 7px;
            background: rgba(14, 165, 233, 0.1);
            color: #0284c7;
            border: 1px solid rgba(14, 165, 233, 0.25);
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-tg-icon:hover {
            background: rgba(14, 165, 233, 0.2);
            transform: translateY(-1px);
        }
        .btn-tg-drawer {
            display: inline-flex;
            align-items: center;
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            background: rgba(14, 165, 233, 0.12);
            color: #0284c7;
            border: 1px solid rgba(14, 165, 233, 0.28);
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-tg-drawer:hover {
            background: rgba(14, 165, 233, 0.22);
        }

        /* Command OS Native Modal (<dialog>) */
        dialog.command-os-modal {
            border: 1px solid var(--co-border, #dce0f4);
            border-radius: 20px;
            padding: 0;
            width: min(600px, calc(100vw - 24px));
            max-width: 100%;
            max-height: 90vh;
            overflow: visible;
            background: var(--co-surface, #ffffff);
            color: var(--co-ink, #0f172a);
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
            margin: auto;
        }
        dialog.command-os-modal::backdrop {
            background: rgba(10, 15, 30, 0.58);
            backdrop-filter: blur(5px);
        }
        .command-os-modal-panel {
            display: flex;
            flex-direction: column;
            max-height: 90vh;
            border-radius: 20px;
            overflow: hidden;
            background: var(--co-surface, #ffffff);
        }
        .command-os-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--co-border, #e2e8f0);
            background: var(--co-surface-muted, #f8fafc);
        }
        .modal-icon-badge {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            font-size: 1rem;
        }
        .modal-icon-tg {
            background: rgba(14, 165, 233, 0.14);
            color: #0284c7;
            border: 1px solid rgba(14, 165, 233, 0.28);
        }
        .modal-icon-purple {
            background: rgba(124, 58, 237, 0.14);
            color: #7c3aed;
            border: 1px solid rgba(124, 58, 237, 0.28);
        }
        .command-os-modal-eyebrow {
            margin: 0;
            font-size: 0.65rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            color: var(--co-purple, #7c3aed);
        }
        .command-os-modal-title {
            margin: 2px 0 0;
            font-size: 1rem;
            font-weight: 800;
            color: var(--co-ink, #0f172a);
        }
        .command-os-modal-close {
            background: transparent;
            border: 1px solid var(--co-border, #e2e8f0);
            border-radius: 8px;
            width: 32px;
            height: 32px;
            font-size: 1.25rem;
            line-height: 1;
            color: var(--co-muted, #64748b);
            cursor: pointer;
        }
        .command-os-modal-close:hover {
            color: var(--co-ink, #0f172a);
            border-color: var(--co-purple, #7c3aed);
        }
        .command-os-modal-body {
            padding: 20px;
            overflow-y: auto;
        }
        .command-os-modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            padding: 14px 20px;
            border-top: 1px solid var(--co-border, #e2e8f0);
            background: var(--co-surface-muted, #f8fafc);
        }
        .command-os-alert-warn {
            padding: 12px 14px;
            border-radius: 10px;
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #b45309;
            font-size: 0.8125rem;
        }
        .tg-issue-preview-card {
            padding: 12px 14px;
            border-radius: 10px;
            background: var(--co-surface-muted, #f8fafc);
            border: 1px solid var(--co-border, #e2e8f0);
        }
        .tg-preview-summary-text {
            font-size: 0.8125rem;
            font-weight: 650;
            color: var(--co-ink, #0f172a);
            word-break: break-word;
        }
        .command-field-label {
            display: block;
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            color: var(--co-muted, #64748b);
            margin-bottom: 5px;
        }
        .command-textarea, .command-input-plain {
            border-radius: 9px;
            background: var(--co-surface-muted, #f8fafc);
            border: 1px solid var(--co-border, #cbd5e1);
            color: var(--co-ink, #0f172a);
            font-size: 0.8125rem;
        }
        .command-textarea:focus, .command-input-plain:focus {
            border-color: var(--co-purple, #7c3aed);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
            outline: none;
        }
        .tg-preset-chip, .tg-token-chip {
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 650;
            background: rgba(124, 58, 237, 0.08);
            color: var(--co-purple, #7c3aed);
            border: 1px solid rgba(124, 58, 237, 0.22);
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .tg-preset-chip:hover, .tg-token-chip:hover {
            background: rgba(124, 58, 237, 0.16);
        }
        .tg-options-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .tg-checkbox-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 7px;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--co-surface-muted, #f1f5f9);
            border: 1px solid var(--co-border, #e2e8f0);
            cursor: pointer;
            margin: 0;
        }
        .tg-security-notice {
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.72rem;
            color: var(--co-muted, #475569);
            background: rgba(16, 185, 129, 0.08);
            border: 1px solid rgba(16, 185, 129, 0.22);
        }
        .tg-rule-toggle-card {
            padding: 12px 14px;
            border-radius: 10px;
            background: var(--co-surface-muted, #f8fafc);
            border: 1px solid var(--co-border, #e2e8f0);
        }
        .command-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }
        .command-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .command-switch-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background-color: #cbd5e1;
            transition: .25s;
            border-radius: 24px;
        }
        .command-switch-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .25s;
            border-radius: 50%;
        }
        .command-switch input:checked + .command-switch-slider {
            background-color: var(--co-purple, #7c3aed);
        }
        .command-switch input:checked + .command-switch-slider:before {
            transform: translateX(20px);
        }
        .btn-link-subtle {
            background: transparent;
            border: 0;
            color: var(--co-purple, #7c3aed);
            font-weight: 650;
            cursor: pointer;
            padding: 0;
        }

        /* Dark Theme Modal & Telegram Adaptations */
        html[data-command-theme="dark"] .btn-tg-icon,
        html[data-command-theme="dark"] .btn-tg-drawer {
            background: rgba(14, 165, 233, 0.16);
            color: #38bdf8;
            border-color: rgba(14, 165, 233, 0.35);
        }
        html[data-command-theme="dark"] dialog.command-os-modal,
        html[data-command-theme="dark"] .command-os-modal-panel {
            background: #0f172a;
            border-color: #242f49;
            color: #f1f5f9;
        }
        html[data-command-theme="dark"] .command-os-modal-header,
        html[data-command-theme="dark"] .command-os-modal-footer {
            background: #141b2d;
            border-color: #242f49;
        }
        html[data-command-theme="dark"] .command-os-modal-title,
        html[data-command-theme="dark"] .tg-preview-summary-text {
            color: #f8fafc;
        }
        html[data-command-theme="dark"] .tg-issue-preview-card,
        html[data-command-theme="dark"] .tg-rule-toggle-card,
        html[data-command-theme="dark"] .tg-checkbox-pill {
            background: #141b2d;
            border-color: #242f49;
            color: #e2e8f0;
        }
        html[data-command-theme="dark"] .command-textarea,
        html[data-command-theme="dark"] .command-input-plain {
            background: #0b1120;
            border-color: #242f49;
            color: #f1f5f9;
        }
        html[data-command-theme="dark"] .command-os-alert-warn {
            background: rgba(245, 158, 11, 0.14);
            color: #fbbf24;
        }
    </style>

    <!-- Client-side Telemetry Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Current filtered dataset for instant sanitized export
            const currentTelemetryHits = @json($hits ?? []);

            // 1. Toggle Trace Drawer
            document.querySelectorAll('.btn-toggle-trace').forEach(function (button) {
                button.addEventListener('click', function () {
                    const targetId = button.getAttribute('data-target');
                    const targetRow = document.getElementById(targetId);
                    if (!targetRow) return;

                    const isHidden = targetRow.classList.contains('d-none');
                    if (isHidden) {
                        targetRow.classList.remove('d-none');
                        button.setAttribute('aria-expanded', 'true');
                    } else {
                        targetRow.classList.add('d-none');
                        button.setAttribute('aria-expanded', 'false');
                    }
                });
            });

            // 1b. Toggle Vendor / Framework Frames
            document.querySelectorAll('.btn-toggle-vendor-frames').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const targetId = btn.getAttribute('data-target');
                    const container = document.getElementById(targetId);
                    if (!container) return;

                    const isHidden = container.classList.toggle('hide-vendor');
                    if (isHidden) {
                        btn.innerHTML = '<i class="fas fa-eye mr-1"></i> Show All Frames';
                    } else {
                        btn.innerHTML = '<i class="fas fa-filter mr-1"></i> Hide Framework Frames';
                    }
                });
            });

            // 2. Copy Request/Correlation ID
            document.querySelectorAll('.btn-copy-id').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const text = btn.getAttribute('data-copy');
                    if (!text) return;
                    navigator.clipboard.writeText(text).then(function () {
                        const icon = btn.querySelector('i');
                        if (icon) {
                            icon.className = 'fas fa-check text-success';
                            setTimeout(function () {
                                icon.className = 'far fa-copy';
                            }, 1500);
                        }
                    });
                });
            });

            // 3. Copy Stack Trace
            document.querySelectorAll('.btn-copy-trace').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const traceId = btn.getAttribute('data-trace-id');
                    const pre = document.getElementById(traceId);
                    if (!pre) return;
                    navigator.clipboard.writeText(pre.innerText).then(function () {
                        const originalHtml = btn.innerHTML;
                        btn.innerHTML = '<i class="fas fa-check text-success mr-1"></i> Copied!';
                        setTimeout(function () {
                            btn.innerHTML = originalHtml;
                        }, 2000);
                    });
                });
            });

            // 4. Module Chip Quick Filter
            document.querySelectorAll('.module-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    const mod = chip.getAttribute('data-filter');
                    const select = document.getElementById('select-module');
                    if (select) {
                        select.value = mod === 'all' ? '' : mod;
                        document.getElementById('filter-form').submit();
                    }
                });
            });

            // 5. Clear Search Button
            const btnClearSearch = document.getElementById('btn-clear-search');
            if (btnClearSearch) {
                btnClearSearch.addEventListener('click', function () {
                    const input = document.getElementById('input-search');
                    if (input) {
                        input.value = '';
                        document.getElementById('filter-form').submit();
                    }
                });
            }

            // 6. Refresh Button
            const btnRefresh = document.getElementById('btn-refresh-errors');
            if (btnRefresh) {
                btnRefresh.addEventListener('click', function () {
                    window.location.reload();
                });
            }

            // 7. Sync to OpenObserve Action
            const btnSync = document.getElementById('btn-sync-openobserve');
            if (btnSync) {
                btnSync.addEventListener('click', function () {
                    const originalHtml = btnSync.innerHTML;
                    btnSync.disabled = true;
                    btnSync.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> SYNCING...';

                    fetch('{{ route('console.miscellaneous.app-errors.sync', $workspace->getKey()) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ limit: 200 })
                    })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.success) {
                            btnSync.innerHTML = '<i class="fas fa-check text-success mr-1"></i> SYNCED (' + (data.synced || 0) + ')';
                            setTimeout(function () {
                                window.location.reload();
                            }, 1200);
                        } else {
                            alert('Sync warning: ' + (data.error || 'Failed to complete OpenObserve ingestion.'));
                            btnSync.disabled = false;
                            btnSync.innerHTML = originalHtml;
                        }
                    })
                    .catch(function (err) {
                        alert('Sync error: ' + err.message);
                        btnSync.disabled = false;
                        btnSync.innerHTML = originalHtml;
                    });
                });
            }

            // 8. Archive & Rotate Logs with Retention (No destructive deletion)
            const btnArchive = document.getElementById('btn-archive-logs');
            if (btnArchive) {
                btnArchive.addEventListener('click', function () {
                    if (!confirm('Archive active laravel.log into a compressed timestamped backup (retaining latest 5 rotations) and start a fresh log buffer?')) {
                        return;
                    }
                    const originalHtml = btnArchive.innerHTML;
                    btnArchive.disabled = true;
                    btnArchive.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> ARCHIVING...';

                    fetch('{{ route('console.miscellaneous.app-errors.archive-logs', $workspace->getKey()) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json'
                        }
                    })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.success) {
                            if (window.AlphaLinkToast) {
                                window.AlphaLinkToast.show('Log archived cleanly: ' + (data.archived_file || 'done') + ' (' + (data.retained_count || 0) + ' retained)', 'success');
                            } else {
                                alert('Log archived: ' + (data.archived_file || 'done'));
                            }
                            setTimeout(function () { window.location.reload(); }, 1000);
                        } else {
                            alert('Archive error: ' + (data.error || 'Unable to archive log file.'));
                            btnArchive.disabled = false;
                            btnArchive.innerHTML = originalHtml;
                        }
                    })
                    .catch(function (err) {
                        alert('Archive error: ' + err.message);
                        btnArchive.disabled = false;
                        btnArchive.innerHTML = originalHtml;
                    });
                });
            }

            // 9. Sanitized Exports (JSON & CSV)
            function triggerDownload(content, filename, mimeType) {
                const blob = new Blob([content], { type: mimeType });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }

            const btnExportJson = document.getElementById('btn-export-json');
            if (btnExportJson) {
                btnExportJson.addEventListener('click', function () {
                    const stamp = new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-');
                    triggerDownload(JSON.stringify(currentTelemetryHits, null, 2), 'alphalink-errors-' + stamp + '.json', 'application/json');
                });
            }

            const btnExportCsv = document.getElementById('btn-export-csv');
            if (btnExportCsv) {
                btnExportCsv.addEventListener('click', function () {
                    const headers = ['Date', 'Level', 'Module', 'Occurrences', 'File', 'Line', 'Summary', 'RequestID', 'Fingerprint'];
                    const rows = [headers.join(',')];
                    currentTelemetryHits.forEach(function (item) {
                        const row = [
                            '"' + String(item.date || '').replace(/"/g, '""') + '"',
                            '"' + String(item.level || '').replace(/"/g, '""') + '"',
                            '"' + String(item.module || '').replace(/"/g, '""') + '"',
                            Number(item.occurrences_count || 1),
                            '"' + String(item.file || '').replace(/"/g, '""') + '"',
                            item.line || '',
                            '"' + String(item.summary || item.message || '').replace(/"/g, '""') + '"',
                            '"' + String(item.request_id || '').replace(/"/g, '""') + '"',
                            '"' + String(item.fingerprint || '').replace(/"/g, '""') + '"'
                        ];
                        rows.push(row.join(','));
                    });
                    const stamp = new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-');
                    triggerDownload(rows.join('\n'), 'alphalink-errors-' + stamp + '.csv', 'text/csv;charset=utf-8;');
                });
            }

            // 10. Read-Only Auto-Refresh Polling (NOC Mode)
            const autoRefreshSelect = document.getElementById('select-auto-refresh');
            const countdownBadge = document.getElementById('auto-refresh-countdown');
            let refreshTimerId = null;
            let remainingSeconds = 0;

            function startAutoRefresh(intervalSeconds) {
                if (refreshTimerId) {
                    clearInterval(refreshTimerId);
                    refreshTimerId = null;
                }
                if (intervalSeconds <= 0) {
                    if (countdownBadge) countdownBadge.classList.add('d-none');
                    try { localStorage.setItem('alphalink-app-errors-refresh', '0'); } catch (e) {}
                    return;
                }
                try { localStorage.setItem('alphalink-app-errors-refresh', String(intervalSeconds)); } catch (e) {}
                remainingSeconds = intervalSeconds;
                if (countdownBadge) {
                    countdownBadge.classList.remove('d-none');
                    countdownBadge.innerText = remainingSeconds + 's';
                }

                refreshTimerId = setInterval(function () {
                    remainingSeconds--;
                    if (countdownBadge) countdownBadge.innerText = remainingSeconds + 's';
                    if (remainingSeconds <= 0) {
                        remainingSeconds = intervalSeconds;
                        // Check if any trace drawer is currently open
                        const anyDrawerOpen = document.querySelector('.trace-drawer-row:not(.d-none)') !== null;
                        const fetchUrl = window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'json=1';

                        fetch(fetchUrl, { headers: { 'Accept': 'application/json' } })
                            .then(function (res) { return res.json(); })
                            .then(function (data) {
                                // Smoothly update KPI counters
                                const kpiTotal = document.getElementById('kpi-total-errors');
                                const kpiCrit = document.getElementById('kpi-critical-errors');
                                const kpiJobs = document.getElementById('kpi-failed-jobs');
                                const kpiMod = document.getElementById('kpi-top-module');
                                if (kpiTotal && data.total_errors !== undefined) kpiTotal.innerText = Number(data.total_errors).toLocaleString();
                                if (kpiCrit && data.critical_count !== undefined) kpiCrit.innerText = Number(data.critical_count).toLocaleString();
                                if (kpiJobs && data.failed_jobs_count !== undefined) kpiJobs.innerText = Number(data.failed_jobs_count).toLocaleString();
                                if (kpiMod && data.top_module) kpiMod.innerText = String(data.top_module).toUpperCase();

                                // Reload full table only if operator isn't reading an open stack trace drawer
                                if (!anyDrawerOpen) {
                                    window.location.reload();
                                } else if (window.AlphaLinkToast) {
                                    window.AlphaLinkToast.show('Telemetry updated in background (trace drawer kept open).', 'info');
                                }
                            })
                            .catch(function () {});
                    }
                }, 1000);
            }

            if (autoRefreshSelect) {
                try {
                    const saved = Number(localStorage.getItem('alphalink-app-errors-refresh') || '0');
                    if (saved > 0) {
                        autoRefreshSelect.value = String(saved);
                        startAutoRefresh(saved);
                    }
                } catch (e) {}

                autoRefreshSelect.addEventListener('change', function () {
                    startAutoRefresh(Number(autoRefreshSelect.value || '0'));
                });
            }

            // 11. Telegram Alert Composer & Rules Modals (Command OS Theme)
            const dialogTgSend = document.getElementById('dialog-telegram-send');
            const dialogTgRules = document.getElementById('dialog-telegram-rules');

            function notifyUser(message, tone) {
                if (window.AlphaLinkToast) {
                    window.AlphaLinkToast.show(message, tone || 'info');
                } else {
                    alert(message);
                }
            }

            // Open Per-Error Telegram Send Modal
            document.querySelectorAll('.btn-open-telegram-modal').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!dialogTgSend) return;
                    let payload = {};
                    try {
                        payload = JSON.parse(btn.getAttribute('data-error-payload') || '{}');
                    } catch (e) {}

                    document.getElementById('tg-field-summary').value = payload.summary || 'Application Error';
                    document.getElementById('tg-field-level').value = payload.level || 'ERROR';
                    document.getElementById('tg-field-module').value = payload.module || 'system';
                    document.getElementById('tg-field-file').value = payload.file || 'laravel.log';
                    document.getElementById('tg-field-request-id').value = payload.request_id || '';
                    document.getElementById('tg-field-fingerprint').value = payload.fingerprint || '';
                    document.getElementById('tg-field-occurrences').value = payload.occurrences_count || 1;

                    const lvlEl = document.getElementById('tg-preview-level');
                    if (lvlEl) {
                        const lvl = String(payload.level || 'ERROR').toUpperCase();
                        lvlEl.textContent = lvl;
                        lvlEl.className = 'badge-level badge-level-' + lvl.toLowerCase();
                    }
                    const modEl = document.getElementById('tg-preview-module');
                    if (modEl) {
                        const mod = String(payload.module || 'system').toLowerCase();
                        modEl.textContent = mod.toUpperCase();
                        modEl.className = 'badge-module badge-module-' + mod;
                    }
                    const occEl = document.getElementById('tg-preview-occ');
                    if (occEl) {
                        const cnt = Number(payload.occurrences_count || 1);
                        if (cnt > 1) {
                            occEl.textContent = cnt + 'x';
                            occEl.classList.remove('d-none');
                        } else {
                            occEl.classList.add('d-none');
                        }
                    }

                    document.getElementById('tg-preview-summary').textContent = payload.summary || '';
                    document.getElementById('tg-preview-file').textContent = payload.file || 'laravel.log';
                    document.getElementById('tg-custom-message').value = '';

                    if (typeof dialogTgSend.showModal === 'function') {
                        dialogTgSend.showModal();
                    }
                });
            });

            [document.getElementById('btn-close-tg-send'), document.getElementById('btn-cancel-tg-send')].forEach(function (el) {
                if (el && dialogTgSend) {
                    el.addEventListener('click', function () { dialogTgSend.close(); });
                }
            });

            // Quick Preset Chips for Custom Message
            document.querySelectorAll('.tg-preset-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    const ta = document.getElementById('tg-custom-message');
                    if (ta) {
                        ta.value = chip.getAttribute('data-text') || '';
                        ta.focus();
                    }
                });
            });

            // Dispatch Custom Per-Error Telegram Alert
            const btnSubmitTgSend = document.getElementById('btn-submit-tg-send');
            if (btnSubmitTgSend) {
                btnSubmitTgSend.addEventListener('click', function () {
                    const originalHtml = btnSubmitTgSend.innerHTML;
                    btnSubmitTgSend.disabled = true;
                    btnSubmitTgSend.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> SENDING...';

                    fetch('{{ route('console.miscellaneous.app-errors.telegram.send', $workspace->getKey()) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            channel_type: document.getElementById('tg-select-channel').value,
                            custom_message: document.getElementById('tg-custom-message').value,
                            error_summary: document.getElementById('tg-field-summary').value,
                            level: document.getElementById('tg-field-level').value,
                            module: document.getElementById('tg-field-module').value,
                            file: document.getElementById('tg-field-file').value,
                            request_id: document.getElementById('tg-field-request-id').value,
                            fingerprint: document.getElementById('tg-field-fingerprint').value,
                            occurrences_count: Number(document.getElementById('tg-field-occurrences').value || 1),
                            include_summary: document.getElementById('tg-opt-summary').checked,
                            include_link: document.getElementById('tg-opt-link').checked,
                            include_request_id: document.getElementById('tg-opt-reqid').checked
                        })
                    })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        btnSubmitTgSend.disabled = false;
                        btnSubmitTgSend.innerHTML = originalHtml;
                        if (data.success) {
                            notifyUser('Telegram alert sent to: ' + (data.sent_channels || []).join(', ').toUpperCase(), 'success');
                            if (dialogTgSend) dialogTgSend.close();
                        } else {
                            notifyUser('Telegram Error: ' + (data.error || 'Failed to deliver alert.'), 'danger');
                        }
                    })
                    .catch(function (err) {
                        btnSubmitTgSend.disabled = false;
                        btnSubmitTgSend.innerHTML = originalHtml;
                        notifyUser('Network error: ' + err.message, 'danger');
                    });
                });
            }

            // Open Automated Rules & Templates Modal
            const btnOpenRules = document.getElementById('btn-telegram-rules');
            if (btnOpenRules && dialogTgRules) {
                btnOpenRules.addEventListener('click', function () {
                    if (typeof dialogTgRules.showModal === 'function') {
                        dialogTgRules.showModal();
                    }
                });
            }
            [document.getElementById('btn-close-tg-rules'), document.getElementById('btn-cancel-tg-rules')].forEach(function (el) {
                if (el && dialogTgRules) {
                    el.addEventListener('click', function () { dialogTgRules.close(); });
                }
            });

            // Insert Template Tokens at Cursor
            document.querySelectorAll('.tg-token-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    const ta = document.getElementById('rule-custom-template');
                    const token = chip.getAttribute('data-token') || '';
                    if (!ta || !token) return;
                    const start = ta.selectionStart || 0;
                    const end = ta.selectionEnd || 0;
                    ta.value = ta.value.substring(0, start) + token + ta.value.substring(end);
                    ta.focus();
                    ta.selectionStart = ta.selectionEnd = start + token.length;
                });
            });

            // Reset Default Template
            const btnResetTpl = document.getElementById('btn-reset-template');
            if (btnResetTpl) {
                btnResetTpl.addEventListener('click', function () {
                    const ta = document.getElementById('rule-custom-template');
                    if (ta) {
                        ta.value = @json(\App\Services\Telegram\TelegramAlertService::DEFAULT_TEMPLATE);
                    }
                });
            }

            // Save Automated Rules & Template
            const btnSaveRules = document.getElementById('btn-save-tg-rules');
            if (btnSaveRules) {
                btnSaveRules.addEventListener('click', function () {
                    const originalHtml = btnSaveRules.innerHTML;
                    btnSaveRules.disabled = true;
                    btnSaveRules.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> SAVING...';

                    fetch('{{ route('console.miscellaneous.app-errors.telegram.config', $workspace->getKey()) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            enabled: document.getElementById('rule-enabled').checked,
                            target_channel: document.getElementById('rule-channel').value,
                            severity_threshold: document.getElementById('rule-threshold').value,
                            cooldown_minutes: Number(document.getElementById('rule-cooldown').value || 30),
                            custom_prefix: document.getElementById('rule-custom-prefix').value,
                            custom_template: document.getElementById('rule-custom-template').value
                        })
                    })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        btnSaveRules.disabled = false;
                        btnSaveRules.innerHTML = originalHtml;
                        if (data.success) {
                            notifyUser('Telegram alert rules & custom templates saved!', 'success');
                            setTimeout(function () { window.location.reload(); }, 900);
                        } else {
                            notifyUser('Error saving rules.', 'danger');
                        }
                    })
                    .catch(function (err) {
                        btnSaveRules.disabled = false;
                        btnSaveRules.innerHTML = originalHtml;
                        notifyUser('Error: ' + err.message, 'danger');
                    });
                });
            }

            // Test Telegram Connection
            const btnTestTg = document.getElementById('btn-test-telegram');
            if (btnTestTg) {
                btnTestTg.addEventListener('click', function () {
                    const originalHtml = btnTestTg.innerHTML;
                    btnTestTg.disabled = true;
                    btnTestTg.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> TESTING...';

                    fetch('{{ route('console.miscellaneous.app-errors.telegram.test', $workspace->getKey()) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            channel_type: document.getElementById('rule-channel').value
                        })
                    })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        btnTestTg.disabled = false;
                        btnTestTg.innerHTML = originalHtml;
                        if (data.success) {
                            notifyUser('Test ping delivered to: ' + (data.sent_channels || []).join(', ').toUpperCase(), 'success');
                        } else {
                            notifyUser('Ping failed: ' + (data.error || 'Verify Bot Token & Channel ID in Settings.'), 'danger');
                        }
                    })
                    .catch(function (err) {
                        btnTestTg.disabled = false;
                        btnTestTg.innerHTML = originalHtml;
                        notifyUser('Network error: ' + err.message, 'danger');
                    });
                });
            }
        });
    </script>
@endsection
