import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const script = await readFile(new URL('../../public/dms-reference/pmta-vmta-response.js', import.meta.url), 'utf8');
const browser = {};
runInNewContext(script, { window: browser });
const { shouldRejectPmtaResponse } = browser.AlphaLinkPmtaVmtaResponse;

test('keeps per-server VMTA failures available for the result table and toast', () => {
    assert.equal(shouldRejectPmtaResponse(true, {
        success: false,
        results: [{ server: 'Relay A', success: false, message: 'SSH connection failed.' }],
    }), false);
});

test('rejects HTTP and validation failures that do not contain server results', () => {
    assert.equal(shouldRejectPmtaResponse(false, { success: false, message: 'Unavailable.' }), true);
    assert.equal(shouldRejectPmtaResponse(true, { success: false, errors: { server_id: ['Choose a server.'] } }), true);
});

test('accepts successful VMTA responses', () => {
    assert.equal(shouldRejectPmtaResponse(true, { success: true, results: [] }), false);
});
