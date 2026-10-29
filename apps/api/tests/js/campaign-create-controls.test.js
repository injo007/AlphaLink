import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/console/campaigns/create.blade.php', import.meta.url), 'utf8');

const fillStart = view.indexOf('            const fill = (key, records, getValue, getLabel, selected, placeholder) => {');
const fillEnd = view.indexOf('\n            const paintOptions', fillStart);
assert.notEqual(fillStart, -1, 'campaign-choice fill helper exists');
assert.notEqual(fillEnd, -1, 'campaign-choice fill helper closes');
const fillSource = view.slice(fillStart, fillEnd);

function createSelect(multiple) {
    return {
        multiple,
        required: false,
        value: '',
        options: [],
        replaceChildren() { this.options = []; },
        append(option) { this.options.push(option); },
    };
}

test('From and Subject rotation selectors remain optional while audience, server and IP selectors stay required', () => {
    const fields = {
        fromAssets: createSelect(true),
        subjectAssets: createSelect(true),
        lists: createSelect(true),
        servers: createSelect(true),
        ips: createSelect(true),
    };
    const targeting = { hidden: false };
    const env = {
        fields,
        targeting,
        document: {
            getElementById(id) {
                assert.equal(id, 'campaign-targeting');
                return targeting;
            },
            createElement() { return { value: '', textContent: '', dataset: {}, selected: false }; },
        },
        select: (key) => fields[key],
        selectedValues: (element) => element.options.filter((option) => option.selected).map((option) => option.value),
        pickerRefresh() {},
    };

    vm.runInNewContext(`${fillSource}
        fill('fromAssets', [], (item) => item.id, (item) => item.value, [], 'Select optional From values');
        fill('subjectAssets', [], (item) => item.id, (item) => item.value, [], 'Select optional subjects');
        fill('lists', [], (item) => item.id, (item) => item.value, [], 'Select Lists');
        fill('servers', [], (item) => item.id, (item) => item.value, [], 'Select Servers');
        fill('ips', [], (item) => item.id, (item) => item.value, [], 'Select IPs');
        globalThis.visibleRequirements = Object.fromEntries(Object.entries(fields).map(([key, element]) => [key, element.required]));
        targeting.hidden = true;
        fill('lists', [], (item) => item.id, (item) => item.value, [], 'Select Lists');
        globalThis.hiddenListRequirement = fields.lists.required;`, env);

    assert.equal(env.visibleRequirements.fromAssets, false);
    assert.equal(env.visibleRequirements.subjectAssets, false);
    assert.equal(env.visibleRequirements.lists, true);
    assert.equal(env.visibleRequirements.servers, true);
    assert.equal(env.visibleRequirements.ips, true);
    assert.equal(env.hiddenListRequirement, false);
});

const transferStart = view.indexOf("            const ipSource = document.getElementById('send-ips');");
const transferEnd = view.indexOf("            document.querySelectorAll('[data-copy-ip-list]')", transferStart);
assert.notEqual(transferStart, -1, 'IP picker controls exist');
assert.notEqual(transferEnd, -1, 'IP picker controls close before copy actions');
const transferSource = view.slice(transferStart, transferEnd);

class FakeSelect {
    constructor(options = []) {
        this.options = options;
        this.listeners = new Map();
        this.events = [];
        this.attributes = new Map();
    }

    get selectedOptions() {
        return this.options.filter((option) => option.selected);
    }

    replaceChildren() {
        this.options = [];
    }

    add(option) {
        this.options.push(option);
    }

    addEventListener(name, handler) {
        this.listeners.set(name, handler);
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }

    dispatchEvent(event) {
        this.events.push(event);
    }

    fire(name) {
        this.listeners.get(name)?.({ target: this });
    }
}

function runTransfer() {
    const source = new FakeSelect([
        { value: 'ip-1', textContent: '203.0.113.10 | IPv4', selected: false, dataset: { ip: '203.0.113.10' } },
        { value: 'ip-2', textContent: '2001:db8::10 | IPv6', selected: false, dataset: { ip: '2001:db8::10' } },
    ]);
    const available = new FakeSelect();
    const chosen = new FakeSelect();
    const availableFilter = { value: '', placeholder: '', listeners: new Map(), addEventListener(name, handler) { this.listeners.set(name, handler); } };
    const chosenFilter = { value: '', placeholder: '', listeners: new Map(), addEventListener(name, handler) { this.listeners.set(name, handler); } };
    const count = { textContent: '' };
    const route = { value: 'ip', listeners: new Map(), addEventListener(name, handler) { this.listeners.set(name, handler); }, fire(name) { this.listeners.get(name)?.({ target: this }); } };
    const heading = { textContent: '' };
    const availableLabel = { textContent: '' };
    const chosenLabel = { textContent: '' };
    const buttons = ['all-right', 'right', 'left', 'all-left'].map((ipMove) => ({
        dataset: { ipMove },
        listeners: new Map(),
        attributes: new Map(),
        setAttribute(name, value) { this.attributes.set(name, value); },
        addEventListener(name, handler) { this.listeners.set(name, handler); },
        fire(name) { this.listeners.get(name)?.({ target: this }); },
    }));
    const elements = {
        'send-ips': source,
        'send-ips-available': available,
        'send-ips-selected': chosen,
        'send-ips-available-filter': availableFilter,
        'send-ips-selected-filter': chosenFilter,
        'send-ips-selected-count': count,
        'send-route': route,
        'send-route-choice-heading': heading,
        'send-ips-available-label': availableLabel,
        'send-ips-selected-label': chosenLabel,
    };
    const env = {
        document: {
            getElementById(id) { return elements[id]; },
            querySelectorAll(selector) {
                assert.equal(selector, '[data-ip-move]');
                return buttons;
            },
        },
        Option: function Option(text, value) {
            this.textContent = text;
            this.value = value;
            this.selected = false;
        },
        Event: function Event(type, options) { this.type = type; this.options = options; },
    };

    vm.runInNewContext(`${transferSource}
        renderIpLists();`, env);

    return { source, available, chosen, count, buttons, route, heading, availableLabel, chosenLabel, availableFilter, chosenFilter };
}

