@extends('layouts.dms-admin')

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
    <h2 class="content-heading">{{ $title }} <small class="text-muted">CAMPAIGN &amp; DROP EVENTS</small></h2>

    <div class="block block-rounded">
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
                <section id="all-stats-pivot-shell" class="analytics-pivot-shell mb-3" data-url="{{ route($reportRoute, $workspace->id) }}" aria-label="All Stats pivot report" aria-busy="false" hidden>
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
                        <div class="table-responsive">
                            <table class="table table-sm table-hover table-striped table-bordered">
                                <thead><tr>@foreach ($headers as $header)<th scope="col">{{ $header }}</th>@endforeach</tr></thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        <tr>
                                            <td>{{ $row['at'] }}</td><td>{{ $row['source'] }}</td><td>{{ $row['mailer'] }}</td><td>{{ $row['isp'] }}</td><td>{{ $row['list'] }}</td><td>{{ $row['list_type'] }}</td><td>{{ $row['provider'] }}</td><td>{{ $row['server'] }}</td><td>{{ $row['ip'] }}</td><td>{{ $row['domain'] }}</td><td>{{ $row['sponsor'] }}</td><td>{{ $row['offer'] }}</td><td>{{ $row['vertical'] }}</td><td>{{ $row['from'] }}</td><td>{{ $row['subject'] }}</td><td title="{{ $row['creative'] }}">{{ \Illuminate\Support\Str::limit(strip_tags($row['creative']), 80) }}</td><td>{{ strtoupper($row['type']) }}</td><td class="text-right">{{ number_format((int) $row['event_count']) }}</td><td class="text-right">${{ number_format((float) $row['lead_revenue'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex flex-wrap justify-content-between align-items-center mt-2" aria-label="Report pagination">
                            <small class="text-muted">Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }} rows</small>
                            {{ $rows->links() }}
                        </div>
                    @endif
                </div>
                <div class="alert alert-warning mb-0" role="note"><strong>Data coverage:</strong> {{ $coverage }}</div>
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
@endsection

@if (in_array($kind, ['all-stats', 'mailer-revenue', 'isp-stats'], true))
    @push('styles')
        @if (in_array($kind, ['all-stats', 'mailer-revenue'], true))
            <link rel="stylesheet" href="https://cdn3.devexpress.com/jslib/21.2.5/css/dx.common.css">
            <link rel="stylesheet" href="https://cdn3.devexpress.com/jslib/21.2.5/css/dx.material.blue.light.css">
        @endif
        <style>
            .analytics-pivot-shell .dx-pivotgrid .dx-pivotgrid-area td { color: #000; padding: 10px 16px; }
            .analytics-pivot-shell .dx-pivotgrid .dx-grandtotal { background-color: #fff; }
            .analytics-pivot-shell:fullscreen { background: #fff; overflow: auto; padding: 1rem; }
            .analytics-pivot-shell:fullscreen .analytics-pivot-surface { min-height: calc(100vh - 6rem); }
            .analytics-pivot-shell.analytics-pivot-fullscreen { background: #fff; inset: 0; overflow: auto; padding: 1rem; position: fixed; z-index: 1300; }
            body.analytics-pivot-no-scroll { overflow: hidden; }
            .isp-stats-chart { overflow: hidden; position: relative; width: 100%; }
            .isp-stats-chart.is-rendered { min-height: 19rem; }
            .isp-stats-chart svg { display: block; height: auto; max-height: 20rem; overflow: visible; width: 100%; }
            .isp-stats-chart-legend { display: flex; flex-wrap: wrap; gap: .5rem 1rem; margin-top: .75rem; }
            .isp-stats-chart-legend-item { align-items: center; display: inline-flex; font-size: .8rem; gap: .4rem; }
            .isp-stats-chart-swatch { border-radius: 50%; display: inline-block; height: .55rem; width: .55rem; }
            .isp-stats-visuals .table-responsive { max-width: 100%; }
            .isp-stats-visuals table th, .isp-stats-visuals table td { white-space: nowrap; }
            .isp-sort-trigger { background: transparent; border: 0; color: inherit; font: inherit; padding: 0; text-align: left; }
            .isp-sort-trigger:focus-visible { outline: 2px solid #17685f; outline-offset: 2px; }
            .isp-sort-indicator { color: #687773; margin-left: .25rem; }
            @media (max-width: 767px) { .isp-stats-chart { min-height: 15rem; } }
            @media (max-width: 767px) { .analytics-pivot-shell .dx-pivotgrid .dx-pivotgrid-area td { padding: 7px 9px; } }
        </style>
    @endpush
@endif

@push('scripts')
    <script src="{{ asset('dms-reference/vendor/daterangepicker/daterangepicker.js') }}?v=3.1.0"></script>
    <script src="{{ asset('dms-reference/analytics-date-range.js') }}?v={{ substr(sha1_file(public_path('dms-reference/analytics-date-range.js')), 0, 12) }}" defer></script>
    @if ($kind !== 'all-stats')
        <script src="{{ asset('dms-reference/analytics-report-refresh.js') }}?v={{ substr(sha1_file(public_path('dms-reference/analytics-report-refresh.js')), 0, 12) }}" defer></script>
    @endif
    @if (in_array($kind, ['all-stats', 'mailer-revenue'], true))
        <script src="https://cdn3.devexpress.com/jslib/21.2.5/js/dx.all.js"></script>
        <script type="module" src="{{ asset('dms-reference/analytics-pivot.js') }}?v={{ substr(sha1_file(public_path('dms-reference/analytics-pivot.js')), 0, 12) }}"></script>
    @elseif ($kind === 'isp-stats')
        <script type="module" src="{{ asset('dms-reference/isp-stats-charts.js') }}?v={{ substr(sha1_file(public_path('dms-reference/isp-stats-charts.js')), 0, 12) }}"></script>
    @endif
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('dms-reference/vendor/daterangepicker/daterangepicker.css') }}">
@endpush
