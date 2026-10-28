@extends('layouts.dms-admin')

@section('title', 'Offer Suppression File - DMS')

@section('content')
    <div id="offer-suppression-file-manager"
         data-refresh-url="{{ route('console.offers.suppression-file.download', [$workspace->id, $offer->id]) }}"
         data-refresh-status-url-template="{{ route('console.offers.suppression-file.download-status', [$workspace->id, $offer->id, '__DOWNLOAD_ID__']) }}"
         data-upload-url="{{ route('console.drops.suppression.offer-file', $workspace->id) }}"
         data-saved-file-url="{{ route('console.drops.suppression.offer-file.download', [$workspace->id, $offer->id]) }}"
         data-sponsor-id="{{ $offer->sponsor_id }}"
         data-offer-id="{{ $offer->id }}">
        <section class="block block-rounded">
            <div class="block-header">
                <h3 class="block-title">Suppression File</h3>
                <div class="block-options">
                    <a class="btn btn-sm btn-light" href="{{ route('clean.console.offers.index', ['sponsor' => $offer->sponsor_id]) }}">BACK TO OFFERS</a>
                    <a class="btn btn-sm btn-primary" href="{{ route('clean.console.drops.suppression', ['sponsor_id' => $offer->sponsor_id, 'offer_id' => $offer->id]) }}">OPEN SUPPRESSION RUNS</a>
                </div>
            </div>
            <div class="block-content block-content-full">
                <div class="mb-3">
                    <h4 class="h5 mb-1">{{ $offer->name }}</h4>
                    <p class="text-muted mb-0">{{ $offer->sponsor?->name ?? 'Unknown sponsor' }} · Offer ID {{ $offer->offer_id }} · {{ $offer->vertical ?: 'No vertical' }}</p>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-3">
                        <tbody>
                            <tr><th scope="row">File status</th><td>{{ $hasStoredFile ? 'SAVED' : 'NO FILE SAVED' }}</td></tr>
                            <tr><th scope="row">Format</th><td>{{ $hasStoredFile ? strtoupper((string) $offer->suppression_format) : '—' }}</td></tr>
                            <tr><th scope="row">Last updated</th><td>{{ $hasStoredFile ? ($offer->suppression_updated_at?->toDateString() ?? 'Date unavailable') : '—' }}</td></tr>
                            <tr><th scope="row">Entries</th><td>{{ $savedFileEntryCount === null ? 'Not recorded' : number_format((int) $savedFileEntryCount) }}</td></tr>
                            <tr><th scope="row">Saved file size</th><td>{{ $savedFileBytes === null ? '—' : number_format($savedFileBytes / 1024 / 1024, 2).' MB' }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="alert {{ $hasStoredFile ? 'alert-success' : 'alert-warning' }}" role="status" aria-live="polite">
                    @if ($hasStoredFile)
                        The verified suppression file is saved on this offer and available to suppression runs. The offer’s normal live, active, freshness, weekday, and access rules still apply when a run starts.
                    @else
                        No verified suppression file is saved for this offer yet. Refresh it from the sponsor or upload a file below.
                    @endif
                </div>

                <div class="d-flex flex-wrap align-items-center mb-3">
                    @if ($hasStoredFile)
                        <a class="btn btn-sm btn-outline-success mr-2 mb-2" href="{{ route('console.drops.suppression.offer-file.download', [$workspace->id, $offer->id]) }}">DOWNLOAD SAVED FILE</a>
                    @endif
                    <button id="offer-suppression-refresh" class="btn btn-sm btn-primary mr-2 mb-2" type="button">REFRESH FROM SPONSOR</button>
                </div>

                <div id="offer-suppression-refresh-status" class="alert alert-info py-2 mb-3" role="status" aria-live="polite" hidden></div>
                <div id="offer-suppression-refresh-progress-wrap" class="mb-4" hidden>
                    <div class="progress" style="height: 22px">
                        <div id="offer-suppression-refresh-progress" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
                    </div>
                    <small id="offer-suppression-refresh-size" class="form-text text-muted mt-2"></small>
                </div>

                @if ($canManageSuppressionFiles)
                    <section class="border-top pt-3" aria-labelledby="offer-suppression-upload-title">
                        <h4 id="offer-suppression-upload-title" class="h5">Upload or replace file</h4>
                        <p class="text-muted">TXT and CSV files containing email addresses, 32-character MD5 hashes, or 128-character SHA-512 hashes are normalized, verified, and stored privately.</p>
                        <form id="offer-suppression-upload-form" class="form-row align-items-end" novalidate>
                            <div class="form-group col-12 col-md-8">
                                <label for="offer-suppression-upload-input">Suppression file</label>
                                <input id="offer-suppression-upload-input" type="file" class="form-control-file" accept=".txt,.csv,text/plain,text/csv" required aria-describedby="offer-suppression-upload-help">
                                <small id="offer-suppression-upload-help" class="form-text text-muted">Choose a TXT or CSV file. There is no application-level size or entry-count cap.</small>
                            </div>
                            <div class="form-group col-12 col-md-4">
                                <button id="offer-suppression-upload-button" class="btn btn-sm btn-warning" type="submit" disabled>UPLOAD / REPLACE FILE</button>
                            </div>
                        </form>
                        <div id="offer-suppression-upload-status" class="alert alert-warning py-2" role="status" aria-live="polite" hidden></div>
                    </section>
                @else
                    <p class="text-muted">Only workspace owners and administrators can refresh, upload, replace, or download offer suppression files.</p>
                @endif
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dms-reference/offer-suppression-file.js') }}?v={{ substr(sha1_file(public_path('dms-reference/offer-suppression-file.js')), 0, 12) }}" defer></script>
@endpush
