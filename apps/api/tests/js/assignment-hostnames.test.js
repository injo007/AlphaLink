import test from 'node:test';
import assert from 'node:assert/strict';
import { findDuplicateSendingHostname } from '../../public/alphalink/infrastructure/assignment-hostnames.js';

test('allows sibling subdomains from the same registered domain on one IP', () => {
    assert.equal(findDuplicateSendingHostname([
        { domain_id: 'domain-a', sub: 'Mail' },
        { domain_id: 'domain-a', sub: 'offers' },
        { domain_id: 'domain-a', sub: 'clicks' },
    ], () => 'apexhub.buzz'), null);
});

test('returns the repeated effective hostname even when domain labels differ in case', () => {
    assert.equal(findDuplicateSendingHostname([
        { domain_id: 'domain-a', sub: 'mail' },
        { domain_id: 'domain-a', sub: 'MAIL' },
    ], () => 'ApexHub.Buzz.'), 'mail.apexhub.buzz');
});
