export type BalanceView = 'calendar' | 'invoice';

export type BalanceNavigationBases = {
    overview: string;
    balances: string;
    receipt: string;
};

export type BalanceNavigationUrls = {
    overview: string;
    receipt: string;
    calendar: string;
    invoice: string;
};

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

export function visitBalanceMonth(router: BalanceRouter, balancesBaseUrl: string, month: string, view: BalanceView, callbacks: BalanceMonthVisitOptions = {}): void {
    router.get(balancesBaseUrl, { month, view }, {
        replace: true,
        preserveState: true,
        preserveScroll: true,
        ...callbacks,
    });
}

export function balanceNavigationUrls(bases: BalanceNavigationBases, month: string, view: BalanceView): BalanceNavigationUrls {
    const params = new URLSearchParams({ month, view });

    return {
        overview: `${bases.overview}?${params.toString()}`,
        receipt: `${bases.receipt}?month=${encodeURIComponent(month)}`,
        calendar: `${bases.balances}?month=${encodeURIComponent(month)}&view=calendar`,
        invoice: `${bases.balances}?month=${encodeURIComponent(month)}&view=invoice`,
    };
}
