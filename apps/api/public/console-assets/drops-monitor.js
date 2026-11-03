(() => {
    const root = document.getElementById('drops-monitoring');
    if (!root) {
        return;
    }

    const tableElement = document.getElementById('drops-monitoring-table');
    const filterForm = document.getElementById('drops-monitoring-filters');
    const filterButton = document.getElementById('drops-monitoring-filter');
    const typeInput = document.getElementById('drops-monitoring-type');
    const message = document.getElementById('drops-monitoring-message');
    const dateFormat = 'YYYY-MM-DD';
    const today = window.moment(root.dataset.today, dateFormat, true).startOf('day');
    let fromDate = today.clone();
    let toDate = today.clone();
    const pollTimers = new Map();
    const polling = new Set();

    const showMessage = (text) => {
        message.textContent = text;
        message.hidden = text === '';
    };

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);

    const validStoredDate = (value) => {
        const parsed = window.moment(value, dateFormat, true);
        return parsed.isValid() && parsed.isSameOrBefore(today, 'day') ? parsed : null;
    };
    try {
        const savedFrom = sessionStorage.getItem('drops_date_from');
        const savedTo = sessionStorage.getItem('drops_date_to');
        const parsedFrom = validStoredDate(savedFrom);
        const parsedTo = validStoredDate(savedTo);
        if (parsedFrom && parsedTo && !parsedFrom.isAfter(parsedTo, 'day')) {
            fromDate = parsedFrom;
            toDate = parsedTo;
        }
    } catch (error) {
        // Storage can be unavailable in restricted browser contexts; the default dates remain usable.
    }

    const showProgress = (result) => {
        const progress = document.getElementById('drop-progress-' + result.id);
        if (!progress) {
            return;
        }

        progress.replaceChildren(document.createTextNode((result.send_progress ?? 0) + '/' + (result.send_count ?? 0)));
        if (result.send_status === 'progress') {
            const spinner = document.createElement('i');
            spinner.className = 'fas fa-circle-notch fa-spin ml-1';
            spinner.setAttribute('aria-hidden', 'true');
            progress.append(spinner);
        }
    };

    const scheduleProgressPoll = (id) => {
        if (pollTimers.has(id) || polling.has(id)) {
            return;
        }

        const timer = window.setTimeout(async () => {
            pollTimers.delete(id);
            polling.add(id);
            try {
                const url = root.dataset.monitorUrlTemplate.replace('__DROP_ID__', encodeURIComponent(id));
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                const result = await response.json();
                showProgress(result);
                showMessage('');
                if (result.send_status === 'progress') {
                    scheduleProgressPoll(id);
                } else if (window.jQuery?.fn?.DataTable) {
                    window.jQuery(tableElement).DataTable().ajax.reload(null, false);
                }
            } catch (error) {
                showMessage('Progress for drop #' + id + ' could not be refreshed. Retry by refreshing the table.');
            } finally {
                polling.delete(id);
            }
        }, 2000);

        pollTimers.set(id, timer);
    };

    if (!window.jQuery?.fn?.DataTable) {
        showMessage('The monitoring table could not start because its table assets did not load.');
        return;
    }

    const $ = window.jQuery;
    const grid = $(tableElement).DataTable({
        ajax: {
            url: root.dataset.rowsUrl,
            data: (request) => {
                request.from = fromDate.format(dateFormat);
                request.to = toDate.format(dateFormat);
                request.drop_type = typeInput.value || 'all';
            },
        },
        dom: '<"row"<"col-sm-12 col-md-6 mb-2 mb-md-0 text-center text-md-left"B><"col-sm-12 col-md-6 mb-2 mb-md-0 text-center text-md-right"f>><"row"<"col-sm-12"tr>><"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        displayLength: 50,
        order: [[0, 'desc']],
        responsive: false,
        autoWidth: false,
        processing: true,
        initComplete: () => {
            const filter = document.getElementById('drops-monitoring-table_filter');
            const searchLabel = filter?.querySelector('label');
            if (!filter || !searchLabel || !$.fn.daterangepicker) {
                showMessage('The date range picker could not start because its local assets did not load.');
                return;
            }

            const dateLabel = document.createElement('label');
            dateLabel.className = 'mb-0 mr-2';
            dateLabel.append(document.createTextNode('Date: '));
            const dateInput = document.createElement('input');
            dateInput.id = 'drops-monitoring-date';
            dateInput.type = 'text';
            dateInput.className = 'form-control form-control-sm curs text-center d-inline-block';
            dateInput.setAttribute('aria-label', 'Date range');
            dateInput.readOnly = true;
            dateLabel.append(dateInput);
            filter.insertBefore(dateLabel, searchLabel);

            $(dateInput).daterangepicker({
                startDate: fromDate,
                endDate: toDate,
                maxDate: today,
                opens: 'left',
                alwaysShowCalendars: true,
                ranges: {
                    Today: [today.clone(), today.clone()],
                    Yesterday: [today.clone().subtract(1, 'days'), today.clone().subtract(1, 'days')],
                    'Today-Yesterday': [today.clone().subtract(1, 'days'), today.clone()],
                    'Last 7 Days': [today.clone().subtract(6, 'days'), today.clone()],
                    'This Month': [today.clone().startOf('month'), today.clone().endOf('month')],
                    'Last Month': [today.clone().subtract(1, 'month').startOf('month'), today.clone().subtract(1, 'month').endOf('month')],
                },
                locale: {
                    format: dateFormat,
                    firstDay: 1,
                },
            }).on('apply.daterangepicker', (event, picker) => {
                fromDate = picker.startDate.clone();
                toDate = picker.endDate.clone();
                try {
                    sessionStorage.setItem('drops_date_from', fromDate.format(dateFormat));
                    sessionStorage.setItem('drops_date_to', toDate.format(dateFormat));
                } catch (error) {
                    // The selected range still applies if session storage is unavailable.
                }
                showMessage('');
                grid.ajax.reload(null, false);
            });
        },
        buttons: [
            { extend: 'pageLength' },
            {
                text: '<i class="fas fa-sync" aria-hidden="true"></i>',
                titleAttr: 'REFRESH',
                action: (event, dataTable) => dataTable.ajax.reload(null, false),
            },
        ],
        rowCallback: (row, data) => {
            row.classList.toggle('table-danger', Boolean(data.is_restricted));
            row.classList.toggle('table-warning', !data.is_restricted && Boolean(data.is_scheduled));
            if (data.send_status === 'progress') {
                scheduleProgressPoll(data.id);
            }
        },
        columns: [
            { title: '#', data: 'id', width: '5%' },
            { title: 'USER', data: 'user', className: 'text-uppercase', render: (data, type) => type === 'display' ? escapeHtml(data).toUpperCase() : data },
            { title: 'TYPE', data: 'send_type', className: 'text-uppercase', render: (data, type) => type === 'display' ? escapeHtml(data).toUpperCase() : data },
            {
                title: 'ISP',
                data: 'isp',
                render: (data, type, row) => {
                    if (type === 'display') {
                        return '<span class="badge badge-primary text-uppercase">' + escapeHtml(data) + '</span>';
                    }
                    return [data].concat(row.lists || [], row.filters || [], row.countries || []).join(' ');
                },
            },
            {
                title: 'SERVERS',
                data: 'servers',
                render: (data, type) => {
                    const names = Array.isArray(data) ? data : [];
                    if (type === 'display' && names.length > 1) {
                        return '<span class="badge badge-primary" title="' + escapeHtml(names.join(', ')) + '">GROUPED : ' + names.length + '</span>';
                    }
                    return type === 'display' ? escapeHtml(names[0] || 'NA0') : names.join(' ');
                },
            },
            {
                title: 'OFFER',
                data: 'offer',
                render: (data, type) => {
                    if (type !== 'display') {
                        return data || '';
                    }
                    const text = String(data || '');
                    const shortText = text.length > 35 ? text.substring(0, 35) + '...' : text;
                    return '<span title="' + escapeHtml(text) + '">' + escapeHtml(shortText) + '</span>';
                },
            },
            { title: 'STATUS', data: 'send_status', render: (data, type) => type === 'display' ? escapeHtml(data) : data },
            {
                title: 'PROGRESS',
                data: null,
                render: (data, type, row) => {
                    if (type !== 'display' || row.send_type !== 'send') {
                        return '';
                    }
                    const progress = document.createElement('span');
                    progress.id = 'drop-progress-' + row.id;
                    progress.textContent = (row.send_progress ?? 0) + '/' + (row.send_count ?? 0);
                    if (row.send_status === 'progress') {
                        const spinner = document.createElement('i');
                        spinner.className = 'fas fa-circle-notch fa-spin ml-1';
                        spinner.setAttribute('aria-hidden', 'true');
                        progress.append(spinner);
                    }
                    return progress.outerHTML;
                },
            },
            {
                title: 'CREATED AT',
                data: 'created_at',
                render: (data, type, row) => {
                    const createdAt = escapeHtml(data);
                    return type === 'display' && row.is_scheduled
                        ? createdAt + '<br><small>Scheduled At: ' + escapeHtml(row.scheduled_at) + '</small>'
                        : createdAt;
                },
            },
            {
                title: 'ACTIONS',
                data: null,
                orderable: false,
                searchable: false,
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return '';
                    }

                    const dropId = encodeURIComponent(row.id);
                    const pmtaUrl = new URL(root.dataset.pmtaAdminUrl, window.location.href);
                    (Array.isArray(row.server_ids) ? row.server_ids : []).forEach((serverId) => {
                        pmtaUrl.searchParams.append('server_ids[]', String(serverId));
                    });
                    const pmta = '<a class="btn btn-link btn-sm" href="' + escapeHtml(pmtaUrl.toString()) + '" title="PMTA" aria-label="Open PMTA for drop ' + dropId + '" target="_blank" rel="noopener"><i class="fas fa-server" aria-hidden="true"></i></a>';
                    if (row.send_type !== 'send') {
                        return pmta;
                    }

                    const visitorUrl = root.dataset.visitorsUrlTemplate.replace('__DROP_ID__', encodeURIComponent(row.id));
                    return pmta + '<button type="button" class="btn btn-link btn-sm drop-stats-action" data-drop-id="' + dropId + '" title="STATS" aria-label="Show statistics for drop ' + dropId + '"><i class="fas fa-chart-bar text-black" aria-hidden="true"></i></button>'
                        + '<a class="btn btn-link btn-sm" href="' + escapeHtml(visitorUrl) + '" title="VISITORS" aria-label="Open visitors for drop ' + encodeURIComponent(row.id) + '" target="_blank" rel="noopener"><i class="fas fa-eye text-black" aria-hidden="true"></i></a>';
                },
            },
        ],
    });

    const statsLabels = [
        ['open', 'OPEN', false],
        ['click', 'CLICK', false],
        ['count_lead', 'COUNT LEAD', false],
        ['lead', 'LEAD', true],
        ['unsubscribe', 'UNSUBSCRIBE', false],
        ['bot', 'BOT', false],
        ['epc', 'EPC', true],
    ];
    const statsContent = (result) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'row';
        statsLabels.forEach(([key, label, currency]) => {
            const column = document.createElement('div');
            column.className = 'col';
            const heading = document.createElement('div');
            heading.className = 'font-weight-bold';
            heading.textContent = label;
            const value = document.createElement('div');
            const amount = key === 'epc'
                ? (Number(result.click) > 0 ? Number(result.lead) / Number(result.click) : 0)
                : Number(result[key] ?? 0);
            value.textContent = currency
                ? '$' + (key === 'epc' ? (amount === 0 ? '0' : amount.toFixed(2)) : String(result.lead ?? 0))
                : String(amount);
            column.append(heading, value);
            wrapper.append(column);
        });

        return wrapper;
    };

    $(tableElement).on('click', 'button.drop-stats-action', async (event) => {
        const button = event.target.closest('button.drop-stats-action');
        if (!button) {
            return;
        }

        const row = grid.row(button.closest('tr'));
        if (row.child.isShown()) {
            row.child.hide();
            return;
        }

        const dropId = button.dataset.dropId;
        const loading = document.createElement('div');
        loading.className = 'p-2 m-2';
        loading.textContent = 'Loading statistics…';
        row.child(loading).show();
        button.disabled = true;
        try {
            const url = root.dataset.statsUrlTemplate.replace('__DROP_ID__', encodeURIComponent(dropId));
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            row.child(statsContent(await response.json())).show();
        } catch (error) {
            const failure = document.createElement('div');
            failure.className = 'alert alert-danger py-2 mb-0';
            failure.textContent = 'Drop statistics could not be loaded. Refresh the monitoring table and try again.';
            row.child(failure).show();
        } finally {
            button.disabled = false;
        }
    });

    grid.on('preXhr.dt', () => {
        filterButton.disabled = true;
        filterButton.setAttribute('aria-busy', 'true');
    });
    grid.on('xhr.dt', () => {
        filterButton.disabled = false;
        filterButton.removeAttribute('aria-busy');
    });
    grid.on('error.dt', () => {
        filterButton.disabled = false;
        filterButton.removeAttribute('aria-busy');
        showMessage('Drop records could not be loaded. Check the connection and refresh the table.');
    });

    const searchInput = document.querySelector('#drops-monitoring-table_filter input');
    if (searchInput) {
        searchInput.setAttribute('aria-label', 'Search drops');
        searchInput.setAttribute('placeholder', 'Search drops...');
        const clearSearch = document.createElement('button');
        clearSearch.type = 'button';
        clearSearch.className = 'btn btn-sm btn-link';
        clearSearch.textContent = '×';
        clearSearch.setAttribute('aria-label', 'Clear search');
        clearSearch.hidden = true;
        searchInput.insertAdjacentElement('afterend', clearSearch);
        searchInput.addEventListener('input', () => {
            clearSearch.hidden = searchInput.value === '';
        });
        clearSearch.addEventListener('click', () => {
            searchInput.value = '';
            grid.search('').draw();
            clearSearch.hidden = true;
            searchInput.focus();
        });
    }

    filterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        showMessage('');
        grid.ajax.reload();
    });

    window.addEventListener('beforeunload', () => {
        pollTimers.forEach((timer) => window.clearTimeout(timer));
        pollTimers.clear();
    }, { once: true });
})();
