@extends('layouts.admin-shell')

@section('title', 'Warmups')

@section('content')
<section class="block block-rounded campaign-register-page warmup-register-page" id="warmup-register">
    <div class="block-header campaign-register-page-header warmup-register-page-header">
        <div class="campaign-register-heading">
            <span class="campaign-register-heading-icon warmup-register-heading-icon" aria-hidden="true"><i class="fas fa-fire"></i></span>
            <div class="campaign-register-heading-copy">
                <span class="campaign-register-eyebrow">WARMUP OPERATIONS</span>
                <h1 class="block-title">My Warmups</h1>
                <p>Review your drafts, seed audiences, pacing, and send progress.</p>
            </div>
        </div>
        <div class="block-options d-flex align-items-center">
            <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle warmup register fullscreen">
                <i class="si si-size-fullscreen" aria-hidden="true"></i>
            </button>
        </div>
    </div>
    <div class="block-content block-content-full">
        <section class="warmup-register-overview" aria-label="Warmup overview">
            <article class="warmup-register-metric">
                <span class="warmup-register-metric-icon" aria-hidden="true"><i class="fas fa-fire"></i></span>
                <span><small>Warmup drafts</small><strong>{{ number_format($drafts->total()) }}</strong><em>In the selected date range</em></span>
            </article>
            <article class="warmup-register-metric warmup-register-metric-active">
                <span class="warmup-register-metric-icon" aria-hidden="true"><i class="fas fa-paper-plane"></i></span>
                <span><small>Active sends</small><strong>{{ number_format($activeSends) }}</strong><em>Queued or running in this workspace</em></span>
            </article>
        </section>

        <form id="warmup-filter-form" method="get" action="{{ route('console.warmups.index', $workspace->id) }}" class="form-inline mb-2 warmup-register-filters" aria-label="Filter warmups" data-analytics-date-range-form data-storage-key="warmups" data-min-date="none" novalidate>
            <div class="form-group mr-2 mb-2">
                <label class="sr-only" for="warmup-per-page">Rows per page</label>
                <select id="warmup-per-page" name="per_page" class="form-control form-control-sm" aria-label="Rows per page" onchange="this.form.submit()">
                    <option value="25" @selected($perPage === 25)>Show 25 rows</option>
                    <option value="50" @selected($perPage === 50)>Show 50 rows</option>
                    <option value="100" @selected($perPage === 100)>Show 100 rows</option>
                </select>
            </div>
            <a class="btn btn-sm campaign-register-create warmup-register-create mr-1 mb-2" href="{{ $createUrl }}" title="Create a warmup" aria-label="Create a warmup"><i class="fas fa-plus" aria-hidden="true"></i><span>New Warmup</span></a>
            <button class="btn btn-sm campaign-register-icon-action mr-1 mb-2" type="button" disabled title="Warmup deletion is not available" aria-label="Delete selected warmups unavailable"><i class="fas fa-trash" aria-hidden="true"></i></button>
            <button class="btn btn-sm campaign-register-icon-action mb-2" type="submit" title="Refresh warmups" aria-label="Refresh warmups"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>
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

        <div class="table-responsive campaign-register-table-wrap warmup-register-table-wrap">
            <table id="warmups" class="table table-sm table-hover campaign-register-table warmup-register-table">
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
                        <td class="warmup-register-progress">@if ($sendRun)<strong>{{ number_format($processed) }} / {{ number_format((int) $sendRun->recipient_count) }}</strong><small>Accepted {{ number_format((int) $sendRun->accepted_count) }} · rejected {{ number_format((int) $sendRun->rejected_count) }} · uncertain {{ number_format((int) $sendRun->uncertain_count) }}</small>@else<span class="badge badge-secondary">—</span>@endif</td>
                        <td>{{ strtoupper((string) ($config['send_type'] ?? '—')) }}</td>
                        <td class="text-nowrap warmup-register-actions">
                            <div class="warmup-register-row-actions">
                            <a class="campaign-register-icon-action" href="{{ route('console.warmups.edit', [$workspace->id, $draft->id]) }}" title="Edit Warmup" aria-label="Edit Warmup"><i class="fas fa-edit" aria-hidden="true"></i></a>
                            <a class="campaign-register-icon-action warmup-action-test" href="{{ route('console.warmups.edit', [$workspace->id, $draft->id]) }}" title="Open Warmup composer to test saved Email Test addresses" aria-label="Test Warmup"><i class="far fa-paper-plane" aria-hidden="true"></i></a>
                            <button class="campaign-register-icon-action warmup-action-danger" type="button" disabled title="Warmup removal is not connected" aria-label="Warmup removal unavailable"><i class="fas fa-trash-alt" aria-hidden="true"></i></button>
                            <button class="campaign-register-icon-action" type="button" disabled title="Resend requires a completed send" aria-label="Resend unavailable"><i class="fas fa-redo" aria-hidden="true"></i></button>
                            @if ($sendRun && in_array($sendRun->state, ['planned', 'running'], true))
                                <form method="post" action="{{ route('console.warmups.send-stop', [$workspace->id, $draft->id, $sendRun->id]) }}">@csrf<button class="campaign-register-icon-action warmup-action-danger" type="submit" title="Stop Warmup send" aria-label="Stop Warmup send"><i class="fas fa-stop" aria-hidden="true"></i></button></form>
                            @else
                                <form method="post" action="{{ route('console.warmups.send', [$workspace->id, $draft->id]) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><button class="campaign-register-icon-action warmup-action-success" type="submit" title="Queue Warmup send" aria-label="Send Warmup"><i class="fas fa-paper-plane" aria-hidden="true"></i></button></form>
                            @endif
                            <button class="campaign-register-icon-action" type="button" disabled title="Warmup schedule is not connected" aria-label="Warmup schedule unavailable"><i class="fas fa-calendar-alt" aria-hidden="true"></i></button>
                            <button class="campaign-register-icon-action" type="button" disabled title="No Warmup server process is attached" aria-label="Warmup PMTA unavailable"><i class="fas fa-server" aria-hidden="true"></i></button>
                            </div>
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
            <small class="warmup-register-scroll-hint"><i class="fas fa-arrows-alt-h" aria-hidden="true"></i> Scroll horizontally to view the remaining fields and actions.</small>
            {{ $drafts->links() }}
        </div>
        <p class="sr-only" role="note">Warmup sends use the saved seed lists and PMTA routes. Test messages go only to saved Email Test addresses.</p>
    </div>
