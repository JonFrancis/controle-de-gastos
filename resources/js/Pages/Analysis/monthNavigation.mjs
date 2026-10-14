export function analysisMonthVisit(month, view) {
    return {
        url: `/analysis?month=${encodeURIComponent(month)}&view=${view}`,
        options: { replace: true, preserveState: true, preserveScroll: true },
    };
}

export function analysisNavigationUrls(month, view) {
    return {
        overview: `/?month=${month}&view=${view}`,
        calendar: `/analysis?month=${month}&view=calendar`,
        invoice: `/analysis?month=${month}&view=invoice`,
    };
}
