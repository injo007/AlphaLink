@extends('layouts.admin-shell')

@section('title', 'Pmta Config History')

@push('styles')
<style>
    #pmta-config-history-dialog {
        border: none;
        border-radius: 16px;
        background: var(--command-os-card-bg, #ffffff);
        color: var(--command-os-text, #1e293b);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
        padding: 0;
        max-width: 780px;
        width: 90vw;
    }
    #pmta-config-history-dialog::backdrop {
        background: rgba(15, 23, 42, 0.7);
        backdrop-filter: blur(4px);
    }
    #pmta-config-history-contents {
        background: rgba(15, 23, 42, 0.05);
        border: 1px solid var(--command-os-border, rgba(99, 102, 241, 0.18));
        border-radius: 10px;
        padding: 12px;
        font-family: 'JetBrains Mono', 'Fira Code', 'Roboto Mono', monospace;
        font-size: 0.8125rem;
    }
    html[data-command-theme='dark'] #pmta-config-history-contents {
        background: rgba(15, 23, 42, 0.6);
        color: #e2e8f0;
    }
</style>
@endpush

@section('content')
<div class="command-os-report-page">
    <!-- 1. Hero Header -->
    <header class="command-os-report-hero mb-4">
        <div class="command-os-report-hero-left">
            <div class="command-os-report-hero-icon" aria-hidden="true">
                <i class="fas fa-file-code"></i>
            </div>
            <div>
                <div class="command-os-report-hero-eyebrow">REVISION ARCHIVE // CONFIG SNAPSHOTS</div>
                <h1 class="command-os-report-hero-title">PMTA CONFIG HISTORY</h1>
                <p class="command-os-report-hero-desc">Historical snapshots, diffs, and audit trail of changes made to PowerMTA configuration files.</p>
            </div>
        </div>
        <div class="command-os-report-hero-right">
            <div class="command-os-report-hero-meta">
                <span class="command-os-report-badge">CONFIG ARCHIVE</span>
            </div>
        </div>
    </header>

    <div id="pmta-config-history"
        data-history-url="{{ route('console.pmta-config-history.index', $workspace->id) }}"
        data-contents-url="{{ route('console.pmta-config-history.contents', [$workspace->id, '__ID__']) }}">

        <!-- 2. Filter Bar -->
        <div class="command-os-table-card p-4 mb-4">
            <div class="row align-items-center">
                <div class="col-sm-12 col-md-5 mb-3 mb-md-0">
                    <label class="command-form-label" for="pmta-config-history-server">Filter By Server:</label>
                    <select class="form-control selectpicker" id="pmta-config-history-server" data-live-search="true" data-size="6" title="Filter By Server">
                        <option value="0" selected>ALL SERVERS</option>
                        @foreach ($servers as $server)
                            <option value="{{ $server->id }}">{{ $server->name }}{{ $server->id }} | {{ $server->ip_address }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-12 col-md-3">
                    <button type="button" id="pmta-config-history-filter" class="btn-command-primary">
                        <i class="fas fa-filter mr-1"></i> APPLY FILTER
                    </button>
                </div>
            </div>
            <div id="pmta-config-history-status" class="sr-only" role="status" aria-live="polite"></div>
        </div>

        <!-- 3. Table Card -->
        <div class="command-os-table-card">
            <div class="table-responsive">
                <table id="pmta-config-history-table" class="table table-sm table-hover table-striped command-os-table" aria-label="PMTA configuration history"></table>
            </div>
        </div>
    </div>
</div>

<!-- Config Contents Dialog -->
<dialog id="pmta-config-history-dialog" aria-labelledby="pmta-config-history-dialog-title">
    <div class="command-modal-surface p-4">
        <div class="command-modal-hero mb-3">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon"><i class="fas fa-file-alt"></i></div>
                <div>
                    <h3 class="command-modal-hero-title" id="pmta-config-history-dialog-title">PMTA CONFIGURATION SNAPSHOT</h3>
                    <div class="command-modal-hero-eyebrow">RAW CONFIG DIRECTIVES</div>
                </div>
            </div>
        </div>
        <pre id="pmta-config-history-contents" class="mb-3" style="max-height: 60vh; overflow: auto; white-space: pre;"></pre>
        <div class="command-modal-actions mt-3">
            <button type="button" class="btn-command-action" id="pmta-config-history-dialog-close">CLOSE</button>
        </div>
    </div>
</dialog>
@endsection

@push('scripts')
<script>
(() => {
    const root = document.getElementById('pmta-config-history');
    if (!root) return;

    const status = document.getElementById('pmta-config-history-status');
    const table = document.getElementById('pmta-config-history-table');
    const dialog = document.getElementById('pmta-config-history-dialog');
    const contents = document.getElementById('pmta-config-history-contents');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const announce = (message, tone = 'info') => {
        status.textContent = message;
        if (window.AlphaLinkToast) window.AlphaLinkToast.show(message, tone);
    };
    const grid = window.jQuery?.fn?.DataTable ? window.jQuery(table).DataTable({
        ajax: {
            url: root.dataset.historyUrl,
            data: (request) => { request.server = document.getElementById('pmta-config-history-server').value || '0'; },
        },
        serverSide: true,
        processing: true,
        dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
        buttons: [
            { extend: 'pageLength', className: 'btn-command-action' },
            {
                text: '<i class="fas fa-sync mr-1"></i> REFRESH',
                className: 'btn-command-action',
                titleAttr: 'REFRESH',
                action: (event, dataTable) => dataTable.ajax.reload(null, false)
            },
        ],
        displayLength: 50,
        order: [[0, 'desc']],
        columns: [
            {
                title: '#', data: 'id', width: '6%',
                render: (val, type) => type === 'display' ? `<span class="command-mono-badge">#${val}</span>` : val
            },
            {
                title: 'SERVER', data: 'server', orderable: false, searchable: false,
                render: (val, type) => type === 'display' ? `<strong>${val || ''}</strong>` : val
            },
            {
                title: 'TYPE', data: 'action',
                render: (val, type) => type === 'display' ? `<span class="badge-command-pill" style="font-weight: 700; color: #8b5cf6;">${(val || '').toUpperCase()}</span>` : val
            },
            {
                title: 'FILE', data: 'file',
                render: (val, type) => type === 'display' ? `<span style="font-family: monospace; font-weight: 600; color: #6366f1;">${val || ''}</span>` : val
            },
            {
                title: 'CONFIG', data: null, orderable: false, searchable: false,
                render: (data, type, row) => type === 'display' && row.has_config ? `<button type="button" class="btn-command-action pmta-config-history-show" data-id="${Number(row.id)}" style="padding: 2px 10px; font-size: 0.75rem;"><i class="fas fa-eye mr-1"></i> VIEW CONFIG</button>` : ''
            },
            {
                title: 'USER', data: 'user', orderable: false, searchable: false,
                render: (val, type) => type === 'display' ? `<span>${val || 'System'}</span>` : val
            },
            {
                title: 'TIMESTAMP', data: 'created_at',
                render: (val, type) => type === 'display' ? `<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">${val || ''}</span>` : val
            },
        ],
    }) : null;

    document.getElementById('pmta-config-history-filter').addEventListener('click', () => {
        if (grid) grid.ajax.reload();
    });
    table.addEventListener('click', async (event) => {
        const button = event.target.closest('.pmta-config-history-show');
        if (!button) return;

        button.disabled = true;
        try {
            const response = await fetch(root.dataset.contentsUrl.replace('__ID__', button.dataset.id), {
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(result.message || 'Could not load configuration.');
            contents.textContent = result.contents || '';
            dialog.showModal();
        } catch (error) {
            announce(error.message, 'danger');
        } finally {
            button.disabled = false;
        }
    });
    document.getElementById('pmta-config-history-dialog-close').addEventListener('click', () => dialog.close());
    if (window.jQuery?.fn.selectpicker) window.jQuery('.selectpicker').selectpicker();
})();
</script>
@endpush
