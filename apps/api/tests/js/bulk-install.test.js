import test from 'node:test';
import assert from 'node:assert/strict';
import { buildBulkInstallPayload, applyDomainIndex, formatBulkInstallError } from '../../public/alphalink/infrastructure/bulk-install.js';

test('formats PMTA and settings blockers for the installation log', () => {
    assert.equal(formatBulkInstallError({
        message: 'PMTA deployment prerequisites are not verified.',
        servers: [{ blockers: ['Asset root is missing.', 'License is unverified.'] }],
    }), 'PMTA deployment prerequisites are not verified.\nServer 1: Asset root is missing.\nServer 1: License is unverified.');
    assert.equal(formatBulkInstallError({ message: 'Settings are missing.', blockers: ['PMTA port is invalid.'] }),
        'Settings are missing.\nPMTA port is invalid.');
});

test('matches the source-compatible submission fields, omits blank rows, and sends switches as 0/1', () => {
    const payload = buildBulkInstallPayload({
        rows: [
            { name: 'Relay A', id: 'server-a', ip: '192.0.2.10', domain: 'domain-a', check: true },
            { name: 'Relay B', id: 'server-b', ip: '192.0.2.11', domain: '', check: false },
        ],
        key: 1782155053000,
        pmtaVersion: '45',
        switches: { tracking: true, proxy: false, imap: false, spf: true, dkim: false, dmarc: true, https: false },
    });

    assert.deepEqual(payload, {
        data: [{ name: 'Relay A', id: 'server-a', ip: '192.0.2.10', domain: 'domain-a', check: true }],
        key: 1782155053000,
        pmta_version: 45,
        tracking: 1,
        proxy: 0,
        imap: 0,
        spf: 1,
        dkim: 0,
        dmarc: 1,
        https: 0,
    });
});

test('does not treat a workspace UUID as an empty numeric domain id', () => {
    const payload = buildBulkInstallPayload({
        rows: [{ id: 'server-a', domain: '91db3e68-04c3-4cf2-a3f8-71b96e6c7f8c' }],
        key: 1,
        pmtaVersion: 40,
        switches: {},
    });

    assert.equal(payload.data.length, 1);
});

test('APPLY copies even the blank option and blocks owned domains', () => {
    assert.equal(applyDomainIndex(0, ''), 0);
    assert.equal(applyDomainIndex(2, 'owned.example.org | IS OUR: YES'), null);
    assert.equal(applyDomainIndex(-1, ''), null);
});

test('submits selected rows and displays incremental AlphaLink logs until the run completes', async () => {
    const previousDocument = globalThis.document;
    const previousWindow = globalThis.window;
    const previousFetch = globalThis.fetch;
    const previousNow = Date.now;
    const listeners = new Map();
    const requests = [];
    let logReads = 0;

    const makeRow = ({ name, id, ip, domain }) => {
        const domainSelect = { value: domain, options: [{ text: '' }], selectedIndex: 0 };
        return {
            removed: false,
            querySelectorAll: () => [{ value: name }, { value: id }, { value: ip }],
            querySelector: (selector) => selector === '[data-domain-select]' ? domainSelect : null,
            remove() { this.removed = true; },
        };
    };
    const selectedRow = makeRow({ name: 'Relay A', id: 'server-a', ip: '192.0.2.10', domain: 'domain-a' });
    const blankRow = makeRow({ name: 'Relay B', id: 'server-b', ip: '192.0.2.11', domain: '' });
    const installButton = {
        disabled: false,
        textContent: 'INSTALL',
        dataset: { installUrl: '/bulk-install/runs', logUrl: '/bulk-install/runs/__token__/logs' },
        addEventListener: (name, listener) => listeners.set(name, listener),
    };
    const logResult = { textContent: '' };
    const logContainer = { classList: { remove() {} } };
    const documentStub = {
        readyState: 'complete',
        addEventListener() {},
        querySelectorAll: () => [selectedRow, blankRow].filter((row) => !row.removed),
        querySelector(selector) {
            if (selector === '#install') return installButton;
            if (selector === '#container_log_result') return logContainer;
            if (selector === '#log_result') return logResult;
            if (selector === 'meta[name="csrf-token"]') return { content: 'csrf-test-token' };
            if (selector === '#pmta_version') return { value: '45' };
            return { checked: false };
        },
    };

    try {
        globalThis.document = documentStub;
        Date.now = () => 1782155053000;
        globalThis.window = {
            confirm: () => true,
            alert() {},
            setTimeout(callback) { callback(); },
        };
        globalThis.fetch = async (url, options = {}) => {
            requests.push({ url, options });
            if (options.method === 'POST') {
                return { ok: true, json: async () => ({ data: 'Installation queued.', run_id: '9977bd8a-5107-4a6e-aa23-646fcb030087', status: 'queued' }) };
            }
            logReads += 1;
            return { ok: true, json: async () => ({
                data: logReads === 1 ? 'Installation Started' : 'Installation Started\nPrepare Installation',
                status: logReads === 1 ? 'running' : 'succeeded',
            }) };
        };

        await import(`../../public/alphalink/infrastructure/bulk-install.js?ui-test=${Date.now()}`);
        await listeners.get('click')();

        assert.equal(blankRow.removed, true);
        assert.equal(requests[0].url, '/bulk-install/runs');
        assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'csrf-test-token');
        assert.deepEqual(JSON.parse(requests[0].options.body), {
            data: [{ name: 'Relay A', id: 'server-a', ip: '192.0.2.10', domain: 'domain-a', check: false }],
            key: 1782155053000,
            pmta_version: 45,
            tracking: 0,
            proxy: 0,
            imap: 0,
            spf: 0,
            dkim: 0,
            dmarc: 0,
            https: 0,
        });
        assert.deepEqual(requests.slice(1).map((request) => request.url), [
            '/bulk-install/runs/9977bd8a-5107-4a6e-aa23-646fcb030087/logs',
            '/bulk-install/runs/9977bd8a-5107-4a6e-aa23-646fcb030087/logs',
        ]);
        assert.equal(logReads, 2);
        assert.equal(logResult.textContent, 'Installation Started\nPrepare Installation');
        assert.equal(installButton.textContent, 'DONE');
    } finally {
        if (previousDocument === undefined) delete globalThis.document;
        else globalThis.document = previousDocument;
        if (previousWindow === undefined) delete globalThis.window;
        else globalThis.window = previousWindow;
        if (previousFetch === undefined) delete globalThis.fetch;
        else globalThis.fetch = previousFetch;
        Date.now = previousNow;
    }
});

