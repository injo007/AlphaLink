@extends('layouts.dms-admin')

@section('title', 'Campaign Visitors Logs')

@section('content')
<div id="campaign-visitors"
    data-table-url="{{ route('console.campaigns.visitors', [$workspace->id, $campaign->id]) }}"
    data-initial-search="{{ $search }}"
    data-initial-start="{{ $tableState['start'] }}"
    data-initial-length="{{ $tableState['length'] }}"
    data-initial-order-index="{{ $tableState['orderIndex'] }}"
    data-initial-order-direction="{{ $tableState['orderDirection'] }}">
    <h2 class="content-heading">CAMPAIGN <span class="text-info" title="{{ $campaign->id }}">{{ substr($campaign->id, 0, 8) }}</span> VISITORS</h2>

    <div class="row row-deck mb-3" aria-label="Campaign visitor totals">
        @foreach ([['OPENS', 'opens'], ['CLICKS', 'clicks'], ['LEADS', 'leads'], ['LEAD REVENUE', 'revenue'], ['UNSUBSCRIBES', 'unsubscribes'], ['BOTS', 'bots']] as [$label, $key])
            <div class="col-6 col-md-4 col-xl-2">
                <section class="block block-rounded mb-3">
                    <div class="block-content block-content-full">
                        <div class="font-size-h4 font-w600">{{ $key === 'revenue' ? '$'.number_format($totals[$key], 2) : number_format($totals[$key]) }}</div>
                        <div class="font-size-sm text-muted">{{ $label }}</div>
                    </div>
                </section>
            </div>
        @endforeach
    </div>

    <section id="campaign-visitors-map-block" class="block block-rounded block-mode-hidden mb-2">
        <div class="block-header block-header-default">
            <h3 class="block-title">MAP</h3>
            <div class="block-options">
                <button type="button" id="campaign-visitors-map-toggle" class="btn-block-option" aria-label="Toggle campaign visitor map" aria-expanded="false">
                    <i class="si si-arrow-down" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div id="campaign-visitors-map" style="width: 100%; min-height: 420px" role="img" aria-label="Visitor counts by country"></div>
            <div id="campaign-visitors-map-empty" class="alert alert-info mb-0" role="status" hidden>No country-enriched visitor records are available for this campaign.</div>
        </div>
    </section>

    <div class="alert alert-warning" role="note"><strong>Data coverage:</strong> this log combines stored campaign open, click, unsubscribe, and bot events with imported campaign leads. Native per-send configuration records are not available in AlphaLink yet.</div>

    <section class="block block-rounded" aria-labelledby="campaign-visitors-table-title">
        <div class="block-header block-header-default">
            <h3 id="campaign-visitors-table-title" class="block-title">VISITORS LOGS</h3>
            <div class="block-options">
                <a class="btn btn-sm btn-alt-secondary" href="{{ route('console.campaigns.index', $workspace->id) }}" aria-label="Back to campaigns"><i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>CAMPAIGNS</a>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="d-flex flex-wrap align-items-end justify-content-between mb-2 campaign-visitors-tools">
                <div class="form-group mb-2">
                    <label for="campaign-visitors-search">Search visitor records</label>
                    <div class="input-group">
                        <input id="campaign-visitors-search" type="search" class="form-control" maxlength="200" value="{{ $search }}" placeholder="Search type, send, contact, revision, or IP">
                        <div class="input-group-append">
                            <button id="campaign-visitors-search-clear" type="button" class="btn btn-alt-secondary" aria-label="Clear visitor search" @disabled($search === '')><i class="fas fa-times" aria-hidden="true"></i></button>
                        </div>
                    </div>
                </div>
                <div id="campaign-visitors-result-count" class="small text-muted mb-2" role="status" aria-live="polite"></div>
            </div>
            <div id="campaign-visitors-error" class="alert alert-danger" role="alert" hidden></div>
            <div class="table-responsive">
                <table id="campaign-visitors-table" class="table table-sm table-hover table-striped w-100">
                    <caption class="sr-only">Campaign open, click, lead, unsubscribe, and bot records</caption>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script src="https://www.gstatic.com/charts/loader.js"></script>
<script src="{{ asset('dms-reference/campaign-visitors.js') }}?v={{ substr(sha1_file(public_path('dms-reference/campaign-visitors.js')), 0, 12) }}"></script>
@endpush
