import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const view = await readFile(new URL('../../resources/views/console/pmta/vmta.blade.php', import.meta.url), 'utf8');
const inlineScript = [...view.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)].at(-1)?.[1];
assert.ok(inlineScript, 'VMTA page inline script exists');
const controlsScript = await readFile(new URL('../../public/dms-reference/pmta-controls.js', import.meta.url), 'utf8');

function createElement(id = '') {
    const listeners = new Map();
    const attributes = new Map();

    return {
        id,
        dataset: {},
        listeners,
        attributes,
        children: [],
        selectedOptions: [],
        value: '',
        textContent: '',
        innerHTML: id.includes('reset') ? 'RESET' : '',
        disabled: false,
        addEventListener(type, listener) {
            listeners.set(type, listener);
        },
        setAttribute(name, value) {
            attributes.set(name, value);
        },
        removeAttribute(name) {
            attributes.delete(name);
        },
        append(...children) {
            this.children.push(...children);
        },
        replaceChildren(...children) {
            this.children = children;
        },
        showModal() {
            this.open = true;
        },
        close() {
            this.open = false;
            this.listeners.get('close')?.();
        },
        focus() {},
        remove() { this.removed = true; },
    };
}

function createVmtaPage() {
    const elements = new Map();
    const getElementById = (id) => {
        if (!elements.has(id)) {
            elements.set(id, createElement(id));
        }

        return elements.get(id);
    };

    getElementById('pmta-vmta').dataset = {
        pushUrl: '/vmta/push',
        pushRowsUrl: '/vmta/push-rows',
        restoreUrl: '/vmta/restore',
        restoreV2Url: '/vmta/restore-v2',
        rowsUrl: '/vmta/rows',
        deleteUrl: '/vmta/rows',
    };
    getElementById('pmta-vmta-ips').selectedOptions = [{ value: '17' }];
    getElementById('pmta-vmta-server-v2').value = 'server-uuid';

    const requests = [];
    const notices = [];
    const jqueryHandlers = new Map();
    const jQuery = (element) => ({
        on(event, listener) {
            const handlers = jqueryHandlers.get(element.id) || new Map();
            handlers.set(event, listener);
            jqueryHandlers.set(element.id, handlers);
        },
    });
    jQuery.fn = {};
    const window = {
        jQuery,
        AlphaLinkToast: {
            show(message, tone) {
                notices.push({ message, tone });
            },
        },
    };
    const document = {
        body: createElement('body'),
        getElementById,
        querySelector: () => ({ content: 'csrf-token' }),
        createElement,
    };
    const fetch = async (url, options) => {
        requests.push({ url, options });

        return {
            ok: true,
            json: async () => ({
                success: true,
                results: [{ server: 'Synthetic server', success: true, message: 'Restored Successfully' }],
            }),
        };
    };

    runInNewContext(controlsScript, { document, window });
    runInNewContext(inlineScript, { document, window, fetch });

    return {
        document,
        elements,
        jqueryHandlers,
        notices,
        requests,
        confirm(accepted) {
            const dialog = document.body.children.at(-1);
            assert.ok(dialog?.open, 'the shared PMTA confirmation dialog is open');
            const form = dialog.children[0];
            if (accepted) {
                form.listeners.get('submit')({ preventDefault() {} });
            } else {
                const actions = form.children.at(-1);
                actions.children[0].listeners.get('click')();
            }
        },
    };
}

async function confirmReset(page, buttonId) {
    const button = page.document.getElementById(buttonId);
    const event = { currentTarget: button };
    const pending = button.listeners.get('click')(event);

    assert.equal(typeof page.confirm, 'function');
    event.currentTarget = null;
    page.confirm(true);
    await pending;

    assert.equal(button.disabled, false);
    assert.equal(button.attributes.has('aria-busy'), false);
    assert.equal(button.innerHTML, 'RESET');
}

test('legacy VMTA Reset submits selected IP IDs after asynchronous confirmation', async () => {
    const page = createVmtaPage();

    await confirmReset(page, 'pmta-vmta-reset');

    assert.equal(page.requests.length, 1);
    assert.equal(page.requests[0].url, '/vmta/restore');
    assert.deepEqual(JSON.parse(page.requests[0].options.body), { ip_ids: ['17'] });
    assert.deepEqual(page.notices.at(-1), { message: 'Synthetic server: Restored Successfully', tone: 'success' });
});

