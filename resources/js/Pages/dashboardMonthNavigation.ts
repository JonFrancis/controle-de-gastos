type DashboardView = 'calendar' | 'invoice';

type DashboardRouter = {
    visit(url: string, options: DashboardVisitOptions & { replace: boolean; preserveState: boolean; preserveScroll: boolean }): void;
};

export type DashboardVisitOptions = {
    onError?: () => void;
    onHttpException?: () => void;
    onNetworkError?: () => void;
    onFinish?: () => void;
};

export type DashboardNavigationUrls = {
    balances: string;
    analysis: string;
    purchasesInvoice: string;
    purchasesCalendar: string;
    overview: string;
    receipt: string;
};

export function dashboardMonthUrl(month: string, view: DashboardView, categoryId: number | null): string {
    const params = new URLSearchParams({ month, view });

    if (view === 'calendar' && categoryId !== null) {
        params.set('category', String(categoryId));
    }

    return `/?${params.toString()}`;
}

export function visitDashboardMonth(router: DashboardRouter, month: string, view: DashboardView, categoryId: number | null, callbacks: DashboardVisitOptions = {}): void {
    router.visit(dashboardMonthUrl(month, view, categoryId), {
        replace: true,
        preserveState: true,
        preserveScroll: true,
        ...callbacks,
    });
}

export function dashboardNavigationUrls(month: string, view: DashboardView): DashboardNavigationUrls {
    return {
        balances: `/balances?month=${month}&view=${view}`,
        analysis: `/analysis?month=${month}&view=${view}`,
        purchasesInvoice: `/purchases?month=${month}&view=invoice`,
        purchasesCalendar: `/purchases?month=${month}&view=calendar`,
        overview: `/?month=${month}&view=${view}`,
        receipt: `/receipts/create?month=${month}`,
    };
}
