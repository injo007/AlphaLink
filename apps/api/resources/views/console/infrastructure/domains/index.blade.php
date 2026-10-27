@extends('layouts.dms-admin')

@section('title', 'Domains - DMS')

@push('styles')
    <style>
        .d_status { width: 100%; padding: 0 12px; border: transparent; outline: none; background-color: inherit; }
    </style>
@endpush

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Domains</h3>
            <div class="block-options">
                <div class="custom-control custom-switch custom-control-info custom-control-inline">
                    <input type="checkbox" class="custom-control-input" id="only_our" checked>
                    <label id="label_auto" class="custom-control-label" for="only_our">ONLY OUR</label>
                </div>
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
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
                const text = value ? 'YES' : 'NO';
                const displayValue = listed ? !value : value;
                const color = displayValue ? 'badge-success' : 'badge-danger';
                return `<span class="badge ${color} p-1 mr-1" title="${escapeHtml(title)}">${text}</span>`;
            };
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            const datatable = $('#domains').DataTable({
                ajax: {
                    url: endpoint,
                    data: function (data) {
                        data.only_our = $('#only_our').is(':checked');
                    }
                },
                order: [[8, 'asc']],
                displayLength: 50,
                select: {style: 'os', selector: 'td:first-child'},
                buttons: [
                    {extend: 'pageLength'},
                    {
                        text: '<i class="fas fa-sync"></i>',
                        titleAttr: 'REFRESH',
                        action: function (event, table) { table.ajax.reload(null, false); }
                    },
                    {
                        text: '<i class="fa fa-plus"></i>',
                        titleAttr: 'ADD NEW DOMAINS',
                        action: function () {
                            const link = @json(route('console.infrastructure.domains.create', $workspace->id));
                            const iframe = `<iframe src="${link}" width="100%" scrolling="no" frameborder="0"></iframe>`;
                            window.domainDialog = bootbox.dialog({
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
                        text: '<i class="fas fa-trash"></i>',
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
                ],
                rowCallback: function (row, data) {
                    if (!data.is_active) { $('td', row).eq(1).addClass('text-danger'); }
                    if (data.expire_at && moment(data.expire_at).diff(moment(), 'days') < 15) {
                        $('td', row).eq(8).addClass('expireSoon');
                    }
                },
                columns: [
                    {data: null, defaultContent: '', orderable: false, searchable: false, className: 'select-checkbox'},
                    {title: '#', data: 'id', render: (data) => `<span title="${escapeHtml(data)}">${escapeHtml(data)}</span>`},
                    {title: 'DID', data: 'domain_id', defaultContent: ''},
                    {title: 'account', data: 'provider', render: (data) => data ? `${escapeHtml(data.name)} (${escapeHtml(data.type)})` : ''},
                    {
                        title: 'dns', data: 'dns', render: (data) => {
                            const dns = String(data ?? '');
                            if (!/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i.test(dns)) { return escapeHtml(dns); }
                            return `<a href="//${escapeHtml(dns)}" target="_blank" rel="noopener noreferrer">${escapeHtml(dns)}</a>`;
                        }
                    },
                    {
                        title: 'status', data: 'status', render: function (data, type, row) {
                            if (type !== 'display') { return data; }
                            const select = $('<select class="d_status" aria-label="Domain status"></select>').attr('data-id', row.id);
                            $.each(['new', 'free', 'used', 'redirect'], function (index, value) {
                                const option = $('<option></option>').text(value.toUpperCase()).val(value);
                                if (data === value) { option.attr('selected', 'selected'); }
                                select.append(option);
                            });
                            return select.prop('outerHTML');
                        }
                    },
                    {
                        title: 'info', data: null, render: function (data, type, row) {
                            return formatBoolBadge(row.is_our, 'IS OUR')
                                + formatBoolBadge(row.have_who_is, 'HAVE WHO IS')
                                + formatBoolBadge(row.have_renew, 'HAVE RENEW');
                        }
                    },
                    {title: 'EXPIRE AT', data: 'expire_at', defaultContent: ''},
                    {title: 'DAYS LEFT', data: 'expire_at', render: (data) => moment(data).diff(moment(), 'days')},
                    {
                        title: 'is listed', data: 'history', render: function (history) {
                            if (!history) { return 'NA'; }
                            return formatBoolBadge(history.is_listed_spamhaus, 'SPAMHAUS', true)
                                + formatBoolBadge(history.is_listed_hostkarma, 'HOSTKARMA', true);
                        }
                    },
                    {
                        title: 'History', data: 'histories_count', render: function (count, type, row) {
                            const historyUrl = @json(route('console.infrastructure.domains.history', [$workspace->id, '__DOMAIN__']));
                            return `<button data-id="${escapeHtml(row.id)}" data-url="${historyUrl.replace('__DOMAIN__', encodeURIComponent(row.id))}" class="btn btn-link btn-sm history" title="HISTORY">${Number(count) || 0}</button>`;
                        }
                    },
                    {
                        title: 'ACTIONS', data: null, orderable: false, searchable: false,
                        render: function (data, type, row) {
                            const id = escapeHtml(row.id);
                            const recordsUrl = @json(route('console.infrastructure.domains.records', [$workspace->id, '__DOMAIN__']));
                            const records = `<button data-url="${recordsUrl.replace('__DOMAIN__', encodeURIComponent(id))}" class="btn btn-primary btn-sm records" title="RECORDS" aria-label="Edit DNS records for ${escapeHtml(row.dns)}"><i class="fas fa-cogs" aria-hidden="true"></i></button>&nbsp;`;
                            const refresh = `<button data-id="${id}" class="btn btn-info btn-sm update" title="UPDATE"><i class="fas fa-pencil-alt"></i></button>&nbsp;`;
                            const active = Boolean(row.is_active);
                            const status = `<button data-id="${id}" data-action="${active ? 0 : 1}" class="btn ${active ? 'btn-warning' : 'btn-success'} btn-sm active-status" title="${active ? 'DISABLE' : 'ENABLE'} DOMAIN"><i class="fas ${active ? 'fa-times' : 'fa-check'}"></i></button>&nbsp;`;
                            const remove = `<button data-id="${id}" class="btn btn-danger btn-sm destroy" title="DELETE DOMAIN"><i class="fas fa-trash"></i></button>`;
                            return records + refresh + status + remove;
                        }
                    }
                ]
            });

            // The DMS reference reloads on the checkbox click. Keep the same
            // interaction so the visible filter and the table stay in sync.
            $('#only_our').on('click', function () { datatable.ajax.reload(); });

            $('#domains').on('click', '.history', function (event) {
                event.preventDefault();
                const iframe = `<iframe src="${$(this).data('url')}" width="100%" scrolling="no" frameborder="0"></iframe>`;
                bootbox.dialog({scrollable: true, closeButton: true, size: 'xl', message: iframe, onEscape: true, centerVertical: true})
                    .on('shown.bs.modal', function () { if (typeof iFrameResize === 'function') { iFrameResize({}, 'iframe'); } })
                    .on('hidden.bs.modal', function () { $('iframe').remove(); });
            }).on('click', '.records', function () {
                const url = $(this).data('url');
                window.open(url, '_blank', 'noopener');
            }).on('click', '.update', async function () {
                const button = $(this);
                const id = button.data('id');
                const original = button.html();
                button.html(spinner()).prop('disabled', true);
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
                button.html(spinner()).prop('disabled', true);
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
            }).on('click', '.destroy', async function () {
                const button = $(this);
                const id = button.data('id');
                const original = button.html();
                button.html(spinner()).prop('disabled', true);
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
                    button.html(original).prop('disabled', false);
                }
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

            window.closeModal = function () {
                if (window.domainDialog) { window.domainDialog.modal('hide'); }
                datatable.ajax.reload(null, false);
            };
        });
    </script>
@endpush
