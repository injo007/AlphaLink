@extends('layouts.admin-shell')

@section('title', 'Audience Lists')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Header Card -->
        <header class="command-os-report-hero mb-3" aria-labelledby="audience-hero-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas fa-users"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas fa-users"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">AUDIENCE INTELLIGENCE // PERMISSIONED REGISTRY</p>
                <h1 id="audience-hero-title">AUDIENCE LISTS</h1>
                <p>Membership totals and telemetry event history, scoped to this workspace.</p>
            </div>
            <div class="command-os-hero-actions">
                <div class="command-os-report-hero-range">
                    <span>SECURITY</span>
                    <strong>READ ONLY · NO EXPORTS</strong>
                </div>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </header>

        <!-- 2. Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                <h4 class="font-w700 text-uppercase font-size-sm mb-0">
                    <i class="fas fa-address-book mr-2 text-purple"></i> Audience Register
                    <span class="badge-command-pill ml-2">{{ number_format($audienceLists->total()) }} Records</span>
                </h4>
            </div>

            @if ($audienceLists->isEmpty())
                <div class="command-table-empty-state py-5 text-center">
                    <div class="command-empty-state-icon mb-3" style="font-size: 2.2rem; color: #8b5cf6; opacity: 0.85;">
                        <i class="fas fa-users"></i>
                    </div>
                    <h4 class="font-w600 text-ink mb-1">No Audience Lists Yet</h4>
                    <p class="text-muted font-size-sm mb-0">Lists will appear here when they are created through the infrastructure or API workflows.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table command-os-table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th scope="col">LIST</th>
                                <th scope="col" class="text-center">STATUS</th>
                                <th scope="col" class="text-right">ACTIVE MEMBERS</th>
                                <th scope="col" class="text-right">OPEN</th>
                                <th scope="col" class="text-right">CLICK</th>
                                <th scope="col" class="text-right">LEAD</th>
                                <th scope="col" class="text-right">UNSUB</th>
                                <th scope="col">CREATED</th>
                                <th scope="col" class="text-right"><span class="sr-only">Open</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($audienceLists as $audienceList)
                                <tr>
                                    <td>
                                        <a class="font-w700 text-purple" href="{{ route('console.audiences.show', [$workspace->id, $audienceList->id]) }}" style="font-size: 13px;">
                                            {{ $audienceList->name }}
                                        </a>
                                        <small class="d-block text-muted font-mono" style="font-size: 11px;">{{ $audienceList->slug }}</small>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge-command-pill {{ $audienceList->status === 'active' ? 'status-ready' : '' }}">
                                            {{ strtoupper(str_replace('_', ' ', $audienceList->status)) }}
                                        </span>
                                    </td>
                                    <td class="text-right font-mono font-w700 text-purple" style="font-size: 12px;">{{ number_format($audienceList->active_members_count) }}</td>
                                    <td class="text-right font-mono font-size-xs">{{ $audienceList->history->exists ? number_format((int) $audienceList->history->open) : '—' }}</td>
                                    <td class="text-right font-mono font-size-xs">{{ $audienceList->history->exists ? number_format((int) $audienceList->history->click) : '—' }}</td>
                                    <td class="text-right font-mono font-size-xs">{{ $audienceList->history->exists ? number_format((int) $audienceList->history->lead) : '—' }}</td>
                                    <td class="text-right font-mono font-size-xs">{{ $audienceList->history->exists ? number_format((int) $audienceList->history->unsubscribe) : '—' }}</td>
                                    <td><span class="text-muted font-mono" style="font-size: 11px;">{{ $audienceList->created_at?->format('M j, Y · H:i') }}</span></td>
                                    <td class="text-right">
                                        <a class="btn-command-action" style="padding: 4px 8px; font-size: 11px;" href="{{ route('console.audiences.show', [$workspace->id, $audienceList->id]) }}" aria-label="View {{ $audienceList->name }}">
                                            <i class="fas fa-arrow-right"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="command-table-footer d-flex flex-wrap align-items-center justify-content-between p-3 border-top">
                    <span class="text-muted font-size-xs">Page {{ $audienceLists->currentPage() }} of {{ $audienceLists->lastPage() }}</span>
                    <div>{{ $audienceLists->links() }}</div>
                </div>
            @endif

            <div class="command-os-info-banner mt-3 py-2 px-3 font-size-xs" role="note">
                <div class="command-os-info-banner-icon"><i class="fas fa-shield-alt"></i></div>
                <div class="command-os-info-banner-content text-muted">
                    <strong>Privacy note:</strong> this view does not display email addresses or provide an export action. Segment columns show the latest saved membership-state snapshot; they are not event totals.
                </div>
            </div>
        </div>
    </div>
@endsection
