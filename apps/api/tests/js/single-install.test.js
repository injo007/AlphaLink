import test from 'node:test';
import assert from 'node:assert/strict';
import { buildSingleInstallPayload, responseErrorMessage } from '../../public/alphalink/infrastructure/single-install.js';

test('single-install loader always gives a visible message for empty and validation error responses', () => {
    assert.equal(
        responseErrorMessage({}, 'Could not load server IPs.'),
        'Could not load server IPs.',
    );
    assert.equal(
        responseErrorMessage({ errors: { server_id: ['Select a valid server.'] } }, 'Could not load server IPs.'),
        'Select a valid server.',
    );
});

test('submits DMS per-IP assignments and uses the main IP domain as the command domain', () => {
    const payload = buildSingleInstallPayload({
        serverId: 'server-1',
        rows: [
            { ip: '192.0.2.10', type: 'IPV4', main: false, sub: 'relay-a', domain_id: 'domain-a' },
            { ip: '2001:db8::10', type: 'IPV6', main: true, sub: '', domain_id: 'domain-b' },
        ],
        key: 1782155053000,
        pmtaVersion: '45',
        switches: { tracking: true, proxy: false, imap: true, spf: true, dkim: true, dmarc: true, https: false },
    });

    assert.deepEqual(payload, {
        data: [{
            id: 'server-1',
            domain: 'domain-b',
            ip_assignments: [
                { ip: '192.0.2.10', type: 'ipv4', main: false, sub: 'relay-a', domain_id: 'domain-a' },
                { ip: '2001:db8::10', type: 'ipv6', main: true, sub: '', domain_id: 'domain-b' },
            ],
        }],
        key: 1782155053000,
        pmta_version: 45,
        tracking: 1,
        proxy: 0,
        imap: 1,
        spf: 1,
        dkim: 1,
        dmarc: 1,
        https: 0,
    });
});
