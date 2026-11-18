import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const view = await readFile(new URL('../../resources/views/console/pmta/smtp.blade.php', import.meta.url), 'utf8');
const handlerStart = view.indexOf("    document.getElementById('pmta-smtp-reset').addEventListener('click', async (event) => {");
const handlerEnd = view.indexOf('\n    });', handlerStart);
assert.notEqual(handlerStart, -1, 'SMTP Reset click handler exists');
assert.notEqual(handlerEnd, -1, 'SMTP Reset click handler closes');
const resetHandler = view.slice(handlerStart, handlerEnd + '\n    });'.length);

function createResetHarness({ selectedIpIds = ['17'], confirmation = async () => true, apiResult = { success: true, results: [{ server: 'Synthetic server', message: 'Restored Successfully' }] }, apiError = null } = {}) {
    const listeners = new Map();
    const attributes = new Map();
    const button = {
        disabled: false,
        addEventListener(name, listener) { listeners.set(name, listener); },
        setAttribute(name, value) { attributes.set(name, value); },
        removeAttribute(name) { attributes.delete(name); },
    };
    const requests = [];
    const results = [];
    const notices = [];
    const document = { getElementById: () => button };
    const environment = {
        document,
        root: { dataset: { restoreUrl: '/smtp/restore' } },
        selectedIpIds: () => selectedIpIds,
        ask: (...args) => confirmation(...args),
        api: async (...args) => {
            requests.push(args);
            if (apiError) throw apiError;

            return apiResult;
        },
        showResults: (value) => results.push(value),
        announce: (message, tone) => notices.push({ message, tone }),
        grid: null,
    };
    runInNewContext(`(() => { ${resetHandler} })()`, environment);

    return {
        button,
        attributes,
        requests,
        results,
        notices,
        click() {
            return listeners.get('click')({ currentTarget: button });
        },
    };
}

test('SMTP Reset confirms, calls the selected-server endpoint, and announces each state', async () => {
    const page = createResetHarness();

    await page.click();

    assert.equal(page.requests.length, 1);
    assert.equal(page.requests[0][0], '/smtp/restore');
    assert.equal(page.requests[0][1], 'POST');
    assert.deepEqual(JSON.parse(JSON.stringify(page.requests[0][2])), { ip_ids: ['17'] });
    assert.deepEqual(JSON.parse(JSON.stringify(page.results.at(-1))), [{ server: 'Synthetic server', message: 'Restored Successfully' }]);
    assert.deepEqual(JSON.parse(JSON.stringify(page.notices)), [
        { message: 'Resetting SMTP configuration on the selected servers.', tone: 'info' },
        { message: 'Synthetic server: Restored Successfully', tone: 'success' },
    ]);
    assert.equal(page.button.disabled, false);
    assert.equal(page.attributes.has('aria-busy'), false);
});

test('canceling or rejecting SMTP Reset confirmation never sends a request and remains visible', async () => {
    const canceled = createResetHarness({ confirmation: async () => false });
    await canceled.click();
    assert.equal(canceled.requests.length, 0);
    assert.equal(canceled.button.disabled, false);

    const rejected = createResetHarness({ confirmation: async () => { throw new Error('Confirmation unavailable.'); } });
    await rejected.click();
    assert.equal(rejected.requests.length, 0);
    assert.deepEqual(JSON.parse(JSON.stringify(rejected.notices.at(-1))), { message: 'Confirmation unavailable.', tone: 'danger' });
    assert.equal(rejected.button.disabled, false);
});

test('SMTP Reset reports API failures and always restores the button state', async () => {
    const page = createResetHarness({ apiError: new Error('SSH connection failed.') });

    await page.click();

    assert.equal(page.requests.length, 1);
    assert.deepEqual(JSON.parse(JSON.stringify(page.notices.at(-1))), { message: 'SSH connection failed.', tone: 'danger' });
    assert.equal(page.button.disabled, false);
    assert.equal(page.attributes.has('aria-busy'), false);
});
