import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const script = await readFile(new URL('../../public/alphalink/tools/text-mechanic.js', import.meta.url), 'utf8');

function element(id) {
    const listeners = new Map();

    return {
        id,
        value: '',
        textContent: '',
        selectionStart: 0,
        selectionEnd: 0,
        selectionDirection: 'none',
        listeners,
        addEventListener(type, listener) {
            listeners.set(type, listener);
        },
        click() {
            this.listeners.get('click')?.({ preventDefault() {} });
        },
        focus() {
            this.focused = true;
        },
        select() {
            this.selected = true;
        },
        setSelectionRange(start, end, direction) {
            this.selectionStart = start;
            this.selectionEnd = end;
            this.selectionDirection = direction;
        },
    };
}

function createPage(navigatorRef = {}) {
    const elements = new Map();
    const documentRef = {
        activeElement: null,
        execCommand: () => true,
        getElementById(id) {
            if (!elements.has(id)) {
                elements.set(id, element(id));
            }

            return elements.get(id);
        },
    };
    const windowRef = {};

    runInNewContext(script, { window: windowRef, document: documentRef, navigator: navigatorRef });

    return {
        elements,
        documentRef,
        page: windowRef.AlphaLinkTextMechanic,
        click(id) {
            elements.get(id).click();
        },
    };
}

test('Text Mechanic sorting follows the local natural-sort behavior and handles blank input', () => {
    const { page } = createPage();

    assert.equal(page.operations.sortAscending('server10\nserver2\nServer1\n\n'), 'Server1\nserver2\nserver10');
    assert.equal(page.operations.sortDescending('first\n\nsecond\r\n'), 'second\nfirst');
    assert.equal(page.operations.sortByLength('four\nb\nccc\n\n'), 'b\nccc\nfour');
    assert.equal(page.operations.sortAscending(''), '');
    assert.equal(page.operations.sortDescending(''), '');
    assert.equal(page.operations.sortByLength(''), '');
});

test('Text Mechanic outputs match the inspected DMS line transformation rules', () => {
    const { page } = createPage();

    assert.equal(page.operations.sortAscending('Item10\nitem2\nItem1\r\n\n'), 'Item1\nitem2\nItem10');
    assert.equal(page.operations.sortDescending('first\nsecond\r\n\n'), 'second\nfirst');
    assert.equal(page.operations.sortByLength('zz\na\nbb\n'), 'a\nzz\nbb');
    assert.equal(page.operations.sortRandom('a\n\nb\nc', () => 0), 'b\nc\na');
    assert.equal(page.operations.removeDuplicates('Alpha\nalpha\nAlpha\n\n'), 'Alpha\nalpha\n');
    assert.equal(page.operations.extractIps('no IPv4 address here'), '');
});

test('Text Mechanic shuffle, duplicate removal, and IP extraction preserve the local result shape', () => {
    const { page } = createPage();
    const shuffled = page.operations.sortRandom('alpha\n\nbeta\ngamma', () => 0).split('\n').sort();

    assert.deepEqual(shuffled, ['alpha', 'beta', 'gamma']);
    assert.equal(page.operations.sortRandom(''), '');
    assert.equal(page.operations.removeDuplicates('A\na\nA\n\n'), 'A\na\n');
    assert.equal(page.operations.extractIps('from 192.0.2.1 to 10.0.0.1 and 192.0.2.1'), '192.0.2.1\n10.0.0.1');
    assert.equal(page.operations.extractIps('no address here'), '');
});

test('IP extraction preserves the captured DMS regex edge semantics', () => {
    const { page } = createPage();

    // The read-only DMS source capture has no token boundaries, and its dot is
    // attached to only the last octet alternative. Keep the copied behavior
    // explicit so a future cleanup does not silently change DMS parity.
    assert.equal(
        page.operations.extractIps('192.168.0.1 and 255.255.255.255\n999.1.1.1\n10.0.0.1'),
        '192.168.0.1\n99.1.1.1\n10.0.0.1',
    );
});

test('all nine toolbar actions are bound and update the text area or clipboard', async () => {
    const copied = [];
    const { elements, page, click } = createPage({
        clipboard: {
            writeText(value) {
                copied.push(value);
                return Promise.resolve();
            },
        },
    });
    const textarea = elements.get('text');
    const expectedActions = ['asc', 'desc', 'length', 'random', 'rduplicate', 'ips', 'sall', 'copy', 'clear'];

    assert.equal(typeof page.initialize, 'function');
    for (const id of expectedActions) {
        assert.equal(typeof elements.get(id).listeners.get('click'), 'function', id + ' is wired');
    }

    textarea.value = 'b\na';
    click('asc');
    assert.equal(textarea.value, 'a\nb');
    click('sall');
    assert.equal(textarea.selected, true);
    click('copy');
    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(copied, ['a\nb']);
    assert.equal(elements.get('text-mechanic-status').textContent, 'Copied text to the clipboard.');
    click('clear');
    assert.equal(textarea.value, '');
});

test('COPY falls back to the local selection when clipboard access is unavailable', () => {
    const { elements, click, documentRef } = createPage();
    const textarea = elements.get('text');
    textarea.value = 'copy me';
    let copied = 0;
    documentRef.execCommand = (command) => {
        assert.equal(command, 'copy');
        copied++;
        return true;
    };

    click('copy');

    assert.equal(copied, 1);
    assert.equal(textarea.selectionStart, 0);
    assert.equal(textarea.selectionEnd, 0);
    assert.equal(elements.get('text-mechanic-status').textContent, 'Copied text to the clipboard.');
});
