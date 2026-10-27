@extends('layouts.dms-admin')

@section('title', 'Drop Suppression')

@section('content')
<div id="drops-suppression"
    data-catalog-url="{{ route('console.drops.suppression.catalog', $workspace->id) }}"
    data-rows-url="{{ route('console.drops.suppression', $workspace->id) }}"
    data-start-url="{{ route('console.drops.suppression.start', $workspace->id) }}"
    data-offer-file-url="{{ route('clean.console.drops.suppression.offer-file') }}"
    data-kill-url="{{ route('console.drops.suppression.kill', $workspace->id) }}"
    data-monitor-url-template="{{ route('console.drops.suppression.monitor', [$workspace->id, '__SUPPRESSION_ID__']) }}"
    data-can-manage-files="{{ $canManageSuppressionFiles ? '1' : '0' }}"
    data-initial-sponsor-id="{{ $initialSponsorId ?? '' }}"
    data-initial-offer-id="{{ $initialOfferId ?? '' }}"
    data-today="{{ $today }}"
    data-default-from="{{ $defaultFrom }}">
    <section class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Suppression</h3>
        </div>
        <div class="block-content block-content-full">
            <form id="drops-suppression-form" class="form-row align-items-end" novalidate>
                <div class="form-group col-12 col-md-2">
                    <label for="drops-suppression-sponsor">Sponsors</label>
                    <select id="drops-suppression-sponsor" class="form-control selectpicker" data-live-search="true" data-allow-clear="true" title="Select Sponsor">
                        <option value="">Select Sponsor</option>
                        @foreach ($sponsors as $sponsor)
                            <option value="{{ $sponsor->id }}" @selected((string) $initialSponsorId === (string) $sponsor->id)>{{ $sponsor->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-12 col-md-4">
                    <label for="drops-suppression-offer">Offers</label>
                    <select id="drops-suppression-offer" class="form-control selectpicker" data-live-search="true" data-size="6" title="Select Offer" disabled>
                        <option value="">Select Offer</option>
                    </select>
                </div>
                <div class="form-group col-12 col-md-3">
                    <label for="drops-suppression-isps">ISPs</label>
                    <select id="drops-suppression-isps" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" title="Select ISPs" multiple disabled></select>
                </div>
                <div class="form-group col-12 col-md-3">
                    <label for="drops-suppression-lists">Lists</label>
                    <select id="drops-suppression-lists" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" title="Select Lists" multiple disabled></select>
                </div>
                <div class="form-group col-12 text-right">
                    <button id="drops-suppression-start" type="submit" class="btn btn-sm btn-primary">START SUPPRESSION</button>
                </div>
            </form>
            <section class="border-top pt-3 mt-3" aria-labelledby="offer-suppression-file-title">
                <h4 id="offer-suppression-file-title" class="h5 mb-1">Offer suppression file</h4>
                <p class="text-muted mb-2">Upload or replace the selected offer’s suppression file here. The verified file and freshness date are saved on the offer; suppression runs remain subject to offer eligibility.</p>
                <p id="offer-suppression-file-status" class="mb-2" role="status" aria-live="polite">Select a sponsor and offer above to view its suppression file.</p>
                @if ($canManageSuppressionFiles)
                    <div class="form-row align-items-end">
                        <div class="form-group col-12 col-md-8">
                            <label for="offer-suppression-file-input">Suppression file (.txt or .csv; no application size or entry-count cap)</label>
                            <input id="offer-suppression-file-input" type="file" class="form-control-file" accept=".txt,.csv,text/plain,text/csv" disabled>
                        </div>
                        <div class="form-group col-12 col-md-4">
                            <button id="offer-suppression-file-upload" class="btn btn-sm btn-warning" type="button" disabled>UPLOAD / REPLACE FILE</button>
                        </div>
                    </div>
                    <div id="offer-suppression-file-result" class="alert alert-warning py-2 mb-3" role="status" aria-live="polite" hidden></div>
                @else
                    <p class="text-muted mb-3">Only workspace owners and administrators can upload or replace offer suppression files.</p>
                @endif
            </section>
            <div id="drops-suppression-message" class="alert alert-warning py-2 mb-3" role="status" aria-live="polite" hidden></div>
            <hr>
            <div class="d-flex flex-wrap align-items-end justify-content-between mb-2">
                <div class="form-inline mb-2">
                    <label class="mr-2" for="drops-suppression-from">Date</label>
                    <input class="form-control form-control-sm mr-2" id="drops-suppression-from" type="date" value="{{ $defaultFrom }}" max="{{ $today }}">
                    <span class="mr-2">to</span>
                    <input class="form-control form-control-sm" id="drops-suppression-to" type="date" value="{{ $today }}" max="{{ $today }}">
                </div>
                <button id="drops-suppression-refresh" type="button" class="btn btn-sm btn-light mb-2" title="Refresh"><i class="fa fa-sync" aria-hidden="true"></i><span class="sr-only">Refresh</span></button>
            </div>
            <div class="table-responsive">
                <table id="drops-suppression-table" class="table table-sm table-hover table-striped w-100">
                    <caption class="sr-only">Suppression runs and their progress</caption>
                </table>
            </div>
        </div>
    </section>
    <dialog id="drops-suppression-details" class="pmta-control-dialog" aria-labelledby="drops-suppression-details-title">
        <h3 id="drops-suppression-details-title" class="h5">Selected suppression scope</h3>
        <div id="drops-suppression-details-content" class="mb-3"></div>
        <form method="dialog" class="text-right"><button class="btn btn-secondary btn-sm">CLOSE</button></form>
    </dialog>
</div>
@endsection

@push('scripts')
<script src="{{ asset('dms-reference/drops-suppression.js') }}?v={{ substr(sha1_file(public_path('dms-reference/drops-suppression.js')), 0, 12) }}" defer></script>
@endpush
