import test from 'node:test';
import assert from 'node:assert/strict';

class ElementStub {
    constructor(tagName = 'div') {
        this.tagName = tagName;
        this.children = [];
        this.dataset = {};
        this.attributes = {};
        this.listeners = new Map();
        this.className = '';
        this.checked = false;
        this.value = '';
    }

    append(...children) {
        children.forEach((child) => {
            child.parent = this;
            this.children.push(child);
        });
    }

    addEventListener(name, listener) {
        this.listeners.set(name, listener);
    }

    setAttribute(name, value) {
        this.attributes[name] = value;
    }

    querySelectorAll(selector) {
        if (selector === '[data-placeholder-row]') return this.children.filter((child) => child.dataset.placeholderRow !== undefined);
        return [];
    }

    querySelector(selector) {
        if (selector === 'textarea') {
            for (const child of this.children) {
                if (child.tagName === 'textarea') return child;
                const nested = child.querySelector(selector);
                if (nested) return nested;
            }
        }
        return null;
    }

    closest(selector) {
        if (selector === '[data-placeholder-row]') {
            let current = this;
            while (current) {
                if (current.dataset.placeholderRow !== undefined) return current;
                current = current.parent;
            }
        }
        if (selector === '[data-placeholder-remove]') return this.dataset.placeholderRemove !== undefined ? this : null;
        return null;
    }

    remove() {
        const siblings = this.parent?.children;
        if (siblings) siblings.splice(siblings.indexOf(this), 1);
    }
}

test('placeholder remove icon confirms then removes even the final row, and Add restores a row', async () => {
    const previousDocument = globalThis.document;
    const previousWindow = globalThis.window;
    let confirmationCount = 0;

    const body = new ElementStub('tbody');
    const initialRow = new ElementStub('tr');
    initialRow.dataset.placeholderRow = '';
    const initialTextarea = new ElementStub('textarea');
    initialTextarea.name = 'send_setup[placeholders][4][data_text]';
    initialRow.append(initialTextarea);
    body.append(initialRow);

    const editor = new ElementStub('section');
    editor.dataset.placeholderEditor = '';
    editor.dataset.namePrefix = 'send_setup[placeholders]';
    const addButton = new ElementStub('button');
    const combination = new ElementStub('input');
    const allCombination = new ElementStub('input');
    combination.name = 'send_setup[placeholder_combination]';
    allCombination.name = 'send_setup[placeholder_all_combination]';
    editor.querySelector = (selector) => ({
        '[data-placeholder-rows]': body,
        '[data-placeholder-add]': addButton,
        'input[name="send_setup[placeholder_combination]"]': combination,
        'input[name="send_setup[placeholder_all_combination]"]': allCombination,
    })[selector] ?? null;

    globalThis.document = {
        querySelectorAll: () => [editor],
        createElement: (tagName) => new ElementStub(tagName),
    };
    globalThis.window = { confirm: () => { confirmationCount += 1; return true; } };

    try {
        await import(`../../public/alphalink/dms-placeholder-editor.js?test=${Date.now()}`);
        const removeClick = body.listeners.get('click');
        const removeButton = new ElementStub('button');
        removeButton.dataset.placeholderRemove = '';
        const actionCell = new ElementStub('td');
        actionCell.append(removeButton);
        initialRow.append(actionCell);
        removeClick({ target: removeButton });

        assert.equal(confirmationCount, 1);
        assert.equal(body.querySelectorAll('[data-placeholder-row]').length, 0);

        addButton.listeners.get('click')();
        const restoredRows = body.querySelectorAll('[data-placeholder-row]');
        assert.equal(restoredRows.length, 1);
        assert.equal(restoredRows[0].querySelector('textarea').name, 'send_setup[placeholders][0][data_text]');
        assert.match(restoredRows[0].children[2].children[0].innerHTML, /fa-times/);
    } finally {
        if (previousDocument === undefined) delete globalThis.document;
        else globalThis.document = previousDocument;
        if (previousWindow === undefined) delete globalThis.window;
        else globalThis.window = previousWindow;
    }
});
