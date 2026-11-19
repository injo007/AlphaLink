import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const view = readFileSync(new URL('../../resources/views/console/audiences/show.blade.php', import.meta.url), 'utf8');
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
    const form = {
        addEventListener: (name, callback) => listeners.set(`form:${name}`, callback),
        querySelector: (selector) => ({
            '#audience-recipient-search': searchInput,
            '#audience-recipient-clear': clearButton,
            '#audience-recipient-page-size': pageSize,
        })[selector],
        requestSubmit: () => listeners.get('form:submit')({ preventDefault: () => {} }),
    };
    const rows = [
        { dataset: { search: 'visible@example.test fresh first_name Vis last_name Able country US Company Example Co' }, hidden: false },
        { dataset: { search: 'other@example.test delivery first_name Sam vertical retail' }, hidden: false },
    ];
    const table = { querySelectorAll: () => rows };
    const resultStatus = { dataset: { total: '87142' }, textContent: '' };
    const noResultsRow = { hidden: true };
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

    return { clearButton, listeners, location, noResultsRow, paginationLink, resultStatus, rows, searchInput };
};

test('search matches recipient details and imported tag values on the loaded page', () => {
    const page = makePage();

    page.searchInput.value = 'company example';
    page.listeners.get('search:input')();

    assert.equal(page.rows[0].hidden, false);
    assert.equal(page.rows[1].hidden, true);
    assert.equal(page.noResultsRow.hidden, true);
    assert.match(page.resultStatus.textContent, /1 matches on this page/);
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
