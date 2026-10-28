import assert from 'node:assert/strict';
import test from 'node:test';

import {
    allStatsPivotFields,
    campaignStatisticsPivotFields,
    mailerRevenuePivotFields,
    projectAllStatsRows,
    projectCampaignStatisticsRows,
    projectMailerRevenueRows,
} from '../../public/dms-reference/analytics-pivot.js';

test('All Stats pivot projects event rows into count measures without recipient fields', () => {
    const rows = projectAllStatsRows([
        { at: '2026-10-26', user: 'Operator', opens: 3, email: 'private@example.test' },
        { at: '2026-10-26', user: 'Operator', clicks: 2 },
        { at: '2026-10-26', user: 'Operator', leads: 1, lead_revenue: 6.25 },
        { at: '2026-10-26', user: 'Operator', unsubscribes: 1 },
        { at: '2026-10-26', user: 'Operator', bots: 4 },
        { at: '2026-10-26', user: 'Operator', deliveries: 7 },
        { at: '2026-10-26', user: 'Operator', soft_bounces: 2 },
        { at: '2026-10-26', user: 'Operator', hard_bounces: 1 },
    ]);

    assert.deepEqual(rows.map(({ opens, clicks, leads, unsubscribes, bots, deliveries, soft_bounces, hard_bounces, lead_revenue }) => ({
        opens,
        clicks,
        leads,
        unsubscribes,
        bots,
        deliveries,
        soft_bounces,
        hard_bounces,
        lead_revenue,
    })), [
        { opens: 3, clicks: 0, leads: 0, unsubscribes: 0, bots: 0, deliveries: 0, soft_bounces: 0, hard_bounces: 0, lead_revenue: 0 },
        { opens: 0, clicks: 2, leads: 0, unsubscribes: 0, bots: 0, deliveries: 0, soft_bounces: 0, hard_bounces: 0, lead_revenue: 0 },
        { opens: 0, clicks: 0, leads: 1, unsubscribes: 0, bots: 0, deliveries: 0, soft_bounces: 0, hard_bounces: 0, lead_revenue: 6.25 },
        { opens: 0, clicks: 0, leads: 0, unsubscribes: 1, bots: 0, deliveries: 0, soft_bounces: 0, hard_bounces: 0, lead_revenue: 0 },
        { opens: 0, clicks: 0, leads: 0, unsubscribes: 0, bots: 4, deliveries: 0, soft_bounces: 0, hard_bounces: 0, lead_revenue: 0 },
        { opens: 0, clicks: 0, leads: 0, unsubscribes: 0, bots: 0, deliveries: 7, soft_bounces: 0, hard_bounces: 0, lead_revenue: 0 },
        { opens: 0, clicks: 0, leads: 0, unsubscribes: 0, bots: 0, deliveries: 0, soft_bounces: 2, hard_bounces: 0, lead_revenue: 0 },
        { opens: 0, clicks: 0, leads: 0, unsubscribes: 0, bots: 0, deliveries: 0, soft_bounces: 0, hard_bounces: 1, lead_revenue: 0 },
    ]);
    assert.equal('email' in rows[0], false);
});

test('All Stats pivot exposes DMS dimensions and stored AlphaLink measures', () => {
    const fields = allStatsPivotFields();
    const byCaption = Object.fromEntries(fields.map((field) => [field.caption, field]));

    for (const caption of ['MAILER', 'ISP', 'LIST', 'LIST TYPE', 'PROVIDER', 'SERVER', 'IP', 'DOMAIN', 'SPONSOR', 'OFFER', 'VERTICAL', 'FROM', 'SUBJECT', 'CREATIVE', 'DATE']) {
        assert.ok(byCaption[caption], `expected ${caption} pivot dimension`);
    }
    for (const caption of ['OPENS', 'CLICKS', 'LEADS', 'UNSUBS', 'BOTS', 'DELIVERIES', 'SOFT BOUNCES', 'HARD BOUNCES', 'ATTRIBUTED LEAD REVENUE', 'EPC']) {
        assert.equal(byCaption[caption].area, 'data');
    }
    assert.equal(byCaption.CONVERSIONS, undefined);
    assert.equal(typeof byCaption.CTOR.calculateSummaryValue, 'function');
    assert.equal(byCaption.CTOR.area, undefined);
    assert.equal(typeof byCaption.EPC.calculateSummaryValue, 'function');
});

test('All Stats calculates DMS CTOR and EPC summaries with zero-denominator handling', () => {
    const byCaption = Object.fromEntries(allStatsPivotFields().map((field) => [field.caption, field]));
    const summary = (values) => ({ value: (field) => values[field] ?? 0 });

    assert.equal(byCaption.CTOR.calculateSummaryValue(summary({ opens: 8, clicks: 2 })), '25.00%');
    assert.equal(byCaption.CTOR.calculateSummaryValue(summary({ opens: 0, clicks: 2 })), '0.00%');
    assert.equal(byCaption.EPC.calculateSummaryValue(summary({ lead_revenue: 7.5, clicks: 3 })), '$2.50');
    assert.equal(byCaption.EPC.calculateSummaryValue(summary({ lead_revenue: 7.5, clicks: 0 })), '$0.00');
});