test('VMTA 2 Reset submits the selected server after asynchronous confirmation', async () => {
    const page = createVmtaPage();

    await confirmReset(page, 'pmta-vmta-reset-v2');

    assert.equal(page.requests.length, 1);
    assert.equal(page.requests[0].url, '/vmta/restore-v2');
    assert.deepEqual(JSON.parse(page.requests[0].options.body), { server_id: 'server-uuid' });
    assert.deepEqual(page.notices.at(-1), { message: 'Synthetic server: Restored Successfully', tone: 'success' });
});

test('canceling the shared PMTA confirmation does not submit VMTA Reset', async () => {
    const page = createVmtaPage();
    const button = page.document.getElementById('pmta-vmta-reset-v2');
    const pending = button.listeners.get('click')({ currentTarget: button });

    page.confirm(false);
    await pending;

    assert.equal(page.requests.length, 0);
    assert.equal(button.disabled, false);
});

test('legacy VMTA Reset explains that an IP must be selected and sends no request', async () => {
    const page = createVmtaPage();
    page.elements.get('pmta-vmta-ips').selectedOptions = [];
    const button = page.elements.get('pmta-vmta-reset');
    const pending = button.listeners.get('click')({ currentTarget: button });
    const dialog = page.document.body.children.at(-1);
    const form = dialog.children[0];

    assert.equal(form.children[0].textContent, 'Oops');
    assert.equal(form.children[1].textContent, 'Please select at least one ip');
    assert.equal(form.children.at(-1).children.length, 1);
    form.listeners.get('submit')({ preventDefault() {} });
    await pending;

    assert.equal(page.requests.length, 0);
});

test('VMTA 2 Reset explains that a server must be selected and sends no request', async () => {
    const page = createVmtaPage();
    page.elements.get('pmta-vmta-server-v2').value = '';
    const button = page.elements.get('pmta-vmta-reset-v2');
    const pending = button.listeners.get('click')({ currentTarget: button });
    const dialog = page.document.body.children.at(-1);
    const form = dialog.children[0];

    assert.equal(form.children[0].textContent, 'Oops');
    assert.equal(form.children[1].textContent, 'Please select server');
    assert.equal(form.children.at(-1).children.length, 1);
    form.listeners.get('submit')({ preventDefault() {} });
    await pending;

    assert.equal(page.requests.length, 0);
});

test('VMTA 2 Reset clears a stale server warning when a valid server is selected', async () => {
    const page = createVmtaPage();
    const status = page.document.getElementById('pmta-vmta-status');
    const button = page.document.getElementById('pmta-vmta-reset-v2');
    status.textContent = 'Please select a server.';

    const pending = button.listeners.get('click')({ currentTarget: button });

    assert.equal(status.textContent, '');
    page.confirm(false);
    await pending;
    assert.equal(page.requests.length, 0);
});

test('legacy VMTA Reset clears a stale IP warning when an IP is selected', async () => {
    const page = createVmtaPage();
    const status = page.document.getElementById('pmta-vmta-status');
    const button = page.document.getElementById('pmta-vmta-reset');
    status.textContent = 'Please select at least one IP.';

    const pending = button.listeners.get('click')({ currentTarget: button });

    assert.equal(status.textContent, '');
    page.confirm(false);
    await pending;
    assert.equal(page.requests.length, 0);
});

test('selecting a server clears the previous VMTA 2 selection warning', () => {
    const page = createVmtaPage();
    const status = page.document.getElementById('pmta-vmta-status');
    const server = page.document.getElementById('pmta-vmta-server-v2');

    status.textContent = 'Please select a server.';
    server.listeners.get('change')();

    assert.equal(status.textContent, '');
});

test('selecting an IP clears the previous legacy Reset selection warning', () => {
    const page = createVmtaPage();
    const status = page.document.getElementById('pmta-vmta-status');
    const ips = page.document.getElementById('pmta-vmta-ips');

    status.textContent = 'Please select at least one IP.';
    ips.listeners.get('change')();

    assert.equal(status.textContent, '');
});

test('bootstrap-select server changes clear the VMTA 2 selection warning', () => {
    const page = createVmtaPage();
    const status = page.document.getElementById('pmta-vmta-status');
    const server = page.document.getElementById('pmta-vmta-server-v2');

    status.textContent = 'Please select a server.';
    page.jqueryHandlers.get(server.id).get('changed.bs.select')();

    assert.equal(status.textContent, '');
});

test('bootstrap-select IP changes clear the legacy Reset selection warning', () => {
    const page = createVmtaPage();
    const status = page.document.getElementById('pmta-vmta-status');
    const ips = page.document.getElementById('pmta-vmta-ips');

    status.textContent = 'Please select at least one IP.';
    page.jqueryHandlers.get(ips.id).get('changed.bs.select')();

    assert.equal(status.textContent, '');
});
