@extends('layouts.admin-shell')

@section('title', 'Campaign Logs')

@push('styles')
    <style>
        .campaign-logs-page { --logs-border: var(--co-border, #e1def4); --logs-ink: var(--co-ink, #1e214d); --logs-muted: var(--co-muted, #64709b); --logs-surface: var(--co-surface, #fff); color: var(--logs-ink); min-width: 0; width: 100%; }
        .campaign-logs-header { align-items: center; background: var(--logs-surface); border-bottom: 1px solid var(--logs-border); display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; padding: 1.25rem 1.5rem; }
        .campaign-logs-title { align-items: center; display: flex; gap: .9rem; min-width: 0; }
        .campaign-logs-title-mark { align-items: center; background: color-mix(in srgb, var(--co-purple, #7a3cff) 12%, var(--logs-surface)); border: 1px solid color-mix(in srgb, var(--co-purple, #7a3cff) 24%, var(--logs-border)); border-radius: .85rem; color: var(--co-purple, #7a3cff); display: inline-flex; flex: 0 0 2.75rem; height: 2.75rem; justify-content: center; }
        .campaign-logs-title h1 { color: var(--logs-ink); font-size: clamp(1.2rem, 2vw, 1.55rem); font-weight: 750; letter-spacing: -.025em; margin: 0; }
        .campaign-logs-title p { color: var(--logs-muted); font-size: .85rem; margin: .2rem 0 0; }
        .campaign-logs-eyebrow { color: var(--co-purple, #7a3cff); display: inline-block; font-size: .65rem; font-weight: 800; letter-spacing: .12em; margin-bottom: .22rem; }
        .campaign-logs-toolbar { align-items: end; background: color-mix(in srgb, var(--logs-surface) 88%, var(--co-page, #f4f3fb)); border-bottom: 1px solid var(--logs-border); display: flex; min-width: 0; padding: 1rem 1.25rem; }
        .campaign-logs-filters { align-items: end; display: grid; flex: 1 1 auto; gap: .65rem; grid-template-columns: repeat(4, minmax(8rem, 1fr)) auto; min-width: 0; }
        .campaign-logs-field { display: flex; flex-direction: column; gap: .3rem; min-width: 0; }
        .campaign-logs-field label { color: var(--logs-muted); font-size: .72rem; font-weight: 700; letter-spacing: .045em; margin: 0; text-transform: uppercase; }
        .campaign-logs-field input, .campaign-logs-field select { background: var(--logs-surface); border: 1px solid var(--logs-border); border-radius: .6rem; color: var(--logs-ink); min-height: 2.55rem; padding: .45rem .7rem; width: 100%; }
        .campaign-logs-field input:focus, .campaign-logs-field select:focus { border-color: var(--co-purple, #7a3cff); box-shadow: 0 0 0 .18rem color-mix(in srgb, var(--co-purple, #7a3cff) 18%, transparent); outline: 0; }
        .campaign-logs-table-wrap { max-width: 100%; min-width: 0; overflow-x: auto; overscroll-behavior-x: contain; scrollbar-gutter: stable; width: 100%; }
        .campaign-logs-table-wrap:focus-visible { outline: 2px solid var(--co-purple, #7a3cff); outline-offset: -2px; }
        .campaign-logs-table { margin: 0; min-width: 1050px; width: max-content; }
        .campaign-logs-table thead th { background: color-mix(in srgb, var(--logs-surface) 82%, var(--co-page, #f4f3fb)); border-bottom: 1px solid var(--logs-border); color: var(--logs-muted); font-size: .7rem; font-weight: 750; letter-spacing: .06em; padding: .78rem .85rem; text-transform: uppercase; white-space: nowrap; }
        .campaign-logs-table tbody td { border-top: 1px solid var(--logs-border); color: var(--logs-ink); padding: .8rem .85rem; vertical-align: middle; }
        .campaign-logs-table tbody tr:hover { background: color-mix(in srgb, var(--co-purple, #7a3cff) 4%, var(--logs-surface)); }
        .campaign-log-id { color: var(--logs-muted); font-family: var(--font-mono, ui-monospace, monospace); font-size: .8rem; font-variant-numeric: tabular-nums; }
        .campaign-log-status { align-items: center; border: 1px solid var(--logs-border); border-radius: 999px; display: inline-flex; font-size: .7rem; font-weight: 750; gap: .38rem; padding: .27rem .55rem; text-transform: uppercase; white-space: nowrap; }
        .campaign-log-status::before { background: currentColor; border-radius: 50%; content: ''; height: .42rem; width: .42rem; }
        .campaign-log-counts { align-items: center; display: flex; flex-wrap: nowrap; gap: .35rem; min-width: max-content; }
        .campaign-log-counts form { display: inline-flex; margin: 0; }
        .campaign-log-action { align-items: center; background: color-mix(in srgb, var(--co-blue, #4888f4) 9%, var(--logs-surface)); border: 1px solid color-mix(in srgb, var(--co-blue, #4888f4) 23%, var(--logs-border)); border-radius: .5rem; color: var(--co-blue, #3978e8); display: inline-flex; font-size: .72rem; font-weight: 750; gap: .3rem; justify-content: center; min-height: 2rem; padding: .3rem .5rem; text-decoration: none; transition: background-color .15s ease, border-color .15s ease, transform .15s ease; }
        .campaign-log-action:hover { background: color-mix(in srgb, var(--co-blue, #4888f4) 16%, var(--logs-surface)); color: var(--co-blue, #3978e8); text-decoration: none; transform: translateY(-1px); }
        .campaign-log-action:focus-visible, .campaign-log-restrict:focus-visible, .campaign-logs-apply:focus-visible { box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--co-purple, #7a3cff) 30%, transparent); outline: 0; }
        .campaign-log-action span { font-variant-numeric: tabular-nums; }
        .campaign-log-restrict { border: 1px solid #e55a69; border-radius: .5rem; color: #c43c4b; display: inline-flex; font-size: .7rem; font-weight: 750; justify-content: center; min-height: 2rem; padding: .3rem .6rem; white-space: nowrap; }
        .campaign-log-restrict.is-restricted { background: #e34e5e; color: #fff; }
        .campaign-log-restrict.is-unrestricted { background: color-mix(in srgb, #e34e5e 8%, var(--logs-surface)); }
        .campaign-log-restrict:disabled { cursor: not-allowed; opacity: .48; }
        .campaign-logs-footer { align-items: center; color: var(--logs-muted); display: flex; flex-wrap: wrap; gap: .75rem; justify-content: space-between; padding: 1rem 1.25rem; }
        .campaign-logs-footer .pagination { margin: 0; }
        .campaign-logs-empty { color: var(--logs-muted); padding: 3rem 1rem !important; text-align: center; }
        .campaign-logs-empty i { color: var(--co-purple, #7a3cff); display: block; font-size: 1.4rem; margin-bottom: .55rem; }
        @media (max-width: 1100px) {
            .campaign-logs-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .campaign-logs-field.search-field { grid-column: 1 / -1; }
            .campaign-logs-filter-actions { align-self: end; display: flex; flex-wrap: wrap; gap: .5rem; }
        }
        @media (max-width: 640px) {
            .campaign-logs-header { align-items: flex-start; padding: 1rem; }
            .campaign-logs-toolbar { padding: .85rem; }
            .campaign-logs-filters { grid-template-columns: minmax(0, 1fr); }
            .campaign-logs-field.search-field { grid-column: auto; }
            .campaign-logs-filter-actions { grid-column: auto; }
            .campaign-logs-filter-actions > * { flex: 1 1 8rem; }
            .campaign-logs-footer { align-items: flex-start; flex-direction: column; padding: .85rem; }
        }
        @media (prefers-reduced-motion: reduce) { .campaign-log-action { transition: none; } }
    </style>
@endpush

@section('content')
    <section class="block block-rounded campaign-logs-page" aria-labelledby="campaign-logs-title">
        <header class="campaign-logs-header">
            <div class="campaign-logs-title">
                <span class="campaign-logs-title-mark" aria-hidden="true"><i class="fas fa-history"></i></span>
                <div>
                    <span class="campaign-logs-eyebrow">CAMPAIGN STUDIO</span>
                    <h1 id="campaign-logs-title">Campaign Logs</h1>
                    <p>Review campaign tests, saved updates, send runs, and restriction status.</p>
                </div>
            </div>
            <a class="btn btn-sm btn-alt-secondary" href="{{ route('console.mailer.campaigns') }}"><i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>My campaigns</a>
        </header>

        <div class="campaign-logs-toolbar">
            <form method="GET" action="{{ route('console.campaign-logs.index', $workspace->id) }}" class="campaign-logs-filters" aria-label="Filter campaign logs">
                <div class="campaign-logs-field">
                    <label for="campaign-logs-from">From</label>
                    <input id="campaign-logs-from" type="date" name="from" value="{{ $from->toDateString() }}" max="{{ today()->toDateString() }}">
                </div>
                <div class="campaign-logs-field">
                    <label for="campaign-logs-to">To</label>
                    <input id="campaign-logs-to" type="date" name="to" value="{{ $to->toDateString() }}" max="{{ today()->toDateString() }}">
                </div>
                <div class="campaign-logs-field search-field">
                    <label for="campaign-logs-search">Search</label>
                    <input id="campaign-logs-search" type="search" name="q" value="{{ $search }}" placeholder="Campaign, ID, or user">
                </div>
                <div class="campaign-logs-field">
                    <label for="campaign-logs-page-size">Rows</label>
                    <select id="campaign-logs-page-size" name="per_page">
                        @foreach ([20, 50, 100] as $pageSize)
                            <option value="{{ $pageSize }}" @selected($perPage === $pageSize)>Show {{ $pageSize }} rows</option>
                        @endforeach
                    </select>
                </div>
                <div class="campaign-logs-filter-actions">
                    <button class="btn btn-sm btn-primary campaign-logs-apply" type="submit"><i class="fas fa-filter mr-1" aria-hidden="true"></i>Apply filters</button>
                    <a class="btn btn-sm btn-alt-secondary" href="{{ route('console.campaign-logs.index', $workspace->id) }}">Reset</a>
                </div>
            </form>
        </div>

        @if (session('status'))
            <div class="alert alert-success m-3" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->has('is_restricted'))
            <div class="alert alert-warning m-3" role="alert">{{ $errors->first('is_restricted') }}</div>
        @endif

        <div class="campaign-logs-table-wrap" role="region" aria-label="Scrollable Campaign Logs table" tabindex="0">
            <table class="table table-sm campaign-logs-table" aria-label="Campaign log register">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">ISP</th>
                        <th scope="col">Offer</th>
                        <th scope="col">User</th>
                        <th scope="col">Send status</th>
                        <th scope="col">Send left</th>
                        <th scope="col">Created at</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campaigns as $campaign)
                        @php
                            $sendRun = $campaign->logs_send_run;
                            $processed = $sendRun ? (int) $sendRun->accepted_count + (int) $sendRun->rejected_count + (int) $sendRun->suppressed_count + (int) $sendRun->uncertain_count : 0;
                            $sendLeft = $sendRun ? max(0, (int) $sendRun->recipient_count - $processed) : null;
                            $sendState = $sendRun?->state ? strtoupper(str_replace('_', ' ', $sendRun->state)) : strtoupper(str_replace('_', ' ', $campaign->status));
                            $offerLabel = $campaign->logs_offer ? trim('('.$campaign->logs_offer->offer_id.') '.$campaign->logs_offer->name) : '—';
                        @endphp
                        <tr>
                            <td><span class="campaign-log-id" title="{{ $campaign->id }}">{{ strtoupper(substr((string) $campaign->id, 0, 8)) }}</span></td>
                            <td>{{ $campaign->logs_isp ?: '—' }}</td>
                            <td>{{ $offerLabel }}</td>
                            <td>{{ $campaign->creator?->name ?: ($campaign->creator?->username ?: '—') }}</td>
                            <td><span class="campaign-log-status">{{ $sendState }}</span></td>
                            <td class="text-nowrap">@if ($sendRun) {{ number_format($sendLeft) }} / {{ number_format((int) $sendRun->recipient_count) }} @else — @endif</td>
                            <td class="text-nowrap">{{ $campaign->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                            <td>
                                <div class="campaign-log-counts" role="group" aria-label="History actions for {{ $campaign->name }}">
                                    <a class="campaign-log-action" href="{{ route('console.campaign-logs.history', [$workspace->id, $campaign->id, 'tests']) }}" title="Open test history" aria-label="Open {{ $campaign->tests_count }} tests for {{ $campaign->name }}"><i class="fas fa-vial" aria-hidden="true"></i><span>T:{{ $campaign->tests_count }}</span></a>
                                    <a class="campaign-log-action" href="{{ route('console.campaign-logs.history', [$workspace->id, $campaign->id, 'updates']) }}" title="Open update history" aria-label="Open {{ $campaign->updates_count }} updates for {{ $campaign->name }}"><i class="fas fa-pen-to-square" aria-hidden="true"></i><span>U:{{ $campaign->updates_count }}</span></a>
                                    <a class="campaign-log-action" href="{{ route('console.campaign-logs.history', [$workspace->id, $campaign->id, 'sends']) }}" title="Open send history" aria-label="Open {{ $campaign->sends_count }} sends for {{ $campaign->name }}"><i class="fas fa-paper-plane" aria-hidden="true"></i><span>S:{{ $campaign->sends_count }}</span></a>
                                    <form method="POST" action="{{ route('console.campaign-logs.restriction', [$workspace->id, $campaign->id]) }}" onsubmit="return confirm(this.dataset.confirmMessage)" data-confirm-message="{{ $campaign->is_restricted ? 'Unrestrict this campaign so new sends can be started?' : 'Restrict this campaign from new sends?' }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_restricted" value="{{ $campaign->is_restricted ? 0 : 1 }}">
                                        <button class="campaign-log-restrict {{ $campaign->is_restricted ? 'is-restricted' : 'is-unrestricted' }}" type="submit" @disabled($campaign->logs_has_active_send && ! $campaign->is_restricted) title="{{ $campaign->logs_has_active_send && ! $campaign->is_restricted ? 'Stop the active send before restricting this campaign.' : ($campaign->is_restricted ? 'Allow new sends' : 'Block new sends') }}" aria-label="{{ $campaign->is_restricted ? 'Unrestrict' : 'Restrict' }} {{ $campaign->name }}">
                                            {{ $campaign->is_restricted ? 'UNRESTRICT' : 'RESTRICT' }}
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="campaign-logs-empty"><i class="fas fa-inbox" aria-hidden="true"></i>No campaign logs match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <footer class="campaign-logs-footer">
            <span>Showing {{ $campaigns->count() }} of {{ $campaigns->total() }} campaigns</span>
            {{ $campaigns->links() }}
        </footer>
    </section>
@endsection
