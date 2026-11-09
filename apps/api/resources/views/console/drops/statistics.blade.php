@extends('layouts.admin-shell')

@section('title', 'Drop Statistics')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-hero-card">
            <div class="command-os-hero-card-left">
                <div class="command-os-hero-mark" aria-hidden="true">
                    <i class="fas fa-chart-bar"></i>
                </div>
                <div class="command-os-hero-copy">
                    <span class="command-os-hero-eyebrow">TRACKING &amp; ANALYTICS</span>
                    <h1 class="command-os-hero-title">DROP STATISTICS</h1>
                </div>
            </div>
            <div class="command-os-hero-card-right">
                <button type="button" class="command-os-btn-option" id="drop-statistics-pivot-reload" data-analytics-report-reload aria-label="Reload Drop Statistics" title="Reload"><i class="fas fa-sync" aria-hidden="true"></i></button>
                <a href="{{ route('console.drops.statistics', $workspace->id) }}" class="btn btn-sm btn-alt-secondary" data-clear-analytics-date-range="d_stats">RESET</a>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle Drop Statistics full screen"><i class="si si-size-fullscreen"></i></button>
            </div>
        </header>

        <!-- 2. Filter Bar Card -->
        <section class="command-os-filter-card" aria-label="Filter drop statistics">
            <form method="get" action="{{ route('console.drops.statistics', $workspace->id) }}" class="form-row align-items-end" data-analytics-date-range-form data-storage-key="d_stats" data-min-date="none" data-analytics-report-refresh>
                <div class="form-group col-sm-6 col-lg-3 mb-2">
                    <label for="analytics-dates">Date range:</label>
                    <input id="analytics-dates" class="form-control" type="text" value="{{ $from }} - {{ $to }}" readonly aria-label="Choose Drop Statistics date range">
                    <input type="hidden" name="from" value="{{ $from }}" required>
                    <input type="hidden" name="to" value="{{ $to }}" required>
                </div>
                <div class="form-group col-sm-6 col-lg-2 mb-2">
                    <label for="drop-stats-drop">Drop:</label>
                    <select id="drop-stats-drop" class="form-control" name="drop_id">
                        <option value="">ALL DROPS</option>
                        @foreach ($drops as $drop)
                            <option value="{{ $drop['id'] }}" @selected($selectedDropId === (string) $drop['id'])>{{ $drop['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-sm-6 col-lg-2 mb-2">
                    <label for="drop-stats-isp">ISP:</label>
                    <select id="drop-stats-isp" class="form-control" name="isp_id">
                        <option value="">ALL ISPS</option>
                        @foreach ($isps as $isp)
                            <option value="{{ $isp->id }}" @selected($selectedIspId === (string) $isp->id)>{{ $isp->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-sm-6 col-lg-2 mb-2">
                    <label for="drop-stats-list">List:</label>
                    <select id="drop-stats-list" class="form-control" name="list_id">
                        <option value="">ALL LISTS</option>
                        @foreach ($lists as $list)
                            <option value="{{ $list->id }}" @selected($selectedListId === (string) $list->id)>{{ $list->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-sm-6 col-lg-2 mb-2">
                    <label for="drop-stats-country">Geo:</label>
                    <select id="drop-stats-country" class="form-control" name="country">
                        <option value="">ALL GEOS</option>
                        @foreach ($countries as $country)
                            <option value="{{ $country }}" @selected($selectedCountry === (string) $country)>{{ $country }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-sm-6 col-lg-1 mb-2">
                    <label for="drop-stats-send-type">Type:</label>
                    <select id="drop-stats-send-type" class="form-control" name="send_type">
                        <option value="">ALL</option>
                        <option value="send" @selected($selectedSendType === 'send')>SEND</option>
                        <option value="test" @selected($selectedSendType === 'test')>TEST</option>
                    </select>
                </div>
                <div class="form-group col-12 text-right mt-2 mb-0">
                    <button type="submit" class="command-os-btn-filter d-inline-block" style="width: auto; padding: 0 24px;"><i class="fas fa-search mr-1" aria-hidden="true"></i> SHOW</button>
                </div>
            </form>
        </section>

        <!-- 3. Info Banner -->
        <div class="command-os-info-banner mb-3" role="note">
            <div class="command-os-info-banner-icon mr-2"><i class="fas fa-info-circle"></i></div>
            <div class="command-os-info-banner-content"><strong>Data coverage:</strong> totals count stored Drop event rows; repeated actions and mailbox scanners can add events for the same recipient. BOT totals include only events already classified as bots. Conversion rows count tracked leads with positive revenue. Delivery and bounce totals are not stored in this report source, so they are not inferred. This aggregate report does not return recipient identifiers or addresses.</div>
        </div>

        <!-- 4. Report Card -->
        <div class="command-os-table-card">
            <section id="drop-statistics-pivot-shell" class="analytics-pivot-shell mb-3" data-url="{{ route('console.drops.statistics', $workspace->id) }}" aria-label="Drop Statistics pivot report" aria-busy="false" hidden>
                <div id="drop-statistics-pivot-status" class="small text-muted mb-2" role="status" aria-live="polite"></div>
                <div id="drop-statistics-pivot" class="analytics-pivot-surface"></div>
            </section>
            <div class="small text-muted mb-2" data-analytics-report-status role="status" aria-live="polite"></div>
            @include('console.drops.partials.statistics-results')
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.js') }}?v=3.1.0"></script>
    <script src="{{ asset('console-assets/analytics-date-range.js') }}?v={{ substr(sha1_file(public_path('console-assets/analytics-date-range.js')), 0, 12) }}" defer></script>
    <script src="{{ asset('console-assets/analytics-report-refresh.js') }}?v={{ substr(sha1_file(public_path('console-assets/analytics-report-refresh.js')), 0, 12) }}" defer></script>
    <script src="https://cdn3.devexpress.com/jslib/21.2.5/js/dx.all.js"></script>
    <script type="module" src="{{ asset('console-assets/analytics-pivot.js') }}?v={{ substr(sha1_file(public_path('console-assets/analytics-pivot.js')), 0, 12) }}"></script>
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.css') }}">
    <link rel="stylesheet" href="https://cdn3.devexpress.com/jslib/21.2.5/css/dx.common.css">
    <link rel="stylesheet" href="https://cdn3.devexpress.com/jslib/21.2.5/css/dx.material.blue.light.css">
    <style>
        .analytics-pivot-shell .dx-pivotgrid .dx-pivotgrid-area td { color: #000; padding: 10px 16px; }
        .analytics-pivot-shell .dx-pivotgrid .dx-grandtotal { background-color: #fff; }
        @media (max-width: 767px) { .analytics-pivot-shell .dx-pivotgrid .dx-pivotgrid-area td { padding: 7px 9px; } }
    </style>
@endpush
