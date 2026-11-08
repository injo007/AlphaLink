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
    <div class="{{ $kind === 'all-stats' ? 'command-os-all-stats-report' : ($kind === 'mailer-revenue' ? 'command-os-mailer-revenue-report' : '') }}">
    @if ($kind === 'all-stats')
        <header class="command-os-report-hero" aria-labelledby="all-stats-page-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true"><i class="fas fa-chart-line"></i></span>
            <div class="command-os-report-hero-mark" aria-hidden="true"><i class="fas fa-chart-line"></i></div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">TRACKING &amp; ANALYTICS</p>
                <h1 id="all-stats-page-title">{{ $title }}</h1>
                <p>Explore campaign and Drop activity across the dimensions available to your workspace.</p>
            </div>
            <div class="command-os-report-hero-range">
                <span>REPORTING WINDOW</span>
                <strong id="all-stats-reporting-window" aria-live="polite">
                    <span data-report-window-start>{{ $from }}</span>
                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    <span data-report-window-end>{{ $to }}</span>
                </strong>
            </div>
        </header>

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
        <header class="command-os-report-hero" aria-labelledby="isp-stats-page-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true"><i class="fas fa-network-wired"></i></span>
            <div class="command-os-report-hero-mark" aria-hidden="true"><i class="fas fa-network-wired"></i></div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">TRACKING &amp; ANALYTICS</p>
                <h1 id="isp-stats-page-title">{{ $title }}</h1>
                <p>Deliverability and engagement breakdown by ISP and reporting period.</p>
            </div>
            <div class="command-os-report-hero-range">
                <span>REPORTING WINDOW</span>
                <strong id="isp-stats-reporting-window" aria-live="polite">
                    <span data-report-window-start>{{ $from }}</span>
                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    <span data-report-window-end>{{ $to }}</span>
                </strong>
            </div>
        </header>

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
        <header class="command-os-report-hero" aria-labelledby="mailer-revenue-page-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true"><i class="fas fa-funnel-dollar"></i></span>
            <div class="command-os-report-hero-mark" aria-hidden="true"><i class="fas fa-funnel-dollar"></i></div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">TRACKING &amp; ANALYTICS</p>
                <h1 id="mailer-revenue-page-title">{{ $title }}</h1>
                <p>Operator performance, volume deliveries, and attributed lead earnings.</p>
            </div>
            <div class="command-os-report-hero-range">
                <span>REPORTING WINDOW</span>
                <strong id="mailer-revenue-reporting-window" aria-live="polite">
                    <span data-report-window-start>{{ $from }}</span>
                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    <span data-report-window-end>{{ $to }}</span>
                </strong>
            </div>
        </header>

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
    @else
        <h2 class="content-heading">{{ $title }} <small class="text-muted">CAMPAIGN &amp; DROP EVENTS</small></h2>
    @endif

    <div class="block block-rounded command-os-all-stats-card">
        <div class="block-header block-header-default">
            <h3 class="block-title">{{ $title }} REPORT</h3>
            <div class="block-options">
                @if (in_array($kind, ['all-stats', 'mailer-revenue'], true))
                    <button type="button" class="btn-block-option" id="{{ $kind === 'all-stats' ? 'all-stats-pivot-reload' : 'mailer-revenue-pivot-reload' }}" aria-label="Reload {{ $title }}" title="Reload"><i class="fas fa-sync" aria-hidden="true"></i></button>
                    @if ($kind === 'mailer-revenue')
                        <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen" title="Full screen"></button>
                    @endif
                @endif
                @if ($kind === 'all-stats')
                    <button type="button" class="btn-block-option" id="all-stats-pivot-fullscreen" aria-label="Enter full screen" aria-pressed="false" title="Full screen"><i class="fas fa-expand-alt" aria-hidden="true"></i></button>
                @endif
                @if ($kind === 'isp-stats')
                    <button type="button" class="btn btn-sm btn-alt-secondary" data-analytics-report-reload aria-label="Refresh ISP Stats" title="Refresh"><i class="fas fa-sync mr-1" aria-hidden="true"></i>RUN</button>
                @endif
                <a href="{{ route($reportRoute, $workspace->id) }}" class="btn btn-sm btn-alt-secondary" data-clear-analytics-date-range="{{ $dateStorageKey }}">RESET</a>
            </div>
        </div>
        <div class="block-content block-content-full">
            <form method="get" action="{{ route($reportRoute, $workspace->id) }}" class="mb-3" data-analytics-date-range-form data-storage-key="{{ $dateStorageKey }}" @if ($kind !== 'all-stats') data-analytics-report-refresh @endif novalidate>
                <div class="form-inline mb-2">
                    <label for="analytics-dates" class="mr-2">DATE</label>
                    <input id="analytics-dates" type="text" class="form-control form-control-sm mr-3" value="{{ $from }} - {{ $to }}" readonly aria-label="Choose report date range">
                    <input name="from" type="hidden" value="{{ $from }}" required>
                    <input name="to" type="hidden" value="{{ $to }}" required>
                @if ($kind === 'isp-stats')
                    <label for="analytics-isp" class="mr-2">ISP</label>
                    <select id="analytics-isp" name="isp_id" class="form-control form-control-sm mr-3">
                        <option value="">ALL ACTIVE ISPS</option>
                        @foreach ($isps as $isp)
                            <option value="{{ $isp->id }}" @selected((string) $selectedIspId === (string) $isp->id)>{{ $isp->name }}</option>
                        @endforeach
                    </select>
                    <label for="analytics-groupby" class="mr-2">GROUP BY</label>
                    <select id="analytics-groupby" name="groupby" class="form-control form-control-sm mr-3">
                        @foreach (['day' => 'DAY', 'week' => 'WEEK', 'month' => 'MONTH'] as $value => $label)
                            <option value="{{ $value }}" @selected($groupBy === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                @endif
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search mr-1" aria-hidden="true"></i> SHOW</button>
                </div>
            </form>

            @if ($kind === 'all-stats')
                <section id="all-stats-pivot-shell" class="analytics-pivot-shell command-os-all-stats-pivot mb-3" data-url="{{ route($reportRoute, $workspace->id) }}" aria-label="All Stats pivot report" aria-busy="false" hidden>
                    <div id="all-stats-pivot-status" class="small text-muted mb-2" role="status" aria-live="polite"></div>
                    <div id="all-stats-pivot" class="analytics-pivot-surface"></div>
                </section>
            @elseif ($kind === 'mailer-revenue')
                <section id="mailer-revenue-pivot-shell" class="analytics-pivot-shell mb-3" data-url="{{ route($reportRoute, $workspace->id) }}" aria-label="Mailer Revenue pivot report" aria-busy="false" hidden>
                    <div id="mailer-revenue-pivot-status" class="small text-muted mb-2" role="status" aria-live="polite"></div>
                    <div id="mailer-revenue-pivot" class="analytics-pivot-surface"></div>
                </section>
                <div class="alert alert-warning" role="note"><strong>Data coverage:</strong> {{ $coverage }}</div>
            @endif

            @if ($kind === 'all-stats')
                <div id="all-stats-fallback">
                    @if ($rows->isEmpty())
                        <div class="alert alert-info" role="status">No matching campaign events, Drop visitor events, or attributed campaign leads are recorded for this date range.</div>
                    @else
                        <div class="table-responsive all-stats-table-wrap">
                            <table class="table table-sm table-hover table-striped table-bordered all-stats-table">
                                <thead><tr>@foreach ($headers as $header)<th scope="col">{{ $header }}</th>@endforeach</tr></thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        @php($rowType = strtolower($row['type']))
                                        <tr>
                                            <td>{{ $row['at'] }}</td>
                                            <td><span class="badge badge-source">{{ $row['source'] }}</span></td>
                                            <td>{{ $row['mailer'] }}</td>
                                            <td>{{ $row['isp'] }}</td>
                                            <td>{{ $row['list'] }}</td>
                                            <td><span class="badge badge-light">{{ $row['list_type'] }}</span></td>
                                            <td>{{ $row['provider'] }}</td>
                                            <td>{{ $row['server'] }}</td>
                                            <td><code>{{ $row['ip'] }}</code></td>
                                            <td>{{ $row['domain'] }}</td>
                                            <td>{{ $row['sponsor'] }}</td>
                                            <td>{{ $row['offer'] }}</td>
                                            <td>{{ $row['vertical'] }}</td>
                                            <td>{{ $row['from'] }}</td>
                                            <td>{{ $row['subject'] }}</td>
                                            <td title="{{ $row['creative'] }}">{{ \Illuminate\Support\Str::limit(strip_tags($row['creative']), 80) }}</td>
                                            <td>
                                                <span class="badge badge-event badge-event-{{ str_replace('_', '-', $rowType) }}">{{ strtoupper($row['type']) }}</span>
                                            </td>
                                            <td class="text-right text-tabular">{{ number_format((int) $row['event_count']) }}</td>
                                            <td class="text-right text-tabular">
                                                @if ((float) $row['lead_revenue'] > 0)
                                                    <span class="text-revenue">${{ number_format((float) $row['lead_revenue'], 2) }}</span>
                                                @else
                                                    ${{ number_format((float) $row['lead_revenue'], 2) }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 all-stats-pagination" aria-label="Report pagination">
                            <small class="text-muted">Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }} rows</small>
                            {{ $rows->links() }}
                        </div>
                    @endif
                </div>
                <div class="alert alert-warning mb-0 mt-3" role="note"><strong>Data coverage:</strong> {{ $coverage }}</div>
            @elseif ($kind === 'mailer-revenue')
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
    </div>
