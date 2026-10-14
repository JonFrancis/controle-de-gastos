import assert from 'node:assert/strict';
import test from 'node:test';
import { analysisMonthVisit } from './monthNavigation.mjs';

test('changes the calendar analysis month without changing its active view or browser history', () => {
    assert.deepEqual(analysisMonthVisit('2026-11', 'calendar'), {
        url: '/analysis?month=2026-11&view=calendar',
        options: { replace: true, preserveState: true, preserveScroll: true },
    });
});

test('changes the invoice analysis month without changing its active view or browser history', () => {
    assert.deepEqual(analysisMonthVisit('2026-11', 'invoice'), {
        url: '/analysis?month=2026-11&view=invoice',
        options: { replace: true, preserveState: true, preserveScroll: true },
    });
});
