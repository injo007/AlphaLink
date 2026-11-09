@extends('layouts.admin-shell')

@section('title', $title)

@section('content')
    @php($reportRoute = match ($kind) {
        'all-stats' => 'console.analytics.all-stats',
        'isp-stats' => 'console.analytics.isp-stats',
        default => 'console.analytics.mailer-revenue',
    })
    @php($dateStorageKey = match ($kind) {
        'all-stats' => 'all_stats',
        'isp-stats' => 'isp_stats',
        default => 'mailers_stats',
    })
    @php($icon = match ($kind) {
        'all-stats' => 'fas fa-chart-line',
        'isp-stats' => 'fas fa-network-wired',
        default => 'fas fa-funnel-dollar',
    })
    @php($subtitle = match ($kind) {
        'all-stats' => 'Explore campaign and Drop activity across dimensions available to your workspace',
        'isp-stats' => 'Deliverability, provider breakdown, and engagement trend analysis by reporting period',
        default => 'Operator performance, volume deliveries, and attributed lead earnings across mailers',
    })

    <div class="command-os-entity-page {{ $kind === 'all-stats' ? 'command-os-all-stats-report' : ($kind === 'mailer-revenue' ? 'command-os-mailer-revenue-report' : '') }}">
        <!-- 1. Hero Card -->
        <header class="command-os-hero-card">
            <div class="command-os-hero-card-left">
                <div class="command-os-hero-mark" aria-hidden="true">
                    <i class="{{ $icon }}"></i>
                </div>
                <div class="command-os-hero-copy">
                    <span class="command-os-hero-eyebrow">TRACKING &amp; ANALYTICS</span>
                    <h1 class="command-os-hero-title">{{ $title }}</h1>
                    <p class="command-os-hero-subtitle">{{ $subtitle }}</p>
                </div>
            </div>
            <div class="command-os-hero-card-right d-flex align-items-center">
                <div class="badge badge-light border text-purple px-3 py-2 font-w700 mr-2 d-none d-md-inline-flex align-items-center" style="border-radius: 8px;">
                    <i class="far fa-calendar-alt mr-2 text-purple"></i>
                    <span data-report-window-start>{{ $from }}</span>
                    <i class="fas fa-arrow-right mx-2 font-size-xs text-muted" aria-hidden="true"></i>
                    <span data-report-window-end>{{ $to }}</span>
                </div>

                @if (in_array($kind, ['all-stats', 'mailer-revenue'], true))
                    <button type="button" class="command-os-btn-option mr-1" id="{{ $kind === 'all-stats' ? 'all-stats-pivot-reload' : 'mailer-revenue-pivot-reload' }}" aria-label="Reload {{ $title }}" title="Reload">
                        <i class="fas fa-sync" aria-hidden="true"></i>
                    </button>
                    @if ($kind === 'mailer-revenue')
                        <button type="button" class="command-os-btn-option mr-1" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen" title="Full screen">
                            <i class="si si-size-fullscreen"></i>
                        </button>
                    @endif
                @endif
                @if ($kind === 'all-stats')
                    <button type="button" class="command-os-btn-option mr-1" id="all-stats-pivot-fullscreen" aria-label="Enter full screen" aria-pressed="false" title="Full screen">
                        <i class="fas fa-expand-alt" aria-hidden="true"></i>
                    </button>
                @endif
                @if ($kind === 'isp-stats')
                    <button type="button" class="command-os-btn-option mr-1" data-analytics-report-reload aria-label="Refresh ISP Stats" title="Refresh">
                        <i class="fas fa-sync" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="command-os-btn-option mr-1" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen" title="Full screen">
                        <i class="si si-size-fullscreen"></i>
                    </button>
                @endif
                <a href="{{ route($reportRoute, $workspace->id) }}" class="btn btn-sm btn-outline-secondary font-w700 font-size-xs px-3" data-clear-analytics-date-range="{{ $dateStorageKey }}" style="border-radius: 8px;">
                    RESET
                </a>
            </div>
        </header>

        <!-- 2. Overview KPI Cards -->
        @if ($kind === 'all-stats')
            <section class="command-os-all-stats-overview" aria-label="Tracking overview">
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-records" aria-hidden="true"><i class="fas fa-layer-group"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Aggregated Rows</span>
                        <strong>{{ number_format($rows->total()) }}</strong>
                        <small>Selected date range</small>
                    </span>
                </article>
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-leads" aria-hidden="true"><i class="fas fa-funnel-dollar"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Attributed Revenue</span>
                        <strong>${{ number_format(collect($rows->items())->sum('lead_revenue'), 2) }}</strong>
                        <small>Active page revenue</small>
                    </span>
                </article>
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-events" aria-hidden="true"><i class="fas fa-bolt"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Logged Events</span>
                        <strong>{{ number_format(collect($rows->items())->sum('event_count')) }}</strong>
                        <small>Active page events</small>
                    </span>
                </article>
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-scope" aria-hidden="true"><i class="fas fa-cubes"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Tracked Scope</span>
                        <strong>19 Dimensions</strong>
                        <small>Workspace scoped</small>
                    </span>
                </article>
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-pivot" aria-hidden="true"><i class="fas fa-table"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Pivot Mode</span>
                        <strong>Interactive</strong>
                        <small>Drag-and-drop dimensions</small>
                    </span>
                </article>
            </section>
        @elseif ($kind === 'isp-stats')
            <section class="command-os-all-stats-overview" aria-label="ISP stats overview">
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-records" aria-hidden="true"><i class="fas fa-envelope-open"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Active ISPs</span>
                        <strong>{{ number_format($isps->count()) }}</strong>
                        <small>Tracked providers</small>
                    </span>
                </article>
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-scope" aria-hidden="true"><i class="fas fa-layer-group"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Grouping</span>
                        <strong>{{ strtoupper($groupBy) }}</strong>
                        <small>Time interval</small>
                    </span>
                </article>
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-events" aria-hidden="true"><i class="fas fa-paper-plane"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Deliveries</span>
                        <strong>{{ number_format(collect($chartData['totals'] ?? [])->sum('deliveries')) }}</strong>
                        <small>Period total</small>
                    </span>
                </article>
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-leads" aria-hidden="true"><i class="fas fa-funnel-dollar"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Revenue</span>
                        <strong>${{ number_format(collect($chartData['totals'] ?? [])->sum('lead_revenue'), 2) }}</strong>
                        <small>Period attributed</small>
                    </span>
                </article>
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-pivot" aria-hidden="true"><i class="fas fa-chart-bar"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Period Trend</span>
                        <strong>Visual Charts</strong>
                        <small>Per-mailer breakdown</small>
                    </span>
                </article>
            </section>
        @elseif ($kind === 'mailer-revenue')
            <section class="command-os-all-stats-overview" aria-label="Mailer revenue overview">
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-records" aria-hidden="true"><i class="fas fa-users"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Active Mailers</span>
                        <strong>{{ number_format($rows->total()) }}</strong>
                        <small>With recorded events</small>
                    </span>
                </article>
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-leads" aria-hidden="true"><i class="fas fa-dollar-sign"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Attributed Revenue</span>
                        <strong>${{ number_format(collect($rows->items())->sum('lead_revenue'), 2) }}</strong>
                        <small>Lead earnings</small>
                    </span>
                </article>
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-events" aria-hidden="true"><i class="fas fa-paper-plane"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Deliveries</span>
                        <strong>{{ number_format(collect($rows->items())->sum('deliveries')) }}</strong>
                        <small>Delivered emails</small>
                    </span>
                </article>
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-scope" aria-hidden="true"><i class="fas fa-envelope-open"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Total Opens</span>
                        <strong>{{ number_format(collect($rows->items())->sum('opens')) }}</strong>
                        <small>Recipient opens</small>
                    </span>
                </article>
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-pivot" aria-hidden="true"><i class="fas fa-mouse-pointer"></i></span>
                    <span class="all-stats-overview-copy">
                        <span>Total Clicks</span>
                        <strong>{{ number_format(collect($rows->items())->sum('clicks')) }}</strong>
                        <small>Tracked clicks</small>
                    </span>
                </article>
            </section>
        @endif

        <!-- 3. Filter Bar Card -->
        <section class="command-os-filter-card" aria-label="Filter {{ $title }}">
            <form method="get" action="{{ route($reportRoute, $workspace->id) }}" class="form-row align-items-end" data-analytics-date-range-form data-storage-key="{{ $dateStorageKey }}" @if ($kind !== 'all-stats') data-analytics-report-refresh @endif novalidate>
                @if ($kind === 'isp-stats')
                    <div class="form-group col-sm-12 col-md-4 col-lg-3 mb-2 mb-lg-0">
                        <label for="analytics-dates" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            <i class="far fa-calendar-alt mr-1 text-purple"></i> Date Range:
                        </label>
                        <div class="command-input-wrap">
                            <input id="analytics-dates" type="text" class="form-control" value="{{ $from }} - {{ $to }}" readonly aria-label="Choose report date range" style="height: 42px;">
                            <input name="from" type="hidden" value="{{ $from }}" required>
                            <input name="to" type="hidden" value="{{ $to }}" required>
                        </div>
                    </div>
                    <div class="form-group col-sm-12 col-md-4 col-lg-3 mb-2 mb-lg-0">
                        <label for="analytics-isp" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            <i class="fas fa-server mr-1 text-purple"></i> Select ISP:
                        </label>
                        <div class="command-input-wrap">
                            <select id="analytics-isp" name="isp_id" class="form-control" style="height: 42px;">
                                <option value="">ALL ACTIVE ISPS</option>
                                @foreach ($isps as $isp)
                                    <option value="{{ $isp->id }}" @selected((string) $selectedIspId === (string) $isp->id)>{{ $isp->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="form-group col-sm-12 col-md-4 col-lg-3 mb-2 mb-lg-0">
                        <label for="analytics-groupby" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            <i class="fas fa-layer-group mr-1 text-purple"></i> Group By:
                        </label>
                        <div class="command-input-wrap">
                            <select id="analytics-groupby" name="groupby" class="form-control" style="height: 42px;">
                                @foreach (['day' => 'DAY', 'week' => 'WEEK', 'month' => 'MONTH'] as $value => $label)
                                    <option value="{{ $value }}" @selected($groupBy === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="form-group col-sm-12 col-md-4 col-lg-3 mb-0">
                        <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1 d-none d-lg-block" style="visibility:hidden;">&nbsp;</label>
                        <button type="submit" class="command-os-btn-filter w-100" style="height: 42px;">
                            <i class="fas fa-search mr-1" aria-hidden="true"></i> SHOW
                        </button>
                    </div>
                @else
                    <div class="form-group col-sm-12 col-md-6 col-lg-4 mb-2 mb-md-0">
                        <label for="analytics-dates" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            <i class="far fa-calendar-alt mr-1 text-purple"></i> Date Range:
                        </label>
                        <div class="command-input-wrap">
                            <input id="analytics-dates" type="text" class="form-control" value="{{ $from }} - {{ $to }}" readonly aria-label="Choose report date range" style="height: 42px;">
                            <input name="from" type="hidden" value="{{ $from }}" required>
                            <input name="to" type="hidden" value="{{ $to }}" required>
                        </div>
                    </div>
                    <div class="form-group col-sm-12 col-md-4 col-lg-2 mb-0">
                        <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1 d-none d-md-block" style="visibility:hidden;">&nbsp;</label>
                        <button type="submit" class="command-os-btn-filter w-100" style="height: 42px;">
                            <i class="fas fa-search mr-1" aria-hidden="true"></i> SHOW
                        </button>
                    </div>
                @endif
            </form>
        </section>

        <!-- 4. Info Banner -->
        @if (!empty($coverage))
            <div class="command-os-info-banner py-2 px-3 font-size-xs" role="note">
                <div class="command-os-info-banner-icon mr-2"><i class="fas fa-info-circle"></i></div>
                <div class="command-os-info-banner-content text-muted"><strong>Data coverage:</strong> {{ $coverage }}</div>
            </div>
        @endif

        <!-- 5. Report Card -->
        <div class="command-os-table-card">
            @if ($kind === 'all-stats')
                <section id="all-stats-pivot-shell" class="analytics-pivot-shell command-os-all-stats-pivot mb-3" data-url="{{ route($reportRoute, $workspace->id) }}" aria-label="All Stats pivot report" aria-busy="false" hidden>
                    <div id="all-stats-pivot-status" class="small text-muted mb-2" role="status" aria-live="polite"></div>
                    <div id="all-stats-pivot" class="analytics-pivot-surface"></div>
                </section>

                <div id="all-stats-fallback">
                    @if ($rows->isEmpty())
                        <div class="command-table-empty-state py-5 text-center" role="status">
                            <div class="command-empty-state-icon mb-3" style="font-size: 2.2rem; color: #8b5cf6; opacity: 0.85;">
                                <i class="fas fa-inbox"></i>
                            </div>
                            <h4 class="font-w600 text-ink mb-1">No Matching Activity Found</h4>
                            <p class="text-muted font-size-sm mb-0">No matching campaign events, Drop visitor events, or attributed campaign leads are recorded for this date range.</p>
                        </div>
                    @else
                        <div class="table-responsive all-stats-table-wrap">
                            <table class="table table-sm table-hover table-striped w-100 all-stats-table">
                                <thead>
                                    <tr>
                                        @foreach ($headers as $header)
                                            <th scope="col">{{ $header }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        @php($rowType = strtolower($row['type']))
                                        <tr>
                                            <td>{{ $row['at'] }}</td>
                                            <td><span class="badge badge-light border text-purple font-w700">{{ $row['source'] }}</span></td>
                                            <td class="font-w600">{{ $row['mailer'] }}</td>
                                            <td>{{ $row['isp'] }}</td>
                                            <td>{{ $row['list'] }}</td>
                                            <td><span class="badge badge-light border font-w600">{{ $row['list_type'] }}</span></td>
                                            <td>{{ $row['provider'] }}</td>
                                            <td>{{ $row['server'] }}</td>
                                            <td><code class="text-purple">{{ $row['ip'] }}</code></td>
                                            <td>{{ $row['domain'] }}</td>
                                            <td>{{ $row['sponsor'] }}</td>
                                            <td>{{ $row['offer'] }}</td>
                                            <td>{{ $row['vertical'] }}</td>
                                            <td>{{ $row['from'] }}</td>
                                            <td>{{ $row['subject'] }}</td>
                                            <td title="{{ $row['creative'] }}">{{ \Illuminate\Support\Str::limit(strip_tags($row['creative']), 80) }}</td>
                                            <td>
                                                <span class="badge badge-secondary px-2 py-1 font-w700 font-size-xs text-uppercase">{{ strtoupper($row['type']) }}</span>
                                            </td>
                                            <td class="text-right font-w600" style="font-family: var(--font-mono, monospace);">{{ number_format((int) $row['event_count']) }}</td>
                                            <td class="text-right font-w600 text-success" style="font-family: var(--font-mono, monospace);">
                                                ${{ number_format((float) $row['lead_revenue'], 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 pt-2 border-top all-stats-pagination" aria-label="Report pagination">
                            <small class="text-muted">Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }} rows</small>
                            {{ $rows->links() }}
                        </div>
                    @endif
                </div>
            @elseif ($kind === 'mailer-revenue')
                <section id="mailer-revenue-pivot-shell" class="analytics-pivot-shell command-os-all-stats-pivot mb-3" data-url="{{ route($reportRoute, $workspace->id) }}" aria-label="Mailer Revenue pivot report" aria-busy="false" hidden>
                    <div id="mailer-revenue-pivot-status" class="small text-muted mb-2" role="status" aria-live="polite"></div>
                    <div id="mailer-revenue-pivot" class="analytics-pivot-surface"></div>
                </section>
                <div id="mailer-revenue-fallback">
                    <div class="small text-muted mb-2" data-analytics-report-status role="status" aria-live="polite"></div>
                    @include('console.analytics.partials.report-results')
                </div>
            @else
                <div class="small text-muted mb-2" data-analytics-report-status role="status" aria-live="polite"></div>
                @include('console.analytics.partials.report-results')
            @endif
        </div>
    </div>
@endsection

@if (in_array($kind, ['all-stats', 'mailer-revenue', 'isp-stats'], true))
    @push('styles')
        @if (in_array($kind, ['all-stats', 'mailer-revenue'], true))
            <link rel="stylesheet" href="https://cdn3.devexpress.com/jslib/21.2.5/css/dx.common.css">
            <link rel="stylesheet" href="https://cdn3.devexpress.com/jslib/21.2.5/css/dx.material.blue.light.css">
        @endif
    @endpush
@endif

@push('scripts')
    <script src="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.js') }}?v=3.1.0"></script>
    <script src="{{ asset('console-assets/analytics-date-range.js') }}?v={{ substr(sha1_file(public_path('console-assets/analytics-date-range.js')), 0, 12) }}" defer></script>
    @if ($kind !== 'all-stats')
        <script src="{{ asset('console-assets/analytics-report-refresh.js') }}?v={{ substr(sha1_file(public_path('console-assets/analytics-report-refresh.js')), 0, 12) }}" defer></script>
    @endif
    @if (in_array($kind, ['all-stats', 'mailer-revenue'], true))
        <script src="https://cdn3.devexpress.com/jslib/21.2.5/js/dx.all.js"></script>
        <script type="module" src="{{ asset('console-assets/analytics-pivot.js') }}?v={{ substr(sha1_file(public_path('console-assets/analytics-pivot.js')), 0, 12) }}"></script>
    @elseif ($kind === 'isp-stats')
        <script type="module" src="{{ asset('console-assets/isp-stats-charts.js') }}?v={{ substr(sha1_file(public_path('console-assets/isp-stats-charts.js')), 0, 12) }}"></script>
    @endif
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.css') }}">
@endpush
