@extends('layouts.admin-shell')

@section('title', 'Offer Suppression File')

@section('content')
    <div id="offer-suppression-file-manager"
         class="command-os-entity-page"
         data-refresh-url="{{ route('console.offers.suppression-file.download', [$workspace->id, $offer->id]) }}"
         data-refresh-status-url-template="{{ route('console.offers.suppression-file.download-status', [$workspace->id, $offer->id, '__DOWNLOAD_ID__']) }}"
         data-upload-url="{{ route('console.offers.suppression-file.upload', [$workspace->id, $offer->id]) }}">

        <!-- 1. Hero Card -->
        <header class="command-os-report-hero" aria-labelledby="offer-suppression-hero-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas fa-file-contract"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas fa-file-contract"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">OFFER SUPPRESSION // REPOSITORY MANAGEMENT</p>
                <h1 id="offer-suppression-hero-title">OFFER SUPPRESSION FILE</h1>
                <p>{{ $offer->name }} &middot; {{ $offer->sponsor?->name ?? 'Unknown sponsor' }} &middot; Offer ID {{ $offer->offer_id }} &middot; {{ $offer->vertical ?: 'No vertical' }}</p>
            </div>
            <div class="command-os-hero-actions">
                <a class="btn btn-sm btn-light" href="{{ route('clean.console.offers.index', ['sponsor' => $offer->sponsor_id]) }}">
                    <i class="fas fa-arrow-left mr-1"></i> BACK TO OFFERS
                </a>
                <a class="btn btn-sm btn-primary" href="{{ route('clean.console.drops.suppression', ['sponsor_id' => $offer->sponsor_id, 'offer_id' => $offer->id]) }}">
                    <i class="fas fa-play mr-1"></i> OPEN SUPPRESSION RUNS
                </a>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </header>

        <!-- 2. KPI Overview Cards -->
        <section class="command-os-all-stats-overview" aria-label="Suppression File Overview">
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon {{ $hasStoredFile ? 'icon-emerald' : 'icon-amber' }}" aria-hidden="true">
                    <i class="fas {{ $hasStoredFile ? 'fa-check-circle' : 'fa-exclamation-circle' }}"></i>
                </span>
                <span class="all-stats-overview-copy">
                    <span>File Status</span>
                    <strong>{{ $hasStoredFile ? 'SAVED' : 'NOT SAVED' }}</strong>
                    <small>{{ $hasStoredFile ? 'Active & verified' : 'Requires refresh' }}</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-cyan" aria-hidden="true"><i class="fas fa-hashtag"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Format</span>
                    <strong>{{ $hasStoredFile ? strtoupper((string) $offer->suppression_format) : 'NONE' }}</strong>
                    <small>Opt-out encoding</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-list-ol"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Entries</span>
                    <strong>{{ $savedFileEntryCount === null ? '0' : number_format((int) $savedFileEntryCount) }}</strong>
                    <small>Total exclusion records</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-blue" aria-hidden="true"><i class="fas fa-hdd"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Storage Size</span>
                    <strong>{{ $savedFileBytes === null ? '0 MB' : number_format($savedFileBytes / 1024 / 1024, 2).' MB' }}</strong>
                    <small>Private vault storage</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-amber" aria-hidden="true"><i class="fas fa-clock"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Last Updated</span>
                    <strong>{{ $hasStoredFile ? ($offer->suppression_updated_at?->toDateString() ?? 'Recent') : 'Never' }}</strong>
                    <small>Sync timestamp</small>
                </span>
            </article>
        </section>

        <!-- 3. Status Alert Banner -->
        <div class="command-os-info-banner mb-3" role="status" aria-live="polite">
            <div class="command-os-info-banner-icon mr-2">
                <i class="fas {{ $hasStoredFile ? 'fa-check' : 'fa-info-circle' }}"></i>
            </div>
            <div class="command-os-info-banner-content">
                @if ($hasStoredFile)
                    The verified suppression file is saved on this offer and available to suppression runs. The offer’s normal live, active, freshness, weekday, and access rules still apply when a run starts.
                @else
                    No verified suppression file is saved for this offer yet. Refresh it from the sponsor platform or upload a local file below.
                @endif
            </div>
        </div>

        <!-- 4. Action Card -->
        <div class="command-os-filter-card mb-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between">
                <div>
                    <h4 class="font-size-sm font-w700 text-uppercase tracking-wider mb-1 text-muted">
                        <i class="fas fa-sync-alt mr-2 text-purple"></i> File Synchronization Actions
                    </h4>
                    <p class="font-size-xs text-muted mb-0">Direct sponsor fetch or verified file download.</p>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2 mt-md-0">
                    @if ($hasStoredFile)
                        <a class="btn btn-sm btn-outline-success mr-2" href="{{ route('console.offers.suppression-file.saved', [$workspace->id, $offer->id]) }}">
                            <i class="fas fa-download mr-1"></i> DOWNLOAD SAVED FILE
                        </a>
                    @endif
                    <button id="offer-suppression-refresh" class="command-os-btn-filter d-inline-block" style="width: auto; padding: 0 24px;" type="button">
                        <i class="fas fa-cloud-download-alt mr-2"></i>REFRESH FROM SPONSOR
                    </button>
                </div>
            </div>

            <div id="offer-suppression-refresh-status" class="alert alert-info py-2 mt-3 mb-0" role="status" aria-live="polite" hidden></div>
            <div id="offer-suppression-refresh-progress-wrap" class="mt-3 mb-0" hidden>
                <div class="progress" style="height: 22px">
                    <div id="offer-suppression-refresh-progress" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
                </div>
                <small id="offer-suppression-refresh-size" class="form-text text-muted mt-2"></small>
            </div>
        </div>

        <!-- 5. Upload / Replace Card -->
        @if ($canManageSuppressionFiles)
            <div class="command-os-table-card">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-upload text-purple font-size-lg mr-2"></i>
                        <h3 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">Manual File Upload or Replacement</h3>
                    </div>
                    <span class="badge badge-light border text-muted px-2 py-1 font-size-xs">
                        <i class="fas fa-shield-alt mr-1 text-purple"></i> Checksum Verified
                    </span>
                </div>
                <p class="font-size-xs text-muted mb-3">TXT and CSV files containing email addresses, 32-character MD5 hashes, or 128-character SHA-512 hashes are normalized, verified, and stored privately.</p>
                <form id="offer-suppression-upload-form" class="form-row align-items-end" novalidate>
                    <div class="form-group col-12 col-md-8 mb-2">
                        <label for="offer-suppression-upload-input" class="font-size-xs font-w700 text-muted mb-1">Select Suppression File:</label>
                        <input id="offer-suppression-upload-input" type="file" class="form-control-file" accept=".txt,.csv,text/plain,text/csv" required aria-describedby="offer-suppression-upload-help">
                        <small id="offer-suppression-upload-help" class="form-text text-muted">Choose a TXT or CSV file. There is no application-level size or entry-count cap.</small>
                    </div>
                    <div class="form-group col-12 col-md-4 mb-2 text-right">
                        <button id="offer-suppression-upload-button" class="btn btn-warning px-4" type="submit" disabled>
                            <i class="fas fa-upload mr-2"></i>UPLOAD / REPLACE FILE
                        </button>
                    </div>
                </form>
                <div id="offer-suppression-upload-status" class="alert alert-warning py-2 mt-3 mb-0" role="status" aria-live="polite" hidden></div>
            </div>
        @else
            <div class="command-os-info-banner">
                <p class="text-muted mb-0 font-size-xs">Only workspace owners and administrators can refresh, upload, replace, or download offer suppression files.</p>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('console-assets/offer-suppression-file.js') }}?v={{ substr(sha1_file(public_path('console-assets/offer-suppression-file.js')), 0, 12) }}" defer></script>
@endpush
