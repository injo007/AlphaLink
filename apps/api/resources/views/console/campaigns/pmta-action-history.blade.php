@extends('layouts.admin-shell')

@section('title', 'Pmta Action History')

@section('content')
<div class="command-os-report-page">
    <!-- 1. Hero Header -->
    <header class="command-os-report-hero mb-4">
        <div class="command-os-report-hero-left">
            <div class="command-os-report-hero-icon" aria-hidden="true">
                <i class="fas fa-history"></i>
            </div>
            <div>
                <div class="command-os-report-hero-eyebrow">AUDIT TRAIL // OPERATION LOGS</div>
                <h1 class="command-os-report-hero-title">PMTA ACTION HISTORY</h1>
                <p class="command-os-report-hero-desc">Chronological audit trail of all manual and scheduled queue actions, restarts, and command dispatches.</p>
            </div>
        </div>
        <div class="command-os-report-hero-right">
            <div class="command-os-report-hero-meta">
                <span class="command-os-report-badge">ACTION AUDIT</span>
            </div>
        </div>
    </header>

    <div id="pmta-action-history-root">
        <!-- 2. Filter Bar -->
        <div class="command-os-table-card p-4 mb-4">
            <div class="row align-items-center">
                <div class="col-sm-12 col-md-5 mb-3 mb-md-0">
                    <label class="command-form-label" for="pmta-history-server">Filter By Server:</label>
                    <select class="form-control selectpicker" id="pmta-history-server" data-live-search="true" data-size="6" title="Filter By Server">
                        <option value="0" selected>ALL SERVERS</option>
                        @foreach ($servers as $server)
                            <option value="{{ $server->id }}">{{ $server->name }}{{ $server->id }} | {{ $server->ip_address }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-12 col-md-3">
                    <button type="button" class="btn-command-primary" id="pmta-history-filter">
                        <i class="fas fa-filter mr-1"></i> APPLY FILTER
                    </button>
                </div>
            </div>
        </div>

        <!-- 3. Table Card -->
        <div class="command-os-table-card">
            <div class="table-responsive">
                <table id="pmta-action-history" class="table table-sm table-hover table-striped command-os-table" aria-label="PMTA action history">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">SERVER</th>
                            <th scope="col">ACTION</th>
                            <th scope="col">RESULT</th>
                            <th scope="col">BY</th>
                            <th scope="col">TIMESTAMP</th>
                        </tr>
                    </thead>
                </table>
            </div>
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
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
                buttons: [
                    { extend: 'pageLength', className: 'btn-command-action' },
                    {
                        text: '<i class="fas fa-sync mr-1" aria-hidden="true"></i> REFRESH',
                        className: 'btn-command-action',
                        titleAttr: 'Refresh history',
                        action: (event, dt) => dt.ajax.reload(null, false)
                    }
                ],
                columns: [
                    {
                        title: '#', data: 'id', width: '6%', searchable: false,
                        render: (id, type) => type === 'display' ? `<span class="command-mono-badge">#${escapeHtml(id)}</span>` : id
                    },
                    {
                        title: 'SERVER',
                        data: 'server',
                        orderable: false,
                        searchable: false,
                        render: (server, type) => {
                            const value = server ? String(server.name || '') + String(server.id || '') : 'N/A';
                            return type === 'display' ? `<strong>${escapeHtml(value)}</strong>` : value;
                        }
                    },
                    {
                        title: 'ACTION',
                        data: 'action',
                        render: (action, type) => {
                            if (type !== 'display') return action;
                            const act = String(action || '').toLowerCase();
                            let color = '#8b5cf6';
                            if (act === 'delete' || act === 'reset') color = '#ef4444';
                            if (act === 'pause') color = '#f59e0b';
                            if (act === 'restart' || act === 'resume') color = '#10b981';
                            return `<span class="badge-command-pill" style="font-weight: 700; color: ${color};">${escapeHtml(act).toUpperCase()}</span>`;
                        }
                    },
                    {
                        title: 'RESULT',
                        data: 'result',
                        render: (result, type) => type === 'display' ? `<span style="font-family: monospace; font-size: 0.8125rem;">${formatResult(result)}</span>` : result
                    },
                    {
                        title: 'BY',
                        data: 'user',
                        orderable: false,
                        searchable: false,
                        render: (user, type) => {
                            const value = user ? String(user.name || '') + String(user.id || '') : 'System';
                            return type === 'display' ? escapeHtml(value) : value;
                        }
                    },
                    {
                        title: 'TIMESTAMP',
                        data: 'created_at',
                        render: (date, type) => type === 'display' ? `<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">${escapeHtml(date)}</span>` : date
                    }
                ]
            });

            filterButton.addEventListener('click', () => {
                dataTable.ajax.reload();
            });

            if (window.jQuery?.fn.selectpicker) window.jQuery('.selectpicker').selectpicker();
        })();
    </script>
@endpush
