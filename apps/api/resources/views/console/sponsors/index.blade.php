@extends('layouts.admin-shell')

@section('title', 'Sponsors')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-report-hero" aria-labelledby="sponsors-hero-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas fa-building"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas fa-building"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">SPONSOR DIRECTORY // ADVERTISER PLATFORMS</p>
                <h1 id="sponsors-hero-title">SPONSORS</h1>
                <p>Manage connected affiliate networks, API configurations, operator permissions, and session helpers.</p>
            </div>
            <div class="command-os-hero-actions">
                <div class="command-os-report-hero-range">
                    <span>CLUSTER STATUS</span>
                    <strong><i class="fas fa-circle text-success" style="font-size: 8px;"></i> LIVE SYNC</strong>
                </div>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </header>

        <!-- 2. KPI Overview Cards -->
        <section class="command-os-all-stats-overview" aria-label="Sponsors Overview">
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-blue" aria-hidden="true"><i class="fas fa-building"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Total Sponsors</span>
                    <strong id="stat-sponsors-count">{{ $workspace->sponsors()->count() }}</strong>
                    <small>Connected platforms</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-emerald" aria-hidden="true"><i class="fas fa-plug"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Active Networks</span>
                    <strong>{{ $activeSponsorsCount ?? $sponsorsCount ?? 0 }}</strong>
                    <small>Enabled integration</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-puzzle-piece"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Browser Helper</span>
                    <strong>{{ !empty($extensionUrl) ? 'READY' : 'LOCAL' }}</strong>
                    <small>Session proxy agent</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-cyan" aria-hidden="true"><i class="fas fa-tags"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Linked Offers</span>
                    <strong>{{ $sponsorsTotalOffers ?? 0 }}</strong>
                    <small>Campaigns catalog</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-amber" aria-hidden="true"><i class="fas fa-user-shield"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Access Control</span>
                    <strong>ENFORCED</strong>
                    <small>Role &amp; operator scope</small>
                </span>
            </article>
        </section>

        <!-- 3. Info Banner -->
        <div class="command-os-info-banner" role="note">
            <div class="command-os-info-banner-icon" aria-hidden="true">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="command-os-info-banner-content">
                <div>Sponsor login opens a provider session in the browser helper. Download and load the helper before using Login.</div>
                @if (!empty($extensionUrl))
                    <a href="{{ $extensionUrl }}" class="btn btn-sm btn-alt-primary mt-1"><i class="fas fa-download mr-1"></i> Download sponsor helper</a>
                @else
                    <div class="small mt-1 text-warning"><i class="fas fa-exclamation-triangle mr-1"></i> The sponsor helper bundle is not installed on this app server, so download and browser handoff are unavailable.</div>
                @endif
                <div class="small mt-1 text-muted">The browser helper requests cookie access across HTTP and HTTPS websites. Review that browser permission scope before installing it.</div>
            </div>
        </div>

        <!-- 4. Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center">
                    <i class="fas fa-building text-purple font-size-lg mr-2"></i>
                    <h3 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">Sponsor Networks &amp; Platform Gateways</h3>
                </div>
                <span class="badge badge-light border text-muted px-2 py-1 font-size-xs">
                    <i class="fas fa-network-wired mr-1 text-purple"></i> API Integration Grid
                </span>
            </div>
            <table id="sponsors" class="table table-sm table-hover"
                   data-source-url="{{ route('clean.console.sponsors.index') }}"
                   data-create-url="{{ route('clean.console.sponsors.create') }}"
                   data-bulk-users-url="{{ route('clean.console.sponsors.bulk-users') }}"
                   data-login-url="{{ route('clean.console.sponsors.login', 0) }}"
                   data-extension-id="{{ $extensionId ?? '' }}"></table>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            const tableElement = document.querySelector('#sponsors');
            let dialog = null;
            const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();

            const openSponsorFrame = (url) => {
                if (!window.bootbox?.dialog) {
                    window.location.assign(url);
                    return;
                }
                const iframe = `<iframe src="${escapeHtml(url)}" width="100%" scrolling="no" frameborder="0"></iframe>`;
                dialog = window.bootbox.dialog({
                    scrollable: true,
                    closeButton: true,
                    size: 'lg',
                    message: iframe,
                    onEscape: true,
                    centerVertical: true,
                }).on('shown.bs.modal', function () {
                    if (window.iFrameResize) window.iFrameResize({}, 'iframe');
                }).on('hidden.bs.modal', function () {
                    $('iframe').remove();
                });
            };

            const sponsorsTable = $('#sponsors').dataTable({
                ajax: tableElement.dataset.sourceUrl,
                serverSide: false,
                pageLength: 20,
                order: [[1, 'desc']],
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>' +
                     '<"table-responsive"t>' +
                     '<"command-table-footer"ip>',
                language: {
                    lengthMenu: 'Show _MENU_ rows',
                    search: '',
                    searchPlaceholder: 'Search sponsors...',
                    info: 'Page _PAGE_ of _PAGES_ (_TOTAL_ records)',
                    infoEmpty: 'Page 0 of 0 (0 records)',
                    paginate: {
                        previous: '<i class="fas fa-chevron-left"></i>',
                        next: '<i class="fas fa-chevron-right"></i>'
                    }
                },
                buttons: {
                    dom: {
                        container: {
                            tag: 'div',
                            className: 'dt-buttons'
                        },
                        button: {
                            tag: 'button',
                            className: 'btn'
                        }
                    },
                    buttons: [
                        { extend: 'pageLength', className: 'btn-command-action' },
                        {
                            text: '<i class="far fa-square mr-1"></i> DESELECT',
                            className: 'btn-command-action',
                            titleAttr: 'SELECT NONE',
                            action: (event, dt) => dt.rows({ page: 'current' }).deselect(),
                        },
                        {
                            text: '<i class="far fa-check-square mr-1"></i> SELECT ALL',
                            className: 'btn-command-action',
                            titleAttr: 'SELECT ALL',
                            action: (event, dt) => dt.rows({ page: 'current' }).select(),
                        },
                        {
                            text: '<i class="fas fa-plus mr-1"></i> NEW SPONSOR',
                            className: 'btn-command-primary',
                            titleAttr: 'ADD NEW SPONSOR',
                            action: () => openSponsorFrame(tableElement.dataset.createUrl),
                        },
                        {
                            text: '<i class="fas fa-users-cog mr-1"></i> BULK USERS',
                            className: 'btn-command-action',
                            titleAttr: 'ALLOWED USERS',
                            action: (event, table) => {
                                const ids = table.rows({ selected: true }).data().toArray().map((row) => row.id);
                                if (!ids.length) return;
                                openSponsorFrame(`${tableElement.dataset.bulkUsersUrl}?${$.param({ ids })}`);
                            },
                        },
                        {
                            text: '<i class="fas fa-sync-alt mr-1"></i> REFRESH',
                            className: 'btn-command-action',
                            titleAttr: 'REFRESH',
                            action: (event, table) => table.ajax.reload(null, false),
                        },
                    ]
                },
                rowCallback: (row, data) => {
                    if (!data.is_active) $('td', row).addClass('is-inactive-row');
                },
                select: { style: 'multi+shift', selector: 'td:first-child' },
                columns: [
                    { data: null, defaultContent: '', orderable: false, searchable: false, className: 'select-checkbox', title: '', width: '3%' },
                    { title: '#', data: 'id', width: '6%', render: (val) => `<span class="command-mono-badge">#${escapeHtml(val)}</span>` },
                    { title: 'AFFILIATE ID', data: 'affiliate_id', width: '8%', render: (val) => val ? `<span class="badge-command-pill" style="font-family: monospace;">${escapeHtml(val)}</span>` : '<span class="text-muted">—</span>' },
                    { title: 'NAME', data: 'name', width: '12%', render: (val) => `<strong style="color: var(--command-os-text, #1e293b);">${escapeHtml(val)}</strong>` },
                    { title: 'USERNAME', data: 'username', width: '14%', render: (val) => `<span style="font-family: monospace; font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">${escapeHtml(val)}</span>` },
                    { title: 'TYPE', data: 'type', width: '8%', render: (val) => `<span class="badge-command-pill" style="color: #8b5cf6; font-weight: 700; text-transform: uppercase;">${escapeHtml(val || 'GENERIC')}</span>` },
                    { title: 'CREATED AT', data: 'created_at', width: '9%', render: (val) => val ? `<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">${escapeHtml(val.split(' ')[0] || val)}</span>` : '' },
                    { title: 'TOTAL OFFERS', data: 'offers_count', width: '8%', render: (value, type, row) => type === 'display' ? `<a href="${@json(route('clean.console.offers.index'))}?sponsor=${escapeHtml(row.id)}" class="badge-command-pill" style="color: #6366f1; text-decoration: none; font-weight: 700;"><i class="fas fa-tag mr-1" style="font-size: 0.75rem;"></i>${Number(value || 0)} Offers</a>` : value },
                    {
                        title: 'USERS',
                        data: 'allowed_users',
                        searchable: false,
                        width: '7%',
                        render: (users, type, row) => `<button type="button" data-id="${escapeHtml(row.id)}" class="badge-command-pill users command-users-link" title="ALLOWED USERS" aria-label="Manage allowed users for ${escapeHtml(row.name)}"><i class="fas fa-users mr-1"></i>${Array.isArray(users) ? users.length : 0}</button>`,
                    },
                    {
                        title: 'URL',
                        data: 'url',
                        width: '6%',
                        render: (url, type) => type === 'display' && url
                            ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" class="command-action-btn action-url" title="Open sponsor website" aria-label="Open sponsor website"><i class="fas fa-external-link-alt"></i></a>`
                            : (url || ''),
                    },
                    {
                        title: 'ACTIONS',
                        data: null,
                        orderable: false,
                        searchable: false,
                        width: '14%',
                        render: (data, type, row) => {
                            const editUrl = @json(route('clean.console.sponsors.index')) + `/${row.id}/edit`;
                            const login = `<button type="button" data-id="${escapeHtml(row.id)}" class="command-action-btn action-terminal login" title="LOGIN SPONSOR" aria-label="Login to ${escapeHtml(row.name)}"><i class="fas fa-sign-in-alt"></i></button>`;
                            const update = `<button type="button" data-url="${escapeHtml(editUrl)}" class="command-action-btn action-info update" title="UPDATE SPONSOR" aria-label="Update ${escapeHtml(row.name)}"><i class="fas fa-pencil-alt"></i></button>`;
                            const statusButton = `<button type="button" data-id="${escapeHtml(row.id)}" data-action="status" data-status="${row.is_active ? 0 : 1}" class="command-action-btn ${row.is_active ? 'action-warning' : 'action-success'} status" title="${row.is_active ? 'DISABLE SPONSOR' : 'ENABLE SPONSOR'}" aria-label="${row.is_active ? 'DISABLE SPONSOR' : 'ENABLE SPONSOR'}"><i class="fas ${row.is_active ? 'fa-pause' : 'fa-play'}"></i></button>`;
                            return `<div class="command-action-btn-group">${login}${update}${statusButton}</div>`;
                        },
                    },
                ],
            });

            sponsorsTable.on('xhr.dt', function (e, settings, json) {
                if (json?.data) {
                    $('#stat-sponsors-count').text(json.data.length);
                }
            });

            $('#sponsors').on('click', '.users', function (event) {
                event.preventDefault();
                const url = @json(route('clean.console.sponsors.index')) + `/${this.dataset.id}/users`;
                openSponsorFrame(url);
            }).on('click', '.update', function (event) {
                event.preventDefault();
                openSponsorFrame(this.dataset.url);
            }).on('click', '.login', async function (event) {
                event.preventDefault();
                const button = this;
                const priorHtml = button.innerHTML;
                const showError = (title) => window.swal?.fire
                    ? window.swal.fire({ icon: 'error', title })
                    : window.alert(title);
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
                try {
                    const extensionId = tableElement.dataset.extensionId;
                    if (!window.App?.pingExtension || !await window.App.pingExtension(extensionId)) {
                        await showError('Sponsor helper is not installed or enabled. Download it above, extract it, and load it from your browser extensions page.');
                        return;
                    }
                    const loginUrl = tableElement.dataset.loginUrl.replace(/\/0\/login$/, `/${encodeURIComponent(button.dataset.id)}/login`);
                    const response = await fetch(loginUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                    });
                    const result = await response.json();
                    if (!response.ok || result.status !== true) {
                        await showError(result.message || 'Sponsor login could not be completed.');
                        return;
                    }
                    const extensionResult = await window.App.commandExtension(extensionId, { type: 'connect', data: result });
                    if (extensionResult?.status !== 'ok') {
                        await showError('The browser helper did not confirm the sponsor session handoff.');
                    }
                } catch (error) {
                    await showError(error.message || 'Sponsor login could not be completed.');
                } finally {
                    button.disabled = false;
                    button.innerHTML = priorHtml;
                }
            }).on('click', '.status', async function (event) {
                event.preventDefault();
                const button = this;
                const priorHtml = button.innerHTML;
                button.disabled = true;
                try {
                    const response = await fetch(@json(route('clean.console.sponsors.index')) + `/${button.dataset.id}/status`, {
                        method: 'PATCH',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ status: Number(button.dataset.status) }),
                    });
                    const body = await response.json();
                    if (!response.ok) throw new Error(body.message || 'Sponsor status could not be changed.');
                    if (window.swal?.fire) await window.swal.fire({ icon: 'success', title: body.data });
                    $('#sponsors').DataTable().ajax.reload(null, false);
                } catch (error) {
                    button.disabled = false;
                    if (window.swal?.fire) await window.swal.fire({ icon: 'error', title: error.message });
                } finally {
                    button.innerHTML = priorHtml;
                }
            });

            window.closeModal = function () {
                if (dialog) dialog.modal('hide');
                $('#sponsors').DataTable().ajax.reload(null, false);
            };
        });
    </script>
@endpush
