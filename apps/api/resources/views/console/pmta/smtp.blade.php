@extends('layouts.dms-admin')

@section('title', 'Pmta SMTP')

@section('content')
<div id="pmta-smtp" data-push-url="{{ route('console.pmta-smtp.push', $workspace->id) }}" data-restore-url="{{ route('console.pmta-smtp.restore', $workspace->id) }}" data-rows-url="{{ route('console.pmta-smtp.rows', $workspace->id) }}" data-delete-url="{{ route('console.pmta-smtp.rows.destroy', $workspace->id) }}">
    <section class="block block-rounded">
        <div class="block-header"><h3 class="block-title">Pmta SMTP</h3><div class="block-options"><button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle fullscreen"><i class="fas fa-expand-arrows-alt"></i></button></div></div>
        <div class="block-content block-content-full">
            <div class="row justify-content-center"><div class="col-sm-12 col-md-6">
                <div class="form-group">
                    <label for="pmta-smtp-ips">Ips: <button type="button" class="btn btn-link btn-sm p-0" id="pmta-smtp-search-ips" title="Search Servers" aria-label="Search IPs"><i class="fas fa-search"></i></button></label>
                    <select id="pmta-smtp-ips" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 5" multiple title="Select Ips">@foreach ($ips as $ip)<option value="{{ $ip->id }}" data-server-id="{{ $ip->managed_server_id }}">{{ $ip->server?->name }} | {{ $ip->ip }}</option>@endforeach</select>
                </div>
                <div class="form-group"><label for="pmta-smtp-lines">SMTP: (username,password,route,port,domain*)</label><textarea id="pmta-smtp-lines" rows="5" class="form-control" placeholder="username,password,route,port"></textarea><small class="text-info">* OPTIONAL</small></div>
                <div class="form-group"><div class="custom-control custom-switch custom-control-inline"><input type="checkbox" class="custom-control-input" id="pmta-smtp-gscript"><label class="custom-control-label" for="pmta-smtp-gscript">GSCRIPT</label><button type="button" class="btn btn-link btn-sm p-0 ml-1" id="pmta-smtp-copy-script" title="Copy script" aria-label="Copy Google Apps Script"><i class="fas fa-copy"></i></button></div></div>
                <div class="form-group"><button type="button" id="pmta-smtp-push" class="btn btn-primary">PUSH</button><button type="button" id="pmta-smtp-reset" class="btn btn-danger float-md-right">RESET</button></div>
                <div class="form-group"><div class="table-responsive"><table id="pmta-smtp-result" class="table table-sm table-hover table-bordered"></table></div></div>
                <div id="pmta-smtp-status" class="sr-only" role="status" aria-live="polite"></div>
            </div></div>
        </div>
    </section>
    <section class="block block-rounded mb-2">
        <div class="block-header block-header-default"><h3 class="block-title">MY SMTP</h3><div class="block-options"><button type="button" class="btn-block-option" data-toggle="block-option" data-action="content_toggle" aria-label="Collapse MY SMTP"><i class="si si-arrow-up"></i></button></div></div>
        <div class="block-content block-content-full"><div class="table-responsive"><table id="pmta-smtp-table" class="table table-sm table-hover table-striped"></table></div></div>
    </section>
</div>
<dialog id="pmta-smtp-ip-search"><div class="p-3" style="min-width:min(520px,90vw)"><h3 class="h5">Search IPs</h3><label for="pmta-smtp-ip-search-input">Server or IP, one per line</label><textarea id="pmta-smtp-ip-search-input" class="form-control" rows="6"></textarea><div class="text-right mt-3"><button type="button" class="btn btn-alt-secondary" id="pmta-smtp-ip-search-cancel">Cancel</button> <button type="button" class="btn btn-primary" id="pmta-smtp-ip-search-apply">Select IPs</button></div></div></dialog>
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
            dom: '<"dms-table-tools"B>frtip',
            buttons: [
                { extend: 'pageLength' },
                { text: '<i class="fas fa-sync"></i>', titleAttr: 'REFRESH', action: (event, table) => table.ajax.reload(null, false) },
                { text: '<i class="far fa-square"></i>', titleAttr: 'SELECT NONE', action: (event, table) => table.rows({ page: 'current' }).deselect() },
                { text: '<i class="far fa-check-square"></i>', titleAttr: 'SELECT ALL', action: (event, table) => table.rows({ page: 'current' }).select() },
                { text: '<i class="fas fa-trash"></i>', titleAttr: 'REMOVE SELECTED', action: async (event, table, node) => {
                    const ids = table.rows({ selected: true }).data().toArray().map((row) => row.id);
                    if (!ids.length || !await ask('Remove selected SMTP rows?', 'The selected records will be removed from your AlphaLink list.', 'Remove')) return;
                    table.buttons(node).disable();
                    try { const result = await api(root.dataset.deleteUrl, 'DELETE', { ids }); announce(result.message, 'success'); table.ajax.reload(null, false); }
                    catch (error) { announce(error.message, 'danger'); }
                    finally { table.buttons(node).enable(); }
                } },
                { text: 'IDS <i class="fas fa-search"></i>', titleAttr: 'SEARCH BY IDS', action: async (event, table) => { const text = await promptForLines('Search IDs'); table.columns().search(''); if (text) table.column(1).search([...new Set(text.split(/\r?\n/).map((value) => value.trim()).filter(Boolean))].join('|'), true, false); table.draw(); } },
                { text: 'USR <i class="fas fa-search"></i>', titleAttr: 'SEARCH BY USERNAMES', action: async (event, table) => { const text = await promptForLines('Search usernames'); table.columns().search(''); if (text) table.column(4).search([...new Set(text.split(/\r?\n/).map((value) => value.trim()).filter(Boolean))].join('|'), true, false); table.draw(); } },
            ],
            order: [[1, 'desc']], pageLength: 50, select: { style: 'os', selector: 'td:first-child' },
            rowCallback: (row, data) => { if (!data.is_active) window.jQuery(row).find('td').addClass('table-danger'); },
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'select-checkbox' },
                { title: '#', data: 'id' }, { title: 'IP', data: 'ip' }, { title: 'DOMAIN', data: 'dns' },
                { title: 'USERNAME', data: 'username' }, { title: 'CREATED AT', data: 'created_at' },
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
