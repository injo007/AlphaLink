@extends('layouts.dms-reference')

@section('title', 'Ip History')

@section('content')
    <div class="block block-rounded" id="server-ip-history" data-source-url="{{ $historyUrl }}">
        <div class="block-header block-header-default">
            <h3 class="block-title"><span class="text-info">{{ $server->name }} | {{ $server->id }}</span> IP {{ $ip->ip }} HISTORIES</h3>
        </div>
        <div class="block-content">
            <div class="table-responsive">
                <table id="histories" class="table table-sm table-hover table-striped" aria-label="IP reputation history"></table>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('dms-reference/plugins/datatables/datatables.min.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('dms-reference/plugins/datatables/datatables.min.js') }}"></script>
    <script src="{{ asset('dms-reference/plugins/datatables/jquery.mark.min.js') }}"></script>
    <script src="{{ asset('dms-reference/plugins/datatables/datatables.mark.js') }}"></script>
    <script src="{{ asset('dms-reference/assets/js/tables_datatables.js') }}"></script>
    <script defer src="{{ asset('dms-reference/server-ip-details.js') }}?v={{ substr(sha1_file(public_path('dms-reference/server-ip-details.js')), 0, 12) }}"></script>
@endpush
