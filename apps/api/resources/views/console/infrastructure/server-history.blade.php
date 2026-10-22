@extends('layouts.dms-admin')

@section('title', 'Server History - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title"><span class="text-info">{{ $server->name }} | {{ $server->id }}</span> HISTORIES</h3>
        </div>
        <div class="block-content">
            <div class="table-responsive">
                <table id="histories" class="table table-sm table-hover table-striped" data-source-url="{{ $historyDataUrl }}"></table>
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
    <script src="{{ asset('dms-reference/server-history.js') }}" defer></script>
@endpush
