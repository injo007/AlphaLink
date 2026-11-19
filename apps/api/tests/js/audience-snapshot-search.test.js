import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const view = readFileSync(new URL('../../resources/views/console/audiences/show.blade.php', import.meta.url), 'utf8');
const listPage = readFileSync(new URL('../../resources/views/console/infrastructure/lists/index.blade.php', import.meta.url), 'utf8');
const scripts = [...view.matchAll(/<script>([\s\S]*?)<\/script>/g)];
const searchScript = scripts.at(-1)?.[1];

assert.ok(searchScript, 'audience snapshot search script exists');

const makePage = () => {
    const listeners = new Map();
    const searchInput = {
        value: '',
        addEventListener: (name, callback) => listeners.set(`search:${name}`, callback),
        focus: () => {},
    };
    const clearButton = {
        hidden: true,
        addEventListener: (name, callback) => listeners.set(`clear:${name}`, callback),
    };
    const pageSize = {
        value: '50',
        dataset: { current: '50' },
        addEventListener: (name, callback) => listeners.set(`page-size:${name}`, callback),
    };
    const typeFilter = {
        value: 'all',
        addEventListener: (name, callback) => listeners.set(`type:${name}`, callback),
    };
    const sortField = { value: '' };
    const directionField = { value: 'asc' };
    const sortButtons = ['recipient', 'type', 'details'].map((key) => {
        const header = { attributes: {}, setAttribute(name, value) { this.attributes[name] = value; } };
        const icon = { className: 'fas fa-sort' };

        return {
            dataset: { sortKey: key },
            attributes: {},
            closest: () => header,
            querySelector: () => icon,
            addEventListener: (name, callback) => listeners.set(`sort:${key}:${name}`, callback),
            setAttribute(name, value) { this.attributes[name] = value; },
            header,
            icon,
        };
    });
    const noResultsRow = { hidden: true };
    const form = {
        addEventListener: (name, callback) => listeners.set(`form:${name}`, callback),
        querySelector: (selector) => ({
            '#audience-recipient-search': searchInput,
            '#audience-recipient-clear': clearButton,
            '#audience-recipient-type': typeFilter,
            '#audience-recipient-page-size': pageSize,
            '[name="sort"]': sortField,
            '[name="direction"]': directionField,
        })[selector],
        requestSubmit: () => listeners.get('form:submit')({ preventDefault: () => {} }),
    };
    const rows = [
        { dataset: { search: 'visible@example.test fresh first_name Vis last_name Able country US Company Example Co', recipient: 'visible@example.test', type: 'fresh', details: 'first_name Vis last_name Able country US Company Example Co' }, hidden: false },
        { dataset: { search: 'other@example.test delivery first_name Sam vertical retail', recipient: 'other@example.test', type: 'delivery', details: 'first_name Sam vertical retail' }, hidden: false },
    ];
    const rowOrder = [...rows, noResultsRow];
    const tbody = {
        appendChild: (row) => {
            const existingIndex = rowOrder.indexOf(row);
            if (existingIndex !== -1) rowOrder.splice(existingIndex, 1);
            rowOrder.push(row);
        },
    };
    const table = {
        dataset: { sortColumn: '', sortDirection: 'asc' },
        querySelector: () => tbody,
        querySelectorAll: (selector) => selector === '.audience-recipient-row' ? rows : sortButtons,
    };
    const resultStatus = { dataset: { total: '87142' }, textContent: '' };
    const paginationLink = { href: 'https://app.test/lists/4?page=2' };
    const document = {
        querySelector: (selector) => ({
            '#audience-recipient-filter': form,
            '#audience-recipient-table': table,
            '#audience-recipient-results': resultStatus,
            '#audience-recipient-no-results': noResultsRow,
        })[selector],
        querySelectorAll: () => [paginationLink],
    };
    const location = { href: 'https://app.test/lists/4?page=1' };
    const window = {
        location,
        history: {
            state: null,
            replaceState: (_state, _title, url) => {
                location.href = url.toString();
            },
        },
    };

    vm.runInNewContext(searchScript, { document, window, URL });

    return { clearButton, directionField, listeners, location, noResultsRow, paginationLink, resultStatus, rowOrder, rows, searchInput, sortButtons, sortField, typeFilter };
};

test('search matches recipient details and imported tag values on the loaded page', () => {
    const page = makePage();

    page.searchInput.value = 'company example';
    page.listeners.get('search:input')();

    assert.equal(page.rows[0].hidden, false);
    assert.equal(page.rows[1].hidden, true);
    assert.equal(page.noResultsRow.hidden, true);
    assert.match(page.resultStatus.textContent, /Showing 1 of 2 loaded recipients/);
    assert.equal(new URL(page.paginationLink.href).searchParams.get('q'), 'company example');
});

test('search shows a clear empty state and clearing restores rows and pagination', () => {
    const page = makePage();

    page.searchInput.value = 'not found';
    page.listeners.get('search:input')();
    assert.equal(page.rows.every((row) => row.hidden), true);
    assert.equal(page.noResultsRow.hidden, false);

    page.listeners.get('clear:click')();

    assert.equal(page.searchInput.value, '');
    assert.equal(page.rows.every((row) => !row.hidden), true);
    assert.equal(page.noResultsRow.hidden, true);
    assert.equal(page.clearButton.hidden, true);
    assert.equal(new URL(page.location.href).searchParams.has('q'), false);
});

test('type filter and sortable headers update the current page and preserve list state', () => {
    const page = makePage();

    page.typeFilter.value = 'open';
    page.listeners.get('type:change')();
    assert.equal(page.rows.every((row) => row.hidden), true);
    assert.equal(page.noResultsRow.hidden, false);

    page.typeFilter.value = 'delivery';
    page.listeners.get('type:change')();
    assert.equal(page.rows[0].hidden, true);
    assert.equal(page.rows[1].hidden, false);
    assert.equal(new URL(page.paginationLink.href).searchParams.get('type'), 'delivery');

    page.listeners.get('sort:recipient:click')();
    assert.deepEqual(page.rowOrder.slice(0, 2).map((row) => row.dataset.recipient), ['other@example.test', 'visible@example.test']);
    assert.equal(page.sortField.value, 'recipient');
    assert.equal(page.sortButtons[0].header.attributes['aria-sort'], 'ascending');
    assert.equal(new URL(page.paginationLink.href).searchParams.get('sort'), 'recipient');

    page.listeners.get('sort:recipient:click')();
    assert.equal(page.sortButtons[0].header.attributes['aria-sort'], 'descending');
    assert.equal(new URL(page.paginationLink.href).searchParams.get('direction'), 'desc');
});

test('the list recipient action navigates to the dedicated page instead of opening an iframe', () => {
    assert.match(listPage, /const openAudienceSnapshot = \(url\) => window\.location\.assign\(url\);/);
    assert.match(listPage, /openAudienceSnapshot\(audienceUrlTemplate\.replace\('__LIST_ID__', encodeURIComponent\(this\.dataset\.id\)\)\)/);
});
