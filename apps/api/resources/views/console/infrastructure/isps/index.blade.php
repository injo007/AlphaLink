@extends('layouts.admin-shell')

@section('title', 'Isps')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-hero-card">
            <div class="command-os-hero-card-left">
                <div class="command-os-hero-mark" aria-hidden="true">
                    <i class="fas fa-network-wired"></i>
                </div>
                <div class="command-os-hero-copy">
                    <h1 class="command-os-hero-title">ISPS</h1>
                </div>
            </div>
            <div class="command-os-hero-card-right">
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="si si-size-fullscreen"></i>
                </button>
            </div>
        </header>

        <!-- 2. Table Card -->
        <div class="command-os-table-card">
            <div class="table-responsive">
                <table id="isps" class="table table-sm table-hover table-striped" data-source-url="{{ route('console.infrastructure.isps.index', $workspace->id) }}" data-create-url="{{ route('console.infrastructure.isps.create', $workspace->id) }}"></table>
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
            const actionButton = (icon, title, color, id, action) => (
                `<button type="button" data-id="${escapeHtml(id)}" data-action="${escapeHtml(action)}" ` +
                `class="btn ${color} btn-sm ${action}" title="${escapeHtml(title)}" aria-label="${escapeHtml(title)}"><i class="fas ${icon}"></i></button>&nbsp;`
            );

            const openIspFrame = (url) => {
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

            $('#isps').dataTable({
                ajax: tableElement.dataset.sourceUrl,
                serverSide: false,
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>' +
                     '<"table-responsive"t>' +
                     '<"command-table-footer"ip>',
                language: {
                    lengthMenu: 'Show _MENU_ rows',
                    search: '',
                    searchPlaceholder: 'Search...',
                    info: 'Page _PAGE_ of _PAGES_ (_TOTAL_ records)',
                    infoEmpty: 'Page 0 of 0 (0 records)',
                    paginate: {
                        previous: '<i class="fas fa-chevron-left"></i>',
                        next: '<i class="fas fa-chevron-right"></i>'
                    }
                },
                buttons: [
                    { extend: 'pageLength' },
                    {
                        text: '<i class="fas fa-sync"></i>',
                        titleAttr: 'REFRESH',
                        action: (event, table) => table.ajax.reload(null, false),
                    },
                    {
                        text: '<i class="fa fa-plus"></i>',
                        titleAttr: 'ADD NEW ISP',
                        action: () => openIspFrame(tableElement.dataset.createUrl),
                    },
                ],
                rowCallback: (row, data) => {
                    if (!data.is_active) $(row).addClass('table-danger');
                },
                columns: [
                    { title: '#', data: 'id' },
                    { title: 'NAME', data: 'name' },
                    {
                        title: 'TOTAL LISTS',
                        data: 'lists_count',
                        render: (value) => value == null ? '<span class="text-muted">N/A</span>' : value,
                    },
                    {
                        title: 'ALLOWED USERS',
                        data: 'allowed_users',
                        render: (users, type, row) => `<button type="button" data-id="${escapeHtml(row.id)}" class="btn btn-primary btn-sm users" title="ALLOWED USERS" aria-label="Manage allowed users for ${escapeHtml(row.name)}"><i class="fas fa-users"></i> : ${Array.isArray(users) ? users.length : 0}</button>&nbsp;`,
                    },
                    {
                        title: 'ALLOWED SPONSORS',
                        data: 'allowed_sponsors',
                        render: (sponsors, type, row) => `<button type="button" data-id="${escapeHtml(row.id)}" class="btn btn-primary btn-sm sponsors" title="ALLOWED SPONSORS" aria-label="Manage allowed sponsors for ${escapeHtml(row.name)}"><i class="fas fa-hand-holding-usd"></i> : ${Array.isArray(sponsors) ? sponsors.length : 0}</button>&nbsp;`,
                    },
                    {
                        title: 'TRACKED COUNTRIES',
                        data: 'tracked_countries',
                        render: (countries, type) => {
                            const values = Array.isArray(countries) ? countries : [];
                            return type === 'display'
                                ? values.map((code) => `<span class="badge badge-light ml-1" title="${escapeHtml(code)}">${escapeHtml(code)}</span>`).join('')
                                : JSON.stringify(values);
                        },
                    },
                    { title: 'CREATED AT', data: 'created_at' },
                    {
                        title: 'ACTIONS',
                        data: null,
                        orderable: false,
                        searchable: false,
                        render: (data, type, row) => {
                            const editUrl = @json(route('console.infrastructure.isps.index', $workspace->id)) + `/${row.id}/edit`;
                            const update = `<button data-url="${escapeHtml(editUrl)}" class="command-action-btn action-info update" title="UPDATE"><i class="fas fa-pencil-alt"></i></button>`;
                            const status = `<button data-id="${escapeHtml(row.id)}" data-action="${row.is_active ? 0 : 1}" class="command-action-btn action-status ${row.is_active ? 'is-active' : ''} status" title="${row.is_active ? 'DISABLE' : 'ENABLE'} ISP"><i class="fas ${row.is_active ? 'fa-times' : 'fa-check'}"></i></button>`;
                            const users = `<button data-id="${escapeHtml(row.id)}" class="command-action-btn action-primary users" title="ALLOWED USERS"><i class="fas fa-users"></i></button>`;
                            const sponsors = `<button data-id="${escapeHtml(row.id)}" class="command-action-btn action-warning sponsors" title="ALLOWED SPONSORS"><i class="fas fa-hand-holding-usd"></i></button>`;
                            return `<div class="command-action-btn-group">${users}${sponsors}${update}${status}</div>`;
                        },
                    },
                ],
            }).on('click', '.users', function (event) {
                event.preventDefault();
                const url = @json(route('console.infrastructure.isps.index', $workspace->id)) + `/${this.dataset.id}/users`;
                openIspFrame(url);
            }).on('click', '.sponsors', function (event) {
                event.preventDefault();
                const url = @json(route('console.infrastructure.isps.index', $workspace->id)) + `/${this.dataset.id}/sponsors`;
                openIspFrame(url);
            }).on('click', '.update', function (event) {
                event.preventDefault();
                openIspFrame(this.dataset.url);
            }).on('click', '.status', async function (event) {
                event.preventDefault();
                const button = this;
                button.disabled = true;
                try {
                    const response = await fetch(@json(route('console.infrastructure.isps.index', $workspace->id)) + `/${button.dataset.id}/status`, {
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
