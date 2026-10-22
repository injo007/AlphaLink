window.jQuery(function () {
    const tableElement = document.getElementById('servers');
    if (!tableElement || !window.jQuery || !window.jQuery.fn.DataTable) return;

    const $ = window.jQuery;
    const showBadge = (value) => {
        const text = value ? 'YES' : 'NO';
        const color = value ? 'success' : 'danger';
        return `<span class="badge badge-${color} p-1" title="${text}">${text}</span>`;
    };
    const inactiveButton = (icon, title, color = 'btn-info') => (
        `<button type="button" class="btn ${color} btn-sm" title="${title}" aria-label="${title}" disabled><i class="fas ${icon}"></i></button>&nbsp;`
    );
    const returned = $('#returned');
    const dataTable = $('#servers').DataTable({
        ajax: {
            url: tableElement.dataset.sourceUrl,
            data: (query) => {
                query.returned = returned.is(':checked');
            },
        },
        processing: true,
        displayLength: 50,
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
            },
            { text: '<i class="fa fa-trash"></i>', titleAttr: 'DELETE SERVERS', enabled: false },
            { text: '<i class="fas fa-users"></i>', titleAttr: 'ALLOWED USERS', enabled: false },
            { text: '<i class="fas fa-magic"></i>', titleAttr: 'INSTALL SERVER', enabled: false },
            { text: '<i class="fas fa-tools"></i>', titleAttr: 'BULK INSTALL SERVERS', enabled: false },
            { text: '<i class="fas fa-recycle"></i>', titleAttr: 'RECONFIG VMTA', enabled: false },
            {
                text: '<i class="fa fa-sync"></i>',
                titleAttr: 'REFRESH',
                action: (event, dt) => dt.ajax.reload(),
            },
        ],
        columns: [
            { targets: 0, data: null, defaultContent: '', orderable: false, searchable: false, className: 'select-checkbox' },
            { title: 'ID', data: 'id' },
            {
                title: 'PROVIDER',
                data: 'provider.name',
                render: (value, type) => type === 'display' ? (value || 'NA') : value,
            },
            { title: 'NAME', data: 'name' },
            {
                title: 'MAIN IP',
                data: 'ip',
                render: (value, type) => type === 'display'
                    ? `<span class="text-primary">${$('<div>').text(value || '').html()}</span>`
                    : value,
            },
            {
                title: 'COUNTRY',
                data: 'country',
                render: (value) => value ? String(value).toUpperCase() : 'N/A',
            },
            { title: 'EXPIRE AT', data: 'expire_at' },
            { title: 'INSTALLED', data: 'is_installed', render: showBadge },
            {
                title: 'STATUS',
                data: 'history',
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
                render: (value) => inactiveButton('fa-server', 'TOTAL IPS', 'btn-link')
                    .replace('<i class="fas fa-server"></i>', `${value ?? 0}`),
            },
            {
                title: 'USERS',
                data: 'allowed_users',
                render: (users) => inactiveButton('fa-users', `ALLOWED USERS : ${(users || []).length}`, 'btn-link')
                    .replace('<i class="fas fa-users"></i>', `<i class="fas fa-users"></i> : ${(users || []).length}`),
            },
            {
                title: 'ACTIONS',
                data: null,
                orderable: false,
                searchable: false,
                render: (data, type, row) => {
                    if (row.deleted_at) return inactiveButton('fa-trash-restore', 'RESTORE', 'btn-secondary');
                    return [
                        inactiveButton('fa-history', 'SERVER HISTORY'),
                        inactiveButton('fa-terminal', 'CONSOLE', 'btn-alt-dark'),
                        inactiveButton('fa-pencil-alt', 'UPDATE SERVER'),
                        inactiveButton('fa-lock-open', 'LOCK SERVER', 'btn-success'),
                        inactiveButton('fa-times', 'DISABLE SERVER', 'btn-warning'),
                        inactiveButton('fa-trash', 'DELETE SERVER', 'btn-danger'),
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

    window.closeModal = () => window.bootbox?.hideAll();
    window.openServerRegistration = () => {
        const iframe = `<iframe src="${tableElement.dataset.createUrl}" width="100%" scrolling="no" frameborder="0" title="Add Servers"></iframe>`;
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

    returned.on('click', () => dataTable.ajax.reload());
});
