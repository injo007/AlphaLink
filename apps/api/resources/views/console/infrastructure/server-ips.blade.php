@extends('layouts.standalone')

@section('title', 'Server IP & MTA')

@section('content')
    <div class="command-modal-surface">
        <div class="command-modal-hero">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon">
                    <i class="fas fa-network-wired"></i>
                </div>
                <div>
                    <div class="command-modal-hero-eyebrow">NETWORK INTERFACES // IP & PMTA ALLOCATION</div>
                    <h3 class="command-modal-hero-title">
                        SERVER IP & MTA <span class="badge-command-pill ml-2" style="font-weight: 700; color: #8b5cf6;">#{{ $server->id }} &bull; {{ $server->name }}</span>
                    </h3>
                </div>
            </div>
        </div>

        <div class="command-modal-body p-0" id="server-ip-mta"
             data-ips-url="{{ $ipsUrl }}"
             data-vmta-url="{{ $vmtaUrl }}"
             data-smtp-url="{{ $smtpUrl }}"
             data-ip-status-url-template="{{ $ipStatusUrlTemplate }}"
             data-mta-status-url-template="{{ $mtaStatusUrlTemplate }}"
             data-ip-history-url-template="{{ $ipHistoryUrlTemplate }}">

            <div class="p-3 pb-0">
                <ul id="server-ip-tabs" class="nav nav-pills" role="tablist" aria-label="Server IP and MTA views" style="gap: 8px;">
                    <li class="nav-item">
                        <a class="nav-link active btn-command-action" href="#ips" role="tab" aria-controls="ips" aria-selected="true" data-toggle="tab" style="border-radius: 10px; font-weight: 600; font-size: 0.8125rem;">
                            <i class="fas fa-server mr-1"></i> IP ADDRESSES <span id="ip_count" class="badge-command-pill ml-1" style="color: #6366f1;">0</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn-command-action" href="#vmta" role="tab" aria-controls="vmta" aria-selected="false" data-toggle="tab" style="border-radius: 10px; font-weight: 600; font-size: 0.8125rem;">
                            <i class="fas fa-layer-group mr-1"></i> VMTA CONFIG <span id="vmta_count" class="badge-command-pill ml-1" style="color: #8b5cf6;">0</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn-command-action" href="#smtp" role="tab" aria-controls="smtp" aria-selected="false" data-toggle="tab" style="border-radius: 10px; font-weight: 600; font-size: 0.8125rem;">
                            <i class="fas fa-envelope-open mr-1"></i> SMTP IDENTITIES <span id="smtp_count" class="badge-command-pill ml-1" style="color: #06b6d4;">0</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="tab-content p-3">
                <div class="tab-pane active" id="ips" role="tabpanel">
                    <div class="table-responsive">
                        <table id="mta_ip" class="table table-sm table-hover table-striped" aria-label="Server IP addresses"></table>
                    </div>
                </div>
                <div class="tab-pane" id="vmta" role="tabpanel">
                    <div class="table-responsive">
                        <table id="mta_vmta" class="table table-sm table-hover table-striped" aria-label="Server VMTAs"></table>
                    </div>
                </div>
                <div class="tab-pane" id="smtp" role="tabpanel">
                    <div class="table-responsive">
                        <table id="mta_smtp" class="table table-sm table-striped" aria-label="Server SMTP identities"></table>
                    </div>
                </div>
            </div>

            <div class="px-3 pb-3" id="server-ip-message" role="status" aria-live="polite"></div>
        </div>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('console-assets/plugins/datatables/datatables.min.css') }}">
    <style>
        #server-ip-mta table { min-width: 680px; }
        #server-ip-mta .history-summary { margin: .5rem 0; min-width: 640px; background: rgba(99, 102, 241, 0.04); border-radius: 8px; }
        #server-ip-mta .ip-copy { cursor: pointer; color: #8b5cf6; margin-left: 4px; }
        #server-ip-message { min-height: 1.5rem; font-size: 0.8125rem; font-weight: 600; }
        #server-ip-tabs .nav-link.active {
            background: linear-gradient(135deg, #6366f1, #8b5cf6) !important;
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3) !important;
        }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('console-assets/plugins/datatables/datatables.min.js') }}"></script>
    <script src="{{ asset('console-assets/plugins/datatables/jquery.mark.min.js') }}"></script>
    <script src="{{ asset('console-assets/plugins/datatables/datatables.mark.js') }}"></script>
    <script src="{{ asset('console-assets/assets/js/tables_datatables.js') }}"></script>
    <script src="{{ asset('console-assets/plugins/datatables/ip-address.js') }}"></script>
    <script defer src="{{ asset('console-assets/server-ip-details.js') }}?v={{ substr(sha1_file(public_path('console-assets/server-ip-details.js')), 0, 12) }}"></script>
@endpush
