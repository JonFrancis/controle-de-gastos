export function analysisMonthVisit(month, view) {
    return {
        url: `/analysis?month=${encodeURIComponent(month)}&view=${view}`,
        options: { replace: true, preserveState: true, preserveScroll: true },
    };
}
