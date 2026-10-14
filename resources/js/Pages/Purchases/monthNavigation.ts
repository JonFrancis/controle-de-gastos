export type PurchaseView = 'calendar' | 'invoice';

export type InvoiceContext = {
    paymentMethodId: number | null;
    closingDate: string | null;
    closingDay?: number | null;
};

export type PurchaseNavigationBases = {
    overview: string;
    purchases: string;
    create: string;
};

export type PurchaseNavigationUrls = {
    overview: string;
    create: string;
    calendar: string;
    invoice: string;
};

type PurchaseQuery = {
    month: string;
    view: PurchaseView;
    payment_method_id?: number;
    closing_date?: string;
};

type PurchaseRouter = {
    get(url: string, data: PurchaseQuery, options: PurchaseVisitOptions & { replace: boolean; preserveState: boolean; preserveScroll: boolean }): void;
};

export type PurchaseVisitOptions = {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: () => void;
    onHttpException?: () => false | void;
    onNetworkError?: () => false | void;
    onFinish?: () => void;
};

export function visitPurchaseMonth(router: PurchaseRouter, purchasesBaseUrl: string, month: string, view: PurchaseView, currentMonth: string, invoiceContext: InvoiceContext | null, callbacks: PurchaseVisitOptions = {}): void {
    router.get(purchasesBaseUrl, purchaseQuery(month, view, currentMonth, invoiceContext), {
        replace: true,
        preserveState: true,
        preserveScroll: true,
        ...callbacks,
    });
}

export function purchaseNavigationUrl(purchasesBaseUrl: string, month: string, view: PurchaseView, invoiceContext: InvoiceContext | null = null): string {
    const query = purchaseQuery(month, view, month, invoiceContext);

    return `${purchasesBaseUrl}?${new URLSearchParams(stringifyQuery(query)).toString()}`;
}

export function purchaseNavigationUrls(bases: PurchaseNavigationBases, month: string, view: PurchaseView, invoiceContext: InvoiceContext | null = null): PurchaseNavigationUrls {
    const overviewQuery = new URLSearchParams({ month, view });

    return {
        overview: `${bases.overview}?${overviewQuery.toString()}`,
        create: bases.create,
        calendar: purchaseNavigationUrl(bases.purchases, month, 'calendar'),
        invoice: purchaseNavigationUrl(bases.purchases, month, 'invoice', invoiceContext),
    };
}

function purchaseQuery(month: string, view: PurchaseView, currentMonth: string, invoiceContext: InvoiceContext | null): PurchaseQuery {
    const query: PurchaseQuery = { month, view };

    if (view !== 'invoice') {
        return query;
    }

    if (invoiceContext?.paymentMethodId !== null && invoiceContext?.paymentMethodId !== undefined) {
        query.payment_method_id = invoiceContext.paymentMethodId;
    }

    const closingDate = deriveClosingDate(currentMonth, month, invoiceContext?.closingDate ?? null, invoiceContext?.closingDay);

    if (closingDate !== null) {
        query.closing_date = closingDate;
    }

    return query;
}

function deriveClosingDate(currentMonth: string, nextMonth: string, closingDate: string | null, closingDay?: number | null): string | null {
    if (closingDate === null) {
        return null;
    }

    const [year, month, day] = closingDate.split('-').map(Number);
    const monthOffset = monthIndex(nextMonth) - monthIndex(currentMonth);
    const targetMonthIndex = year * 12 + month - 1 + monthOffset;
    const targetYear = Math.floor(targetMonthIndex / 12);
    const targetMonth = targetMonthIndex % 12 + 1;
    const sourceDays = daysInMonth(year, month);
    const targetDays = daysInMonth(targetYear, targetMonth);
    const targetDay = isValidClosingDay(closingDay)
        ? Math.min(closingDay, targetDays)
        : day === sourceDays ? targetDays : Math.min(day, targetDays);

    return `${targetYear}-${pad(targetMonth)}-${pad(targetDay)}`;
}

function isValidClosingDay(value: number | null | undefined): value is number {
    return value !== null && value !== undefined && Number.isInteger(value) && value >= 1 && value <= 31;
}

function monthIndex(value: string): number {
    const [year, month] = value.split('-').map(Number);

    return year * 12 + month - 1;
}

function daysInMonth(year: number, month: number): number {
    return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

function stringifyQuery(query: PurchaseQuery): Record<string, string> {
    return Object.fromEntries(Object.entries(query).map(([key, value]) => [key, String(value)]));
}
