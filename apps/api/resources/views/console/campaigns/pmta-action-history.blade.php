@extends('layouts.dms-admin')

@section('title', 'Pmta Action History')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Pmta Action History</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand-arrows-alt" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="form-group form-row align-items-end">
                <div class="col-4">
                    <label class="sr-only" for="pmta-history-server">Servers:</label>
                    <select class="form-control selectpicker" id="pmta-history-server" data-live-search="true" data-size="6" title="Filter By Server">
                        <option value="0" selected>ALL</option>
                        @foreach ($servers as $server)
                            <option value="{{ $server->id }}">{{ $server->name }}{{ $server->id }} | {{ $server->ip_address }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-2">
                    <button type="button" class="btn btn-primary" id="pmta-history-filter">FILTER</button>
                </div>
            </div>
            <div class="table-responsive">
                <table id="pmta-action-history" class="table table-sm table-hover" aria-label="PMTA action history">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Server</th>
                            <th scope="col">Action</th>
                            <th scope="col">Result</th>
                            <th scope="col">By</th>
                            <th scope="col">At</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const table = document.getElementById('pmta-action-history');
            const serverFilter = document.getElementById('pmta-history-server');
            const filterButton = document.getElementById('pmta-history-filter');
            if (!table || !serverFilter || !filterButton || !window.jQuery || !jQuery.fn.dataTable) return;

            const escapeHtml = (value) => String(value == null ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            const formatResult = (value) => escapeHtml(value).replace(/\r?\n/g, '<br>');
            const historyUrl = new URL(@json($historyUrl), window.location.href);

            const dataTable = jQuery(table).DataTable({
                ajax: {
                    url: historyUrl.toString(),
                    data: (request) => { request.server = serverFilter.value || '0'; },
                    dataSrc: 'data'
                },
                serverSide: true,
                pageLength: 50,
                order: [[0, 'desc']],
                rowCallback: (row, data) => {
                    const action = String(data.action || '').toLowerCase();
                    const rowClass = {
                        pause: 'table-warning',
                        resume: 'table-info',
                        delete: 'table-danger',
                        reset: 'table-active',
                        restart: 'table-success'
                    }[action];
                    if (rowClass) row.classList.add(rowClass);
                },
                dom: 'Bfrtip',
                buttons: [
                    { extend: 'pageLength' },
                    {
                        text: '<i class="fas fa-sync" aria-hidden="true"></i>',
                        titleAttr: 'Refresh history',
                        action: (event, dt) => dt.ajax.reload(null, false)
                    }
                ],
                columns: [
                    { title: '#', data: 'id', width: '10%', searchable: false },
                    {
                        title: 'server',
                        data: 'server',
                        orderable: false,
                        searchable: false,
                        render: (server, type) => {
                            const value = server ? String(server.name || '') + String(server.id || '') : 'N/A';
                            return type === 'display' ? escapeHtml(value) : value;
                        }
                    },
                    {
                        title: 'action',
                        data: 'action',
                        render: (value, type) => type === 'display' ? escapeHtml(value) : value
                    },
                    {
                        title: 'result',
                        data: 'result',
                        render: (value, type) => type === 'display' ? formatResult(value) : value
                    },
                    {
                        title: 'by',
                        data: 'user',
                        orderable: false,
                        searchable: false,
                        render: (user, type) => {
                            const value = user && user.username ? user.username : 'N/A';
                            return type === 'display' ? escapeHtml(value) : value;
                        }
                    },
                    { title: 'at', data: 'created_at' }
                ],
                language: {
                    emptyTable: 'No PMTA actions have been recorded.',
                    zeroRecords: 'No PMTA actions match this filter.'
                }
            });

            filterButton.addEventListener('click', () => dataTable.ajax.reload());
        })();
    </script>
@endpush
