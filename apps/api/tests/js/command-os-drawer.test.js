import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const script = await readFile(new URL('../../public/console-assets/command-os-drawer.js', import.meta.url), 'utf8');
const stylesheet = await readFile(new URL('../../resources/css/command-os.css', import.meta.url), 'utf8');

class MockElement {
    constructor(name) {
        this.name = name;
        this.attributes = {};
        this.listeners = {};
        this.hidden = false;
        this.inert = false;
        this.isConnected = true;
        this.focusCount = 0;
        this.visible = true;
        this.parent = null;
    }
    setAttribute(name, value) { this.attributes[name] = value; }
    getAttribute(name) { return this.attributes[name] ?? null; }
    removeAttribute(name) { delete this.attributes[name]; }
    addEventListener(name, callback) { this.listeners[name] = callback; }
    focus() { this.focusCount++; browser.activeElement = this; }
    closest(selector) {
        const selectors = selector.split(', ');
        for (let current = this; current; current = current.parent) {
            if (current.selectors?.some((candidate) => selectors.includes(candidate))) return current;
            if (selectors.includes('[hidden]') && current.hidden) return current;
            if (selectors.includes('[aria-hidden="true"]') && current.getAttribute('aria-hidden') === 'true') return current;
        }
        return null;
    }
    getClientRects() { return this.visible ? [1] : []; }
    querySelectorAll(selector) {
        const supportsSummary = selector.includes('summary');
        return (this.items ?? []).filter((item) => !item.isSummary || supportsSummary);
    }
    contains(item) { return this.items?.includes(item) ?? false; }
}

class MockClassList {
    constructor() { this.values = new Set(['command-os-page']); }
    contains(value) { return this.values.has(value); }
    add(value) { this.values.add(value); }
    remove(value) { this.values.delete(value); }
}

let browser;
function createBrowser() {
    const page = new MockElement('page');
    page.classList = new MockClassList();
    const sidebar = new MockElement('sidebar');
    const firstLink = new MockElement('first navigation link');
    const closeButton = new MockElement('close navigation');
    const lastLink = new MockElement('last navigation link');
    const lastSummary = new MockElement('last navigation disclosure');
    lastSummary.isSummary = true;
    const collapsedLink = new MockElement('collapsed submenu link');
    collapsedLink.visible = false;
    const ariaHiddenGroup = new MockElement('aria hidden submenu');
    ariaHiddenGroup.setAttribute('aria-hidden', 'true');
    const ariaHiddenLink = new MockElement('aria hidden submenu link');
    ariaHiddenLink.parent = ariaHiddenGroup;
    sidebar.items = [firstLink, closeButton, lastLink, lastSummary, collapsedLink, ariaHiddenLink];
    const main = new MockElement('main');
    const header = new MockElement('header');
    const footer = new MockElement('footer');
    const opener = new MockElement('open navigation');
    const backdrop = new MockElement('backdrop');
    const desktopWidthToggle = new MockElement('desktop width toggle');
    backdrop.hidden = true;
    const documentListeners = {};
    const breakpointListeners = {};
    browser = { activeElement: opener, layoutCalls: [], breakpointListeners, mutationCallback: null, desktopMatches: true };
    const document = {
        getElementById: (id) => ({
            'page-container': page,
            sidebar,
            'main-container': main,
            'page-header': header,
            'page-footer': footer,
        })[id],
        querySelector: (selector) => selector.includes('sidebar_open') ? opener : selector.includes('sidebar_mini_toggle') ? desktopWidthToggle : backdrop,
        addEventListener: (name, callback) => { documentListeners[name] = callback; },
    };
    Object.defineProperty(document, 'activeElement', {
        get: () => browser.activeElement,
        set: (element) => { browser.activeElement = element; },
    });
    const window = {
        App: { layout(action) {
            browser.layoutCalls.push(action);
            if (action === 'sidebar_close') page.classList.remove('sidebar-o-xs');
        } },
        matchMedia(query) {
            assert.equal(query, '(min-width: 992px)');
            return { get matches() { return browser.desktopMatches; }, addEventListener: (name, callback) => { breakpointListeners[name] = callback; } };
        },
    };
    class MutationObserver {
        constructor(callback) { browser.mutationCallback = callback; }
        observe(target, options) {
            assert.equal(target, page);
            assert.equal(options.attributeFilter.join(','), 'class');
        }
    }
    class Element {
        static [Symbol.hasInstance](target) { return target instanceof MockElement; }
    }
    runInNewContext(script, { document, window, Element, MutationObserver });
    return { page, sidebar, firstLink, closeButton, lastLink, lastSummary, main, header, footer, opener, backdrop, desktopWidthToggle, documentListeners, document };
}

function triggerClick(browserState, target) {
    if (target === browserState.opener) browserState.page.classList.add('sidebar-o-xs');
    browserState.documentListeners.click({ target, preventDefault() {} });
}
function triggerKey(browserState, key, shiftKey = false) {
    let prevented = false;
    browserState.documentListeners.keydown({ key, shiftKey, preventDefault() { prevented = true; } });
    return prevented;
}

