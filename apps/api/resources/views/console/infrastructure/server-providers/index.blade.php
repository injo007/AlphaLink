@extends('layouts.admin-shell')

@section('title', 'Server Providers')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-tasks"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">INFRASTRUCTURE VENDORS // HARDWARE PARTNERS</div>
                    <h1 class="command-os-report-hero-title">SERVER PROVIDERS</h1>
                    <p class="command-os-report-hero-desc">Managed server infrastructure vendors, credential vaults, and hardware provisioning endpoints.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">HARDWARE FLEET // ACTIVE</span>
                </div>
            </div>
        </header>

        <!-- 2. Table Card -->
        <div class="command-os-table-card">
            <div class="table-responsive">
                <table id="server-providers" class="table table-sm table-hover table-striped"
                       data-source-url="{{ route('console.infrastructure.server-providers.index', $workspace->id) }}"
                       data-create-url="{{ route('console.infrastructure.server-providers.create', $workspace->id) }}"
                       data-edit-url-template="{{ route('console.infrastructure.server-providers.edit', [$workspace->id, '__PROVIDER__']) }}"
                       data-delete-url-template="{{ route('console.infrastructure.server-providers.destroy', [$workspace->id, '__PROVIDER__']) }}"
                       data-servers-url="{{ route('console.infrastructure.servers.index', $workspace->id) }}"
                       aria-label="Server infrastructure providers"></table>
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
                    className: 'command-modal-dialog',
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
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
                serverSide: false,
                buttons: [
                    { extend: 'pageLength', className: 'btn-command-action' },
                    {
                        text: '<i class="fa fa-plus mr-1"></i> NEW PROVIDER',
                        className: 'btn-command-primary',
                        titleAttr: 'ADD NEW PROVIDER',
                        action: () => openProviderFrame(tableElement.dataset.createUrl, 'Add server provider'),
                    },
                    {
                        text: '<i class="fas fa-sync mr-1"></i> REFRESH',
                        className: 'btn-command-action',
                        titleAttr: 'REFRESH',
                        action: (event, datatable) => datatable.ajax.reload(null, false),
                    },
                ],
                columns: [
                    {
                        title: '#',
                        data: 'id',
                        render: (value, type) => type === 'display' ? `<span class="command-mono-badge">#${value}</span>` : value
                    },
                    {
                        title: 'NAME',
                        data: 'name',
                        render: (name, type, row) => {
                            const label = escapeHtml(name);
                            return type === 'display' && row.url
                                ? `<a href="${escapeHtml(row.url)}" target="_blank" rel="noopener noreferrer" style="font-weight: 700; color: #8b5cf6;">${label} <i class="fas fa-external-link-alt" style="font-size: 0.7rem; margin-left: 2px;"></i></a>`
                                : `<span style="font-weight: 700;">${label}</span>`;
                        },
                    },
                    {
                        title: 'USERNAME',
                        data: 'username',
                        render: (value, type) => type === 'display' ? `<span style="font-family: monospace;">${escapeHtml(value || 'N/A')}</span>` : value
                    },
                    {
                        title: 'PASSWORD',
                        data: 'password',
                        render: (value, type) => type === 'display' ? `<span style="font-family: monospace; letter-spacing: 2px; color: var(--command-os-text-muted, #64748b);">${value ? '&bull;&bull;&bull;&bull;&bull;&bull;' : 'None'}</span>` : value
                    },
                    {
                        title: 'TOTAL SERVERS',
                        data: 'servers_count',
                        render: (count, type, row) => type === 'display'
                            ? `<a href="${escapeHtml(tableElement.dataset.serversUrl)}?provider_id=${encodeURIComponent(String(row.id))}" class="badge-command-pill" style="color: #6366f1; text-decoration: none; font-weight: 700;"><i class="fas fa-server mr-1" style="font-size: 0.75rem;"></i>${escapeHtml(count)}</a>`
                            : count,
                    },
                    {
                        title: 'CREATED AT',
                        data: 'created_at',
                        render: (value, type) => type === 'display' ? `<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">${escapeHtml(value || 'N/A')}</span>` : value
                    },
                    {
                        title: 'ACTIONS',
                        data: null,
                        orderable: false,
                        searchable: false,
                        render: (data, type, row) => {
                            if (!row.can_manage) {
                                return type === 'display' ? '<span class="badge-command-pill" style="opacity: 0.6;">SHARED</span>' : '';
                            }
                            const update = `<button type="button" data-id="${escapeHtml(row.id)}" class="command-action-btn action-info update" title="UPDATE PROVIDER" aria-label="Update provider"><i class="fas fa-pencil-alt"></i></button>`;
                            const remove = `<button type="button" data-id="${escapeHtml(row.id)}" class="command-action-btn action-danger delete" title="DELETE PROVIDER" aria-label="Delete provider"><i class="fas fa-trash"></i></button>`;
                            return `<div class="command-action-btn-group">${update}${remove}</div>`;
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
