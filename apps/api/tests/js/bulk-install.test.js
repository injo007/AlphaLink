import test from 'node:test';
import assert from 'node:assert/strict';
import { buildDmsBulkInstallPayload, dmsApplyDomainIndex } from '../../public/dms-reference/bulk-install.js';

test('matches DMS bulk-install submission fields, omits rows without a domain, and sends switches as 0/1', () => {
    const payload = buildDmsBulkInstallPayload({
        rows: [
            { name: 'Relay A', id: 'server-a', ip: '192.0.2.10', domain: 'domain-a', check: true },
            { name: 'Relay B', id: 'server-b', ip: '192.0.2.11', domain: '', check: false },
        ],
        key: 1782155053000,
        pmtaVersion: '45',
        switches: { tracking: true, proxy: false, imap: false, spf: true, dkim: false, dmarc: true, https: false },
    });

    assert.deepEqual(payload, {
        data: [{ name: 'Relay A', id: 'server-a', ip: '192.0.2.10', domain: 'domain-a', check: true }],
        key: 1782155053000,
        pmta_version: 45,
        tracking: 1,
        proxy: 0,
        imap: 0,
        spf: 1,
        dkim: 0,
        dmarc: 1,
        https: 0,
    });
});

test('does not treat a workspace UUID as an empty numeric domain id', () => {
    const payload = buildDmsBulkInstallPayload({
        rows: [{ id: 'server-a', domain: '91db3e68-04c3-4cf2-a3f8-71b96e6c7f8c' }],
        key: 1,
        pmtaVersion: 40,
        switches: {},
    });

    assert.equal(payload.data.length, 1);
});

test('APPLY copies even the blank option and blocks owned domains', () => {
    assert.equal(dmsApplyDomainIndex(0, ''), 0);
    assert.equal(dmsApplyDomainIndex(2, 'owned.example.org | IS OUR: YES'), null);
    assert.equal(dmsApplyDomainIndex(-1, ''), null);
});