test('opens an accessible modal drawer, traps focus, and restores focus on Escape', () => {
    const state = createBrowser();
    state.opener.selectors = ['[data-action="sidebar_open"]'];
    triggerClick(state, state.opener);

    assert.equal(state.page.classList.contains('command-os-sidebar-open'), true);
    assert.equal(state.sidebar.getAttribute('aria-modal'), 'true');
    assert.equal(state.sidebar.getAttribute('role'), 'dialog');
    assert.equal(state.opener.getAttribute('aria-expanded'), 'true');
    assert.equal(state.main.inert, true);
    assert.equal(state.header.inert, true);
    assert.equal(state.footer.inert, true);
    assert.equal(state.backdrop.hidden, false);
    assert.equal(state.firstLink.focusCount, 1);
    assert.equal(triggerKey(state, 'Tab'), false);
    state.document.activeElement = state.lastSummary;
    assert.equal(triggerKey(state, 'Tab'), true);
    assert.equal(browser.activeElement, state.firstLink);
    state.document.activeElement = state.firstLink;
    assert.equal(triggerKey(state, 'Tab', true), true);
    assert.equal(browser.activeElement, state.lastSummary);

    assert.equal(triggerKey(state, 'Escape'), true);
    assert.equal(state.page.classList.contains('command-os-sidebar-open'), false);
    assert.equal(state.page.classList.contains('sidebar-o-xs'), false);
    assert.equal(state.sidebar.getAttribute('aria-modal'), null);
    assert.equal(state.sidebar.getAttribute('role'), null);
    assert.equal(state.opener.getAttribute('aria-expanded'), 'false');
    assert.equal(state.main.inert, false);
    assert.equal(state.header.inert, false);
    assert.equal(state.footer.inert, false);
    assert.equal(state.backdrop.hidden, true);
    assert.equal(browser.activeElement, state.opener);
    assert.deepEqual(browser.layoutCalls, ['sidebar_close']);
});

test('backdrop click dismisses the drawer and restores focus', () => {
    const state = createBrowser();
    state.opener.selectors = ['[data-action="sidebar_open"]'];
    state.backdrop.selectors = ['[data-action="sidebar_backdrop"]'];
    triggerClick(state, state.opener);
    triggerClick(state, state.backdrop);

    assert.equal(state.page.classList.contains('command-os-sidebar-open'), false);
    assert.equal(state.page.classList.contains('sidebar-o-xs'), false);
    assert.equal(state.backdrop.hidden, true);
    assert.equal(state.main.inert, false);
    assert.equal(browser.activeElement, state.opener);
    assert.deepEqual(browser.layoutCalls, ['sidebar_close']);
});