test('double-click moves an available IP into Selected IPs and updates the hidden form selection', () => {
    const { source, available, chosen, count } = runTransfer();
    assert.equal(available.options.length, 2);
    available.options[0].selected = true;
    available.fire('dblclick');

    assert.equal(source.options[0].selected, true);
    assert.deepEqual(chosen.options.map((option) => option.value), ['ip-1']);
    assert.equal(count.textContent, '1');
    assert.equal(source.events.at(-1).type, 'change');
});

test('double-click moves a selected IP back to Available IPs', () => {
    const { source, available, chosen, count } = runTransfer();
    available.options[0].selected = true;
    available.fire('dblclick');
    chosen.options[0].selected = true;
    chosen.fire('dblclick');

    assert.equal(source.options[0].selected, false);
    assert.deepEqual(available.options.map((option) => option.value), ['ip-1', 'ip-2']);
    assert.equal(chosen.options.length, 0);
    assert.equal(count.textContent, '0');
});

test('arrow controls continue to move IPs for keyboard-accessible operation', () => {
    const { source, available, chosen, buttons } = runTransfer();
    available.options[1].selected = true;
    buttons.find((button) => button.dataset.ipMove === 'right').fire('click');

    assert.equal(source.options[1].selected, true);
    assert.deepEqual(chosen.options.map((option) => option.value), ['ip-2']);
});

test('route picker labels and accessible controls switch to SMTP route terminology', () => {
    const { route, heading, availableLabel, chosenLabel, available, chosen, availableFilter, chosenFilter, buttons } = runTransfer();
    route.value = 'smtp';
    route.fire('change');

    assert.equal(heading.textContent, 'SMTP routes');
    assert.equal(availableLabel.textContent, 'Available SMTP routes');
    assert.equal(chosenLabel.textContent, 'Selected SMTP routes');
    assert.equal(available.attributes.get('aria-label'), 'Available SMTP routes');
    assert.equal(chosen.attributes.get('aria-label'), 'Selected SMTP routes');
    assert.equal(availableFilter.placeholder, 'Filter available smtp routes');
    assert.equal(chosenFilter.placeholder, 'Filter selected smtp routes');
    assert.equal(buttons[0].attributes.get('aria-label'), 'Move all SMTP routes to selected');
});

test('IP route choices disclose linked SMTP account and ambiguity', () => {
    const start = view.indexOf('                const routeChoiceLabel = (ip, routeOption) => {');
    const end = view.indexOf('\n                };', start) + '\n                };'.length;
    assert.notEqual(start, -1, 'route choice label helper exists');
    assert.ok(end > start, 'route choice label helper closes');
    const source = view.slice(start, end);
    const env = {};
    vm.runInNewContext(`${source}\nglobalThis.routeChoiceLabel = routeChoiceLabel;`, env);

    assert.equal(env.routeChoiceLabel({ ip: '203.0.113.15', dns: 'mail.example.test', smtp_account_count: 1, username: 'relay@example.test' }, 'ip'), '203.0.113.15 | mail.example.test | SMTP: relay@example.test');
    assert.equal(env.routeChoiceLabel({ ip: '203.0.113.16', dns: 'mail.example.test', smtp_account_count: 0 }, 'ip'), '203.0.113.16 | mail.example.test');
    assert.match(env.routeChoiceLabel({ ip: '203.0.113.17', dns: 'mail.example.test', smtp_account_count: 2 }, 'ip'), /Multiple SMTP accounts/);
});

test('campaign routing help explains SMTP tags and distinguishes PMTA ingress from outbound route', () => {
    const view = readFileSync(new URL('../../resources/views/console/campaigns/create.blade.php', import.meta.url), 'utf8');
    assert.match(view, /id="send-route-behavior"/);
    assert.match(view, /SMTP mode uses the selected SMTP account for outbound routing/);
    assert.match(view, /\[smtp\]<\/code> is its username and <code>\[smtp_name\]/);
    assert.match(view, /PMTA applies the selected outbound route/);
    assert.match(view, /In IP mode, if the content uses/);
    assert.match(view, /Campaign Test connects to the server’s PMTA listener/);
    assert.match(view, /exactly one active SMTP account must be linked to that IP and its route is used for outbound delivery/);
    assert.match(view, /Without those tags, Campaign Test uses the selected IP route/);
    assert.match(view, /server\.pmta_panel_display_url \|\| server\.pmta_panel_url/);
});
