import assert from 'node:assert/strict';
import test from 'node:test';
import { visitBalanceMonth } from './monthSelection.ts';

test('loads a calendar month without adding a history entry', () => {
    let request = [];

    visitBalanceMonth({ get: (...args) => { request = args; } }, '2026-11', 'calendar');

    assert.deepEqual(request, [
        '/balances',
        { month: '2026-11', view: 'calendar' },
        { replace: true, preserveState: true, preserveScroll: true },
    ]);
});

test('loads an invoice month while preserving the active view', () => {
    let request = [];

    visitBalanceMonth({ get: (...args) => { request = args; } }, '2026-11', 'invoice');

    assert.deepEqual(request, [
        '/balances',
        { month: '2026-11', view: 'invoice' },
        { replace: true, preserveState: true, preserveScroll: true },
    ]);
});

test('forwards visit lifecycle callbacks so a failed month can be retried', () => {
    const callbacks = {
        onStart: () => {},
        onError: () => {},
        onHttpException: () => {},
        onNetworkError: () => {},
        onFinish: () => {},
    };
    let request = [];

    visitBalanceMonth({ get: (...args) => { request = args; } }, '2026-11', 'calendar', callbacks);

    assert.strictEqual(request[2].onStart, callbacks.onStart);
    assert.strictEqual(request[2].onError, callbacks.onError);
    assert.strictEqual(request[2].onHttpException, callbacks.onHttpException);
    assert.strictEqual(request[2].onNetworkError, callbacks.onNetworkError);
    assert.strictEqual(request[2].onFinish, callbacks.onFinish);
});
