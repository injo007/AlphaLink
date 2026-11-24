import test from 'node:test';
import assert from 'node:assert/strict';
import { buildSingleInstallPayload, installFailureLogMessage, responseErrorMessage } from '../../public/alphalink/infrastructure/single-install.js';

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

test('blocked readiness does not leave a false preparing-installation status', () => {
    assert.equal(
        installFailureLogMessage({ code: 'pmta_deployment_not_ready' }),
        'Not started: preflight blocked the request before an install run was queued. No target-server changes were made.',
    );
    assert.equal(
        installFailureLogMessage({ code: 'unexpected_server_error' }),
        'The installation start was not confirmed. Check run status before retrying to avoid duplicate work.',
    );
});

test('submits the supported per-IP assignments and uses the main IP domain as the command domain', () => {
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
        server_id: 'server-1',
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
        clean_dns: 0,
        multi_domain_per_ip: 0,
    });
});

test('single install submits extra domain assignments only when the multi-domain switch is active', () => {
    const payload = buildSingleInstallPayload({
        serverId: 'server-1',
        rows: [{
            ip: '192.0.2.10', type: 'ipv4', main: true, sub: 'mail', domain_id: 'domain-a',
            domains: [{ domain_id: 'domain-b', sub: 'offers' }],
        }],
        key: 1,
        pmtaVersion: 45,
        switches: { multi_domain_per_ip: true },
    });

    assert.deepEqual(payload.data[0].ip_assignments[0], {
        ip: '192.0.2.10', type: 'ipv4', main: true, sub: 'mail', domain_id: 'domain-a',
        domains: [{ domain_id: 'domain-b', sub: 'offers' }],
    });
    assert.equal(payload.multi_domain_per_ip, 1);
    assert.equal(payload.domains_per_ip, 2);
});
