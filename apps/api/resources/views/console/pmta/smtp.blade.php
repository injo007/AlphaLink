@extends('layouts.admin-shell')

@section('title', 'Pmta SMTP')

@push('styles')
<style>
    .command-switch-box {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    #pmta-smtp-ip-search {
        border: none;
        border-radius: 16px;
        background: var(--command-os-card-bg, #ffffff);
        color: var(--command-os-text, #1e293b);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
        padding: 0;
        max-width: 540px;
        width: 90vw;
    }
    #pmta-smtp-ip-search::backdrop {
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
                <i class="fas fa-bus-alt"></i>
            </div>
            <div>
                <div class="command-os-report-hero-eyebrow">SMTP SUITE // AUTHENTICATED RELAYS</div>
                <h1 class="command-os-report-hero-title">PMTA SMTP</h1>
                <p class="command-os-report-hero-desc">Direct SMTP user credential provisioning, port bindings, relay routing, and Google Apps Script integrations.</p>
            </div>
        </div>
        <div class="command-os-report-hero-right">
            <div class="command-os-report-hero-meta">
                <span class="command-os-report-badge">SMTP RELAYS</span>
            </div>
        </div>
    </header>

    <div id="pmta-smtp"
        data-push-url="{{ route('console.pmta-smtp.push', $workspace->id) }}"
        data-restore-url="{{ route('console.pmta-smtp.restore', $workspace->id) }}"
        data-rows-url="{{ route('console.pmta-smtp.rows', $workspace->id) }}"
        data-delete-url="{{ route('console.pmta-smtp.rows.destroy', $workspace->id) }}">

        <!-- 2. Form Card -->
        <div class="command-os-table-card p-4 mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                <h3 style="font-size: 0.9375rem; font-weight: 700; color: var(--command-os-text, #1e293b); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                    <i class="fas fa-plus-circle mr-1 text-purple"></i> Configure &amp; Deploy SMTP Identities
                </h3>
            </div>
            <div class="row justify-content-center">
                <div class="col-sm-12 col-md-8">
                    <div class="form-group mb-3">
                        <label class="command-form-label" for="pmta-smtp-ips">
                            IP Allocations:
                            <button type="button" class="btn-command-action ml-1" id="pmta-smtp-search-ips" title="Search Servers" aria-label="Search IPs" style="padding: 2px 8px; font-size: 0.75rem;">
                                <i class="fas fa-search"></i> SEARCH
                            </button>
                        </label>
                        <select id="pmta-smtp-ips" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 5" multiple title="Select Ips">
                            @foreach ($ips as $ip)
                                <option value="{{ $ip->id }}" data-server-id="{{ $ip->managed_server_id }}">{{ $ip->server?->name }} | {{ $ip->ip }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="command-form-label" for="pmta-smtp-lines">
                            SMTP Credentials: <span style="font-size: 0.75rem; color: var(--command-os-text-muted, #64748b);">(username,password,route,port,domain*)</span>
                        </label>
                        <textarea id="pmta-smtp-lines" rows="5" class="form-control" placeholder="username,password,route,port" style="font-family: monospace;"></textarea>
                        <small class="text-info mt-1 d-block" style="font-size: 0.75rem;">* OPTIONAL: domain directive</small>
                    </div>
                    <div class="form-group mb-3 d-flex align-items-center justify-content-between">
                        <div class="command-switch-box">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="pmta-smtp-gscript">
                                <label class="custom-control-label command-form-label mb-0" for="pmta-smtp-gscript">GSCRIPT COMPATIBILITY</label>
                            </div>
                            <button type="button" class="btn-command-action ml-2" id="pmta-smtp-copy-script" title="Copy Google Apps Script" aria-label="Copy Google Apps Script" style="padding: 2px 8px; font-size: 0.75rem;">
                                <i class="fas fa-copy mr-1"></i> COPY SCRIPT
                            </button>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                        <button type="button" id="pmta-smtp-push" class="btn-command-primary">
                            <i class="fas fa-upload mr-1"></i> PUSH SMTP
                        </button>
                        <button type="button" id="pmta-smtp-reset" class="btn-command-danger">
                            <i class="fas fa-trash-alt mr-1"></i> RESET SMTP
                        </button>
                    </div>
                    <div class="table-responsive mt-3">
                        <table id="pmta-smtp-result" class="table table-sm table-hover table-striped command-os-table"></table>
                    </div>
                    <div id="pmta-smtp-status" class="sr-only" role="status" aria-live="polite"></div>
                </div>
            </div>
        </div>

        <!-- 3. MY SMTP Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between p-3 border-bottom">
                <h3 style="font-size: 0.9375rem; font-weight: 700; color: var(--command-os-text, #1e293b); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                    <i class="fas fa-table mr-1 text-purple"></i> MY SMTP Identities
                </h3>
            </div>
            <div class="table-responsive">
                <table id="pmta-smtp-table" class="table table-sm table-hover table-striped command-os-table" aria-label="SMTP records"></table>
            </div>
        </div>
    </div>
</div>

<!-- Search IPs Dialog -->
<dialog id="pmta-smtp-ip-search">
    <div class="command-modal-surface p-4">
        <div class="command-modal-hero mb-3">
            <div class="command-modal-hero-left">
                <div class="command-modal-hero-icon"><i class="fas fa-search"></i></div>
                <div>
                    <h3 class="command-modal-hero-title">SEARCH IPS</h3>
                    <div class="command-modal-hero-eyebrow">SERVER OR IP FILTER</div>
                </div>
            </div>
        </div>
        <div class="form-group mb-3">
            <label class="command-form-label" for="pmta-smtp-ip-search-input">Server or IP, one per line:</label>
            <textarea id="pmta-smtp-ip-search-input" class="form-control" rows="6" placeholder="server-1&#10;192.168.1.1" style="font-family: monospace;"></textarea>
        </div>
        <div class="command-modal-actions mt-3">
            <button type="button" class="btn-command-action" id="pmta-smtp-ip-search-cancel">CANCEL</button>
            <button type="button" class="btn-command-primary" id="pmta-smtp-ip-search-apply"><i class="fas fa-check mr-1"></i> SELECT IPS</button>
        </div>
    </div>
</dialog>
@endsection

@push('scripts')
<script>
(() => {
    const root = document.getElementById('pmta-smtp');
    if (!root) return;
    const status = document.getElementById('pmta-smtp-status');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const api = async (url, method = 'GET', body = null) => {
        const response = await fetch(url, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: body ? JSON.stringify(body) : undefined });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || (result.success === false && !Array.isArray(result.results))) throw new Error(result.message || Object.values(result.errors || {}).flat()[0] || 'PMTA operation failed.');
        return result;
    };
    const announce = (message, tone = 'info') => {
        status.textContent = message;
        if (window.AlphaLinkToast) window.AlphaLinkToast.show(message, tone);
    };
    const ask = (title, text, verb) => window.PmtaControls.confirm(title, text, verb, true);
    const showResults = (results) => {
        const table = document.getElementById('pmta-smtp-result');
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
        grid = $('#pmta-smtp-table').DataTable({
            ajax: { url: root.dataset.rowsUrl, dataSrc: 'data' },
            dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
            buttons: [
                { extend: 'pageLength', className: 'btn-command-action' },
                { text: '<i class="fas fa-sync mr-1"></i> REFRESH', className: 'btn-command-action', titleAttr: 'REFRESH', action: (event, table) => table.ajax.reload(null, false) },
                { text: '<i class="far fa-square mr-1"></i> NONE', className: 'btn-command-action', titleAttr: 'SELECT NONE', action: (event, table) => table.rows({ page: 'current' }).deselect() },
                { text: '<i class="far fa-check-square mr-1"></i> ALL', className: 'btn-command-action', titleAttr: 'SELECT ALL', action: (event, table) => table.rows({ page: 'current' }).select() },
                { text: '<i class="fas fa-trash mr-1"></i> REMOVE', className: 'btn-command-danger', titleAttr: 'REMOVE SELECTED', action: async (event, table, node) => {
                    const ids = table.rows({ selected: true }).data().toArray().map((row) => row.id);
                    if (!ids.length || !await ask('Remove selected SMTP rows?', 'The selected records will be removed from your AlphaLink list.', 'Remove')) return;
                    table.buttons(node).disable();
                    try { const result = await api(root.dataset.deleteUrl, 'DELETE', { ids }); announce(result.message, 'success'); table.ajax.reload(null, false); }
                    catch (error) { announce(error.message, 'danger'); }
                    finally { table.buttons(node).enable(); }
                } },
                { text: '<i class="fas fa-search mr-1"></i> IDS', className: 'btn-command-action', titleAttr: 'SEARCH BY IDS', action: async (event, table) => { const text = await promptForLines('Search IDs'); table.columns().search(''); if (text) table.column(1).search([...new Set(text.split(/\r?\n/).map((value) => value.trim()).filter(Boolean))].join('|'), true, false); table.draw(); } },
                { text: '<i class="fas fa-search mr-1"></i> USR', className: 'btn-command-action', titleAttr: 'SEARCH BY USERNAMES', action: async (event, table) => { const text = await promptForLines('Search usernames'); table.columns().search(''); if (text) table.column(4).search([...new Set(text.split(/\r?\n/).map((value) => value.trim()).filter(Boolean))].join('|'), true, false); table.draw(); } },
            ],
            order: [[1, 'desc']], pageLength: 50, select: { style: 'multi+shift', selector: 'td:first-child' },
            rowCallback: (row, data) => { if (!data.is_active) window.jQuery(row).find('td').addClass('table-danger'); },
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'select-checkbox' },
                { title: '#', data: 'id', render: (val, type) => type === 'display' ? `<span class="command-mono-badge">#${val}</span>` : val },
                { title: 'IP', data: 'ip', render: (val, type) => type === 'display' ? `<span style="font-family: monospace; font-weight: 600; color: #6366f1;">${val || ''}</span>` : val },
                { title: 'DOMAIN', data: 'dns', render: (val, type) => type === 'display' ? `<span style="font-weight: 600; color: #8b5cf6;">${val || ''}</span>` : val },
                { title: 'USERNAME', data: 'username', render: (val, type) => type === 'display' ? `<strong>${val || ''}</strong>` : val },
                { title: 'CREATED AT', data: 'created_at', render: (val, type) => type === 'display' ? `<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">${val || ''}</span>` : val },
            ],
        });
    }
    const selectedIpIds = () => [...document.getElementById('pmta-smtp-ips').selectedOptions].map((option) => option.value);
    document.getElementById('pmta-smtp-push').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const lines = document.getElementById('pmta-smtp-lines').value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
        const smtp = [...new Map(lines.map((line) => line.split(',').map((value) => value.trim())).filter((row) => row.length >= 2).map((row) => [JSON.stringify(row), row])).values()];
        const googleScript = document.getElementById('pmta-smtp-gscript').checked;
        if (!selectedIpIds().length) { announce('Please select at least one IP.', 'warning'); return; }
        if (!smtp.length) { announce('Please enter at least one SMTP row.', 'warning'); return; }
        button.disabled = true;
        try {
            const result = await api(root.dataset.pushUrl, 'POST', { ip_ids: selectedIpIds(), smtp, google_script: googleScript });
            showResults(result.results);
            announce((result.results || []).map((row) => `${row.server}: ${row.message}`).join(' · '), result.success ? 'success' : 'danger');
            if (grid) grid.ajax.reload(null, false);
        } catch (error) { showResults([]); announce(error.message, 'danger'); }
        finally { button.disabled = false; }
    });
    document.getElementById('pmta-smtp-reset').addEventListener('click', async (event) => {
        const ipIds = selectedIpIds();
        if (!ipIds.length) { announce('Please select at least one IP.', 'warning'); return; }
        if (!await ask('Reset SMTP on the selected servers?', 'This removes your SMTP configuration file from each selected server.', 'Reset')) return;
        const button = event.currentTarget; button.disabled = true;
        try { const result = await api(root.dataset.restoreUrl, 'POST', { ip_ids: ipIds }); showResults(result.results); announce(result.results.map((row) => `${row.server}: ${row.message}`).join(' · '), result.success ? 'success' : 'danger'); if (grid) grid.ajax.reload(null, false); }
        catch (error) { announce(error.message, 'danger'); }
        finally { button.disabled = false; }
    });
    document.getElementById('pmta-smtp-gscript').addEventListener('change', (event) => {
        const enabled = event.currentTarget.checked;
        document.getElementById('pmta-smtp-lines').placeholder = enabled ? 'username,token' : 'username,password,route,port,domain* (you can use rnd tags)';
    });
    document.getElementById('pmta-smtp-copy-script').addEventListener('click', async () => {
        const script = 'const JSON_MIME_TYPE=ContentService.MimeType.JSON;function doPost(e){try{let t=JSON.parse(e.postData.getDataAsString()),r=sendEmail(t);return createResponse("success","Email sent successfully",r)}catch(s){return console.error("doPost Error:",s),createResponse("error","Failed to send email",s)}}function sendEmail({toEmail:e="",from:t="",fromName:r=t||"-",subject:s="-",body:n=""}){return MailApp.sendEmail({to:e,subject:s,htmlBody:n,name:r}),{to:e,from:t,subject:s,timestamp:new Date().toISOString()}}function createResponse(e,t,r=null){let s={status:e,message:t,...("success"===e&&{quota:MailApp.getRemainingDailyQuota()}),...(r&&{data:"success"===e?r:void 0,error:"error"===e?r.toString():void 0})};return ContentService.createTextOutput(JSON.stringify(s)).setMimeType(JSON_MIME_TYPE)}';
        try { await navigator.clipboard.writeText(script); announce('Google Apps Script copied to clipboard.', 'success'); }
        catch { const field = document.createElement('textarea'); field.value = script; document.body.append(field); field.select(); document.execCommand('copy'); field.remove(); announce('Google Apps Script copied to clipboard.', 'success'); }
    });
    const dialog = document.getElementById('pmta-smtp-ip-search');
    document.getElementById('pmta-smtp-search-ips').addEventListener('click', () => dialog.showModal());
    document.getElementById('pmta-smtp-ip-search-cancel').addEventListener('click', () => dialog.close());
    document.getElementById('pmta-smtp-ip-search-apply').addEventListener('click', () => {
        const terms = document.getElementById('pmta-smtp-ip-search-input').value.split(/\r?\n/).map((value) => value.trim().toLowerCase()).filter(Boolean);
        const select = document.getElementById('pmta-smtp-ips');
        Array.from(select.options).forEach((option) => { option.selected = terms.some((term) => option.textContent.toLowerCase().includes(term)); });
        select.dispatchEvent(new Event('change', { bubbles: true }));
        if (window.jQuery?.fn.selectpicker) window.jQuery(select).selectpicker('refresh');
        dialog.close();
    });
    if (window.jQuery?.fn.selectpicker) window.jQuery('.selectpicker').selectpicker();
})();
</script>
@endpush
