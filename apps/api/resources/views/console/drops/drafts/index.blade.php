@extends('layouts.admin-shell')

@section('title', 'Drop Drafts')

@section('content')
<section class="block block-rounded" id="drop-draft-register">
    <div class="block-header">
        <h3 class="block-title">My Drop Drafts</h3>
        <div class="block-options">
            <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle fullscreen"></button>
        </div>
    </div>
    <div class="block-content block-content-full">
        <form id="drop-draft-filter-form" method="get" action="{{ route('console.drops.drafts.index', $workspace->id) }}" class="form-inline mb-2 drop-draft-register-filters" aria-label="Filter Drop drafts">
            <div class="form-group mr-2 mb-2">
                <label class="sr-only" for="drop-draft-per-page">Rows per page</label>
                <select id="drop-draft-per-page" name="per_page" class="form-control form-control-sm" aria-label="Rows per page">
                    <option value="25" @selected($perPage === 25)>Show 25 rows</option>
                    <option value="50" @selected($perPage === 50)>Show 50 rows</option>
                    <option value="100" @selected($perPage === 100)>Show 100 rows</option>
                </select>
            </div>
            <a class="btn btn-sm btn-alt-secondary mr-1 mb-2" href="{{ $createUrl }}" title="New Drop draft" aria-label="Create Drop draft"><i class="fas fa-plus" aria-hidden="true"></i></a>
            <button class="btn btn-sm btn-alt-secondary mb-2" type="submit" title="Refresh Drop drafts" aria-label="Refresh Drop drafts"><i class="fas fa-sync" aria-hidden="true"></i></button>
            <div class="ml-auto d-flex align-items-center drop-draft-register-search">
                <label class="sr-only" for="drop-draft-search">Search Drop drafts</label>
                <input class="form-control form-control-sm" type="search" id="drop-draft-search" name="q" value="{{ $query }}" placeholder="Search...">
                <button class="btn btn-sm btn-alt-primary ml-1 mb-2" type="submit" aria-label="Search Drop drafts"><i class="fas fa-search" aria-hidden="true"></i></button>
            </div>
        </form>

        <div class="table-responsive">
            <table id="drop-drafts" class="table table-sm table-hover drop-draft-register-table">
                <caption class="sr-only">Saved Drop drafts created by you</caption>
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">ISP</th>
                        <th scope="col">LISTS</th>
                        <th scope="col">COUNTRY</th>
                        <th scope="col">TOTAL SEND</th>
                        <th scope="col">SEND TYPE</th>
                        <th scope="col">STATUS</th>
                        <th scope="col">PROGRESS</th>
                        <th scope="col">UPDATED</th>
                        <th scope="col">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($drafts as $draft)
                    @php($config = $draft->drop_config ?? [])
                    @php($sendRun = $draft->drop_send_run)
                    @php($processed = $sendRun ? (int) $sendRun->accepted_count + (int) $sendRun->rejected_count + (int) $sendRun->suppressed_count + (int) $sendRun->uncertain_count : 0)
                    <tr>
                        <th scope="row"><a href="{{ route('console.drops.drafts.edit', [$workspace->id, $draft->id]) }}">{{ substr((string) $draft->id, 0, 8) }}</a><br><small>{{ $draft->name }}</small></th>
                        <td>{{ $draft->drop_isp_name }}</td>
                        <td>{{ number_format((int) $draft->drop_list_count) }}</td>
                        <td>{{ $draft->drop_countries !== '' ? $draft->drop_countries : 'ALL' }}</td>
                        <td>{{ $config['total_send'] ?? '—' }}</td>
                        <td>{{ strtoupper((string) ($config['send_type'] ?? '—')) }}</td>
                        <td><span class="badge badge-{{ $draft->status === 'preparing_snapshot' ? 'primary' : ($draft->status === 'snapshot_failed' ? 'danger' : ($sendRun ? (in_array($sendRun->state, ['planned', 'running'], true) ? 'primary' : ($sendRun->state === 'completed' ? 'success' : 'warning')) : 'secondary')) }}">{{ $draft->status === 'preparing_snapshot' ? 'PREPARING AUDIENCE' : ($draft->status === 'snapshot_failed' ? 'PREPARATION FAILED' : strtoupper($sendRun->state ?? 'draft')) }}</span>@if ($draft->status === 'snapshot_failed' && $draft->snapshot_build_error)<br><small class="text-danger">{{ $draft->snapshot_build_error }}</small>@endif</td>
                        <td>@if ($sendRun){{ number_format($processed) }} / {{ number_format((int) $sendRun->recipient_count) }} · accepted {{ number_format((int) $sendRun->accepted_count) }} · rejected {{ number_format((int) $sendRun->rejected_count) }} · suppressed {{ number_format((int) $sendRun->suppressed_count) }} · uncertain {{ number_format((int) $sendRun->uncertain_count) }}@elseif ($draft->status === 'preparing_snapshot'){{ $draft->snapshot_build_stage ?? 'queued' }} · {{ (int) $draft->snapshot_build_percent }}%@else<span class="badge badge-secondary">—</span>@endif</td>
                        <td>{{ $draft->updated_at?->format('Y-m-d H:i') }}</td>
                        <td class="text-nowrap drop-draft-register-actions">
                            <a class="btn btn-sm btn-link" href="{{ route('console.drops.drafts.edit', [$workspace->id, $draft->id]) }}" title="Edit Drop draft" aria-label="Edit {{ $draft->name }}"><i class="fas fa-edit" aria-hidden="true"></i></a>
                            <a class="btn btn-sm btn-link text-warning" href="{{ route('console.drops.drafts.edit', [$workspace->id, $draft->id]) }}" title="Open Drop composer to test saved Email Test addresses" aria-label="Test Drop"><i class="far fa-paper-plane" aria-hidden="true"></i></a>
                            @if ($sendRun && in_array($sendRun->state, ['planned', 'running'], true))
                                <form class="d-inline" method="post" action="{{ route('console.drops.send-stop', [$workspace->id, $draft->id, $sendRun->id]) }}">@csrf<button class="btn btn-sm btn-link text-danger" type="submit" title="Stop Drop send" aria-label="Stop Drop send"><i class="fas fa-stop" aria-hidden="true"></i></button></form>
                            @elseif ($draft->status !== 'preparing_snapshot')
                                <form class="d-inline" method="post" action="{{ route('console.drops.send', [$workspace->id, $draft->id]) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><button class="btn btn-sm btn-link text-success" type="submit" title="Queue Drop send" aria-label="Send Drop"><i class="fas fa-paper-plane" aria-hidden="true"></i></button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted">No Drop drafts found. Create a draft to get started.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center drop-draft-register-footer">
            <small class="text-muted">Showing {{ $drafts->firstItem() ?? 0 }} to {{ $drafts->lastItem() ?? 0 }} of {{ $drafts->total() }} drafts.</small>
            {{ $drafts->links() }}
        </div>
        <p class="sr-only" role="note">Drop sends build a suppression-checked recipient snapshot and start in the background after PMTA route validation. Tests go only to saved Email Test addresses.</p>
    </div>
</section>
@endsection

@push('styles')
    <style>
        .drop-draft-register-table { font-size: .8rem; min-width: 900px; }
        .drop-draft-register-table th { border-bottom: 1px solid #111; white-space: nowrap; }
        .drop-draft-register-table td { vertical-align: middle; }
        .drop-draft-register-actions .btn[disabled] { cursor: not-allowed; opacity: .55; }
        .drop-draft-register-search input[type="search"] { max-width: 12rem; }
        @media (max-width: 767.98px) {
            .drop-draft-register-filters { align-items: stretch; }
            .drop-draft-register-search { margin-left: 0 !important; width: 100%; }
            .drop-draft-register-search input[type="search"] { max-width: none; width: 100%; }
        }
    </style>
@endpush
