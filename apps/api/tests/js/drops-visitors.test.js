import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const script = await readFile(new URL('../../public/dms-reference/drops-visitors.js', import.meta.url), 'utf8');

function createPage({ drawChart = () => {}, rows = [{ country: 'United States', open: 2, click: 1, lead: 1, unsubscribe: 0 }] } = {}) {
    const root = { dataset: { chartUrl: '/drops/1/visitors?chart=1', tableUrl: '/drops/1/visitors' } };
    const mapElement = {
        attributes: new Map(),
        children: [],
        setAttribute(name, value) { this.attributes.set(name, value); },
        removeAttribute(name) { this.attributes.delete(name); },
        replaceChildren(...children) { this.children = children; },
    };
    const mapMessage = { hidden: true, textContent: '' };
    const mapBlock = { classList: { contains: () => false } };
    const tableElement = {};
    const elements = new Map([
        ['drop-visitors', root],
        ['drop-visitors-map-block', mapBlock],
        ['drop-visitors-map', mapElement],
        ['drop-visitors-map-message', mapMessage],
        ['drop-visitors-table', tableElement],
    ]);
    const handlers = {};
    const charts = [];
    const events = {
        addListener(chart, name, handler) {
            chart.handlers[name] = handler;
            return { chart, name, handler };
        },
        removeListener(listener) {
            delete listener.chart.handlers[listener.name];
        },
    };
    const google = {
        charts: { load: async () => {} },
        visualization: {
            events,
            arrayToDataTable: (data) => data,
            GeoChart: class {
                constructor(element) {
                    this.element = element;
                    this.handlers = {};
                    charts.push(this);
                }

                draw(data, options) {
                    this.data = data;
                    this.options = options;
                    drawChart(this);
                }
            },
        },
    };
    const grid = { ajax: { reload() {} } };
    const jquery = (element) => ({
        DataTable() { return grid; },
        on(name, handler) { handlers[element === mapBlock ? 'blockOpened' : name] = handler; return this; },
    });
    jquery.fn = { DataTable() {} };

    runInNewContext(script, {
        document: {
            getElementById: (id) => elements.get(id) ?? null,
            querySelector: () => null,
        },
        window: { google, jQuery: jquery },
        fetch: async () => ({ ok: true, json: async () => rows }),
        console: { warn() {} },
        setTimeout,
        clearTimeout,
    });

    return { handlers, charts, mapElement, mapMessage };
}

test('visitor map keeps loading state until Google GeoChart reports ready', async () => {
    const page = createPage();
    const pending = page.handlers.blockOpened();
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(page.charts.length, 1);
    assert.equal(page.mapMessage.hidden, false);
    assert.equal(page.mapMessage.textContent, 'Loading visitor locations…');
    assert.equal(page.mapElement.attributes.get('aria-busy'), 'true');
    assert.equal(JSON.stringify(page.charts[0].data[1]), JSON.stringify([
        'United States',
        2,
        'Open: 2 - Click: 1 - Lead: 1 - Unsubscribe: 0',
    ]));

    page.charts[0].handlers.ready();
    await pending;

    assert.equal(page.mapMessage.hidden, true);
    assert.equal(page.mapElement.attributes.has('aria-busy'), false);
});

test('visitor map shows an error when Google GeoChart rejects its data', async () => {
    const page = createPage({ drawChart: (chart) => chart.handlers.error({ message: 'synthetic chart error' }) });

    await page.handlers.blockOpened();

    assert.equal(page.mapMessage.hidden, false);
    assert.equal(page.mapMessage.textContent, 'Visitor locations could not be rendered. Refresh the table and try again.');
    assert.equal(page.mapElement.attributes.has('aria-busy'), false);
});

test('visitor map reports malformed chart responses instead of hiding the loading state', async () => {
    const page = createPage({ rows: { error: 'unexpected response' } });

    await page.handlers.blockOpened();

    assert.equal(page.charts.length, 0);
    assert.equal(page.mapMessage.hidden, false);
    assert.equal(page.mapMessage.textContent, 'Visitor locations could not be rendered. Refresh the table and try again.');
});
