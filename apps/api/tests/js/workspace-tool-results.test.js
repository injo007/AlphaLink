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

function createWorkspacePage(results) {
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
    const window = { Swal: { fire: async () => ({ isConfirmed: true }) } };
    const fetch = async () => ({ ok: true, json: async () => ({ data: results }) });
    const script = inlineScript.replace(/@json\(url\([\s\S]*?\)\)/, JSON.stringify('/app/workspaces/test/tools/workspace'));

    runInNewContext(script, { document, window, fetch });

    return { elements };
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

test('Workspace RESET announces confirmed per-server success', async () => {
    const page = createWorkspacePage([{ id: 'synthetic-server', data: 'Workspace SMTP reset successfully.', success: true }]);
    const button = page.elements.get('workspace-reset');

    await button.listeners.get('click')({ currentTarget: button });

    const status = page.elements.get('workspace-status');
    assert.match(status.textContent, /Workspace SMTP reset: 1 server completed successfully/);
    assert.equal(status.className, 'alert alert-success');
    assert.equal(button.disabled, false);
});
