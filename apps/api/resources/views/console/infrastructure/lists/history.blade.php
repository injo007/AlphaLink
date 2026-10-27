@extends('layouts.dms-admin')

@section('title', 'List History')

@push('styles')
    <style>
        .list-history-controls label { display: block; }
        .list-history-chart { min-height: 400px; }
        .list-history-chart svg { display: block; width: 100%; height: 400px; }
        .list-history-chart .history-grid { stroke: #e3e6e9; stroke-width: 1; }
        .list-history-chart .history-axis-label { fill: #697782; font-size: 12px; }
        .list-history-chart .history-series { fill: none; stroke-width: 2; vector-effect: non-scaling-stroke; }
        .list-history-chart .history-point { stroke: #fff; stroke-width: 1.5; vector-effect: non-scaling-stroke; }
        .list-history-legend { display: flex; flex-wrap: wrap; gap: .5rem 1rem; padding: 0; list-style: none; }
        .list-history-legend li { display: inline-flex; align-items: center; gap: .4rem; font-size: 12px; }
        .list-history-legend-swatch { width: 1rem; height: 3px; }
        .list-history-unknown { color: #697782; font-style: italic; }
        @media (max-width: 575.98px) {
            .list-history-chart, .list-history-chart svg { min-height: 300px; height: 300px; }
        }
    </style>
@endpush

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">List History</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle list history full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <form id="list-history-filter" class="row list-history-controls" novalidate
                data-lists-url="{{ url('/app/workspaces/'.$workspace->id.'/infrastructure/list-history/isps') }}"
                data-history-url="{{ route('console.infrastructure.list-history.data', $workspace->id) }}">
                <div class="form-group col-sm-12 col-md-4">
                    <label for="history-isp">ISPs:</label>
                    <select id="history-isp" class="form-control selectpicker" data-live-search="true" title="Select ISP" data-allow-clear="true" required>
                        @foreach ($isps as $isp)
                            <option value="{{ $isp->id }}">{{ $isp->name }}</option>
                        @endforeach
                    </select>
                    <small id="history-isp-error" class="text-danger d-none"></small>
                </div>
                <div class="form-group col-sm-12 col-md-4">
                    <label for="history-list">Lists:</label>
                    <select id="history-list" class="form-control selectpicker" data-live-search="true" title="Select list" data-allow-clear="true" required disabled aria-describedby="history-list-help history-list-error">
                        <option value="" selected>Select an ISP first</option>
                    </select>
                    <small id="history-list-help" class="form-text text-muted">Choose one list or ALL LISTS for the selected ISP.</small>
                    <small id="history-list-error" class="text-danger d-none"></small>
                </div>
                <div class="form-group col-sm-12 col-md-2">
                    <label for="history-from">Date from:</label>
                    <input id="history-from" type="date" class="form-control" value="{{ $from }}" min="{{ $minDate }}" max="{{ $maxDate }}" required aria-describedby="history-date-error">
                </div>
                <div class="form-group col-sm-12 col-md-2">
                    <label for="history-to">Date to:</label>
                    <input id="history-to" type="date" class="form-control" value="{{ $to }}" min="{{ $minDate }}" max="{{ $maxDate }}" required aria-describedby="history-date-error">
                </div>
                <div class="form-group col-sm-12 d-flex flex-wrap align-items-center justify-content-between">
                    <small id="history-date-error" class="text-danger d-none" role="alert"></small>
                    <div id="history-form-status" class="text-muted" role="status" aria-live="polite"></div>
                    <button id="load-history" type="submit" class="btn btn-alt-primary ml-auto">LOAD HISTORY</button>
                </div>
            </form>
            @if ($isps->isEmpty())
                <div class="alert alert-info mb-3" role="status">Add an active ISP before viewing list history.</div>
            @endif
            <div id="history-result" class="d-none" aria-live="polite">
                <div id="history-unknown-note" class="alert alert-info d-none" role="status">Older history rows can show NA for recipient types until their daily snapshot is recalculated.</div>
                <div class="table-responsive">
                    <table id="history-table" class="table table-bordered table-striped table-vcenter table-sm">
                        <caption id="history-caption" class="text-left"></caption>
                        <thead><tr></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p id="history-empty" class="alert alert-info d-none" role="status">No list history is available for this date range.</p>
                <ul id="history-legend" class="list-history-legend" aria-label="History chart series"></ul>
                <div id="history-chart" class="list-history-chart" aria-label="Daily list history chart"></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const filter = document.querySelector('#list-history-filter');
            const isp = document.querySelector('#history-isp');
            const list = document.querySelector('#history-list');
            const from = document.querySelector('#history-from');
            const to = document.querySelector('#history-to');
            const button = document.querySelector('#load-history');
            const status = document.querySelector('#history-form-status');
            const result = document.querySelector('#history-result');
            const table = document.querySelector('#history-table');
            const types = [
                ['fresh', 'FRESH', '#33d4d1'], ['delivery', 'DELIVERY', '#d95456'],
                ['open', 'OPEN', '#6a87d3'], ['click', 'CLICK', '#0dee91'],
                ['lead', 'LEAD', '#004bff'], ['unsubscribe', 'UNSUBSCRIBE', '#999340'],
                ['opt_out', 'OPTOUT', '#cb6432'], ['bot', 'BOT', '#e7105d'],
                ['proxy', 'PROXY', '#e867e8'], ['blacklist', 'BLACKLIST', '#a2a5ab'],
                ['hard_bounce', 'HARD BOUNCE', '#282831'],
            ];
            let listController = null;
            let historyController = null;
            let listRequestId = 0;
            let historyRequestId = 0;

            const refreshSelect = (element) => {
                if (window.jQuery?.fn?.selectpicker) window.jQuery(element).selectpicker('refresh');
            };
            const setError = (id, message) => {
                const element = document.querySelector(id);
                element.textContent = message || '';
                element.classList.toggle('d-none', !message);
            };
            const clearResult = () => {
                result.classList.add('d-none');
                document.querySelector('#history-table tbody').replaceChildren();
                document.querySelector('#history-table thead tr').replaceChildren();
                document.querySelector('#history-legend').replaceChildren();
                document.querySelector('#history-chart').replaceChildren();
                document.querySelector('#history-empty').classList.add('d-none');
                document.querySelector('#history-unknown-note').classList.add('d-none');
            };
            const readJson = async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const errors = Object.values(payload.errors || {}).flat();
                    throw new Error(errors[0] || payload.message || 'List history could not be loaded. Try again.');
                }
                return payload;
            };

            if (window.jQuery?.fn?.selectpicker) {
                window.jQuery(isp).selectpicker();
                window.jQuery(list).selectpicker();
            }
            document.querySelector('#history-from').addEventListener('change', () => setError('#history-date-error', ''));
            document.querySelector('#history-to').addEventListener('change', () => setError('#history-date-error', ''));

            isp.addEventListener('change', async () => {
                listController?.abort();
                historyController?.abort();
                clearResult();
                setError('#history-isp-error', '');
                setError('#history-list-error', '');
                status.textContent = '';
                list.replaceChildren(new Option('Loading lists…', ''));
                list.disabled = true;
                refreshSelect(list);
                const selectedIsp = isp.value;
                if (!selectedIsp) {
                    list.replaceChildren(new Option('Select an ISP first', ''));
                    refreshSelect(list);
                    return;
                }

                const requestId = ++listRequestId;
                listController = new AbortController();
                status.textContent = 'Loading lists…';
                try {
                    const response = await fetch(`${filter.dataset.listsUrl}/${encodeURIComponent(selectedIsp)}/lists`, {
                        headers: { 'Accept': 'application/json' }, signal: listController.signal,
                    });
                    const payload = await readJson(response);
                    if (requestId !== listRequestId || selectedIsp !== isp.value) return;
                    list.replaceChildren(new Option('Select list', ''), new Option('ALL LISTS', '0'));
                    payload.data.forEach((item) => list.add(new Option(item.name, item.id)));
                    list.disabled = false;
                    status.textContent = '';
                    refreshSelect(list);
                } catch (error) {
                    if (error.name === 'AbortError') return;
                    if (requestId !== listRequestId) return;
                    list.replaceChildren(new Option('Lists could not be loaded', ''));
                    status.textContent = error.message;
                    refreshSelect(list);
                }
            });

            const appendCell = (row, value, header = false) => {
                const cell = document.createElement(header ? 'th' : 'td');
                if (header) cell.scope = 'col';
                cell.textContent = String(value ?? 'NA');
                row.append(cell);
            };

            const renderTable = (payload) => {
                const header = document.querySelector('#history-table thead tr');
                const body = document.querySelector('#history-table tbody');
                header.replaceChildren();
                body.replaceChildren();
                appendCell(header, 'DATE', true);
                types.forEach(([, label]) => appendCell(header, label, true));
                const isAllLists = String(payload.scope) === 'all_lists';
                document.querySelector('#history-caption').textContent = isAllLists
                    ? `History: ${payload.list.name} · All lists`
                    : `History: ${payload.list.name}`;
                payload.histories.forEach((history) => {
                    const row = document.createElement('tr');
                    appendCell(row, history.at);
                    types.forEach(([key]) => {
                        const cell = document.createElement('td');
                        const value = history[key] ?? 'NA';
                        cell.textContent = String(value);
                        if (value === 'NA') cell.classList.add('list-history-unknown');
                        row.append(cell);
                    });
                    body.append(row);
                });
                document.querySelector('#history-empty').classList.toggle('d-none', payload.histories.length > 0);
                const hasUnknown = payload.histories.some((history) => types.some(([key]) => history[key] === 'NA'));
                document.querySelector('#history-unknown-note').classList.toggle('d-none', !hasUnknown);
            };

            const renderChart = (payload) => {
                const legend = document.querySelector('#history-legend');
                const chart = document.querySelector('#history-chart');
                legend.replaceChildren();
                chart.replaceChildren();
                if (payload.histories.length === 0) return;

                const namespace = 'http://www.w3.org/2000/svg';
                const width = 960;
                const height = 380;
                const left = 56;
                const right = 18;
                const top = 18;
                const bottom = 44;
                const plotWidth = width - left - right;
                const plotHeight = height - top - bottom;
                const knownValues = payload.histories.flatMap((entry) => types
                    .map(([key]) => entry[key]).filter((value) => value !== 'NA' && Number.isFinite(Number(value))).map(Number));
                const maxValue = Math.max(1, ...knownValues);
                const svg = document.createElementNS(namespace, 'svg');
                svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
                svg.setAttribute('role', 'img');
                svg.setAttribute('aria-labelledby', 'history-chart-title history-chart-description');
                const title = document.createElementNS(namespace, 'title');
                title.id = 'history-chart-title';
                title.textContent = `${payload.list.name} list history`;
                const description = document.createElementNS(namespace, 'desc');
                description.id = 'history-chart-description';
                description.textContent = `Daily recipient counts from ${payload.histories[0].at} to ${payload.histories[payload.histories.length - 1].at}. Unsupported DMS recipient types have no plotted values.`;
                svg.append(title, description);

                [0, .25, .5, .75, 1].forEach((fraction) => {
                    const y = top + plotHeight * fraction;
                    const line = document.createElementNS(namespace, 'line');
                    line.setAttribute('x1', left);
                    line.setAttribute('x2', width - right);
                    line.setAttribute('y1', y);
                    line.setAttribute('y2', y);
                    line.setAttribute('class', 'history-grid');
                    svg.append(line);
                    const label = document.createElementNS(namespace, 'text');
                    label.setAttribute('x', left - 8);
                    label.setAttribute('y', y + 4);
                    label.setAttribute('text-anchor', 'end');
                    label.setAttribute('class', 'history-axis-label');
                    label.textContent = Math.round(maxValue * (1 - fraction)).toLocaleString();
                    svg.append(label);
                });

                const dateIndexes = [...new Set([0, Math.floor((payload.histories.length - 1) / 2), payload.histories.length - 1])];
                dateIndexes.forEach((index) => {
                    const x = left + (payload.histories.length === 1 ? plotWidth / 2 : plotWidth * index / (payload.histories.length - 1));
                    const label = document.createElementNS(namespace, 'text');
                    label.setAttribute('x', x);
                    label.setAttribute('y', height - 12);
                    label.setAttribute('text-anchor', index === 0 ? 'start' : (index === payload.histories.length - 1 ? 'end' : 'middle'));
                    label.setAttribute('class', 'history-axis-label');
                    label.textContent = payload.histories[index].at;
                    svg.append(label);
                });

                types.forEach(([key, label, color]) => {
                    const item = document.createElement('li');
                    const swatch = document.createElement('span');
                    swatch.className = 'list-history-legend-swatch';
                    swatch.style.backgroundColor = color;
                    swatch.setAttribute('aria-hidden', 'true');
                    const series = payload.histories.map((entry) => entry[key]);
                    const available = series.some((value) => value !== 'NA' && Number.isFinite(Number(value)));
                    const name = document.createElement('span');
                    name.textContent = available ? label : `${label} (NA)`;
                    item.append(swatch, name);
                    legend.append(item);
                    if (!available) return;

                    let segmentOpen = false;
                    let pathData = '';
                    series.forEach((value, index) => {
                        if (value === 'NA' || !Number.isFinite(Number(value))) {
                            segmentOpen = false;
                            return;
                        }
                        const x = left + (series.length === 1 ? plotWidth / 2 : plotWidth * index / (series.length - 1));
                        const y = top + plotHeight - plotHeight * Number(value) / maxValue;
                        pathData += `${segmentOpen ? 'L' : 'M'}${x.toFixed(2)},${y.toFixed(2)} `;
                        segmentOpen = true;
                    });
                    const path = document.createElementNS(namespace, 'path');
                    path.setAttribute('d', pathData.trim());
                    path.setAttribute('stroke', color);
                    path.setAttribute('class', 'history-series');
                    svg.append(path);
                });
                chart.append(svg);
            };

            filter.addEventListener('submit', async (event) => {
                event.preventDefault();
                clearResult();
                setError('#history-isp-error', '');
                setError('#history-list-error', '');
                setError('#history-date-error', '');
                if (!isp.value) {
                    setError('#history-isp-error', 'Select an ISP.');
                    isp.focus();
                    return;
                }
                if (!list.value) {
                    setError('#history-list-error', 'Select a list or ALL LISTS.');
                    list.focus();
                    return;
                }
                if (!from.value || !to.value || from.value > to.value || to.value > to.max || from.value < from.min) {
                    setError('#history-date-error', 'Choose a date range within the past year, ending today or earlier.');
                    (from.value > to.value ? to : from).focus();
                    return;
                }

                historyController?.abort();
                historyController = new AbortController();
                const requestId = ++historyRequestId;
                const oldLabel = button.textContent;
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                status.textContent = 'Loading history…';
                try {
                    const response = await fetch(filter.dataset.historyUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ isp: isp.value, list: list.value, from: from.value, to: to.value }),
                        signal: historyController.signal,
                    });
                    const payload = await readJson(response);
                    if (requestId !== historyRequestId) return;
                    renderTable(payload);
                    renderChart(payload);
                    result.classList.remove('d-none');
                    status.textContent = payload.histories.length ? `${payload.histories.length} history records loaded.` : 'No history was found for that selection.';
                } catch (error) {
                    if (error.name !== 'AbortError' && requestId === historyRequestId) status.textContent = error.message;
                } finally {
                    if (requestId === historyRequestId) {
                        button.disabled = false;
                        button.removeAttribute('aria-busy');
                        button.textContent = oldLabel;
                    }
                }
            });
        })();
    </script>
@endpush
