@extends('layouts.console')

@section('title', $campaign->name)

@section('content')
    <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="{{ route('console.workspace', $workspace->id) }}">Overview</a><span aria-hidden="true">/</span><a href="{{ route('console.campaigns.index', $workspace->id) }}">Campaigns</a><span aria-hidden="true">/</span><span aria-current="page">{{ $campaign->name }}</span></nav>
    <div class="page-heading compact-heading">
        <div><p class="eyebrow page-eyebrow"><span class="eyebrow-square" aria-hidden="true"></span> CAMPAIGN RECORD</p><h1>{{ $campaign->name }}<span class="heading-period">.</span></h1><p class="page-lede">{{ str_replace('_', ' ', $campaign->kind) }} <span class="lede-dot">·</span> created {{ $campaign->created_at?->format('M j, Y') }}</p></div>
        <span class="status-pill status-{{ $campaign->status === 'draft' ? 'draft' : 'neutral' }}">{{ str_replace('_', ' ', $campaign->status) }}</span>
    </div>

    <div class="detail-grid">
        <section class="data-panel full-panel" aria-labelledby="revision-title">
            <div class="panel-header"><div><p class="eyebrow panel-eyebrow">IMMUTABLE HISTORY</p><h2 id="revision-title">Content revisions</h2></div><span class="panel-meta">{{ $revisions->total() }} {{ $revisions->total() === 1 ? 'revision' : 'revisions' }}</span></div>
            @if ($revisions->isEmpty())
                <div class="empty-state"><span class="empty-state-mark" aria-hidden="true">—</span><strong>No content revision is attached</strong><p>The campaign record exists, but it does not yet contain a saved content version.</p></div>
            @else
                <div class="table-scroll">
                    <table class="data-table data-table-roomy">
                        <thead><tr><th scope="col">VERSION</th><th scope="col">CONTENT FINGERPRINT</th><th scope="col">SAVED</th></tr></thead>
                        <tbody>@foreach ($revisions as $revision)
                            <tr>
                                <td><span class="revision-number">R{{ str_pad((string) $revision->revision_number, 2, '0', STR_PAD_LEFT) }}</span><span class="current-badge">{{ $loop->first && $revisions->currentPage() === 1 ? 'LATEST' : '' }}</span></td>
                                <td><code class="fingerprint">{{ $revision->content_sha256 }}</code></td>
                                <td class="date-cell">{{ $revision->created_at?->format('M j, Y · H:i') }}</td>
                            </tr>
                        @endforeach</tbody>
                    </table>
                </div>
                <div class="pagination-wrap">{{ $revisions->links() }}</div>
            @endif
        </section>

        <aside class="detail-note"><span class="detail-note-index">01 / RECORD NOTE</span><h2>Versioned content.<br><em>No delivery state.</em></h2><p>The revision fingerprint identifies saved content. It does not indicate review approval, transport submission, provider acceptance, or delivery.</p><a class="text-link" href="{{ route('console.campaigns.index', $workspace->id) }}">Back to campaigns <span aria-hidden="true">↗</span></a></aside>
    </div>
@endsection
