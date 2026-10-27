@extends('layouts.dms-admin')

@section('title', 'Pmta Config History')

@section('content')
<section class="block block-rounded" id="pmta-config-history"
    data-history-url="{{ route('console.pmta-config-history.index', $workspace->id) }}"
    data-contents-url="{{ route('console.pmta-config-history.contents', [$workspace->id, '__ID__']) }}">
    <div class="block-header">
        <h3 class="block-title">Pmta Config History</h3>
        <div class="block-options">
            <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                <i class="fas fa-expand-arrows-alt" aria-hidden="true"></i>
            </button>
        </div>
    </div>
    <div class="block-content block-content-full">
        <div class="form-group form-row">
            <div class="col-4">
                <label class="sr-only" for="pmta-config-history-server">Servers:</label>
                <select class="form-control selectpicker" id="pmta-config-history-server" data-live-search="true" data-size="6" title="Filter By Server">
                    <option value="0" selected>ALL</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}">{{ $server->name }}{{ $server->id }} | {{ $server->ip_address }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-2">
                <button type="button" id="pmta-config-history-filter" class="btn btn-primary">FILTER</button>
            </div>
        </div>
        <div id="pmta-config-history-status" class="sr-only" role="status" aria-live="polite"></div>
        <div class="table-responsive">
            <table id="pmta-config-history-table" class="table table-sm table-hover" aria-label="PMTA configuration history"></table>
        </div>
    </div>
</section>

<dialog id="pmta-config-history-dialog" aria-labelledby="pmta-config-history-dialog-title">
    <div class="p-3" style="min-width:min(720px,90vw)">
        <h3 class="h5" id="pmta-config-history-dialog-title">PMTA configuration</h3>
        <pre id="pmta-config-history-contents" class="mb-3" style="max-height:70vh;overflow:auto;white-space:pre"></pre>
        <div class="text-right"><button type="button" class="btn btn-alt-secondary" id="pmta-config-history-dialog-close">Close</button></div>
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
        dom: '<"dms-table-tools"B>frtip',
        buttons: [
            { extend: 'pageLength' },
            { text: '<i class="fas fa-sync"></i>', titleAttr: 'REFRESH', action: (event, dataTable) => dataTable.ajax.reload(null, false) },
        ],
        displayLength: 50,
        order: [[0, 'desc']],
        columns: [
            { title: '#', data: 'id' },
            { title: 'server', data: 'server', orderable: false, searchable: false },
            { title: 'type', data: 'action' },
            { title: 'file', data: 'file' },
            { title: 'config', data: null, orderable: false, searchable: false, render: (data, type, row) => type === 'display' && row.has_config ? `<button type="button" class="btn btn-link pmta-config-history-show" data-id="${Number(row.id)}">SEE CONFIG</button>` : '' },
            { title: 'by', data: 'user', orderable: false, searchable: false },
            { title: 'at', data: 'created_at' },
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
            if (!response.ok) throw new Error(result.message || 'Configuration contents could not be loaded.');
            contents.textContent = result.contents ?? 'No configuration contents were saved for this history record.';
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
