@extends('layouts.console')

@section('title', $audienceList->name)

@section('content')
    <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="{{ route('console.workspace', $workspace->id) }}">Overview</a><span aria-hidden="true">/</span><a href="{{ route('console.audiences.index', $workspace->id) }}">Audience lists</a><span aria-hidden="true">/</span><span aria-current="page">{{ $audienceList->name }}</span></nav>
    <div class="page-heading compact-heading">
        <div><p class="eyebrow page-eyebrow"><span class="eyebrow-square" aria-hidden="true"></span> AUDIENCE REGISTER</p><h1>{{ $audienceList->name }}<span class="heading-period">.</span></h1><p class="page-lede">{{ $audienceList->slug }} <span class="lede-dot">·</span> created {{ $audienceList->created_at?->format('M j, Y') }}</p></div>
        <span class="status-pill status-{{ $audienceList->status === 'active' ? 'active' : 'neutral' }}">{{ str_replace('_', ' ', $audienceList->status) }}</span>
    </div>

    <div class="audience-summary-grid">
        <section class="data-panel audience-summary" aria-labelledby="membership-count-title">
            <p class="eyebrow panel-eyebrow">CURRENT MEMBERSHIP</p>
            <p class="summary-number" id="membership-count-title">{{ number_format($audienceList->active_members_count) }}</p>
            <p class="summary-caption">Active contacts in this list</p>
        </section>
        <section class="data-panel description-panel" aria-labelledby="list-description-title">
            <p class="eyebrow panel-eyebrow">LIST DESCRIPTION</p>
            <h2 class="visually-hidden" id="list-description-title">List description</h2>
            <p>{{ $audienceList->description ?: 'No description has been recorded for this audience list.' }}</p>
        </section>
    </div>

    <section class="data-panel full-panel event-panel" aria-labelledby="membership-history-title">
        <div class="panel-header"><div><p class="eyebrow panel-eyebrow">APPEND-ONLY EVIDENCE</p><h2 id="membership-history-title">Recent membership events</h2></div><span class="panel-meta">Latest 10 events</span></div>
        @if ($membershipEvents->isEmpty())
            <div class="empty-state"><span class="empty-state-mark" aria-hidden="true">—</span><strong>No membership events yet</strong><p>Membership history will appear here as event records are added.</p></div>
        @else
            <div class="event-list">
                @foreach ($membershipEvents as $event)
                    <article class="event-row">
                        <span class="event-marker {{ $event->action === 'added' ? 'event-marker-added' : 'event-marker-removed' }}" aria-hidden="true"></span>
                        <div class="event-copy"><strong>{{ ucfirst($event->action) }} to list</strong><small>Recorded from {{ str_replace('_', ' ', $event->source) }}</small></div>
                        <time class="event-time" datetime="{{ $event->occurred_at?->toIso8601String() }}">{{ $event->occurred_at?->format('M j, Y · H:i') }}</time>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
    <aside class="quiet-callout"><span class="callout-mark" aria-hidden="true">i</span><p>Addresses are intentionally omitted from this console view. Membership history is append-only evidence; current membership is a separate state.</p></aside>
@endsection
