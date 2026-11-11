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
            <div class="command-os-hero-actions">
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
                <button type="button" class="btn-command-action" id="btn-sync-openobserve" title="Ingest recent local logs and failed queue jobs into OpenObserve">
                    <i class="fas fa-cloud-upload-alt mr-1"></i> <span class="d-none d-sm-inline">SYNC TO OPENOBSERVE</span><span class="d-sm-none">SYNC</span>
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
            <form id="filter-form" method="GET" action="{{ route('console.miscellaneous.app-errors', $workspace->getKey()) }}" class="row align-items-center g-2 m-0">
                <!-- Search input -->
                <div class="col-12 col-md-4 p-1">
                    <div class="input-with-icon">
                        <i class="fas fa-search input-icon"></i>
                        <input type="text" name="search" id="input-search" class="form-control form-control-sm command-input" placeholder="Search message, request ID, file, trace..." value="{{ $filters['search'] ?? '' }}">
                        @if (!empty($filters['search']))
                            <button type="button" class="btn-clear-search" id="btn-clear-search" aria-label="Clear search">&times;</button>
                        @endif
                    </div>
                </div>

                <!-- Severity Level -->
                <div class="col-6 col-md-2 p-1">
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
                <div class="col-6 col-md-2 p-1">
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
                <div class="col-6 col-md-2 p-1">
                    <select name="time_range" id="select-time-range" class="form-control form-control-sm command-select">
                        <option value="" @selected(empty($filters['time_range']))>All Time (Recent)</option>
                        <option value="1h" @selected(($filters['time_range'] ?? '') === '1h')>Last 1 Hour</option>
                        <option value="24h" @selected(($filters['time_range'] ?? '') === '24h')>Last 24 Hours</option>
                        <option value="7d" @selected(($filters['time_range'] ?? '') === '7d')>Last 7 Days</option>
                        <option value="30d" @selected(($filters['time_range'] ?? '') === '30d')>Last 30 Days</option>
                    </select>
                </div>

                <!-- Filter Actions -->
                <div class="col-6 col-md-2 p-1 d-flex justify-content-end" style="gap: 6px;">
                    <button type="submit" class="btn-command-primary w-100" style="height: 34px; font-size: 0.75rem;">
                        <i class="fas fa-filter mr-1"></i> APPLY
                    </button>
                    <a href="{{ route('console.miscellaneous.app-errors', $workspace->getKey()) }}" class="btn-command-action" style="height: 34px; padding: 0 10px; font-size: 0.75rem;" title="Reset filters">
                        <i class="fas fa-undo"></i>
                    </a>
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
                                                <span class="message-text">{{ $hit['summary'] ?? $hit['message'] ?? 'Error' }}</span>
                                            </div>
                                            <div class="cell-source-file mt-1">
                                                <i class="far fa-file-code mr-1 text-muted"></i>
                                                <code class="file-code">{{ $hit['file'] ?? 'unknown' }}@if(!empty($hit['line'])):{{ $hit['line'] }}@endif</code>
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

                                    <!-- Action Drawer Toggle -->
                                    <td style="text-align: right;">
                                        <button type="button" class="btn-toggle-trace" data-target="trace-{{ $hitId }}" aria-expanded="false" title="Inspect stack trace & context">
                                            <span>Trace</span>
                                            <i class="fas fa-chevron-down ml-1 arrow-icon"></i>
                                        </button>
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
                                                </div>
                                                <div class="d-flex align-items-center" style="gap: 8px;">
                                                    @if (!empty($hit['request_id']))
                                                        <span class="badge-corr-pill">
                                                            <i class="fas fa-fingerprint mr-1"></i> REQ: {{ $hit['request_id'] }}
                                                        </span>
                                                    @endif
                                                    <button type="button" class="btn-copy-trace" data-trace-id="trace-content-{{ $hitId }}">
                                                        <i class="far fa-copy mr-1"></i> Copy Trace
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Context Metadata (if any) -->
                                            @if (!empty($hit['context']))
                                                <div class="mb-2">
                                                    <div class="text-muted small font-w600 mb-1">EXECUTION CONTEXT:</div>
                                                    <pre class="trace-context-box"><code>{{ json_encode($hit['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                                                </div>
                                            @endif

                                            <!-- Formatted Stack Trace Box -->
                                            <div class="trace-box-wrapper">
                                                <div class="text-muted small font-w600 mb-1">EXCEPTION STACK TRACE:</div>
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
    </style>

    <!-- Client-side Telemetry Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
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
        });
    </script>
@endsection
