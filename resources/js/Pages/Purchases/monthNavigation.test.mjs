import assert from 'node:assert/strict';
import test from 'node:test';
import { purchaseNavigationUrl, purchaseNavigationUrls, visitPurchaseMonth } from './monthNavigation.ts';

const bases = {
    overview: '/custom-dashboard',
    purchases: '/custom-purchases',
    create: '/custom-purchases/create',
};

test('changes an invoice month without losing its payment method and derives the new closing date', () => {
    let request = [];

    visitPurchaseMonth(
        { get: (...args) => { request = args; } },
        bases.purchases,
        '2026-11',
        'invoice',
        '2026-10',
        { paymentMethodId: 7, closingDate: '2026-10-05' },
    );

    assert.deepEqual(request, [
        '/custom-purchases',
        {
            month: '2026-11',
            view: 'invoice',
            payment_method_id: 7,
            closing_date: '2026-11-05',
        },
        { replace: true, preserveState: true, preserveScroll: true },
    ]);
});

test('caps a derived invoice closing date at the target month end', () => {
    let request = [];

    visitPurchaseMonth(
        { get: (...args) => { request = args; } },
        bases.purchases,
        '2026-11',
        'invoice',
        '2026-10',
        { paymentMethodId: 7, closingDate: '2026-10-31' },
    );

    assert.strictEqual(request[1].closing_date, '2026-11-30');
});

test('uses the configured closing day when February closing dates are both the last day', () => {
    let cardClosingOnDay28 = [];
    let cardClosingOnDay31 = [];

    visitPurchaseMonth(
        { get: (...args) => { cardClosingOnDay28 = args; } },
        bases.purchases,
        '2026-03',
        'invoice',
        '2026-02',
        { paymentMethodId: 28, closingDate: '2026-02-28', closingDay: 28 },
    );
    visitPurchaseMonth(
        { get: (...args) => { cardClosingOnDay31 = args; } },
        bases.purchases,
        '2026-03',
        'invoice',
        '2026-02',
        { paymentMethodId: 31, closingDate: '2026-02-28', closingDay: 31 },
    );

    assert.strictEqual(cardClosingOnDay28[1].closing_date, '2026-03-28');
    assert.strictEqual(cardClosingOnDay31[1].closing_date, '2026-03-31');
});

test('forwards visit lifecycle callbacks so a failed invoice month can be retried', () => {
    const callbacks = {
        onStart: () => {},
        onError: () => {},
        onHttpException: () => false,
        onNetworkError: () => false,
        onFinish: () => {},
    };
    let request = [];

    visitPurchaseMonth(
        { get: (...args) => { request = args; } },
        bases.purchases,
        '2026-11',
        'invoice',
        '2026-10',
        { paymentMethodId: 7, closingDate: '2026-10-05' },
        callbacks,
    );

    assert.strictEqual(request[2].onStart, callbacks.onStart);
    assert.strictEqual(request[2].onError, callbacks.onError);
    assert.strictEqual(request[2].onHttpException, callbacks.onHttpException);
    assert.strictEqual(request[2].onNetworkError, callbacks.onNetworkError);
    assert.strictEqual(request[2].onFinish, callbacks.onFinish);
    assert.strictEqual(request[2].onHttpException(), false);
    assert.strictEqual(request[2].onNetworkError(), false);
});

test('composes purchase navigation URLs from the controller-provided bases', () => {
    const context = { paymentMethodId: 7, closingDate: '2026-11-05', closingDay: 5 };

    assert.equal(purchaseNavigationUrl(bases.purchases, '2026-11', 'invoice', context), '/custom-purchases?month=2026-11&view=invoice&payment_method_id=7&closing_date=2026-11-05');
    assert.deepEqual(purchaseNavigationUrls(bases, '2026-11', 'invoice', context), {
        overview: '/custom-dashboard?month=2026-11&view=invoice',
        create: '/custom-purchases/create',
        calendar: '/custom-purchases?month=2026-11&view=calendar',
        invoice: '/custom-purchases?month=2026-11&view=invoice&payment_method_id=7&closing_date=2026-11-05',
    });
});
