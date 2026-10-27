@extends('layouts.dms-admin')

@section('title', 'Cloud Providers')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Cloud Providers</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="table-responsive">
                <table id="cloud-providers" class="table table-sm table-hover table-striped">
                    <thead>
                        <tr><th>#</th><th>type</th><th>name</th><th>created at</th><th>Actions</th></tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            const table = $('#cloud-providers').DataTable({
                ajax: @json(route('clean.console.infrastructure.cloud-providers.index')),
                serverSide: true,
                buttons: [
                    { extend: 'pageLength' },
                    {
                        text: '<i class="fas fa-sync"></i>',
                        titleAttr: 'REFRESH',
                        action: (event, datatable) => datatable.ajax.reload(null, false),
                    },
                    {
                        text: '<i class="fa fa-plus"></i>',
                        titleAttr: 'ADD NEW CLOUD',
                        action: () => openCloudProviderForm(@json(route('clean.console.infrastructure.cloud-providers.create')), 'Add cloud provider'),
                    },
                ],
                columns: [
                    { title: '#', data: 'id' },
                    { title: 'type', data: 'type', render: $.fn.dataTable.render.text() },
                    { title: 'name', data: 'name', render: $.fn.dataTable.render.text() },
                    { title: 'created at', data: 'created_at', render: $.fn.dataTable.render.text() },
                    {
                        title: 'ACTIONS', data: null, orderable: false, searchable: false,
                        render: function (data, type, row) {
                            const id = $('<div>').text(String(row.id)).html();
                            return `<button type="button" data-id="${id}" class="btn btn-info btn-sm update" title="UPDATE PROVIDER" aria-label="Update provider"><i class="fas fa-pencil-alt"></i></button>&nbsp;` +
                                `<button type="button" data-id="${id}" class="btn btn-danger btn-sm destroy" title="DELETE PROVIDER" aria-label="Delete provider"><i class="fas fa-trash"></i></button>`;
                        },
                    },
                ],
            });

            function openCloudProviderForm(url, title) {
                if (!window.bootbox?.dialog) {
                    window.location.assign(url);
                    return;
                }

                const escapedUrl = $('<div>').text(url).html();
                const iframe = `<iframe src="${escapedUrl}" width="100%" scrolling="no" frameborder="0" title="${title}"></iframe>`;
                window.cloudProviderDialog = window.bootbox.dialog({
                    scrollable: true,
                    closeButton: true,
                    size: 'lg',
                    message: iframe,
                    onEscape: true,
                    centerVertical: true,
                }).on('shown.bs.modal', function () {
                    if (typeof window.iFrameResize === 'function') { window.iFrameResize({}, 'iframe'); }
                }).on('hidden.bs.modal', function () {
                    $('iframe').remove();
                });
            }

            $('#cloud-providers').on('click', '.update', function () {
                const base = @json(route('clean.console.infrastructure.cloud-providers.index'));
                openCloudProviderForm(`${base}/${encodeURIComponent(this.dataset.id)}/edit`, 'Edit cloud provider');
            }).on('click', '.destroy', async function () {
                const button = this;
                const confirmation = await window.swal.fire({
                    icon: 'question',
                    title: 'Are you sure ?',
                    allowOutsideClick: false,
                    showCloseButton: true,
                    confirmButtonText: 'DELETE',
                    showCancelButton: true,
                });

                if (!confirmation.isConfirmed && !confirmation.value) { return; }

                const original = button.innerHTML;
                button.disabled = true;
                button.innerHTML = window.spinner();

                try {
                    const base = @json(route('clean.console.infrastructure.cloud-providers.index'));
                    const response = await fetch(`${base}/${encodeURIComponent(button.dataset.id)}`, {
                        method: 'DELETE',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                    });
                    const body = await response.json();
                    if (!response.ok) { throw new Error(body.message || 'The cloud provider could not be deleted.'); }
                    await window.App.swalPromise({ icon: 'success', title: body.data, confirmButtonText: 'close' });
                    table.ajax.reload(null, false);
                } catch (error) {
                    window.App.processAjaxFail({ error: error.message });
                } finally {
                    button.innerHTML = original;
                    button.disabled = false;
                }
            });

            window.closeCloudProviderDialog = async function (message) {
                if (message) {
                    await window.App.swalPromise({ icon: 'success', title: message, confirmButtonText: 'close' });
                }
                if (window.cloudProviderDialog) { window.cloudProviderDialog.modal('hide'); }
                table.ajax.reload(null, false);
            };
        });
    </script>
@endpush
