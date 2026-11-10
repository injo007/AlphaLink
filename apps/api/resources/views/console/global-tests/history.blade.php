@extends('layouts.admin-shell')

@section('title', 'Global Test History')

@push('styles')
    <style>
        .global-test-output {
            max-height: 30rem;
            overflow: auto;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            background: var(--command-os-card-bg, #ffffff);
            border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.2));
            border-radius: 10px;
            padding: 1rem;
            font-family: 'JetBrains Mono', 'Fira Code', 'SFMono-Regular', Menlo, Monaco, Consolas, monospace;
            font-size: 0.8125rem;
            line-height: 1.5;
            color: var(--command-os-text, #1e293b);
        }
        html[data-command-theme='dark'] .global-test-output {
            background: #090d28;
            color: #e2e8f0;
            border-color: rgba(139, 92, 246, 0.25);
        }
        .global-history-spec {
            display: grid;
            gap: 0.75rem 1.25rem;
            grid-template-columns: minmax(9rem, 0.3fr) minmax(0, 1fr);
            margin: 0;
            padding: 1.25rem;
            background: rgba(139, 92, 246, 0.03);
            border-radius: 12px;
            border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.12));
        }
        .global-history-spec dt {
            font-weight: 700;
            font-size: 0.8125rem;
            color: var(--command-os-text-muted, #64748b);
            text-transform: uppercase;
        }
        .global-history-spec dd {
            margin: 0;
            font-size: 0.875rem;
            color: var(--command-os-text, #1e293b);
        }
        html[data-command-theme='dark'] .global-history-spec {
            background: rgba(15, 23, 42, 0.5);
            border-color: rgba(139, 92, 246, 0.22);
        }
        html[data-command-theme='dark'] .global-history-spec dd {
            color: #f1f5f9;
        }
    </style>
@endpush

@section('content')
    @php
        $header = base64_decode((string) ($log->data['header'] ?? ''), true);
        $body = base64_decode((string) ($log->data['body'] ?? ''), true);
    @endphp
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">OPERATIONS TOOLKIT // TEST AUDIT TRAIL</div>
                    <h1 class="command-os-report-hero-title">GLOBAL TEST RUN #{{ $log->id }}</h1>
                    <p class="command-os-report-hero-desc">Recorded delivery parameters, headers, payload, and sender transmission output.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <a class="btn-command-action" href="{{ route('console.global-tests.histories', $workspace->id) }}">
                        <i class="fas fa-arrow-left mr-1" aria-hidden="true"></i> Back to Histories
                    </a>
                </div>
            </div>
        </header>

        <!-- 2. Detail Surface Card -->
        <div class="command-os-table-card p-4">
            <dl class="global-history-spec mb-4">
                <dt>Dispatched At</dt><dd><span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">{{ $log->created_at?->format('Y-m-d H:i:s') }}</span></dd>
                <dt>Operator</dt><dd><strong>{{ $log->actor?->name ?: $log->actor?->email }}</strong></dd>
                <dt>Return Path</dt><dd><code>{{ $log->data['return_path'] ?? '' }}</code></dd>
                <dt>Test Recipients</dt><dd>{{ implode(', ', $log->data['email_tests'] ?? []) }}</dd>
                <dt>Sender Option</dt><dd><span class="badge-command-pill" style="color: #8b5cf6;">{{ strtoupper($log->data['mta'] ?? '') }} / {{ strtoupper($log->data['type'] ?? '') }}</span></dd>
                <dt>Server IDs</dt><dd><code>{{ implode(', ', $log->data['servers'] ?? []) }}</code></dd>
                <dt>IP / MTA IDs</dt><dd><code>{{ implode(', ', $log->data['ips'] ?? []) }}</code></dd>
                <dt>Repeat Count</dt><dd><span class="command-mono-badge">{{ $log->data['repeat'] ?? '1' }}</span></dd>
            </dl>

            <div class="form-group mb-3">
                <label class="command-form-label" for="global-history-header"><i class="fas fa-heading mr-1 text-purple"></i> Message Headers:</label>
                <textarea id="global-history-header" class="command-form-control" rows="6" readonly style="font-family: monospace; font-size: 0.8125rem;">{{ $header === false ? 'Stored header could not be decoded.' : $header }}</textarea>
            </div>
            <div class="form-group mb-4">
                <label class="command-form-label" for="global-history-body"><i class="fas fa-envelope-open-text mr-1 text-purple"></i> Message Body:</label>
                <textarea id="global-history-body" class="command-form-control" rows="8" readonly style="font-family: monospace; font-size: 0.8125rem;">{{ $body === false ? 'Stored body could not be decoded.' : $body }}</textarea>
            </div>
            <div class="form-group mb-0">
                <label class="command-form-label" for="global-history-output"><i class="fas fa-terminal mr-1 text-purple"></i> Raw Sender Output:</label>
                <pre id="global-history-output" class="global-test-output">{{ $log->output }}</pre>
            </div>
        </div>
    </div>
@endsection
