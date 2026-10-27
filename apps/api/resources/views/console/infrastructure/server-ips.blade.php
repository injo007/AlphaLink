@extends('layouts.dms-reference')

@section('title', 'Server Ip & Mta')

@section('content')
    <h2 class="content-heading"><span class="text-info">{{ $server->name }} | {{ $server->id }}</span> IP AND MTA</h2>
    <div class="block block-rounded" id="server-ip-mta" data-ips-url="{{ $ipsUrl }}"
         data-vmta-url="{{ $vmtaUrl }}" data-smtp-url="{{ $smtpUrl }}"
         data-ip-status-url-template="{{ $ipStatusUrlTemplate }}"
         data-mta-status-url-template="{{ $mtaStatusUrlTemplate }}"
         data-ip-history-url-template="{{ $ipHistoryUrlTemplate }}">
        <ul id="server-ip-tabs" class="nav nav-tabs nav-tabs-block" data-toggle="tabs" role="tablist" aria-label="Server IP and MTA views">
            <li class="nav-item">
                <a class="nav-link active" href="#ips" role="tab" aria-controls="ips" aria-selected="true">IP <span id="ip_count" class="badge badge-pill badge-info">0</span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#vmta" role="tab" aria-controls="vmta" aria-selected="false">VMTA <span id="vmta_count" class="badge badge-pill badge-info">0</span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#smtp" role="tab" aria-controls="smtp" aria-selected="false">SMTP <span id="smtp_count" class="badge badge-pill badge-info">0</span></a>
            </li>
        </ul>
        <div class="block-content tab-content">
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
        <div class="px-3 pb-2" id="server-ip-message" role="status" aria-live="polite"></div>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('dms-reference/plugins/datatables/datatables.min.css') }}">
    <style>
        #server-ip-mta table { min-width: 680px; }
        #server-ip-mta .history-summary { margin: .25rem 0; min-width: 640px; }
        #server-ip-mta .ip-copy { cursor: pointer; }
        #server-ip-message { min-height: 1.5rem; }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('dms-reference/plugins/datatables/datatables.min.js') }}"></script>
    <script src="{{ asset('dms-reference/plugins/datatables/jquery.mark.min.js') }}"></script>
    <script src="{{ asset('dms-reference/plugins/datatables/datatables.mark.js') }}"></script>
    <script src="{{ asset('dms-reference/assets/js/tables_datatables.js') }}"></script>
    <script src="{{ asset('dms-reference/plugins/datatables/ip-address.js') }}"></script>
    <script defer src="{{ asset('dms-reference/server-ip-details.js') }}?v={{ substr(sha1_file(public_path('dms-reference/server-ip-details.js')), 0, 12) }}"></script>
@endpush
