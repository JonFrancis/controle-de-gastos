import assert from 'node:assert/strict';
import test from 'node:test';
import { dashboardMonthUrl } from './dashboardMonthNavigation.ts';

test('builds a replaceable Dashboard query with the active view and category', () => {
    assert.equal(
        dashboardMonthUrl('2026-11', 'calendar', 7),
        '/?month=2026-11&view=calendar&category=7',
    );
});

test('does not add a category to invoice queries', () => {
    assert.equal(
        dashboardMonthUrl('2026-11', 'invoice', null),
        '/?month=2026-11&view=invoice',
    );
});
