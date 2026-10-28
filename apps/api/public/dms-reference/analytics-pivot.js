export function projectAllStatsRows(rows) {
    return rows.map((row) => ({
        at: row.at || 'NA',
        user: row.user || 'NA',
        isp: row.isp || 'NA',
        list: row.list || 'NA',
        list_type: row.list_type || 'NA',
        provider: row.provider || 'NA',
        server: row.server || 'NA',
        ip: row.ip || 'NA',
        domain: row.domain || 'NA',
        sponsor: row.sponsor || 'NA',
        offer: row.offer || 'NA',
        vertical: row.vertical || 'NA',
        from: row.from || 'NA',
        subject: row.subject || 'NA',
        creative: row.creative || 'NA',
        opens: Number(row.opens) || 0,
        clicks: Number(row.clicks) || 0,
        leads: Number(row.leads) || 0,
        unsubscribes: Number(row.unsubscribes) || 0,
        bots: Number(row.bots) || 0,
        deliveries: Number(row.deliveries) || 0,
        soft_bounces: Number(row.soft_bounces) || 0,
        hard_bounces: Number(row.hard_bounces) || 0,
        lead_revenue: Number(row.lead_revenue) || 0,
    }));
}

export function allStatsPivotFields() {
    const dimensions = [
        ['user', 'MAILER', 'row'],
        ['isp', 'ISP', 'filter'],
        ['list', 'LIST', 'filter'],
        ['list_type', 'LIST TYPE', undefined],
        ['provider', 'PROVIDER', 'filter'],
        ['server', 'SERVER', 'filter'],
        ['ip', 'IP', 'filter'],
        ['domain', 'DOMAIN', 'filter'],
        ['sponsor', 'SPONSOR', 'filter'],
        ['offer', 'OFFER', 'filter'],
        ['vertical', 'VERTICAL', undefined],
        ['from', 'FROM', undefined],
        ['subject', 'SUBJECT', undefined],
        ['creative', 'CREATIVE', undefined],
        ['at', 'DATE', 'filter'],
    ].map(([dataField, caption, area]) => ({
        dataField,
        caption,
        ...(area ? { area } : {}),
        ...(dataField === 'user' ? { width: 180, sortBySummaryField: 'lead_revenue', sortOrder: 'desc' } : {}),
        ...(dataField === 'at' ? { dataType: 'string' } : {}),
        sortOrder: 'desc',
    }));
    const measures = [
        ['opens', 'OPENS'],
        ['clicks', 'CLICKS'],
        ['leads', 'LEADS'],
        ['unsubscribes', 'UNSUBS'],
        ['bots', 'BOTS'],
        ['deliveries', 'DELIVERIES'],
        ['soft_bounces', 'SOFT BOUNCES'],
        ['hard_bounces', 'HARD BOUNCES'],
    ].map(([dataField, caption]) => ({
        dataField,
        caption,
        area: 'data',
        summaryType: 'sum',
        format: 'fixedPoint',
    }));

    return [
        ...dimensions,
        ...measures,
        {
            dataField: 'lead_revenue',
            caption: 'ATTRIBUTED LEAD REVENUE',
            area: 'data',
            summaryType: 'sum',
            dataType: 'number',
            format: { type: 'currency', precision: 2 },
        },
        {
            caption: 'CTOR',
            calculateSummaryValue(event) {
                const opens = event.value('opens');
                const clicks = event.value('clicks');
                const rate = opens > 0 ? (clicks / opens) * 100 : 0;

                return `${rate.toFixed(2)}%`;
            },
        },
        {
            caption: 'EPC',
            area: 'data',
            calculateSummaryValue(event) {
                const revenue = event.value('lead_revenue');
                const clicks = event.value('clicks');
                const earningsPerClick = clicks > 0 ? revenue / clicks : 0;

                return `$${earningsPerClick.toFixed(2)}`;
            },
        },
    ];
}

