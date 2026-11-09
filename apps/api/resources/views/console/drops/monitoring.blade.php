@extends('layouts.admin-shell')

@section('title', 'Drops Monitoring')

@section('content')
<div id="drops-monitoring"
    class="command-os-entity-page"
    data-rows-url="{{ route('console.drops.monitoring', $workspace->id) }}"
    data-monitor-url-template="{{ route('console.drops.monitor', [$workspace->id, '__DROP_ID__']) }}"
    data-pmta-admin-url="{{ route('console.pmta-admin.index', $workspace->id) }}"
    data-stats-url-template="{{ route('console.drops.stats', [$workspace->id, '__DROP_ID__']) }}"
    data-visitors-url-template="{{ route('console.drops.visitors', [$workspace->id, '__DROP_ID__']) }}"
    data-today="{{ $today }}">
    <!-- 1. Hero Card -->
    <header class="command-os-hero-card">
        <div class="command-os-hero-card-left">
            <div class="command-os-hero-mark" aria-hidden="true">
                <i class="fas fa-desktop"></i>
            </div>
            <div class="command-os-hero-copy">
                <h1 class="command-os-hero-title">DROPS MONITORING</h1>
            </div>
        </div>
        <div class="command-os-hero-card-right">
            <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                <i class="si si-size-fullscreen"></i>
            </button>
        </div>
    </header>

    <!-- 2. Filter Bar Card -->
    <section class="command-os-filter-card" aria-label="Filter drops">
        <form id="drops-monitoring-filters" class="form-row align-items-end" novalidate>
            <div class="col-sm-6 col-lg-3">
                <label for="drops-monitoring-type">Drop Type:</label>
                <select id="drops-monitoring-type" class="form-control selectpicker" data-live-search="true" data-size="6" title="Drop Type">
                    <option value="all" selected>ALL</option>
                    <option value="send">SEND</option>
                    <option value="test">TEST</option>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <button id="drops-monitoring-filter" type="submit" class="command-os-btn-filter">FILTER</button>
            </div>
        </form>
    </section>

    <!-- 3. Table Card -->
    <div class="command-os-table-card">
        <div id="drops-monitoring-message" class="command-os-info-banner mb-3" role="status" aria-live="polite" hidden></div>
        <div class="table-responsive">
            <table id="drops-monitoring-table" class="table table-sm table-hover table-striped w-100">
                <caption class="sr-only">Processed drops and their send progress</caption>
            </table>
        </div>
    </div>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.css') }}">
@endpush

@push('scripts')
<script src="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.js') }}?v=3.1.0"></script>
<script src="{{ asset('console-assets/drops-monitor.js') }}?v={{ substr(sha1_file(public_path('console-assets/drops-monitor.js')), 0, 12) }}"></script>
@endpush
