export type AnalysisView = 'calendar' | 'invoice';

export type AnalysisNavigationBases = {
    overview: string;
    analysis: string;
};

export type AnalysisVisitOptions = {
    onSuccess?: () => void;
    onError?: () => void;
    onHttpException?: () => false | void;
    onNetworkError?: () => false | void;
    onFinish?: () => void;
};

type AnalysisRouter = {
    visit(url: string, options: AnalysisVisitOptions & { replace: boolean; preserveState: boolean; preserveScroll: boolean }): void;
};

export declare function analysisMonthVisit(analysisBaseUrl: string, month: string, view: AnalysisView): {
    url: string;
    options: {
        replace: true;
        preserveState: true;
        preserveScroll: true;
    };
};

export declare function visitAnalysisMonth(router: AnalysisRouter, analysisBaseUrl: string, month: string, view: AnalysisView, callbacks?: AnalysisVisitOptions): void;

export declare function analysisNavigationUrls(bases: AnalysisNavigationBases, month: string, view: AnalysisView): {
    overview: string;
    calendar: string;
    invoice: string;
};
