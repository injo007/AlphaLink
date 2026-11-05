@extends('layouts.admin-shell')

@section('title', 'Warmups')

@section('content')
<section class="block block-rounded" id="warmup-register">
    <div class="block-header">
        <h3 class="block-title">My Warmups</h3>
        <div class="block-options">
            <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle fullscreen"></button>
        </div>
    </div>
    <div class="block-content block-content-full">
        <form id="warmup-filter-form" method="get" action="{{ route('console.warmups.index', $workspace->id) }}" class="form-inline mb-2 warmup-register-filters" aria-label="Filter warmups" data-analytics-date-range-form data-storage-key="warmups" data-min-date="none" novalidate>
            <div class="form-group mr-2 mb-2">
                <label class="sr-only" for="warmup-per-page">Rows per page</label>
                <select id="warmup-per-page" name="per_page" class="form-control form-control-sm" aria-label="Rows per page">
                    <option value="25" @selected($perPage === 25)>Show 25 rows</option>
                    <option value="50" @selected($perPage === 50)>Show 50 rows</option>
                    <option value="100" @selected($perPage === 100)>Show 100 rows</option>
                </select>
            </div>
            <a class="btn btn-sm btn-alt-secondary mr-1 mb-2" href="{{ $createUrl }}" title="CREATE WARMUP" aria-label="CREATE WARMUP"><i class="fas fa-plus" aria-hidden="true"></i></a>
            <span class="btn btn-sm btn-alt-secondary mr-1 mb-2" aria-label="Active warmup sends"><i class="fas fa-cog" aria-hidden="true"></i> Active sends <span class="badge badge-secondary">{{ number_format($activeSends) }}</span></span>
            <button class="btn btn-sm btn-alt-secondary mr-1 mb-2" type="button" disabled title="Warmup deletion is not available" aria-label="Delete selected warmups unavailable"><i class="fas fa-trash" aria-hidden="true"></i></button>
            <button class="btn btn-sm btn-alt-secondary mb-2" type="submit" title="Refresh warmups" aria-label="Refresh warmups"><i class="fas fa-sync" aria-hidden="true"></i></button>
            <div class="ml-auto d-flex flex-wrap align-items-center warmup-register-filters-right">
                <label for="analytics-dates" class="mr-1 mb-2">Date:</label>
                <input id="analytics-dates" type="text" class="form-control form-control-sm mr-2 mb-2 warmup-date-range" value="{{ $from }} - {{ $to }}" readonly aria-label="Choose warmup date range">
                <input type="hidden" name="from" value="{{ $from }}">
                <input type="hidden" name="to" value="{{ $to }}">
                <div class="form-group warmup-search-control mb-2">
                    <label class="sr-only" for="warmup-search">Search warmups</label>
                    <input class="form-control form-control-sm" type="search" id="warmup-search" name="q" value="{{ $query }}" placeholder="Search...">
                </div>
                <button class="btn btn-sm btn-alt-primary ml-1 mb-2" type="submit" aria-label="Search warmups"><i class="fas fa-search" aria-hidden="true"></i></button>
            </div>
        </form>

        <div class="table-responsive">
            <table id="warmups" class="table table-sm table-hover warmup-register-table">
                <caption class="sr-only">Saved Warmup drafts in this workspace</caption>
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">USER</th>
                        <th scope="col">SEEDS</th>
                        <th scope="col">SERVER</th>
                        <th scope="col">TOTAL SEND</th>
                        <th scope="col">FRACTION IP</th>
                        <th scope="col">SLEEP EMAIL</th>
                        <th scope="col">SLEEP IP</th>
                        <th scope="col">SEED</th>
                        <th scope="col">STATUS</th>
                        <th scope="col">PROGRESS</th>
                        <th scope="col">TYPE</th>
                        <th scope="col">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($drafts as $draft)
                    @php($config = $draft->warmup_config ?? [])
                    @php($sendRun = $draft->warmup_send_run)
                    @php($processed = $sendRun ? (int) $sendRun->accepted_count + (int) $sendRun->rejected_count + (int) $sendRun->uncertain_count : 0)
                    <tr>
                        <th scope="row"><a href="{{ route('console.warmups.edit', [$workspace->id, $draft->id]) }}">{{ substr((string) $draft->id, 0, 8) }}</a><br><small>{{ $draft->name }}</small></th>
                        <td>{{ auth()->user()->username }}</td>
                        <td>{{ number_format((int) ($draft->warmup_seed_count ?? 0)) }}</td>
                        <td>@if ($draft->warmup_servers->isNotEmpty())<span class="badge badge-primary">{{ $draft->warmup_servers->count() > 1 ? 'GROUPED : '.$draft->warmup_servers->count() : $draft->warmup_servers->first() }}</span>@else — @endif</td>
                        <td>{{ $config['total_send'] ?? '—' }}</td>
                        <td>{{ $config['fraction_ip'] ?? '—' }}</td>
                        <td>{{ $config['sleep_email'] ?? '—' }}</td>
                        <td>{{ $config['sleep_ip'] ?? '—' }}</td>
                        <td>{{ $config['seed_every'] ?? '—' }}</td>
                        <td><span class="badge badge-{{ $sendRun ? (in_array($sendRun->state, ['planned', 'running'], true) ? 'primary' : ($sendRun->state === 'completed' ? 'success' : 'warning')) : 'secondary' }}">{{ strtoupper($sendRun->state ?? 'draft') }}</span></td>
                        <td>@if ($sendRun){{ number_format($processed) }} / {{ number_format((int) $sendRun->recipient_count) }} accepted {{ number_format((int) $sendRun->accepted_count) }} · rejected {{ number_format((int) $sendRun->rejected_count) }} · uncertain {{ number_format((int) $sendRun->uncertain_count) }}@else<span class="badge badge-secondary">—</span>@endif</td>
                        <td>{{ strtoupper((string) ($config['send_type'] ?? '—')) }}</td>
                        <td class="text-nowrap warmup-register-actions">
                            <a class="btn btn-sm btn-link" href="{{ route('console.warmups.edit', [$workspace->id, $draft->id]) }}" title="SHOW" aria-label="Show Warmup"><i class="fas fa-edit" aria-hidden="true"></i></a>
                            <a class="btn btn-sm btn-link text-warning" href="{{ route('console.warmups.edit', [$workspace->id, $draft->id]) }}" title="Open Warmup composer to test saved Email Test addresses" aria-label="Test Warmup"><i class="far fa-paper-plane" aria-hidden="true"></i></a>
                            <button class="btn btn-sm btn-link text-danger" type="button" disabled title="Warmup removal is not connected" aria-label="Warmup removal unavailable"><i class="fas fa-trash-alt" aria-hidden="true"></i></button>
                            <br>
                            <button class="btn btn-sm btn-link" type="button" disabled title="Resend requires a completed send" aria-label="Resend unavailable"><i class="fas fa-redo text-secondary" aria-hidden="true"></i></button>
                            @if ($sendRun && in_array($sendRun->state, ['planned', 'running'], true))
                                <form class="d-inline" method="post" action="{{ route('console.warmups.send-stop', [$workspace->id, $draft->id, $sendRun->id]) }}">@csrf<button class="btn btn-sm btn-link text-danger" type="submit" title="Stop Warmup send" aria-label="Stop Warmup send"><i class="fas fa-stop" aria-hidden="true"></i></button></form>
                            @else
                                <form class="d-inline" method="post" action="{{ route('console.warmups.send', [$workspace->id, $draft->id]) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><button class="btn btn-sm btn-link text-success" type="submit" title="Queue Warmup send" aria-label="Send Warmup"><i class="fas fa-paper-plane" aria-hidden="true"></i></button></form>
                            @endif
                            <button class="btn btn-sm btn-link" type="button" disabled title="Warmup schedule is not connected" aria-label="Warmup schedule unavailable"><i class="fas fa-calendar-alt" aria-hidden="true"></i></button>
                            <button class="btn btn-sm btn-link" type="button" disabled title="No Warmup server process is attached" aria-label="Warmup PMTA unavailable"><i class="fas fa-server" aria-hidden="true"></i></button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="text-center text-muted">No data available in table</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center warmup-register-footer">
            <small class="text-muted">Showing {{ $drafts->firstItem() ?? 0 }} to {{ $drafts->lastItem() ?? 0 }} of {{ $drafts->total() }} entries.</small>
            {{ $drafts->links() }}
        </div>
        <p class="sr-only" role="note">Warmup sends use the saved seed lists and PMTA routes. Test messages go only to saved Email Test addresses.</p>
    </div>
