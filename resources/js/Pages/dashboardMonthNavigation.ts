type DashboardView = 'calendar' | 'invoice';

export function dashboardMonthUrl(month: string, view: DashboardView, categoryId: number | null): string {
    const params = new URLSearchParams({ month, view });

    if (view === 'calendar' && categoryId !== null) {
        params.set('category', String(categoryId));
    }

    return `/?${params.toString()}`;
}