test('native navigation disclosure keeps the chevron inset and the menu in flow', () => {
    assert.match(stylesheet, /\.command-os-navigation-row\s*\{\s*position:\s*relative;\s*display:\s*grid;\s*grid-template-columns:\s*minmax\(0,\s*1fr\)\s+38px;[\s\S]{0,100}padding-right:\s*16px;/);
    assert.match(stylesheet, /\.command-os-navigation-disclosure\s*\{\s*display:\s*contents;/);
    assert.match(stylesheet, /\.command-os-navigation-disclosure\s*>\s*summary\s*\{[\s\S]{0,180}grid-column:\s*2;[\s\S]{0,100}width:\s*38px;/);
    assert.match(stylesheet, /\.command-os-navigation-disclosure\[open\]\s*>\s*summary\s*>\s*i\s*\{\s*transform:\s*rotate\(180deg\);/);
    assert.match(stylesheet, /\.command-os-navigation-submenu\s*\{[\s\S]{0,160}grid-column:\s*1\s*\/\s*-1;[\s\S]{0,80}grid-row:\s*2;/);
    assert.match(stylesheet, /#page-container\.sidebar-mini #sidebar \.command-os-navigation-row\s*\{\s*grid-template-columns:\s*minmax\(0,\s*1fr\);\s*padding-right:\s*0;/);
    assert.match(stylesheet, /#page-container\.sidebar-mini #sidebar \.command-os-navigation-link\s*\{\s*justify-content:\s*center;/);
});

test('entering the desktop breakpoint closes modal state without restoring focus to the mobile opener', () => {
    const state = createBrowser();
    state.opener.selectors = ['[data-action="sidebar_open"]'];
    triggerClick(state, state.opener);
    const focusBeforeResize = browser.activeElement;

    browser.breakpointListeners.change({ matches: true });

    assert.equal(state.page.classList.contains('command-os-sidebar-open'), false);
    assert.equal(state.page.classList.contains('sidebar-o-xs'), false);
    assert.equal(state.sidebar.getAttribute('aria-modal'), null);
    assert.equal(state.sidebar.getAttribute('role'), null);
    assert.equal(state.opener.getAttribute('aria-expanded'), 'false');
    assert.equal(state.main.inert, false);
    assert.equal(state.header.inert, false);
    assert.equal(state.footer.inert, false);
    assert.equal(state.backdrop.hidden, true);
    assert.equal(browser.activeElement, focusBeforeResize);
    assert.notEqual(browser.activeElement, state.opener);
    assert.deepEqual(browser.layoutCalls, []);
});

test('collapsed desktop rail expands before submenu activation and keeps its width toggle synchronized', () => {
    const state = createBrowser();
    state.page.classList.add('sidebar-mini');
    const submenuToggle = new MockElement('campaign submenu toggle');
    submenuToggle.selectors = ['#sidebar a[data-toggle="submenu"]'];
    let prevented = false;
    state.documentListeners.click({ target: submenuToggle, preventDefault() { prevented = true; } });

    assert.equal(state.page.classList.contains('sidebar-mini'), false);
    assert.equal(state.desktopWidthToggle.getAttribute('aria-pressed'), 'false');
    assert.equal(prevented, false, 'OneUI must receive the click to open the existing submenu');
    state.page.classList.add('sidebar-mini');
    browser.mutationCallback();
    assert.equal(state.desktopWidthToggle.getAttribute('aria-pressed'), 'true', 'OneUI collapse must restore the pressed state');
    assert.match(stylesheet, /#sidebar \.nav-main-link-name\s*\{\s*position:\s*absolute;/);
    assert.doesNotMatch(stylesheet, /#page-container\.sidebar-mini #sidebar \.nav-main-link-name,[\s\S]{0,160}?display:\s*none;/);
    assert.match(stylesheet, /#sidebar:focus-within \.nav-main-link-name/);
    assert.match(stylesheet, /#sidebar:hover \.nav-main-link-name/);
    assert.match(stylesheet, /#sidebar:hover \.nav-main-item\.open > \.nav-main-submenu,[\s\S]{0,160}#sidebar:focus-within \.nav-main-item\.open > \.nav-main-submenu\s*\{\s*display:\s*block;/);
    assert.match(stylesheet, /#page-container\.sidebar-mini #sidebar:hover,[\s\S]{0,160}#page-container\.sidebar-mini #sidebar:focus-within\s*\{\s*position:\s*sticky;[\s\S]{0,100}width:\s*240px;/);
    assert.match(stylesheet, /#sidebar:hover \.content-side,/);
    assert.match(stylesheet, /#sidebar:focus-within \.content-side \{\s*transform: translateX\(0\);/);
    assert.match(stylesheet, /body\.command-os-shell #page-container\.sidebar-mini #sidebar \.content-header,[\s\S]{0,120}#sidebar \.content-side\s*\{\s*width: 100% !important;\s*transform: translateX\(0\);/);
    const desktopRulesStart = stylesheet.indexOf('@media (min-width: 992px)');
    const desktopRulesEnd = stylesheet.indexOf('@media (max-width: 1400px)', desktopRulesStart);
    const miniHoverRule = stylesheet.indexOf('#page-container.sidebar-mini #sidebar:hover', desktopRulesStart);
    assert.ok(desktopRulesStart >= 0 && miniHoverRule > desktopRulesStart && miniHoverRule < desktopRulesEnd, 'compact rail hover rules must be desktop scoped');
    assert.match(stylesheet.slice(desktopRulesStart, desktopRulesEnd), /#sidebar:hover,[\s\S]{0,160}#sidebar:focus-within\s*\{\s*position:\s*sticky;[\s\S]{0,120}width:\s*240px;/);
    const mobileRules = stylesheet.slice(stylesheet.indexOf('@media (max-width: 991.98px)'));
    assert.match(mobileRules, /#page-container\.sidebar-mini #sidebar\s*\{\s*position:\s*fixed;[\s\S]{0,100}transform:\s*translateX\(-105%\);/);
    assert.match(mobileRules, /#page-container\.sidebar-mini\.command-os-sidebar-open #sidebar\s*\{\s*transform:\s*translateX\(0\);/);
});

test('desktop mini rail stays hidden in mobile state and restores correctly after resizing back to desktop', () => {
    const state = createBrowser();
    state.page.classList.add('sidebar-mini');
    browser.mutationCallback();
    assert.equal(state.desktopWidthToggle.getAttribute('aria-pressed'), 'true');

    browser.desktopMatches = false;
    browser.breakpointListeners.change({ matches: false });
    state.opener.selectors = ['[data-action="sidebar_open"]'];
    triggerClick(state, state.opener);
    assert.equal(state.page.classList.contains('sidebar-mini'), true);
    assert.equal(state.page.classList.contains('command-os-sidebar-open'), true);
    assert.equal(state.opener.getAttribute('aria-expanded'), 'true');
    assert.equal(state.desktopWidthToggle.getAttribute('aria-pressed'), 'true');

    browser.desktopMatches = true;
    browser.breakpointListeners.change({ matches: true });
    assert.equal(state.page.classList.contains('sidebar-mini'), true);
    assert.equal(state.page.classList.contains('command-os-sidebar-open'), false);
    assert.equal(state.opener.getAttribute('aria-expanded'), 'false');
    assert.equal(state.desktopWidthToggle.getAttribute('aria-pressed'), 'true');
    assert.equal(state.backdrop.hidden, true);
    assert.equal(state.main.inert, false);
});