export function projectMailerRevenueRows(rows) {
    return rows.map((row) => ({
        user: String(row.user || 'Unknown'),
        opens: Number(row.opens) || 0,
        clicks: Number(row.clicks) || 0,
        leads: Number(row.leads) || 0,
        deliveries: Number(row.deliveries) || 0,
        soft_bounces: Number(row.soft_bounces) || 0,
        hard_bounces: Number(row.hard_bounces) || 0,
        unsubscribes: Number(row.unsubscribes) || 0,
        bots: Number(row.bots) || 0,
        lead_revenue: Number(row.lead_revenue) || 0,
    }));
}

export function mailerRevenuePivotFields() {
    return [
        {
            dataField: 'user',
            caption: 'MAILER',
            area: 'row',
            width: 180,
            sortBySummaryField: 'lead_revenue',
            sortOrder: 'desc',
        },
        ...[
            ['opens', 'OPENS'],
            ['clicks', 'CLICKS'],
            ['leads', 'LEADS'],
            ['deliveries', 'DELIVERIES'],
            ['soft_bounces', 'SOFT BOUNCES'],
            ['hard_bounces', 'HARD BOUNCES'],
            ['unsubscribes', 'UNSUBS'],
            ['bots', 'BOTS'],
        ].map(([dataField, caption]) => ({
            dataField,
            caption,
            area: 'data',
            summaryType: 'sum',
            format: 'fixedPoint',
        })),
        {
            dataField: 'lead_revenue',
            caption: 'ATTRIBUTED LEAD REVENUE',
            area: 'data',
            summaryType: 'sum',
            dataType: 'number',
            format: { type: 'currency', precision: 2 },
        },
        {
            caption: 'CTOR',
            calculateSummaryValue(event) {
                const opens = event.value('opens');
                const clicks = event.value('clicks');
                const rate = opens > 0 ? (clicks / opens) * 100 : 0;

                return `${rate.toFixed(2)}%`;
            },
        },
        {
            caption: 'EPC',
            area: 'data',
            calculateSummaryValue(event) {
                const revenue = event.value('lead_revenue');
                const clicks = event.value('clicks');
                const earningsPerClick = clicks > 0 ? revenue / clicks : 0;

                return `$${earningsPerClick.toFixed(2)}`;
            },
        },
    ];
}

export function projectCampaignStatisticsRows(rows) {
    return rows.map((row) => ({
        at: String(row.at || 'NA'),
        user: String(row.user || 'NA'),
        campaign_id: String(row.campaign_id || 'NA'),
        isp: String(row.isp || 'NA'),
        list: String(row.list || 'NA'),
        list_type: String(row.list_type || 'NA'),
        provider: String(row.provider || 'NA'),
        server: String(row.server || 'NA'),
        ip: String(row.ip || 'NA'),
        domain: String(row.domain || 'NA'),
        sponsor: String(row.sponsor || 'NA'),
        offer: String(row.offer || 'NA'),
        vertical: String(row.vertical || 'NA'),
        from: String(row.from || 'NA'),
        subject: String(row.subject || 'NA'),
        creative: String(row.creative || 'NA'),
        deliveries: Number(row.deliveries) || 0,
        soft_bounces: Number(row.soft_bounces) || 0,
        hard_bounces: Number(row.hard_bounces) || 0,
        opens: Number(row.opens) || 0,
        clicks: Number(row.clicks) || 0,
        leads: Number(row.leads) || 0,
        conversions: Number(row.conversions) || 0,
        unsubscribes: Number(row.unsubscribes) || 0,
        bots: Number(row.bots) || 0,
        lead_revenue: Number(row.lead_revenue) || 0,
    }));
}

