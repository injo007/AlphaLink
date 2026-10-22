@extends('layouts.console')

@section('title', 'Campaigns')

@section('content')
    <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="{{ route('console.workspace', $workspace->id) }}">Overview</a><span aria-hidden="true">/</span><span aria-current="page">Campaigns</span></nav>
    <div class="page-heading compact-heading">
        <div><p class="eyebrow page-eyebrow"><span class="eyebrow-square" aria-hidden="true"></span> CONTENT REGISTER</p><h1>Campaigns<span class="heading-period">.</span></h1><p class="page-lede">Draft records and immutable content revisions for this workspace.</p></div>
        <span class="read-only-stamp">READ ONLY <span aria-hidden="true">·</span> NO SENDS</span>
    </div>

    <section class="data-panel full-panel" aria-labelledby="campaign-list-title">
        <div class="panel-header"><div><p class="eyebrow panel-eyebrow">{{ number_format($campaigns->total()) }} RECORDS</p><h2 id="campaign-list-title">Campaign register</h2></div><span class="panel-meta">Most recently created first</span></div>
        @if ($campaigns->isEmpty())
            <div class="empty-state empty-large"><span class="empty-state-mark" aria-hidden="true">—</span><strong>No campaign records yet</strong><p>Campaign records will appear here when they are created through the API.</p></div>
        @else
            <div class="table-scroll">
                <table class="data-table data-table-roomy">
                    <thead><tr><th scope="col">CAMPAIGN</th><th scope="col">TYPE</th><th scope="col">STATUS</th><th scope="col">LATEST REVISION</th><th scope="col">CREATED</th><th scope="col"><span class="visually-hidden">Open</span></th></tr></thead>
                    <tbody>@foreach ($campaigns as $campaign)
                        <tr>
                            <td><a class="record-link" href="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}">{{ $campaign->name }}</a></td>
                            <td><span class="type-label">{{ str_replace('_', ' ', $campaign->kind) }}</span></td>
                            <td><span class="status-pill status-{{ $campaign->status === 'draft' ? 'draft' : 'neutral' }}">{{ str_replace('_', ' ', $campaign->status) }}</span></td>
                            <td class="numeric-cell">{{ $campaign->latestRevision?->revision_number ?? '—' }}</td>
                            <td class="date-cell">{{ $campaign->created_at?->format('M j, Y · H:i') }}</td>
                            <td><a class="row-arrow" href="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}" aria-label="View {{ $campaign->name }}">↗</a></td>
                        </tr>
                    @endforeach</tbody>
                </table>
            </div>
            <div class="pagination-wrap">{{ $campaigns->links() }}</div>
        @endif
    </section>

    <aside class="quiet-callout"><span class="callout-mark" aria-hidden="true">i</span><p><strong>Operational boundary:</strong> AlphaLink has no scheduling, sending, or delivery-feedback path in this release. This register shows stored campaign records only.</p></aside>
@endsection
