window.jQuery(function () {
    const tableElement = document.getElementById('servers');
    if (!tableElement || !window.jQuery || !window.jQuery.fn.DataTable) return;

    const $ = window.jQuery;
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
                action: () => window.openServerRegistration(),
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
            { text: '<i class="fas fa-magic"></i>', titleAttr: 'INSTALL SERVER', enabled: false },
            { text: '<i class="fas fa-tools"></i>', titleAttr: 'BULK INSTALL SERVERS', enabled: false },
            { text: '<i class="fas fa-recycle"></i>', titleAttr: 'RECONFIG VMTA', enabled: false },
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
                render: (value) => value == null
                    ? '<span class="text-muted">N/A</span>'
                    : inactiveButton('fa-server', 'TOTAL IPS', 'btn-link').replace('<i class="fas fa-server"></i>', `${value}`),
            },
            {
                title: 'USERS',
                data: 'allowed_users',
                width: '6%',
                render: (users, type, row) => {
                    if (!Array.isArray(users)) return '<span class="text-muted">N/A</span>';
                    if (row.deleted_at) return '';
                    const label = `<i class="fas fa-users"></i> : ${users.length}`;
                    return row.can_manage
                        ? button('fa-users', `ALLOWED USERS : ${users.length}`, 'btn-link', 'users', row.id).replace(/<i class="fas fa-users"><\/i>/, label)
                        : `<span class="text-muted" title="ALLOWED USERS">${label}</span>`;
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
                        button('fa-history', 'SERVER HISTORY', 'btn-info', 'history', row.id),
                        inactiveButton('fa-terminal', 'CONSOLE', 'btn-alt-dark'),
                        inactiveButton('fa-pencil-alt', 'UPDATE SERVER', 'btn-info'),
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
    const serverUrl = (template, id) => template.replace('__SERVER__', encodeURIComponent(id));
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

    function openFrame(url, size, title) {
        const iframe = `<iframe src="${escapeHtml(url)}" width="100%" scrolling="no" frameborder="0" title="${escapeHtml(title)}"></iframe>`;
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
            $('iframe').remove();
        });
    }

    function openBulkUsers(ids) {
        if (!ids.length) return;
        const url = new URL(tableElement.dataset.bulkUsersUrl, window.location.href);
        ids.forEach((id) => url.searchParams.append('ids[]', id));
        openFrame(url.toString(), 'lg', 'Allowed Users');
    }

    $('#servers').on('select.dt deselect.dt draw.dt', refreshSelectionButtons)
        .on('click', '.users', function (event) {
            event.preventDefault();
            openFrame(serverUrl(tableElement.dataset.usersUrlTemplate, $(this).data('id')), 'lg', 'Allowed Users');
        })
        .on('click', '.history', function (event) {
            event.preventDefault();
            const url = serverUrl(tableElement.dataset.historyUrlTemplate, $(this).data('id'));
            window.open(url, '_blank', 'noopener');
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

    window.closeModal = () => window.bootbox?.hideAll();
    window.openServerRegistration = () => {
        const iframe = `<iframe src="${escapeHtml(tableElement.dataset.createUrl)}" width="100%" scrolling="no" frameborder="0" title="Add Servers"></iframe>`;
        window.bootbox.dialog({
            scrollable: true,
            closeButton: true,
            size: 'lg',
            message: iframe,
            onEscape: true,
            centerVertical: true,
        }).on('shown.bs.modal', () => {
            if (window.iFrameResize) window.iFrameResize({}, 'iframe');
        }).on('hidden.bs.modal', () => {
            $('iframe').remove();
            dataTable.ajax.reload(null, false);
        });
    };
    window.AlphaLinkServers = { reload: () => dataTable.ajax.reload(null, false) };

    returned.on('change', () => {
        dataTable.rows().deselect();
        refreshSelectionButtons();
        dataTable.ajax.reload();
    });
    refreshSelectionButtons();
});
