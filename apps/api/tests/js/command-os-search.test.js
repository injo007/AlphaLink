import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const script = await readFile(new URL('../../public/console-assets/command-os-search.js', import.meta.url), 'utf8');

class MockElement {
    constructor(name = '') {
        this.name = name;
        this.listeners = {};
        this.attributes = {};
        this.children = [];
        this.isConnected = true;
        this.value = '';
    }
    addEventListener(name, callback) { this.listeners[name] = callback; }
    setAttribute(name, value) { this.attributes[name] = value; }
    getAttribute(name) { return this.attributes[name] ?? null; }
    focus() { browser.activeElement = this; this.focused = true; }
    append(...items) { this.children.push(...items); }
    replaceChildren(...items) { this.children = [...items]; }
    querySelectorAll(selector) {
        if (selector === '[role="option"]') return this.children.filter((child) => child.attributes.role === 'option');
        return [];
    }
    querySelector(selector) {
        if (!selector.startsWith('#')) return null;
        return this.children.find((child) => child.id === selector.slice(1)) ?? null;
    }
    closest(selector) {
        if (selector === '[aria-disabled="true"], .is-disabled') return this.disabled ? this : null;
        if (selector === '.command-os-navigation-item') return this.item ?? null;
        if (selector === 'input, textarea, select, [contenteditable="true"]') return this.editable ? this : null;
        return null;
    }
    scrollIntoView() {}
    contains(target) { return target === this || this.children.some((child) => child.contains?.(target)); }
    showModal() { this.open = true; }
    close() {
        if (!this.open) return;
        this.open = false;
        this.listeners.close?.();
    }
}

let browser;
function createBrowser() {
    const dialog = new MockElement('dialog');
    const trigger = new MockElement('trigger');
    const closeButton = new MockElement('close');
    const input = new MockElement('input');
    const results = new MockElement('results');
    const help = new MockElement('help');
    const navigation = new MockElement('navigation');
    const makePage = (label, href, section = 'Campaigns', disabled = false) => {
        const headerLink = new MockElement('section');
        headerLink.textContent = section;
        const item = new MockElement('item');
        item.querySelector = () => headerLink;
        const anchor = new MockElement(label);
        anchor.textContent = label;
        anchor.href = href;
        anchor.disabled = disabled;
        anchor.item = item;
        return anchor;
    };
    navigation.querySelectorAll = () => [
        makePage('Campaign Register', '/mailer/campaigns'),
        makePage('Drops Monitor', '/mailer/drops', 'Drops'),
        makePage('Restricted Page', '/admin/users', 'Admin', true),
        makePage('Foreign Page', 'https://attacker.invalid/hidden'),
    ];
    dialog.contains = (target) => [input, results, closeButton].includes(target);
    const nodes = {
        'command-os-search-dialog': dialog,
        'command-os-search-input': input,
        'command-os-search-results': results,
        'command-os-search-help': help,
    };
    const documentListeners = {};
    const document = {
        activeElement: trigger,
        querySelector(selector) {
            return {
                '[data-action="command_os_search_open"]': trigger,
                '[data-action="command_os_search_close"]': closeButton,
                '#sidebar .command-os-navigation': navigation,
            }[selector] ?? null;
        },
        getElementById(id) { return nodes[id] ?? null; },
        createElement(name) { return new MockElement(name); },
        addEventListener(name, callback) { documentListeners[name] = callback; },
    };
    browser = { activeElement: trigger, dialog, trigger, closeButton, input, results, help, documentListeners };
    const window = {
        location: { href: 'https://alphalink.test/dashboard/main', origin: 'https://alphalink.test', assign(href) { browser.navigatedTo = href; } },
    };
    runInNewContext(script, { document, window, Element: MockElement, URL, requestAnimationFrame: (callback) => callback() });
    return browser;
}

test('search filters available same-origin navigation and omits disabled destinations', () => {
    const state = createBrowser();
    state.trigger.listeners.click();
    assert.equal(state.dialog.open, true);
    assert.equal(state.results.children.length, 2);

    state.input.value = 'campaign';
    state.input.listeners.input();
    assert.equal(state.results.children.length, 1);
    assert.match(state.results.children[0].children[1].children[0].textContent, /Campaign Register/);
    assert.equal(state.input.getAttribute('aria-activedescendant'), 'command-os-search-result-0');
});

test('arrow keys select destinations and Enter opens the selected route', () => {
    const state = createBrowser();
    state.trigger.listeners.click();
    state.input.listeners.keydown({ key: 'ArrowDown', preventDefault() {} });
    assert.equal(state.results.children[0].getAttribute('aria-selected'), 'false');
    assert.equal(state.results.children[1].getAttribute('aria-selected'), 'true');

    let prevented = false;
    state.input.listeners.keydown({ key: 'Enter', preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(state.navigatedTo, '/mailer/drops');
    assert.equal(state.dialog.open, false);
});

test('Ctrl/Command+K opens search and Escape closes it with focus restored', () => {
    const state = createBrowser();
    state.documentListeners.keydown({ key: 'k', ctrlKey: true, metaKey: false, altKey: false, target: state.trigger, preventDefault() {} });
    assert.equal(state.dialog.open, true);

    state.input.listeners.keydown({ key: 'Escape', preventDefault() {} });
    assert.equal(state.dialog.open, false);
    assert.equal(browser.activeElement, state.trigger);

    const macState = createBrowser();
    macState.documentListeners.keydown({ key: 'k', ctrlKey: false, metaKey: true, altKey: false, target: macState.trigger, preventDefault() {} });
    assert.equal(macState.dialog.open, true);
});
