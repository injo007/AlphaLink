@extends('layouts.admin-shell')

@section('title', 'Server History')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-history"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">DAEMON TELEMETRY // HISTORICAL AUDIT</div>
                    <h1 class="command-os-report-hero-title">
                        SERVER HISTORY <span class="badge-command-pill ml-2" style="font-size: 0.875rem; color: #8b5cf6;">#{{ $server->id }} &bull; {{ $server->name }}</span>
                    </h1>
                    <p class="command-os-report-hero-desc">Historical daemon uptime audits for SSH, Apache web server, and PowerMTA daemon.</p>
                </div>
            </div>
        </header>

        <!-- 2. Table Card -->
        <div class="command-os-table-card">
            <div class="table-responsive">
                <table id="histories" class="table table-sm table-hover table-striped" data-source-url="{{ $historyDataUrl }}" aria-label="Server daemon uptime history"></table>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('console-assets/plugins/datatables/datatables.min.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('console-assets/plugins/datatables/datatables.min.js') }}"></script>
    <script src="{{ asset('console-assets/plugins/datatables/jquery.mark.min.js') }}"></script>
    <script src="{{ asset('console-assets/plugins/datatables/datatables.mark.js') }}"></script>
    <script src="{{ asset('console-assets/assets/js/tables_datatables.js') }}"></script>
    <script src="{{ asset('console-assets/server-history.js') }}" defer></script>
@endpush
