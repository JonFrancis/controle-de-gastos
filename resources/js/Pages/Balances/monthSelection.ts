export type BalanceView = 'calendar' | 'invoice';

type BalanceRouter = {
    get(url: string, data: { month: string; view: BalanceView }, options: BalanceMonthVisitOptions & { replace: boolean; preserveState: boolean; preserveScroll: boolean }): void;
};

export type BalanceMonthVisitOptions = {
    onStart?: () => void;
    onError?: () => void;
    onHttpException?: () => false | void;
    onNetworkError?: () => false | void;
    onFinish?: () => void;
};

export function visitBalanceMonth(router: BalanceRouter, month: string, view: BalanceView, callbacks: BalanceMonthVisitOptions = {}): void {
    router.get('/balances', { month, view }, {
        replace: true,
        preserveState: true,
        preserveScroll: true,
        ...callbacks,
    });
}
