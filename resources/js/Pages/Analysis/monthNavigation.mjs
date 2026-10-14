export function analysisMonthVisit(analysisBaseUrl, month, view) {
    const params = new URLSearchParams({ month, view });

    return {
        url: `${analysisBaseUrl}?${params.toString()}`,
        options: { replace: true, preserveState: true, preserveScroll: true },
    };
}

export function visitAnalysisMonth(router, analysisBaseUrl, month, view, callbacks = {}) {
    const visit = analysisMonthVisit(analysisBaseUrl, month, view);

    router.visit(visit.url, {
        ...visit.options,
        ...callbacks,
    });
}

export function analysisNavigationUrls(bases, month, view) {
    const params = new URLSearchParams({ month, view });

    return {
        overview: `${bases.overview}?${params.toString()}`,
        calendar: `${bases.analysis}?month=${encodeURIComponent(month)}&view=calendar`,
        invoice: `${bases.analysis}?month=${encodeURIComponent(month)}&view=invoice`,
    };
}
