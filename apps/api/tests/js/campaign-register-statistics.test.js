import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/console/campaigns/index.blade.php', import.meta.url), 'utf8');
const start = view.indexOf('            const metricDefinitions = [');
const end = view.indexOf("            document.querySelectorAll('[data-campaign-statistics-toggle]')", start);
assert.notEqual(start, -1, 'campaign register statistics summary exists');
assert.notEqual(end, -1, 'campaign register statistics summary closes before toggle handler');
const summarySource = view.slice(start, end);

class FakeElement {
    constructor() {
        this.children = [];
        this.className = '';
        this.scope = '';
        this.text = '';
    }

    set textContent(value) {
        this.text = String(value);
        this.children = [];
    }

    get textContent() {
        return this.text || this.children.map(child => child.textContent).join('');
    }

    append(...children) {
        this.children.push(...children);
    }

    replaceChildren(...children) {
        this.text = '';
        this.children = [...children];
    }
}

function render(rows) {
    const target = new FakeElement();
    vm.runInNewContext(`${summarySource}\nrenderCampaignStatistics(target, rows);`, {
        target,
        rows,
        document: { createElement: () => new FakeElement() },
    });

    return target;
}

test('inline campaign statistics render the captured summary columns and campaign totals', () => {
    const target = render([
        { at: '2026-10-30', deliveries: 10, opens: 7, clicks: 3, leads: 1, unsubscribes: 0, lead_revenue: 4.5 },
        { at: '2026-10-30', deliveries: 20, opens: 8, clicks: 2, leads: 2, unsubscribes: 1, lead_revenue: 8 },
        { at: '2026-10-29', deliveries: 5, opens: 2, clicks: 1, leads: 0, unsubscribes: 0, lead_revenue: 0 },
    ]);

    const table = target.children[0];
    assert.deepEqual(table.children[0].children[0].children.map(cell => cell.textContent), ['OPEN', 'CLICK', 'COUNT LEAD', 'LEAD', 'UNSUBSCRIBE', 'BOT', 'EPC']);
    assert.deepEqual(table.children[1].children[0].children.map(cell => cell.textContent), ['17', '6', '3', '$12.5', '1', '0', '$2.08']);
});

test('campaign register uses live event counts for its inline statistics request', () => {
    assert.match(view, /'inline'\s*=>\s*1/);
    assert.match(view, /minimumFractionDigits:\s*0/);
});

test('inline campaign statistics show a styled empty state when the selected range has no activity', () => {
    const target = render([]);

    assert.equal(target.children[0].className, 'campaign-statistics-empty');
    assert.equal(target.textContent, 'No campaign statistics are recorded.');
    assert.equal(target.children.length, 1);
});
