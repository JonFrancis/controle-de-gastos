import assert from 'node:assert/strict';
import test from 'node:test';
import { createRecoverableNavigationHandler } from '../recoverableNavigationFailure.ts';
import { analysisMonthVisit, analysisNavigationUrls, visitAnalysisMonth } from './monthNavigation.mjs';

const bases = {
    overview: '/custom-dashboard',
    analysis: '/custom-analysis',
};

test('changes the calendar analysis month without changing its active view or browser history', () => {
    assert.deepEqual(analysisMonthVisit(bases.analysis, '2026-11', 'calendar'), {
        url: '/custom-analysis?month=2026-11&view=calendar',
        options: { replace: true, preserveState: true, preserveScroll: true },
    });
});

test('changes the invoice analysis month without changing its active view or browser history', () => {
    assert.deepEqual(analysisMonthVisit(bases.analysis, '2026-11', 'invoice'), {
        url: '/custom-analysis?month=2026-11&view=invoice',
        options: { replace: true, preserveState: true, preserveScroll: true },
    });
});

test('forwards visit failure handlers so a failed analysis month can be retried', () => {
    const callbacks = {
        onSuccess: () => {},
        onError: () => {},
        onHttpException: () => false,
        onNetworkError: () => false,
        onFinish: () => {},
    };

    let request = [];

    visitAnalysisMonth({ visit: (...args) => { request = args; } }, bases.analysis, '2026-11', 'invoice', callbacks);

    assert.strictEqual(request[1].onSuccess, callbacks.onSuccess);
    assert.strictEqual(request[1].onError, callbacks.onError);
    assert.strictEqual(request[1].onHttpException, callbacks.onHttpException);
    assert.strictEqual(request[1].onNetworkError, callbacks.onNetworkError);
    assert.strictEqual(request[1].onFinish, callbacks.onFinish);
    assert.strictEqual(request[1].onHttpException(), false);
    assert.strictEqual(request[1].onNetworkError(), false);
});

test('keeps the locally selected month in Analysis navigation after a failed visit', () => {
    assert.deepEqual(analysisNavigationUrls(bases, '2026-11', 'invoice'), {
        overview: '/custom-dashboard?month=2026-11&view=invoice',
        calendar: '/custom-analysis?month=2026-11&view=calendar',
        invoice: '/custom-analysis?month=2026-11&view=invoice',
    });
});

test('contains a recoverable Analysis visit failure while preserving retry state', () => {
    let failed = false;
    const handler = createRecoverableNavigationHandler((value) => { failed = value; });

    assert.strictEqual(handler(), false);
    assert.equal(failed, true);
});
