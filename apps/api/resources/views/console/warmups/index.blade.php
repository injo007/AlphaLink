@extends('layouts.admin-shell')

@section('title', 'My Warmups')

@section('content')
<section class="block block-rounded">
    <div class="block-header">
        <h3 class="block-title">My Warmups</h3>
        <div class="block-options d-flex align-items-center" style="gap:.5rem"><a class="btn btn-sm btn-alt-secondary" href="{{ route('console.warmups.seeds.index', $workspace->id) }}">SEEDS</a><a class="btn btn-sm btn-alt-secondary" href="{{ route('console.warmups.statistics', $workspace->id) }}">STATISTICS</a><button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle fullscreen"></button></div>
    </div>
    <div class="block-content block-content-full">
        <p class="small text-muted mb-3" role="note">Saved AlphaLink Warmup drafts. No send process is attached to these rows.</p>
        <form method="get" action="{{ route('console.warmups.index', $workspace->id) }}" class="d-flex flex-wrap align-items-end mb-3" style="gap:.5rem">
            <div class="form-group mb-0"><label class="sr-only" for="warmup-per-page">Rows per page</label><select id="warmup-per-page" name="per_page" class="form-control form-control-sm"><option value="25" @selected($perPage === 25)>Show 25 rows</option><option value="50" @selected($perPage === 50)>Show 50 rows</option><option value="100" @selected($perPage === 100)>Show 100 rows</option></select></div>
            <a class="btn btn-sm btn-alt-secondary" href="{{ $createUrl }}" title="CREATE WARMUP" aria-label="CREATE WARMUP"><i class="fas fa-plus"></i></a>
            <button class="btn btn-sm btn-alt-secondary" type="button" disabled title="Running process view is not connected"><i class="fas fa-cog"></i> Active sends <span class="badge badge-secondary">{{ number_format($activeSends) }}</span></button>
            <button class="btn btn-sm btn-alt-secondary" type="button" disabled title="Warmup deletion is not available"><i class="fas fa-trash"></i><span class="sr-only">Delete selected warmups unavailable</span></button>
            <button class="btn btn-sm btn-alt-secondary" type="submit" title="Refresh warmups" aria-label="Refresh warmups"><i class="fas fa-sync"></i></button>
            <div class="ml-auto d-flex flex-wrap align-items-end" style="gap:.5rem">
                <div class="form-group mb-0"><label class="small mb-0" for="warmup-from">Date from</label><input class="form-control form-control-sm text-center" type="date" id="warmup-from" name="from" value="{{ $from }}" max="{{ today()->toDateString() }}"></div>
                <div class="form-group mb-0"><label class="small mb-0" for="warmup-to">Date to</label><input class="form-control form-control-sm text-center" type="date" id="warmup-to" name="to" value="{{ $to }}" max="{{ today()->toDateString() }}"></div>
                <div class="form-group mb-0"><label class="sr-only" for="warmup-search">Search</label><input class="form-control form-control-sm" type="search" id="warmup-search" name="q" value="{{ $query }}" placeholder="Search..."></div>
                <button class="btn btn-sm btn-alt-primary" type="submit" aria-label="Apply filters"><i class="fas fa-search"></i></button>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-2">
                <caption class="sr-only">Saved warmup drafts in this workspace</caption>
                <thead><tr><th scope="col">#</th><th scope="col">USER</th><th scope="col">SEEDS</th><th scope="col">SERVER</th><th scope="col">TOTAL SEND</th><th scope="col">FRACTION IP</th><th scope="col">SLEEP EMAIL</th><th scope="col">SLEEP IP</th><th scope="col">SEED</th><th scope="col">STATUS</th><th scope="col">PROGRESS</th><th scope="col">TYPE</th><th scope="col">ACTIONS</th></tr></thead>
                <tbody>
                @forelse ($drafts as $draft)
                    @php($config = $draft->warmup_config ?? [])
                    @php($sendSetup = $config['send_setup'] ?? [])
                    <tr>
                        <th scope="row"><a href="{{ route('console.warmups.edit', [$workspace->id, $draft->id]) }}">{{ substr((string) $draft->id, 0, 8) }}</a><br><small>{{ $draft->name }}</small></th>
                        <td>{{ auth()->user()->username }}</td>
                        <td>{{ number_format((int) ($draft->warmup_seed_count ?? 0)) }}<br><small class="text-muted">{{ (int) ($draft->warmup_seed_lists ?? 0) }} list(s)</small></td>
                        <td>@if ($draft->warmup_servers->isNotEmpty())<span class="badge badge-primary">{{ $draft->warmup_servers->count() > 1 ? 'GROUPED : '.$draft->warmup_servers->count() : $draft->warmup_servers->first() }}</span>@else — @endif</td>
                        <td>{{ $config['total_send'] ?? '—' }}</td>
                        <td>{{ $config['fraction_ip'] ?? '—' }}</td>
                        <td>{{ $config['sleep_email'] ?? '—' }}</td>
                        <td>{{ $config['sleep_ip'] ?? '—' }}</td>
                        <td>{{ $config['seed_every'] ?? '—' }}</td>
                        <td><span class="badge badge-secondary">DRAFT</span></td>
                        <td><span class="text-muted">No send run</span></td>
                        <td>{{ strtoupper((string) ($config['send_type'] ?? '—')) }}</td>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-link" href="{{ route('console.warmups.edit', [$workspace->id, $draft->id]) }}" title="Show Warmup" aria-label="Show Warmup"><i class="fas fa-edit"></i></a>
                            <button class="btn btn-sm btn-link text-warning" type="button" disabled title="Warmup test is not connected" aria-label="Warmup test unavailable"><i class="fas fa-vial"></i></button>
                            <button class="btn btn-sm btn-link text-danger" type="button" disabled title="Warmup removal is not connected" aria-label="Warmup removal unavailable"><i class="fas fa-trash-alt"></i></button>
                            <br>
                            <button class="btn btn-sm btn-link" type="button" disabled title="Resend requires a completed send" aria-label="Resend unavailable"><i class="fas fa-redo"></i></button>
                            <button class="btn btn-sm btn-link text-success" type="button" disabled title="Warmup send is not connected" aria-label="Warmup send unavailable"><i class="fas fa-paper-plane"></i></button>
                            <button class="btn btn-sm btn-link" type="button" disabled title="Warmup schedule is not connected" aria-label="Warmup schedule unavailable"><i class="fas fa-calendar-alt"></i></button>
                            <button class="btn btn-sm btn-link" type="button" disabled title="No Warmup server process is attached" aria-label="Warmup PMTA unavailable"><i class="fas fa-server"></i></button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="text-center text-muted py-4">No saved Warmup drafts exist for this date range.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <small class="text-muted">Showing {{ $drafts->firstItem() ?? 0 }} to {{ $drafts->lastItem() ?? 0 }} of {{ $drafts->total() }} warmups in the selected date range.</small>
            {{ $drafts->links() }}
        </div>
    </div>
</section>
@endsection
