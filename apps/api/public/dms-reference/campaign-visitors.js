(() => {
    const root = document.getElementById('campaign-visitors');
    if (!root) {
        return;
    }

    const tableElement = document.getElementById('campaign-visitors-table');
    const searchInput = document.getElementById('campaign-visitors-search');
    const clearButton = document.getElementById('campaign-visitors-search-clear');
    const resultCount = document.getElementById('campaign-visitors-result-count');
    const errorMessage = document.getElementById('campaign-visitors-error');
    const mapElement = document.getElementById('campaign-visitors-map');
    const mapEmpty = document.getElementById('campaign-visitors-map-empty');
    const mapBlock = document.getElementById('campaign-visitors-map-block');
    const mapToggle = document.getElementById('campaign-visitors-map-toggle');
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);

    if (!window.jQuery?.fn?.DataTable) {
        errorMessage.hidden = false;
        errorMessage.textContent = 'Visitor records could not be loaded because the table assets did not start.';
        return;
    }

    const $ = window.jQuery;
    const params = new URLSearchParams(window.location.search);
    const lengthValues = [20, 50, 100];
    const initialLength = Number(root.dataset.initialLength);
    const initialOrderIndex = Number(root.dataset.initialOrderIndex);
    const initialStart = Number(root.dataset.initialStart);
    const initialDirection = root.dataset.initialOrderDirection === 'desc' ? 'desc' : 'asc';
    const savedSearch = String(root.dataset.initialSearch || '');
    let searchTimer = null;
    let isComposing = false;
    let mapLoaded = false;

    const loadCountryMap = () => {
        if (mapLoaded) {
            return;
        }

        $.getJSON(root.dataset.tableUrl, { chart: 1 })
            .done((rows) => {
                const countries = Array.isArray(rows) ? rows.filter((row) => row.country) : [];
                if (countries.length === 0) {
                    mapElement.hidden = true;
                    mapEmpty.hidden = false;
                    return;
                }
                if (!window.google?.charts || !window.google?.visualization) {
                    mapElement.hidden = true;
                    mapEmpty.hidden = false;
                    mapEmpty.textContent = 'The country map library could not be loaded.';
                    return;
                }

                window.google.charts.load('current', { packages: ['geochart'] });
                window.google.charts.setOnLoadCallback(() => {
                    const chartRows = countries.map((row) => [
                        row.country,
                        Number(row.open || 0),
                        'Open: ' + Number(row.open || 0)
                            + ' - Click: ' + Number(row.click || 0)
                            + ' - Lead: ' + Number(row.lead || 0)
                            + ' - Unsubscribe: ' + Number(row.unsubscribe || 0),
                    ]);
                    const data = window.google.visualization.arrayToDataTable([
                        ['country', 'visitors', { type: 'string', label: 'actions', role: 'tooltip' }],
                        ...chartRows,
                    ]);
                    new window.google.visualization.GeoChart(mapElement).draw(data, {});
                    mapLoaded = true;
                });
            })
            .fail(() => {
                mapElement.hidden = true;
                mapEmpty.hidden = false;
                mapEmpty.textContent = 'Country visitor data could not be loaded. Refresh the visitor page and try again.';
            });
    };

    searchInput.value = savedSearch;
    clearButton.disabled = searchInput.value === '';

    const saveUrlState = (grid) => {
        const info = grid.page.info();
        const order = grid.order()[0] || [0, 'asc'];
        const next = new URL(window.location.href);
        const query = next.searchParams;

        if (searchInput.value.trim() !== '') {
            query.set('q', searchInput.value.trim());
        } else {
            query.delete('q');
        }
        query.set('start', String(info.start));
        query.set('length', String(info.length));
        query.set('order_index', String(order[0]));
        query.set('order_dir', String(order[1]));
        window.history.replaceState(null, '', next.pathname + '?' + query.toString() + next.hash);
    };

    const grid = $(tableElement).DataTable({
        ajax: {
            url: root.dataset.tableUrl,
            dataSrc: 'data',
            data: (request) => {
                request.search = { value: searchInput.value.trim() };
            },
        },
        serverSide: true,
        processing: true,
        searching: false,
        searchDelay: 300,
        displayStart: Number.isInteger(initialStart) && initialStart >= 0 ? initialStart : 0,
        pageLength: lengthValues.includes(initialLength) ? initialLength : 20,
        lengthMenu: [[20, 50, 100], [20, 50, 100]],
        order: [[Number.isInteger(initialOrderIndex) && initialOrderIndex >= 0 && initialOrderIndex <= 13 ? initialOrderIndex : 0, initialDirection]],
        dom: '<"dms-table-tools"B>rt<"dms-table-footer"ip>',
        buttons: [
            { extend: 'pageLength' },
            {
                text: '<i class="fas fa-sync" aria-hidden="true"></i>',
                titleAttr: 'REFRESH',
                action: (event, dataTable) => {
                    dataTable.ajax.reload(null, false);
                    if (!mapBlock.classList.contains('block-mode-hidden')) {
                        mapLoaded = false;
                        loadCountryMap();
                    }
                },
            },
        ],
        language: {
            emptyTable: 'No campaign visitor or lead records have been recorded.',
            zeroRecords: 'No visitor records match this search. Clear the search to see all records.',
            processing: 'Loading visitor records…',
        },
        columns: [
            {
                title: '#',
                data: 'id',
                render: (data, type) => type === 'display' ? escapeHtml(String(data || '').slice(0, 8)) : data,
            },
            {
                title: 'type',
                data: 'type',
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return data;
                    }
                    const label = escapeHtml(data);
                    return data === 'lead' ? label + ' |($' + Number(row.revenue || 0).toFixed(2) + ')' : label;
                },
            },
            { title: 'send id', data: 'send_id', defaultContent: '—', render: (data) => escapeHtml(data || '—') },
            {
                title: 'contact id',
                data: 'contact_id',
                render: (data, type) => type === 'display' ? escapeHtml(String(data || '').slice(0, 8)) : data,
            },
            {
                title: 'revision id',
                data: 'revision_id',
                render: (data, type) => type === 'display' ? escapeHtml(String(data || '').slice(0, 8)) : data,
            },
            { title: 'ip', data: 'ip', defaultContent: '—', render: (data) => escapeHtml(data || '—') },
            { title: 'country', data: 'country', defaultContent: '', render: (data) => escapeHtml(data || '') },
            { title: 'state', data: 'state', defaultContent: '', render: (data) => escapeHtml(data || '') },
            { title: 'city', data: 'city', defaultContent: '', render: (data) => escapeHtml(data || '') },
            { title: 'device', data: 'device', defaultContent: '', render: (data) => escapeHtml(data || '') },
            { title: 'platform', data: 'platform', defaultContent: '', render: (data) => escapeHtml(data || '') },
            { title: 'browser', data: 'browser', defaultContent: '', render: (data) => escapeHtml(data || '') },
            { title: 'is mobile', data: 'is_mobile', render: (data) => data === null ? '—' : (data ? 'true' : 'false') },
            { title: 'at', data: 'created_at', render: (data) => escapeHtml(data || '—') },
        ],
    });

    const runSearch = () => {
        window.clearTimeout(searchTimer);
        clearButton.disabled = searchInput.value === '';
        grid.ajax.reload(null, true);
    };

    searchInput.addEventListener('compositionstart', () => {
        isComposing = true;
        window.clearTimeout(searchTimer);
    });
    searchInput.addEventListener('compositionend', () => {
        isComposing = false;
        runSearch();
    });
    searchInput.addEventListener('input', () => {
        clearButton.disabled = searchInput.value === '';
        if (isComposing) {
            return;
        }
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(runSearch, 300);
    });
    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !isComposing && !event.isComposing) {
            event.preventDefault();
            runSearch();
        }
    });
    clearButton.addEventListener('click', () => {
        searchInput.value = '';
        runSearch();
        searchInput.focus();
    });

    mapToggle.addEventListener('click', () => {
        const isHidden = mapBlock.classList.toggle('block-mode-hidden');
        mapToggle.setAttribute('aria-expanded', String(!isHidden));
        mapToggle.innerHTML = '<i class="si si-arrow-' + (isHidden ? 'down' : 'up') + '" aria-hidden="true"></i>';
        if (!isHidden) {
            mapLoaded = false;
            loadCountryMap();
        }
    });

    grid.on('processing.dt', (event, settings, processing) => {
        tableElement.setAttribute('aria-busy', processing ? 'true' : 'false');
    });
    grid.on('draw.dt', () => {
        const info = grid.page.info();
        if (info.recordsDisplay > 0 && info.start >= info.recordsDisplay) {
            grid.page('last').draw('page');
            return;
        }
        resultCount.textContent = info.recordsDisplay === 0
            ? (searchInput.value.trim() ? 'No matching visitor records.' : 'No visitor records.')
            : 'Showing ' + (info.start + 1) + '–' + Math.min(info.end, info.recordsDisplay) + ' of ' + info.recordsDisplay + ' visitor records';
        errorMessage.hidden = true;
        saveUrlState(grid);
    });
    $(tableElement).on('error.dt', () => {
        errorMessage.hidden = false;
        errorMessage.textContent = 'Visitor records could not be loaded. Check the connection and refresh the table.';
    });

    const savedStart = params.get('start');
    if (savedStart !== null && Number(savedStart) !== initialStart) {
        saveUrlState(grid);
    }
})();