@endsection

@if (in_array($kind, ['all-stats', 'mailer-revenue', 'isp-stats'], true))
    @push('styles')
        @if (in_array($kind, ['all-stats', 'mailer-revenue'], true))
            <link rel="stylesheet" href="https://cdn3.devexpress.com/jslib/21.2.5/css/dx.common.css">
            <link rel="stylesheet" href="https://cdn3.devexpress.com/jslib/21.2.5/css/dx.material.blue.light.css">
        @endif
        <style>
            .command-os-mailer-revenue-report .analytics-pivot-shell .dx-pivotgrid .dx-pivotgrid-area td { color: var(--co-ink); padding: 10px 16px; border-color: var(--co-border); }
            .command-os-mailer-revenue-report .analytics-pivot-shell .dx-pivotgrid .dx-grandtotal { background-color: color-mix(in srgb, var(--co-purple) 9%, var(--co-surface)); color: var(--co-ink); font-weight: 800; }
            html[data-command-theme='dark'] .command-os-mailer-revenue-report .analytics-pivot-shell .dx-pivotgrid .dx-pivotgrid-area td { color: var(--co-ink) !important; background: var(--co-surface) !important; border-color: var(--co-border) !important; }
            html[data-command-theme='dark'] .command-os-mailer-revenue-report .analytics-pivot-shell .dx-pivotgrid .dx-grandtotal { background-color: color-mix(in srgb, var(--co-purple) 26%, var(--co-surface)) !important; color: #fff !important; }
            .analytics-pivot-shell:fullscreen { background: var(--co-page, #f5f6fb); overflow: auto; padding: 1rem; }
            .analytics-pivot-shell:fullscreen .analytics-pivot-surface { min-height: calc(100vh - 6rem); }
            .analytics-pivot-shell.analytics-pivot-fullscreen { background: var(--co-page, #f5f6fb); inset: 0; overflow: auto; padding: 1rem; position: fixed; z-index: 1300; }
            body.analytics-pivot-no-scroll { overflow: hidden; }
            .isp-stats-visuals .block { background: var(--co-surface); border: 1px solid var(--co-border); border-radius: 14px; box-shadow: var(--co-shadow); overflow: hidden; }
            .isp-stats-visuals .block-header { background: linear-gradient(100deg, color-mix(in srgb, var(--co-purple) 8%, var(--co-surface)), var(--co-surface)); border-bottom: 1px solid var(--co-border); }
            .isp-stats-visuals .block-title { color: var(--co-ink); font-size: 12px; font-weight: 750; letter-spacing: .04em; }
            .isp-stats-chart { overflow: hidden; position: relative; width: 100%; }
            .isp-stats-chart.is-rendered { min-height: 19rem; }
            .isp-stats-chart svg { display: block; height: auto; max-height: 20rem; overflow: visible; width: 100%; }
            .isp-stats-chart-legend { display: flex; flex-wrap: wrap; gap: .5rem 1rem; margin-top: .75rem; }
            .isp-stats-chart-legend-item { align-items: center; display: inline-flex; font-size: .8rem; gap: .4rem; }
            .isp-stats-chart-swatch { border-radius: 50%; display: inline-block; height: .55rem; width: .55rem; }
            .isp-stats-visuals .table-responsive { max-width: 100%; }
            .isp-stats-visuals table th, .isp-stats-visuals table td { white-space: nowrap; }
            .isp-sort-trigger { background: transparent; border: 0; color: inherit; font: inherit; padding: 0; text-align: left; cursor: pointer; }
            .isp-sort-trigger:focus-visible { outline: 2px solid var(--co-purple); outline-offset: 2px; }
            .isp-sort-indicator { color: var(--co-muted); margin-left: .25rem; }
            @media (max-width: 767px) { .isp-stats-chart { min-height: 15rem; } }
            @media (max-width: 767px) { .analytics-pivot-shell .dx-pivotgrid .dx-pivotgrid-area td { padding: 7px 9px; } }
        </style>
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
