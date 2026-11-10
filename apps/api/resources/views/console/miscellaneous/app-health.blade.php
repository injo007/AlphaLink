@extends('layouts.admin-shell')

@section('title', 'App Health')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-heartbeat"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">OPERATIONS TOOLKIT // SYSTEM DIAGNOSTICS</div>
                    <h1 class="command-os-report-hero-title">APP HEALTH MONITOR</h1>
                    <p class="command-os-report-hero-desc">Real-time diagnostics, database availability, storage thresholds, and internal services.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">
                        <i class="fas fa-history mr-1"></i> Last Run: {{ \Illuminate\Support\Carbon::parse($report['finished_at'])->diffForHumans() }}
                    </span>
                </div>
            </div>
        </header>

        <!-- 2. Health Results Card -->
        <div class="command-os-table-card p-4">
            <div class="table-responsive">
                <table class="table table-sm table-hover command-os-table">
                    <thead>
                        <tr>
                            <th>SYSTEM CHECK</th>
                            <th class="text-center">STATUS / SUMMARY</th>
                            <th class="d-none d-sm-table-cell">DIAGNOSTIC MESSAGE</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['stored_check_results'] as $result)
                            <tr>
                                <td class="font-w600">
                                    <i class="fas fa-check-circle mr-1 text-purple"></i> {{ $result['label'] }}
                                </td>
                                <td class="text-center">
                                    @if ($result['status'] === 'ok')
                                        <span class="badge-command-pill" style="color: #10b981; font-weight: 700;">
                                            <i class="fas fa-check mr-1"></i> {{ $result['shortSummary'] }}
                                        </span>
                                    @else
                                        <span class="badge-command-pill" style="color: #f59e0b; font-weight: 700;">
                                            <i class="fas fa-exclamation-triangle mr-1"></i> {{ $result['shortSummary'] }}
                                        </span>
                                    @endif
                                </td>
                                <td class="d-none d-sm-table-cell text-muted" style="font-size: 0.8125rem;">
                                    {{ $result['notificationMessage'] }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center py-4 text-muted">No diagnostic checks have been recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-muted small mt-3 mb-0"><i class="fas fa-shield-alt mr-1"></i> This view reads the latest cached diagnostic report. External notifications are not configured.</p>
        </div>
    </div>
@endsection
