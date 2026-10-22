window.jQuery(function () {
    const table = document.getElementById('histories');
    if (!table || !window.jQuery?.fn?.DataTable) return;

    const $ = window.jQuery;
    const badge = (value) => {
        const down = value === true;
        const color = down ? 'badge-danger' : 'badge-success';
        const text = down ? 'YES' : 'NO';
        return `<span class="badge ${color} p-1">${text}</span>`;
    };

    $('#histories').DataTable({
        ajax: table.dataset.sourceUrl,
        serverSide: true,
        order: [[0, 'asc']],
        buttons: [
            { extend: 'pageLength' },
            {
                text: '<i class="fas fa-sync"></i>',
                titleAttr: 'REFRESH',
                action: (event, dataTable) => dataTable.ajax.reload(null, false),
            },
        ],
        columns: [
            { title: '#', data: 'id' },
            { title: 'is ssh down', data: 'is_ssh_down', searchable: false, render: badge },
            { title: 'is apache down', data: 'is_apache_down', searchable: false, render: badge },
            { title: 'is pmta down', data: 'is_pmta_down', searchable: false, render: badge },
            { title: 'at', data: 'created_at', render: $.fn.dataTable.render.text() },
        ],
    });
});
