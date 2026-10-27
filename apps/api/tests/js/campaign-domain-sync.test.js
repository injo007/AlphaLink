import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/console/campaigns/create.blade.php', import.meta.url), 'utf8');
const syncStart = view.indexOf('            const syncCampaignDomainToRoute = () => {');
const syncEnd = view.indexOf('\n            const selectedCreative', syncStart);
assert.notEqual(syncStart, -1, 'campaign route-domain synchronizer exists');
assert.notEqual(syncEnd, -1, 'campaign route-domain synchronizer closes');
const syncSource = view.slice(syncStart, syncEnd);

function createOption(dataset, value = 'vmta-choice-1') {
    return { dataset, value };
}

function runSync({ route = 'vmta', selectedChoices = [], availableChoices = [], selectedServers = ['server-1'], domain = 'stale-ip.example.test', body = 'https://stale-ip.example.test/path' } = {}) {
    const campaignDomainField = {
        value: domain,
        options: [],
        add(option) { this.options.push(option); },
    };
    const campaignDomainHint = { hidden: true, textContent: '' };
    const bodyField = { value: body };
    const env = {
        campaignRouteField: { value: route },
        campaignIpsField: { selectedOptions: selectedChoices, options: availableChoices },
        campaignDomainField,
        campaignDomainHint,
        document: {
            getElementById(id) {
                assert.equal(id, 'send-servers');

                return { selectedOptions: selectedServers.map((value) => ({ value })) };
            },
        },
        window: {},
        Option: function Option(text, value) { this.text = text; this.value = value; },
        applyCampaignDomainToBody(value) {
            bodyField.value = bodyField.value.replace(/(https?:\/\/)[^/\s"'<>]+/gi, `$1${value}`);
        },
    };

    vm.runInNewContext(`${syncSource}\nsyncCampaignDomainToRoute();`, env);

    return { body: bodyField.value, domain: campaignDomainField.value, hint: campaignDomainHint };
}

test('a selected VMTA without a domain clears the old Domain and replaces body URL hosts', () => {
    const vmta = createOption({ serverId: 'server-1', route: 'vmta', vmtaDomain: '' });
    const result = runSync({ selectedChoices: [vmta] });

    assert.equal(result.domain, '');
    assert.equal(result.body, 'https://[domain]/path');
    assert.equal(result.hint.hidden, false);
    assert.match(result.hint.textContent, /no configured domain/i);
});

test('an unselected VMTA with no domain is found from the selected server inventory', () => {
    const vmta = createOption({ serverId: 'server-1', route: 'vmta', vmtaDomain: '' });
    const otherServerVmta = createOption({ serverId: 'server-2', route: 'vmta', vmtaDomain: 'other.example.test' });
    const result = runSync({ availableChoices: [vmta, otherServerVmta] });

    assert.equal(result.domain, '');
    assert.equal(result.body, 'https://[domain]/path');
    assert.match(result.hint.textContent, /no configured domain/i);
});

test('a selected VMTA domain replaces the Domain value and all body URL hosts', () => {
    const vmta = createOption({ serverId: 'server-1', route: 'vmta', vmtaDomain: 'vmta.example.test' });
    const result = runSync({ selectedChoices: [vmta] });

    assert.equal(result.domain, 'vmta.example.test');
    assert.equal(result.body, 'https://vmta.example.test/path');
    assert.equal(result.hint.hidden, false);
    assert.match(result.hint.textContent, /using the selected VMTA domain/i);
});