export function campaignStatisticsPivotFields() {
    const dimensions = [
        ['user', 'MAILER', 'row'],
        ['campaign_id', 'CAMPAIGN', 'filter'],
        ['isp', 'ISP', 'filter'],
        ['list', 'LIST', 'filter'],
        ['list_type', 'LIST TYPE', undefined],
        ['provider', 'PROVIDER', 'filter'],
        ['server', 'SERVER', 'filter'],
        ['ip', 'IP', 'filter'],
        ['domain', 'DOMAIN', 'filter'],
        ['sponsor', 'SPONSOR', 'filter'],
        ['offer', 'OFFER', 'filter'],
        ['vertical', 'VERTICAL', undefined],
        ['from', 'FROM', undefined],
        ['subject', 'SUBJECT', undefined],
        ['creative', 'CREATIVE', undefined],
        ['at', 'DATE', 'filter'],
    ].map(([dataField, caption, area]) => ({
        dataField,
        caption,
        ...(area ? { area } : {}),
        ...(dataField === 'user' ? { width: 180, sortBySummaryField: 'lead_revenue', sortOrder: 'desc' } : {}),
        ...(dataField === 'at' ? { dataType: 'string' } : {}),
        sortOrder: 'desc',
    }));
    const measures = [
        ['opens', 'OPENS'],
        ['clicks', 'CLICKS'],
        ['leads', 'LEADS'],
        ['conversions', 'CONVERSIONS'],
        ['deliveries', 'DELIVERIES'],
        ['soft_bounces', 'SOFT BOUNCES'],
        ['hard_bounces', 'HARD BOUNCES'],
        ['unsubscribes', 'UNSUBS'],
        ['bots', 'BOTS'],
    ].map(([dataField, caption]) => ({
        dataField,
        caption,
        area: 'data',
        summaryType: 'sum',
        format: 'fixedPoint',
    }));

    return [
        ...dimensions,
        ...measures,
        {
            dataField: 'lead_revenue',
            caption: 'ATTRIBUTED LEAD REVENUE',
            area: 'data',
            summaryType: 'sum',
            dataType: 'number',
            format: { type: 'currency', precision: 2 },
        },
        {
            caption: 'CTOR',
            calculateSummaryValue(event) {
                const opens = event.value('opens');
                const clicks = event.value('clicks');
                const rate = opens > 0 ? (clicks / opens) * 100 : 0;

                return `${rate.toFixed(2)}%`;
            },
        },
        {
            caption: 'EPC',
            area: 'data',
            calculateSummaryValue(event) {
                const revenue = event.value('lead_revenue');
                const clicks = event.value('clicks');
                const earningsPerClick = clicks > 0 ? revenue / clicks : 0;

                return `$${earningsPerClick.toFixed(2)}`;
            },
        },
    ];
}

