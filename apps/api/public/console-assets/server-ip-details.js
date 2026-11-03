window.jQuery(function () {
    const $ = window.jQuery;
    const container = document.getElementById('server-ip-mta');
    const historyPage = document.getElementById('server-ip-history');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const escapeHtml = (value) => $('<div>').text(value == null ? '' : String(value)).html();
    const historyFields = [
        ['ip_listed_spamhaus', 'ip spamhaus zen'],
        ['ip_listed_spamcop', 'ip spamcop'],
        ['ip_listed_barracuda', 'ip barracuda'],
        ['domain_listed_hostkarma', 'domain hostkarma'],
        ['domain_listed_spamhaus_dbl', 'domain spamhaus dbl'],
        ['domain_listed_surbl', 'domain surbl'],
    ];
    const formatBadge = (value, title) => {
        const listed = Boolean(value);
        const text = listed ? 'YES' : 'NO';
        return `<span class="badge badge-${listed ? 'danger' : 'success'} p-1" title="${escapeHtml(title)}">${text}</span>&nbsp;`;
    };
    const formatHistory = (row, historyUrlTemplate) => {
        const history = row.history;
        const badges = historyFields.map(([field, title]) => formatBadge(history?.[field], title)).join('');
        const more = historyUrlTemplate.replace('__IP__', encodeURIComponent(row.id));

        return `<div class="table-responsive"><table class="table table-sm history-summary">
            <thead><tr><th>PTR</th><th>BLACKLISTED</th><th>SENDER SCORE</th><th>CREATED AT</th><th>SEE MORE</th></tr></thead>
            <tbody><tr><td>${escapeHtml(history?.ptr || 'NA')}</td><td>${badges}</td>
            <td>${escapeHtml(history?.sender_score || 'NA')}</td><td>${escapeHtml(history?.created_at || 'NA')}</td>
            <td><a href="${escapeHtml(more)}" target="_blank" rel="noopener">IP history <i class="fas fa-door-open" aria-hidden="true"></i></a></td></tr></tbody>
        </table></div>`;
    };
    const ipLink = (value, type) => {
        if (type !== 'display') return value;
        const safe = escapeHtml(value);
        return `<a href="//${safe}" target="_blank" rel="noopener noreferrer">${safe}</a>&nbsp;<button type="button" class="btn btn-link btn-sm ip-copy" data-copy="${safe}" aria-label="Copy ${safe}" title="COPY"><i class="fa fa-clone" aria-hidden="true"></i></button>`;
    };
    const domainLink = (value, type) => {
        if (type !== 'display') return value;
        const safe = escapeHtml(value);
        return `<a href="//${safe}" target="_blank" rel="noopener noreferrer">${safe}</a>&nbsp;<button type="button" class="btn btn-link btn-sm ip-copy" data-copy="${safe}" aria-label="Copy ${safe}" title="COPY"><i class="fa fa-clone" aria-hidden="true"></i></button>`;
    };

    if (historyPage && $.fn.DataTable) {
        const table = $('#histories').DataTable({
            ajax: { url: historyPage.dataset.sourceUrl, dataType: 'json' },
            processing: true,
            serverSide: true,
            order: [[0, 'desc']],
            columns: [
                { title: '#', data: 'id' },
                { title: 'PTR', data: 'ptr', render: (value, type) => type === 'display' ? escapeHtml(value || 'NA') : value },
                {
                    title: 'BLACKLISTED',
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: (value, type, row) => type === 'display'
                        ? historyFields.map(([field, title]) => formatBadge(row[field], title)).join('')
                        : '',
                },
                { title: 'SENDER SCORE', data: 'sender_score' },
                { title: 'AT', data: 'created_at' },
            ],
        });

        return;
    }

    if (!container || !$.fn.DataTable) return;

    const statusMessage = document.getElementById('server-ip-message');
    const setMessage = (message, isError = false) => {
        statusMessage.textContent = message;
        statusMessage.className = isError ? 'px-3 pb-2 text-danger' : 'px-3 pb-2 text-success';
    };
    const updateStatus = (urlTemplate, id, status, table) => {
        const button = document.activeElement instanceof HTMLButtonElement ? document.activeElement : null;
        if (button) {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        }
        $.ajax({
            url: urlTemplate.replace(/__(?:IP|MTA)__/, encodeURIComponent(id)),
            type: 'PATCH',
            dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: { status },
        }).done((response) => {
            setMessage(response.data || 'Changed successfully');
            table.ajax.reload(null, false);
        }).fail((response) => {
            setMessage(response.responseJSON?.data || response.responseJSON?.message || 'Could not update status. Try again.', true);
        }).always(() => {
            if (button) {
                button.disabled = false;
                button.removeAttribute('aria-busy');
            }
        });
    };
    const actionButton = (row) => {
        const active = Boolean(row.is_active);
        const nextStatus = active ? 0 : 1;
        const title = active ? 'DISABLE' : 'ENABLE';
        const icon = active ? 'fa-times' : 'fa-check';
        const color = active ? 'btn-warning' : 'btn-success';

        return `<button type="button" data-record-id="${escapeHtml(row.id)}" data-next-status="${nextStatus}" ` +
            `class="btn ${color} btn-sm status" title="${title}" aria-label="${title} ${escapeHtml(row.ip)}">` +
            `<i class="fas ${icon}" aria-hidden="true"></i></button>`;
    };
    const ipTable = $('#mta_ip').DataTable({
        ajax: { url: container.dataset.ipsUrl, dataType: 'json' },
        processing: true,
        serverSide: true,
        order: [[0, 'desc']],
        rowCallback: (row, data) => {
            if (!data.is_active) $(row).addClass('table-danger');
        },
        columns: [
            { title: '#', data: 'id' },
            { title: 'IP', data: 'ip', type: 'ip-address', render: ipLink },
            { title: 'TYPE', data: 'type' },
            { title: 'DOMAIN', data: 'dns', render: domainLink },
            { title: 'CREATED AT', data: 'created_at' },
            {
                title: 'ACTIONS',
                data: null,
                orderable: false,
                searchable: false,
                render: (value, type, row) => type !== 'display'
                    ? row.histories_count
                    : `<button type="button" class="btn btn-sm btn-info history" data-history-count="${escapeHtml(row.histories_count)}" ` +
                        `title="IP HISTORY" aria-expanded="false">HISTORIES : ${escapeHtml(row.histories_count)} ` +
                        '<i class="fas fa-angle-down" aria-hidden="true"></i></button>&nbsp;' + actionButton(row),
            },
        ],
    });
    $('#mta_ip').on('click', '.history', function () {
        const tr = $(this).closest('tr');
        const row = ipTable.row(tr);
        const button = this;
        if (row.child.isShown()) {
            row.child.hide();
            tr.removeClass('shown');
            button.setAttribute('aria-expanded', 'false');
        } else {
            row.child(formatHistory(row.data(), container.dataset.ipHistoryUrlTemplate), 'text-left').show();
            tr.addClass('shown');
            button.setAttribute('aria-expanded', 'true');
        }
    }).on('click', '.status', function (event) {
        event.preventDefault();
        updateStatus(container.dataset.ipStatusUrlTemplate, this.dataset.recordId, this.dataset.nextStatus, ipTable);
    });

    const mtaTable = (selector, url, countSelector) => {
        const $table = $(selector);
        const table = $table.DataTable({
            ajax: { url, dataType: 'json' },
            processing: true,
            serverSide: true,
            order: [[0, 'desc']],
            rowCallback: (row, data) => {
                if (!data.is_active) $(row).addClass('table-danger');
            },
            columns: [
                { title: '#', data: 'id' },
                { title: 'IP', data: 'ip', type: 'ip-address', render: ipLink },
                { title: 'TYPE', data: 'ip_type' },
                { title: 'DOMAIN', data: 'dns', render: domainLink },
                { title: 'CREATED AT', data: 'created_at' },
                { title: 'CREATED BY', data: 'user.name', defaultContent: 'NA' },
                {
                    title: 'ACTIONS',
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: (value, type, row) => type === 'display' ? actionButton(row) : '',
                },
            ],
            initComplete: function (settings, json) {
                $(countSelector).text(json?.recordsTotal ?? 0);
            },
        });
        $table.on('xhr.dt', (event, settings, json) => $(countSelector).text(json?.recordsTotal ?? 0));
        $table.on('click', '.status', function (event) {
            event.preventDefault();
            updateStatus(container.dataset.mtaStatusUrlTemplate, this.dataset.recordId, this.dataset.nextStatus, table);
        });

        return table;
    };

    $('#mta_ip').on('xhr.dt', (event, settings, json) => $('#ip_count').text(json?.recordsTotal ?? 0));
    $('#server-ip-mta').on('click', '.ip-copy', async function () {
        try {
            await navigator.clipboard.writeText(this.dataset.copy || '');
            setMessage('Copied to clipboard.');
        } catch {
            setMessage('Clipboard access is unavailable.', true);
        }
    });
    $('#server-ip-tabs').on('shown.bs.tab', 'a[role="tab"]', function () {
        $('#server-ip-tabs a[role="tab"]').attr('aria-selected', 'false');
        this.setAttribute('aria-selected', 'true');
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });
    mtaTable('#mta_vmta', container.dataset.vmtaUrl, '#vmta_count');
    mtaTable('#mta_smtp', container.dataset.smtpUrl, '#smtp_count');
});
