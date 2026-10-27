(() => {
    const root = document.getElementById('drop-visitors');
    if (!root) {
        return;
    }

    const mapBlock = document.getElementById('drop-visitors-map-block');
    const mapElement = document.getElementById('drop-visitors-map');
    const mapMessage = document.getElementById('drop-visitors-map-message');
    const tableElement = document.getElementById('drop-visitors-table');
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);
    let chartLoading = false;
    let geoChartLoad = null;

    const withTimeout = (promise, message) => {
        let timeoutId;
        const timeout = new Promise((resolve, reject) => {
            timeoutId = setTimeout(() => reject(new Error(message)), 15000);
        });

        return Promise.race([promise, timeout]).finally(() => clearTimeout(timeoutId));
    };

    const loadGeoChart = async () => {
        if (!window.google?.charts?.load) {
            throw new Error('Google Charts did not load.');
        }

        if (!geoChartLoad) {
            geoChartLoad = withTimeout(
                window.google.charts.load('current', { packages: ['geochart'] }),
                'Google Charts timed out while loading.',
            ).then(() => {
                if (!window.google?.visualization?.GeoChart || !window.google?.visualization?.events?.addListener) {
                    throw new Error('Google GeoChart is unavailable.');
                }
            }).catch((error) => {
                geoChartLoad = null;
                throw error;
            });
        }

        await geoChartLoad;
    };

    const drawGeoChart = (rows) => new Promise((resolve, reject) => {
        const visualization = window.google.visualization;
        const data = visualization.arrayToDataTable([
            ['country', 'visitors', { type: 'string', label: 'actions', role: 'tooltip' }],
            ...rows.map((visitor) => [
                visitor.country,
                visitor.open,
                'Open: ' + visitor.open + ' - Click: ' + visitor.click + ' - Lead: ' + visitor.lead + ' - Unsubscribe: ' + visitor.unsubscribe,
            ]),
        ]);
        const chart = new visualization.GeoChart(mapElement);
        let settled = false;
        let timeoutId;
        const listeners = [];
        const finish = (error = null) => {
            if (settled) {
                return;
            }

            settled = true;
            clearTimeout(timeoutId);
            if (typeof visualization.events.removeListener === 'function') {
                listeners.forEach((listener) => visualization.events.removeListener(listener));
            }

            if (error) {
                reject(error);
            } else {
                resolve();
            }
        };

        try {
            listeners.push(visualization.events.addListener(chart, 'ready', () => finish()));
            listeners.push(visualization.events.addListener(chart, 'error', (event) => finish(new Error(event?.message || 'GeoChart could not render.'))));
            timeoutId = setTimeout(() => finish(new Error('GeoChart timed out while rendering.')), 15000);
            chart.draw(data, {});
        } catch (error) {
            finish(error);
        }
    });

    const drawChart = async () => {
        if (chartLoading) {
            return;
        }

        chartLoading = true;
        mapElement.setAttribute('aria-busy', 'true');
        mapMessage.hidden = false;
        mapMessage.textContent = 'Loading visitor locations…';
        try {
            const response = await fetch(root.dataset.chartUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const chartRows = await response.json();
            if (!Array.isArray(chartRows)) {
                throw new Error('Visitor location data was not an array.');
            }

            if (chartRows.length === 0) {
                mapElement.replaceChildren();
                mapMessage.textContent = 'No visitor events have been recorded for this drop.';
                return;
            }

            await loadGeoChart();
            await drawGeoChart(chartRows);
            mapMessage.hidden = true;
        } catch (error) {
            const diagnostic = error instanceof Error ? error.message : 'Unknown chart error.';
            console.warn('Drop visitor GeoChart failed:', diagnostic);
            mapMessage.textContent = 'Visitor locations could not be rendered. Refresh the table and try again.';
        } finally {
            mapElement.removeAttribute('aria-busy');
            chartLoading = false;
        }
    };

    if (!window.jQuery?.fn?.DataTable) {
        mapMessage.hidden = false;
        mapMessage.textContent = 'The visitor table could not start because its table assets did not load.';
        return;
    }

    const $ = window.jQuery;
    const grid = $(tableElement).DataTable({
        ajax: {
            url: root.dataset.tableUrl,
            dataSrc: 'data',
        },
        serverSide: true,
        processing: true,
        lengthMenu: [[20, 50, 100], [20, 50, 100]],
        order: [[0, 'asc']],
        dom: '<"dms-table-tools"B>frtip',
        buttons: [
            { extend: 'pageLength' },
            {
                text: '<i class="fas fa-sync" aria-hidden="true"></i>',
                titleAttr: 'REFRESH',
                action: (event, dataTable) => {
                    dataTable.ajax.reload(null, false);
                    if (!mapBlock.classList.contains('block-mode-hidden')) {
                        drawChart();
                    }
                },
            },
        ],
        columns: [
            { title: '#', data: 'id' },
            {
                title: 'type',
                data: 'type',
                render: (data, type, row) => {
                    const value = data === 'lead' ? data + ' |($' + escapeHtml(row.revenue) + ')' : escapeHtml(data);
                    return type === 'display' ? value : data;
                },
            },
            { title: 'email id', data: 'email_id' },
            {
                title: 'ip',
                data: 'ip',
                render: (data, type) => type === 'display'
                    ? '<a href="https://ip-api.com/#' + encodeURIComponent(data) + '" rel="noreferrer nofollow" target="_blank">' + escapeHtml(data) + '</a>'
                    : data,
            },
            { title: 'country', data: 'country', defaultContent: '' },
            { title: 'state', data: 'state', defaultContent: '' },
            { title: 'city', data: 'city', defaultContent: '' },
            { title: 'device', data: 'device', defaultContent: '' },
            { title: 'platform', data: 'platform', defaultContent: '' },
            { title: 'browser', data: 'browser', defaultContent: '' },
            { title: 'is mobile', data: 'is_mobile', render: (data) => data ? 'true' : 'false' },
            { title: 'at', data: 'created_at' },
        ],
    });

    const searchInput = document.querySelector('#drop-visitors-table_filter input');
    if (searchInput) {
        searchInput.setAttribute('aria-label', 'Search drop visitors');
        searchInput.setAttribute('placeholder', 'Search visitors...');
    }

    $(mapBlock).on('blockOpened', () => drawChart());
    $(tableElement).on('error.dt', () => {
        mapMessage.hidden = false;
        mapMessage.textContent = 'Visitor records could not be loaded. Check the connection and refresh the table.';
    });
})();
