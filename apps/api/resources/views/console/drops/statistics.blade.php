@extends('layouts.admin-shell')

@section('title', 'Drop Statistics')

@section('content')
    <h2 class="content-heading">DROP STATISTICS <small class="text-muted">RECORDED ACTIVITY &amp; ATTRIBUTED LEAD REVENUE</small></h2>

    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">ACTIVITY REPORT</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" id="drop-statistics-pivot-reload" data-analytics-report-reload aria-label="Reload Drop Statistics" title="Reload"><i class="fas fa-sync" aria-hidden="true"></i></button>
                <a href="{{ route('console.drops.statistics', $workspace->id) }}" class="btn btn-sm btn-alt-secondary" data-clear-analytics-date-range="d_stats">RESET</a>
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle Drop Statistics full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <form method="get" action="{{ route('console.drops.statistics', $workspace->id) }}" class="row align-items-end mb-3" data-analytics-date-range-form data-storage-key="d_stats" data-min-date="none" data-analytics-report-refresh>
                <div class="form-group col-sm-6 col-lg-3">
                    <label for="analytics-dates">Date range</label>
                    <input id="analytics-dates" class="form-control" type="text" value="{{ $from }} - {{ $to }}" readonly aria-label="Choose Drop Statistics date range">
                    <input type="hidden" name="from" value="{{ $from }}" required>
                    <input type="hidden" name="to" value="{{ $to }}" required>
                </div>
                <div class="form-group col-sm-6 col-lg-2">
                    <label for="drop-stats-drop">Drop</label>
                    <select id="drop-stats-drop" class="form-control" name="drop_id">
                        <option value="">ALL DROPS</option>
                        @foreach ($drops as $drop)
                            <option value="{{ $drop['id'] }}" @selected($selectedDropId === (string) $drop['id'])>{{ $drop['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-sm-6 col-lg-2">
                    <label for="drop-stats-isp">ISP</label>
                    <select id="drop-stats-isp" class="form-control" name="isp_id">
                        <option value="">ALL ISPS</option>
                        @foreach ($isps as $isp)
                            <option value="{{ $isp->id }}" @selected($selectedIspId === (string) $isp->id)>{{ $isp->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-sm-6 col-lg-2">
                    <label for="drop-stats-list">List</label>
                    <select id="drop-stats-list" class="form-control" name="list_id">
                        <option value="">ALL LISTS</option>
                        @foreach ($lists as $list)
                            <option value="{{ $list->id }}" @selected($selectedListId === (string) $list->id)>{{ $list->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-sm-6 col-lg-2">
                    <label for="drop-stats-country">Geo</label>
                    <select id="drop-stats-country" class="form-control" name="country">
                        <option value="">ALL GEOS</option>
                        @foreach ($countries as $country)
                            <option value="{{ $country }}" @selected($selectedCountry === (string) $country)>{{ $country }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-sm-6 col-lg-1">
                    <label for="drop-stats-send-type">Type</label>
                    <select id="drop-stats-send-type" class="form-control" name="send_type">
                        <option value="">ALL</option>
                        <option value="send" @selected($selectedSendType === 'send')>SEND</option>
                        <option value="test" @selected($selectedSendType === 'test')>TEST</option>
                    </select>
                </div>
                <div class="form-group col-sm-6 col-lg-12 text-right">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search mr-1" aria-hidden="true"></i> SHOW</button>
                </div>
            </form>

            <div class="alert alert-info mb-3" role="note"><strong>Data coverage:</strong> totals count stored Drop event rows; repeated actions and mailbox scanners can add events for the same recipient. BOT totals include only events already classified as bots. Conversion rows count tracked leads with positive revenue. Delivery and bounce totals are not stored in this report source, so they are not inferred. This aggregate report does not return recipient identifiers or addresses.</div>

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
