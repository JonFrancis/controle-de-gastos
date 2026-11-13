import { Form, Head, Link, router } from '@inertiajs/react';
import { useState, type ChangeEvent } from 'react';
import { purchaseNavigationUrl, purchaseNavigationUrls, visitPurchaseMonth, type InvoiceContext, type PurchaseNavigationBases } from './monthNavigation';

type ExpenseItem = {
    id: number;
    type: 'purchase' | 'occurrence';
    origin: 'manual' | 'installment' | 'recurrence';
    canDelete: boolean;
    editUrl: string;
    occurrenceNumber?: number | null;
    occurrenceCount?: number | null;
    purchasedAt: string;
    description: string;
    cardName: string | null;
    amountCents: number;
    payer: string | null;
    participant: string | null;
    paymentMethod: string | null;
    category: string | null;
    isAdjusted?: boolean;
};

type InvoiceGroup = {
    paymentMethodId: number;
    paymentMethod: string;
    color: string;
    periodStart: string;
    periodEnd: string;
    closingDate: string;
    dueDate: string | null;
    totalCents: number;
    purchases: ExpenseItem[];
};

type Props = {
    monthLabel: string;
    selectedMonth: string;
    view: 'calendar' | 'invoice';
    monthTotalCents: number;
    purchases: ExpenseItem[];
    occurrences: ExpenseItem[];
    invoiceGroups: InvoiceGroup[];
    invoiceContext: InvoiceContext | null;
    flash?: { success?: string };
    navigationBases: PurchaseNavigationBases;
};

export default function PurchasesIndex({ monthLabel, selectedMonth, view, monthTotalCents, purchases, occurrences, invoiceGroups, invoiceContext, flash, navigationBases }: Props) {
    const itemCount = purchases.length + occurrences.length;
    const [month, setMonth] = useState(selectedMonth);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const loadMonth = (nextMonth: string): void => {
        setMonth(nextMonth);
        setError(null);
        setProcessing(true);

        visitPurchaseMonth(router, navigationBases.purchases, nextMonth, view, selectedMonth, invoiceContext, {
            onSuccess: () => setError(null),
            onError: () => setError('Não foi possível carregar este mês.'),
            onHttpException: () => {
                setError('Não foi possível carregar este mês. Tente novamente.');

                return false;
            },
            onNetworkError: () => {
                setError('Não foi possível carregar este mês. Tente novamente.');

                return false;
            },
            onFinish: () => setProcessing(false),
        });
    };

    const navigationUrls = purchaseNavigationUrls(navigationBases, month, view, invoiceContext);

    const handleMonthChange = (event: ChangeEvent<HTMLInputElement>): void => {
        if (event.target.value !== '') {
            loadMonth(event.target.value);
        }
    };

    return (
        <>
            <Head title={view === 'invoice' ? 'Faturas' : 'Movimentações'} />
            <main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10">
                <div className="mx-auto max-w-6xl">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Link href={navigationUrls.overview} className="text-sm text-emerald-300">← Voltar para visão geral</Link>
                        <Link href={navigationUrls.create} className="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-bold text-slate-950">＋ Nova compra</Link>
                    </div>

                    <header className="mt-6 flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                        <div>
                            <p className="text-sm font-medium text-emerald-300">Consulta de lançamentos</p>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">{view === 'invoice' ? 'Faturas' : 'Movimentações'}</h1>
                            <p className="mt-2 text-sm text-slate-400">{view === 'invoice' ? 'Consulte o que precisa ser pago pelo mês de vencimento.' : 'Consulte lançamentos que não possuem uma fatura, pelo mês-calendário.'}</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2" aria-busy={processing}>
                            <label htmlFor="purchases-month" className="text-sm text-slate-400">{view === 'invoice' ? 'Faturas com vencimento em' : 'Mês-calendário'}</label>
                            <input id="purchases-month" type="month" value={month} onChange={handleMonthChange} disabled={processing} aria-busy={processing} className="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white disabled:cursor-wait disabled:opacity-60" />
                            {processing && <span role="status" className="text-xs text-slate-400">Carregando...</span>}
                        </div>
                    </header>

                    {error && <div role="alert" className="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-sm text-rose-200"><span>{error}</span><button type="button" onClick={() => loadMonth(month)} disabled={processing} className="font-semibold text-rose-100 underline disabled:opacity-60">Tentar novamente</button></div>}
                    {flash?.success && <p role="status" className="mt-5 rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200">{flash.success}</p>}

                    <section className="mt-8 rounded-3xl border border-white/10 bg-slate-900/70 p-5 sm:p-6">
                        <div className="flex flex-col justify-between gap-4 border-b border-white/10 pb-5 sm:flex-row sm:items-center">
                            <div>
                                <p className="text-sm font-medium text-emerald-300">{monthLabel}</p>
                                <h2 className="mt-1 text-xl font-semibold text-white">{view === 'invoice' ? 'Faturas' : 'Movimentações'}</h2>
                                <p className="mt-1 text-sm text-slate-400">{view === 'invoice' ? `${invoiceGroups.length} fatura(s)` : `${itemCount} lançamento(s)`} · total de <strong className="text-emerald-300">{formatMoney(monthTotalCents)}</strong></p>
                            </div>
                            <div className="flex rounded-xl border border-white/10 bg-white/5 p-1 text-sm" aria-label="Visão de compras">
                                <Link href={navigationUrls.calendar} className={`rounded-lg px-3 py-2 ${view === 'calendar' ? 'bg-emerald-400 font-semibold text-slate-950' : 'text-slate-300 hover:text-white'}`}>Movimentações</Link>
                                <Link href={navigationUrls.invoice} className={`rounded-lg px-3 py-2 ${view === 'invoice' ? 'bg-emerald-400 font-semibold text-slate-950' : 'text-slate-300 hover:text-white'}`}>Faturas</Link>
                            </div>
                        </div>

                        {view === 'invoice' ? <InvoiceGroups groups={invoiceGroups} month={month} invoiceContext={invoiceContext} navigationBases={navigationBases} /> : <CalendarPurchases purchases={purchases} occurrences={occurrences} />}
                    </section>
                </div>
            </main>
        </>
    );
}

