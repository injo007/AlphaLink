@extends('layouts.console')

@section('title', 'Audience lists')

@section('content')
    <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="{{ route('console.workspace', $workspace->id) }}">Overview</a><span aria-hidden="true">/</span><span aria-current="page">Audience lists</span></nav>
    <div class="page-heading compact-heading">
        <div><p class="eyebrow page-eyebrow"><span class="eyebrow-square" aria-hidden="true"></span> PERMISSIONED AUDIENCES</p><h1>Audience lists<span class="heading-period">.</span></h1><p class="page-lede">Membership totals and event history, scoped to this workspace.</p></div>
        <span class="read-only-stamp">READ ONLY <span aria-hidden="true">·</span> NO EXPORTS</span>
    </div>

    <section class="data-panel full-panel" aria-labelledby="audience-list-title">
        <div class="panel-header"><div><p class="eyebrow panel-eyebrow">{{ number_format($audienceLists->total()) }} RECORDS</p><h2 id="audience-list-title">Audience register</h2></div><span class="panel-meta">Active memberships shown</span></div>
        @if ($audienceLists->isEmpty())
            <div class="empty-state empty-large"><span class="empty-state-mark" aria-hidden="true">—</span><strong>No audience lists yet</strong><p>Lists will appear here when they are created through the API.</p></div>
        @else
            <div class="table-scroll">
                <table class="data-table data-table-roomy">
                    <thead><tr><th scope="col">LIST</th><th scope="col">STATUS</th><th scope="col">ACTIVE MEMBERS</th><th scope="col">CREATED</th><th scope="col"><span class="visually-hidden">Open</span></th></tr></thead>
                    <tbody>@foreach ($audienceLists as $audienceList)
                        <tr>
                            <td><a class="record-link" href="{{ route('console.audiences.show', [$workspace->id, $audienceList->id]) }}">{{ $audienceList->name }}</a><small>{{ $audienceList->slug }}</small></td>
                            <td><span class="status-pill status-{{ $audienceList->status === 'active' ? 'active' : 'neutral' }}">{{ str_replace('_', ' ', $audienceList->status) }}</span></td>
                            <td class="numeric-cell">{{ number_format($audienceList->active_members_count) }}</td>
                            <td class="date-cell">{{ $audienceList->created_at?->format('M j, Y · H:i') }}</td>
                            <td><a class="row-arrow" href="{{ route('console.audiences.show', [$workspace->id, $audienceList->id]) }}" aria-label="View {{ $audienceList->name }}">↗</a></td>
                        </tr>
                    @endforeach</tbody>
                </table>
            </div>
            <div class="pagination-wrap">{{ $audienceLists->links() }}</div>
        @endif
    </section>

    <aside class="quiet-callout"><span class="callout-mark" aria-hidden="true">i</span><p><strong>Privacy note:</strong> this view does not display email addresses or provide an export action. Member counts reflect active membership rows only.</p></aside>
@endsection
