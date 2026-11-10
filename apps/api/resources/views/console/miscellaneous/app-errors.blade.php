@extends('layouts.admin-shell')

@section('title', 'App Errors')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">OPERATIONS TOOLKIT // RUNTIME ERROR LOGS</div>
                    <h1 class="command-os-report-hero-title">APP ERROR MONITOR</h1>
                    <p class="command-os-report-hero-desc">Recent application runtime exceptions, stack origins, severity levels, and redacted error messages.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">
                        <i class="fas fa-bug mr-1"></i> {{ count($entries) }} Entries Recorded
                    </span>
                </div>
            </div>
        </header>

        <!-- 2. Error Table Card -->
        <div class="command-os-table-card p-4">
            @if ($entries === [])
                <div class="text-center py-5 text-muted">
                    <div style="font-size: 2.5rem; color: #10b981; margin-bottom: 12px;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h4 class="font-w700 mb-1" style="font-size: 1rem; color: var(--command-os-text, #1e293b);">No Application Errors</h4>
                    <p class="small text-muted mb-0">The application logs report zero recent errors or exceptions.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-striped command-os-table">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 15%;">DATE</th>
                                <th scope="col" style="width: 10%;">LEVEL</th>
                                <th scope="col" style="width: 25%;">SOURCE FILE</th>
                                <th scope="col" style="width: 50%;">SUMMARY</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr>
                                    <td><span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">{{ $entry['date'] }}</span></td>
                                    <td>
                                        <span class="badge-command-pill" style="color: #ef4444; font-weight: 700;">
                                            {{ strtoupper($entry['level']) }}
                                        </span>
                                    </td>
                                    <td>
                                        <code style="font-size: 0.75rem; color: var(--command-os-text, #1e293b); background: rgba(139, 92, 246, 0.05); padding: 2px 6px; border-radius: 4px;">{{ $entry['file'] }}</code>
                                    </td>
                                    <td style="font-size: 0.8125rem; font-weight: 500;">
                                        {{ $entry['summary'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p class="text-muted small mt-3 mb-0"><i class="fas fa-shield-alt mr-1"></i> Only the last 100 error-level summaries from AlphaLink Laravel log files are shown. Sensitive tokens, email addresses, and IPs are automatically redacted.</p>
        </div>
    </div>
@endsection
