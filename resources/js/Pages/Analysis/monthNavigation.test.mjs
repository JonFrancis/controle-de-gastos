import assert from 'node:assert/strict';
import test from 'node:test';
import { analysisMonthVisit, analysisNavigationUrls } from './monthNavigation.mjs';

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

test('keeps the locally selected month in Analysis navigation after a failed visit', () => {
    assert.deepEqual(analysisNavigationUrls('2026-11', 'invoice'), {
        overview: '/?month=2026-11&view=invoice',
        calendar: '/analysis?month=2026-11&view=calendar',
        invoice: '/analysis?month=2026-11&view=invoice',
    });
});
