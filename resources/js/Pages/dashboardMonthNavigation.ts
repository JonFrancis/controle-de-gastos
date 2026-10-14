type DashboardView = 'calendar' | 'invoice';

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

export function dashboardNavigationUrls(month: string, view: DashboardView): DashboardNavigationUrls {
    return {
        balances: `/balances?month=${month}&view=${view}`,
        analysis: `/analysis?month=${month}&view=${view}`,
        purchasesInvoice: `/purchases?month=${month}&view=invoice`,
        purchasesCalendar: `/purchases?month=${month}&view=calendar`,
        overview: `/?month=${month}&view=calendar`,
        receipt: `/receipts/create?month=${month}`,
    };
}
