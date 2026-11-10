@extends('layouts.admin-shell')

@section('title', 'ISPs & GEO')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-report-hero" aria-labelledby="isps-hero-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas fa-network-wired"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas fa-network-wired"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">AUDIENCE INTELLIGENCE // GEO DIRECTORY</p>
                <h1 id="isps-hero-title">ISPS &amp; GEO</h1>
                <p>Internet service provider partitions, country tracking filters, and mailer access allocations.</p>
            </div>
            <div class="command-os-hero-actions">
                <button type="button" class="btn-command-primary mr-2" id="hero-add-isp">
                    <i class="fa fa-plus mr-1"></i> ADD NEW ISP
                </button>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </header>

        <!-- 2. Table Card -->
        <div class="command-os-table-card">
            <div class="table-responsive">
                <table id="isps" class="table command-os-table table-sm table-hover w-100" data-source-url="{{ route('clean.console.infrastructure.isps.index') }}" data-create-url="{{ route('clean.console.infrastructure.isps.create') }}"></table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            const tableElement = document.querySelector('#isps');
            let dialog = null;
            const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();

            const openIspFrame = (url) => {
                if (!window.bootbox?.dialog) {
                    window.location.assign(url);
                    return;
                }
                const iframe = `<iframe src="${escapeHtml(url)}" width="100%" scrolling="no" frameborder="0" style="min-height: 440px; border-radius: 12px;"></iframe>`;
                dialog = window.bootbox.dialog({
                    scrollable: true,
                    closeButton: true,
                    size: 'lg',
                    message: `<div class="command-modal-surface p-1">${iframe}</div>`,
                    onEscape: true,
                    centerVertical: true,
                }).on('shown.bs.modal', function () {
                    if (window.iFrameResize) window.iFrameResize({}, 'iframe');
                }).on('hidden.bs.modal', function () {
                    $('iframe').remove();
                });
            };

            $('#hero-add-isp').on('click', () => openIspFrame(tableElement.dataset.createUrl));

            $('#isps').dataTable({
                ajax: tableElement.dataset.sourceUrl,
                serverSide: false,
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>' +
                     '<"table-responsive"t>' +
                     '<"command-table-footer"ip>',
                language: {
                    lengthMenu: 'Show _MENU_ rows',
                    search: '',
                    searchPlaceholder: 'Search ISPs...',
                    info: 'Page _PAGE_ of _PAGES_ (_TOTAL_ records)',
                    infoEmpty: 'Page 0 of 0 (0 records)',
                    paginate: {
                        previous: '<i class="fas fa-chevron-left"></i>',
                        next: '<i class="fas fa-chevron-right"></i>'
                    }
                },
                buttons: [
                    { extend: 'pageLength', className: 'btn-command-action' },
                    {
                        text: '<i class="fas fa-sync-alt mr-1"></i> REFRESH',
                        className: 'btn-command-action',
                        titleAttr: 'REFRESH',
                        action: (event, table) => table.ajax.reload(null, false),
                    },
                    {
                        text: '<i class="fa fa-plus mr-1"></i> ADD NEW ISP',
                        className: 'btn-command-primary',
                        titleAttr: 'ADD NEW ISP',
                        action: () => openIspFrame(tableElement.dataset.createUrl),
                    },
                ],
                rowCallback: (row, data) => {
                    if (!data.is_active) $(row).addClass('table-danger');
                },
                columns: [
                    {
                        title: '#',
                        data: 'id',
                        render: (val) => `<span class="text-muted font-mono" style="font-size: 11px;">#${escapeHtml(val)}</span>`,
                    },
                    {
                        title: 'NAME',
                        data: 'name',
                        render: (val) => `<strong class="text-ink font-w600" style="font-size: 13px;">${escapeHtml(val)}</strong>`,
                    },
                    {
                        title: 'TOTAL LISTS',
                        data: 'lists_count',
                        render: (value) => value == null ? '<span class="text-muted font-size-xs">0</span>' : `<span class="badge-command-pill font-mono">${escapeHtml(value)}</span>`,
                    },
                    {
                        title: 'ALLOWED USERS',
                        data: 'allowed_users',
                        render: (users, type, row) => `<button type="button" data-id="${escapeHtml(row.id)}" class="badge-command-pill users" style="cursor: pointer; border-color: rgba(139, 92, 246, 0.35); color: #8b5cf6;" title="ALLOWED USERS" aria-label="Manage allowed users for ${escapeHtml(row.name)}"><i class="fas fa-users mr-1"></i> ${Array.isArray(users) ? users.length : 0}</button>`,
                    },
                    {
                        title: 'ALLOWED SPONSORS',
                        data: 'allowed_sponsors',
                        render: (sponsors, type, row) => `<button type="button" data-id="${escapeHtml(row.id)}" class="badge-command-pill sponsors" style="cursor: pointer; border-color: rgba(245, 158, 11, 0.35); color: #f59e0b;" title="ALLOWED SPONSORS" aria-label="Manage allowed sponsors for ${escapeHtml(row.name)}"><i class="fas fa-hand-holding-usd mr-1"></i> ${Array.isArray(sponsors) ? sponsors.length : 0}</button>`,
                    },
                    {
                        title: 'TRACKED COUNTRIES',
                        data: 'tracked_countries',
                        render: (countries, type) => {
                            const values = Array.isArray(countries) ? countries : [];
                            return type === 'display'
                                ? values.map((code) => `<span class="badge-command-pill mr-1" style="font-size: 10.5px;" title="${escapeHtml(code)}">${escapeHtml(code)}</span>`).join('')
                                : JSON.stringify(values);
                        },
                    },
                    {
                        title: 'CREATED AT',
                        data: 'created_at',
                        render: (val) => `<span class="text-muted font-mono" style="font-size: 11px;">${escapeHtml(val || '—')}</span>`,
                    },
                    {
                        title: 'ACTIONS',
                        data: null,
                        orderable: false,
                        searchable: false,
                        render: (data, type, row) => {
                            const editUrl = @json(route('clean.console.infrastructure.isps.index')) + `/${row.id}/edit`;
                            const update = `<button data-url="${escapeHtml(editUrl)}" class="command-action-btn action-update update" title="UPDATE"><i class="fas fa-pencil-alt"></i></button>`;
                            const status = `<button data-id="${escapeHtml(row.id)}" data-action="${row.is_active ? 0 : 1}" class="command-action-btn action-status ${row.is_active ? 'is-active' : ''} status" title="${row.is_active ? 'DISABLE' : 'ENABLE'} ISP"><i class="fas ${row.is_active ? 'fa-times' : 'fa-check'}"></i></button>`;
                            const users = `<button data-id="${escapeHtml(row.id)}" class="command-action-btn action-primary users" title="ALLOWED USERS"><i class="fas fa-users"></i></button>`;
                            const sponsors = `<button data-id="${escapeHtml(row.id)}" class="command-action-btn action-warning sponsors" title="ALLOWED SPONSORS"><i class="fas fa-hand-holding-usd"></i></button>`;
                            return `<div class="command-action-btn-group">${users}${sponsors}${update}${status}</div>`;
                        },
                    },
                ],
            }).on('click', '.users', function (event) {
                event.preventDefault();
                const url = @json(route('clean.console.infrastructure.isps.index')) + `/${this.dataset.id}/users`;
                openIspFrame(url);
            }).on('click', '.sponsors', function (event) {
                event.preventDefault();
                const url = @json(route('clean.console.infrastructure.isps.index')) + `/${this.dataset.id}/sponsors`;
                openIspFrame(url);
            }).on('click', '.update', function (event) {
                event.preventDefault();
                openIspFrame(this.dataset.url);
            }).on('click', '.status', async function (event) {
                event.preventDefault();
                const button = this;
                button.disabled = true;
                try {
                    const response = await fetch(@json(route('clean.console.infrastructure.isps.index')) + `/${button.dataset.id}/status`, {
                        method: 'PATCH',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ status: Number(button.dataset.action) }),
                    });
                    const body = await response.json();
                    if (!response.ok) throw new Error(body.message || 'ISP status could not be changed.');
                    if (window.swal?.fire) {
                        await window.swal.fire({ icon: 'success', title: body.data });
                    }
                    $('#isps').DataTable().ajax.reload(null, false);
                } catch (error) {
                    button.disabled = false;
                    if (window.swal?.fire) window.swal.fire({ icon: 'error', title: error.message });
                }
            });

            window.closeModal = function () {
                if (dialog) dialog.modal('hide');
                $('#isps').DataTable().ajax.reload(null, false);
            };
        });
    </script>
@endpush
