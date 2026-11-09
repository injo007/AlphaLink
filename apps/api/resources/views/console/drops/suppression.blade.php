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
    <header class="command-os-report-hero" aria-labelledby="drops-suppression-hero-title">
        <span class="command-os-report-hero-illustration" aria-hidden="true">
            <i class="fas fa-shield-alt"></i>
        </span>
        <div class="command-os-report-hero-mark" aria-hidden="true">
            <i class="fas fa-shield-alt"></i>
        </div>
        <div class="command-os-report-hero-copy">
            <p class="command-os-report-eyebrow">SUPPRESSION ENGINE // DEDUPLICATION &amp; EXCLUSIONS</p>
            <h1 id="drops-suppression-hero-title">DROP SUPPRESSION</h1>
            <p>Execute MD5/plain opt-out suppression runs, verify exclusion catalogs, and monitor live worker deduplication pipelines.</p>
        </div>
        <div class="command-os-hero-actions">
            <div class="command-os-report-hero-range">
                <span>CLUSTER STATUS</span>
                <strong><i class="fas fa-circle text-success" style="font-size: 8px;"></i> ENGINE READY</strong>
            </div>
            <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                <i class="fas fa-expand"></i>
            </button>
        </div>
    </header>

    <!-- 2. KPI Overview Cards -->
    <section class="command-os-all-stats-overview" aria-label="Suppression Overview">
        <article class="all-stats-overview-card">
            <span class="all-stats-overview-icon icon-amber" aria-hidden="true"><i class="fas fa-shield-alt"></i></span>
            <span class="all-stats-overview-copy">
                <span>Suppression Runs</span>
                <strong>MONITORED</strong>
                <small>Active job scheduler</small>
            </span>
        </article>
        <article class="all-stats-overview-card">
            <span class="all-stats-overview-icon icon-rose" aria-hidden="true"><i class="fas fa-user-slash"></i></span>
            <span class="all-stats-overview-copy">
                <span>Dedupe Scope</span>
                <strong>MD5 &amp; PLAIN</strong>
                <small>Dual hashing lookup</small>
            </span>
        </article>
        <article class="all-stats-overview-card">
            <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-database"></i></span>
            <span class="all-stats-overview-copy">
                <span>Storage Health</span>
                <strong>CLEAN</strong>
                <small>Zero stale cache</small>
            </span>
        </article>
        <article class="all-stats-overview-card">
            <span class="all-stats-overview-icon icon-emerald" aria-hidden="true"><i class="fas fa-bolt"></i></span>
            <span class="all-stats-overview-copy">
                <span>Worker Engine</span>
                <strong>SUB-SECOND</strong>
                <small>Fast stream filter</small>
            </span>
        </article>
        <article class="all-stats-overview-card">
            <span class="all-stats-overview-icon icon-cyan" aria-hidden="true"><i class="fas fa-check-double"></i></span>
            <span class="all-stats-overview-copy">
                <span>Integrity</span>
                <strong>VERIFIED</strong>
                <small>Production grade</small>
            </span>
        </article>
    </section>

    <!-- 3. Filter Bar Card -->
    <section class="command-os-filter-card" aria-label="Filter suppression">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h4 class="font-size-sm font-w700 text-uppercase tracking-wider mb-0 text-muted">
                <i class="fas fa-play-circle mr-2 text-purple"></i> Execute Suppression Run
            </h4>
            <span class="badge badge-primary px-2 py-1"><i class="fas fa-cogs mr-1"></i> Interactive Pipeline</span>
        </div>
        <form id="drops-suppression-form" class="form-row align-items-end" novalidate>
            <div class="form-group col-12 col-md-3">
                <label for="drops-suppression-sponsor"><i class="fas fa-building text-purple mr-1"></i> Sponsors:</label>
                <div class="command-input-wrap">
                    <span class="command-input-leading-icon"><i class="fas fa-building"></i></span>
                    <select id="drops-suppression-sponsor" class="form-control selectpicker command-input-with-icon" data-live-search="true" data-allow-clear="true" title="Select Sponsor">
                        <option value="">Select Sponsor</option>
                        @foreach ($sponsors as $sponsor)
                            <option value="{{ $sponsor->id }}" @selected((string) $initialSponsorId === (string) $sponsor->id)>{{ $sponsor->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-group col-12 col-md-3">
                <label for="drops-suppression-offer"><i class="fas fa-tag text-purple mr-1"></i> Offers:</label>
                <div class="command-input-wrap">
                    <span class="command-input-leading-icon"><i class="fas fa-tag"></i></span>
                    <select id="drops-suppression-offer" class="form-control selectpicker command-input-with-icon" data-live-search="true" data-size="6" title="Select Offer" disabled>
                        <option value="">Select Offer</option>
                    </select>
                </div>
            </div>
            <div class="form-group col-12 col-md-3">
                <label for="drops-suppression-isps"><i class="fas fa-network-wired text-purple mr-1"></i> ISPs:</label>
                <div class="command-input-wrap">
                    <span class="command-input-leading-icon"><i class="fas fa-network-wired"></i></span>
                    <select id="drops-suppression-isps" class="form-control selectpicker command-input-with-icon" data-live-search="true" data-actions-box="true" data-size="6" title="Select ISPs" multiple disabled></select>
                </div>
            </div>
            <div class="form-group col-12 col-md-3">
                <label for="drops-suppression-lists"><i class="fas fa-list text-purple mr-1"></i> Lists:</label>
                <div class="command-input-wrap">
                    <span class="command-input-leading-icon"><i class="fas fa-list"></i></span>
                    <select id="drops-suppression-lists" class="form-control selectpicker command-input-with-icon" data-live-search="true" data-actions-box="true" data-size="6" title="Select Lists" multiple disabled></select>
                </div>
            </div>
            <div class="form-group col-12 text-right mt-3 mb-0">
                <button id="drops-suppression-start" type="submit" class="command-os-btn-filter d-inline-block" style="width: auto; padding: 0 28px;">
                    <i class="fas fa-play mr-2"></i>START SUPPRESSION
                </button>
            </div>
        </form>
    </section>

    <!-- 4. Info Banner -->
    <div class="command-os-info-banner d-flex flex-wrap align-items-center justify-content-between" role="status" aria-live="polite">
        <div class="d-flex align-items-center gap-2">
            <div class="command-os-info-banner-icon mr-2"><i class="fas fa-info-circle"></i></div>
            <span id="offer-suppression-file-status">Select an offer to check its saved suppression file.</span>
        </div>
        @if ($canManageSuppressionFiles)
            <a id="offer-suppression-file-manage" class="btn btn-sm btn-outline-info ml-md-3 mt-2 mt-md-0" href="#" hidden>
                <i class="fas fa-external-link-alt mr-1"></i> OPEN OFFER FILE
            </a>
        @endif
    </div>

    <!-- 5. Table Card -->
    <div class="command-os-table-card">
        <div id="drops-suppression-message" class="alert alert-warning py-2 mb-3" role="status" aria-live="polite" hidden></div>
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
            <div class="d-flex align-items-center">
                <i class="fas fa-history text-purple font-size-lg mr-2"></i>
                <h3 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">Suppression Runs &amp; Live Workers</h3>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="form-inline">
                    <label class="mr-2 text-muted font-size-xs" for="drops-suppression-from"><i class="far fa-calendar-alt mr-1 text-purple"></i> Date:</label>
                    <input class="form-control form-control-sm mr-2" id="drops-suppression-from" type="date" value="{{ $defaultFrom }}" max="{{ $today }}">
                    <span class="mr-2 text-muted">to</span>
                    <input class="form-control form-control-sm" id="drops-suppression-to" type="date" value="{{ $today }}" max="{{ $today }}">
                </div>
                <button id="drops-suppression-refresh" type="button" class="command-os-btn-option ml-2" title="Refresh">
                    <i class="fas fa-sync-alt" aria-hidden="true"></i>
                    <span class="sr-only">Refresh</span>
                </button>
            </div>
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
