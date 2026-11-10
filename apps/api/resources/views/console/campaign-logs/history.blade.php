@extends('layouts.admin-shell')

@section('title', 'Campaign '.$history.' history')

@push('styles')
    <style>
        .campaign-history-page { --logs-border: var(--co-border, #e1def4); --logs-ink: var(--co-ink, #1e214d); --logs-muted: var(--co-muted, #64709b); --logs-surface: var(--co-surface, #fff); color: var(--logs-ink); }
        .campaign-history-header { align-items: center; background: var(--logs-surface); border-bottom: 1px solid var(--logs-border); display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; padding: 1.2rem 1.4rem; }
        .campaign-history-heading { align-items: center; display: flex; gap: .85rem; }
        .campaign-history-heading h1 { color: var(--logs-ink); font-size: clamp(1.15rem, 2vw, 1.5rem); font-weight: 750; margin: 0; }
        .campaign-history-heading p { color: var(--logs-muted); margin: .25rem 0 0; }
        .campaign-history-eyebrow { color: var(--co-purple, #7a3cff); display: inline-block; font-size: .65rem; font-weight: 800; letter-spacing: .12em; margin-bottom: .22rem; }
        .campaign-history-icon { align-items: center; background: color-mix(in srgb, var(--co-purple, #7a3cff) 12%, var(--logs-surface)); border: 1px solid color-mix(in srgb, var(--co-purple, #7a3cff) 24%, var(--logs-border)); border-radius: .85rem; color: var(--co-purple, #7a3cff); display: inline-flex; flex: 0 0 2.75rem; height: 2.75rem; justify-content: center; }
        .campaign-history-tabs { border-bottom: 1px solid var(--logs-border); display: flex; flex-wrap: wrap; gap: .45rem; padding: .9rem 1.25rem 0; }
        .campaign-history-tabs a { border: 1px solid transparent; border-radius: .65rem .65rem 0 0; color: var(--logs-muted); font-weight: 700; padding: .6rem .8rem; text-decoration: none; }
        .campaign-history-tabs a:hover { background: color-mix(in srgb, var(--co-purple, #7a3cff) 7%, var(--logs-surface)); color: var(--logs-ink); }
        .campaign-history-tabs a[aria-current="page"] { background: color-mix(in srgb, var(--co-purple, #7a3cff) 9%, var(--logs-surface)); border-color: var(--logs-border); border-bottom-color: color-mix(in srgb, var(--co-purple, #7a3cff) 9%, var(--logs-surface)); color: var(--co-purple, #7a3cff); margin-bottom: -1px; }
        .campaign-history-note { background: color-mix(in srgb, var(--co-blue, #4888f4) 7%, var(--logs-surface)); border: 1px solid color-mix(in srgb, var(--co-blue, #4888f4) 20%, var(--logs-border)); border-radius: .65rem; color: var(--logs-muted); margin: 1rem 1.25rem; padding: .75rem .9rem; }
        .campaign-history-table-wrap { overflow-x: auto; }
        .campaign-history-table { margin: 0; min-width: 720px; }
        .campaign-history-table thead th { background: color-mix(in srgb, var(--logs-surface) 82%, var(--co-page, #f4f3fb)); border-bottom: 1px solid var(--logs-border); color: var(--logs-muted); font-size: .7rem; font-weight: 750; letter-spacing: .06em; padding: .75rem .85rem; text-transform: uppercase; white-space: nowrap; }
        .campaign-history-table td { border-top: 1px solid var(--logs-border); color: var(--logs-ink); padding: .78rem .85rem; vertical-align: middle; }
        .campaign-history-empty { color: var(--logs-muted) !important; padding: 3rem 1rem !important; text-align: center; }
        .campaign-history-footer { align-items: center; color: var(--logs-muted); display: flex; flex-wrap: wrap; gap: .75rem; justify-content: space-between; padding: 1rem 1.25rem; }
        .campaign-history-footer .pagination { margin: 0; }
        .campaign-history-code { font-family: var(--font-mono, ui-monospace, monospace); font-size: .78rem; }
        @media (max-width: 640px) { .campaign-history-header { align-items: flex-start; padding: 1rem; } .campaign-history-note { margin: .8rem; } .campaign-history-footer { align-items: flex-start; flex-direction: column; padding: .85rem; } }
    </style>
@endpush

@section('content')
    @php
        $historyTitle = ['tests' => 'Test history', 'updates' => 'Update history', 'sends' => 'Send history'][$history];
        $historyIcon = ['tests' => 'fa-vial', 'updates' => 'fa-pen-to-square', 'sends' => 'fa-paper-plane'][$history];
    @endphp
    <section class="block block-rounded campaign-history-page" aria-labelledby="campaign-history-title">
        <header class="campaign-history-header">
            <div class="campaign-history-heading">
                <span class="campaign-history-icon" aria-hidden="true"><i class="fas {{ $historyIcon }}"></i></span>
                <div>
                    <span class="campaign-history-eyebrow">CAMPAIGN LOGS // {{ strtoupper($history) }}</span>
                    <h1 id="campaign-history-title">{{ $historyTitle }}</h1>
                    <p>{{ $campaign->name }} <span class="campaign-history-code">· {{ strtoupper(substr((string) $campaign->id, 0, 8)) }}</span></p>
                </div>
            </div>
            <a class="btn btn-sm btn-alt-secondary" href="{{ route('console.campaign-logs.index', $workspace->id) }}"><i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>Campaign Logs</a>
        </header>

        <nav class="campaign-history-tabs" aria-label="Campaign history type">
            @foreach (['tests' => 'Tests', 'updates' => 'Updates', 'sends' => 'Sends'] as $key => $label)
                <a href="{{ route('console.campaign-logs.history', [$workspace->id, $campaign->id, $key]) }}" @if ($history === $key) aria-current="page" @endif>{{ $label }} <span class="badge badge-light">{{ $counts[$key] }}</span></a>
            @endforeach
        </nav>

        <p class="campaign-history-note"><i class="fas fa-shield-alt mr-1" aria-hidden="true"></i>This register shows campaign activity summaries. Recipient addresses and authored message bodies are not displayed here.</p>

        <div class="campaign-history-table-wrap">
            <table class="table table-sm campaign-history-table" aria-label="{{ $historyTitle }} for {{ $campaign->name }}">
                @if ($history === 'tests')
                    <thead><tr><th scope="col">Type</th><th scope="col">Outcome</th><th scope="col">Recorded by</th><th scope="col">Created at</th><th scope="col">Configuration</th></tr></thead>
                    <tbody>
                        @forelse ($historyRows as $row)
                            @php $detailUrl = route('console.campaign-logs.history-detail', [$workspace->id, $campaign->id, 'tests', $row->id]).'?'.http_build_query(['source' => $row->test_source_key]); @endphp
                            <tr><td>{{ $row->test_source }}</td><td><span class="badge badge-{{ in_array($row->status, ['accepted_by_transport', 'sent'], true) ? 'success' : (in_array($row->status, ['rejected_by_transport', 'failed'], true) ? 'danger' : 'secondary') }}">{{ strtoupper(str_replace('_', ' ', $row->status)) }}</span></td><td>{{ $row->actor_label }}</td><td class="text-nowrap">{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('Y-m-d H:i:s') }}</td><td><a class="btn btn-sm btn-alt-primary" href="{{ $detailUrl }}">View config</a></td></tr>
                        @empty
                            <tr><td colspan="5" class="campaign-history-empty">No test activity is recorded for this campaign.</td></tr>
                        @endforelse
                    </tbody>
                @elseif ($history === 'updates')
                    <thead><tr><th scope="col">Revision</th><th scope="col">Content fingerprint</th><th scope="col">Updated by</th><th scope="col">Created at</th><th scope="col">Configuration</th></tr></thead>
                    <tbody>
                        @forelse ($historyRows as $row)
                            <tr><td>Revision {{ $row->revision_number }}</td><td class="campaign-history-code">{{ substr($row->content_sha256, 0, 12) }}</td><td>{{ $row->actor_label }}</td><td class="text-nowrap">{{ $row->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td><td><a class="btn btn-sm btn-alt-primary" href="{{ route('console.campaign-logs.history-detail', [$workspace->id, $campaign->id, 'updates', $row->id]) }}">View config</a></td></tr>
                        @empty
                            <tr><td colspan="5" class="campaign-history-empty">No campaign updates are recorded.</td></tr>
                        @endforelse
                    </tbody>
                @else
                    <thead><tr><th scope="col">Send type</th><th scope="col">Status</th><th scope="col">Accepted</th><th scope="col">Rejected</th><th scope="col">Suppressed</th><th scope="col">Uncertain</th><th scope="col">Requested by</th><th scope="col">Started at</th><th scope="col">Configuration</th></tr></thead>
                    <tbody>
                        @forelse ($historyRows as $row)
                            <tr><td>{{ strtoupper($row->send_type) }}</td><td><span class="badge badge-{{ in_array($row->state, ['completed', 'running'], true) ? 'success' : (in_array($row->state, ['failed', 'needs_review'], true) ? 'warning' : 'secondary') }}">{{ strtoupper(str_replace('_', ' ', $row->state)) }}</span></td><td>{{ number_format((int) $row->accepted_count) }}</td><td>{{ number_format((int) $row->rejected_count) }}</td><td>{{ number_format((int) $row->suppressed_count) }}</td><td>{{ number_format((int) $row->uncertain_count) }}</td><td>{{ $row->actor_label }}</td><td class="text-nowrap">{{ $row->started_at?->format('Y-m-d H:i:s') ?? '—' }}</td><td><a class="btn btn-sm btn-alt-primary" href="{{ route('console.campaign-logs.history-detail', [$workspace->id, $campaign->id, 'sends', $row->id]) }}">View config</a></td></tr>
                        @empty
                            <tr><td colspan="9" class="campaign-history-empty">No send runs are recorded for this campaign.</td></tr>
                        @endforelse
                    </tbody>
                @endif
            </table>
        </div>

        <footer class="campaign-history-footer">
            <span>Showing {{ $historyRows->count() }} of {{ $historyRows->total() }} records</span>
            {{ $historyRows->links() }}
        </footer>
    </section>
@endsection
