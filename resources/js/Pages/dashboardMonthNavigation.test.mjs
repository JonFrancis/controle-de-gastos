import assert from 'node:assert/strict';
import test from 'node:test';
import { createRecoverableNavigationHandler } from './recoverableNavigationFailure.ts';
import { dashboardCategoryIdForResponse, dashboardMonthUrl, dashboardNavigationUrls, visitDashboardMonth } from './dashboardMonthNavigation.ts';

const bases = {
    overview: '/dashboard',
    balances: '/custom-balances',
    analysis: '/custom-analysis',
    purchases: '/custom-purchases',
    receipt: '/custom-receipts/create',
    purchaseCreate: '/custom-purchases/create',
};

test('builds a replaceable Dashboard query with the active view and category', () => {
    assert.equal(
        dashboardMonthUrl(bases.overview, '2026-11', 'calendar', 7),
        '/dashboard?month=2026-11&view=calendar&category=7',
    );
});

test('does not add a category to invoice queries', () => {
    assert.equal(
        dashboardMonthUrl(bases.overview, '2026-11', 'invoice', null),
        '/dashboard?month=2026-11&view=invoice',
    );
});

test('preserves the selected category when returning to the Dashboard overview', () => {
    assert.equal(
        dashboardNavigationUrls(bases, '2026-11', 'calendar', 7).overview,
        '/dashboard?month=2026-11&view=calendar&category=7',
    );
});

test('keeps the local category for the next month after a response without that category', () => {
    const locallySelectedCategoryId = dashboardCategoryIdForResponse(7, null, true);

    assert.equal(
        dashboardNavigationUrls(bases, '2026-12', 'calendar', locallySelectedCategoryId).overview,
        '/dashboard?month=2026-12&view=calendar&category=7',
    );
});

test('includes the active Dashboard view in salary analysis links', () => {
    assert.equal(
        dashboardNavigationUrls(bases, '2026-11', 'calendar').analysisSalary,
        '/custom-analysis?month=2026-11&view=calendar#salary',
    );
    assert.equal(
        dashboardNavigationUrls(bases, '2026-11', 'invoice').analysisSalary,
        '/custom-analysis?month=2026-11&view=invoice#salary',
    );
});

test('accepts an explicit category response outside monthly navigation', () => {
    assert.strictEqual(dashboardCategoryIdForResponse(7, 11, false), 11);
    assert.strictEqual(dashboardCategoryIdForResponse(7, null, false), null);
});

test('does not restore a previous category after a failed month visit and an explicit category response', () => {
    let monthlyNavigationInFlight = true;
    let monthRequestFailed = false;
    const events = [];
    const markMonthRequestFailed = createRecoverableNavigationHandler(
        (failed) => {
            events.push('mark-error');
            monthRequestFailed = failed;
        },
        () => {
            events.push('clear-month-navigation');
            monthlyNavigationInFlight = false;
        },
    );

    assert.strictEqual(markMonthRequestFailed(), false);
    assert.deepEqual(events, ['clear-month-navigation', 'mark-error']);
    assert.equal(monthlyNavigationInFlight, false);
    assert.equal(monthRequestFailed, true);
    assert.equal(dashboardCategoryIdForResponse(7, null, monthlyNavigationInFlight), null);
});

test('forwards visit failure handlers so a failed Dashboard month can be retried', () => {
    const callbacks = {
        onError: () => {},
        onHttpException: () => false,
        onNetworkError: () => false,
        onFinish: () => {},
    };

    let request = [];

    visitDashboardMonth({ visit: (...args) => { request = args; } }, bases.overview, '2026-11', 'calendar', 7, callbacks);

    assert.strictEqual(request[1].onError, callbacks.onError);
    assert.strictEqual(request[1].onHttpException, callbacks.onHttpException);
    assert.strictEqual(request[1].onNetworkError, callbacks.onNetworkError);
    assert.strictEqual(request[1].onFinish, callbacks.onFinish);
    assert.strictEqual(request[1].onHttpException(), false);
    assert.strictEqual(request[1].onNetworkError(), false);
});

test('keeps the locally selected month in Dashboard navigation after a failed visit', () => {
    assert.deepEqual(dashboardNavigationUrls(bases, '2026-11', 'invoice'), {
        balances: '/custom-balances?month=2026-11&view=invoice',
        analysis: '/custom-analysis?month=2026-11&view=invoice',
        analysisSalary: '/custom-analysis?month=2026-11&view=invoice#salary',
        purchasesInvoice: '/custom-purchases?month=2026-11&view=invoice',
        purchasesCalendar: '/custom-purchases?month=2026-11&view=calendar',
        overview: '/dashboard?month=2026-11&view=invoice',
        receipt: '/custom-receipts/create?month=2026-11',
        purchaseCreate: '/custom-purchases/create',
    });
});
