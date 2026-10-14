export type PurchaseView = 'calendar' | 'invoice';

export type InvoiceContext = {
    paymentMethodId: number | null;
    closingDate: string | null;
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

export function visitPurchaseMonth(router: PurchaseRouter, month: string, view: PurchaseView, currentMonth: string, invoiceContext: InvoiceContext | null, callbacks: PurchaseVisitOptions = {}): void {
    router.get('/purchases', purchaseQuery(month, view, currentMonth, invoiceContext), {
        replace: true,
        preserveState: true,
        preserveScroll: true,
        ...callbacks,
    });
}

export function purchaseNavigationUrl(month: string, view: PurchaseView, invoiceContext: InvoiceContext | null = null): string {
    const query = purchaseQuery(month, view, month, invoiceContext);

    return `/purchases?${new URLSearchParams(stringifyQuery(query)).toString()}`;
}

function purchaseQuery(month: string, view: PurchaseView, currentMonth: string, invoiceContext: InvoiceContext | null): PurchaseQuery {
    const query: PurchaseQuery = { month, view };

    if (view !== 'invoice') {
        return query;
    }

    if (invoiceContext?.paymentMethodId !== null && invoiceContext?.paymentMethodId !== undefined) {
        query.payment_method_id = invoiceContext.paymentMethodId;
    }

    const closingDate = deriveClosingDate(currentMonth, month, invoiceContext?.closingDate ?? null);

    if (closingDate !== null) {
        query.closing_date = closingDate;
    }

    return query;
}

function deriveClosingDate(currentMonth: string, nextMonth: string, closingDate: string | null): string | null {
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
    const targetDay = day === sourceDays ? targetDays : Math.min(day, targetDays);

    return `${targetYear}-${pad(targetMonth)}-${pad(targetDay)}`;
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
