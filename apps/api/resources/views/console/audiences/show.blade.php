@extends('layouts.admin-shell')

@section('title', $audienceList->name)

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Header Card -->
        <header class="command-os-report-hero mb-3" aria-labelledby="audience-show-hero-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas fa-address-book"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas fa-address-book"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">AUDIENCE INTELLIGENCE // LIST SNAPSHOT</p>
                <h1 id="audience-show-hero-title">{{ $audienceList->name }}</h1>
                <p>{{ $audienceList->slug }} <span class="mx-1">·</span> Created {{ $audienceList->created_at?->format('M j, Y') }}</p>
            </div>
            <div class="command-os-hero-actions">
                <a class="btn-command-action mr-2" href="{{ route('console.audiences.index', $workspace->id) }}">
                    <i class="fas fa-arrow-left mr-1"></i> AUDIENCE LISTS
                </a>
                <span class="badge-command-pill {{ $audienceList->status === 'active' ? 'status-ready' : '' }}">
                    {{ strtoupper(str_replace('_', ' ', $audienceList->status)) }}
                </span>
            </div>
        </header>

        <!-- 2. KPI / Summary Grid -->
        <div class="row mb-3" style="gap: 12px 0;">
            <div class="col-sm-12 col-md-4">
                <div class="command-os-table-card p-4 h-100">
                    <p class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                        <i class="fas fa-users mr-1 text-purple"></i> Current Membership:
                    </p>
                    <p class="font-w700 font-size-h3 text-purple font-mono mb-1">{{ number_format($audienceList->active_members_count) }}</p>
                    <small class="text-muted">Active contacts in this list partition</small>
                </div>
            </div>
            <div class="col-sm-12 col-md-8">
                <div class="command-os-table-card p-4 h-100">
                    <p class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                        <i class="fas fa-align-left mr-1 text-purple"></i> List Description:
                    </p>
                    <p class="font-size-sm mb-0" style="color: var(--command-os-text, #1e293b);">
                        {{ $audienceList->description ?: 'No description has been recorded for this audience list.' }}
                    </p>
                </div>
            </div>
        </div>

        <!-- 3. Membership Events Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                <h4 class="font-w700 text-uppercase font-size-sm mb-0">
                    <i class="fas fa-history mr-2 text-purple"></i> Append-Only Evidence // Recent Events
                </h4>
                <span class="badge-command-pill">Latest 10 Events</span>
            </div>

            @if ($membershipEvents->isEmpty())
                <div class="command-table-empty-state py-4 text-center">
                    <p class="text-muted font-size-sm mb-0">No membership events recorded yet.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table command-os-table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>EVENT</th>
                                <th>SOURCE</th>
                                <th class="text-right">TIMESTAMP</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($membershipEvents as $event)
                                <tr>
                                    <td>
                                        <span class="badge-command-pill {{ $event->action === 'added' ? 'status-ready' : '' }}">
                                            <i class="fas {{ $event->action === 'added' ? 'fa-plus-circle' : 'fa-minus-circle' }} mr-1"></i>
                                            {{ strtoupper($event->action) }} TO LIST
                                        </span>
                                    </td>
                                    <td>
                                        <span class="font-mono text-muted" style="font-size: 11.5px;">{{ str_replace('_', ' ', $event->source) }}</span>
                                    </td>
                                    <td class="text-right">
                                        <span class="font-mono text-muted" style="font-size: 11px;">{{ $event->occurred_at?->format('M j, Y · H:i:s') }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="command-os-info-banner mt-3 py-2 px-3 font-size-xs" role="note">
                <div class="command-os-info-banner-icon"><i class="fas fa-info-circle"></i></div>
                <div class="command-os-info-banner-content text-muted">
                    Addresses are intentionally omitted from this console view. Membership history is append-only evidence; current membership is a separate state.
                </div>
            </div>
        </div>
    </div>
@endsection
