const SVG_NS = 'http://www.w3.org/2000/svg';
const PALETTE = ['#3d76cf', '#b84d35', '#307760', '#8469b2', '#bf8c28', '#338c9b', '#a34d78', '#65727b'];
const METRICS = [
    { key: 'opens', label: 'Opens', color: PALETTE[0] },
    { key: 'clicks', label: 'Clicks', color: PALETTE[1] },
    { key: 'leads', label: 'Leads', color: PALETTE[2] },
    { key: 'unsubscribes', label: 'Unsubscribes', color: PALETTE[3] },
];

export function calculateLineCoordinates(values, width, height, padding, minimum, maximum) {
    const normalized = values.map((value) => Number.isFinite(Number(value)) ? Number(value) : 0);
    if (normalized.length === 0) {
        return [];
    }

    const min = Number.isFinite(minimum) ? Math.min(0, minimum) : Math.min(0, ...normalized);
    const max = Number.isFinite(maximum) ? Math.max(0, maximum) : Math.max(0, ...normalized);
    const valueRange = max - min || 1;
    const plotWidth = width - (padding.left + padding.right);
    const plotHeight = height - (padding.top + padding.bottom);

    return normalized.map((value, index) => ({
        x: normalized.length === 1
            ? padding.left + plotWidth / 2
            : padding.left + (index / (normalized.length - 1)) * plotWidth,
        y: padding.top + ((max - value) / valueRange) * plotHeight,
        value,
    }));
}

export function compareSortValues(left, right, type = 'text') {
    if (type === 'number') {
        const leftNumber = Number(left);
        const rightNumber = Number(right);
        return leftNumber === rightNumber ? 0 : leftNumber < rightNumber ? -1 : 1;
    }

    return String(left).localeCompare(String(right), undefined, { numeric: true, sensitivity: 'base' });
}

function svgElement(name, attributes = {}) {
    const element = document.createElementNS(SVG_NS, name);
    for (const [key, value] of Object.entries(attributes)) {
        element.setAttribute(key, String(value));
    }

    return element;
}

function numberLabel(value, metric) {
    if (metric === 'lead_revenue') {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(value);
    }

    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
}

function appendText(parent, text, attributes = {}) {
    const element = svgElement('text', attributes);
    element.textContent = text;
    parent.append(element);

    return element;
}

