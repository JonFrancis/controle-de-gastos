import assert from 'node:assert/strict';
import test from 'node:test';
import { createRecoverableNavigationHandler } from '../recoverableNavigationFailure.ts';
import { balanceNavigationUrls, visitBalanceMonth } from './monthSelection.ts';

const bases = {
    overview: '/custom-dashboard',
    balances: '/custom-balances',
    receipt: '/custom-receipts/create',
};

test('loads a calendar month without adding a history entry', () => {
    let request = [];

    visitBalanceMonth({ get: (...args) => { request = args; } }, bases.balances, '2026-11', 'calendar');

    assert.deepEqual(request, [
        '/custom-balances',
        { month: '2026-11', view: 'calendar' },
        { replace: true, preserveState: true, preserveScroll: true },
    ]);
});

test('loads an invoice month while preserving the active view', () => {
    let request = [];

    visitBalanceMonth({ get: (...args) => { request = args; } }, bases.balances, '2026-11', 'invoice');

    assert.deepEqual(request, [
        '/custom-balances',
        { month: '2026-11', view: 'invoice' },
        { replace: true, preserveState: true, preserveScroll: true },
    ]);
});

test('forwards visit lifecycle callbacks so a failed month can be retried', () => {
    const callbacks = {
        onStart: () => {},
        onError: () => {},
        onHttpException: () => false,
        onNetworkError: () => false,
        onFinish: () => {},
    };
    let request = [];

    visitBalanceMonth({ get: (...args) => { request = args; } }, bases.balances, '2026-11', 'calendar', callbacks);

    assert.strictEqual(request[2].onStart, callbacks.onStart);
    assert.strictEqual(request[2].onError, callbacks.onError);
    assert.strictEqual(request[2].onHttpException, callbacks.onHttpException);
    assert.strictEqual(request[2].onNetworkError, callbacks.onNetworkError);
    assert.strictEqual(request[2].onFinish, callbacks.onFinish);
    assert.strictEqual(request[2].onHttpException(), false);
    assert.strictEqual(request[2].onNetworkError(), false);
});

test('composes monthly balance links from the controller-provided bases', () => {
    assert.deepEqual(balanceNavigationUrls(bases, '2026-11', 'invoice'), {
        overview: '/custom-dashboard?month=2026-11&view=invoice',
        receipt: '/custom-receipts/create?month=2026-11',
        calendar: '/custom-balances?month=2026-11&view=calendar',
        invoice: '/custom-balances?month=2026-11&view=invoice',
    });
});

test('contains a recoverable Balances visit failure while preserving retry state', () => {
    let failed = false;
    const handler = createRecoverableNavigationHandler((value) => { failed = value; });

    assert.strictEqual(handler(), false);
    assert.equal(failed, true);
});
