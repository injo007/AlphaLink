@extends('layouts.console')

@section('title', 'Overview')

@section('content')
    <div class="page-heading overview-heading">
        <div>
            <p class="eyebrow page-eyebrow"><span class="eyebrow-square" aria-hidden="true"></span> WORKSPACE OVERVIEW</p>
            <h1>{{ $workspace->name }}<span class="heading-period">.</span></h1>
            <p class="page-lede">A read-only view of audience and campaign records in this workspace.</p>
        </div>
        <div class="heading-side-note"><span class="note-rule"></span><span>ACCESS LEVEL<br><strong>{{ str_replace('_', ' ', $role) }}</strong></span></div>
    </div>

    <div class="metric-grid" aria-label="Workspace totals">
        <article class="metric-card metric-card-primary">
            <div class="metric-topline"><span class="metric-label">ACTIVE CONTACTS</span><span class="metric-symbol" aria-hidden="true">◎</span></div>
            <p class="metric-value">{{ $contactCount === null ? '—' : number_format($contactCount) }}</p>
            <p class="metric-caption">{{ $contactCount === null ? 'Not available to your role' : 'Current workspace records' }}</p>
        </article>
        <article class="metric-card">
            <div class="metric-topline"><span class="metric-label">AUDIENCE LISTS</span><span class="metric-symbol" aria-hidden="true">▤</span></div>
            <p class="metric-value">{{ $audienceListCount === null ? '—' : number_format($audienceListCount) }}</p>
            <p class="metric-caption">{{ $canViewAudiences ? 'Lists available to your role' : 'Not available to your role' }}</p>
        </article>
        <article class="metric-card">
            <div class="metric-topline"><span class="metric-label">CAMPAIGNS</span><span class="metric-symbol" aria-hidden="true">↗</span></div>
            <p class="metric-value">{{ $campaignCount === null ? '—' : number_format($campaignCount) }}</p>
            <p class="metric-caption">{{ $canViewCampaigns ? 'Draft and version records' : 'Not available to your role' }}</p>
        </article>
    </div>

    <div class="section-grid">
        <section class="data-panel" aria-labelledby="campaigns-title">
            <div class="panel-header">
                <div><p class="eyebrow panel-eyebrow">CONTENT REGISTER</p><h2 id="campaigns-title">Recent campaigns</h2></div>
                @if ($canViewCampaigns)<a class="text-link" href="{{ route('console.campaigns.index', $workspace->id) }}">All campaigns <span aria-hidden="true">↗</span></a>@endif
            </div>
            @if (! $canViewCampaigns)
                <div class="permission-note"><span class="permission-lock" aria-hidden="true">⌑</span><div><strong>Campaign access is role-scoped.</strong><p>Your current workspace role does not include campaign review.</p></div></div>
            @elseif ($campaigns->isEmpty())
                <div class="empty-state"><span class="empty-state-mark" aria-hidden="true">—</span><strong>No campaign records yet</strong><p>Campaign drafts and their revisions will appear here once they exist.</p></div>
            @else
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th scope="col">CAMPAIGN</th><th scope="col">TYPE</th><th scope="col">STATUS</th><th scope="col">REV.</th><th scope="col"><span class="visually-hidden">Open</span></th></tr></thead>
                        <tbody>
                            @foreach ($campaigns as $campaign)
                                <tr>
                                    <td><a class="record-link" href="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}">{{ $campaign->name }}</a><small>{{ $campaign->created_at?->format('M j, Y') }}</small></td>
                                    <td><span class="type-label">{{ str_replace('_', ' ', $campaign->kind) }}</span></td>
                                    <td><span class="status-pill status-{{ $campaign->status === 'draft' ? 'draft' : 'neutral' }}">{{ str_replace('_', ' ', $campaign->status) }}</span></td>
                                    <td class="numeric-cell">{{ $campaign->latestRevision?->revision_number ?? '—' }}</td>
                                    <td><a class="row-arrow" href="{{ route('console.campaigns.show', [$workspace->id, $campaign->id]) }}" aria-label="View {{ $campaign->name }}">↗</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="data-panel" aria-labelledby="audiences-title">
            <div class="panel-header">
                <div><p class="eyebrow panel-eyebrow">PERMISSIONED AUDIENCES</p><h2 id="audiences-title">Audience lists</h2></div>
                @if ($canViewAudiences)<a class="text-link" href="{{ route('console.audiences.index', $workspace->id) }}">All lists <span aria-hidden="true">↗</span></a>@endif
            </div>
            @if (! $canViewAudiences)
                <div class="permission-note"><span class="permission-lock" aria-hidden="true">⌑</span><div><strong>Audience access is role-scoped.</strong><p>Your current workspace role does not include list review.</p></div></div>
            @elseif ($audienceLists->isEmpty())
                <div class="empty-state"><span class="empty-state-mark" aria-hidden="true">—</span><strong>No audience lists yet</strong><p>Workspace lists and their active membership counts will appear here.</p></div>
            @else
                <div class="list-stack">
                    @foreach ($audienceLists as $audienceList)
                        <a class="audience-row" href="{{ route('console.audiences.show', [$workspace->id, $audienceList->id]) }}">
                            <span class="list-glyph" aria-hidden="true">▤</span>
                            <span class="audience-copy"><strong>{{ $audienceList->name }}</strong><small>{{ $audienceList->slug }} <span aria-hidden="true">·</span> {{ $audienceList->status }}</small></span>
                            <span class="audience-count"><strong>{{ number_format($audienceList->active_members_count) }}</strong><small>ACTIVE</small></span>
                            <span class="row-arrow" aria-hidden="true">↗</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <section class="integrity-strip" aria-label="Data scope">
        <span class="integrity-icon" aria-hidden="true">✓</span>
        <p><strong>Workspace-scoped view.</strong> Counts come from current records; campaign totals do not represent sends or deliveries.</p>
        <span class="integrity-code">ALPHA / W</span>
    </section>
@endsection
