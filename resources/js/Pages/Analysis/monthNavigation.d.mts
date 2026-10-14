export type AnalysisView = 'calendar' | 'invoice';

export declare function analysisMonthVisit(month: string, view: AnalysisView): {
    url: string;
    options: {
        replace: true;
        preserveState: true;
        preserveScroll: true;
    };
};

export declare function analysisNavigationUrls(month: string, view: AnalysisView): {
    overview: string;
    calendar: string;
    invoice: string;
};
