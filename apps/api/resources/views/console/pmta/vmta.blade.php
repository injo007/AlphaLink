@extends('layouts.admin-shell')

@section('title', 'Pmta VMTA')

@push('styles')
<style>
    .command-switch-box {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    #pmta-vmta-ip-search {
        border: none;
        border-radius: 16px;
        background: var(--command-os-card-bg, #ffffff);
        color: var(--command-os-text, #1e293b);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
        padding: 0;
        max-width: 540px;
        width: 90vw;
    }
    #pmta-vmta-ip-search::backdrop {
        background: rgba(15, 23, 42, 0.7);
        backdrop-filter: blur(4px);
    }
</style>
@endpush

@section('content')
<div class="command-os-report-page">
    <!-- 1. Hero Header -->
    <header class="command-os-report-hero mb-4">
        <div class="command-os-report-hero-left">
            <div class="command-os-report-hero-icon" aria-hidden="true">
                <i class="fas fa-bus"></i>
            </div>
            <div>
                <div class="command-os-report-hero-eyebrow">VIRTUAL MTA // POOL CONFIGURATION</div>
                <h1 class="command-os-report-hero-title">PMTA VMTA</h1>
                <p class="command-os-report-hero-desc">Virtual MTA pool mapping, IP-to-domain bindings, routing directives, and server sync.</p>
            </div>
        </div>
        <div class="command-os-report-hero-right">
            <div class="command-os-report-hero-meta">
                <span class="command-os-report-badge">VMTA ALLOCATOR</span>
            </div>
        </div>
    </header>

    <div id="pmta-vmta"
        data-push-url="{{ route('console.pmta-vmta.push', $workspace->id) }}"
        data-push-rows-url="{{ route('console.pmta-vmta.push-rows', $workspace->id) }}"
        data-restore-url="{{ route('console.pmta-vmta.restore', $workspace->id) }}"
        data-restore-v2-url="{{ route('console.pmta-vmta.restore-v2', $workspace->id) }}"
        data-rows-url="{{ route('console.pmta-vmta.rows', $workspace->id) }}"
        data-delete-url="{{ route('console.pmta-vmta.rows.destroy', $workspace->id) }}">

        <div class="row">
            <!-- 2. VMTA V2 Panel -->
            <div class="col-sm-12 col-lg-6 mb-4">
                <div class="command-os-table-card p-4 h-100">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                        <h3 style="font-size: 0.9375rem; font-weight: 700; color: var(--command-os-text, #1e293b); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                            <i class="fas fa-layer-group mr-1 text-purple"></i> PMTA VMTA 2.0 (Fast Bulk Mapping)
                        </h3>
                    </div>
                    <div class="form-group mb-3">
                        <label class="command-form-label" for="pmta-vmta-server-v2">Target Server:</label>
                        <select id="pmta-vmta-server-v2" class="form-control selectpicker" data-live-search="true" data-size="6" title="Select Server">
                            <option value="">Select Server</option>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}">{{ $server->name }} | {{ $server->ip_address }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="command-form-label" for="pmta-vmta-rows-v2">IP &amp; VMTA Directives (ip,vmta):</label>
                        <textarea id="pmta-vmta-rows-v2" class="form-control" rows="5" placeholder="192.168.1.1,vmta_domain_com" style="font-family: monospace;"></textarea>
                    </div>
                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                        <button type="button" id="pmta-vmta-push-v2" class="btn-command-primary">
                            <i class="fas fa-upload mr-1"></i> PUSH V2
                        </button>
                        <button type="button" id="pmta-vmta-reset-v2" class="btn-command-danger">
                            <i class="fas fa-trash-alt mr-1"></i> RESET V2
                        </button>
                    </div>
                </div>
            </div>

            <!-- 3. VMTA V1 Panel -->
            <div class="col-sm-12 col-lg-6 mb-4">
                <div class="command-os-table-card p-4 h-100">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                        <h3 style="font-size: 0.9375rem; font-weight: 700; color: var(--command-os-text, #1e293b); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                            <i class="fas fa-network-wired mr-1 text-purple"></i> PMTA VMTA Builder
                        </h3>
                    </div>
                    <div class="form-group mb-3">
                        <label class="command-form-label" for="pmta-vmta-ips">
                            IP Allocations:
                            <button type="button" class="btn-command-action ml-1" id="pmta-vmta-search-ips" title="Search IPs" aria-label="Search IPs" style="padding: 2px 8px; font-size: 0.75rem;">
                                <i class="fas fa-search"></i> SEARCH
                            </button>
                        </label>
                        <select id="pmta-vmta-ips" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 5" multiple title="Select Ips">
                            @foreach ($ips as $ip)
                                <option value="{{ $ip->id }}" data-server-id="{{ $ip->managed_server_id }}">{{ $ip->server?->name }} | {{ $ip->ip }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="command-form-label" for="pmta-vmta-domains">Domains (one per line):</label>
                        <textarea id="pmta-vmta-domains" class="form-control" rows="4" placeholder="example.com" style="font-family: monospace;"></textarea>
                    </div>
                    <div class="form-group d-flex justify-content-between align-items-center mb-3">
                        <div class="command-switch-box">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="pmta-vmta-route">
                                <label class="custom-control-label command-form-label mb-0" for="pmta-vmta-route">ROUTE DIRECTIVE</label>
                            </div>
                        </div>
                        <div class="d-flex align-items-center">
                            <label class="command-form-label mb-0 mr-2" for="pmta-vmta-duplicate">Duplicate:</label>
                            <input type="number" id="pmta-vmta-duplicate" class="form-control form-control-sm text-center" style="width: 70px;" min="1" value="1">
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                        <button type="button" id="pmta-vmta-push" class="btn-command-primary">
                            <i class="fas fa-upload mr-1"></i> PUSH
                        </button>
                        <button type="button" id="pmta-vmta-reset" class="btn-command-danger">
                            <i class="fas fa-trash-alt mr-1"></i> RESET
                        </button>
                    </div>
                    <div class="table-responsive mt-3">
                        <table id="pmta-vmta-result" class="table table-sm table-hover table-striped command-os-table"></table>
                    </div>
                    <div id="pmta-vmta-status" class="sr-only" role="status" aria-live="polite"></div>
                </div>
            </div>
        </div>

        <!-- 4. MY VMTA Table Card -->
        <div class="command-os-table-card mt-2">
            <div class="d-flex align-items-center justify-content-between p-3 border-bottom">
                <h3 style="font-size: 0.9375rem; font-weight: 700; color: var(--command-os-text, #1e293b); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                    <i class="fas fa-table mr-1 text-purple"></i> MY VMTA Records
                </h3>
            </div>
            <div class="table-responsive">
                <table id="pmta-vmta-table" class="table table-sm table-hover table-striped command-os-table" aria-label="VMTA records"></table>
            </div>
        </div>
    </div>
</div>

<!-- Search IPs Dialog -->
<dialog id="pmta-vmta-ip-search">
    <div class="command-modal-surface p-4">
        <div class="command-modal-hero mb-3">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon"><i class="fas fa-search"></i></div>
                <div>
                    <h3 class="command-modal-hero-title">SEARCH IPS</h3>
                    <div class="command-modal-hero-eyebrow">BATCH SERVER / IP FILTER</div>
                </div>
            </div>
        </div>
        <div class="form-group mb-3">
            <label class="command-form-label" for="pmta-vmta-ip-search-input">Server name or IP, one per line:</label>
            <textarea id="pmta-vmta-ip-search-input" class="form-control" rows="6" placeholder="server-1&#10;192.168.1.1" style="font-family: monospace;"></textarea>
        </div>
        <div class="command-modal-actions mt-3">
            <button type="button" class="btn-command-action" id="pmta-vmta-ip-search-cancel">CANCEL</button>
            <button type="button" class="btn-command-primary" id="pmta-vmta-ip-search-apply"><i class="fas fa-check mr-1"></i> SELECT IPS</button>
        </div>
    </div>
</dialog>
@endsection

@push('scripts')
<script src="{{ asset('console-assets/pmta-vmta-response.js') }}"></script>
<script>
(() => {
    const root = document.getElementById('pmta-vmta');
    if (!root) return;
    const status = document.getElementById('pmta-vmta-status');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const shouldRejectPmtaResponse = window.AlphaLinkPmtaVmtaResponse?.shouldRejectPmtaResponse ?? ((httpOk, result) => !httpOk || (result?.success === false && !Array.isArray(result?.results)));
    const api = async (url, method = 'GET', body = null) => {
        const response = await fetch(url, { method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: body ? JSON.stringify(body) : undefined });
        const result = await response.json().catch(() => ({}));
        if (shouldRejectPmtaResponse(response.ok, result)) throw new Error(result.message || Object.values(result.errors || {}).flat()[0] || 'PMTA operation failed.');
        return result;
    };
    const announce = (message, tone = 'info') => { status.textContent = message; if (window.AlphaLinkToast) window.AlphaLinkToast.show(message, tone); };
    const showSelectionError = async (message, statusMessage = message) => {
        status.textContent = statusMessage;
        if (typeof window.PmtaControls?.alert === 'function') { await window.PmtaControls.alert('Oops', message); return; }
        if (window.AlphaLinkToast) window.AlphaLinkToast.show(message, 'warning');
    };
    const domainInput = document.getElementById('pmta-vmta-domains');
    const routeToggle = document.getElementById('pmta-vmta-route');
    routeToggle.addEventListener('change', () => { domainInput.placeholder = routeToggle.checked ? 'Domain,route' : 'Domain'; });
    const serverSelectV2 = document.getElementById('pmta-vmta-server-v2');
    const ipSelect = document.getElementById('pmta-vmta-ips');
    const clearSelectionWarning = (message) => { if (status.textContent === message) status.textContent = ''; };
    const clearServerWarning = () => { if (serverSelectV2.value) clearSelectionWarning('Please select a server.'); };
    const clearIpWarning = () => { if (ipSelect.selectedOptions.length) clearSelectionWarning('Please select at least one IP.'); };
    serverSelectV2.addEventListener('change', clearServerWarning);
    ipSelect.addEventListener('change', clearIpWarning);
    if (window.jQuery) {
        window.jQuery(serverSelectV2).on('changed.bs.select', clearServerWarning);
        window.jQuery(ipSelect).on('changed.bs.select', clearIpWarning);
    }
    const ask = (title, text, verb) => typeof window.PmtaControls?.confirm === 'function' ? window.PmtaControls.confirm(title, text, verb, true) : Promise.resolve(window.confirm(`${title}\n\n${text}`));
    const showResults = (results) => {
        const table = document.getElementById('pmta-vmta-result');
        table.replaceChildren();
        const head = document.createElement('thead');
        const header = document.createElement('tr');
        ['Server', 'Result'].forEach((label) => { const cell = document.createElement('th'); cell.textContent = label; header.append(cell); });
        head.append(header);
        const body = document.createElement('tbody');
        (results || []).forEach((entry) => {
            const row = document.createElement('tr');
            [entry.server, entry.message].forEach((value) => { const cell = document.createElement('td'); cell.textContent = value || ''; row.append(cell); });
            body.append(row);
        });
        table.append(head, body);
    };
    const promptForLines = (label) => window.PmtaControls.prompt(label, 'Enter one value per line');
    let grid = null;
    if (window.jQuery?.fn?.DataTable) {
        const $ = window.jQuery;
        grid = $('#pmta-vmta-table').DataTable({
            ajax: { url: root.dataset.rowsUrl, dataSrc: 'data' },
            dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
            buttons: [
                { extend: 'pageLength', className: 'btn-command-action' },
                { text: '<i class="fas fa-sync mr-1"></i> REFRESH', className: 'btn-command-action', titleAttr: 'REFRESH', action: (event, table) => table.ajax.reload(null, false) },
                { text: '<i class="far fa-square mr-1"></i> NONE', className: 'btn-command-action', titleAttr: 'SELECT NONE', action: (event, table) => table.rows({ page: 'current' }).deselect() },
                { text: '<i class="far fa-check-square mr-1"></i> ALL', className: 'btn-command-action', titleAttr: 'SELECT ALL', action: (event, table) => table.rows({ page: 'current' }).select() },
                { text: '<i class="fas fa-trash mr-1"></i> REMOVE', className: 'btn-command-danger', titleAttr: 'REMOVE SELECTED', action: async (event, table, node) => {
                    const ids = table.rows({ selected: true }).data().toArray().map((row) => row.id);
                    if (!ids.length || !await ask('Remove selected VMTA rows?', 'The selected records will be removed from your AlphaLink list.', 'Remove')) return;
                    table.buttons(node).disable();
                    try {
                        const result = await api(root.dataset.deleteUrl, 'DELETE', { ids });
                        announce(result.message, 'success');
                        table.ajax.reload(null, false);
                    } catch (error) { announce(error.message, 'danger'); }
                    finally { table.buttons(node).enable(); }
                } },
                { text: '<i class="fas fa-search mr-1"></i> IDS', className: 'btn-command-action', titleAttr: 'SEARCH BY IDS', action: async (event, table) => {
                    const text = await promptForLines('Search IDs');
                    table.columns().search('');
                    if (text) table.column(1).search([...new Set(text.split(/\r?\n/).map((value) => value.trim()).filter(Boolean))].join('|'), true, false);
                    table.draw();
                } },
                { text: '<i class="fas fa-search mr-1"></i> DMN', className: 'btn-command-action', titleAttr: 'SEARCH BY DOMAINS', action: async (event, table) => {
                    const text = await promptForLines('Search domains');
                    table.columns().search('');
                    if (text) table.column(4).search([...new Set(text.split(/\r?\n/).map((value) => value.trim()).filter(Boolean))].join('|'), true, false);
                    table.draw();
                } },
            ],
            order: [[1, 'desc']],
            pageLength: 50,
            select: { style: 'multi+shift', selector: 'td:first-child' },
            rowCallback: (row, data) => { if (!data.is_active) $(row).find('td').addClass('table-danger'); },
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'select-checkbox' },
                { title: '#', data: 'id', render: (val, type) => type === 'display' ? `<span class="command-mono-badge">#${val}</span>` : val },
                { title: 'NAME', data: 'name', render: (val, type) => type === 'display' ? `<strong>${val || ''}</strong>` : val },
                { title: 'IP', data: 'ip', render: (val, type) => type === 'display' ? `<span style="font-family: monospace; font-weight: 600; color: #6366f1;">${val || ''}</span>` : val },
                { title: 'DOMAIN', data: 'dns', render: (val, type) => type === 'display' ? `<span style="font-weight: 600; color: #8b5cf6;">${val || ''}</span>` : val },
                { title: 'CREATED AT', data: 'created_at', render: (val, type) => type === 'display' ? `<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">${val || ''}</span>` : val },
            ],
        });
    }
    const restore = async (ipIds) => {
        if (!ipIds.length) throw new Error('Select at least one IP.');
        return api(root.dataset.restoreUrl, 'POST', { ip_ids: ipIds });
    };
    const restoreV2 = async (serverId) => api(root.dataset.restoreV2Url, 'POST', { server_id: serverId });
    document.getElementById('pmta-vmta-push-v2').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        try {
            const result = await api(root.dataset.pushRowsUrl, 'POST', { server_id: document.getElementById('pmta-vmta-server-v2').value, rows: document.getElementById('pmta-vmta-rows-v2').value });
            announce(result.message, 'success');
            if (grid) grid.ajax.reload(null, false);
        } catch (error) { announce(error.message, 'danger'); }
        finally { button.disabled = false; }
    });
    document.getElementById('pmta-vmta-reset-v2').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const serverId = serverSelectV2.value;
        if (!serverId) { await showSelectionError('Please select server', 'Please select a server.'); return; }
        clearSelectionWarning('Please select a server.');
        if (!await ask('Reset VMTA on the selected server?', 'This removes your VMTA configuration file from the selected server.', 'Reset')) return;
        const original = button.innerHTML;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.textContent = 'RESETTING…';
        try {
            const result = await restoreV2(serverId);
            showResults(result.results);
            announce(result.results.map((row) => `${row.server}: ${row.message}`).join(' · '), result.success ? 'success' : 'danger');
            if (grid) grid.ajax.reload(null, false);
        } catch (error) { announce(error.message, 'danger'); }
        finally { button.disabled = false; button.removeAttribute('aria-busy'); button.innerHTML = original; }
    });
    document.getElementById('pmta-vmta-push').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const ipIds = Array.from(document.getElementById('pmta-vmta-ips').selectedOptions).map((option) => option.value);
        const domains = Array.from(new Set(domainInput.value.split(/\r?\n/).map((value) => value.trim()).filter(Boolean)));
        if (!ipIds.length) { announce('Please select at least one IP.', 'warning'); return; }
        if (!domains.length) { announce('Please enter at least one domain.', 'warning'); return; }
        button.disabled = true;
        try {
            const result = await api(root.dataset.pushUrl, 'POST', { ip_ids: ipIds, domains: domains.join('\n'), route: routeToggle.checked, duplicate: document.getElementById('pmta-vmta-duplicate').value });
            showResults(result.results);
            announce(result.results.map((row) => `${row.server}: ${row.message}`).join(' · '), result.success ? 'success' : 'danger');
            if (grid) grid.ajax.reload(null, false);
        } catch (error) { showResults([]); announce(error.message, 'danger'); }
        finally { button.disabled = false; }
    });
    document.getElementById('pmta-vmta-reset').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const ipIds = Array.from(ipSelect.selectedOptions).map((option) => option.value);
        if (!ipIds.length) { await showSelectionError('Please select at least one ip', 'Please select at least one IP.'); return; }
        clearSelectionWarning('Please select at least one IP.');
        if (!await ask('Reset VMTA on the selected servers?', 'This removes your VMTA configuration file from each selected server.', 'Reset')) return;
        const original = button.innerHTML;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.textContent = 'RESETTING…';
        try {
            const result = await restore(ipIds);
            showResults(result.results);
            announce(result.results.map((row) => `${row.server}: ${row.message}`).join(' · '), result.success ? 'success' : 'danger');
            if (grid) grid.ajax.reload(null, false);
        } catch (error) { announce(error.message, 'danger'); }
        finally { button.disabled = false; button.removeAttribute('aria-busy'); button.innerHTML = original; }
    });
    const searchDialog = document.getElementById('pmta-vmta-ip-search');
    document.getElementById('pmta-vmta-search-ips').addEventListener('click', () => searchDialog.showModal());
    document.getElementById('pmta-vmta-ip-search-cancel').addEventListener('click', () => searchDialog.close());
    document.getElementById('pmta-vmta-ip-search-apply').addEventListener('click', () => {
        const terms = document.getElementById('pmta-vmta-ip-search-input').value.split(/\r?\n/).map((v) => v.trim().toLowerCase()).filter(Boolean);
        const select = document.getElementById('pmta-vmta-ips');
        Array.from(select.options).forEach((option) => {
            const text = option.textContent.toLowerCase();
            option.selected = terms.some((term) => text.includes(term));
        });
        select.dispatchEvent(new Event('change', { bubbles: true }));
        if (window.jQuery?.fn.selectpicker) window.jQuery(select).selectpicker('refresh');
        searchDialog.close();
    });
    if (window.jQuery?.fn.selectpicker) window.jQuery('.selectpicker').selectpicker();
})();
</script>
@endpush
