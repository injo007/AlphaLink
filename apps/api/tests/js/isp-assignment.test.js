import test from 'node:test';
import assert from 'node:assert/strict';
import { applyDmsCheckAll } from '../../public/alphalink/infrastructure/isp-assignment.js';

test('DMS CHECK inverts the first checked row across the complete assignment list', () => {
    const allChecked = [{ checked: true }, { checked: true }, { checked: true }];
    applyDmsCheckAll(allChecked);
    assert.deepEqual(allChecked.map((checkbox) => checkbox.checked), [false, false, false]);

    const mixedWithFirstUnchecked = [{ checked: false }, { checked: true }, { checked: false }];
    applyDmsCheckAll(mixedWithFirstUnchecked);
    assert.deepEqual(mixedWithFirstUnchecked.map((checkbox) => checkbox.checked), [true, true, true]);
});

test('DMS CHECK leaves an empty assignment list unchanged', () => {
    assert.doesNotThrow(() => applyDmsCheckAll([]));
});
