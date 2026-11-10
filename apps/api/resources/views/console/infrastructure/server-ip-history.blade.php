@extends('layouts.standalone')

@section('title', 'IP Reputation History')

@section('content')
    <div class="command-modal-surface" id="server-ip-history" data-source-url="{{ $historyUrl }}">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div>
                    <div class="command-modal-hero-eyebrow">REPUTATION AUDIT // BLACKLIST TELEMETRY</div>
                    <h3 class="command-modal-hero-title">
                        IP HISTORY <span class="badge-command-pill ml-2" style="font-weight: 700; color: #8b5cf6;">{{ $ip->ip }} &bull; {{ $server->name }}</span>
                    </h3>
                </div>
            </div>
        </div>

        <div class="command-modal-body">
            <div class="table-responsive">
                <table id="histories" class="table table-sm table-hover table-striped" aria-label="IP reputation history"></table>
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
    <script defer src="{{ asset('console-assets/server-ip-details.js') }}?v={{ substr(sha1_file(public_path('console-assets/server-ip-details.js')), 0, 12) }}"></script>
@endpush