function CalendarPurchases({ purchases, occurrences }: { purchases: ExpenseItem[]; occurrences: ExpenseItem[] }) {
    const items = [...purchases, ...occurrences].sort((left, right) => right.purchasedAt.localeCompare(left.purchasedAt));

    return (
        <div className="mt-5 space-y-3">
            {items.length === 0 && <EmptyState text="Nenhum lançamento ativo neste mês." />}
            {items.map((item) => <PurchaseRow key={`${item.origin}-${item.id}`} purchase={item} />)}
        </div>
    );
}

function InvoiceGroups({ groups, month, invoiceContext, navigationBases }: { groups: InvoiceGroup[]; month: string; invoiceContext: InvoiceContext | null; navigationBases: PurchaseNavigationBases }) {
    if (invoiceContext?.paymentMethodId !== null && invoiceContext?.paymentMethodId !== undefined) {
        return (
            <div className="mt-5">
                <Link href={purchaseNavigationUrl(navigationBases.purchases, month, 'invoice')} className="text-sm font-semibold text-emerald-300">← Todos os cartões</Link>
                <div className="mt-4 space-y-5"><InvoiceGroupCards groups={groups} /></div>
            </div>
        );
    }

    const cards = [...new Map(groups.map((group) => [group.paymentMethodId, {
        paymentMethodId: group.paymentMethodId,
        paymentMethod: group.paymentMethod,
        color: group.color,
        groups: groups.filter((candidate) => candidate.paymentMethodId === group.paymentMethodId),
    }])).values()];

    return (
        <div className="mt-5">
            {cards.length === 0 && <EmptyState text="Nenhuma fatura de cartão neste mês." />}
            {cards.length > 0 && <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{cards.map((card) => {
                const totalCents = card.groups.reduce((total, group) => total + group.totalCents, 0);
                const purchaseCount = card.groups.reduce((total, group) => total + group.purchases.length, 0);
                const href = purchaseNavigationUrl(navigationBases.purchases, month, 'invoice', {
                    paymentMethodId: card.paymentMethodId,
                });

                const colors = paymentMethodCardColors(card.color);

                return <Link key={card.paymentMethodId} href={href} className="group rounded-2xl border p-5 transition hover:-translate-y-0.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300" style={{ backgroundColor: colors.background, borderColor: colors.border }}>
                    <div className="flex items-start justify-between gap-3"><div><h3 className="font-semibold" style={{ color: colors.foreground }}>{card.paymentMethod}</h3><p className="mt-1 text-xs" style={{ color: colors.muted }}>{purchaseCount} compra(s) · {card.groups.length} fatura(s)</p></div><span aria-hidden="true" className="text-lg transition group-hover:translate-x-0.5" style={{ color: colors.accent }}>→</span></div>
                    <p className="mt-5 text-lg font-bold" style={{ color: colors.accent }}>{formatMoney(totalCents)}</p>
                    <p className="mt-1 text-xs" style={{ color: colors.muted }}>Abrir compras do cartão</p>
                </Link>;
            })}</div>}
        </div>
    );
}

function paymentMethodCardColors(color: string): { background: string; border: string; foreground: string; muted: string; accent: string } {
    const normalizedColor = /^#[0-9a-f]{6}$/i.test(color) ? color : '#64748B';

    return {
        background: mixColors(normalizedColor, '#0F172A', 0.68),
        border: mixColors(normalizedColor, '#FFFFFF', 0.52),
        foreground: '#F8FAFC',
        muted: mixColors(normalizedColor, '#CBD5E1', 0.38),
        accent: mixColors(normalizedColor, '#FFFFFF', 0.32),
    };
}

