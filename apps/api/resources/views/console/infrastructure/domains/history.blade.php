@extends('layouts.dms-reference')

@section('title', 'Domain History - DMS')

@push('styles')
    <link rel="stylesheet" href="{{ asset('dms-reference/plugins/datatables/datatables.min.css') }}">
@endpush

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title"><span class="text-info">{{ $domain->name }}</span> HISTORIES</h3>
        </div>
        <div class="block-content">
            <div class="table-responsive">
                <table id="histories" class="table table-sm table-hover table-striped"></table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dms-reference/plugins/datatables/datatables.min.js') }}"></script>
    <script src="{{ asset('dms-reference/plugins/datatables/jquery.mark.min.js') }}"></script>
    <script src="{{ asset('dms-reference/plugins/datatables/datatables.mark.js') }}"></script>
    <script src="{{ asset('dms-reference/assets/js/tables_datatables.js') }}"></script>
    <script>
        $(function () {
            const formatBadge = (value) => {
                const color = value ? 'badge-success' : 'badge-danger';
                const text = value ? 'YES' : 'NO';
                return `<span class="badge ${color} p-1">${text}</span>`;
            };
            const textarea = (value) => {
                const element = document.createElement('textarea');
                element.rows = 2;
                element.className = 'form-control';
                element.readOnly = true;
                element.value = value ?? '';
                return element.outerHTML;
            };

            $('#histories').DataTable({
                ajax: @json(route('console.infrastructure.domains.history', [$workspace->id, $domain->id])),
                serverSide: true,
                buttons: [
                    {extend: 'pageLength'},
                    {text: '<i class="fas fa-sync"></i>', titleAttr: 'REFRESH', action: function (event, table) { table.ajax.reload(null, false); }}
                ],
                columns: [
                    {title: '#', data: 'id'},
                    {title: 'a record', data: 'a_record'},
                    {title: 'spf', data: 'spf', render: (data, type) => type === 'display' ? textarea(data) : data},
                    {title: 'dkim', data: 'dkim', render: (data, type) => type === 'display' ? textarea(data) : data},
                    {title: 'dmarc', data: 'dmarc', render: (data, type) => type === 'display' ? textarea(data) : data},
                    {title: 'is listed spamhaus', data: 'is_listed_spamhaus', render: formatBadge, searchable: false},
                    {title: 'is listed hostkarma', data: 'is_listed_hostkarma', render: formatBadge, searchable: false},
                    {title: 'at', data: 'created_at'}
                ]
            });
        });
    </script>
@endpush
