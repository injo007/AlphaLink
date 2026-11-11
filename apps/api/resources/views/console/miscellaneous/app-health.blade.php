@extends('layouts.admin-shell')

@section('title', 'App Health Monitor')

@section('content')
    @php
        $results = is_array($report['stored_check_results'] ?? null) ? $report['stored_check_results'] : [];
        $totalChecks = count($results);
        $okChecks = collect($results)->where('status', 'ok')->count();
        $failedChecks = collect($results)->where('status', 'failed')->count();
        $unknownChecks = max(0, $totalChecks - $okChecks - $failedChecks);
        $overallHealthy = ($report['status'] ?? 'ok') === 'ok' && $failedChecks === 0;
        $finishedAt = ! empty($report['finished_at'])
            ? \Illuminate\Support\Carbon::parse($report['finished_at'])
            : now();

        $uptimeCheck = collect($results)->firstWhere('label', 'Uptime');
        $ramCheck = collect($results)->firstWhere('label', 'RAM');
        $uptimeHuman = $uptimeCheck
            ? trim(str_replace('Uptime since ', '', (string) ($uptimeCheck['notificationMessage'] ?? 'Unavailable')))
            : 'Unavailable';

        $ramPercent = null;
        $ramMsg = (string) ($ramCheck['notificationMessage'] ?? '');
        if (preg_match('/Percent used:\s*([0-9]+(?:\.[0-9]+)?)\s*%/i', $ramMsg, $m) === 1) {
            $ramPercent = min(100.0, max(0.0, (float) $m[1]));
        }

        $checkMeta = [
            'Uptime' => ['icon' => 'fas fa-clock', 'tone' => 'indigo', 'category' => 'KERNEL'],
            'RAM' => ['icon' => 'fas fa-memory', 'tone' => 'purple', 'category' => 'MEMORY'],
            'Database' => ['icon' => 'fas fa-database', 'tone' => 'emerald', 'category' => 'POSTGRESQL'],
            'Application storage' => ['icon' => 'fas fa-hdd', 'tone' => 'blue', 'category' => 'FILESYSTEM'],
            'Queue' => ['icon' => 'fas fa-stream', 'tone' => 'amber', 'category' => 'HORIZON / QUEUE'],
        ];
    @endphp

    <div class="command-os-entity-page command-os-report-page" id="app-health-root">
        <!-- 1. Atmospheric Hero Header -->
        <header class="command-os-report-hero mb-4" aria-labelledby="app-health-hero-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas fa-heartbeat"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas fa-heartbeat"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">OPERATIONS TOOLKIT // SYSTEM DIAGNOSTICS</p>
                <h1 id="app-health-hero-title" class="command-os-report-hero-title">APP HEALTH MONITOR</h1>
                <p class="command-os-report-hero-desc">Real-time host telemetry, memory utilization, database availability, storage writability, and queue readiness.</p>
            </div>
            <div class="command-os-hero-actions" style="flex-wrap: wrap;">
                @if ($overallHealthy)
                    <span class="badge-status-live" title="All recorded health probes are passing">
                        <span class="pulse-indicator pulse-green"></span>
                        <span class="status-label">SYSTEMS NOMINAL</span>
                        <span class="status-sub">({{ $okChecks }}/{{ $totalChecks }})</span>
                    </span>
                @else
                    <span class="badge-status-fallback" title="One or more health probes reported warnings or failures">
                        <span class="pulse-indicator pulse-amber"></span>
                        <span class="status-label">ATTENTION REQUIRED</span>
                        <span class="status-sub">({{ $failedChecks }} failed)</span>
                    </span>
                @endif

                <span class="command-os-health-timestamp" title="{{ $finishedAt->toIso8601String() }}">
                    <i class="fas fa-history mr-1 text-purple"></i>
                    <span>last run: <strong>{{ $finishedAt->diffForHumans() }}</strong></span>
                </span>

                <a href="{{ route('console.miscellaneous.app-health', ['workspace' => $workspace->getKey(), 'json' => 1]) }}"
                   class="btn-command-action"
                   target="_blank"
                   rel="noopener noreferrer"
                   title="View raw JSON health snapshot">
                    <i class="fas fa-code mr-1"></i> <span class="d-none d-xl-inline">JSON</span>
                </a>

                <a href="{{ route('console.miscellaneous.app-health', ['workspace' => $workspace->getKey(), 'refresh' => 1]) }}"
                   class="btn-command-primary"
                   style="height: 36px; padding: 0 15px; font-size: 0.78rem;"
                   id="btn-run-health-checks"
                   title="Execute fresh read-only system probes">
                    <i class="fas fa-sync-alt mr-1"></i> RUN PROBES
                </a>
            </div>
        </header>

        <!-- Diagnostics Sub-nav Tabs -->
        <nav class="d-flex flex-wrap align-items-center mb-4 pb-2 border-bottom command-os-subnav" style="gap: 10px;" aria-label="System Diagnostics Navigation">
            <a href="{{ route('console.miscellaneous.app-errors', $workspace->getKey()) }}" class="btn-command-action" style="height: 36px; padding: 0 16px; font-size: 0.8125rem;">
                <i class="fas fa-bug mr-1"></i> APP ERRORS
            </a>
            <a href="{{ route('console.miscellaneous.app-health', $workspace->getKey()) }}" class="btn-command-primary" aria-current="page" style="height: 36px; padding: 0 16px; font-size: 0.8125rem;">
                <i class="fas fa-heartbeat mr-1"></i> APP HEALTH
            </a>
            <a href="{{ route('console.miscellaneous.horizon', $workspace->getKey()) }}" class="btn-command-action" style="height: 36px; padding: 0 16px; font-size: 0.8125rem;">
                <i class="fas fa-balance-scale mr-1"></i> HORIZON
            </a>
            <a href="{{ route('console.app-settings.index', $workspace->getKey()) }}" class="btn-command-action" style="height: 36px; padding: 0 16px; font-size: 0.8125rem;">
                <i class="fas fa-cogs mr-1"></i> SETTINGS
            </a>
        </nav>

        <!-- 2. Operational KPI Summary Cards -->
        <section class="command-os-all-stats-overview mb-4" aria-label="Health Overview Metrics">
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon {{ $overallHealthy ? 'icon-emerald' : 'icon-amber' }}" aria-hidden="true">
                    <i class="fas {{ $overallHealthy ? 'fa-shield-alt' : 'fa-exclamation-triangle' }}"></i>
                </span>
                <span class="all-stats-overview-copy">
                    <span>Health Posture</span>
                    <strong>{{ $okChecks }} / {{ $totalChecks }} Passing</strong>
                    <small>{{ $failedChecks > 0 ? "{$failedChecks} failed, {$unknownChecks} unknown" : 'Zero critical failures' }}</small>
                </span>
            </article>

            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-clock"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Host Uptime</span>
                    <strong style="font-size: 1.05rem;">{{ \Illuminate\Support\Str::limit($uptimeHuman, 24) }}</strong>
                    <small>Continuous server runtime</small>
                </span>
            </article>

            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon {{ ($ramPercent ?? 0) > 90 ? 'icon-crimson' : 'icon-blue' }}" aria-hidden="true">
                    <i class="fas fa-memory"></i>
                </span>
                <span class="all-stats-overview-copy" style="width: 100%;">
                    <span>Memory Utilization</span>
                    <strong>{{ $ramPercent !== null ? number_format($ramPercent, 1).'%' : ($ramCheck['shortSummary'] ?? 'N/A') }}</strong>
                    @if ($ramPercent !== null)
                        <div class="command-os-health-meter mt-1" aria-hidden="true">
                            <span style="width: {{ $ramPercent }}%;" class="{{ $ramPercent > 90 ? 'is-danger' : ($ramPercent > 75 ? 'is-warn' : 'is-ok') }}"></span>
                        </div>
                    @else
                        <small>Threshold: &lt; 90% RAM used</small>
                    @endif
                </span>
            </article>

            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-indigo" aria-hidden="true"><i class="fas fa-clock"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Snapshot Freshness</span>
                    <strong style="font-size: 1.05rem;">{{ $finishedAt->format('H:i:s T') }}</strong>
                    <small>{{ $finishedAt->toDateString() }} • Cached 24h</small>
                </span>
            </article>
        </section>

        <!-- 3. Interactive Probe Status Cards -->
        @if (! empty($results))
            <section class="row mb-3" aria-label="Diagnostic Check Cards">
                @foreach ($results as $result)
                    @php
                        $label = (string) ($result['label'] ?? 'Check');
                        $status = (string) ($result['status'] ?? 'unknown');
                        $meta = $checkMeta[$label] ?? ['icon' => 'fas fa-stethoscope', 'tone' => 'purple', 'category' => 'PROBE'];
                        $badgeTone = $status === 'ok' ? 'is-ok' : ($status === 'failed' ? 'is-failed' : 'is-unknown');
                    @endphp
                    <div class="col-md-6 col-xl-4 mb-3">
                        <article class="command-os-health-card h-100 p-3 {{ $badgeTone }}">
                            <div class="d-flex align-items-start justify-content-between mb-2" style="gap: 10px;">
                                <div class="d-flex align-items-center" style="gap: 10px;">
                                    <span class="command-os-health-icon tone-{{ $meta['tone'] }}">
                                        <i class="{{ $meta['icon'] }}" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <div class="command-os-health-kicker">{{ $meta['category'] }}</div>
                                        <h2 class="command-os-health-title">{{ $label }}</h2>
                                    </div>
                                </div>
                                <span class="command-os-health-pill {{ $badgeTone }}">
                                    <i class="fas {{ $status === 'ok' ? 'fa-check-circle' : ($status === 'failed' ? 'fa-times-circle' : 'fa-question-circle') }} mr-1"></i>
                                    {{ strtoupper($status) }}
                                </span>
                            </div>
                            <div class="command-os-health-summary mb-1">{{ $result['shortSummary'] ?? '' }}</div>
                            <p class="command-os-health-msg mb-0">{{ $result['notificationMessage'] ?? '' }}</p>
                        </article>
                    </div>
                @endforeach
            </section>
        @endif

        <!-- 4. Full Structured Probe Ledger Table -->
        <div class="command-os-table-card p-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-3" style="gap: 10px;">
                <div>
                    <h2 class="h6 font-w800 mb-0" style="letter-spacing: 0.02em;">
                        <i class="fas fa-clipboard-list mr-2 text-purple"></i>DIAGNOSTIC PROBE LEDGER
                    </h2>
                    <p class="text-muted small mb-0">Read-only cached verification report generated locally or via <code>alphalink:health-check</code>.</p>
                </div>
                <span class="badge-command-pill small font-w600">
                    last run: {{ $finishedAt->toDateTimeString() }}
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover command-os-table mb-0">
                    <thead>
                        <tr>
                            <th>CHECK</th>
                            <th class="text-center">Summary</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($results as $result)
                            @php
                                $rowStatus = (string) ($result['status'] ?? 'unknown');
                            @endphp
                            <tr>
                                <td class="font-w700">
                                    <i class="fas {{ $rowStatus === 'ok' ? 'fa-check-circle text-success' : ($rowStatus === 'failed' ? 'fa-times-circle text-danger' : 'fa-exclamation-circle text-warning') }} mr-2"></i>
                                    {{ $result['label'] }}
                                </td>
                                <td class="text-center">
                                    <span class="command-os-health-pill {{ $rowStatus === 'ok' ? 'is-ok' : ($rowStatus === 'failed' ? 'is-failed' : 'is-unknown') }}">
                                        {{ $result['shortSummary'] }}
                                    </span>
                                </td>
                                <td class="text-muted" style="font-size: 0.82rem;">
                                    {{ $result['notificationMessage'] }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center py-4 text-muted">No diagnostic checks have been recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .command-os-health-timestamp {
            display: inline-flex;
            align-items: center;
            height: 36px;
            padding: 0 12px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid #d9d6f4;
            font-size: 0.78rem;
            color: #313b67;
        }
        .command-os-health-card {
            background: linear-gradient(145deg, #ffffff 0%, #faf8ff 100%);
            border: 1px solid #e1dcf6;
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(74, 58, 160, 0.06);
            transition: transform 0.16s ease, box-shadow 0.16s ease;
        }
        .command-os-health-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(74, 58, 160, 0.1);
        }
        .command-os-health-card.is-failed {
            border-color: rgba(239, 68, 68, 0.45);
        }
        .command-os-health-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }
        .command-os-health-icon.tone-purple { background: rgba(121, 43, 250, 0.12); color: #792bfa; }
        .command-os-health-icon.tone-indigo { background: rgba(99, 102, 241, 0.12); color: #4f46e5; }
        .command-os-health-icon.tone-emerald { background: rgba(16, 185, 129, 0.12); color: #059669; }
        .command-os-health-icon.tone-blue { background: rgba(14, 165, 233, 0.12); color: #0284c7; }
        .command-os-health-icon.tone-amber { background: rgba(245, 158, 11, 0.14); color: #d97706; }
        .command-os-health-kicker {
            font-size: 0.65rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            color: #7c85a8;
        }
        .command-os-health-title {
            font-size: 0.95rem;
            font-weight: 800;
            margin: 0;
            color: #151d48;
        }
        .command-os-health-summary {
            font-size: 0.84rem;
            font-weight: 700;
            color: #27315f;
        }
        .command-os-health-msg {
            font-size: 0.78rem;
            color: #647096;
            line-height: 1.45;
        }
        .command-os-health-pill {
            display: inline-flex;
            align-items: center;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.03em;
        }
        .command-os-health-pill.is-ok { background: rgba(16, 185, 129, 0.13); color: #059669; }
        .command-os-health-pill.is-failed { background: rgba(239, 68, 68, 0.14); color: #dc2626; }
        .command-os-health-pill.is-unknown { background: rgba(245, 158, 11, 0.15); color: #d97706; }
        .command-os-health-meter {
            width: 100%;
            height: 7px;
            border-radius: 999px;
            background: rgba(121, 43, 250, 0.12);
            overflow: hidden;
        }
        .command-os-health-meter > span {
            display: block;
            height: 100%;
            border-radius: 999px;
        }
        .command-os-health-meter > span.is-ok { background: linear-gradient(90deg, #792bfa, #10b981); }
        .command-os-health-meter > span.is-warn { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .command-os-health-meter > span.is-danger { background: linear-gradient(90deg, #ef4444, #dc2626); }

        /* Status Badges matching App Errors */
        .badge-status-live, .badge-status-fallback {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            height: 36px;
            padding: 0 12px;
            border-radius: 10px;
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.03em;
        }
        .badge-status-live {
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.35);
            color: #047857;
        }
        .badge-status-fallback {
            background: rgba(245, 158, 11, 0.14);
            border: 1px solid rgba(245, 158, 11, 0.35);
            color: #b45309;
        }
        .pulse-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .pulse-green { background: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25); }
        .pulse-amber { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.25); }

        html[data-command-theme='dark'] .command-os-health-timestamp {
            background: rgba(15, 23, 42, 0.78);
            border-color: #283a69;
            color: #e2e8f0;
        }
        html[data-command-theme='dark'] .command-os-health-card {
            background: linear-gradient(145deg, #101936 0%, #141f42 100%);
            border-color: #26365f;
        }
        html[data-command-theme='dark'] .command-os-health-title { color: #f1f5f9; }
        html[data-command-theme='dark'] .command-os-health-summary { color: #cbd5e1; }
        html[data-command-theme='dark'] .command-os-health-msg { color: #94a3b8; }
        html[data-command-theme='dark'] .badge-status-live {
            background: rgba(16, 185, 129, 0.16);
            color: #34d399;
        }
        html[data-command-theme='dark'] .badge-status-fallback {
            background: rgba(245, 158, 11, 0.16);
            color: #fbbf24;
        }
    </style>
@endpush