test('shows the server-side execution blocker in the AlphaLink installation log area', async () => {
    const previousDocument = globalThis.document;
    const previousWindow = globalThis.window;
    const previousFetch = globalThis.fetch;
    const previousNow = Date.now;
    const listeners = new Map();
    const installButton = {
        disabled: false,
        textContent: 'INSTALL',
        dataset: { installUrl: '/bulk-install/runs', logUrl: '/bulk-install/runs/__token__/logs' },
        addEventListener: (name, listener) => listeners.set(name, listener),
    };
    const logResult = { textContent: '' };
    const selectedRow = {
        removed: false,
        querySelectorAll: () => [{ value: 'Relay A' }, { value: 'server-a' }, { value: '192.0.2.10' }],
        querySelector: () => ({ value: 'domain-a' }),
        remove() { this.removed = true; },
    };
    const documentStub = {
        readyState: 'complete',
        addEventListener() {},
        querySelectorAll: () => [selectedRow],
        querySelector(selector) {
            if (selector === '#install') return installButton;
            if (selector === '#container_log_result') return { classList: { remove() {} } };
            if (selector === '#log_result') return logResult;
            if (selector === 'meta[name="csrf-token"]') return { content: 'csrf-test-token' };
            if (selector === '#pmta_version') return { value: '40' };
            return { checked: false };
        },
    };

    try {
        globalThis.document = documentStub;
        Date.now = () => 1782155053001;
        globalThis.window = {
            confirm: () => true,
            alert() {},
            setInterval: () => 7,
            setTimeout() {},
            clearInterval() {},
        };
        globalThis.fetch = async (_url, options = {}) => options.method === 'POST'
            ? { ok: false, json: async () => ({ message: 'PMTA deployment prerequisites are not verified.' }) }
            : { ok: true, json: async () => '' };

        await import(`../../public/alphalink/infrastructure/bulk-install.js?gate-test=${Date.now()}`);
        await listeners.get('click')();

        assert.equal(logResult.textContent, 'PMTA deployment prerequisites are not verified.');
        assert.equal(installButton.disabled, false);
        assert.equal(installButton.textContent, 'INSTALL');
    } finally {
        if (previousDocument === undefined) delete globalThis.document;
        else globalThis.document = previousDocument;
        if (previousWindow === undefined) delete globalThis.window;
        else globalThis.window = previousWindow;
        if (previousFetch === undefined) delete globalThis.fetch;
        else globalThis.fetch = previousFetch;
        Date.now = previousNow;
    }
});

test('expired install session reloads the page for sign-in instead of showing Unauthenticated', async () => {
    const previousDocument = globalThis.document;
    const previousWindow = globalThis.window;
    const previousFetch = globalThis.fetch;
    const listeners = new Map();
    let reloaded = false;
    const installButton = {
        disabled: false,
        textContent: 'INSTALL',
        dataset: { installUrl: '/bulk-install/runs', logUrl: '/bulk-install/runs/__token__/logs' },
        addEventListener: (name, listener) => listeners.set(name, listener),
    };
    const row = {
        querySelectorAll: () => [{ value: 'Relay A' }, { value: 'server-a' }, { value: '192.0.2.10' }],
        querySelector: () => ({ value: 'domain-a' }),
    };
    globalThis.document = {
        readyState: 'complete',
        addEventListener() {},
        querySelectorAll: () => [row],
        querySelector(selector) {
            if (selector === '#install') return installButton;
            if (selector === '#container_log_result') return { classList: { remove() {} } };
            if (selector === '#log_result') return { textContent: '' };
            if (selector === 'meta[name="csrf-token"]') return { content: 'test-token' };
            if (selector === '#pmta_version') return { value: '40' };
            return { checked: false };
        },
    };
    globalThis.window = {
        location: { reload() { reloaded = true; } },
        setTimeout() {},
    };
    globalThis.fetch = async (_url, options = {}) => options.method === 'POST'
        ? { ok: false, status: 401, json: async () => ({ message: 'Unauthenticated.' }) }
        : { ok: false, status: 401 };

    try {
        await import(`../../public/alphalink/infrastructure/bulk-install.js?expired-test=${Date.now()}`);
        await listeners.get('click')();
        assert.equal(reloaded, true);
    } finally {
        if (previousDocument === undefined) delete globalThis.document;
        else globalThis.document = previousDocument;
        if (previousWindow === undefined) delete globalThis.window;
        else globalThis.window = previousWindow;
        if (previousFetch === undefined) delete globalThis.fetch;
        else globalThis.fetch = previousFetch;
    }
});
