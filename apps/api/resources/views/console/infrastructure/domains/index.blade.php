@extends('layouts.admin-shell')

@section('title', 'Domains')

@push('styles')
    <style>
        .d_status {
            width: 100%;
            min-width: 90px;
            padding: 3px 8px;
            border: 1px solid var(--co-border, #e2e8f0);
            border-radius: 6px;
            outline: none;
            background-color: var(--co-surface, #ffffff);
            color: var(--co-ink, #0f172a);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .d_status:focus {
            border-color: var(--co-purple, #8b5cf6);
            box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.2);
        }
        html[data-command-theme='dark'] .d_status {
            background-color: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.12);
            color: #e2e8f0;
        }
        html[data-command-theme='dark'] .d_status option {
            background-color: #0f172a;
            color: #f8fafc;
        }
        .expireSoon { color: #ef4444 !important; font-weight: 700; }
        .domains-management { display: grid; gap: 14px; }
        .domains-management .command-os-report-hero { margin-bottom: 0; }
        .domains-management .command-os-report-hero-left { align-items: center; display: flex; gap: 14px; min-width: 0; }
        .domains-management .command-os-report-hero { min-height: 104px; padding: 16px 20px; }
        .domain-metrics { display: grid; gap: 10px; grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .domain-metric { align-items: center; background: var(--co-surface, #fff); border: 1px solid var(--co-border, #dfe3f1); border-radius: 12px; box-shadow: 0 4px 16px rgb(45 54 115 / 4%); display: flex; gap: 10px; min-width: 0; padding: 12px; }
        .domain-metric-icon { align-items: center; background: color-mix(in srgb, var(--metric-color, #793df6) 11%, var(--co-surface, #fff)); border-radius: 10px; color: var(--metric-color, #793df6); display: inline-flex; flex: 0 0 38px; height: 38px; justify-content: center; }
        .domain-metric-copy { color: var(--co-muted, #5f6b89); display: block; font-size: 11px; line-height: 1.3; min-width: 0; }
        .domain-metric-copy strong { color: var(--co-ink, #17203e); display: block; font-size: 20px; font-variant-numeric: tabular-nums; line-height: 1.15; margin-top: 3px; }
        .domain-filter-card { background: var(--co-surface, #fff); border: 1px solid var(--co-border, #dfe3f1); border-radius: 12px; padding: 12px; }
        .domain-status-filters { align-items: center; display: flex; flex-wrap: wrap; gap: 7px; }
        .domain-filter-chip { align-items: center; background: var(--co-surface, #fff); border: 1px solid var(--co-border, #dfe3f1); border-radius: 9px; color: var(--co-ink, #17203e); cursor: pointer; display: inline-flex; font-size: 12px; font-weight: 700; gap: 7px; min-height: 36px; padding: 0 12px; }
        .domain-filter-chip:hover, .domain-filter-chip.is-active { background: color-mix(in srgb, var(--co-purple, #793df6) 10%, var(--co-surface, #fff)); border-color: color-mix(in srgb, var(--co-purple, #793df6) 32%, var(--co-border, #dfe3f1)); color: var(--co-purple, #793df6); }
        .domain-filter-chip-count { background: var(--co-surface-muted, #f7f8fd); border-radius: 999px; font-size: 10px; min-width: 20px; padding: 2px 6px; text-align: center; }
        .domain-filter-grid { align-items: end; display: grid; gap: 10px; grid-template-columns: repeat(4, minmax(140px, 1fr)) auto auto; margin-top: 12px; }
        .domain-filter-field { min-width: 0; }
        .domain-filter-field label { color: var(--co-muted, #5f6b89); display: block; font-size: 10px; font-weight: 800; letter-spacing: .04em; margin-bottom: 4px; text-transform: uppercase; }
        .domain-filter-field select, .domain-bulk-status select { background: var(--co-surface, #fff); border: 1px solid var(--co-border-strong, #d3d8eb); border-radius: 8px; color: var(--co-ink, #17203e); height: 36px; padding: 0 10px; width: 100%; }
        .domain-filter-action { height: 36px; white-space: nowrap; }
        .domain-bulk-row { align-items: center; border-top: 1px solid var(--co-border, #dfe3f1); display: flex; flex-wrap: wrap; gap: 9px; justify-content: space-between; margin-top: 12px; padding-top: 12px; }
        .domain-selection-count { color: var(--co-muted, #5f6b89); font-size: 12px; font-weight: 700; }
        .domain-bulk-status { align-items: center; display: flex; gap: 8px; }
        .domain-bulk-status select { min-width: 155px; }
        .domain-table-title { align-items: center; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; margin: 2px 2px -6px; }
        .domain-table-title h2 { color: var(--co-ink, #17203e); font-size: 14px; font-weight: 800; margin: 0; }
        .domain-table-title p { color: var(--co-muted, #5f6b89); font-size: 11px; margin: 3px 0 0; }
        .domains-management .command-os-table-card { min-width: 0; }
        .domains-management .table-responsive { border-radius: 12px; }
        .domains-management #domains th { white-space: nowrap; }
        .domain-live-badge { background: #e5f9ef; border-radius: 999px; color: #087545; display: inline-flex; font-size: 10px; font-weight: 800; padding: 3px 8px; }
        .domain-live-badge.is-off { background: #f1f3f7; color: #667085; }
        html[data-command-theme='dark'] .domain-live-badge { background: rgb(16 185 129 / 16%); color: #8df0c5; }
        html[data-command-theme='dark'] .domain-live-badge.is-off { background: rgb(148 163 184 / 14%); color: #a2afd3; }
        @media (max-width: 1250px) { .domain-metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); } .domain-filter-grid { grid-template-columns: repeat(3, minmax(140px, 1fr)); } }
        @media (max-width: 720px) { .domain-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } .domain-filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .domain-filter-action { width: 100%; } .domain-bulk-status { flex: 1 1 100%; } .domain-bulk-status select { flex: 1 1 auto; } }
        @media (max-width: 420px) { .domain-metrics { grid-template-columns: 1fr; } .domain-filter-grid { grid-template-columns: 1fr; } }
    </style>
@endpush

@section('content')
    <div class="command-os-entity-page domains-management">
        <!-- 1. Hero Card -->
        <header class="command-os-report-hero">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-mark" aria-hidden="true">
                    <i class="fas fa-globe"></i>
                </div>
                <div class="command-os-report-hero-copy">
                    <span class="command-os-report-hero-eyebrow">INFRASTRUCTURE · DOMAIN INVENTORY</span>
                    <h1 class="command-os-report-hero-title">DOMAINS</h1>
                    <p class="command-os-report-hero-subtitle">Manage sending domains, lifecycle status, DNS health, expiry, and redirects in one workspace view.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <label class="command-os-toggle-wrap mr-3" for="only_our" style="display: inline-flex; align-items: center; gap: 8px;">
                    <span class="command-os-toggle-label font-w700 font-size-xs text-uppercase" style="letter-spacing: 0.05em;">ONLY OUR</span>
                    <input type="checkbox" class="command-os-toggle-input" id="only_our">
                    <span class="command-os-toggle-slider"></span>
                </label>
                <div class="command-os-report-hero-badge">
                    <span class="status-dot"></span>
                    <span>WORKSPACE INVENTORY · LIVE STATUS</span>
                </div>
                <button type="button" class="command-os-btn-option ml-2" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen" title="Full screen">
                    <i class="fas fa-expand-alt" aria-hidden="true"></i>
                </button>
            </div>
        </header>

        <section class="domain-metrics" aria-label="Domain totals">
            <article class="domain-metric"><span class="domain-metric-icon" style="--metric-color:#793df6"><i class="fas fa-globe"></i></span><span class="domain-metric-copy">Total domains<strong id="stat-total-domains">—</strong></span></article>
            <article class="domain-metric"><span class="domain-metric-icon" style="--metric-color:#10a56c"><i class="fas fa-circle"></i></span><span class="domain-metric-copy">Free domains<strong id="stat-free-domains">—</strong></span></article>
            <article class="domain-metric"><span class="domain-metric-icon" style="--metric-color:#d88916"><i class="fas fa-plug"></i></span><span class="domain-metric-copy">Used domains<strong id="stat-used-domains">—</strong></span></article>
            <article class="domain-metric"><span class="domain-metric-icon" style="--metric-color:#16a56c"><i class="fas fa-check-circle"></i></span><span class="domain-metric-copy">Live domains<strong id="stat-live-domains">—</strong></span></article>
            <article class="domain-metric"><span class="domain-metric-icon" style="--metric-color:#793df6"><i class="fas fa-link"></i></span><span class="domain-metric-copy">Redirect domains<strong id="stat-redirect-domains">—</strong></span></article>
            <article class="domain-metric"><span class="domain-metric-icon" style="--metric-color:#277bff"><i class="fas fa-star"></i></span><span class="domain-metric-copy">New domains<strong id="stat-new-domains">—</strong></span></article>
        </section>

        <section class="domain-filter-card" aria-label="Domain filters and bulk status">
            <div class="domain-status-filters" role="group" aria-label="Filter by domain status">
                <button type="button" class="domain-filter-chip is-active" data-domain-filter="all" aria-pressed="true">All <span class="domain-filter-chip-count" id="count-all">0</span></button>
                <button type="button" class="domain-filter-chip" data-domain-filter="free" aria-pressed="false"><i class="fas fa-circle text-success"></i>Free <span class="domain-filter-chip-count" id="count-free">0</span></button>
                <button type="button" class="domain-filter-chip" data-domain-filter="used" aria-pressed="false"><i class="fas fa-plug text-warning"></i>Used <span class="domain-filter-chip-count" id="count-used">0</span></button>
                <button type="button" class="domain-filter-chip" data-domain-filter="live" aria-pressed="false"><i class="fas fa-check-circle text-success"></i>Live <span class="domain-filter-chip-count" id="count-live">0</span></button>
                <button type="button" class="domain-filter-chip" data-domain-filter="new" aria-pressed="false"><span class="badge badge-primary">NEW</span>New <span class="domain-filter-chip-count" id="count-new">0</span></button>
                <button type="button" class="domain-filter-chip" data-domain-filter="redirect" aria-pressed="false"><i class="fas fa-link text-purple"></i>Redirect <span class="domain-filter-chip-count" id="count-redirect">0</span></button>
            </div>
            <div class="domain-filter-grid">
                <div class="domain-filter-field"><label for="filter-account">Account</label><select id="filter-account"><option value="">All accounts</option></select></div>
                <div class="domain-filter-field"><label for="filter-dns-health">DNS checks</label><select id="filter-dns-health"><option value="">All results</option><option value="complete">Complete</option><option value="attention">Needs attention</option><option value="unchecked">Not checked</option></select></div>
                <div class="domain-filter-field"><label for="filter-expiry">Expiry</label><select id="filter-expiry"><option value="">Any date</option><option value="expired">Expired</option><option value="30">Next 30 days</option><option value="90">Next 90 days</option><option value="none">No expiry date</option></select></div>
                <div class="domain-filter-field"><label for="filter-listed">Blacklist</label><select id="filter-listed"><option value="">All</option><option value="clear">Clear</option><option value="listed">Listed</option><option value="unchecked">Not checked</option></select></div>
                <button type="button" class="btn-command-primary domain-filter-action" id="apply-domain-filters"><i class="fas fa-filter mr-1"></i> Apply filters</button>
                <button type="button" class="btn-command-action domain-filter-action" id="reset-domain-filters">Reset</button>
            </div>
            <div class="domain-bulk-row">
                <span class="domain-selection-count" id="domain-selection-count" aria-live="polite">0 selected</span>
                <div class="domain-bulk-status">
                    <label class="sr-only" for="bulk-domain-status">Set selected domains to</label>
                    <select id="bulk-domain-status">
                        <option value="">Change status…</option><option value="free">Set to Free</option><option value="used">Set to Used</option><option value="new">Set to New</option><option value="redirect">Set to Redirect</option>
                    </select>
                    <button type="button" class="btn-command-primary" id="apply-bulk-domain-status" disabled>Apply to selected</button>
                </div>
            </div>
        </section>

        <div class="domain-table-title"><div><h2>Domain register</h2><p>Status and expiry are workspace-scoped. Use the row checkboxes to select domains for bulk status changes.</p></div></div>
        <div class="command-os-table-card">
            <div class="table-responsive">
                <table id="domains" class="table table-sm table-hover table-striped"></table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            const endpoint = @json(route('console.infrastructure.domains.index', $workspace->id));
            const escapeHtml = (value) => $('<div>').text(value ?? '').html();
            const formatBoolBadge = (value, title = '', listed = false) => {
                if (value == null) return '<span class="text-muted">NA</span>';
                const text = value ? 'YES' : 'NO';
                const displayValue = listed ? !value : value;
                const badgeClass = displayValue ? 'badge-command-active-yes' : 'badge-command-active-no';
                return `<span class="badge ${badgeClass} mr-1" title="${escapeHtml(title)}">${text}</span>`;
            };
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            const params = new URLSearchParams(window.location.search);
            let activeDomainFilter = ['free', 'used', 'live', 'new', 'redirect'].includes(params.get('status')) ? params.get('status') : 'all';
            let tableSearch = params.get('q') || '';
            const filterState = {
                account: params.get('account') || '',
                dns: params.get('dns') || '',
                expiry: params.get('expiry') || '',
                listed: params.get('listed') || '',
            };
            $('#filter-account').val(filterState.account);
            $('#filter-dns-health').val(filterState.dns);
            $('#filter-expiry').val(filterState.expiry);
            $('#filter-listed').val(filterState.listed);
            $('#only_our').prop('checked', params.get('only_our') === '1');
            const dnsHealth = (history) => {
                if (!history) return 'unchecked';
                const checks = ['a_record', 'spf', 'dkim', 'dmarc'];
                return checks.every((key) => String(history[key] ?? '').trim() !== '') ? 'complete' : 'attention';
            };
            const blacklistHealth = (history) => {
                if (!history || (history.is_listed_spamhaus == null && history.is_listed_hostkarma == null)) return 'unchecked';
                return history.is_listed_spamhaus || history.is_listed_hostkarma ? 'listed' : 'clear';
            };
            const matchesExpiry = (row, filter) => {
                if (!filter) return true;
                if (!row.expire_at) return filter === 'none';
                const days = moment(row.expire_at).startOf('day').diff(moment().startOf('day'), 'days');
                if (filter === 'expired') return days < 0;
                if (filter === '30') return days >= 0 && days <= 30;
                if (filter === '90') return days >= 0 && days <= 90;
                return true;
            };
            $.fn.dataTable.ext.search.push(function (settings, searchData, dataIndex, rowData) {
                if (settings.nTable.id !== 'domains') return true;
                const row = rowData || settings.aoData[dataIndex]?._aData;
                if (!row) return true;
                if (activeDomainFilter === 'live' && !row.is_active) return false;
                if (activeDomainFilter !== 'all' && activeDomainFilter !== 'live' && row.status !== activeDomainFilter) return false;
                if (filterState.account && String(row.provider?.id || '') !== filterState.account) return false;
                if (filterState.dns && dnsHealth(row.history) !== filterState.dns) return false;
                if (filterState.listed && blacklistHealth(row.history) !== filterState.listed) return false;
                return matchesExpiry(row, filterState.expiry);
            });
            const setUrlState = () => {
                const next = new URL(window.location.href);
                const values = {
                    status: activeDomainFilter === 'all' ? '' : activeDomainFilter,
                    account: filterState.account,
                    dns: filterState.dns,
                    expiry: filterState.expiry,
                    listed: filterState.listed,
                    q: tableSearch,
                    only_our: $('#only_our').is(':checked') ? '1' : '',
                };
                Object.entries(values).forEach(([key, value]) => value ? next.searchParams.set(key, value) : next.searchParams.delete(key));
                window.history.replaceState({}, '', next);
            };
            const refreshSummary = (table) => {
                const rows = table.rows({search: 'none'}).data().toArray();
                const counts = {all: rows.length, free: 0, used: 0, live: 0, new: 0, redirect: 0};
                const accounts = new Map();
                rows.forEach((row) => {
                    if (Object.prototype.hasOwnProperty.call(counts, row.status)) counts[row.status] += 1;
                    if (row.is_active) counts.live += 1;
                    if (row.provider?.id) accounts.set(String(row.provider.id), row.provider.name);
                });
                Object.entries(counts).forEach(([key, count]) => {
                    const stat = document.getElementById('stat-' + (key === 'all' ? 'total' : key) + '-domains');
                    if (stat) stat.textContent = new Intl.NumberFormat().format(count);
                    const chip = document.getElementById('count-' + key);
                    if (chip) chip.textContent = new Intl.NumberFormat().format(count);
                });
                const accountSelect = document.getElementById('filter-account');
                const selectedAccount = filterState.account;
                accountSelect.innerHTML = '<option value="">All accounts</option>';
                Array.from(accounts.entries()).sort((a, b) => a[1].localeCompare(b[1])).forEach(([id, name]) => {
                    const option = document.createElement('option');
                    option.value = id;
                    option.textContent = name;
                    accountSelect.append(option);
                });
                accountSelect.value = selectedAccount;
            };
            const updateSelectionControls = (table) => {
                const count = table.rows({selected: true}).count();
                document.getElementById('domain-selection-count').textContent = count + ' selected';
                document.getElementById('apply-bulk-domain-status').disabled = count === 0 || !document.getElementById('bulk-domain-status').value;
            };
            const datatable = $('#domains').DataTable({
                ajax: {
                    url: endpoint,
                    data: function (data) {
                        data.only_our = $('#only_our').is(':checked');
                    }
                },
                order: [[8, 'asc']],
                displayLength: 50,
                search: {search: tableSearch},
                select: {style: 'multi+shift', selector: 'td:first-child'},
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>' +
                     '<"table-responsive"t>' +
                     '<"command-table-footer"ip>',
                language: {
                    lengthMenu: 'Show _MENU_ rows',
                    search: '',
                    searchPlaceholder: 'Search domains...',
                    info: 'Page _PAGE_ of _PAGES_ (_TOTAL_ records)',
                    infoEmpty: 'Page 0 of 0 (0 records)',
                    paginate: {
                        previous: '<i class="fas fa-chevron-left"></i>',
                        next: '<i class="fas fa-chevron-right"></i>'
                    }
                },
                buttons: {
                    dom: {
                        container: { tag: 'div', className: 'dt-buttons' },
                        button: { tag: 'button', className: 'btn' }
                    },
                    buttons: [
                        {
                            text: '<i class="fas fa-sync mr-1"></i> REFRESH',
                            className: 'btn-command-action',
                            titleAttr: 'REFRESH DOMAINS',
                            action: function (event, table) { table.ajax.reload(null, false); }
                        },
                        {
                            text: '<i class="fas fa-plus mr-1"></i> NEW DOMAIN',
                            className: 'btn-command-primary',
                            titleAttr: 'ADD NEW DOMAINS',
                            action: function () {
                                const link = @json(route('console.infrastructure.domains.create', $workspace->id));
                                const iframe = `<iframe src="${link}" width="100%" scrolling="no" frameborder="0"></iframe>`;
                                window.domainDialog = bootbox.dialog({
                                    className: 'command-modal-dialog',
                                    scrollable: true,
                                    closeButton: true,
                                    size: 'lg',
                                    message: iframe,
                                    onEscape: true,
                                    centerVertical: true
                                }).on('shown.bs.modal', function () {
                                    if (typeof iFrameResize === 'function') { iFrameResize({}, 'iframe'); }
                                }).on('hidden.bs.modal', function () {
                                    $('iframe').remove();
                                });
                            }
                        },
                        {
                            text: '<i class="fas fa-trash mr-1"></i> DELETE SELECTED',
                            className: 'btn-command-action text-danger',
                            titleAttr: 'DELETE SELECTED DOMAINS',
                            action: function (event, table, node) {
                                const rows = table.rows({selected: true}).data().toArray();
                                if (!rows.length) { return; }
                                swal.fire({
                                    icon: 'question',
                                    title: 'Are you sure ?',
                                    allowOutsideClick: false,
                                    showCloseButton: true,
                                    confirmButtonText: 'DELETE',
                                    showCancelButton: true,
                                }).then(async (result) => {
                                    if (!result.value) { return; }
                                    table.buttons(node).disable();
                                    try {
                                        const response = await fetch(@json(route('console.infrastructure.domains.destroy', $workspace->id)), {
                                            method: 'DELETE',
                                            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                                            body: JSON.stringify({ids: rows.map((row) => row.id)})
                                        });
                                        const payload = await response.json();
                                        if (!response.ok) { throw new Error(payload.message || payload.data || 'Failed'); }
                                        await App.swalPromise({icon: 'success', title: payload.data, confirmButtonText: 'close'});
                                        table.ajax.reload(null, false);
                                    } catch (error) {
                                        await swal.fire({icon: 'error', title: error.message || 'Failed'});
                                    } finally {
                                        table.buttons(node).enable();
                                    }
                                });
                            }
                        }
                    ]
                },
                initComplete: function () {
                    refreshSummary(this.api());
                },
                rowCallback: function (row, data) {
                    if (!data.is_active) { $('td', row).eq(1).addClass('text-danger'); }
                    if (data.expire_at && moment(data.expire_at).diff(moment(), 'days') < 15) {
                        $('td', row).eq(9).addClass('expireSoon');
                    }
                },
                columns: [
                    {data: null, defaultContent: '', orderable: false, searchable: false, className: 'select-checkbox'},
                    {title: '#', data: 'id', render: (data) => `<span class="badge badge-light border font-mono font-size-xs text-muted" title="${escapeHtml(data)}">#${escapeHtml(data)}</span>`},
                    {title: 'DID', data: 'domain_id', defaultContent: '', render: (data) => data ? `<span class="badge badge-light border font-mono">${escapeHtml(data)}</span>` : '<span class="text-muted">—</span>'},
                    {title: 'ACCOUNT', data: 'provider', render: (data) => data ? `<span class="badge badge-light border text-purple font-w700">${escapeHtml(data.name)}</span> <small class="text-muted">(${escapeHtml(data.type)})</small>` : '<span class="text-muted">—</span>'},
                    {
                        title: 'DNS', data: 'dns', render: (data) => {
                            const dns = String(data ?? '');
                            if (!/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i.test(dns)) { return escapeHtml(dns); }
                            return `<a class="command-table-link font-w600" href="//${escapeHtml(dns)}" target="_blank" rel="noopener noreferrer">${escapeHtml(dns)}</a>`;
                        }
                    },
                    {
                        title: 'STATUS', data: 'status', render: function (data, type, row) {
                            if (type !== 'display') { return data; }
                            const select = $('<select class="business-status d_status" aria-label="Domain status"></select>').attr('data-id', row.id);
                            $.each(['new', 'free', 'used', 'redirect'], function (index, value) {
                                const option = $('<option></option>').text(value.toUpperCase()).val(value);
                                if (data === value) { option.attr('selected', 'selected'); }
                                select.append(option);
                            });
                            return select.prop('outerHTML');
                        }
                    },
                    {title: 'LIVE', data: 'is_active', render: (data) => '<span class="domain-live-badge ' + (data ? '' : 'is-off') + '">' + (data ? 'LIVE' : 'OFF') + '</span>'},
                    {
                        title: 'DNS CHECKS', data: 'history', render: function (history) {
                            const state = dnsHealth(history);
                            const label = state === 'complete' ? 'Complete' : (state === 'attention' ? 'Review' : 'Not checked');
                            return '<span class="domain-live-badge ' + (state === 'complete' ? '' : 'is-off') + '">' + label + '</span>';
                        }
                    },
                    {title: 'EXPIRE AT', data: 'expire_at', defaultContent: '<span class="text-muted">—</span>'},
                    {
                        title: 'DAYS LEFT', data: 'expire_at', render: (data) => {
                            if (!data) return '<span class="text-muted">—</span>';
                            const diff = moment(data).diff(moment(), 'days');
                            return `<span class="font-w700 ${diff < 15 ? 'text-danger' : 'text-success'}">${diff}d</span>`;
                        }
                    },
                    {
                        title: 'IS LISTED', data: 'history', render: function (history) {
                            if (!history) { return '<span class="text-muted">NA</span>'; }
                            return formatBoolBadge(history.is_listed_spamhaus, 'SPAMHAUS', true)
                                + formatBoolBadge(history.is_listed_hostkarma, 'HOSTKARMA', true);
                        }
                    },
                    {
                        title: 'HISTORY', data: 'histories_count', render: function (count, type, row) {
                            const historyUrl = @json(route('console.infrastructure.domains.history', [$workspace->id, '__DOMAIN__']));
                            return `<button data-id="${escapeHtml(row.id)}" data-url="${historyUrl.replace('__DOMAIN__', encodeURIComponent(row.id))}" class="badge badge-light border text-purple font-w700 px-2 py-1 history" title="INSPECT HISTORY" style="cursor: pointer; background: transparent;"><i class="fas fa-history mr-1"></i>${Number(count) || 0}</button>`;
                        }
                    },
                    {
                        title: 'ACTIONS', data: null, orderable: false, searchable: false,
                        render: function (data, type, row) {
                            const id = escapeHtml(row.id);
                            const recordsUrl = @json(route('console.infrastructure.domains.records', [$workspace->id, '__DOMAIN__']));
                            const records = `<button data-url="${recordsUrl.replace('__DOMAIN__', encodeURIComponent(id))}" class="command-action-btn action-primary records" title="DNS RECORDS" aria-label="Edit DNS records for ${escapeHtml(row.dns)}"><i class="fas fa-cogs" aria-hidden="true"></i></button>`;
                            const refresh = `<button data-id="${id}" class="command-action-btn action-info update" title="UPDATE TELEMETRY"><i class="fas fa-pencil-alt"></i></button>`;
                            const active = Boolean(row.is_active);
                            const status = `<button data-id="${id}" data-action="${active ? 0 : 1}" class="command-action-btn action-status ${active ? 'is-active' : ''} active-status" title="${active ? 'DISABLE' : 'ENABLE'} DOMAIN"><i class="fas ${active ? 'fa-times' : 'fa-check'}"></i></button>`;
                            const remove = `<button data-id="${id}" class="command-action-btn action-danger destroy" title="DELETE DOMAIN"><i class="fas fa-trash"></i></button>`;
                            return `<div class="command-action-btn-group">${records}${refresh}${status}${remove}</div>`;
                        }
                    }
                ]
            });

            const searchInput = document.querySelector('#domains_filter input');
            if (searchInput) {
                searchInput.setAttribute('aria-label', 'Search domains');
                searchInput.value = tableSearch;
                const clearSearch = document.createElement('button');
                clearSearch.type = 'button';
                clearSearch.className = 'btn-command-action ml-1';
                clearSearch.setAttribute('aria-label', 'Clear domain search');
                clearSearch.title = 'Clear search';
                clearSearch.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';
                clearSearch.hidden = tableSearch === '';
                searchInput.after(clearSearch);
                searchInput.addEventListener('input', () => {
                    tableSearch = searchInput.value;
                    clearSearch.hidden = tableSearch === '';
                });
                clearSearch.addEventListener('click', () => {
                    tableSearch = '';
                    searchInput.value = '';
                    clearSearch.hidden = true;
                    datatable.search('').draw();
                    searchInput.focus();
                });
            }
            datatable.on('search.dt', function () {
                tableSearch = datatable.search();
                setUrlState();
            });

            const syncFilterChips = () => {
                document.querySelectorAll('[data-domain-filter]').forEach((button) => {
                    const active = button.dataset.domainFilter === activeDomainFilter;
                    button.classList.toggle('is-active', active);
                    button.setAttribute('aria-pressed', String(active));
                });
            };
            syncFilterChips();
            refreshSummary(datatable);
            updateSelectionControls(datatable);
            datatable.on('draw select deselect', function () {
                refreshSummary(datatable);
                updateSelectionControls(datatable);
            });
            datatable.on('xhr', function () {
                window.setTimeout(() => refreshSummary(datatable), 0);
            });
            document.querySelectorAll('[data-domain-filter]').forEach((button) => button.addEventListener('click', () => {
                activeDomainFilter = button.dataset.domainFilter;
                syncFilterChips();
                setUrlState();
                datatable.draw();
            }));
            $('#apply-domain-filters').on('click', function () {
                filterState.account = $('#filter-account').val() || '';
                filterState.dns = $('#filter-dns-health').val() || '';
                filterState.expiry = $('#filter-expiry').val() || '';
                filterState.listed = $('#filter-listed').val() || '';
                setUrlState();
                datatable.draw();
            });
            $('#reset-domain-filters').on('click', function () {
                activeDomainFilter = 'all';
                filterState.account = filterState.dns = filterState.expiry = filterState.listed = '';
                $('#filter-account, #filter-dns-health, #filter-expiry, #filter-listed').val('');
                $('#only_our').prop('checked', false);
                datatable.search('');
                tableSearch = '';
                syncFilterChips();
                setUrlState();
                datatable.ajax.reload();
            });
            $('#bulk-domain-status').on('change', () => updateSelectionControls(datatable));
            $('#apply-bulk-domain-status').on('click', async function () {
                const rows = datatable.rows({selected: true}).data().toArray();
                const status = $('#bulk-domain-status').val();
                if (!rows.length || !status) return;
                const result = await swal.fire({
                    icon: 'question',
                    title: 'Set ' + rows.length + ' selected domain' + (rows.length === 1 ? '' : 's') + ' to ' + status.toUpperCase() + '?',
                    text: 'This updates only the selected domains in this workspace.',
                    showCancelButton: true,
                    confirmButtonText: 'Change status',
                    cancelButtonText: 'Cancel',
                    allowOutsideClick: false,
                });
                if (!result.isConfirmed && !result.value) return;
                const button = this;
                button.disabled = true;
                try {
                    const response = await fetch(@json(route('console.infrastructure.domains.bulk-status', $workspace->id)), {
                        method: 'PATCH',
                        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                        body: JSON.stringify({ids: rows.map((row) => row.id), status}),
                    });
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || payload.data || 'Could not change domain status.');
                    datatable.rows({selected: true}).deselect();
                    $('#bulk-domain-status').val('');
                    await swal.fire({icon: 'success', title: payload.data});
                    datatable.ajax.reload(null, false);
                } catch (error) {
                    await swal.fire({icon: 'error', title: error.message || 'Could not change domain status.'});
                } finally {
                    button.disabled = false;
                    updateSelectionControls(datatable);
                }
            });

            // Reload after checkbox changes to refresh dependent values.
            $('#only_our').on('change', function () { setUrlState(); datatable.ajax.reload(); });

            $('#domains').on('click', '.history', function (event) {
                event.preventDefault();
                const iframe = `<iframe src="${$(this).data('url')}" width="100%" scrolling="no" frameborder="0"></iframe>`;
                bootbox.dialog({className: 'command-modal-dialog', scrollable: true, closeButton: true, size: 'xl', message: iframe, onEscape: true, centerVertical: true})
                    .on('shown.bs.modal', function () { if (typeof iFrameResize === 'function') { iFrameResize({}, 'iframe'); } })
                    .on('hidden.bs.modal', function () { $('iframe').remove(); });
            }).on('click', '.records', function () {
                const url = $(this).data('url');
                window.open(url, '_blank', 'noopener');
            }).on('click', '.update', async function () {
                const button = $(this);
                const id = button.data('id');
                const original = button.html();
                button.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
                try {
                    const response = await fetch(`${endpoint}/${encodeURIComponent(id)}`, {
                        method: 'PUT', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken}
                    });
                    const payload = await response.json();
                    if (!response.ok) { throw new Error(payload.message || payload.data || 'Failed'); }
                    await swal.fire({icon: 'success', title: payload.data});
                    datatable.ajax.reload(null, false);
                } catch (error) {
                    await swal.fire({icon: 'error', title: error.message || 'Failed'});
                } finally {
                    button.html(original).prop('disabled', false);
                }
            }).on('click', '.active-status', async function () {
                const button = $(this);
                const id = button.data('id');
                const original = button.html();
                button.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
                try {
                    const response = await fetch(`${endpoint}/${encodeURIComponent(id)}/active`, {
                        method: 'PATCH',
                        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                        body: JSON.stringify({status: Boolean(Number(button.data('action')))})
                    });
                    const payload = await response.json();
                    if (!response.ok) { throw new Error(payload.message || payload.data || 'Failed'); }
                    await swal.fire({icon: 'success', title: payload.data});
                    datatable.ajax.reload(null, false);
                } catch (error) {
                    await swal.fire({icon: 'error', title: error.message || 'Failed'});
                } finally {
                    button.html(original).prop('disabled', false);
                }
            }).on('click', '.destroy', function () {
                const button = $(this);
                const id = button.data('id');
                swal.fire({
                    icon: 'question',
                    title: 'Are you sure you want to delete this domain ?',
                    showCancelButton: true,
                    confirmButtonText: 'DELETE',
                    showCloseButton: true,
                    allowOutsideClick: false
                }).then(async (result) => {
                    if (!result.value) { return; }
                    button.prop('disabled', true);
                    try {
                        const response = await fetch(@json(route('console.infrastructure.domains.destroy', $workspace->id)), {
                            method: 'DELETE',
                            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                            body: JSON.stringify({ids: [id]})
                        });
                        const payload = await response.json();
                        if (!response.ok) { throw new Error(payload.message || payload.data || 'Failed'); }
                        await swal.fire({icon: 'success', title: payload.data});
                        datatable.ajax.reload(null, false);
                    } catch (error) {
                        await swal.fire({icon: 'error', title: error.message || 'Failed'});
                    } finally {
                        button.prop('disabled', false);
                    }
                });
            }).on('change', '.d_status', async function () {
                const select = $(this);
                const id = select.data('id');
                select.prop('disabled', true);
                try {
                    const response = await fetch(`${endpoint}/${encodeURIComponent(id)}/status`, {
                        method: 'PATCH',
                        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                        body: JSON.stringify({status: select.val()})
                    });
                    const payload = await response.json();
                    if (!response.ok) { throw new Error(payload.message || payload.data || 'Failed'); }
                    await swal.fire({icon: 'success', title: payload.data});
                } catch (error) {
                    await swal.fire({icon: 'error', title: error.message || 'Failed'});
                    datatable.ajax.reload(null, false);
                } finally {
                    select.prop('disabled', false);
                }
            });

            window.addEventListener('message', function (event) {
                if (event.data && event.data.type === 'alphalink-dialog-resize' && event.data.height) {
                    $('.command-modal-dialog iframe').css('height', event.data.height + 'px');
                }
            });

            window.closeModal = function () {
                if (window.domainDialog) { window.domainDialog.modal('hide'); }
                datatable.ajax.reload(null, false);
            };
        });
    </script>
@endpush
