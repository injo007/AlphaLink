import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const script = await readFile(new URL('../../public/dms-reference/drops-suppression.js', import.meta.url), 'utf8');

function createElement(id) {
    return {
        id,
        value: '',
        dataset: {},
        selectedOptions: [],
        children: [],
        files: [],
        disabled: false,
        hidden: false,
        parentElement: { className: '' },
        listeners: new Map(),
        addEventListener(name, listener) {
            this.listeners.set(name, listener);
        },
        removeAttribute() {},
        replaceChildren(...children) {
            this.children = children;
            this.selectedOptions = [];
            this.value = '';
        },
        append(...children) {
            this.children.push(...children);
        },
    };
}

function createPage({ fetch: fetcher = async () => ({ ok: true, json: async () => ({}) }) } = {}) {
    const ids = [
        'drops-suppression',
        'drops-suppression-sponsor',
        'drops-suppression-offer',
        'drops-suppression-isps',
        'drops-suppression-lists',
        'drops-suppression-form',
        'drops-suppression-message',
        'drops-suppression-from',
        'drops-suppression-to',
        'drops-suppression-start',
        'offer-suppression-file-status',
        'offer-suppression-file-manage',
        'drops-suppression-refresh',
        'drops-suppression-details-content',
        'drops-suppression-details',
    ];
    const elements = new Map(ids.map((id) => [id, createElement(id)]));
    elements.get('drops-suppression').dataset = {
        canManageFiles: '1',
        today: '2026-10-29',
        defaultFrom: '2026-10-23',
        catalogUrl: '/catalog',
        rowsUrl: '/rows',
        startUrl: '/start',
        offerFileManageUrlTemplate: '/offers/__OFFER_ID__/suppression',
        monitorUrlTemplate: '/monitor/__SUPPRESSION_ID__',
        killUrl: '/kill',
    };
    let reloadCount = 0;
    const grid = { ajax: { reload() { reloadCount += 1; } }, on() { return this; } };
    const jquery = () => ({
        DataTable(options) {
            grid.options = options;

            return grid;
        },
        on() { return this; },
    });
    jquery.fn = { DataTable() {} };
    const windowListeners = new Map();

    const messages = [];
    runInNewContext(script, {
        document: {
            getElementById: (id) => elements.get(id) ?? null,
            querySelector: () => null,
            createElement: (tag) => ({ tagName: tag, dataset: {}, value: '', textContent: '' }),
        },
        window: {
            jQuery: jquery,
            location: { origin: 'https://alphalink.example.test' },
            AlphaLinkToast: { show: (text, tone) => messages.push({ text, tone }) },
            addEventListener(name, listener) {
                windowListeners.set(name, listener);
            },
        },
        fetch: fetcher,
        URL,
        setTimeout,
    });

    return { elements, windowListeners, messages, get reloadCount() { return reloadCount; } };
}

function selectSuppressionScope(elements) {
    elements.get('drops-suppression-sponsor').value = 'sponsor-1';
    elements.get('drops-suppression-offer').value = 'offer-1';
    elements.get('drops-suppression-offer').selectedOptions = [{ value: 'offer-1', dataset: { canRun: 'true' } }];
    elements.get('drops-suppression-isps').selectedOptions = [{ value: 'isp-1' }];
    elements.get('drops-suppression-lists').selectedOptions = [{ value: 'list-1' }];
}

test('selecting a List enables an otherwise eligible suppression run', () => {
    const { elements } = createPage();
    const startButton = elements.get('drops-suppression-start');
    const lists = elements.get('drops-suppression-lists');
    selectSuppressionScope(elements);
    startButton.disabled = true;

    assert.equal(typeof lists.listeners.get('change'), 'function');
    lists.listeners.get('change')();

    assert.equal(startButton.disabled, false);
});

test('restoring a selected suppression form rechecks Start Suppression', () => {
    const { elements, windowListeners } = createPage();
    const startButton = elements.get('drops-suppression-start');
    selectSuppressionScope(elements);
    startButton.disabled = true;

    assert.equal(typeof windowListeners.get('pageshow'), 'function');
    windowListeners.get('pageshow')();

    assert.equal(startButton.disabled, false);
});

test('a current offer has a separate file-manager link, not upload controls on the run page', () => {
    const { elements } = createPage();
    const offer = elements.get('drops-suppression-offer');
    const status = elements.get('offer-suppression-file-status');
    const managerLink = elements.get('offer-suppression-file-manage');
    offer.value = 'offer-7';
    offer.selectedOptions = [{ value: 'offer-7', dataset: { canRun: 'true', hasFile: 'true', format: 'email', updatedAt: '2026-10-29' } }];

    offer.listeners.get('change')();

    assert.match(status.textContent, /EMAIL.*2026-10-29/);
    assert.equal(managerLink.href, '/offers/offer-7/suppression');
    assert.equal(elements.has('offer-suppression-file-upload'), false);
});

test('an up-to-date suppression scope returns an informational success without creating a run', async () => {
    const page = createPage({
        fetch: async () => ({ ok: true, json: async () => ({ data: 'Suppression up to date', started: false }) }),
    });
    const { elements, messages } = page;
    selectSuppressionScope(elements);
    const form = elements.get('drops-suppression-form');
    await form.listeners.get('submit')({ preventDefault() {} });

    assert.deepEqual(messages, [{ text: 'Suppression is up to date for all selected lists.', tone: 'info' }]);
    assert.equal(page.reloadCount, 0);
});

test('a late sponsor catalog response cannot replace the newer sponsor selection', async () => {
    const pending = [];
    const page = createPage({
        fetch: (url) => new Promise((resolve) => pending.push({ url, resolve })),
    });
    const sponsor = page.elements.get('drops-suppression-sponsor');
    const load = sponsor.listeners.get('change');
    const flush = async () => {
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
    };

    sponsor.value = 'sponsor-old';
    load();
    sponsor.value = 'sponsor-new';
    load();
    assert.equal(pending.length, 2);

    const catalogResponse = (offerId, name) => ({
        ok: true,
        json: async () => ({
            offers: [{
                id: offerId,
                offer_id: offerId,
                name,
                vertical: 'test',
                payout: '1',
                is_active: true,
                can_run_suppression: true,
                has_suppression_file: true,
                suppression_format: 'email',
                suppression_updated_at: '2026-10-29',
            }],
            isps: [],
        }),
    });

    pending[1].resolve(catalogResponse('new-offer', 'New sponsor offer'));
    await flush();
    pending[0].resolve(catalogResponse('old-offer', 'Old sponsor offer'));
    await flush();

    const offerLabels = page.elements.get('drops-suppression-offer').children.map((option) => option.textContent);
    assert.ok(offerLabels.some((label) => label.includes('New sponsor offer')));
    assert.ok(offerLabels.every((label) => !label.includes('Old sponsor offer')));
});
