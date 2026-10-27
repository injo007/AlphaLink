@extends('layouts.dms-admin')

@section('title', 'Domains Providers')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Domains Providers</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
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
                buttons: [
                    { extend: 'pageLength' },
                    {
                        text: '<i class="fas fa-sync"></i>',
                        titleAttr: 'REFRESH',
                        action: (event, datatable) => datatable.ajax.reload(null, false),
                    },
                    {
                        text: '<i class="fa fa-plus"></i>',
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
                        },
                    },
                ],
                rowCallback: function (row, data) {
                    if (!data.is_active) { $(row).addClass('table-danger'); }
                },
                columns: [
                    { title: '#', data: 'id' },
                    { title: 'NAME', data: 'name', render: escapeHtml },
                    { title: 'TYPE', data: 'type', render: escapeHtml },
                    { title: 'USERNAME', data: 'username', render: escapeHtml },
                    {
                        title: 'TOTAL DOMAINS',
                        data: 'sending_domains_count',
                        render: (count, type) => type === 'display' ? `<a href="${@json(route('clean.console.infrastructure.domains.index'))}">${count}</a>` : count,
                    },
                    { title: 'CREATED AT', data: 'created_at', render: escapeHtml },
                    {
                        title: 'ACTIONS', data: null, orderable: false, searchable: false, width: '20%',
                        render: function (data, type, row) {
                            const id = escapeHtml(row.id);
                            const update = `<button type="button" data-id="${id}" class="btn btn-info btn-sm update" title="UPDATE" aria-label="Update provider"><i class="fas fa-pencil-alt"></i></button>&nbsp;`;
                            const status = `<button type="button" data-id="${id}" data-action="${row.is_active ? 0 : 1}" class="btn ${row.is_active ? 'btn-warning' : 'btn-success'} btn-sm status" title="${row.is_active ? 'DISABLE' : 'ENABLE'} PROVIDER" aria-label="${row.is_active ? 'Disable' : 'Enable'} provider"><i class="fas ${row.is_active ? 'fa-times' : 'fa-check'}"></i></button>&nbsp;`;
                            return update + status;
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
                button.innerHTML = window.spinner();
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
