@extends('layouts.admin-shell')

@section('title', 'Cloud Providers')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-cloud-sun"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">CLOUD INFRASTRUCTURE // API INTEGRATIONS</div>
                    <h1 class="command-os-report-hero-title">CLOUD PROVIDERS</h1>
                    <p class="command-os-report-hero-desc">API access tokens and integrations for external cloud computing and virtual server providers.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">CLOUD VENDORS</span>
                </div>
            </div>
        </header>

        <!-- 2. Table Card -->
        <div class="command-os-table-card">
            <div class="table-responsive">
                <table id="cloud-providers" class="table table-sm table-hover table-striped" aria-label="Cloud infrastructure providers">
                    <thead>
                        <tr><th>#</th><th>TYPE</th><th>NAME</th><th>CREATED AT</th><th>ACTIONS</th></tr>
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
                dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
                serverSide: true,
                buttons: [
                    { extend: 'pageLength', className: 'btn-command-action' },
                    {
                        text: '<i class="fa fa-plus mr-1"></i> NEW CLOUD PROVIDER',
                        className: 'btn-command-primary',
                        titleAttr: 'ADD NEW CLOUD',
                        action: () => openCloudProviderForm(@json(route('clean.console.infrastructure.cloud-providers.create')), 'Add cloud provider'),
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
                        title: '#', data: 'id',
                        render: (value, type) => type === 'display' ? `<span class="command-mono-badge">#${value}</span>` : value
                    },
                    {
                        title: 'TYPE', data: 'type',
                        render: (value) => `<span class="badge-command-pill" style="font-weight: 700; color: #8b5cf6;">${value ? String(value).toUpperCase() : 'N/A'}</span>`
                    },
                    {
                        title: 'NAME', data: 'name',
                        render: (value) => `<span style="font-weight: 700;">${value || ''}</span>`
                    },
                    {
                        title: 'CREATED AT', data: 'created_at',
                        render: (value, type) => type === 'display' ? `<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">${value || ''}</span>` : value
                    },
                    {
                        title: 'ACTIONS', data: null, orderable: false, searchable: false,
                        render: function (data, type, row) {
                            const id = $('<div>').text(String(row.id)).html();
                            return `<div class="command-action-btn-group">` +
                                `<button type="button" data-id="${id}" class="command-action-btn action-info update" title="UPDATE PROVIDER" aria-label="Update provider"><i class="fas fa-pencil-alt"></i></button>` +
                                `<button type="button" data-id="${id}" class="command-action-btn action-danger destroy" title="DELETE PROVIDER" aria-label="Delete provider"><i class="fas fa-trash"></i></button>` +
                                `</div>`;
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
                    className: 'command-modal-dialog',
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
                    title: 'Delete this cloud provider?',
                    allowOutsideClick: false,
                    showCloseButton: true,
                    confirmButtonText: 'DELETE',
                    showCancelButton: true,
                });

                if (!confirmation.isConfirmed && !confirmation.value) { return; }

                button.disabled = true;

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
