window.jQuery(function () {
    const tableElement = document.getElementById('my-servers');
    if (!tableElement || !window.jQuery || !window.jQuery.fn.DataTable) return;

    const $ = window.jQuery;
    const escapeHtml = (value) => String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    const status = (isDown, label) => {
        if (isDown == null) return '<span class="badge-command-pill" title="' + escapeHtml(label) + '">N/A</span>';
        const down = isDown === true;
        const color = down ? 'badge-command-active-no' : 'badge-command-active-yes';
        return '<span class="badge-command-pill ' + color + '" title="' + escapeHtml(label) + '">' + (down ? 'DOWN' : 'UP') + '</span>';
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
    const copyButton = (value) => '<button type="button" class="my-server-copy btn btn-link btn-sm p-0 ml-1" data-copy-value="' + escapeHtml(value) + '" title="Copy IP address" aria-label="Copy IP address" style="color: #8b5cf6;"><i class="fa fa-clone" aria-hidden="true"></i></button>';
    const ipCell = (value, type) => {
        const ip = String(value == null ? '' : value);
        if (type !== 'display') return ip;
        const url = safeHostUrl(ip);
        const link = url
            ? '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" style="font-family: monospace; font-weight: 600; color: #6366f1;">' + escapeHtml(ip) + '</a>'
            : '<span style="font-family: monospace; font-weight: 600; color: #6366f1;">' + escapeHtml(ip || 'N/A') + '</span>';
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
        dom: '<"command-table-toolbar"<"command-table-toolbar-left"lB><"command-table-toolbar-right"f>>rt<"command-table-footer"ip>',
        buttons: [
            { extend: 'pageLength', className: 'btn-command-action' },
            {
                text: '<i class="fas fa-sync mr-1" aria-hidden="true"></i> REFRESH',
                className: 'btn-command-action',
                titleAttr: 'Refresh',
                action: function (event, dt) { dt.ajax.reload(null, false); },
            },
        ],
        columns: [
            {
                title: '#', data: 'id', type: 'natural', width: '7%',
                render: function (id, type) {
                    return type === 'display' ? '<span class="command-mono-badge" title="' + escapeHtml(id) + '">#' + escapeHtml(shortId(id)) + '</span>' : id;
                },
            },
            {
                title: 'SERVER', data: 'name', type: 'natural', width: '17%',
                render: function (name, type, row) {
                    const value = String(name == null ? '' : name) + shortId(row.id);
                    return type === 'display' ? '<strong style="color: var(--command-os-text, #1e293b);">' + escapeHtml(value) + '</strong>' : value;
                },
            },
            { title: 'MAIN IP', data: 'ip', type: 'ip-address', width: '14%', render: ipCell },
            {
                title: 'COUNTRY', data: 'country', width: '8%',
                render: function (country, type) {
                    const value = flag(country);
                    return type === 'display' ? '<span class="badge-command-pill" style="font-family: monospace;">' + escapeHtml(value) + '</span>' : value;
                },
            },
            {
                title: 'TOTAL IPS', data: 'ips_count', type: 'num', width: '8%',
                render: function (count, type, row) {
                    const value = Number(count) || 0;
                    return type === 'display' ? '<a href="' + escapeHtml(ipsUrl(row.id)) + '" class="badge-command-pill" style="color: #6366f1; text-decoration: none; font-weight: 700;"><i class="fas fa-network-wired mr-1" style="font-size: 0.75rem;"></i>' + value + '</a>' : value;
                },
            },
            { title: 'SSH', data: 'history', width: '8%', render: (history) => status(history ? history.is_ssh_down : null, 'SSH status') },
            { title: 'APACHE', data: 'history', width: '8%', render: (history) => status(history ? history.is_apache_down : null, 'Apache status') },
            { title: 'PMTA', data: 'history', width: '8%', render: (history) => status(history ? history.is_pmta_down : null, 'PowerMTA status') },
            {
                title: 'LAST CHECK', data: 'history', width: '12%',
                render: function (history, type) {
                    if (!history || !history.created_at) return '<span class="text-muted">N/A</span>';
                    const value = type === 'display' ? new Date(history.created_at).toLocaleString() : history.created_at;
                    return type === 'display' ? '<span style="font-size: 0.8125rem; color: var(--command-os-text-muted, #64748b);">' + escapeHtml(value) + '</span>' : value;
                },
            },
            {
                title: 'ACTIONS', data: null, orderable: false, searchable: false, width: '10%',
                render: function (data, type, row) {
                    if (type !== 'display') return '';
                    return '<div class="command-action-btn-group"><a href="' + escapeHtml(pmtaUrl(row.id)) + '" class="command-action-btn action-terminal" title="OPEN PMTA CONSOLE" aria-label="Open PMTA console"><i class="fas fa-desktop" aria-hidden="true"></i></a></div>';
                },
            },
        ],
    });

    $(tableElement).on('click', '.my-server-copy', async function (event) {
        event.preventDefault();
        const value = this.dataset.copyValue || '';
        try {
            await navigator.clipboard.writeText(value);
            statusRegion.textContent = 'IP address copied to clipboard.';
        } catch {
            statusRegion.textContent = 'Clipboard access is unavailable.';
        }
    });
});
