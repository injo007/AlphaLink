window.jQuery(function () {
    const table = document.getElementById('histories');
    if (!table || !window.jQuery?.fn?.DataTable) return;

    const $ = window.jQuery;
    const badge = (value) => {
        const down = value === true;
        const color = down ? 'badge-command-active-no' : 'badge-command-active-yes';
        const text = down ? 'DOWN' : 'UP';
        return `<span class="badge-command-pill ${color}">${text}</span>`;
    };

    $('#histories').DataTable({
        ajax: table.dataset.sourceUrl,
        dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
        serverSide: true,
        order: [[0, 'desc']],
        buttons: [
            { extend: 'pageLength', className: 'btn-command-action' },
            {
                text: '<i class="fas fa-sync mr-1"></i> REFRESH',
                className: 'btn-command-action',
                titleAttr: 'REFRESH',
                action: (event, dataTable) => dataTable.ajax.reload(null, false),
            },
        ],
        columns: [
            {
                title: '#',
                data: 'id',
                render: (value, type) => type === 'display' ? `<span class="command-mono-badge">#${value}</span>` : value
            },
            { title: 'SSH DAEMON', data: 'is_ssh_down', searchable: false, render: badge },
            { title: 'APACHE DAEMON', data: 'is_apache_down', searchable: false, render: badge },
            { title: 'PMTA DAEMON', data: 'is_pmta_down', searchable: false, render: badge },
            {
                title: 'AUDIT TIMESTAMP',
                data: 'created_at',
                render: (value, type) => type === 'display' ? `<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">${value}</span>` : value
            },
        ],
    });
});
