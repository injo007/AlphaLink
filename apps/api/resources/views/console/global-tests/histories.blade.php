@extends('layouts.admin-shell')

@section('title', 'Global Test Histories')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-history"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">OPERATIONS TOOLKIT // TEST AUDIT TRAIL</div>
                    <h1 class="command-os-report-hero-title">GLOBAL TEST HISTORIES</h1>
                    <p class="command-os-report-hero-desc">Review dispatched deliverability tests, recipient sets, server allocations, and sender outputs.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <a class="btn-command-primary" href="{{ route('console.global-tests.index', $workspace->id) }}">
                        <i class="fas fa-vial mr-1" aria-hidden="true"></i> NEW GLOBAL TEST
                    </a>
                </div>
            </div>
        </header>

        <!-- 2. Table & Filter Surface Card -->
        <div class="command-os-table-card p-4">
            <form class="row align-items-end mb-4" method="get" action="{{ route('console.global-tests.histories', $workspace->id) }}">
                <div class="col-sm-6 col-md-3 mb-3 mb-md-0">
                    <label class="command-form-label" for="history-from"><i class="fas fa-calendar mr-1 text-purple"></i> FROM DATE:</label>
                    <input id="history-from" class="command-form-control" type="date" name="from" value="{{ $filters['from'] ?? '' }}" style="height: 38px;">
                </div>
                <div class="col-sm-6 col-md-3 mb-3 mb-md-0">
                    <label class="command-form-label" for="history-to"><i class="fas fa-calendar mr-1 text-purple"></i> TO DATE:</label>
                    <input id="history-to" class="command-form-control" type="date" name="to" value="{{ $filters['to'] ?? '' }}" style="height: 38px;">
                </div>
                <div class="col-sm-12 col-md-4 d-flex align-items-center" style="gap: 8px;">
                    <button class="btn-command-primary" type="submit" style="height: 38px; padding: 0 20px;">
                        <i class="fas fa-filter mr-1"></i> FILTER
                    </button>
                    <a class="btn-command-action" href="{{ route('console.global-tests.histories', $workspace->id) }}" style="height: 38px; line-height: 24px; padding: 0 16px;">
                        <i class="fas fa-times mr-1"></i> CLEAR
                    </a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped command-os-table">
                    <caption class="sr-only">Saved Global Test results for this workspace</caption>
                    <thead>
                        <tr>
                            <th scope="col">CREATED</th>
                            <th scope="col">RETURN PATH</th>
                            <th scope="col">EMAIL TEST</th>
                            <th scope="col">OPTION</th>
                            <th scope="col">SERVERS</th>
                            <th scope="col">ACTOR</th>
                            <th scope="col">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td><span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">{{ $log->created_at?->format('Y-m-d H:i:s') }}</span></td>
                                <td><code>{{ $log->data['return_path'] ?? '' }}</code></td>
                                <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ implode(', ', $log->data['email_tests'] ?? []) }}</td>
                                <td>
                                    <span class="badge-command-pill" style="color: #8b5cf6;">
                                        {{ strtoupper($log->data['mta'] ?? '') }} / {{ strtoupper($log->data['type'] ?? '') }}
                                    </span>
                                </td>
                                <td><span class="command-mono-badge">{{ count($log->data['servers'] ?? []) }}</span></td>
                                <td><strong>{{ $log->actor?->name ?: $log->actor?->email }}</strong></td>
                                <td>
                                    <a class="btn-command-action" style="padding: 3px 10px; font-size: 0.75rem;" href="{{ route('console.global-tests.history', [$workspace->id, $log->id]) }}">
                                        <i class="fas fa-eye mr-1" aria-hidden="true"></i> View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-5">No Global Test results have been saved for this period. A history row is saved only when sender output is returned.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap mt-3">
                <span class="text-muted small">{{ $logs->firstItem() ?? 0 }}–{{ $logs->lastItem() ?? 0 }} of {{ $logs->total() }} results</span>
                {{ $logs->links() }}
            </div>
        </div>
    </div>
@endsection
