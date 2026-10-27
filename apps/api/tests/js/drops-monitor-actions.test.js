import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const script = await readFile(new URL('../../public/dms-reference/drops-monitor.js', import.meta.url), 'utf8');

function createPage() {
    const root = {
        dataset: {
            today: '2026-10-25',
            rowsUrl: '/drops/monitoring',
            monitorUrlTemplate: '/drops/__DROP_ID__/monitor',
            pmtaAdminUrl: '/app/workspaces/123e4567-e89b-12d3-a456-426614174000/pmta-admin',
            statsUrlTemplate: '/drops/__DROP_ID__/stats',
            visitorsUrlTemplate: '/drops/__DROP_ID__/visitors',
        },
    };
    const elements = new Map();
    const filterForm = { addEventListener() {} };
    const filterButton = { disabled: false, setAttribute() {}, removeAttribute() {} };
    const typeInput = { value: 'all' };
    const message = { hidden: true, textContent: '' };
    const tableElement = {};
    const grid = {
        ajax: { reload() {} },
        on() { return this; },
        search() { return this; },
        draw() {},
    };
    const jquery = () => ({
        DataTable(options) {
            grid.options = options;

            return grid;
        },
        on() { return this; },
    });
    jquery.fn = { DataTable() {} };
    const moment = (value) => ({
        clone() { return moment(value); },
        startOf() { return this; },
        subtract() { return this; },
        endOf() { return this; },
        format() { return value; },
        isValid() { return typeof value === 'string' && value.length > 0; },
        isSameOrBefore() { return true; },
        isAfter() { return false; },
    });
    elements.set('drops-monitoring', root);
    elements.set('drops-monitoring-table', tableElement);
    elements.set('drops-monitoring-filters', filterForm);
    elements.set('drops-monitoring-filter', filterButton);
    elements.set('drops-monitoring-type', typeInput);
    elements.set('drops-monitoring-message', message);

    runInNewContext(script, {
        URL,
        document: {
            getElementById: (id) => elements.get(id) ?? null,
            querySelector: () => null,
        },
        sessionStorage: { getItem: () => null, setItem() {} },
        window: {
            jQuery: jquery,
            location: { href: 'http://127.0.0.1/app/workspaces/123e4567-e89b-12d3-a456-426614174000/drops/monitoring' },
            moment,
            addEventListener() {},
        },
    });

    return grid.options.columns.find((column) => column.title === 'ACTIONS').render;
}

function pmtaHref(markup) {
    const href = markup.match(/href="([^"]+)"[^>]*title="PMTA"/)?.[1];
    assert.ok(href, 'the DMS-style PMTA action has a link');

    return new URL(href.replaceAll('&amp;', '&'));
}

test('Drop PMTA action opens PMTA Admin with only the Drop servers selected', () => {
    const renderActions = createPage();
    const serverIds = [
        '11111111-1111-4111-8111-111111111111',
        '22222222-2222-4222-8222-222222222222',
    ];
    const markup = renderActions(null, 'display', { id: 48, send_type: 'send', server_ids: serverIds });
    const url = pmtaHref(markup);

    assert.equal(url.pathname, '/app/workspaces/123e4567-e89b-12d3-a456-426614174000/pmta-admin');
    assert.deepEqual(Array.from(url.searchParams.getAll('server_ids[]')), serverIds);
    assert.match(markup, /aria-label="Open PMTA for drop 48"/);
    assert.match(markup, /target="_blank" rel="noopener"/);
    assert.match(markup, /drop-stats-action/);
    assert.match(markup, /title="VISITORS"/);
});

test('test Drops retain the DMS PMTA action without showing send-only statistics', () => {
    const renderActions = createPage();
    const markup = renderActions(null, 'display', {
        id: 49,
        send_type: 'test',
        server_ids: ['11111111-1111-4111-8111-111111111111'],
    });

    assert.match(markup, /title="PMTA"/);
    assert.doesNotMatch(markup, /drop-stats-action|title="VISITORS"/);
});
