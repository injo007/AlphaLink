@extends('layouts.admin-shell')

@section('title', 'Pmta SMTP')

@push('styles')
<style>
    .pmta-smtp-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1.35fr);
        gap: 20px;
        align-items: start;
    }

    .pmta-smtp-panel {
        padding: 18px;
        border-radius: 14px;
        border: 1px solid var(--co-border, #dfe3f1);
        background: var(--co-surface-muted, #f7f8fd);
    }

    html[data-command-theme='dark'] .pmta-smtp-panel {
        background: rgba(15, 23, 42, 0.45);
        border-color: var(--co-border, #202e55);
    }

    .pmta-smtp-option-tile {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 12px 14px;
        border-radius: 12px;
        border: 1px solid var(--co-border, #dfe3f1);
        background: var(--co-surface, #ffffff);
        margin-top: 12px;
    }

    html[data-command-theme='dark'] .pmta-smtp-option-tile {
        background: var(--co-surface, #111d3b);
        border-color: var(--co-border, #202e55);
    }

    .pmta-smtp-count-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 11px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.04em;
        border: 1px solid rgba(121, 61, 246, 0.35);
        background: rgba(121, 61, 246, 0.12);
        color: var(--co-purple, #793df6);
    }

    .pmta-smtp-count-pill.is-muted {
        border-color: var(--co-border, #dfe3f1);
        background: var(--co-surface, #ffffff);
        color: var(--co-muted, #5f6b89);
    }

    html[data-command-theme='dark'] .pmta-smtp-count-pill.is-muted {
        background: rgba(15, 23, 42, 0.55);
    }

    .pmta-smtp-format-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 10px;
    }

    .pmta-smtp-chip {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 11px;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 6px;
        border: 1px solid var(--co-border, #dfe3f1);
        background: var(--co-surface, #ffffff);
        color: var(--co-muted, #5f6b89);
    }

    html[data-command-theme='dark'] .pmta-smtp-chip {
        background: rgba(15, 23, 42, 0.65);
    }

    .pmta-smtp-imap-controls {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        padding: 14px 16px;
        border-radius: 12px;
        border: 1px solid var(--co-border, #dfe3f1);
        background: var(--co-surface-muted, #f7f8fd);
        margin-bottom: 16px;
    }

    html[data-command-theme='dark'] .pmta-smtp-imap-controls {
        background: rgba(15, 23, 42, 0.45);
    }

    .pmta-imap-row-selected {
        background: rgba(121, 61, 246, 0.08) !important;
    }

    .pmta-main-ip-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.06em;
        padding: 2px 7px;
        border-radius: 999px;
        background: rgba(16, 185, 129, 0.14);
        color: #059669;
        border: 1px solid rgba(16, 185, 129, 0.3);
        margin-left: 6px;
    }

    html[data-command-theme='dark'] .pmta-main-ip-badge {
        color: #34d399;
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

    @media (max-width: 992px) {
        .pmta-smtp-grid,
        .pmta-smtp-imap-controls {
            grid-template-columns: minmax(0, 1fr);
        }
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
                <p class="command-os-report-hero-desc">Provision authenticated SMTP virtual-MTA identities, load Main IP IMAP accounts, and push relay routes cleanly per server.</p>
            </div>
        </div>
        <div class="command-os-report-hero-right d-flex flex-wrap align-items-center" style="gap: 8px;">
            <span class="command-os-report-badge">{{ count($servers) }} SERVER(S)</span>
            <span class="command-os-report-badge" id="pmta-smtp-hero-selected">0 SELECTED</span>
        </div>
    </header>

    <div id="pmta-smtp"
        data-push-url="{{ route('console.pmta-smtp.push', $workspace->id) }}"
        data-restore-url="{{ route('console.pmta-smtp.restore', $workspace->id) }}"
        data-rows-url="{{ route('console.pmta-smtp.rows', $workspace->id) }}"
        data-delete-url="{{ route('console.pmta-smtp.rows.destroy', $workspace->id) }}"
        data-imap-accounts-url="{{ route('console.pmta-smtp.imap-accounts', $workspace->id) }}"
        data-push-imap-url="{{ route('console.pmta-smtp.push-imap', $workspace->id) }}">

        <!-- 2. Main SMTP Configuration Card -->
        <section class="command-os-table-card p-4 mb-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 pb-3 border-bottom" style="gap: 10px;">
                <div>
                    <h2 style="font-size: 0.95rem; font-weight: 800; color: var(--co-ink, #17203e); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                        <i class="fas fa-plus-circle mr-2 text-purple" aria-hidden="true"></i>Configure &amp; Deploy SMTP Identities
                    </h2>
                    <small style="color: var(--co-muted, #5f6b89);">Select target server IPs, choose manual or IMAP-provisioned accounts, and push SMTP routes to PowerMTA.</small>
                </div>
                <div class="d-flex flex-wrap align-items-center" style="gap: 8px;">
                    <span class="pmta-smtp-count-pill is-muted" id="pmta-smtp-ip-selected-badge">
                        <i class="fas fa-network-wired" aria-hidden="true"></i> 0 IPs Selected
                    </span>
                    <span class="pmta-smtp-count-pill" id="pmta-smtp-section-selected-count">
                        <i class="fas fa-check-circle" aria-hidden="true"></i> 0 Accounts Selected
                    </span>
                </div>
            </div>

            <div class="pmta-smtp-grid">
                <!-- Left Column: Target IPs & Provisioning Options -->
                <div class="pmta-smtp-panel">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <label class="command-form-label mb-0" for="pmta-smtp-ips">Target IP Allocations</label>
                        <button type="button" class="btn-command-action" id="pmta-smtp-search-ips" title="Search Servers or IPs" aria-label="Search IPs" style="padding: 4px 10px; font-size: 0.75rem;">
                            <i class="fas fa-search mr-1"></i> Search IPs
                        </button>
                    </div>
                    <select id="pmta-smtp-ips" class="form-control selectpicker" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 4" multiple title="Choose target server IPs...">
                        @foreach ($ips as $ip)
                            <option value="{{ $ip->id }}" data-server-id="{{ $ip->managed_server_id }}">{{ $ip->server?->name }} | {{ $ip->ip }}</option>
                        @endforeach
                    </select>

                    <div class="pmta-smtp-option-tile">
                        <div>
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="pmta-smtp-gscript">
                                <label class="custom-control-label command-form-label mb-0" for="pmta-smtp-gscript">GScript Compatibility</label>
                            </div>
                            <small style="color: var(--co-muted, #5f6b89);">Format lines as <code>username,token</code> for Google Apps Script relays.</small>
                        </div>
                        <button type="button" class="btn-command-action" id="pmta-smtp-copy-script" title="Copy Google Apps Script" aria-label="Copy Google Apps Script" style="padding: 5px 10px; font-size: 0.75rem;">
                            <i class="fas fa-copy mr-1"></i> Copy Script
                        </button>
                    </div>

                    <div class="pmta-smtp-option-tile">
                        <div>
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="pmta-smtp-show-imap">
                                <label class="custom-control-label command-form-label mb-0" for="pmta-smtp-show-imap">IMAP-Installed Accounts (Main IP)</label>
                            </div>
                            <small style="color: var(--co-muted, #5f6b89);">Load existing Dovecot accounts from each IMAP server&rsquo;s Main IP.</small>
                        </div>
                        <span class="pmta-smtp-count-pill is-muted" id="pmta-smtp-imap-tile-count">0 selected</span>
                    </div>
                </div>

                <!-- Right Column: SMTP Lines & Push Actions -->
                <div class="pmta-smtp-panel">
                    <div class="d-flex flex-wrap align-items-center justify-content-between mb-2" style="gap: 8px;">
                        <label class="command-form-label mb-0" for="pmta-smtp-lines">SMTP Credentials Payload</label>
                        <span class="pmta-smtp-count-pill is-muted" id="pmta-smtp-lines-count">0 lines</span>
                    </div>

                    <div class="pmta-smtp-format-chips" aria-hidden="true">
                        <span class="pmta-smtp-chip">username</span>
                        <span class="pmta-smtp-chip">password</span>
                        <span class="pmta-smtp-chip">route</span>
                        <span class="pmta-smtp-chip">port</span>
                        <span class="pmta-smtp-chip">domain*</span>
                    </div>

                    <textarea id="pmta-smtp-lines" rows="6" class="form-control command-form-control" placeholder="username,password,route,port,domain*" style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px;"></textarea>
                    <small class="d-block mt-2" style="color: var(--co-muted, #5f6b89);">* Optional 5th column overrides the IP&rsquo;s default domain hostname in PMTA VMTA config.</small>

                    <div class="d-flex flex-wrap justify-content-between align-items-center pt-3 mt-3 border-top" style="gap: 10px;">
                        <button type="button" id="pmta-smtp-push" class="btn-command-primary">
                            <i class="fas fa-upload mr-1"></i> PUSH SMTP
                        </button>
                        <button type="button" id="pmta-smtp-reset" class="btn-command-danger">
                            <i class="fas fa-trash-alt mr-1"></i> RESET SMTP
                        </button>
                    </div>
                </div>
            </div>

            <div class="table-responsive mt-3">
                <table id="pmta-smtp-result" class="table table-sm table-hover table-striped command-os-table"></table>
            </div>
            <div id="pmta-smtp-status" class="sr-only" role="status" aria-live="polite"></div>
        </section>

        <!-- 2b. Existing Accounts Provisioned via IMAP Option (Main IP Only, Unselected by Default) -->
        <section class="command-os-table-card p-4 mb-4 d-none" id="pmta-smtp-imap-card" aria-labelledby="pmta-smtp-imap-heading">
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 pb-3 border-bottom" style="gap: 12px;">
                <div>
                    <h2 id="pmta-smtp-imap-heading" style="font-size: 0.95rem; font-weight: 800; color: var(--co-ink, #17203e); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                        <i class="fas fa-inbox mr-2 text-purple" aria-hidden="true"></i>IMAP-Installed Server Accounts (Main IP Only)
                    </h2>
                    <small style="color: var(--co-muted, #5f6b89);">
                        Starts unselected by default. Check one account or select all to push directly to their installed server&rsquo;s PMTA SMTP configuration.
                    </small>
                </div>
                <div class="d-flex flex-wrap align-items-center" style="gap: 8px;">
                    <span class="pmta-smtp-count-pill" id="pmta-smtp-imap-selected-pill">0 Selected</span>
                    <button type="button" class="btn-command-action" id="pmta-smtp-imap-select-all" style="padding: 6px 11px; font-size: 0.75rem;">
                        <i class="far fa-check-square mr-1"></i> Select All
                    </button>
                    <button type="button" class="btn-command-action" id="pmta-smtp-imap-clear" style="padding: 6px 11px; font-size: 0.75rem;">
                        <i class="far fa-square mr-1"></i> Clear
                    </button>
                    <button type="button" class="btn-command-action" id="pmta-smtp-load-imap" style="padding: 6px 11px; font-size: 0.75rem;">
                        <i class="fas fa-sync-alt mr-1"></i> Refresh
                    </button>
                </div>
            </div>

            <div class="pmta-smtp-imap-controls">
                <div>
                    <label class="command-form-label" for="pmta-smtp-imap-route">Relay Host / Route (Optional)</label>
                    <input id="pmta-smtp-imap-route" type="text" class="form-control command-form-control" placeholder="Blank = server Main IP">
                </div>
                <div>
                    <label class="command-form-label" for="pmta-smtp-imap-port">PMTA / SMTP Port</label>
                    <input id="pmta-smtp-imap-port" type="number" class="form-control command-form-control" value="{{ $pmtaPort }}" min="1" max="65535">
                </div>
                <div>
                    <label class="command-form-label" for="pmta-smtp-imap-password">Password Override (Optional)</label>
                    <input id="pmta-smtp-imap-password" type="password" class="form-control command-form-control" placeholder="{{ $hasSavedWebmailPassword ? 'Blank = use saved Settings password' : 'Enter SMTP/IMAP password' }}">
                </div>
            </div>

            <div class="table-responsive mb-3">
                <table class="table table-hover command-os-table mb-0" id="pmta-smtp-imap-table">
                    <thead>
                        <tr>
                            <th style="width: 42px;">
                                <input type="checkbox" id="pmta-smtp-imap-master-check" aria-label="Select all IMAP accounts">
                            </th>
                            <th>Server</th>
                            <th>Main IP</th>
                            <th>Domain</th>
                            <th>IMAP Account</th>
                            <th>Source</th>
                            <th>PMTA SMTP Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="pmta-smtp-imap-tbody">
                        <tr><td colspan="8" class="text-center text-muted py-4">Enable IMAP-Installed Accounts to scan servers for Main IP accounts.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center pt-3 border-top" style="gap: 12px;">
                <span id="pmta-smtp-imap-summary" style="font-size: 0.8125rem; font-weight: 600; color: var(--co-muted, #5f6b89);">0 of 0 account(s) selected</span>
                <button type="button" class="btn-command-primary" id="pmta-smtp-push-imap" disabled>
                    <i class="fas fa-upload mr-1"></i> Push Selected to Server (0)
                </button>
            </div>
        </section>

        <!-- 3. MY SMTP Table Card -->
        <section class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between p-3 border-bottom">
                <h2 style="font-size: 0.95rem; font-weight: 800; color: var(--co-ink, #17203e); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                    <i class="fas fa-table mr-2 text-purple" aria-hidden="true"></i>MY SMTP Identities
                </h2>
            </div>
            <div class="table-responsive">
                <table id="pmta-smtp-table" class="table table-sm table-hover table-striped command-os-table" aria-label="SMTP records"></table>
            </div>
        </section>
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

    const ipsSelect = document.getElementById('pmta-smtp-ips');
    const linesInput = document.getElementById('pmta-smtp-lines');
    const ipSelectedBadge = document.getElementById('pmta-smtp-ip-selected-badge');
    const linesCountBadge = document.getElementById('pmta-smtp-lines-count');
    const heroSelectedBadge = document.getElementById('pmta-smtp-hero-selected');
    const sectionSelectedBadge = document.getElementById('pmta-smtp-section-selected-count');
    const imapTileCount = document.getElementById('pmta-smtp-imap-tile-count');

    const selectedIpIds = () => [...ipsSelect.selectedOptions].map((option) => option.value);

    const updateTopCounters = () => {
        const ipCount = selectedIpIds().length;
        const lineCount = linesInput.value.split(/\r?\n/).map((l) => l.trim()).filter(Boolean).length;
        const imapCount = document.querySelectorAll('.pmta-imap-check:checked').length;
        const totalSelected = imapCount > 0 ? imapCount : ipCount;

        ipSelectedBadge.innerHTML = `<i class="fas fa-network-wired" aria-hidden="true"></i> ${ipCount} ${ipCount === 1 ? 'IP' : 'IPs'} Selected`;
        linesCountBadge.textContent = `${lineCount} ${lineCount === 1 ? 'line' : 'lines'}`;
        sectionSelectedBadge.innerHTML = `<i class="fas fa-check-circle" aria-hidden="true"></i> ${imapCount} ${imapCount === 1 ? 'Account' : 'Accounts'} Selected`;
        imapTileCount.textContent = `${imapCount} selected`;
        heroSelectedBadge.textContent = `${totalSelected} SELECTED`;
    };

    ipsSelect?.addEventListener('change', updateTopCounters);
    linesInput?.addEventListener('input', updateTopCounters);

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

    document.getElementById('pmta-smtp-push').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const lines = linesInput.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
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
        linesInput.placeholder = enabled ? 'username,token' : 'username,password,route,port,domain*';
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
        Array.from(ipsSelect.options).forEach((option) => { option.selected = terms.some((term) => option.textContent.toLowerCase().includes(term)); });
        ipsSelect.dispatchEvent(new Event('change', { bubbles: true }));
        if (window.jQuery?.fn.selectpicker) window.jQuery(ipsSelect).selectpicker('refresh');
        dialog.close();
    });
    if (window.jQuery?.fn.selectpicker) window.jQuery('.selectpicker').selectpicker();

    // IMAP-Installed Accounts (Main IP Only, Unselected by Default)
    const showImapToggle = document.getElementById('pmta-smtp-show-imap');
    const imapCard = document.getElementById('pmta-smtp-imap-card');
    const imapBody = document.getElementById('pmta-smtp-imap-tbody');
    const imapSummary = document.getElementById('pmta-smtp-imap-summary');
    const imapSelectedPill = document.getElementById('pmta-smtp-imap-selected-pill');
    const imapMasterCheck = document.getElementById('pmta-smtp-imap-master-check');
    const loadImapBtn = document.getElementById('pmta-smtp-load-imap');
    const pushImapBtn = document.getElementById('pmta-smtp-push-imap');
    const selectAllImapBtn = document.getElementById('pmta-smtp-imap-select-all');
    const clearImapBtn = document.getElementById('pmta-smtp-imap-clear');
    let cachedImapAccounts = [];

    const escImap = (v) => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    const updateImapSelectionUi = () => {
        const boxes = [...document.querySelectorAll('.pmta-imap-check')];
        const checkedBoxes = boxes.filter((b) => b.checked);
        const count = checkedBoxes.length;
        const total = boxes.length;

        boxes.forEach((box) => {
            box.closest('tr')?.classList.toggle('pmta-imap-row-selected', box.checked);
        });

        if (imapMasterCheck) {
            imapMasterCheck.checked = total > 0 && count === total;
            imapMasterCheck.indeterminate = count > 0 && count < total;
        }
        imapSelectedPill.textContent = `${count} Selected`;
        imapSummary.textContent = `${count} of ${total} account(s) selected across Server Main IPs`;
        pushImapBtn.disabled = count === 0;
        pushImapBtn.innerHTML = `<i class="fas fa-upload mr-1"></i> Push Selected to Server (${count})`;
        updateTopCounters();
    };

    const renderImapAccounts = (rows) => {
        if (!rows || rows.length === 0) {
            imapBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No active servers with the IMAP option installed were found in this workspace.</td></tr>';
            updateImapSelectionUi();
            return;
        }
        // Always start unselected when opened/loaded
        imapBody.innerHTML = rows.map((row, idx) => `
            <tr>
                <td><input type="checkbox" class="pmta-imap-check" data-imap-index="${idx}" aria-label="Select ${escImap(row.login_address)}"></td>
                <td><strong>${escImap(row.server_name)}</strong></td>
                <td><span style="font-family: monospace; color: #6366f1;">${escImap(row.ip)}</span><span class="pmta-main-ip-badge">MAIN IP</span></td>
                <td><span style="font-weight: 600; color: #8b5cf6;">${escImap(row.dns || '-')}</span></td>
                <td><code>${escImap(row.login_address)}</code></td>
                <td><span class="badge-command-pill">${escImap(row.source)}</span></td>
                <td>${row.already_pushed ? '<span class="badge badge-success">Pushed to SMTP</span>' : '<span class="badge badge-secondary">Ready</span>'}</td>
                <td>
                    <button type="button" class="btn-command-action pmta-imap-fill" data-imap-index="${idx}" style="padding: 4px 10px; font-size: 0.72rem;">
                        Load in Form
                    </button>
                </td>
            </tr>
        `).join('');
        updateImapSelectionUi();
    };

    const loadImapAccounts = async () => {
        loadImapBtn.disabled = true;
        imapBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-1"></i> Loading Main IP IMAP accounts from installed servers...</td></tr>';
        try {
            const payload = await api(root.dataset.imapAccountsUrl);
            cachedImapAccounts = payload.data || [];
            renderImapAccounts(cachedImapAccounts);
        } catch (error) {
            imapBody.innerHTML = `<tr><td colspan="8" class="text-center text-danger py-3">${escImap(error.message)}</td></tr>`;
            updateImapSelectionUi();
        } finally {
            loadImapBtn.disabled = false;
        }
    };

    showImapToggle?.addEventListener('change', () => {
        const show = showImapToggle.checked;
        imapCard.classList.toggle('d-none', !show);
        if (show) {
            // Reset to unselected whenever opened
            if (cachedImapAccounts.length === 0) {
                loadImapAccounts();
            } else {
                renderImapAccounts(cachedImapAccounts);
            }
        }
    });
    loadImapBtn?.addEventListener('click', loadImapAccounts);

    imapMasterCheck?.addEventListener('change', () => {
        const checked = imapMasterCheck.checked;
        document.querySelectorAll('.pmta-imap-check').forEach((b) => { b.checked = checked; });
        updateImapSelectionUi();
    });

    selectAllImapBtn?.addEventListener('click', () => {
        document.querySelectorAll('.pmta-imap-check').forEach((b) => { b.checked = true; });
        updateImapSelectionUi();
    });

    clearImapBtn?.addEventListener('click', () => {
        document.querySelectorAll('.pmta-imap-check').forEach((b) => { b.checked = false; });
        updateImapSelectionUi();
    });

    imapBody?.addEventListener('change', (event) => {
        if (event.target.classList.contains('pmta-imap-check')) {
            updateImapSelectionUi();
        }
    });

    imapBody?.addEventListener('click', (event) => {
        const fillBtn = event.target.closest('.pmta-imap-fill');
        if (!fillBtn) return;
        const entry = cachedImapAccounts[Number(fillBtn.dataset.imapIndex)];
        if (!entry) return;
        Array.from(ipsSelect.options).forEach((opt) => {
            if (String(opt.value) === String(entry.ip_id)) opt.selected = true;
        });
        ipsSelect.dispatchEvent(new Event('change', { bubbles: true }));
        if (window.jQuery?.fn.selectpicker) window.jQuery(ipsSelect).selectpicker('refresh');

        const route = document.getElementById('pmta-smtp-imap-route').value.trim() || entry.default_route;
        const port = document.getElementById('pmta-smtp-imap-port').value.trim() || entry.default_port;
        const line = `${entry.login_address},<WEBMAIL_PASSWORD>,${route},${port}${entry.dns ? ',' + entry.dns : ''}`;
        linesInput.value = linesInput.value.trim() ? `${linesInput.value.trim()}\n${line}` : line;
        updateTopCounters();
        announce(`Loaded ${entry.login_address} (${entry.ip} Main IP) into SMTP Credentials form.`, 'info');
    });

    pushImapBtn?.addEventListener('click', async () => {
        const checked = [...document.querySelectorAll('.pmta-imap-check:checked')]
            .map((el) => cachedImapAccounts[Number(el.dataset.imapIndex)])
            .filter(Boolean);
        if (checked.length === 0) {
            announce('Select at least one IMAP account to push to its selected server.', 'warning');
            return;
        }
        const items = checked.map((entry) => ({ ip_id: entry.ip_id, username: entry.login_address }));
        const route = document.getElementById('pmta-smtp-imap-route').value.trim();
        const port = Number(document.getElementById('pmta-smtp-imap-port').value) || undefined;
        const password = document.getElementById('pmta-smtp-imap-password').value;

        pushImapBtn.disabled = true;
        try {
            const result = await api(root.dataset.pushImapUrl, 'POST', { items, route, port, password });
            showResults(result.results);
            announce((result.results || []).map((row) => `${row.server}: ${row.message}`).join(' · '), result.success ? 'success' : 'danger');
            if (grid) grid.ajax.reload(null, false);
            await loadImapAccounts();
        } catch (error) {
            showResults([]);
            announce(error.message, 'danger');
        } finally {
            updateImapSelectionUi();
        }
    });

    updateTopCounters();
})();
</script>
@endpush