</section>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.css') }}">
    <style>
        .warmup-register-table { font-size: .8rem; min-width: 1120px; }
        .warmup-register-table th { border-bottom: 1px solid #111; white-space: nowrap; }
        .warmup-register-table td { vertical-align: middle; }
        .warmup-register-actions .btn[disabled] { cursor: not-allowed; opacity: .55; }
        .warmup-register-filters .warmup-date-range { width: 13.2rem; }
        .warmup-register-filters .warmup-search-control { position: relative; }
        .warmup-register-filters input[type="search"] { max-width: 12rem; }
        @media (max-width: 767.98px) {
            .warmup-register-filters { align-items: stretch; }
            .warmup-register-filters-right { justify-content: flex-start; margin-left: 0 !important; width: 100%; }
            .warmup-register-filters .warmup-date-range { flex: 1 1 12rem; width: auto; }
            .warmup-register-filters .warmup-search-control { flex: 1 1 10rem; }
            .warmup-register-filters input[type="search"] { max-width: none; width: 100%; }
        }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.js') }}?v=3.1.0"></script>
    <script src="{{ asset('console-assets/analytics-date-range.js') }}?v={{ substr(sha1_file(public_path('console-assets/analytics-date-range.js')), 0, 12) }}" defer></script>
@endpush
