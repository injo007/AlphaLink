@extends('layouts.dms-admin')

@section('title', 'Sponsors - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Sponsors</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content">
            <div class="alert alert-info" role="note">
                DMS-style sponsor login opens a provider session in the browser helper. Download and load the helper before using Login.
                <a href="{{ $extensionUrl }}" class="btn btn-sm btn-alt-primary ml-2">Download sponsor helper</a>
                <div class="small mt-2">The reviewed DMS helper requests cookie access across HTTP and HTTPS websites. Review that browser permission scope before installing it.</div>
            </div>
            <div class="table-responsive">
                <table id="sponsors" class="table table-sm table-hover table-striped"
                       data-source-url="{{ route('clean.console.sponsors.index') }}"
                       data-create-url="{{ route('clean.console.sponsors.create') }}"
                       data-bulk-users-url="{{ route('clean.console.sponsors.bulk-users') }}"
                       data-login-url="{{ route('clean.console.sponsors.login', 0) }}"
                       data-extension-id="{{ $extensionId }}"></table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.jQuery(function () {
            const $ = window.jQuery;
            const tableElement = document.querySelector('#sponsors');
            let dialog = null;
            const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();

            const openSponsorFrame = (url) => {
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

            const actionButton = (icon, title, color, id, action, status) => (
                `<button type="button" data-id="${escapeHtml(id)}" data-action="${escapeHtml(action)}" data-status="${escapeHtml(status)}" ` +
                `class="btn ${color} btn-sm ${action}" title="${escapeHtml(title)}" aria-label="${escapeHtml(title)}"><i class="fas ${icon}"></i></button>&nbsp;`
            );

            $('#sponsors').dataTable({
                ajax: tableElement.dataset.sourceUrl,
                serverSide: false,
                order: [[1, 'desc']],
                buttons: [
                    { extend: 'pageLength' },
                    {
                        text: '<i class="fas fa-sync"></i>',
                        titleAttr: 'REFRESH',
                        action: (event, table) => table.ajax.reload(null, false),
                    },
                    {
                        text: '<i class="fa fa-plus"></i>',
                        titleAttr: 'ADD NEW SPONSOR',
                        action: () => openSponsorFrame(tableElement.dataset.createUrl),
                    },
                    {
                        text: '<i class="fas fa-users"></i>',
                        titleAttr: 'ALLOWED USERS',
                        action: (event, table) => {
                            const ids = table.rows({ selected: true }).data().toArray().map((row) => row.id);
                            if (!ids.length) return;
                            openSponsorFrame(`${tableElement.dataset.bulkUsersUrl}?${$.param({ ids })}`);
                        },
                    },
                ],
                rowCallback: (row, data) => {
                    if (!data.is_active) $('td', row).addClass('table-danger');
                },
                select: { style: 'os', selector: 'td:first-child' },
                columns: [
                    { data: null, defaultContent: '', orderable: false, searchable: false, className: 'select-checkbox', title: '' },
                    { title: '#', data: 'id' },
                    { title: 'AFFILIATE ID', data: 'affiliate_id', defaultContent: '' },
                    { title: 'NAME', data: 'name' },
                    { title: 'USERNAME', data: 'username' },
                    { title: 'TYPE', data: 'type' },
                    { title: 'CREATED AT', data: 'created_at' },
                    { title: 'TOTAL OFFERS', data: 'offers_count', render: (value, type, row) => type === 'display' ? `<a href="${@json(route('clean.console.offers.index'))}?sponsor=${escapeHtml(row.id)}">${Number(value || 0)}</a>` : value },
                    {
                        title: 'USERS',
                        data: 'allowed_users',
                        searchable: false,
                        render: (users, type, row) => `<button type="button" data-id="${escapeHtml(row.id)}" class="btn btn-link users" title="ALLOWED USERS" aria-label="Manage allowed users for ${escapeHtml(row.name)}"><i class="fas fa-users"></i> : ${Array.isArray(users) ? users.length : 0}</button>&nbsp;`,
                    },
                    {
                        title: 'URL',
                        data: 'url',
                        render: (url, type) => type === 'display'
                            ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" aria-label="Open sponsor website"><i class="fas fa-link"></i></a>`
                            : url,
                    },
                    {
                        title: 'ACTIONS',
                        data: null,
                        orderable: false,
                        searchable: false,
                        render: (data, type, row) => {
                            const editUrl = @json(route('clean.console.sponsors.index')) + `/${row.id}/edit`;
                            const login = `<button type="button" data-id="${escapeHtml(row.id)}" class="btn btn-info btn-sm login" title="LOGIN SPONSOR" aria-label="Login to ${escapeHtml(row.name)}"><i class="fas fa-door-open"></i></button>&nbsp;`;
                            const update = `<button type="button" data-url="${escapeHtml(editUrl)}" class="btn btn-info btn-sm update" title="UPDATE SPONSOR" aria-label="Update ${escapeHtml(row.name)}"><i class="fas fa-pencil-alt"></i></button>&nbsp;`;
                            const statusButton = actionButton(row.is_active ? 'fa-times' : 'fa-check', row.is_active ? 'DISABLE SPONSOR' : 'ENABLE SPONSOR', row.is_active ? 'btn-warning' : 'btn-success', row.id, 'status', row.is_active ? 0 : 1);
                            return login + update + statusButton;
                        },
                    },
                ],
            }).on('click', '.users', function (event) {
                event.preventDefault();
                const url = @json(route('clean.console.sponsors.index')) + `/${this.dataset.id}/users`;
                openSponsorFrame(url);
            }).on('click', '.update', function (event) {
                event.preventDefault();
                openSponsorFrame(this.dataset.url);
            }).on('click', '.login', async function (event) {
                event.preventDefault();
                const button = this;
                const priorHtml = button.innerHTML;
                const showError = (title) => window.swal?.fire
                    ? window.swal.fire({ icon: 'error', title })
                    : window.alert(title);
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
                try {
                    const extensionId = tableElement.dataset.extensionId;
                    if (!window.App?.pingExtension || !await window.App.pingExtension(extensionId)) {
                        await showError('Sponsor helper is not installed or enabled. Download it above, extract it, and load it from your browser extensions page.');
                        return;
                    }
                    const loginUrl = tableElement.dataset.loginUrl.replace(/\/0\/login$/, `/${encodeURIComponent(button.dataset.id)}/login`);
                    const response = await fetch(loginUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                    });
                    const result = await response.json();
                    if (!response.ok || result.status !== true) {
                        await showError(result.message || 'Sponsor login could not be completed.');
                        return;
                    }
                    const extensionResult = await window.App.commandExtension(extensionId, { type: 'connect', data: result });
                    if (extensionResult?.status !== 'ok') {
                        await showError('The browser helper did not confirm the sponsor session handoff.');
                    }
                } catch (error) {
                    await showError(error.message || 'Sponsor login could not be completed.');
                } finally {
                    button.disabled = false;
                    button.innerHTML = priorHtml;
                }
            }).on('click', '.status', async function (event) {
                event.preventDefault();
                const button = this;
                const priorHtml = button.innerHTML;
                button.disabled = true;
                try {
                    const response = await fetch(@json(route('clean.console.sponsors.index')) + `/${button.dataset.id}/status`, {
                        method: 'PATCH',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ status: Number(button.dataset.status) }),
                    });
                    const body = await response.json();
                    if (!response.ok) throw new Error(body.message || 'Sponsor status could not be changed.');
                    if (window.swal?.fire) await window.swal.fire({ icon: 'success', title: body.data });
                    $('#sponsors').DataTable().ajax.reload(null, false);
                } catch (error) {
                    button.disabled = false;
                    if (window.swal?.fire) await window.swal.fire({ icon: 'error', title: error.message });
                } finally {
                    button.innerHTML = priorHtml;
                }
            });

            window.closeModal = function () {
                if (dialog) dialog.modal('hide');
                $('#sponsors').DataTable().ajax.reload(null, false);
            };
        });
    </script>
@endpush
