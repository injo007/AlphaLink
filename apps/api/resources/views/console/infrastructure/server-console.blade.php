@extends('layouts.standalone')

@section('title', 'Console')

@section('content')
    <div class="command-modal-surface">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon">
                    <i class="fas fa-terminal"></i>
                </div>
                <div>
                    <div class="command-modal-hero-eyebrow">SECURE SHELL // REMOTE EXECUTION</div>
                    <h3 class="command-modal-hero-title">
                        SERVER CONSOLE <span class="badge-command-pill ml-2" style="font-weight: 700; color: #8b5cf6;">#{{ $server->id }} &bull; {{ $server->name }}</span>
                    </h3>
                </div>
            </div>
        </div>

        <div class="command-modal-body">
            <form id="server-console-form" action="{{ $consoleUrl }}" method="post">
                @csrf
                <div class="command-form-group">
                    <label class="command-form-label" for="command">
                        <i class="fas fa-code mr-1 text-purple"></i> COMMAND
                    </label>
                    <textarea id="command" name="cmd" class="command-form-control" rows="4" placeholder="Enter shell command (e.g. uptime, pmta status)..." style="font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 0.875rem; background: #0f172a; color: #38bdf8; border-color: rgba(99, 102, 241, 0.3);"></textarea>
                </div>

                <div class="command-modal-actions mb-4">
                    <button id="run" type="submit" class="btn-command-primary" style="min-width: 140px;">
                        <i class="fas fa-bolt mr-1"></i> RUN COMMAND
                    </button>
                </div>
            </form>

            <div class="command-form-group">
                <label class="command-form-label">
                    <i class="fas fa-desktop mr-1 text-purple"></i> TERMINAL OUTPUT
                </label>
                <div style="background: #090d16; border: 1px solid rgba(99, 102, 241, 0.25); border-radius: 12px; padding: 1rem; min-height: 140px; max-height: 380px; overflow-y: auto;">
                    <code id="result" style="white-space: pre-wrap; font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 0.8125rem; color: #4ade80; display: block;" aria-live="polite">Awaiting command execution...</code>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script defer src="{{ asset('console-assets/server-console.js') }}"></script>
@endpush