test('Mailer Revenue pivot projects workspace counts and attributed revenue without recipient fields', () => {
    const rows = projectMailerRevenueRows([{
        user: 'Mailer One',
        opens: '8',
        clicks: '2',
        leads: '1',
        deliveries: '7',
        soft_bounces: '2',
        hard_bounces: '1',
        unsubscribes: '1',
        bots: '3',
        lead_revenue: '7.50',
        email: 'private@example.test',
    }]);

    assert.deepEqual(rows, [{
        user: 'Mailer One',
        opens: 8,
        clicks: 2,
        leads: 1,
        deliveries: 7,
        soft_bounces: 2,
        hard_bounces: 1,
        unsubscribes: 1,
        bots: 3,
        lead_revenue: 7.5,
    }]);
});

test('Mailer Revenue exposes imported delivery and bounce counts with partial CTOR/EPC formulas', () => {
    const fields = mailerRevenuePivotFields();
    const byCaption = Object.fromEntries(fields.map((field) => [field.caption, field]));
    const summary = (values) => ({ value: (field) => values[field] ?? 0 });

    for (const caption of ['MAILER', 'OPENS', 'CLICKS', 'LEADS', 'DELIVERIES', 'SOFT BOUNCES', 'HARD BOUNCES', 'UNSUBS', 'BOTS', 'ATTRIBUTED LEAD REVENUE', 'CTOR', 'EPC']) {
        assert.ok(byCaption[caption], `expected ${caption} pivot field`);
    }
    assert.equal(byCaption.MAILER.area, 'row');
    assert.equal(byCaption.DELIVERIES.dataField, 'deliveries');
    assert.equal(byCaption['SOFT BOUNCES'].dataField, 'soft_bounces');
    assert.equal(byCaption['HARD BOUNCES'].dataField, 'hard_bounces');
    assert.equal(byCaption.EARNINGS, undefined);
    assert.equal(byCaption['OPT-OUTS'], undefined);
    assert.equal(byCaption.CTOR.calculateSummaryValue(summary({ opens: 8, clicks: 2 })), '25.00%');
    assert.equal(byCaption.CTOR.calculateSummaryValue(summary({ opens: 0, clicks: 2 })), '0.00%');
    assert.equal(byCaption.EPC.calculateSummaryValue(summary({ lead_revenue: 7.5, clicks: 3 })), '$2.50');
    assert.equal(byCaption.EPC.calculateSummaryValue(summary({ lead_revenue: 7.5, clicks: 0 })), '$0.00');
});

test('Campaign Statistics pivot projects only supported fields and omits contact addresses', () => {
    const rows = projectCampaignStatisticsRows([{
        at: '2026-10-26',
        user: 'Mailer One',
        campaign_id: 'campaign-1',
        opens: '8',
        clicks: '2',
        leads: '1',
        deliveries: '37',
        soft_bounces: '4',
        hard_bounces: '2',
        unsubscribes: '1',
        bots: '3',
        lead_revenue: '7.50',
        email: 'private@example.test',
    }]);

    assert.equal(rows[0].campaign_id, 'campaign-1');
    assert.equal(rows[0].opens, 8);
    assert.equal(rows[0].deliveries, 37);
    assert.equal(rows[0].soft_bounces, 4);
    assert.equal(rows[0].hard_bounces, 2);
    assert.equal(rows[0].lead_revenue, 7.5);
    assert.equal('email' in rows[0], false);
});

test('Campaign Statistics pivot exposes DMS dimensions, conversion proxy and local lead measures', () => {
    const fields = campaignStatisticsPivotFields();
    const byCaption = Object.fromEntries(fields.map((field) => [field.caption, field]));
    const summary = (values) => ({ value: (field) => values[field] ?? 0 });

    for (const caption of ['MAILER', 'CAMPAIGN', 'ISP', 'LIST', 'LIST TYPE', 'PROVIDER', 'SERVER', 'IP', 'DOMAIN', 'SPONSOR', 'OFFER', 'VERTICAL', 'FROM', 'SUBJECT', 'CREATIVE', 'DATE']) {
        assert.ok(byCaption[caption], `expected ${caption} pivot dimension`);
    }
    for (const caption of ['DELIVERIES', 'SOFT BOUNCES', 'HARD BOUNCES', 'OPENS', 'CLICKS', 'LEADS', 'CONVERSIONS', 'UNSUBS', 'BOTS', 'ATTRIBUTED LEAD REVENUE', 'EPC']) {
        assert.equal(byCaption[caption].area, 'data');
    }
    assert.equal(typeof byCaption.CTOR.calculateSummaryValue, 'function');
    assert.equal(byCaption['OPEN RATE'], undefined);
    assert.equal(byCaption.CTOR.calculateSummaryValue(summary({ opens: 8, clicks: 2 })), '25.00%');
    assert.equal(byCaption.EPC.calculateSummaryValue(summary({ lead_revenue: 7.5, clicks: 3 })), '$2.50');
    assert.equal(projectCampaignStatisticsRows([{ leads: '2', conversions: '1' }])[0].leads, 2);
    assert.equal(projectCampaignStatisticsRows([{ leads: '2', conversions: '1' }])[0].conversions, 1);
});