function renderLineChart(root, labels, series, metric) {
    root.replaceChildren();
    root.classList.toggle('is-rendered', labels.length > 0 && series.length > 0);
    if (labels.length === 0 || series.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'text-center text-muted mb-0';
        empty.setAttribute('role', 'status');
        empty.textContent = 'NO DATA AVAILABLE';
        root.append(empty);

        return;
    }

    const width = 900;
    const height = 330;
    const padding = { top: 18, right: 22, bottom: 48, left: 76 };
    const allValues = series.flatMap((item) => item.values.map((value) => Number(value) || 0));
    const minimum = Math.min(0, ...allValues);
    const maximum = Math.max(0, ...allValues);
    const valueRange = maximum - minimum || 1;
    const plotHeight = height - padding.top - padding.bottom;
    const svg = svgElement('svg', {
        viewBox: `0 0 ${width} ${height}`,
        role: 'img',
        'aria-label': root.getAttribute('aria-label') || 'Statistics trend chart',
        focusable: 'false',
    });
    const title = svgElement('title');
    title.textContent = root.getAttribute('aria-label') || 'Statistics trend chart';
    svg.append(title);

    for (let tick = 0; tick <= 4; tick += 1) {
        const value = maximum - (valueRange * tick) / 4;
        const y = padding.top + (plotHeight * tick) / 4;
        svg.append(svgElement('line', {
            x1: padding.left,
            x2: width - padding.right,
            y1: y,
            y2: y,
            stroke: '#d9dfd9',
            'stroke-width': 1,
        }));
        appendText(svg, numberLabel(value, metric), {
            x: padding.left - 10,
            y: y + 4,
            'text-anchor': 'end',
            fill: '#687773',
            'font-size': 12,
        });
    }

    const labelStep = Math.max(1, Math.ceil(labels.length / 9));
    for (let index = 0; index < labels.length; index += labelStep) {
        const x = labels.length === 1
            ? padding.left + (width - padding.left - padding.right) / 2
            : padding.left + (index / (labels.length - 1)) * (width - padding.left - padding.right);
        appendText(svg, labels[index], {
            x,
            y: height - 18,
            'text-anchor': index === 0 ? 'start' : 'middle',
            fill: '#687773',
            'font-size': 11,
        });
    }

    if ((labels.length - 1) % labelStep !== 0 && labels.length > 1) {
        const index = labels.length - 1;
        appendText(svg, labels[index], {
            x: width - padding.right,
            y: height - 18,
            'text-anchor': 'end',
            fill: '#687773',
            'font-size': 11,
        });
    }

    series.forEach((item, seriesIndex) => {
        const color = item.color || PALETTE[seriesIndex % PALETTE.length];
        const values = labels.map((_, index) => Number(item.values[index]) || 0);
        const points = calculateLineCoordinates(values, width, height, padding, minimum, maximum);
        const path = svgElement('path', {
            d: points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x.toFixed(2)} ${point.y.toFixed(2)}`).join(' '),
            fill: 'none',
            stroke: color,
            'stroke-width': 2.5,
            'stroke-linejoin': 'round',
            'stroke-linecap': 'round',
        });
        svg.append(path);

        points.forEach((point, index) => {
            const circle = svgElement('circle', {
                cx: point.x,
                cy: point.y,
                r: 3,
                fill: color,
                stroke: '#fffefa',
                'stroke-width': 1.5,
            });
            const tooltip = svgElement('title');
            tooltip.textContent = `${item.label} · ${labels[index]} · ${numberLabel(point.value, metric)}`;
            circle.append(tooltip);
            svg.append(circle);
        });
    });

    root.append(svg);
    const legend = document.createElement('div');
    legend.className = 'isp-stats-chart-legend';
    series.forEach((item, seriesIndex) => {
        const entry = document.createElement('span');
        entry.className = 'isp-stats-chart-legend-item';
        const swatch = document.createElement('span');
        swatch.className = 'isp-stats-chart-swatch';
        swatch.style.backgroundColor = item.color || PALETTE[seriesIndex % PALETTE.length];
        swatch.setAttribute('aria-hidden', 'true');
        entry.append(swatch, document.createTextNode(item.label));
        legend.append(entry);
    });
    root.append(legend);
}

function renderIspCharts() {
    const root = document.querySelector('[data-isp-stats-chart-data]');
    if (!root) {
        return;
    }

    let data;
    try {
        data = JSON.parse(root.textContent || '{}');
    } catch {
        return;
    }

    const labels = Array.isArray(data.labels) ? data.labels : [];
    for (const panel of document.querySelectorAll('[data-isp-stats-chart]')) {
        const metric = panel.dataset.ispStatsChart;
        const series = metric === 'overview'
            ? METRICS.map((item) => ({
                label: item.label,
                color: item.color,
                values: (Array.isArray(data.totals) ? data.totals : []).map((row) => row?.[item.key] ?? 0),
            }))
            : (Array.isArray(data.mailers) ? data.mailers : []).map((mailer, index) => ({
                label: mailer.label,
                color: PALETTE[index % PALETTE.length],
                values: mailer.values?.[metric] ?? [],
            }));
        renderLineChart(panel, labels, series, metric);
    }
}

if (typeof document !== 'undefined') {
    document.addEventListener('analytics-report-fragment-updated', renderIspCharts);
    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const button = target?.closest('button[data-isp-sort-column]');
        if (!button) {
            return;
        }

        const table = button.closest('table[data-isp-sortable]');
        const body = table?.tBodies[0];
        const header = button.closest('th');
        if (!table || !body || !header) {
            return;
        }

        const column = Number(button.dataset.ispSortColumn);
        const descending = header.getAttribute('aria-sort') === 'ascending';
        const direction = descending ? -1 : 1;
        const type = button.dataset.sortType || 'text';
        const rows = Array.from(body.rows);
        rows.sort((left, right) => {
            const leftCell = left.cells[column];
            const rightCell = right.cells[column];
            const leftValue = type === 'number' ? leftCell?.dataset.sortValue ?? leftCell?.textContent : leftCell?.textContent;
            const rightValue = type === 'number' ? rightCell?.dataset.sortValue ?? rightCell?.textContent : rightCell?.textContent;
            return compareSortValues(leftValue ?? '', rightValue ?? '', type) * direction;
        });
        rows.forEach((row) => body.append(row));

        table.querySelectorAll('thead th').forEach((cell) => cell.setAttribute('aria-sort', 'none'));
        header.setAttribute('aria-sort', descending ? 'descending' : 'ascending');
        table.querySelectorAll('.isp-sort-indicator').forEach((indicator) => {
            indicator.textContent = indicator.closest('th') === header ? descending ? '↓' : '↑' : '↕';
        });
    });
    renderIspCharts();
}
