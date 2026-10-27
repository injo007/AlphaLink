@extends('layouts.dms-admin')

@section('title', 'Servers Providers')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Servers Providers</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="table-responsive">
                <table id="server-providers" class="table table-sm table-hover table-striped"
                       data-source-url="{{ route('console.infrastructure.server-providers.index', $workspace->id) }}"
                       data-create-url="{{ route('console.infrastructure.server-providers.create', $workspace->id) }}"
                       data-edit-url-template="{{ route('console.infrastructure.server-providers.edit', [$workspace->id, '__PROVIDER__']) }}"
                       data-delete-url-template="{{ route('console.infrastructure.server-providers.destroy', [$workspace->id, '__PROVIDER__']) }}"
                       data-servers-url="{{ route('console.infrastructure.servers.index', $workspace->id) }}"></table>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .bootbox .server-provider-frame {
            border: 0;
            display: block;
            height: min(72vh, 560px);
            min-height: 420px;
            width: 100%;
        }

        @media (max-width: 575.98px) {
            .bootbox .server-provider-frame { height: 68vh; min-height: 360px; }
        }
    </style>
@endpush

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            const tableElement = document.querySelector('#server-providers');
            let dialog = null;
            const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();
            const urlFor = (template, id) => template.replace('__PROVIDER__', encodeURIComponent(String(id)));

            function openProviderFrame(url, title) {
                if (!window.bootbox?.dialog) {
                    window.location.assign(url);
                    return;
                }
                const iframe = `<iframe src="${escapeHtml(url)}" class="server-provider-frame" width="100%" scrolling="auto" frameborder="0" title="${escapeHtml(title)}"></iframe>`;
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
            }

            const table = $('#server-providers').DataTable({
                ajax: tableElement.dataset.sourceUrl,
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
                        action: () => openProviderFrame(tableElement.dataset.createUrl, 'Add server provider'),
                    },
                ],
                columns: [
                    { title: '#', data: 'id' },
                    {
                        title: 'NAME',
                        data: 'name',
                        render: (name, type, row) => {
                            const label = escapeHtml(name);
                            return type === 'display' && row.url
                                ? `<a href="${escapeHtml(row.url)}" target="_blank" rel="noopener noreferrer">${label}</a>`
                                : label;
                        },
                    },
                    { title: 'USERNAME', data: 'username', render: escapeHtml },
                    { title: 'PASSWORD', data: 'password', render: escapeHtml },
                    {
                        title: 'TOTAL SERVERS',
                        data: 'servers_count',
                        render: (count, type, row) => type === 'display'
                            ? `<a href="${escapeHtml(tableElement.dataset.serversUrl)}?provider_id=${encodeURIComponent(String(row.id))}">${escapeHtml(count)}</a>`
                            : count,
                    },
                    { title: 'CREATED AT', data: 'created_at', render: escapeHtml },
                    {
                        title: 'ACTIONS',
                        data: null,
                        orderable: false,
                        searchable: false,
                        render: (data, type, row) => {
                            if (!row.can_manage) {
                                return type === 'display' ? '<span class="text-muted">SHARED</span>' : '';
                            }
                            const update = `<button type="button" data-id="${escapeHtml(row.id)}" class="btn btn-info btn-sm update" title="UPDATE PROVIDER" aria-label="Update provider"><i class="fas fa-pencil-alt"></i></button>&nbsp;`;
                            const remove = `<button type="button" data-id="${escapeHtml(row.id)}" class="btn btn-danger btn-sm delete" title="DELETE PROVIDER" aria-label="Delete provider"><i class="fas fa-trash"></i></button>&nbsp;`;
                            return update + remove;
                        },
                    },
                ],
            });

            table.on('click', '.update', function (event) {
                event.preventDefault();
                openProviderFrame(urlFor(tableElement.dataset.editUrlTemplate, this.dataset.id), 'Edit server provider');
            }).on('click', '.delete', async function (event) {
                event.preventDefault();
                const button = this;
                const providerId = button.dataset.id;
                const confirmation = window.swal?.fire
                    ? await window.swal.fire({
                        icon: 'question',
                        title: 'Delete this provider? Linked servers will remain, but the provider will no longer be available for new assignments.',
                        allowOutsideClick: false,
                        showCloseButton: true,
                        confirmButtonText: 'DELETE',
                        showCancelButton: true,
                    })
                    : { isConfirmed: window.confirm('Delete this provider? Linked servers will remain, but the provider will no longer be available for new assignments.') };
                if (!confirmation.isConfirmed && !confirmation.value) return;

                button.disabled = true;
                try {
                    const response = await fetch(urlFor(tableElement.dataset.deleteUrlTemplate, providerId), {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                    });
                    const body = await response.json();
                    if (!response.ok) throw new Error(body.message || body.data || 'Provider could not be deleted.');
                    if (window.swal?.fire) await window.swal.fire({ icon: 'success', title: body.data });
                    table.ajax.reload(null, false);
                } catch (error) {
                    if (window.swal?.fire) await window.swal.fire({ icon: 'error', title: error.message });
                    else window.alert(error.message);
                } finally {
                    button.disabled = false;
                }
            });

            window.closeModal = function () {
                if (dialog) dialog.modal('hide');
                table.ajax.reload(null, false);
            };
        });
    </script>
@endpush