function mixColors(color: string, otherColor: string, colorWeight: number): string {
    const channels = [1, 3, 5].map((index) => {
        const first = Number.parseInt(color.slice(index, index + 2), 16);
        const second = Number.parseInt(otherColor.slice(index, index + 2), 16);

        return Math.round(first * colorWeight + second * (1 - colorWeight));
    });

    return `#${channels.map((channel) => channel.toString(16).padStart(2, '0')).join('')}`;
}

function InvoiceGroupCards({ groups }: { groups: InvoiceGroup[] }) {
    return <>
            {groups.length === 0 && <EmptyState text="Nenhuma compra encontrada para este cartão." />}
            {groups.map((group) => {
                const colors = paymentMethodCardColors(group.color);

                return <article key={`${group.paymentMethodId}-${group.closingDate}`} className="rounded-2xl border p-4" style={{ backgroundColor: colors.background, borderColor: colors.border }}>
                    <div className="flex flex-col justify-between gap-2 border-b border-white/10 pb-4 sm:flex-row sm:items-center">
                        <div>
                            <h3 className="font-semibold" style={{ color: colors.foreground }}>{group.paymentMethod}</h3>
                            <p className="mt-1 text-xs" style={{ color: colors.muted }}>Período: {formatDate(group.periodStart)} a {formatDate(group.periodEnd)}</p>
                            <p className="mt-1 text-xs" style={{ color: colors.muted }}>Fechamento: {formatDate(group.closingDate)} · Vencimento: {group.dueDate ? formatDate(group.dueDate) : 'não configurado'}</p>
                        </div>
                        <strong className="text-sm" style={{ color: colors.accent }}>{formatMoney(group.totalCents)}</strong>
                    </div>
                    <div className="mt-3 space-y-3">{group.purchases.map((purchase) => <PurchaseRow key={`${purchase.origin}-${purchase.id}`} purchase={purchase} variant="invoice" accentColor={group.color} />)}</div>
                </article>;
            })}
    </>;
}

function PurchaseRow({ purchase, variant = 'default', accentColor }: { purchase: ExpenseItem; variant?: 'default' | 'invoice'; accentColor?: string }) {
    const originLabel = purchase.type === 'occurrence' && purchase.origin === 'installment'
        ? `Parcela ${purchase.occurrenceNumber}/${purchase.occurrenceCount}${purchase.isAdjusted ? ' · ajustada' : ''}`
        : purchase.type === 'occurrence' && purchase.origin === 'recurrence'
            ? `Recorrência${purchase.isAdjusted ? ' · ajustada' : ''}`
            : null;

    return (
        <div className={`flex flex-col gap-4 rounded-2xl border p-4 sm:flex-row sm:items-center sm:justify-between ${variant === 'invoice' ? 'border-white/10 bg-slate-950/80 shadow-sm' : 'border-white/5 bg-white/[0.03]'}`} style={variant === 'invoice' ? { borderLeftColor: accentColor, borderLeftWidth: '3px' } : undefined}>
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-medium text-slate-200">{purchase.description}</p>
                    {originLabel && <span className="rounded-full bg-violet-300/10 px-2 py-1 text-[10px] font-semibold text-violet-300">{originLabel}</span>}
                </div>
                <p className="mt-1 text-xs text-slate-400">Data original: {formatDate(purchase.purchasedAt)}</p>
                <p className="mt-1 text-xs text-slate-500">{purchase.cardName ? `Nome na fatura: ${purchase.cardName} · ` : ''}{purchase.category ?? 'Sem categoria'} · {purchase.paymentMethod ?? 'Sem forma'}</p>
                <p className="mt-1 text-xs text-slate-500">Pagador: {purchase.payer ?? 'Eu'} · Participante: {purchase.participant ?? 'Eu'}</p>
            </div>
            <div className="flex shrink-0 items-center justify-between gap-4 sm:justify-end">
                <strong className="text-sm text-white">{formatMoney(purchase.amountCents)}</strong>
                <Link href={purchase.editUrl} className="text-xs font-semibold text-emerald-300">{purchase.type === 'occurrence' ? 'Ajustar' : 'Editar'}</Link>
                {purchase.canDelete && <Form action={`/purchases/${purchase.id}`} method="delete" onBefore={() => window.confirm(`Excluir ${purchase.description} permanentemente?`)}><button type="submit" className="text-xs font-semibold text-rose-300">Excluir</button></Form>}
            </div>
        </div>
    );
}

function EmptyState({ text }: { text: string }) {
    return <p className="rounded-2xl border border-dashed border-white/10 px-5 py-12 text-center text-sm text-slate-500">{text}</p>;
}

function formatMoney(cents: number): string { return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100); }
function formatDate(value: string): string { return new Intl.DateTimeFormat('pt-BR').format(new Date(`${value}T12:00:00`)); }
