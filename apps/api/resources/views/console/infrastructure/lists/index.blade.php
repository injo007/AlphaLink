@extends('layouts.dms-admin')

@section('title', 'Lists - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Lists</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="row">
                <div class="col-sm-12 col-md-3">
                    <div class="form-group">
                        <label for="isps">Isps:</label>
                        <select id="isps" name="isps" class="form-control selectpicker" data-size="10" data-live-search="true" title="Select Isp ..." data-allow-clear="true">
                            @foreach ($isps as $isp)
                                <option value="{{ $isp->id }}">{{ $isp->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-sm-12 col-md-2">
                    <div class="form-group" style="margin-top: 1.8rem !important;">
                        <button id="load_lists" type="button" class="btn btn-alt-primary btn-block">LOAD LISTS</button>
                    </div>
                </div>
            </div>
            <div class="row push">
                <div class="col-sm-12 table-responsive">
                    <table id="lists" class="table table-sm table-hover"
                        data-workspace-id="{{ $workspace->id }}"
                        data-base-url="{{ url('/app/workspaces/'.$workspace->id.'/infrastructure') }}"></table>
                </div>
            </div>
            @if ($isps->isEmpty())
                <div class="alert alert-info mb-0">Add an ISP before creating its lists.</div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            if ($.fn.selectpicker) $('#isps').selectpicker();
            const tableElement = document.querySelector('#lists');
            const baseUrl = tableElement.dataset.baseUrl;
            let dialog = null;
            let activeTableUrl = null;
            const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();
            const activeId = () => $('#isps').val();

            const openFrame = (url) => {
                if (!window.bootbox?.dialog) {
                    window.location.assign(url);
                    return;
                }
                dialog = window.bootbox.dialog({
                    scrollable: true,
                    closeButton: true,
                    size: 'lg',
                    message: `<iframe src="${escapeHtml(url)}" width="100%" scrolling="no" frameborder="0"></iframe>`,
                    onEscape: true,
                    centerVertical: true,
                }).on('shown.bs.modal', function () {
                    if (window.iFrameResize) window.iFrameResize({}, 'iframe');
                }).on('hidden.bs.modal', function () {
                    $('iframe').remove();
                });
            };

            const send = async (url, method, payload) => {
                const response = await fetch(url, {
                    method,
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(payload),
                });
                const body = await response.json();
                if (!response.ok) throw new Error(body.message || body.data || 'The list change could not be saved.');
                return body;
            };

            const reload = () => {
                if (activeTableUrl && $.fn.DataTable.isDataTable('#lists')) {
                    $('#lists').DataTable().ajax.reload(null, false);
                }
            };

            const makeTable = (ispId) => {
                activeTableUrl = `${baseUrl}/isps/${encodeURIComponent(ispId)}/lists`;
                if ($.fn.DataTable.isDataTable('#lists')) {
                    $('#lists').DataTable().clear().destroy();
                    $('#lists').off().empty();
                }
                $('#lists').html('<tfoot><tr><td colspan="3">TOTAL:</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr></tfoot>');
                $('#lists').dataTable({
                    ajax: activeTableUrl,
                    serverSide: false,
                    paging: false,
                    pageLength: -1,
                    lengthChange: false,
                    select: { style: 'os' },
                    buttons: [
                        { text: '<i class="far fa-square"></i>', titleAttr: 'SELECT NONE', action: (event, table) => table.rows({ page: 'current' }).deselect() },
                        { text: '<i class="far fa-check-square"></i>', titleAttr: 'SELECT ALL', action: (event, table) => table.rows({ page: 'current' }).select() },
                        {
                            text: '<i class="fas fa-users"></i>', titleAttr: 'ALLOWED USERS',
                            action: (event, table) => openBulkAssignment(table, 'users'),
                        },
                        {
                            text: '<i class="fas fa-hand-holding-usd"></i>', titleAttr: 'ALLOWED SPONSORS',
                            action: (event, table) => openBulkAssignment(table, 'sponsors'),
                        },
                        {
                            text: '<i class="fa fa-plus"></i>', titleAttr: 'ADD NEW LIST',
                            action: () => openFrame(`${baseUrl}/isps/${encodeURIComponent(ispId)}/lists/create`),
                        },
                        { text: '<i class="fas fa-sync"></i>', titleAttr: 'REFRESH', action: (event, table) => table.ajax.reload(null, false) },
                    ],
                    rowCallback: (row, data) => {
                        if (!data.is_active) $('td', row).addClass('table-danger');
                        ['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'].forEach((type, index) => {
                            if (data[`is_${type}_locked`]) $('td', row).eq(index + 3).addClass('table-warning');
                        });
                    },
                    columns: [
                        { title: '#', data: 'id' },
                        { title: 'NAME', data: 'name' },
                        { title: 'COUNTRY', data: 'country', defaultContent: '' },
                        ...['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'].map((type) => ({
                            title: type === 'delivery' ? 'dlv' : type === 'unsubscribe' ? 'unsub' : type,
                            data: `history.${type}`,
                            searchable: false,
                            render: (value, display, row) => `<span>${escapeHtml(value ?? 'NA')}</span>&nbsp;<button type="button" class="btn btn-link btn-sm lock" data-id="${escapeHtml(row.id)}" data-type="${type}" data-status="${row[`is_${type}_locked`] ? 0 : 1}" title="${row[`is_${type}_locked`] ? 'UNLOCK' : 'LOCK'} LIST TYPE" aria-label="${row[`is_${type}_locked`] ? 'Unlock' : 'Lock'} ${type} for ${escapeHtml(row.name)}"><i class="fas ${row[`is_${type}_locked`] ? 'fa-lock' : 'fa-lock-open'}"></i></button>`,
                        })),
                        ...['opt_out', 'proxy', 'bot', 'blacklist', 'hard_bounce'].map((type) => ({
                            title: ({ opt_out: 'optout', proxy: 'proxy', bot: 'bt', blacklist: 'bl', hard_bounce: 'hb' })[type],
                            data: `history.${type}`,
                            searchable: false,
                            render: (value) => escapeHtml(value ?? 'NA'),
                        })),
                        {
                            title: 'ACTIONS', data: null, orderable: false, searchable: false,
                            render: (data, display, row) => {
                                const edit = `<button type="button" data-id="${escapeHtml(row.id)}" class="btn btn-info btn-sm edit" title="UPDATE LIST" aria-label="Update ${escapeHtml(row.name)}"><i class="fas fa-pencil-alt"></i></button>&nbsp;`;
                                const users = `<button type="button" data-id="${escapeHtml(row.id)}" class="btn btn-primary btn-sm assignment" data-type="users" title="ALLOWED USERS" aria-label="Allowed users for ${escapeHtml(row.name)}"><i class="fas fa-users"></i></button>&nbsp;`;
                                const sponsors = `<button type="button" data-id="${escapeHtml(row.id)}" class="btn btn-primary btn-sm assignment" data-type="sponsors" title="ALLOWED SPONSORS" aria-label="Allowed sponsors for ${escapeHtml(row.name)}"><i class="fas fa-hand-holding-usd"></i></button>&nbsp;`;
                                const upload = `<button type="button" data-id="${escapeHtml(row.id)}" class="btn btn-secondary btn-sm upload" title="UPLOAD EMAILS" aria-label="Upload emails to ${escapeHtml(row.name)}"><i class="fas fa-upload"></i></button>&nbsp;`;
                                const track = `<button type="button" data-id="${escapeHtml(row.id)}" data-status="${row.is_tracked ? 0 : 1}" class="btn ${row.is_tracked ? 'btn-success' : 'btn-warning'} btn-sm track" title="${row.is_tracked ? 'DISABLE' : 'ENABLE'} TRACKING" aria-label="Toggle tracking for ${escapeHtml(row.name)}"><i class="fas ${row.is_tracked ? 'fa-thumbs-up' : 'fa-thumbs-down'}"></i></button>&nbsp;`;
                                const status = `<button type="button" data-id="${escapeHtml(row.id)}" data-status="${row.is_active ? 0 : 1}" class="btn ${row.is_active ? 'btn-warning' : 'btn-success'} btn-sm status" title="${row.is_active ? 'DISABLE' : 'ENABLE'} LIST" aria-label="${row.is_active ? 'Disable' : 'Enable'} ${escapeHtml(row.name)}"><i class="fas ${row.is_active ? 'fa-times' : 'fa-check'}"></i></button>&nbsp;`;
                                return users + sponsors + upload + edit + track + status;
                            },
                        },
                    ],
                }).on('click', '.edit', function (event) {
                    event.preventDefault();
                    openFrame(`${baseUrl}/lists/${encodeURIComponent(this.dataset.id)}/edit`);
                }).on('click', '.assignment', function (event) {
                    event.preventDefault();
                    openFrame(`${baseUrl}/lists/${encodeURIComponent(this.dataset.id)}/assignment/${this.dataset.type}`);
                }).on('click', '.upload', function (event) {
                    event.preventDefault();
                    openFrame(`${baseUrl}/lists/${encodeURIComponent(this.dataset.id)}/upload`);
                }).on('click', '.lock', async function (event) {
                    event.preventDefault();
                    const button = this;
                    button.disabled = true;
                    try {
                        await send(`${baseUrl}/lists/${encodeURIComponent(button.dataset.id)}/lock`, 'PATCH', { type: button.dataset.type, status: Number(button.dataset.status) });
                        reload();
                    } catch (error) {
                        button.disabled = false;
                        if (window.swal?.fire) await window.swal.fire({ icon: 'error', title: error.message });
                    }
                }).on('click', '.track', async function (event) {
                    event.preventDefault();
                    const button = this;
                    button.disabled = true;
                    try {
                        await send(`${baseUrl}/lists/${encodeURIComponent(button.dataset.id)}/track`, 'PATCH', { track: Number(button.dataset.status) });
                        reload();
                    } catch (error) {
                        button.disabled = false;
                        if (window.swal?.fire) await window.swal.fire({ icon: 'error', title: error.message });
                    }
                }).on('click', '.status', async function (event) {
                    event.preventDefault();
                    const button = this;
                    button.disabled = true;
                    try {
                        await send(`${baseUrl}/lists/${encodeURIComponent(button.dataset.id)}/status`, 'PATCH', { status: Number(button.dataset.status) });
                        reload();
                    } catch (error) {
                        button.disabled = false;
                        if (window.swal?.fire) await window.swal.fire({ icon: 'error', title: error.message });
                    }
                });
            };

            const openBulkAssignment = (table, type) => {
                const ids = table.rows({ selected: true }).data().toArray().map((row) => row.id);
                if (!ids.length) return;
                const query = new URLSearchParams();
                ids.forEach((id) => query.append('ids[]', id));
                openFrame(`${baseUrl}/lists/bulk-assignment/${type}?${query.toString()}`);
            };

            $('#load_lists').on('click', function (event) {
                event.preventDefault();
                if (!activeId()) return;
                const button = this;
                button.disabled = true;
                button.textContent = 'LOADING';
                makeTable(activeId());
                $('#lists').one('xhr.dt', () => {
                    button.disabled = false;
                    button.textContent = 'LOAD LISTS';
                });
            });

            window.closeModal = function () {
                if (dialog) dialog.modal('hide');
                reload();
            };
        });
    </script>
@endpush