function initializeAllStatsPivot() {
    const shell = document.getElementById('all-stats-pivot-shell');
    const form = document.querySelector('[data-analytics-date-range-form][data-storage-key="all_stats"]');
    const grid = document.getElementById('all-stats-pivot');
    const fallback = document.getElementById('all-stats-fallback');
    const status = document.getElementById('all-stats-pivot-status');
    const reloadButton = document.getElementById('all-stats-pivot-reload');
    const fullscreenButton = document.getElementById('all-stats-pivot-fullscreen');

    if (!shell || !form || !grid || !status) {
        return;
    }

    const setStatus = (message, tone = 'muted') => {
        status.textContent = message;
        status.className = `small mb-2 text-${tone}`;
        status.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
    };

    if (!window.jQuery?.fn?.dxPivotGrid || !window.DevExpress?.data?.PivotGridDataSource) {
        shell.hidden = false;
        setStatus('The interactive pivot library did not load. The server table below remains available.', 'danger');
        reloadButton?.addEventListener('click', () => window.location.reload());
        fullscreenButton?.addEventListener('click', () => {
            const active = shell.classList.toggle('analytics-pivot-fullscreen');
            document.body.classList.toggle('analytics-pivot-no-scroll', active);
            fullscreenButton.setAttribute('aria-pressed', String(active));
            fullscreenButton.setAttribute('aria-label', active ? 'Exit full screen' : 'Enter full screen');
            fullscreenButton.setAttribute('title', active ? 'Exit full screen' : 'Full screen');
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && shell.classList.contains('analytics-pivot-fullscreen')) {
                shell.classList.remove('analytics-pivot-fullscreen');
                document.body.classList.remove('analytics-pivot-no-scroll');
                fullscreenButton?.setAttribute('aria-pressed', 'false');
                fullscreenButton?.setAttribute('aria-label', 'Enter full screen');
                fullscreenButton?.setAttribute('title', 'Full screen');
            }
        });
        return;
    }

    const requestRows = async () => {
        const from = form.elements.namedItem('from')?.value;
        const to = form.elements.namedItem('to')?.value;
        if (!from || !to || from > to) {
            throw new Error('Choose a valid start and end date.');
        }

        const url = new URL(shell.dataset.url, window.location.origin);
        url.searchParams.set('from', from);
        url.searchParams.set('to', to);
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) {
            throw new Error('The All Stats data could not be loaded. The table fallback is still available.');
        }

        const result = await response.json();
        if (!Array.isArray(result)) {
            throw new Error('The All Stats response was not a report row list. The table fallback is still available.');
        }

        const rows = projectAllStatsRows(result);
        shell.hidden = false;
        if (fallback) {
            fallback.hidden = true;
        }
        setStatus(rows.length ? `${rows.length.toLocaleString()} report rows loaded.` : 'No matching campaign, Drop, or attributed lead rows for this range.');

        return rows;
    };

    const dataSource = new window.DevExpress.data.PivotGridDataSource({
        remoteOperations: false,
        fields: allStatsPivotFields(),
        load: async () => {
            setStatus('Loading All Stats…');
            try {
                return await requestRows();
            } catch (error) {
                setStatus(error instanceof Error ? error.message : 'The All Stats data could not be loaded.', 'danger');
                throw error;
            }
        },
    });

    shell.hidden = false;
    window.jQuery(grid).dxPivotGrid({
        rowHeaderLayout: 'tree',
        allowSortingBySummary: true,
        showColumnGrandTotals: true,
        allowFiltering: true,
        allowSorting: true,
        fieldChooser: { enabled: true },
        fieldPanel: {
            visible: true,
            showDataFields: false,
            showColumnFields: true,
            showRowFields: true,
        },
        stateStoring: {
            enabled: true,
            type: 'sessionStorage',
            storageKey: 'all_statistics_states',
        },
        onCellPrepared(event) {
            const field = event.component.getDataSource().getAreaFields(event.area)
                .find((item) => item.areaIndex === event.cell.dataIndex);
            if (!field?.caption) {
                return;
            }

            const colors = {
                OPENS: '#52b8ff',
                CLICKS: '#006100',
                LEADS: '#0b54f1',
                UNSUBS: '#ff0006',
                BOTS: '#fd950c',
                'ATTRIBUTED LEAD REVENUE': '#0b54f1',
            };
            const color = colors[field.caption];
            if (color) {
                Object.assign(event.cellElement.get(0).style, { color, fontWeight: 'bold' });
            }
        },
        onDataErrorOccurred() {
            setStatus('The All Stats request failed. Retry Reload; the server table fallback remains available.', 'danger');
        },
        dataSource,
    });

    const refresh = () => {
        const result = dataSource.reload();
        if (result && typeof result.catch === 'function') {
            result.catch(() => {});
        }
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        refresh();
    }, true);
    reloadButton?.addEventListener('click', refresh);

    const updateFullscreenControl = () => {
        const active = document.fullscreenElement === shell || shell.classList.contains('analytics-pivot-fullscreen');
        fullscreenButton?.setAttribute('aria-pressed', String(active));
        fullscreenButton?.setAttribute('aria-label', active ? 'Exit full screen' : 'Enter full screen');
        fullscreenButton?.setAttribute('title', active ? 'Exit full screen' : 'Full screen');
    };
    const exitFallbackFullscreen = () => {
        shell.classList.remove('analytics-pivot-fullscreen');
        document.body.classList.remove('analytics-pivot-no-scroll');
        updateFullscreenControl();
    };

    fullscreenButton?.addEventListener('click', async () => {
        try {
            if (document.fullscreenElement === shell) {
                await document.exitFullscreen();
            } else if (document.fullscreenElement) {
                await document.exitFullscreen();
                await shell.requestFullscreen();
            } else if (shell.requestFullscreen) {
                await shell.requestFullscreen();
            } else {
                shell.classList.add('analytics-pivot-fullscreen');
                document.body.classList.add('analytics-pivot-no-scroll');
                updateFullscreenControl();
            }
        } catch {
            shell.classList.toggle('analytics-pivot-fullscreen');
            document.body.classList.toggle('analytics-pivot-no-scroll');
            updateFullscreenControl();
        }
    });
    document.addEventListener('fullscreenchange', updateFullscreenControl);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && shell.classList.contains('analytics-pivot-fullscreen')) {
            exitFallbackFullscreen();
        }
    });

    window.setInterval(refresh, 15 * 60 * 1000);
}

