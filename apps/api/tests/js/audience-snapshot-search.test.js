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
    const sortButtons = ['id', 'email', 'first_name', 'last_name', 'country', 'age', 'vertical', 'sex', 'type', 'logs', 'created_at', 'custom_tags'].map((key) => {
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
        { dataset: { search: 'id visible@example.test fresh first name Vis last name Able country US age 32 vertical ecommerce sex Female logs 2 company Example Co', id: 'b0000000', email: 'visible@example.test', firstName: 'vis', lastName: 'able', country: 'us', age: '32', vertical: 'ecommerce', sex: 'female', type: 'fresh', logs: '2', createdAt: '100', customTags: 'company example co' }, hidden: false },
        { dataset: { search: 'id other@example.test delivery first name Sam last name Lee country CA age 9 vertical retail sex Male logs 0', id: 'a0000000', email: 'other@example.test', firstName: 'sam', lastName: 'lee', country: 'ca', age: '9', vertical: 'retail', sex: 'male', type: 'delivery', logs: '0', createdAt: '200', customTags: '' }, hidden: false },
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
    assert.match(page.resultStatus.textContent, /Showing 1 of 2 recipients on this page/);
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

    page.listeners.get('sort:age:click')();
    assert.deepEqual(page.rowOrder.slice(0, 2).map((row) => row.dataset.email), ['other@example.test', 'visible@example.test']);
    assert.equal(page.sortField.value, 'age');
    assert.equal(page.sortButtons.find((button) => button.dataset.sortKey === 'age').header.attributes['aria-sort'], 'ascending');
    assert.equal(new URL(page.paginationLink.href).searchParams.get('sort'), 'age');

    page.listeners.get('sort:age:click')();
    assert.deepEqual(page.rowOrder.slice(0, 2).map((row) => row.dataset.email), ['visible@example.test', 'other@example.test']);
    assert.equal(page.sortButtons.find((button) => button.dataset.sortKey === 'age').header.attributes['aria-sort'], 'descending');
    assert.equal(new URL(page.paginationLink.href).searchParams.get('direction'), 'desc');
});

test('the list recipient action is a native link to the dedicated page', () => {
    assert.match(listPage, /const audienceUrl = escapeHtml\(audienceUrlTemplate\.replace\('__LIST_ID__', encodeURIComponent\(row\.id\)\)\);/);
    assert.match(listPage, /<a href="\$\{audienceUrl\}" class="command-action-btn action-view audience-snapshot-link"/);
    assert.doesNotMatch(listPage, /openAudienceSnapshot|\.on\('click', '\.view'/);
    assert.doesNotMatch(listPage, /openFrame\([^\n]*audienceUrl/);
});
