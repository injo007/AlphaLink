@extends('layouts.admin-shell')

@section('title', 'Drop Suppression')

@section('content')
<div id="drops-suppression"
    class="command-os-entity-page"
    data-catalog-url="{{ route('console.drops.suppression.catalog', $workspace->id) }}"
    data-rows-url="{{ route('console.drops.suppression', $workspace->id) }}"
    data-start-url="{{ route('console.drops.suppression.start', $workspace->id) }}"
    data-offer-file-manage-url-template="{{ route('console.offers.suppression-file.show', [$workspace->id, '__OFFER_ID__']) }}"
    data-kill-url="{{ route('console.drops.suppression.kill', $workspace->id) }}"
    data-monitor-url-template="{{ route('console.drops.suppression.monitor', [$workspace->id, '__SUPPRESSION_ID__']) }}"
    data-can-manage-files="{{ $canManageSuppressionFiles ? '1' : '0' }}"
    data-initial-sponsor-id="{{ $initialSponsorId ?? '' }}"
    data-initial-offer-id="{{ $initialOfferId ?? '' }}"
    data-today="{{ $today }}"
    data-default-from="{{ $defaultFrom }}">
    <!-- 1. Hero Card -->
    <header class="command-os-hero-card">
        <div class="command-os-hero-card-left">
            <div class="command-os-hero-mark" aria-hidden="true">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div class="command-os-hero-copy">
                <h1 class="command-os-hero-title">DROP SUPPRESSION</h1>
            </div>
        </div>
        <div class="command-os-hero-card-right">
            <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                <i class="si si-size-fullscreen"></i>
            </button>
        </div>
    </header>

    <!-- 2. Filter Bar Card -->
    <section class="command-os-filter-card" aria-label="Filter suppression">
        <form id="drops-suppression-form" class="form-row align-items-end" novalidate>
            <div class="form-group col-12 col-md-3">
                <label for="drops-suppression-sponsor">Sponsors:</label>
                <select id="drops-suppression-sponsor" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select Sponsor">
                    <option value="">Select Sponsor</option>
                    @foreach ($sponsors as $sponsor)
                        <option value="{{ $sponsor->id }}" @selected((string) $initialSponsorId === (string) $sponsor->id)>{{ $sponsor->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group col-12 col-md-3">
                <label for="drops-suppression-offer">Offers:</label>
                <select id="drops-suppression-offer" class="form-control selectpicker" data-live-search="true" data-size="6" title="Select Offer" disabled>
                    <option value="">Select Offer</option>
                </select>
            </div>
            <div class="form-group col-12 col-md-3">
                <label for="drops-suppression-isps">ISPs:</label>
                <select id="drops-suppression-isps" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" title="Select ISPs" multiple disabled></select>
            </div>
            <div class="form-group col-12 col-md-3">
                <label for="drops-suppression-lists">Lists:</label>
                <select id="drops-suppression-lists" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" title="Select Lists" multiple disabled></select>
            </div>
            <div class="form-group col-12 text-right mt-2 mb-0">
                <button id="drops-suppression-start" type="submit" class="command-os-btn-filter d-inline-block" style="width: auto; padding: 0 24px;">START SUPPRESSION</button>
            </div>
        </form>
    </section>

    <!-- 3. Info Banner -->
    <div class="command-os-info-banner d-flex flex-wrap align-items-center justify-content-between" role="status" aria-live="polite">
        <div class="d-flex align-items-center gap-2">
            <div class="command-os-info-banner-icon mr-2"><i class="fas fa-info-circle"></i></div>
            <span id="offer-suppression-file-status">Select an offer to check its saved suppression file.</span>
        </div>
        @if ($canManageSuppressionFiles)
            <a id="offer-suppression-file-manage" class="btn btn-sm btn-outline-info ml-md-3 mt-2 mt-md-0" href="#" hidden>OPEN OFFER FILE</a>
        @endif
    </div>

    <!-- 4. Table Card -->
    <div class="command-os-table-card">
        <div id="drops-suppression-message" class="alert alert-warning py-2 mb-3" role="status" aria-live="polite" hidden></div>
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
            <div class="form-inline">
                <label class="mr-2" for="drops-suppression-from">Date:</label>
                <input class="form-control form-control-sm mr-2" id="drops-suppression-from" type="date" value="{{ $defaultFrom }}" max="{{ $today }}">
                <span class="mr-2 text-muted">to</span>
                <input class="form-control form-control-sm" id="drops-suppression-to" type="date" value="{{ $today }}" max="{{ $today }}">
            </div>
            <button id="drops-suppression-refresh" type="button" class="command-os-btn-option" title="Refresh"><i class="fa fa-sync" aria-hidden="true"></i><span class="sr-only">Refresh</span></button>
        </div>
        <div class="table-responsive">
            <table id="drops-suppression-table" class="table table-sm table-hover table-striped w-100">
                <caption class="sr-only">Suppression runs and their progress</caption>
            </table>
        </div>
    </div>
    <dialog id="drops-suppression-details" class="pmta-control-dialog" aria-labelledby="drops-suppression-details-title">
        <h3 id="drops-suppression-details-title" class="h5">Selected suppression scope</h3>
        <div id="drops-suppression-details-content" class="mb-3"></div>
        <form method="dialog" class="text-right"><button class="btn btn-secondary btn-sm">CLOSE</button></form>
    </dialog>
</div>
@endsection

@push('scripts')
<script src="{{ asset('console-assets/drops-suppression.js') }}?v={{ substr(sha1_file(public_path('console-assets/drops-suppression.js')), 0, 12) }}" defer></script>
@endpush
