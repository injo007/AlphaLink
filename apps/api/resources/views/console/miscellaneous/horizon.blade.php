@extends('layouts.admin-shell')

@section('title', 'Horizon')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">OPERATIONS TOOLKIT // QUEUE &amp; WORKER TELEMETRY</div>
                    <h1 class="command-os-report-hero-title">HORIZON MONITOR</h1>
                    <p class="command-os-report-hero-desc">Monitor Redis queue throughput, active background jobs, and worker telemetry.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    @if ($available)
                        <span class="command-os-report-badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border-color: rgba(16, 185, 129, 0.3);">ONLINE</span>
                    @else
                        <span class="command-os-report-badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border-color: rgba(245, 158, 11, 0.3);">OFFLINE</span>
                    @endif
                </div>
            </div>
        </header>

        <!-- 2. Surface Card -->
        <div class="command-os-table-card p-4">
            @if ($available)
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                    <h3 class="font-w700 mb-0" style="font-size: 0.9375rem; text-transform: uppercase; color: var(--command-os-text, #1e293b);">
                        <i class="fas fa-tv mr-1 text-purple"></i> Horizon Dashboard
                    </h3>
                    <button type="button" class="btn-command-action" disabled aria-describedby="horizon-restart-note" style="padding: 4px 12px; font-size: 0.75rem;">
                        <i class="fas fa-redo mr-1"></i> RESTART HORIZON
                    </button>
                </div>
                <div class="rounded overflow-hidden mb-3" style="border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.15)); border-radius: 12px;">
                    <iframe src="{{ route('horizon.index') }}" title="Laravel Horizon dashboard" width="100%" height="720" frameborder="0"></iframe>
                </div>
                <p class="text-muted small mb-1"><i class="fas fa-info-circle mr-1"></i> Horizon monitors the Redis-backed default queue. Suppression imports run on the separate PostgreSQL-backed suppression worker.</p>
                <p id="horizon-restart-note" class="text-muted small mb-0">Restart is not available from this page.</p>
            @else
                <div class="p-4 rounded text-center" style="background: rgba(245, 158, 11, 0.05); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 16px;">
                    <div style="font-size: 2.5rem; color: #f59e0b; margin-bottom: 12px;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h4 class="font-w700 mb-2" style="font-size: 1.125rem; color: var(--command-os-text, #1e293b);">Horizon Dashboard Unavailable</h4>
                    <p class="text-muted mb-2" style="font-size: 0.875rem; max-width: 540px; margin: 0 auto;">Laravel Horizon is not installed and routed in this AlphaLink runtime environment.</p>
                    <p class="small text-muted mb-0">Queue connection: <strong class="command-mono-badge">{{ $connection }}</strong> · Worker activity is not verified here.</p>
                </div>
            @endif
        </div>
    </div>
@endsection
