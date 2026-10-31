import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/dms-reference/campaign-register-controls.js', import.meta.url), 'utf8');

class FakeElement {
    constructor() {
        this.hidden = false;
        this.value = '';
        this.listeners = {};
        this.focused = false;
        this.submitted = 0;
    }

    addEventListener(type, callback) {
        this.listeners[type] = callback;
    }

    dispatch(type) {
        this.listeners[type]?.();
    }

    focus() {
        this.focused = true;
    }

    requestSubmit() {
        this.submitted += 1;
    }
}

function boot(searchValue = 'campaign') {
    const search = new FakeElement();
    search.value = searchValue;
    const clear = new FakeElement();
    const searchForm = new FakeElement();
    const elements = { search, clear, searchForm };
    const document = {
        getElementById: id => ({
            'campaign-search': search,
            'campaign-search-clear': clear,
            'campaign-filter-form': searchForm,
        })[id] ?? null,
    };

    vm.runInNewContext(source, { document });

    return elements;
}

test('campaign register search clear resets the query and submits the current filters', () => {
    const elements = boot();

    assert.equal(elements.clear.hidden, false);
    elements.clear.dispatch('click');

    assert.equal(elements.search.value, '');
    assert.equal(elements.search.focused, true);
    assert.equal(elements.clear.hidden, true);
    assert.equal(elements.searchForm.submitted, 1);
});

test('campaign register search clear stays hidden for an empty query', () => {
    const elements = boot('   ');

    assert.equal(elements.clear.hidden, true);
});