</section>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.css') }}">
    <style>
        .warmup-register-page { --warmup-danger: #ce4560; --warmup-success: #14825d; margin: 0 0 18px; overflow: hidden; border: 1px solid var(--co-border); border-radius: 17px; color: var(--co-ink); background: var(--co-surface); box-shadow: var(--co-shadow); }
        .warmup-register-page-header { align-items: center; justify-content: space-between; gap: 18px; min-height: 110px; overflow: hidden; padding: 15px 20px; position: relative; isolation: isolate; border-bottom: 1px solid var(--co-border); background: linear-gradient(105deg, color-mix(in srgb, var(--co-purple) 8%, var(--co-surface)), var(--co-surface) 48%, color-mix(in srgb, var(--co-blue) 6%, var(--co-surface))); }
        .warmup-register-page-header::before { position: absolute; z-index: -1; inset: -65% -5% -55% 28%; border-radius: 50%; background: radial-gradient(ellipse at 52% 50%, color-mix(in srgb, var(--co-purple-bright) 22%, transparent), transparent 57%), radial-gradient(ellipse at 73% 54%, color-mix(in srgb, var(--co-blue) 22%, transparent), transparent 48%); content: ""; }
        .warmup-register-page .campaign-register-heading { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .warmup-register-page .campaign-register-heading-icon { display: grid; flex: 0 0 46px; width: 46px; height: 46px; place-items: center; border: 1px solid color-mix(in srgb, var(--co-purple) 24%, var(--co-border)); border-radius: 14px; color: #fff; background: linear-gradient(145deg, var(--co-purple-bright), var(--co-purple)); box-shadow: 0 7px 18px color-mix(in srgb, var(--co-purple) 24%, transparent); font-size: 18px; }
        .warmup-register-page .campaign-register-heading-copy { min-width: 0; }
        .warmup-register-page .campaign-register-eyebrow { color: var(--co-purple); font-size: 9px; font-weight: 800; letter-spacing: .14em; }
        .warmup-register-page .campaign-register-heading-copy p { margin: 0; color: var(--co-muted); font-size: 10px; }
        .warmup-register-heading-icon { background: linear-gradient(145deg, var(--co-purple-bright), var(--co-purple)); }
        .warmup-register-page .block-title { margin: 2px 0 3px; color: var(--co-ink); font-size: 20px; font-weight: 780; letter-spacing: -.04em; line-height: 1.15; text-transform: none; }
        .warmup-register-page .block-options { gap: 12px; margin: 0; }
        .warmup-register-page .block-options .btn-block-option { display: grid; width: 34px; height: 34px; place-items: center; border: 1px solid var(--co-border); border-radius: 10px; color: var(--co-muted); background: var(--co-surface); }
        .warmup-register-page .block-options .btn-block-option:hover { border-color: var(--co-purple); color: var(--co-purple); }
        .warmup-register-page > .block-content { padding: 16px 18px 18px; }
        .warmup-register-overview { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 0 0 13px; }
        .warmup-register-metric { display: flex; align-items: center; gap: 11px; min-width: 0; min-height: 76px; padding: 11px 13px; border: 1px solid var(--co-border); border-radius: 13px; background: linear-gradient(145deg, color-mix(in srgb, var(--co-purple) 5%, var(--co-surface)), var(--co-surface)); box-shadow: 0 5px 16px color-mix(in srgb, var(--co-purple) 7%, transparent); }
        .warmup-register-metric-icon { display: grid; flex: 0 0 38px; width: 38px; height: 38px; place-items: center; border: 1px solid color-mix(in srgb, var(--co-purple) 16%, var(--co-border)); border-radius: 12px; color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 10%, var(--co-surface)); font-size: 15px; }
        .warmup-register-metric > span:last-child { display: grid; gap: 1px; min-width: 0; }
        .warmup-register-metric small { color: var(--co-muted); font-size: 9px; font-weight: 750; }
        .warmup-register-metric strong { color: var(--co-ink); font-size: 19px; font-weight: 800; line-height: 1.15; font-variant-numeric: tabular-nums; }
        .warmup-register-metric em { overflow: hidden; color: var(--co-subtle); font-size: 8px; font-style: normal; text-overflow: ellipsis; white-space: nowrap; }
        .warmup-register-metric-active .warmup-register-metric-icon { color: var(--co-blue); background: color-mix(in srgb, var(--co-blue) 10%, var(--co-surface)); }
        .warmup-register-filters { display: flex; flex: 1 1 auto; align-items: center; gap: 7px; min-width: 0; margin: 0 0 13px !important; padding: 10px; border: 1px solid var(--co-border); border-radius: 13px; background: linear-gradient(110deg, color-mix(in srgb, var(--co-purple) 4%, var(--co-surface-muted)), var(--co-surface-muted)); }
        .warmup-register-filters .form-group { margin: 0 7px 0 0 !important; }
        .warmup-register-filters .form-control { height: 35px; border: 1px solid var(--co-border-strong); border-radius: 9px; color: var(--co-ink); background: var(--co-surface); box-shadow: none; font-size: 10px; }
        .warmup-register-filters .form-control:focus { border-color: var(--co-purple); box-shadow: 0 0 0 3px color-mix(in srgb, var(--co-purple) 12%, transparent); }
        .warmup-register-filters select.form-control { min-width: 132px; }
        .warmup-register-filters-right { flex: 1 1 560px; justify-content: flex-end; gap: 7px; min-width: 0; }
        .warmup-register-filters-right > label:not(.sr-only) { color: var(--co-muted); font-size: 10px; font-weight: 700; }
        .warmup-register-filters .warmup-date-range { width: 190px; }
        .warmup-register-filters .warmup-search-control { flex: 0 1 220px; min-width: 130px; }
        .warmup-register-filters input[type="search"] { width: 100%; max-width: none; }
        @media (min-width: 768px) { .warmup-register-filters-right { flex-wrap: nowrap !important; } }
        .warmup-register-create { display: inline-flex; align-items: center; justify-content: center; gap: 7px; min-height: 35px; padding: 0 13px; border: 1px solid var(--co-purple); border-radius: 9px; color: #fff !important; background: linear-gradient(105deg, var(--co-purple-bright), var(--co-purple)); box-shadow: 0 4px 10px color-mix(in srgb, var(--co-purple) 20%, transparent); font-size: 10px; font-weight: 750; text-decoration: none; }
        .warmup-register-create:hover { filter: brightness(1.04); transform: translateY(-1px); }
        .warmup-register-page .campaign-register-icon-action { display: inline-flex; align-items: center; justify-content: center; width: 35px; min-width: 35px; height: 35px; min-height: 35px; padding: 0; border: 1px solid var(--co-border-strong); border-radius: 9px; color: var(--co-muted); background: var(--co-surface); text-decoration: none; }
        .warmup-register-page .campaign-register-icon-action:hover:not(:disabled) { border-color: var(--co-purple); color: var(--co-purple); }
        .warmup-register-page .campaign-register-icon-action:disabled { cursor: not-allowed; opacity: .46; }
        .warmup-register-table-wrap { max-width: 100%; overflow: auto; border: 1px solid var(--co-border); border-radius: 13px; background: var(--co-surface); box-shadow: var(--co-shadow); -webkit-overflow-scrolling: touch; scrollbar-color: var(--co-border-strong) transparent; scrollbar-width: thin; touch-action: pan-x pan-y; }
        .warmup-register-table-wrap::-webkit-scrollbar { height: 8px; }
        .warmup-register-table-wrap::-webkit-scrollbar-track { background: var(--co-surface-muted); }
        .warmup-register-table-wrap::-webkit-scrollbar-thumb { border: 2px solid var(--co-surface-muted); border-radius: 8px; background: var(--co-border-strong); }
        .warmup-register-table { width: 100%; min-width: 1330px; margin: 0; border-collapse: separate; border-spacing: 0; color: var(--co-ink); font-size: 10px; table-layout: fixed; }
        .warmup-register-table th:nth-child(1), .warmup-register-table td:nth-child(1) { width: 120px; }
        .warmup-register-table th:nth-child(2), .warmup-register-table td:nth-child(2) { width: 70px; }
        .warmup-register-table th:nth-child(3), .warmup-register-table td:nth-child(3) { width: 65px; }
        .warmup-register-table th:nth-child(4), .warmup-register-table td:nth-child(4) { width: 95px; }
        .warmup-register-table th:nth-child(5), .warmup-register-table td:nth-child(5) { width: 95px; }
        .warmup-register-table th:nth-child(6), .warmup-register-table td:nth-child(6) { width: 85px; }
        .warmup-register-table th:nth-child(7), .warmup-register-table td:nth-child(7) { width: 100px; }
        .warmup-register-table th:nth-child(8), .warmup-register-table td:nth-child(8) { width: 80px; }
        .warmup-register-table th:nth-child(9), .warmup-register-table td:nth-child(9) { width: 75px; }
        .warmup-register-table th:nth-child(10), .warmup-register-table td:nth-child(10) { width: 80px; }
        .warmup-register-table th:nth-child(11), .warmup-register-table td:nth-child(11) { width: 185px; }
        .warmup-register-table th:nth-child(12), .warmup-register-table td:nth-child(12) { width: 70px; }
        .warmup-register-table th:nth-child(13), .warmup-register-table td:nth-child(13) { width: 160px; }
        .warmup-register-table th { position: sticky; top: 0; z-index: 1; padding: 11px 9px; border-top: 0; border-bottom: 1px solid var(--co-border); color: var(--co-muted); background: var(--co-surface-muted); font-size: 8px; font-weight: 800; letter-spacing: .07em; white-space: nowrap; }
        .warmup-register-table td { padding: 9px; border-top: 1px solid color-mix(in srgb, var(--co-border) 76%, transparent); color: var(--co-ink); vertical-align: middle; }
        .warmup-register-table .warmup-register-progress strong { display: block; color: var(--co-ink); font-size: 10px; font-weight: 800; white-space: nowrap; }
        .warmup-register-table .warmup-register-progress small { display: block; margin-top: 3px; color: var(--co-muted); font-size: 8px; line-height: 1.45; }
        .warmup-register-table tbody tr:first-child td { border-top: 0; }
        .warmup-register-table tbody tr:hover td { background: color-mix(in srgb, var(--co-purple) 4%, var(--co-surface)); }
        .warmup-register-table th:first-child, .warmup-register-table td:first-child { min-width: 120px; }
        .warmup-register-table tbody th a { color: var(--co-purple); font-weight: 800; text-decoration: none; }
        .warmup-register-table tbody th a:hover { text-decoration: underline; }
        .warmup-register-table tbody th small { display: inline-block; max-width: 160px; overflow: hidden; color: var(--co-muted); text-overflow: ellipsis; vertical-align: bottom; white-space: nowrap; }
        .warmup-register-table .badge { border-radius: 20px; font-size: 8px; font-weight: 750; letter-spacing: .025em; }
        .warmup-register-table .badge-primary { color: var(--co-purple); background: color-mix(in srgb, var(--co-purple) 12%, var(--co-surface)); }
        .warmup-register-table .badge-secondary { color: var(--co-muted); background: color-mix(in srgb, var(--co-muted) 12%, var(--co-surface)); }
        .warmup-register-table .badge-success { color: #08784e; background: #def8ec; }
        .warmup-register-table .badge-warning { color: #a65d00; background: #fff2d8; }
        html[data-command-theme='dark'] .warmup-register-table .badge-success { color: #75e4b4; background: rgb(25 173 116 / 20%); }
        html[data-command-theme='dark'] .warmup-register-table .badge-warning { color: #ffd591; background: rgb(209 126 20 / 22%); }
        .warmup-register-table .warmup-register-actions { min-width: 160px; }
        .warmup-register-row-actions { display: grid; grid-template-columns: repeat(4, 30px); gap: 4px; }
        .warmup-register-row-actions form { display: flex; margin: 0; }
        .warmup-register-page .warmup-register-row-actions .campaign-register-icon-action { width: 30px; min-width: 30px; height: 30px; min-height: 30px; border-color: color-mix(in srgb, var(--co-purple) 24%, var(--co-border)); border-radius: 9px; color: var(--co-purple); background: linear-gradient(145deg, color-mix(in srgb, var(--co-purple) 13%, var(--co-surface)), color-mix(in srgb, var(--co-blue) 9%, var(--co-surface))); box-shadow: 0 2px 6px color-mix(in srgb, var(--co-purple) 12%, transparent); }
        .warmup-register-page .warmup-register-row-actions .warmup-action-test { color: var(--co-blue); }
        .warmup-register-page .warmup-register-row-actions .warmup-action-danger { color: var(--warmup-danger); }
        .warmup-register-page .warmup-register-row-actions .warmup-action-success { color: var(--warmup-success); }
        .warmup-register-footer { gap: 10px; padding-top: 12px; color: var(--co-muted); }
        .warmup-register-scroll-hint { align-items: center; display: inline-flex; gap: 6px; color: var(--co-muted); font-size: 9px; }
        .warmup-register-footer nav { margin-left: auto; }
        .warmup-register-footer .pagination { margin: 0; }
        @media (max-width: 767.98px) {
            .warmup-register-page-header { align-items: flex-start; flex-direction: column; padding: 14px; }
            .warmup-register-page .campaign-register-heading { align-items: flex-start; }
            .warmup-register-page .block-options { justify-content: space-between; width: 100%; }
            .warmup-register-page > .block-content { padding: 12px; }
            .warmup-register-overview { grid-template-columns: 1fr; }
            .warmup-register-filters { align-items: stretch; }
            .warmup-register-filters > .form-group { flex: 1 1 100%; margin: 0 0 4px !important; }
            .warmup-register-filters > .form-group select { width: 100%; }
            .warmup-register-filters-right { flex: 1 1 100%; justify-content: flex-start; margin-left: 0 !important; }
            .warmup-register-filters-right > label:not(.sr-only) { margin: 0; }
            .warmup-register-filters .warmup-date-range { flex: 1 1 12rem; width: auto; }
            .warmup-register-filters .warmup-search-control { flex: 1 1 10rem; }
            .warmup-register-filters input[type="search"] { width: 100%; }
            .warmup-register-footer { align-items: flex-start !important; flex-direction: column; }
            .warmup-register-footer nav { margin-left: 0; max-width: 100%; overflow-x: auto; }
        }
        @media (prefers-reduced-motion: reduce) { .warmup-register-create { transition: none; } .warmup-register-create:hover { transform: none; } }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('console-assets/vendor/daterangepicker/daterangepicker.js') }}?v=3.1.0"></script>
    <script src="{{ asset('console-assets/analytics-date-range.js') }}?v={{ substr(sha1_file(public_path('console-assets/analytics-date-range.js')), 0, 12) }}" defer></script>
@endpush
