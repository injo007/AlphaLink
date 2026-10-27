import test from 'node:test';
import assert from 'node:assert/strict';
import { calculateLineCoordinates, compareSortValues } from '../../public/dms-reference/isp-stats-charts.js';

const padding = { top: 10, right: 10, bottom: 10, left: 10 };

test('ISP chart positions periods in order and scales larger values higher', () => {
    const points = calculateLineCoordinates([0, 5, 10], 120, 100, padding);

    assert.equal(points.length, 3);
    assert.equal(points[0].x, 10);
    assert.equal(points[2].x, 110);
    assert.ok(points[2].y < points[1].y);
    assert.ok(points[1].y < points[0].y);
});

test('ISP chart keeps series on a shared scale and centers a single period', () => {
    const smallSeries = calculateLineCoordinates([5], 120, 100, padding, 0, 10);
    const largeSeries = calculateLineCoordinates([10], 120, 100, padding, 0, 10);

    assert.equal(smallSeries[0].x, 60);
    assert.ok(largeSeries[0].y < smallSeries[0].y);
});

test('ISP chart handles empty and negative attributed revenue values', () => {
    assert.deepEqual(calculateLineCoordinates([], 120, 100, padding), []);

    const points = calculateLineCoordinates([-4, 0, 4], 120, 100, padding);
    assert.ok(points[0].y > points[1].y);
    assert.ok(points[1].y > points[2].y);
});

test('ISP report table sort compares numbers and calendar labels correctly', () => {
    assert.ok(compareSortValues('2', '10', 'number') < 0);
    assert.ok(compareSortValues('2026-09-30', '2026-10-01') < 0);
    assert.equal(compareSortValues('Mailer A', 'mailer a'), 0);
});
