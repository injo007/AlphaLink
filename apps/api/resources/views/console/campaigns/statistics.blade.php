@extends('layouts.dms-admin')

@section('title', 'Campaign Statistics')

@section('content')
    <h2 class="content-heading">CAMPAIGN STATISTICS <small class="text-muted">ENGAGEMENT &amp; LEADS</small></h2>

    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">STATS</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" id="campaign-statistics-pivot-reload" aria-label="Reload campaign statistics" title="Reload"><i class="fas fa-sync" aria-hidden="true"></i></button>
                <a href="{{ route('console.campaign-statistics.index', $workspace->id) }}" class="btn btn-sm btn-alt-secondary" data-clear-analytics-date-range="c_stats">RESET</a>
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle campaign statistics full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <form method="get" action="{{ route('console.campaign-statistics.index', $workspace->id) }}" class="row align-items-end mb-3" data-analytics-date-range-form data-storage-key="c_stats" data-min-date="none" data-analytics-report-refresh novalidate>
                <div class="form-group col-sm-6 col-lg-3">
                    <label for="analytics-dates">Date range</label>
                    <input id="analytics-dates" class="form-control" type="text" value="{{ $from }} - {{ $to }}" readonly aria-label="Choose campaign statistics date range">
                    <input type="hidden" name="from" value="{{ $from }}" required>
                    <input type="hidden" name="to" value="{{ $to }}" required>
                </div>
                <div class="form-group col-sm-6 col-lg-3">
                    <label for="campaign-stats-campaign">Campaign</label>
                    <select id="campaign-stats-campaign" class="form-control" name="campaign_id">
                        <option value="">ALL CAMPAIGNS</option>
                        @foreach ($campaigns as $campaign)
                            <option value="{{ $campaign->id }}" @selected($selectedCampaignId === (string) $campaign->id)>{{ $campaign->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-sm-6 col-lg-2">
                    <label for="campaign-stats-isp">ISP</label>
                    <select id="campaign-stats-isp" class="form-control" name="isp_id">
                        <option value="">ALL ISPS</option>
                        @foreach ($isps as $isp)
                            <option value="{{ $isp->id }}" @selected($selectedIspId === (string) $isp->id)>{{ $isp->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-sm-6 col-lg-2">
                    <label for="campaign-stats-list">List</label>
                    <select id="campaign-stats-list" class="form-control" name="list_id">
                        <option value="">ALL LISTS</option>
                        @foreach ($lists as $list)
                            <option value="{{ $list->id }}" @selected($selectedListId === (string) $list->id)>{{ $list->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-sm-6 col-lg-1">
                    <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-search mr-1" aria-hidden="true"></i> SHOW</button>
                </div>
            </form>

            <section id="campaign-statistics-pivot-shell" class="analytics-pivot-shell mb-3" data-url="{{ route('console.campaign-statistics.index', $workspace->id) }}" aria-label="Campaign Statistics pivot report" aria-busy="false" hidden>
                <div id="campaign-statistics-pivot-status" class="small text-muted mb-2" role="status" aria-live="polite"></div>
                <div id="campaign-statistics-pivot" class="analytics-pivot-surface"></div>
            </section>
            <div class="alert alert-warning mb-3" role="note"><strong>Data coverage:</strong> stored campaign opens, clicks, bot events, unsubscribes, actual lead-row counts and attributed lead revenue are available. Delivery, bounce, opt-out and seed measures are not available; no value is inferred for those measures. Server, IP, domain, sponsor, offer and creative dimensions appear only when they were captured with an event.</div>
            <div id="campaign-statistics-fallback">
            <div class="small text-muted mb-2" data-analytics-report-status role="status" aria-live="polite"></div>
            @include('console.campaigns.partials.statistics-results')
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dms-reference/vendor/daterangepicker/daterangepicker.js') }}?v=3.1.0"></script>
    <script src="{{ asset('dms-reference/analytics-date-range.js') }}?v={{ substr(sha1_file(public_path('dms-reference/analytics-date-range.js')), 0, 12) }}" defer></script>
    <script src="{{ asset('dms-reference/analytics-report-refresh.js') }}?v={{ substr(sha1_file(public_path('dms-reference/analytics-report-refresh.js')), 0, 12) }}" defer></script>
    <script src="https://cdn3.devexpress.com/jslib/21.2.5/js/dx.all.js"></script>
    <script type="module" src="{{ asset('dms-reference/analytics-pivot.js') }}?v={{ substr(sha1_file(public_path('dms-reference/analytics-pivot.js')), 0, 12) }}"></script>
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('dms-reference/vendor/daterangepicker/daterangepicker.css') }}">
    <link rel="stylesheet" href="https://cdn3.devexpress.com/jslib/21.2.5/css/dx.common.css">
    <link rel="stylesheet" href="https://cdn3.devexpress.com/jslib/21.2.5/css/dx.material.blue.light.css">
    <style>
        .analytics-pivot-shell .dx-pivotgrid .dx-pivotgrid-area td { color: #000; padding: 10px 16px; }
        .analytics-pivot-shell .dx-pivotgrid .dx-grandtotal { background-color: #fff; }
        @media (max-width: 767px) { .analytics-pivot-shell .dx-pivotgrid .dx-pivotgrid-area td { padding: 7px 9px; } }
    </style>
@endpush
