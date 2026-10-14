import assert from 'node:assert/strict';
import test from 'node:test';
import { dashboardMonthUrl, dashboardNavigationUrls, visitDashboardMonth } from './dashboardMonthNavigation.ts';

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

test('forwards visit failure handlers so a failed Dashboard month can be retried', () => {
    const callbacks = {
        onError: () => {},
        onHttpException: () => {},
        onNetworkError: () => {},
        onFinish: () => {},
    };

    let request = [];

    visitDashboardMonth({ visit: (...args) => { request = args; } }, '2026-11', 'calendar', 7, callbacks);

    assert.strictEqual(request[1].onError, callbacks.onError);
    assert.strictEqual(request[1].onHttpException, callbacks.onHttpException);
    assert.strictEqual(request[1].onNetworkError, callbacks.onNetworkError);
    assert.strictEqual(request[1].onFinish, callbacks.onFinish);
});

test('keeps the locally selected month in Dashboard navigation after a failed visit', () => {
    assert.deepEqual(dashboardNavigationUrls('2026-11', 'invoice'), {
        balances: '/balances?month=2026-11&view=invoice',
        analysis: '/analysis?month=2026-11&view=invoice',
        purchasesInvoice: '/purchases?month=2026-11&view=invoice',
        purchasesCalendar: '/purchases?month=2026-11&view=calendar',
        overview: '/?month=2026-11&view=invoice',
        receipt: '/receipts/create?month=2026-11',
    });
});
