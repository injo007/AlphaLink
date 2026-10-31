import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const view = await readFile(new URL('../../resources/views/console/tools/workspace/index.blade.php', import.meta.url), 'utf8');
const inlineScript = [...view.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)].at(-1)?.[1];
assert.ok(inlineScript, 'Workspace page inline script exists');

function createElement(id = '') {
    const listeners = new Map();
    const attributes = new Map();

    return {
        id,
        listeners,
        attributes,
        selectedOptions: [],
        options: [],
        checked: false,
        disabled: false,
        className: '',
        textContent: '',
        innerHTML: '',
        addEventListener(type, listener) {
            listeners.set(type, listener);
        },
        setAttribute(name, value) {
            attributes.set(name, value);
        },
    };
}

function createWorkspacePage(results, dialogResults = []) {
    const elements = new Map();
    const getElementById = (id) => {
        if (!elements.has(id)) elements.set(id, createElement(id));

        return elements.get(id);
    };
    getElementById('workspace-ips').selectedOptions = [{ value: '17' }];
    getElementById('workspace-projects').selectedOptions = [{ value: '23' }];
    getElementById('workspace-status').className = 'alert d-none';
    const document = {
        getElementById,
        querySelector: () => ({ content: 'csrf-token' }),
        querySelectorAll: () => [],
    };
    const dialogCalls = [];
    const requests = [];
    const window = {
        swal: { fire: async (options) => { dialogCalls.push(options); return dialogResults.shift() ?? { isConfirmed: true }; } },
        alert: (message) => dialogCalls.push({title: message}),
    };
    const fetch = async (url, options) => { requests.push({url, options}); return { ok: true, json: async () => ({ data: results }) }; };
    const script = inlineScript.replace(/@json\(url\([\s\S]*?\)\)/, JSON.stringify('/app/workspaces/test/tools/workspace'));

    runInNewContext(script, { document, window, fetch });

    return { elements, dialogCalls, requests };
}

test('Workspace PUSH announces a failed server result instead of a generic success', async () => {
    const failure = 'Workspace relay failed its post-install service or listener health check.';
    const page = createWorkspacePage([{ id: 'synthetic-server', data: failure, success: false }]);
    const button = page.elements.get('workspace-push');

    await button.listeners.get('click')({ currentTarget: button });

    const status = page.elements.get('workspace-status');
    assert.match(status.textContent, /PUSH failed on 1 server/);
    assert.equal(status.className, 'alert alert-danger');
    assert.match(page.elements.get('workspace-results').innerHTML, new RegExp(failure));
    assert.equal(button.disabled, false);
});

test('Workspace RESET uses the DMS confirmation and announces per-server success', async () => {
    const page = createWorkspacePage([{ id: 'synthetic-server', data: 'Workspace SMTP reset successfully.', success: true }]);
    const button = page.elements.get('workspace-reset');

    const event = { currentTarget: button };
    const pending = button.listeners.get('click')(event);
    // Browsers clear Event.currentTarget once the async listener yields.
    event.currentTarget = null;
    await pending;

    const status = page.elements.get('workspace-status');
    assert.match(status.textContent, /Workspace SMTP reset: 1 server completed successfully/);
    assert.equal(status.className, 'alert alert-success');
    assert.equal(page.requests.length, 1);
    assert.match(page.requests[0].url, /\/reset$/);
    assert.equal(page.dialogCalls[0].icon, 'question');
    assert.equal(page.dialogCalls[0].title, 'Are you sure ?');
    assert.equal(page.dialogCalls[0].text, "Your Smtp's in those servers will be reset");
    assert.equal(page.dialogCalls[0].showCancelButton, true);
    assert.equal(page.dialogCalls[0].confirmButtonText, 'YES');
    assert.equal(page.dialogCalls[0].allowOutsideClick, false);
    assert.equal(page.dialogCalls[0].showCloseButton, true);
    assert.equal(page.dialogCalls[1].icon, 'success');
    assert.match(page.dialogCalls[1].text, /Workspace SMTP reset successfully/);
    assert.equal(button.disabled, false);
});

test('Workspace RESET cancellation leaves the server untouched', async () => {
    const page = createWorkspacePage([], [{ isConfirmed: false, isDismissed: true }]);
    const button = page.elements.get('workspace-reset');

    const event = { currentTarget: button };
    const pending = button.listeners.get('click')(event);
    event.currentTarget = null;
    await pending;

    assert.equal(page.requests.length, 0);
    assert.match(page.elements.get('workspace-status').textContent, /RESET cancelled/);
});
