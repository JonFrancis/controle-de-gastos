import { Form, Head, Link } from '@inertiajs/react';

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
    addedAfterClosing?: boolean;
};

type InvoiceGroup = {
    paymentMethodId: number;
    paymentMethod: string;
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
    flash?: { success?: string };
};

export default function PurchasesIndex({ monthLabel, selectedMonth, view, monthTotalCents, purchases, occurrences, invoiceGroups, flash }: Props) {
    const itemCount = purchases.length + occurrences.length;

    return (
        <>
            <Head title="Compras" />
            <main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10">
                <div className="mx-auto max-w-6xl">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Link href="/" className="text-sm text-emerald-300">← Voltar para visão geral</Link>
                        <Link href="/purchases/create" className="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-bold text-slate-950">＋ Nova compra</Link>
                    </div>

                    <header className="mt-6 flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                        <div>
                            <p className="text-sm font-medium text-emerald-300">Consulta de lançamentos</p>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Compras</h1>
                            <p className="mt-2 text-sm text-slate-400">Consulte compras, parcelas e recorrências pelo mês ou pelo ciclo da fatura.</p>
                        </div>
                        <form method="get" action="/purchases" className="flex flex-wrap items-center gap-2">
                            <input type="hidden" name="view" value={view} />
                            <label htmlFor="purchases-month" className="text-sm text-slate-400">{view === 'invoice' ? 'Faturas com vencimento em' : 'Mês'}</label>
                            <input id="purchases-month" type="month" name="month" defaultValue={selectedMonth} className="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                            <button className="rounded-xl border border-white/10 px-3 py-2 text-sm font-semibold text-slate-200 transition hover:border-emerald-300/40">Filtrar</button>
                        </form>
                    </header>

                    {flash?.success && <p role="status" className="mt-5 rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200">{flash.success}</p>}

                    <section className="mt-8 rounded-3xl border border-white/10 bg-slate-900/70 p-5 sm:p-6">
                        <div className="flex flex-col justify-between gap-4 border-b border-white/10 pb-5 sm:flex-row sm:items-center">
                            <div>
                                <p className="text-sm font-medium text-emerald-300">{monthLabel}</p>
                                <h2 className="mt-1 text-xl font-semibold text-white">{view === 'invoice' ? 'Faturas' : 'Compras do mês'}</h2>
                                <p className="mt-1 text-sm text-slate-400">{view === 'invoice' ? `${invoiceGroups.length} fatura(s)` : `${itemCount} lançamento(s)`} · total de <strong className="text-emerald-300">{formatMoney(monthTotalCents)}</strong></p>
                            </div>
                            <div className="flex rounded-xl border border-white/10 bg-white/5 p-1 text-sm" aria-label="Visão de compras">
                                <Link href={`/purchases?month=${selectedMonth}&view=calendar`} className={`rounded-lg px-3 py-2 ${view === 'calendar' ? 'bg-emerald-400 font-semibold text-slate-950' : 'text-slate-300 hover:text-white'}`}>Compras do mês</Link>
                                <Link href={`/purchases?month=${selectedMonth}&view=invoice`} className={`rounded-lg px-3 py-2 ${view === 'invoice' ? 'bg-emerald-400 font-semibold text-slate-950' : 'text-slate-300 hover:text-white'}`}>Faturas</Link>
                            </div>
                        </div>

                        {view === 'invoice' ? <InvoiceGroups groups={invoiceGroups} /> : <CalendarPurchases purchases={purchases} occurrences={occurrences} />}
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

function InvoiceGroups({ groups }: { groups: InvoiceGroup[] }) {
    return (
        <div className="mt-5 space-y-5">
            {groups.length === 0 && <EmptyState text="Nenhuma fatura de cartão neste mês." />}
            {groups.map((group) => (
                <article key={`${group.paymentMethodId}-${group.closingDate}`} className="rounded-2xl border border-white/10 bg-white/[0.02] p-4">
                    <div className="flex flex-col justify-between gap-2 border-b border-white/10 pb-4 sm:flex-row sm:items-center">
                        <div>
                            <h3 className="font-semibold text-white">{group.paymentMethod}</h3>
                            <p className="mt-1 text-xs text-slate-400">Período: {formatDate(group.periodStart)} a {formatDate(group.periodEnd)}</p>
                            <p className="mt-1 text-xs text-slate-400">Fechamento: {formatDate(group.closingDate)} · Vencimento: {group.dueDate ? formatDate(group.dueDate) : 'não configurado'}</p>
                        </div>
                        <strong className="text-sm text-emerald-300">{formatMoney(group.totalCents)}</strong>
                    </div>
                    <div className="mt-3 space-y-3">{group.purchases.map((purchase) => <PurchaseRow key={`${purchase.origin}-${purchase.id}`} purchase={purchase} />)}</div>
                </article>
            ))}
        </div>
    );
}

function PurchaseRow({ purchase }: { purchase: ExpenseItem }) {
    const originLabel = purchase.type === 'occurrence' && purchase.origin === 'installment'
        ? `Parcela ${purchase.occurrenceNumber}/${purchase.occurrenceCount}${purchase.isAdjusted ? ' · ajustada' : ''}`
        : purchase.type === 'occurrence' && purchase.origin === 'recurrence'
            ? `Recorrência${purchase.isAdjusted ? ' · ajustada' : ''}`
            : null;

    return (
        <div className="flex flex-col gap-4 rounded-2xl border border-white/5 bg-white/[0.03] p-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-medium text-slate-200">{purchase.description}</p>
                    {originLabel && <span className="rounded-full bg-violet-300/10 px-2 py-1 text-[10px] font-semibold text-violet-300">{originLabel}</span>}
                    {purchase.addedAfterClosing && <span className="rounded-full bg-amber-300/10 px-2 py-1 text-[10px] font-semibold text-amber-200">Adicionada após o fechamento</span>}
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
