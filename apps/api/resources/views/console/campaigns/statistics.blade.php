@extends('layouts.admin-shell')

@section('title', 'Campaign Statistics')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-hero-card">
            <div class="command-os-hero-card-left">
                <div class="command-os-hero-mark" aria-hidden="true">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="command-os-hero-copy">
                    <span class="command-os-hero-eyebrow">TRACKING &amp; ANALYTICS</span>
                    <h1 class="command-os-hero-title">CAMPAIGN STATISTICS</h1>
                    <p class="command-os-hero-subtitle">Interactive pivot intelligence, deliverability metrics, and attributed revenue across campaigns</p>
                </div>
            </div>
            <div class="command-os-hero-card-right d-flex align-items-center">
                <button type="button" class="command-os-btn-option mr-1" id="campaign-statistics-pivot-reload" aria-label="Reload campaign statistics" title="Reload"><i class="fas fa-sync" aria-hidden="true"></i></button>
                <a href="{{ route('console.campaign-statistics.index', $workspace->id) }}" class="btn btn-sm btn-outline-secondary font-w700 font-size-xs px-3 mr-2" data-clear-analytics-date-range="c_stats" style="border-radius: 8px;">RESET</a>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"><i class="si si-size-fullscreen"></i></button>
            </div>
        </header>

        <!-- 2. Filter Bar Card -->
        <section class="command-os-filter-card" aria-label="Filter campaign statistics">
            <form method="get" action="{{ route('console.campaign-statistics.index', $workspace->id) }}" class="form-row align-items-end" data-analytics-date-range-form data-storage-key="c_stats" data-min-date="none" data-analytics-report-refresh novalidate>
                <div class="form-group col-sm-6 col-lg-3 mb-2 mb-lg-0">
                    <label for="analytics-dates" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                        <i class="far fa-calendar-alt mr-1 text-purple"></i> Date Range:
                    </label>
                    <div class="command-input-wrap">
                        <input id="analytics-dates" class="form-control" type="text" value="{{ $from }} - {{ $to }}" readonly aria-label="Choose campaign statistics date range" style="height: 42px;">
                        <input type="hidden" name="from" value="{{ $from }}" required>
                        <input type="hidden" name="to" value="{{ $to }}" required>
                    </div>
                </div>
                <div class="form-group col-sm-6 col-lg-3 mb-2 mb-lg-0">
                    <label for="campaign-stats-campaign" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                        <i class="fas fa-envelope mr-1 text-purple"></i> Campaign:
                    </label>
                    <div class="command-input-wrap">
                        <select id="campaign-stats-campaign" class="form-control" name="campaign_id" style="height: 42px;">
                            <option value="">ALL CAMPAIGNS</option>
                            @foreach ($campaigns as $campaign)
                                <option value="{{ $campaign->id }}" @selected($selectedCampaignId === (string) $campaign->id)>{{ $campaign->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-group col-sm-6 col-lg-2 mb-2 mb-lg-0">
                    <label for="campaign-stats-isp" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                        <i class="fas fa-server mr-1 text-purple"></i> ISP:
                    </label>
                    <div class="command-input-wrap">
                        <select id="campaign-stats-isp" class="form-control" name="isp_id" style="height: 42px;">
                            <option value="">ALL ISPS</option>
                            @foreach ($isps as $isp)
                                <option value="{{ $isp->id }}" @selected($selectedIspId === (string) $isp->id)>{{ $isp->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-group col-sm-6 col-lg-2 mb-2 mb-lg-0">
                    <label for="campaign-stats-list" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                        <i class="fas fa-list mr-1 text-purple"></i> List:
                    </label>
                    <div class="command-input-wrap">
                        <select id="campaign-stats-list" class="form-control" name="list_id" style="height: 42px;">
                            <option value="">ALL LISTS</option>
                            @foreach ($lists as $list)
                                <option value="{{ $list->id }}" @selected($selectedListId === (string) $list->id)>{{ $list->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-group col-sm-12 col-lg-2 mb-0">
                    <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1 d-none d-lg-block" style="visibility:hidden;">&nbsp;</label>
                    <button type="submit" class="command-os-btn-filter w-100" style="height: 42px;">
                        <i class="fas fa-search mr-1" aria-hidden="true"></i> SHOW
                    </button>
                </div>
            </form>
        </section>

        <!-- 3. Info Banner -->
        <div class="command-os-info-banner py-2 px-3 font-size-xs" role="note">
            <div class="command-os-info-banner-icon"><i class="fas fa-info-circle"></i></div>
            <div class="command-os-info-banner-content text-muted">
                <strong>Data coverage:</strong> stored campaign opens, clicks, bot events, unsubscribes, actual lead-row counts and attributed lead revenue are available. Open, click and unsubscribe events are attributed to each selected eligible source List; totals across Lists can exceed unique events. Imported PMTA delivery and soft/hard bounce totals appear when feedback resolves to a campaign and its dimensions. CONVERSIONS follows the one-per-daily-stat-row positive-earnings rule. Server, IP, domain, sponsor, offer and creative dimensions appear only when captured with an event.
            </div>
        </div>

        <!-- 4. Report Card -->
        <div class="command-os-table-card">
            <section id="campaign-statistics-pivot-shell" class="analytics-pivot-shell command-os-all-stats-pivot mb-3" data-url="{{ route('console.campaign-statistics.index', $workspace->id) }}" aria-label="Campaign Statistics pivot report" aria-busy="false" hidden>
                <div id="campaign-statistics-pivot-status" class="small text-muted mb-2" role="status" aria-live="polite"></div>
                <div id="campaign-statistics-pivot" class="analytics-pivot-surface"></div>
            </section>
            <div id="campaign-statistics-fallback">
                <div class="small text-muted mb-2" data-analytics-report-status role="status" aria-live="polite"></div>
                @include('console.campaigns.partials.statistics-results')
            </div>
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
@endpush
