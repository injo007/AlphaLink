window.jQuery(function () {
    const tableElement = document.getElementById('my-servers');
    if (!tableElement || !window.jQuery || !window.jQuery.fn.DataTable) return;

    const $ = window.jQuery;
    const escapeHtml = (value) => String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    const status = (isDown, label) => {
        if (isDown == null) return '<span class="badge badge-secondary p-1" title="' + escapeHtml(label) + '">N/A</span>';
        const down = isDown === true;
        return '<span class="badge badge-' + (down ? 'danger' : 'success') + ' p-1" title="' + escapeHtml(label) + '">' + (down ? 'YES' : 'NO') + '</span>';
    };
    const flag = (country) => {
        const code = String(country == null ? '' : country).trim().toUpperCase();
        if (!/^[A-Z]{2}$/.test(code)) return 'N/A';
        return String.fromCodePoint(...Array.from(code, (letter) => letter.charCodeAt(0) + 127397));
    };
    const safeHostUrl = (value) => {
        const host = String(value == null ? '' : value).trim();
        if (!host || /[\/?#@\s]/.test(host)) return '';
        const candidate = host.includes(':') && !host.startsWith('[') ? '[' + host + ']' : host;
        try {
            const parsed = new URL(window.location.protocol + '//' + candidate);
            return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed.href : '';
        } catch (error) {
            return '';
        }
    };
    const shortId = (id) => String(id == null ? '' : id).slice(0, 8);
    const copyButton = (value) => '<button type="button" class="my-server-copy btn btn-link btn-sm p-0 ml-1" data-copy-value="' + escapeHtml(value) + '" title="Copy IP address" aria-label="Copy IP address"><i class="fa fa-clone" aria-hidden="true"></i></button>';
    const ipCell = (value, type) => {
        const ip = String(value == null ? '' : value);
        if (type !== 'display') return ip;
        const url = safeHostUrl(ip);
        const link = url
            ? '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(ip) + '</a>'
            : escapeHtml(ip || 'N/A');
        return link + (ip ? copyButton(ip) : '');
    };
    const ipsUrl = (serverId) => {
        const url = new URL(tableElement.dataset.myIpsUrl, window.location.href);
        url.searchParams.set('server_id', serverId);
        return url.toString();
    };
    const pmtaUrl = (serverId) => {
        const url = new URL(tableElement.dataset.pmtaUrl, window.location.href);
        url.searchParams.append('server_ids[]', serverId);
        return url.toString();
    };
    const statusRegion = document.getElementById('my-servers-status');

    const dataTable = $(tableElement).DataTable({
        ajax: function (request, callback) {
            $.getJSON(tableElement.dataset.sourceUrl)
                .done(callback)
                .fail(function () {
                    statusRegion.textContent = 'My Servers could not be loaded. Use Refresh to try again.';
                    callback({ data: [] });
                });
        },
        order: [],
        pageLength: 50,
        dom: 'Bfrtip',
        buttons: [
            { extend: 'pageLength' },
            {
                text: '<i class="fas fa-sync" aria-hidden="true"></i>',
                titleAttr: 'Refresh',
                action: function (event, dt) { dt.ajax.reload(null, false); },
            },
        ],
        columns: [
            {
                title: '#', data: 'id', type: 'natural', width: '7%',
                render: function (id, type) {
                    return type === 'display' ? '<span title="' + escapeHtml(id) + '">' + escapeHtml(shortId(id)) + '</span>' : id;
                },
            },
            {
                title: 'SERVER', data: 'name', type: 'natural', width: '17%',
                render: function (name, type, row) {
                    const value = String(name == null ? '' : name) + shortId(row.id);
                    return type === 'display' ? '<span title="' + escapeHtml(row.id) + '">' + escapeHtml(value) + '</span>' : value;
                },
            },
            { title: 'MAIN IP', data: 'ip', type: 'ip-address', width: '14%', render: ipCell },
            {
                title: 'COUNTRY', data: 'country', width: '8%',
                render: function (country, type) {
                    const value = flag(country);
                    return type === 'display' ? '<span title="' + escapeHtml(country || 'Country unavailable') + '">' + escapeHtml(value) + '</span>' : value;
                },
            },
            {
                title: 'TOTAL IPS', data: 'ips_count', type: 'num', width: '8%',
                render: function (count, type, row) {
                    const value = Number(count) || 0;
                    return type === 'display' ? '<a href="' + escapeHtml(ipsUrl(row.id)) + '" aria-label="View ' + value + ' IPs for ' + escapeHtml(row.name) + '">' + value + '</a>' : value;
                },
            },
            { title: 'IS SSH DOWN', data: 'history', width: '9%', render: (history) => status(history ? history.is_ssh_down : null, 'SSH down') },
            { title: 'IS APACHE DOWN', data: 'history', width: '10%', render: (history) => status(history ? history.is_apache_down : null, 'Apache down') },
            { title: 'IS PMTA DOWN', data: 'history', width: '9%', render: (history) => status(history ? history.is_pmta_down : null, 'PMTA down') },
            {
                title: 'LAST CHECK', data: 'history', width: '12%',
                render: function (history, type) {
                    if (!history || !history.created_at) return 'N/A';
                    const value = type === 'display' ? new Date(history.created_at).toLocaleString() : history.created_at;
                    return type === 'display' ? escapeHtml(value) : value;
                },
            },
            {
                title: 'ACTION', data: null, orderable: false, searchable: false, width: '6%',
                render: function (data, type, row) {
                    if (type !== 'display') return '';
                    return '<a class="btn btn-sm btn-link" href="' + escapeHtml(pmtaUrl(row.id)) + '" target="_blank" rel="noopener noreferrer" title="See PMTA" aria-label="Open PMTA Admin for ' + escapeHtml(row.name) + '"><i class="fas fa-server" aria-hidden="true"></i></a>';
                },
            },
        ],
    });

    tableElement.addEventListener('click', async (event) => {
        const button = event.target.closest('.my-server-copy');
        if (!button) return;
        try {
            await navigator.clipboard.writeText(button.dataset.copyValue || '');
            statusRegion.textContent = 'IP address copied.';
        } catch (error) {
            statusRegion.textContent = 'Copy failed. Select the IP address to copy it.';
        }
    });

});
