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
        files: [],
        disabled: false,
        hidden: false,
        parentElement: { className: '' },
        listeners: new Map(),
        addEventListener(name, listener) {
            this.listeners.set(name, listener);
        },
        removeAttribute() {},
        replaceChildren() {},
    };
}

function createPage() {
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
        'offer-suppression-file-input',
        'offer-suppression-file-upload',
        'offer-suppression-file-status',
        'offer-suppression-file-download',
        'offer-suppression-file-manage',
        'offer-suppression-file-result',
        'drops-suppression-refresh',
        'drops-suppression-details-content',
        'drops-suppression-details',
    ];
    const elements = new Map(ids.map((id) => [id, createElement(id)]));
    elements.get('drops-suppression').dataset = {
        canManageFiles: '1',
        today: '2026-10-29',
        defaultFrom: '2026-10-23',
    };
    const grid = { ajax: { reload() {} }, on() { return this; } };
    const jquery = () => ({
        DataTable(options) {
            grid.options = options;

            return grid;
        },
        on() { return this; },
    });
    jquery.fn = { DataTable() {} };
    const windowListeners = new Map();

    runInNewContext(script, {
        document: {
            getElementById: (id) => elements.get(id) ?? null,
            querySelector: () => null,
        },
        window: {
            jQuery: jquery,
            addEventListener(name, listener) {
                windowListeners.set(name, listener);
            },
        },
    });

    return { elements, windowListeners };
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
