window.jQuery(function () {
    const tableElement = document.getElementById('servers');
    if (!tableElement || !window.jQuery || !window.jQuery.fn.DataTable) return;

    const $ = window.jQuery;
    const serverUrl = (template, id) => template.replace('__SERVER__', encodeURIComponent(id));
    const showBadge = (value) => {
        if (value == null) return '<span class="badge badge-secondary p-1" title="UNKNOWN">N/A</span>';
        const text = value ? 'YES' : 'NO';
        const color = value ? 'success' : 'danger';
        return `<span class="badge badge-${color} p-1" title="${text}">${text}</span>`;
    };
    const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();
    const button = (icon, title, color, action, id, value) => (
        `<button type="button" data-id="${escapeHtml(id)}"${value == null ? '' : ` data-action="${value}"`} ` +
        `class="btn ${color} btn-sm ${action}" title="${escapeHtml(title)}" aria-label="${escapeHtml(title)}"><i class="fas ${icon}"></i></button>&nbsp;`
    );
    const consoleAction = (row) => (
        `<a href="${escapeHtml(serverUrl(tableElement.dataset.consoleUrlTemplate, row.id))}" target="_self" role="button" ` +
        `data-id="${escapeHtml(row.id)}" class="btn btn-alt-dark btn-sm console" title="CONSOLE" ` +
        `aria-label="CONSOLE"><i class="fas fa-terminal"></i></a>&nbsp;`
    );
    const historyAction = (row) => (
        `<a href="${escapeHtml(serverUrl(tableElement.dataset.historyUrlTemplate, row.id))}" target="_blank" rel="noopener" ` +
        `class="btn btn-info btn-sm history" title="SERVER HISTORY" aria-label="SERVER HISTORY"><i class="fas fa-history"></i></a>&nbsp;`
    );
    const ipsAction = (row, count) => row.can_manage
        ? `<a href="${escapeHtml(serverUrl(tableElement.dataset.ipsUrlTemplate, row.id))}" target="_self" role="button" data-id="${escapeHtml(row.id)}" class="btn btn-link btn-sm ips" title="TOTAL IPS" aria-label="TOTAL IPS ${escapeHtml(count)}">${escapeHtml(count)}</a>&nbsp;`
        : `<span class="text-muted" title="TOTAL IPS">${escapeHtml(count)}</span>`;
    const usersAction = (row, users) => (
        `<a href="${escapeHtml(serverUrl(tableElement.dataset.usersUrlTemplate, row.id))}" target="_self" role="button" ` +
        `data-id="${escapeHtml(row.id)}" class="btn btn-link btn-sm users" title="ALLOWED USERS : ${users.length}" ` +
        `aria-label="ALLOWED USERS : ${users.length}"><i class="fas fa-users"></i> : ${users.length}</a>&nbsp;`
    );
    const editAction = (row) => (
        `<a href="${escapeHtml(serverUrl(tableElement.dataset.editUrlTemplate, row.id))}" target="_self" role="button" ` +
        `data-id="${escapeHtml(row.id)}" class="btn btn-info btn-sm edit" title="UPDATE SERVER" ` +
        `aria-label="UPDATE SERVER"><i class="fas fa-pencil-alt"></i></a>&nbsp;`
    );
    const inactiveButton = (icon, title, color = 'btn-info') => (
        `<button type="button" class="btn ${color} btn-sm" title="${title}" aria-label="${title}" disabled><i class="fas ${icon}"></i></button>&nbsp;`
    );
    const returned = $('#returned');
    const canManage = tableElement.dataset.canManage === 'true';
    const canReturn = tableElement.dataset.canReturn === 'true';
    const dataTable = $('#servers').DataTable({
        ajax: {
            url: tableElement.dataset.sourceUrl,
            data: (query) => {
                query.returned = returned.is(':checked');
            },
        },
        processing: true,
        displayLength: 50,
        autoWidth: false,
        order: [[6, 'asc'], [1, 'desc']],
        select: { style: 'os', selector: 'td:first-child' },
        buttons: [
            { extend: 'pageLength' },
            {
                text: '<i class="far fa-square"></i>',
                titleAttr: 'SELECT NONE',
                action: (event, dt) => dt.rows({ page: 'current' }).deselect(),
            },
            {
                text: '<i class="far fa-check-square"></i>',
                titleAttr: 'SELECT ALL',
                action: (event, dt) => dt.rows({ page: 'current' }).select(),
            },
            {
                text: '<i class="fa fa-plus"></i>',
                titleAttr: 'ADD NEW SERVERS',
                action: () => openFrame(
                    tableElement.dataset.createUrl,
                    'lg',
                    'Add Servers',
                    () => dataTable.ajax.reload(null, false),
                ),
                enabled: canManage && Boolean(tableElement.dataset.createUrl),
            },
            {
                text: '<i class="fa fa-trash"></i>',
                titleAttr: 'DELETE SERVERS',
                action: (event, dt) => returnServers(dt.rows({ selected: true }).data().toArray().map((row) => row.id), true),
                enabled: false,
            },
            {
                text: '<i class="fas fa-users"></i>',
                titleAttr: 'ALLOWED USERS',
                action: (event, dt) => openBulkUsers(dt.rows({ selected: true }).data().toArray().map((row) => row.id)),
                enabled: false,
            },
            {
                text: '<i class="fas fa-magic"></i>',
                titleAttr: 'INSTALL SERVER',
                action: () => window.open(tableElement.dataset.installUrl, '_blank', 'noopener'),
                enabled: Boolean(tableElement.dataset.installUrl),
            },
            {
                text: '<i class="fas fa-hat-wizard"></i>',
                titleAttr: 'BULK INSTALL SERVERS',
                action: () => window.open(tableElement.dataset.bulkInstallUrl, '_blank', 'noopener'),
                enabled: Boolean(tableElement.dataset.bulkInstallUrl),
            },
            {
                text: '<i class="fa fa-sync"></i>',
                titleAttr: 'REFRESH',
                action: (event, dt) => dt.ajax.reload(null, false),
            },
        ],
        columns: [
            { targets: 0, data: null, defaultContent: '', orderable: false, searchable: false, className: 'select-checkbox', width: '3%' },
            {
                title: 'ID',
                data: 'id',
                width: '6%',
                render: (value, type) => type === 'display'
                    ? `<span title="${escapeHtml(value)}">${escapeHtml(String(value).slice(0, 6))}…</span>`
                    : value,
            },
            {
                title: 'PROVIDER',
                data: 'provider.name',
                width: '8%',
                render: (value, type) => type === 'display' ? escapeHtml(value || 'NA') : value,
            },
            { title: 'NAME', type: 'natural', data: null, width: '10%', render: (data, type, row) => type === 'display' ? escapeHtml(`${row.name}${row.id.slice(0, 4)}`) : `${row.name}${row.id}` },
            {
                title: 'MAIN IP',
                data: 'ip',
                width: '10%',
                render: (value, type) => type === 'display'
                    ? `<span class="text-primary">${escapeHtml(value || '')}</span>`
                    : value,
            },
            {
                title: 'COUNTRY',
                data: 'country',
                width: '8%',
                render: (value) => value ? escapeHtml(String(value).toUpperCase()) : 'N/A',
            },
            { title: 'EXPIRE AT', data: 'expire_at', width: '9%' },
            { title: 'INSTALLED', data: 'is_installed', width: '8%', render: showBadge },
            {
                title: 'STATUS',
                data: 'history',
                width: '7%',
                render: (history) => {
                    if (!history) return '<span class="badge badge-secondary p-1" title="NA">NA</span>';
                    const down = history.is_ssh_down === true;
                    const title = down ? 'DOWN' : 'UP';
                    return `<span class="badge badge-${down ? 'danger' : 'success'} p-1" title="${title}">${title}</span>`;
                },
            },
            {
                title: 'TOTAL IPS',
                data: 'ips_count',
                searchable: false,
                width: '8%',
                render: (value, type, row) => value == null
                    ? '<span class="text-muted">N/A</span>'
                    : ipsAction(row, value),
            },
            {
                title: 'USERS',
                data: 'allowed_users',
                width: '6%',
                render: (users, type, row) => {
                    if (!Array.isArray(users)) return '<span class="text-muted">N/A</span>';
                    if (row.deleted_at) return '';
                    return row.can_manage
                        ? usersAction(row, users)
                        : `<span class="text-muted" title="ALLOWED USERS"><i class="fas fa-users"></i> : ${users.length}</span>`;
                },
            },
            {
                title: 'ACTIONS',
                data: null,
                orderable: false,
                searchable: false,
                width: '17%',
                render: (data, type, row) => {
                    if (row.deleted_at) return row.can_restore
                        ? button('fa-trash-restore', 'RESTORE', 'btn-secondary', 'restore', row.id)
                        : '';
                    const locked = row.is_locked === true;
                    const status = locked || !row.can_operate ? '' : button(
                        row.is_active ? 'fa-times' : 'fa-check',
                        row.is_active ? 'DISABLE SERVER' : 'ENABLE SERVER',
                        row.is_active ? 'btn-warning' : 'btn-success',
                        'status', row.id, row.is_active ? 0 : 1,
                    );
                    const returnedServer = !row.can_return ? '' : button('fa-trash', 'DELETE SERVER', 'btn-danger', 'return', row.id);
                    const lockButton = row.can_manage
                        ? button(locked ? 'fa-lock' : 'fa-lock-open', locked ? 'UNLOCK SERVER' : 'LOCK SERVER', locked ? 'btn-warning' : 'btn-success', 'lock', row.id, locked ? 0 : 1)
                        : '';
                    return [
                        historyAction(row),
                        row.can_console
                            ? consoleAction(row)
                            : inactiveButton('fa-terminal', 'CONSOLE', 'btn-alt-dark'),
                        row.can_manage ? editAction(row) : inactiveButton('fa-pencil-alt', 'UPDATE SERVER', 'btn-info'),
                        lockButton,
                        status,
                        returnedServer,
                    ].join('');
                },
            },
        ],
        rowCallback: (row, data) => {
            if (data.deleted_at) {
                $(row).addClass('table-danger');
            } else if (data.expire_at && window.moment && window.moment(data.expire_at).diff(window.moment(), 'days') < 5) {
                $('td', row).eq(6).addClass('expireSoon');
            }
        },
    });

    const selectedIds = () => dataTable.rows({ selected: true }).data().toArray().map((row) => row.id);
    const refreshSelectionButtons = () => {
        const hasSelection = selectedIds().length > 0;
        const activeRows = !returned.is(':checked');
        dataTable.button(4).enable(canReturn && activeRows && hasSelection);
        dataTable.button(5).enable(canManage && activeRows && hasSelection);
        dataTable.button(3).enable(canManage && Boolean(tableElement.dataset.createUrl));
    };
    const notify = (icon, title) => {
        const sweetAlert = window.Swal || window.swal;
        if (sweetAlert?.fire) return sweetAlert.fire({ icon, title });
        if (icon === 'success') window.alert(title);
        return Promise.resolve({ isConfirmed: icon === 'success' });
    };
    const ask = (confirmButtonText, title = 'Are you sure ?') => {
        const sweetAlert = window.Swal || window.swal;
        if (sweetAlert?.fire) {
            return sweetAlert.fire({
                icon: 'question',
                title,
                allowOutsideClick: false,
                showCloseButton: true,
                confirmButtonText,
                showCancelButton: true,
            }).then((result) => result.isConfirmed || result.value === true);
        }
        return Promise.resolve(window.confirm(title));
    };
    const patchAction = (url, status, successText) => $.ajax({
        url,
        type: 'PATCH',
        dataType: 'json',
        data: status == null ? {} : { status },
    }).done((response) => notify('success', response.data || successText).then(() => dataTable.ajax.reload(null, false)))
        .fail((response) => notify('error', response.responseJSON?.data || response.responseJSON?.message || 'Action failed.'));

    function returnServers(ids, bulk = false) {
        if (!ids.length) return;
        ask(bulk ? 'RETURN SERVERS' : 'RETURN SERVER').then((confirmed) => {
            if (!confirmed) return;
            $.ajax({
                url: tableElement.dataset.returnUrl,
                type: 'DELETE',
                dataType: 'json',
                data: { ids },
            }).done((response) => notify('success', response.data).then(() => dataTable.ajax.reload(null, false)))
                .fail((response) => notify('error', response.responseJSON?.data || response.responseJSON?.message || 'Failed'));
        });
    }

    function openFrame(url, size, title, afterClose = () => {}) {
        const destination = new URL(url, window.location.href).href;
        const existingFrame = [...document.querySelectorAll('.bootbox iframe.server-action-frame, .server-frame-dialog iframe.server-action-frame')]
            .some((frame) => frame.src === destination && frame.getClientRects().length > 0);
        if (existingFrame) return;

        if (!window.bootbox?.dialog) {
            const dialog = document.createElement('dialog');
            if (typeof dialog.showModal !== 'function') {
                window.location.assign(destination);
                return;
            }
            const opener = document.activeElement;
            const modalSizeClass = size === 'xl' ? 'modal-xl' : 'modal-lg';
            dialog.className = 'server-frame-dialog';
            dialog.setAttribute('aria-label', title);
            dialog.innerHTML = `
                <div class="modal-dialog ${modalSizeClass} modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">${escapeHtml(title)}</h5>
                            <button type="button" class="close" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        </div>
                        <div class="modal-body"><iframe class="server-action-frame" src="${escapeHtml(url)}" scrolling="auto" frameborder="0" title="${escapeHtml(title)}"></iframe></div>
                    </div>
                </div>`;
            const close = () => dialog.close();
            dialog.querySelector('.close').addEventListener('click', close);
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) close();
            });
            dialog.addEventListener('close', () => {
                dialog.remove();
                if (opener instanceof HTMLElement && opener.isConnected) opener.focus();
                afterClose();
            }, { once: true });
            document.body.append(dialog);
            dialog.showModal();
            return;
        }

        const iframe = `<iframe class="server-action-frame" src="${escapeHtml(url)}" width="100%" scrolling="auto" frameborder="0" title="${escapeHtml(title)}"></iframe>`;
        window.bootbox.dialog({
            scrollable: true,
            closeButton: true,
            size,
            message: iframe,
            onEscape: true,
            centerVertical: true,
        }).on('shown.bs.modal', function () {
            if (window.iFrameResize) window.iFrameResize({}, 'iframe');
        }).on('hidden.bs.modal', function () {
            $(this).find('iframe.server-action-frame').remove();
            afterClose();
        });
    }

    function openBulkUsers(ids) {
        if (!ids.length) return;
        const url = new URL(tableElement.dataset.bulkUsersUrl, window.location.href);
        ids.forEach((id) => url.searchParams.append('ids[]', id));
        openFrame(url.toString(), 'lg', 'Allowed Users');
    }

    $('#servers').on('select.dt deselect.dt draw.dt', refreshSelectionButtons)
        .on('click', '.edit', function (event) {
            event.preventDefault();
            openFrame(serverUrl(tableElement.dataset.editUrlTemplate, $(this).data('id')), 'lg', 'Edit Server');
        })
        .on('click', '.users', function (event) {
            event.preventDefault();
            openFrame(serverUrl(tableElement.dataset.usersUrlTemplate, $(this).data('id')), 'lg', 'Allowed Users');
        })
        .on('click', '.ips', function (event) {
            event.preventDefault();
            openFrame(serverUrl(tableElement.dataset.ipsUrlTemplate, $(this).data('id')), 'xl', 'Server IP & MTA');
        })
        .on('click', '.console', function (event) {
            event.preventDefault();
            openFrame(this.href, 'lg', 'Console');
        })
        .on('click', '.return', function (event) {
            event.preventDefault();
            returnServers([$(this).data('id')]);
        })
        .on('click', '.restore', function (event) {
            event.preventDefault();
            const id = $(this).data('id');
            ask('RESTORE').then((confirmed) => {
                if (confirmed) patchAction(serverUrl(tableElement.dataset.restoreUrlTemplate, id), null, 'Server restored');
            });
        })
        .on('click', '.lock', function (event) {
            event.preventDefault();
            const id = $(this).data('id');
            patchAction(serverUrl(tableElement.dataset.lockUrlTemplate, id), $(this).data('action'), 'Changed successfully');
        })
        .on('click', '.status', function (event) {
            event.preventDefault();
            const id = $(this).data('id');
            patchAction(serverUrl(tableElement.dataset.statusUrlTemplate, id), $(this).data('action'), 'Changed successfully');
        });

    window.closeModal = async () => {
        const editor = document.querySelector('.bootbox iframe')?.contentWindow?.AlphaLinkServerEdit;
        if (editor?.isDirty()) {
            const result = await window.App.swalPromise({
                icon: 'warning',
                title: 'You have unsaved changes. Discard them?',
                showCancelButton: true,
                cancelButtonText: 'Keep editing',
                confirmButtonText: 'Discard',
                focusCancel: true,
            });
            if (!result.isConfirmed) return;
            editor.allowClose();
        }
        window.bootbox?.hideAll();
    };
    window.openServerRegistration = () => openFrame(
        tableElement.dataset.createUrl,
        'lg',
        'Add Servers',
        () => dataTable.ajax.reload(null, false),
    );
    window.AlphaLinkServers = { reload: () => dataTable.ajax.reload(null, false) };

    returned.on('change', () => {
        dataTable.rows().deselect();
        refreshSelectionButtons();
        dataTable.ajax.reload();
    });
    refreshSelectionButtons();
});
