@extends('layouts.dms-admin')

@section('title', 'Drops Monitoring')

@section('content')
<div id="drops-monitoring"
    data-rows-url="{{ route('console.drops.monitoring', $workspace->id) }}"
    data-monitor-url-template="{{ route('console.drops.monitor', [$workspace->id, '__DROP_ID__']) }}"
    data-pmta-admin-url="{{ route('console.pmta-admin.index', $workspace->id) }}"
    data-stats-url-template="{{ route('console.drops.stats', [$workspace->id, '__DROP_ID__']) }}"
    data-visitors-url-template="{{ route('console.drops.visitors', [$workspace->id, '__DROP_ID__']) }}"
    data-today="{{ $today }}">
    <section class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Drops Monitoring</h3>
        </div>
        <div class="block-content block-content-full">
            <form id="drops-monitoring-filters" class="form-row align-items-end mb-3" novalidate>
                <div class="form-group col-12 col-sm-6 col-lg-2">
                    <label class="sr-only" for="drops-monitoring-type">Drop Type</label>
                    <select id="drops-monitoring-type" class="form-control selectpicker" data-live-search="true" data-size="6" title="Drop Type">
                        <option value="all" selected>ALL</option>
                        <option value="send">SEND</option>
                        <option value="test">TEST</option>
                    </select>
                </div>
                <div class="form-group col-12 col-sm-6 col-lg-2">
                    <button id="drops-monitoring-filter" type="submit" class="btn btn-primary btn-sm">FILTER</button>
                </div>
            </form>
            <div id="drops-monitoring-message" class="alert alert-warning py-2 mb-3" role="status" aria-live="polite" hidden></div>
            <div class="table-responsive">
                <table id="drops-monitoring-table" class="table table-sm table-hover table-striped w-100">
                    <caption class="sr-only">Processed drops and their send progress</caption>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('dms-reference/vendor/daterangepicker/daterangepicker.css') }}">
@endpush

@push('scripts')
<script src="{{ asset('dms-reference/vendor/daterangepicker/daterangepicker.js') }}?v=3.1.0"></script>
<script src="{{ asset('dms-reference/drops-monitor.js') }}?v={{ substr(sha1_file(public_path('dms-reference/drops-monitor.js')), 0, 12) }}"></script>
@endpush