function initializeMailerRevenuePivot() {
    const shell = document.getElementById('mailer-revenue-pivot-shell');
    const form = document.querySelector('[data-analytics-date-range-form][data-storage-key="mailers_stats"]');
    const grid = document.getElementById('mailer-revenue-pivot');
    const fallback = document.getElementById('mailer-revenue-fallback');
    const status = document.getElementById('mailer-revenue-pivot-status');
    const reloadButton = document.getElementById('mailer-revenue-pivot-reload');

    if (!shell || !form || !grid || !status) {
        return;
    }

    const setStatus = (message, tone = 'muted') => {
        status.textContent = message;
        status.className = `small mb-2 text-${tone}`;
        status.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
    };

    if (!window.jQuery?.fn?.dxPivotGrid || !window.DevExpress?.data?.PivotGridDataSource) {
        shell.hidden = false;
        grid.hidden = true;
        setStatus('The interactive pivot library did not load. The report table below remains available.', 'danger');
        reloadButton?.addEventListener('click', () => window.location.reload());
        return;
    }

    let activeRequest = null;
    let requestVersion = 0;
    let lastRows = [];
    let lastLoadError = null;

    const requestRows = async () => {
        const from = form.elements.namedItem('from')?.value;
        const to = form.elements.namedItem('to')?.value;
        if (!from || !to || from > to) {
            throw new Error('Choose a valid start and end date.');
        }

        const url = new URL(shell.dataset.url, window.location.origin);
        url.searchParams.set('from', from);
        url.searchParams.set('to', to);
        const version = ++requestVersion;
        activeRequest?.abort();
        activeRequest = new AbortController();
        const request = activeRequest;
        shell.setAttribute('aria-busy', 'true');

        try {
            const response = await window.fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: request.signal,
            });
            if (version !== requestVersion) {
                return lastRows;
            }
            if (response.status === 401 || response.redirected) {
                throw new Error('Your session may have expired. Sign in again before viewing this report.');
            }
            if (response.status === 403 || response.status === 404) {
                throw new Error('You no longer have access to this report. Ask a workspace owner.');
            }
            if (!response.ok) {
                throw new Error('Mailer Revenue data could not be loaded. The report table remains available.');
            }

            const result = await response.json();
            if (version !== requestVersion) {
                return lastRows;
            }
            if (!Array.isArray(result)) {
                throw new Error('Mailer Revenue returned an invalid report. The report table remains available.');
            }

            lastRows = projectMailerRevenueRows(result);
            lastLoadError = null;
            grid.hidden = false;
            if (fallback) {
                fallback.hidden = true;
            }
            setStatus(lastRows.length ? `${lastRows.length.toLocaleString()} mailer rows loaded.` : 'No mailer activity or attributed lead revenue is recorded for this date range.');

            return lastRows;
        } catch (error) {
            if (error?.name === 'AbortError' || version !== requestVersion) {
                return lastRows;
            }

            lastLoadError = error instanceof Error ? error.message : 'Mailer Revenue data could not be loaded.';
            throw error;
        } finally {
            if (version === requestVersion) {
                activeRequest = null;
                shell.setAttribute('aria-busy', 'false');
            }
        }
    };

    const dataSource = new window.DevExpress.data.PivotGridDataSource({
        remoteOperations: false,
        fields: mailerRevenuePivotFields(),
        load: async () => {
            setStatus('Loading Mailer Revenue…');
            try {
                return await requestRows();
            } catch (error) {
                grid.hidden = true;
                if (fallback) {
                    fallback.hidden = false;
                }
                setStatus(lastLoadError || (error instanceof Error ? error.message : 'Mailer Revenue data could not be loaded.'), 'danger');
                throw error;
            }
        },
    });

    shell.hidden = false;
    window.jQuery(grid).dxPivotGrid({
        rowHeaderLayout: 'tree',
        allowSortingBySummary: true,
        showColumnGrandTotals: true,
        allowFiltering: true,
        allowSorting: true,
        fieldChooser: { enabled: true },
        fieldPanel: {
            visible: true,
            showDataFields: false,
            showColumnFields: true,
            showRowFields: true,
        },
        stateStoring: {
            enabled: true,
            type: 'sessionStorage',
            storageKey: 'mailers_statistics_states',
        },
        onCellPrepared(event) {
            const field = event.component.getDataSource().getAreaFields(event.area)
                .find((item) => item.areaIndex === event.cell.dataIndex);
            if (!field?.caption) {
                return;
            }

            const colors = {
                OPENS: '#52b8ff',
                CLICKS: '#006100',
                LEADS: '#0b54f1',
                UNSUBS: '#ff0006',
                BOTS: '#fd950c',
                'ATTRIBUTED LEAD REVENUE': '#0b54f1',
            };
            const color = colors[field.caption];
            if (color) {
                Object.assign(event.cellElement.get(0).style, { color, fontWeight: 'bold' });
            }
        },
        onDataErrorOccurred() {
            grid.hidden = true;
            if (fallback) {
                fallback.hidden = false;
            }
            setStatus(lastLoadError || 'The Mailer Revenue request failed. Retry Reload; the report table remains available.', 'danger');
            lastLoadError = null;
        },
        dataSource,
    });

    const refresh = () => {
        const result = dataSource.reload();
        if (result && typeof result.catch === 'function') {
            result.catch(() => {});
        }
    };

    reloadButton?.addEventListener('click', refresh);
    document.addEventListener('analytics-report-fragment-updated', refresh);
    window.setInterval(refresh, 15 * 60 * 1000);
}

