export type AnalysisView = 'calendar' | 'invoice';

export type AnalysisVisitOptions = {
    onSuccess?: () => void;
    onError?: () => void;
    onHttpException?: () => void;
    onNetworkError?: () => void;
    onFinish?: () => void;
};

type AnalysisRouter = {
    visit(url: string, options: AnalysisVisitOptions & { replace: boolean; preserveState: boolean; preserveScroll: boolean }): void;
};

export declare function analysisMonthVisit(month: string, view: AnalysisView): {
    url: string;
    options: {
        replace: true;
        preserveState: true;
        preserveScroll: true;
    };
};

export declare function visitAnalysisMonth(router: AnalysisRouter, month: string, view: AnalysisView, callbacks?: AnalysisVisitOptions): void;

export declare function analysisNavigationUrls(month: string, view: AnalysisView): {
    overview: string;
    calendar: string;
    invoice: string;
};
