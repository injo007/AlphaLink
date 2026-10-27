@extends('layouts.dms-admin')

@section('title', 'Isps - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Isps</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
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
                        width: '20%',
                        render: (data, type, row) => {
                            const editUrl = @json(route('console.infrastructure.isps.index', $workspace->id)) + `/${row.id}/edit`;
                            const update = `<button data-url="${escapeHtml(editUrl)}" class="btn btn-info btn-sm update" title="UPDATE"><i class="fas fa-pencil-alt"></i></button>&nbsp;`;
                            const status = `<button data-id="${escapeHtml(row.id)}" data-action="${row.is_active ? 0 : 1}" class="btn ${row.is_active ? 'btn-warning' : 'btn-success'} btn-sm status" title="${row.is_active ? 'DISABLE' : 'ENABLE'} ISP"><i class="fas ${row.is_active ? 'fa-times' : 'fa-check'}"></i></button>&nbsp;`;
                            const users = `<button data-id="${escapeHtml(row.id)}" class="btn btn-primary btn-sm users" title="ALLOWED USERS"><i class="fas fa-users"></i></button>&nbsp;`;
                            const sponsors = `<button data-id="${escapeHtml(row.id)}" class="btn btn-primary btn-sm sponsors" title="ALLOWED SPONSORS"><i class="fas fa-hand-holding-usd"></i></button>&nbsp;`;
                            return users + sponsors + update + status;
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