function initializeCampaignStatisticsPivot() {
    const shell = document.getElementById('campaign-statistics-pivot-shell');
    const form = document.querySelector('[data-analytics-date-range-form][data-storage-key="c_stats"]');
    const grid = document.getElementById('campaign-statistics-pivot');
    const fallback = document.getElementById('campaign-statistics-fallback');
    const status = document.getElementById('campaign-statistics-pivot-status');
    const reloadButton = document.getElementById('campaign-statistics-pivot-reload');

    if (!shell || !form || !grid || !status) {
        return;
    }

    const setStatus = (message, tone = 'muted') => {
        status.textContent = message;
        status.className = `small mb-2 text-${tone}`;
        status.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
    };

    if (!window.jQuery?.fn?.dxPivotGrid || !window.DevExpress?.data?.PivotGridDataSource) {
        shell.hidden = false;
        grid.hidden = true;
        setStatus('The interactive pivot library did not load. The report table below remains available.', 'danger');
        reloadButton?.addEventListener('click', () => window.location.reload());
        return;
    }

    let activeRequest = null;
    let requestVersion = 0;
    let lastRows = [];
    let lastLoadError = null;

    const requestRows = async () => {
        const url = new URL(shell.dataset.url, window.location.origin);
        for (const name of ['from', 'to', 'campaign_id', 'isp_id', 'list_id']) {
            const value = form.elements.namedItem(name)?.value;
            if (value) {
                url.searchParams.set(name, value);
            }
        }
        if (!url.searchParams.get('from') || !url.searchParams.get('to')) {
            throw new Error('Choose a valid campaign statistics date range.');
        }

        const version = ++requestVersion;
        activeRequest?.abort();
        activeRequest = new AbortController();
        const request = activeRequest;
        shell.setAttribute('aria-busy', 'true');

        try {
            const response = await window.fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: request.signal,
            });
            if (version !== requestVersion) {
                return lastRows;
            }
            if (response.status === 401 || response.redirected) {
                throw new Error('Your session may have expired. Sign in again before viewing this report.');
            }
            if (response.status === 403 || response.status === 404) {
                throw new Error('You no longer have access to this report. Ask a workspace owner.');
            }
            if (!response.ok) {
                throw new Error('Campaign Statistics data could not be loaded. The report table remains available.');
            }

            const result = await response.json();
            if (version !== requestVersion) {
                return lastRows;
            }
            if (!Array.isArray(result)) {
                throw new Error('Campaign Statistics returned an invalid report. The report table remains available.');
            }

            lastRows = projectCampaignStatisticsRows(result);
            lastLoadError = null;
            grid.hidden = false;
            if (fallback) {
                fallback.hidden = true;
            }
            setStatus(lastRows.length ? `${lastRows.length.toLocaleString()} campaign statistics rows loaded.` : 'No campaign activity or attributed leads are recorded for this date range.');

            return lastRows;
        } catch (error) {
            if (error?.name === 'AbortError' || version !== requestVersion) {
                return lastRows;
            }

            lastLoadError = error instanceof Error ? error.message : 'Campaign Statistics data could not be loaded.';
            throw error;
        } finally {
            if (version === requestVersion) {
                activeRequest = null;
                shell.setAttribute('aria-busy', 'false');
            }
        }
    };

    const dataSource = new window.DevExpress.data.PivotGridDataSource({
        remoteOperations: false,
        fields: campaignStatisticsPivotFields(),
        load: async () => {
            setStatus('Loading Campaign Statistics…');
            try {
                return await requestRows();
            } catch (error) {
                grid.hidden = true;
                if (fallback) {
                    fallback.hidden = false;
                }
                setStatus(lastLoadError || (error instanceof Error ? error.message : 'Campaign Statistics data could not be loaded.'), 'danger');
                throw error;
            }
        },
    });

    shell.hidden = false;
    window.jQuery(grid).dxPivotGrid({
        rowHeaderLayout: 'tree',
        allowSortingBySummary: true,
        showColumnGrandTotals: true,
        allowFiltering: true,
        allowSorting: true,
        fieldChooser: { enabled: true },
        fieldPanel: {
            visible: true,
            showDataFields: false,
            showColumnFields: true,
            showRowFields: true,
        },
        stateStoring: {
            enabled: true,
            type: 'sessionStorage',
            storageKey: 'campaign_statistics_states',
        },
        onCellPrepared(event) {
            const field = event.component.getDataSource().getAreaFields(event.area)
                .find((item) => item.areaIndex === event.cell.dataIndex);
            if (!field?.caption) {
                return;
            }

            const colors = {
                OPENS: '#52b8ff',
                CLICKS: '#006100',
                LEADS: '#0b54f1',
                UNSUBS: '#ff0006',
                BOTS: '#fd950c',
                'ATTRIBUTED LEAD REVENUE': '#0b54f1',
            };
            const color = colors[field.caption];
            if (color) {
                Object.assign(event.cellElement.get(0).style, { color, fontWeight: 'bold' });
            }
        },
        onDataErrorOccurred() {
            grid.hidden = true;
            if (fallback) {
                fallback.hidden = false;
            }
            setStatus(lastLoadError || 'The Campaign Statistics request failed. Retry Reload; the report table remains available.', 'danger');
            lastLoadError = null;
        },
        dataSource,
    });

    const refresh = () => {
        const result = dataSource.reload();
        if (result && typeof result.catch === 'function') {
            result.catch(() => {});
        }
    };

    reloadButton?.addEventListener('click', refresh);
    document.addEventListener('analytics-report-fragment-updated', refresh);
    window.setInterval(refresh, 15 * 60 * 1000);
}

if (typeof document !== 'undefined') {
    initializeAllStatsPivot();
    initializeMailerRevenuePivot();
    initializeCampaignStatisticsPivot();
}
