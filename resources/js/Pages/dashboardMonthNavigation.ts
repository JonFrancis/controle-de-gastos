type DashboardView = 'calendar' | 'invoice';

type DashboardRouter = {
    visit(url: string, options: DashboardVisitOptions & { replace: boolean; preserveState: boolean; preserveScroll: boolean }): void;
};

export type DashboardVisitOptions = {
    onError?: () => void;
    onHttpException?: () => false | void;
    onNetworkError?: () => false | void;
    onFinish?: () => void;
};

export type DashboardNavigationUrls = {
    balances: string;
    analysis: string;
    analysisSalary: string;
    purchasesInvoice: string;
    purchasesCalendar: string;
    overview: string;
    receipt: string;
    purchaseCreate: string;
};

export type DashboardNavigationBases = {
    balances: string;
    analysis: string;
    purchases: string;
    overview: string;
    receipt: string;
    purchaseCreate: string;
};

export function dashboardCategoryIdForResponse(localCategoryId: number | null, responseCategoryId: number | null, preserveLocalSelection: boolean): number | null {
    return preserveLocalSelection && responseCategoryId === null ? localCategoryId : responseCategoryId;
}

export function dashboardMonthUrl(overviewBaseUrl: string, month: string, view: DashboardView, categoryId: number | null, tab: 'overview' | 'personal' = 'overview'): string {
    const params = new URLSearchParams({ month, view });

    if (tab === 'personal') {
        params.set('tab', tab);
    }

    if (view === 'calendar' && categoryId !== null) {
        params.set('category', String(categoryId));
    }

    return `${overviewBaseUrl}?${params.toString()}`;
}

export function visitDashboardMonth(router: DashboardRouter, overviewBaseUrl: string, month: string, view: DashboardView, categoryId: number | null, callbacks: DashboardVisitOptions = {}, tab: 'overview' | 'personal' = 'overview'): void {
    router.visit(dashboardMonthUrl(overviewBaseUrl, month, view, categoryId, tab), {
        replace: true,
        preserveState: true,
        preserveScroll: true,
        ...callbacks,
    });
}

export function dashboardNavigationUrls(bases: DashboardNavigationBases, month: string, view: DashboardView, categoryId: number | null = null): DashboardNavigationUrls {
    const params = new URLSearchParams({ month, view });

    return {
        balances: `${bases.balances}?${params.toString()}`,
        analysis: `${bases.analysis}?${params.toString()}`,
        analysisSalary: `${bases.analysis}?${params.toString()}#salary`,
        purchasesInvoice: `${bases.purchases}?month=${encodeURIComponent(month)}&view=invoice`,
        purchasesCalendar: `${bases.purchases}?month=${encodeURIComponent(month)}&view=calendar`,
        overview: dashboardMonthUrl(bases.overview, month, view, categoryId),
        receipt: `${bases.receipt}?month=${encodeURIComponent(month)}`,
        purchaseCreate: bases.purchaseCreate,
    };
}
