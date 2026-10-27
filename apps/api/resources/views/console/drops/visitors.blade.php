@extends('layouts.dms-admin')

@section('title', 'Drop Visitors Logs')

@section('content')
<div id="drop-visitors"
    data-table-url="{{ route('console.drops.visitors', [$workspace->id, $drop->id]) }}"
    data-chart-url="{{ route('console.drops.visitors', [$workspace->id, $drop->id, 'chart' => 1]) }}"
    data-drop-id="{{ $drop->id }}">
    <h2 class="content-heading">DROP <span class="text-info">{{ $drop->id }}</span> VISITORS</h2>
    <section id="drop-visitors-map-block" class="block block-rounded block-mode-hidden mb-2">
        <div class="block-header block-header-default">
            <h3 class="block-title">MAP</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="content_toggle" aria-label="Toggle visitor map">
                    <i class="si si-arrow-up" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div id="drop-visitors-map" role="img" aria-label="Visitor activity by country" style="width: 100%; height: 500px;"></div>
            <p id="drop-visitors-map-message" class="text-muted mb-0" role="status" aria-live="polite" hidden></p>
        </div>
    </section>
    <section class="block block-rounded">
        <div class="block-content block-content-full">
            <div class="table-responsive">
                <table id="drop-visitors-table" class="table table-sm table-hover table-striped w-100">
                    <caption class="sr-only">Visitor events for drop {{ $drop->id }}</caption>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script src="https://www.gstatic.com/charts/loader.js"></script>
<script src="{{ asset('dms-reference/drops-visitors.js') }}?v={{ substr(sha1_file(public_path('dms-reference/drops-visitors.js')), 0, 12) }}"></script>
@endpush
