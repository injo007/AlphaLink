@extends('layouts.admin-shell')

@section('title', 'Domain Providers')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-report-hero">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-mark" aria-hidden="true">
                    <i class="fas fa-cloud"></i>
                </div>
                <div class="command-os-report-hero-copy">
                    <span class="command-os-report-hero-eyebrow">INFRASTRUCTURE TELEMETRY // DNS GATEWAYS</span>
                    <h1 class="command-os-report-hero-title">DOMAIN PROVIDERS</h1>
                    <p class="command-os-report-hero-subtitle">Connected DNS registrars, Cloudflare API credentials, and account synchronizations.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-badge">
                    <span class="status-dot"></span>
                    <span>API INTEGRATION // CLOUD CONNECT</span>
                </div>
                <button type="button" class="command-os-btn-option ml-2" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen" title="Full screen">
                    <i class="fas fa-expand-alt" aria-hidden="true"></i>
                </button>
            </div>
        </header>

        <!-- 2. KPI Overview Cards -->
        <section class="command-os-all-stats-overview" aria-label="Domain Providers Overview">
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-cloud"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Providers</span>
                    <strong id="stat-providers">{{ \App\Models\DomainProvider::where('workspace_id', $workspace->getKey())->count() }}</strong>
                    <small>Connected accounts</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-blue" aria-hidden="true"><i class="fas fa-globe"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Associated Domains</span>
                    <strong id="stat-associated-domains">{{ $workspace->sendingDomains()->count() }}</strong>
                    <small>Configured zones</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-emerald" aria-hidden="true"><i class="fas fa-bolt"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Cloudflare API</span>
                    <strong>CONNECTED</strong>
                    <small>Automated DNS sync</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-cyan" aria-hidden="true"><i class="fas fa-shield-alt"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Proxy Guard</span>
                    <strong>ENABLED</strong>
                    <small>Cloud security routing</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-amber" aria-hidden="true"><i class="fas fa-key"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Auth Scope</span>
                    <strong>ENCRYPTED</strong>
                    <small>Secure credential store</small>
                </span>
            </article>
        </section>

        <!-- 3. Table Card -->
        <div class="command-os-table-card">
            <div class="table-responsive">
                <table id="providers" class="table table-sm table-hover table-striped"></table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();
            const table = $('#providers').DataTable({
                ajax: @json(route('clean.console.infrastructure.domain-providers.index')),
                serverSide: false,
                displayLength: 20,
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>' +
                     '<"table-responsive"t>' +
                     '<"command-table-footer"ip>',
                language: {
                    lengthMenu: 'Show _MENU_ rows',
                    search: '',
                    searchPlaceholder: 'Search providers...',
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
                            titleAttr: 'REFRESH PROVIDERS',
                            action: (event, datatable) => datatable.ajax.reload(null, false)
                        },
                        {
                            text: '<i class="fas fa-plus mr-1"></i> NEW PROVIDER',
                            className: 'btn-command-primary',
                            titleAttr: 'ADD NEW PROVIDER',
                            action: function () {
                                const link = @json(route('clean.console.infrastructure.domain-providers.create'));
                                const iframe = `<iframe src="${escapeHtml(link)}" width="100%" scrolling="no" frameborder="0" title="Add domain provider"></iframe>`;
                                window.providerDialog = bootbox.dialog({
                                    scrollable: true,
                                    closeButton: true,
                                    size: 'lg',
                                    message: iframe,
                                    onEscape: true,
                                    centerVertical: true,
                                }).on('shown.bs.modal', function () {
                                    if (typeof iFrameResize === 'function') { iFrameResize({}, 'iframe'); }
                                }).on('hidden.bs.modal', function () {
                                    $('iframe').remove();
                                });
                            }
                        }
                    ]
                },
                rowCallback: function (row, data) {
                    if (!data.is_active) { $(row).addClass('table-danger'); }
                },
                columns: [
                    { title: '#', data: 'id', render: (data) => `<span class="badge badge-light border font-mono font-size-xs text-muted">#${escapeHtml(data)}</span>` },
                    { title: 'NAME', data: 'name', render: (data) => `<span class="font-w600 text-purple">${escapeHtml(data)}</span>` },
                    { title: 'TYPE', data: 'type', render: (data) => `<span class="badge badge-light border text-purple font-w700 text-uppercase px-2 py-1">${escapeHtml(data)}</span>` },
                    { title: 'USERNAME', data: 'username', render: (data) => `<span class="font-mono font-size-sm">${escapeHtml(data)}</span>` },
                    {
                        title: 'TOTAL DOMAINS',
                        data: 'sending_domains_count',
                        render: (count, type) => type === 'display' ? `<a href="${@json(route('clean.console.infrastructure.domains.index'))}" class="command-table-link"><span class="badge badge-light border text-purple px-2 py-1 font-w700">${Number(count) || 0} Domains</span></a>` : count,
                    },
                    { title: 'CREATED AT', data: 'created_at', render: (data) => `<span class="text-muted font-size-xs">${escapeHtml(data)}</span>` },
                    {
                        title: 'ACTIONS', data: null, orderable: false, searchable: false, width: '120px',
                        render: function (data, type, row) {
                            const id = escapeHtml(row.id);
                            const update = `<button type="button" data-id="${id}" class="command-action-btn action-info update" title="UPDATE PROVIDER" aria-label="Update provider"><i class="fas fa-pencil-alt"></i></button>`;
                            const active = Boolean(row.is_active);
                            const status = `<button type="button" data-id="${id}" data-action="${active ? 0 : 1}" class="command-action-btn action-status ${active ? 'is-active' : ''} status" title="${active ? 'DISABLE' : 'ENABLE'} PROVIDER" aria-label="${active ? 'Disable' : 'Enable'} provider"><i class="fas ${active ? 'fa-times' : 'fa-check'}"></i></button>`;
                            return `<div class="command-action-btn-group">${update}${status}</div>`;
                        },
                    },
                ],
            });

            $('#providers').on('click', '.update', function () {
                const base = @json(route('clean.console.infrastructure.domain-providers.index'));
                const link = `${base}/${encodeURIComponent(this.dataset.id)}/edit`;
                const iframe = `<iframe src="${escapeHtml(link)}" width="100%" scrolling="no" frameborder="0" title="Edit domain provider"></iframe>`;
                window.providerDialog = bootbox.dialog({
                    scrollable: true, closeButton: true, size: 'lg', message: iframe,
                    onEscape: true, centerVertical: true,
                }).on('shown.bs.modal', function () {
                    if (typeof iFrameResize === 'function') { iFrameResize({}, 'iframe'); }
                }).on('hidden.bs.modal', function () { $('iframe').remove(); });
            }).on('click', '.status', async function () {
                const button = this;
                const original = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                try {
                    const base = @json(route('clean.console.infrastructure.domain-providers.index'));
                    const response = await fetch(`${base}/${encodeURIComponent(button.dataset.id)}/status`, {
                        method: 'PATCH',
                        headers: {
                            'Accept': 'application/json', 'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ status: Number(button.dataset.action) }),
                    });
                    const body = await response.json();
                    if (!response.ok) { throw new Error(body.message || 'Provider status could not be changed.'); }
                    await window.App.swalPromise({ icon: 'success', title: body.data, confirmButtonText: 'close' });
                    table.ajax.reload(null, false);
                } catch (error) {
                    window.App.processAjaxFail({ error: error.message });
                } finally {
                    button.innerHTML = original;
                    button.disabled = false;
                }
            });

            window.closeModal = function () {
                if (window.providerDialog) { window.providerDialog.modal('hide'); }
                table.ajax.reload(null, false);
            };
        });
    </script>
@endpush
