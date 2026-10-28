import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ChangeEvent } from 'react';
import { createRecoverableNavigationHandler } from './recoverableNavigationFailure';
import { dashboardCategoryIdForResponse, dashboardMonthUrl, dashboardNavigationUrls, visitDashboardMonth, type DashboardNavigationBases } from './dashboardMonthNavigation';

type Props = {
    selectedMonth: string;
    view: 'calendar' | 'invoice';
    tab: 'overview' | 'personal';
    pendingReview: number;
    pendingReviewUrl: string | null;
    summary: {
        ownConsumptionCents: number;
        totalDisbursedCents: number;
        totalReceiptsCents: number;
        monthBalanceCents: number;
        salaryCents: number | null;
        salaryRemainingCents: number | null;
    };
    personalSummary: { ownConsumptionCents: number; salaryCents: number | null; salaryRemainingCents: number | null };
    ownSummary: { id: number; name: string; grossCents: number; message: string } | null;
    invoiceGroups: InvoiceGroup[];
    recentMovements: RecentMovement[];
    charts: {
        paymentMethodTotals: ChartRow[];
        categories: { id: number; name: string }[];
        selectedCategoryId: number | null;
        invoiceCategoryTotals: ChartRow[];
    };
    personChart: PersonChartData;
    flash?: { success?: string };
    navigationBases: DashboardNavigationBases;
};
type ChartRow = { name: string; amountCents: number };
type PersonChartItem = { id: number; name: string; amountCents: number; netCents?: number; creditCents?: number; status?: 'chargeable' | 'settled' };
type PersonChartData = { expenses: PersonChartItem[]; balances: PersonChartItem[] };
type InvoiceGroup = { paymentMethodId: number; paymentMethod: string; periodStart: string; periodEnd: string; closingDate: string; dueDate: string | null; totalCents: number; purchases: { id: number }[]; detailsUrl: string };
type RecentMovement = { key: string; date: string; description: string; categoryName: string | null; amountCents: number; kind: 'expense' | 'receipt'; paymentMethodName: string | null; participantName: string | null; isOwn: boolean; pending: boolean };

const brandColors = ['#3BD936', '#D97B36', '#5036D9', '#4F844D', '#594C43'];

const navigation = (navigationBases: DashboardNavigationBases, month: string, view: 'calendar' | 'invoice', categoryId: number | null) => {
    const urls = dashboardNavigationUrls(navigationBases, month, view, categoryId);
    const overviewUrl = dashboardNavigationUrls(navigationBases, month, 'calendar', categoryId).overview;

    return [
        { label: 'Visão Geral', href: overviewUrl, icon: '⌂', isActive: view === 'calendar' },
        { label: 'Compras', href: urls.purchasesInvoice, icon: 'R$', isActive: view === 'invoice' },
        { label: 'Parcelamentos', href: '/installments', icon: '∑', isActive: false },
        { label: 'Recorrentes', href: '/recurrences', icon: '↻', isActive: false },
        { label: 'Saldos', href: urls.balances, icon: '±', isActive: false },
        { label: 'Análise', href: urls.analysis, icon: '◎', isActive: false },
        { label: 'Exportações', href: '/exports', icon: '↗', isActive: false },
        { label: 'Configurações', href: '/settings/catalogs', icon: '⚙', isActive: false },
    ];
};

export default function Dashboard({ selectedMonth, view, tab, pendingReview, pendingReviewUrl, summary, personalSummary, ownSummary, charts, personChart, invoiceGroups, recentMovements, flash, navigationBases }: Props) {
    const [month, setMonth] = useState(selectedMonth);
    const [localCategoryId, setLocalCategoryId] = useState(charts.selectedCategoryId);
    const [monthRequestPending, setMonthRequestPending] = useState(false);
    const [monthRequestFailed, setMonthRequestFailed] = useState(false);
    const monthlyNavigationInFlight = useRef(false);

    useEffect(() => {
        const preserveLocalSelection = monthlyNavigationInFlight.current;
        monthlyNavigationInFlight.current = false;
        setLocalCategoryId((currentCategoryId) => dashboardCategoryIdForResponse(currentCategoryId, charts.selectedCategoryId, preserveLocalSelection));
    }, [charts.selectedCategoryId, selectedMonth]);

    const localNavigationUrls = dashboardNavigationUrls(navigationBases, month, view, localCategoryId);
    const markMonthRequestFailed = createRecoverableNavigationHandler(setMonthRequestFailed, () => {
        monthlyNavigationInFlight.current = false;
    });

    const requestMonth = (nextMonth: string) => {
        if (!nextMonth || (nextMonth === selectedMonth && !monthRequestFailed)) {
            return;
        }

        setMonth(nextMonth);
        setMonthRequestPending(true);
        setMonthRequestFailed(false);
        monthlyNavigationInFlight.current = true;

        visitDashboardMonth(router, navigationBases.overview, nextMonth, view, localCategoryId, {
            onError: markMonthRequestFailed,
            onHttpException: markMonthRequestFailed,
            onNetworkError: markMonthRequestFailed,
            onFinish: () => setMonthRequestPending(false),
        }, tab);
    };

    const handleMonthChange = (event: ChangeEvent<HTMLInputElement>) => {
        requestMonth(event.currentTarget.value);
    };
    const overviewTabUrl = dashboardMonthUrl(navigationBases.overview, month, view, localCategoryId);
    const personalTabUrl = dashboardMonthUrl(navigationBases.overview, month, view, localCategoryId, 'personal');

    return (
        <>
            <Head title={tab === 'personal' ? 'Gastos Pessoais' : view === 'invoice' ? 'Faturas' : 'Visão geral'} />
            <div className="dashboard-dark min-h-screen bg-[#f6f1e8] text-[#302b24]">
                <aside className="fixed inset-y-0 left-0 z-20 hidden w-64 border-r border-[#594c43]/15 bg-[#f1ebdf] px-5 py-6 shadow-[8px_0_28px_rgba(65,48,34,0.04)] lg:block">
                    <div className="flex items-center gap-3 px-2">
                        <div className="grid h-11 w-11 place-items-center rounded-2xl bg-[#3BD936] font-black tracking-tight text-[#17351e] shadow-[0_8px_18px_rgba(59,217,54,0.2)]">CG</div>
                        <div>
                            <p className="font-semibold text-[#302b24]">Controle de Gastos</p>
                            <p className="text-xs text-[#766b5d]">seu dinheiro, mais claro</p>
                        </div>
                    </div>
                    <nav className="mt-10 space-y-2" aria-label="Navegação principal">
                        {navigation(navigationBases, month, view, localCategoryId).map(({ label, href, icon, isActive }) => (
                            <Link key={label} href={href} aria-current={isActive ? 'page' : undefined} className={`flex items-center gap-3 rounded-2xl px-3 py-3 text-sm transition ${isActive ? 'bg-[#e0edd9] font-semibold text-[#24452b] shadow-[inset_3px_0_0_#3BD936]' : 'text-[#776d60] hover:bg-white/70 hover:text-[#302b24]'}`}>
                                <span aria-hidden="true" className={`grid h-8 w-8 place-items-center rounded-xl text-base ${isActive ? 'bg-white/75 text-[#315b37]' : 'bg-[#e9e1d4] text-[#594c43]'}`}>{icon}</span>
                                {label}
                            </Link>
                        ))}
                    </nav>
                    <div className="absolute inset-x-5 bottom-6 overflow-hidden rounded-2xl border border-[#4F844D]/20 bg-[#e8eddf] p-4">
                        <div aria-hidden="true" className="mb-3 flex h-9 w-9 items-center justify-center rounded-full bg-[#3BD936] text-lg text-[#23452c]">✳</div>
                        <p className="font-serif text-lg font-semibold leading-tight text-[#294a30]">Organização para uma vida mais leve.</p>
                        <div className="mt-3 h-1 w-12 rounded-full bg-[#D97B36]" />
                    </div>
                </aside>

                <main className="min-h-screen lg:pl-64">
                    <div className="mx-auto max-w-[1480px] px-5 py-6 sm:px-8 lg:px-10 lg:py-8">
                        <header className="flex flex-col justify-between gap-5 xl:flex-row xl:items-center">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[#4F844D]">{tab === 'personal' ? 'Visão pessoal' : view === 'invoice' ? 'Faturas' : 'Seu dinheiro em perspectiva'}</p>
                                <h1 className="mt-1 font-serif text-4xl font-semibold tracking-tight text-[#263b2b]">{tab === 'personal' ? 'Gastos pessoais' : view === 'invoice' ? 'O que precisa ser pago' : 'Olá!'}</h1>
                                <p className="mt-1 text-sm text-[#766b5d]">{tab === 'personal' ? 'Acompanhe seus gastos, o salário restante e suas compras.' : view === 'invoice' ? 'Consulte as faturas pelo mês de vencimento.' : `Aqui está o resumo das suas finanças em ${formatMonth(month)}.`}</p>
                            </div>
                            <div className="flex flex-wrap items-center gap-3" aria-busy={monthRequestPending}>
                                <label htmlFor="dashboard-month" className="sr-only">{view === 'invoice' ? 'Mês de vencimento' : 'Mês'}</label>
                                <input id="dashboard-month" type="month" name="month" value={month} onChange={handleMonthChange} disabled={monthRequestPending} className="rounded-2xl border border-[#594c43]/15 bg-[#fffdf8] px-3 py-2.5 text-sm text-[#302b24] shadow-sm disabled:cursor-wait disabled:opacity-60" />
                                {monthRequestPending && <span role="status" aria-live="polite" className="text-xs text-[#766b5d]">Atualizando…</span>}
                                {monthRequestFailed && !monthRequestPending && <span role="alert" className="flex items-center gap-2 text-xs text-[#a44e24]">Não foi possível atualizar. <button type="button" onClick={() => requestMonth(month)} className="font-semibold underline underline-offset-2">Tentar novamente</button></span>}
                                <Link href={localNavigationUrls.purchaseCreate} className="rounded-2xl bg-[#3BD936] px-4 py-3 text-sm font-bold text-[#17351e] shadow-[0_8px_18px_rgba(59,217,54,0.19)] transition hover:-translate-y-0.5 hover:shadow-[0_12px_22px_rgba(59,217,54,0.25)]">＋ Nova compra</Link>
                                <Link href={localNavigationUrls.receipt} className="rounded-2xl border border-[#D97B36]/35 bg-[#fff9f2] px-4 py-3 text-sm font-semibold text-[#96501f] transition hover:bg-[#f8e8d7]">＋ Registrar recebimento</Link>
                            </div>
                        </header>

                        {flash?.success && <p role="status" className="mt-5 rounded-2xl border border-[#4F844D]/20 bg-[#e6efdf] px-4 py-3 text-sm text-[#315b37]">{flash.success}</p>}

                        <nav className="mt-6 inline-flex rounded-2xl border border-[#594c43]/10 bg-[#eee7db] p-1" aria-label="Abas da tela inicial">
                            <Link href={overviewTabUrl} aria-current={tab === 'overview' ? 'page' : undefined} className={`rounded-xl px-4 py-2 text-sm transition ${tab === 'overview' ? 'bg-[#fffdf8] font-semibold text-[#24452b] shadow-sm' : 'text-[#766b5d] hover:text-[#302b24]'}`}>Visão Geral</Link>
                            <Link href={personalTabUrl} aria-current={tab === 'personal' ? 'page' : undefined} className={`rounded-xl px-4 py-2 text-sm transition ${tab === 'personal' ? 'bg-[#fffdf8] font-semibold text-[#24452b] shadow-sm' : 'text-[#766b5d] hover:text-[#302b24]'}`}>Gastos Pessoais</Link>
                        </nav>

                        {tab === 'personal' ? <>
                            <section className="mt-6 grid gap-4 md:grid-cols-2">
                                <SummaryCard label="Meus gastos totais do mês" value={formatMoney(personalSummary.ownConsumptionCents)} note="Consumo próprio atribuído a Eu" accent="emerald" />
                                <SalarySummaryCard salaryCents={personalSummary.salaryCents} remainingCents={personalSummary.salaryRemainingCents} salaryUrl={localNavigationUrls.analysisSalary} />
                            </section>
                            <InvoiceCategoryChart selectedMonth={month} rows={charts.invoiceCategoryTotals} />
                            <OwnPurchasesSummary ownSummary={ownSummary} selectedMonth={month} />
                        </> : view === 'invoice' ? <InvoiceOverview groups={invoiceGroups} selectedMonth={selectedMonth} totalCents={summary.totalDisbursedCents} invoiceListUrl={localNavigationUrls.purchasesInvoice} /> : <>
                            <section className="mt-5 grid gap-5 xl:grid-cols-[1.08fr_0.92fr]">
                                <PaymentMethodChart rows={charts.paymentMethodTotals} />
                                <ParticipantChart chart={personChart} balancesUrl={localNavigationUrls.balances} />
                            </section>
                            <RecentMovements rows={recentMovements} purchasesUrl={localNavigationUrls.purchasesCalendar} />
                        </>}

                        {pendingReview > 0 && pendingReviewUrl && <div className="mt-6 flex flex-col justify-between gap-3 rounded-2xl border border-[#D97B36]/25 bg-[#f8e8d7] px-4 py-3 text-sm text-[#71411f] sm:flex-row sm:items-center"><span>{pendingReview} item(ns) aguardando revisão.</span><Link href={pendingReviewUrl} className="font-semibold text-[#96501f] hover:text-[#594c43]">Revisar agora →</Link></div>}

                        <footer className="mt-6 flex flex-wrap items-center justify-end gap-3 text-xs text-[#8b8072]"><span>Dados locais · BRL · America/Sao_Paulo</span></footer>
                    </div>
                </main>
            </div>
        </>
    );
}

function InvoiceOverview({ groups, selectedMonth, totalCents, invoiceListUrl }: { groups: InvoiceGroup[]; selectedMonth: string; totalCents: number; invoiceListUrl: string }) {
    return <section className="mt-8 rounded-3xl border border-[#594c43]/10 bg-[#fffdf8] p-6 shadow-[0_12px_30px_rgba(65,48,34,0.05)]">
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
            <div><p className="text-sm font-medium text-[#4F844D]">Faturas com vencimento em {formatMonth(selectedMonth)}</p><h2 className="mt-1 font-serif text-xl font-semibold text-[#302b24]">Total a pagar: {formatMoney(totalCents)}</h2><p className="mt-1 text-sm text-[#8b8072]">{groups.length} fatura(s) de cartão, separadas por cartão e ciclo.</p></div>
            <Link href={invoiceListUrl} className="text-sm font-semibold text-[#4F844D]">Ver detalhes →</Link>
        </div>
        <div className="mt-6 grid gap-4 md:grid-cols-2">
            {groups.length === 0 && <p className="rounded-2xl border border-dashed border-[#594c43]/20 px-5 py-10 text-center text-sm text-[#8b8072] md:col-span-2">Nenhuma fatura de cartão com vencimento neste mês.</p>}
            {groups.map((group, index) => <article key={`${group.paymentMethodId}-${group.closingDate}`} className="rounded-2xl border border-[#594c43]/10 bg-[#f8f4ec] p-4"><div className="flex items-start justify-between gap-3"><div><Link href={group.detailsUrl} className="font-semibold text-[#302b24] hover:text-[#4F844D]">{group.paymentMethod}</Link><p className="mt-1 text-xs text-[#8b8072]">Período: {formatDate(group.periodStart)} a {formatDate(group.periodEnd)}</p><p className="mt-1 text-xs text-[#8b8072]">Fecha em {formatDate(group.closingDate)} · vence em {group.dueDate ? formatDate(group.dueDate) : 'não configurado'}</p></div><strong className="text-sm" style={{ color: brandColors[index % brandColors.length] }}>{formatMoney(group.totalCents)}</strong></div><p className="mt-4 text-xs text-[#8b8072]">{group.purchases.length} lançamento(s)</p></article>)}
        </div>
    </section>;
}

function RecentMovements({ rows, purchasesUrl }: { rows: RecentMovement[]; purchasesUrl: string }) {
    return <section className="mt-5 overflow-hidden rounded-[1.75rem] border border-[#594c43]/10 bg-[#fffdf8] shadow-[0_12px_30px_rgba(65,48,34,0.05)]">
        <div className="flex flex-col justify-between gap-3 border-b border-[#594c43]/10 px-5 py-5 sm:flex-row sm:items-center sm:px-6">
            <div><h2 className="font-serif text-xl font-semibold text-[#302b24]">Movimentações recentes</h2><p className="mt-1 text-sm text-[#8b8072]">Compras e recebimentos deste mês</p></div>
            <Link href={purchasesUrl} className="text-sm font-semibold text-[#4F844D] hover:text-[#315b37]">Ver compras →</Link>
        </div>
        {rows.length === 0 ? <p className="px-5 py-10 text-center text-sm text-[#8b8072]">Nenhuma movimentação neste mês.</p> : <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-left text-sm">
                <thead className="text-xs uppercase tracking-wider text-[#8b8072]"><tr><th className="px-6 py-3 font-medium">Data</th><th className="px-3 py-3 font-medium">Descrição</th><th className="px-3 py-3 font-medium">Forma</th><th className="px-3 py-3 font-medium">Pessoa</th><th className="px-6 py-3 text-right font-medium">Valor</th></tr></thead>
                <tbody className="divide-y divide-[#594c43]/10">{rows.map((row) => <tr key={row.key} className="transition hover:bg-[#f7f3eb]">
                    <td className="whitespace-nowrap px-6 py-3.5 text-[#766b5d]">{formatDate(row.date)}</td>
                    <td className="px-3 py-3.5"><div className="flex items-center gap-2">
                        {row.kind === 'expense' && row.isOwn ? <span aria-label={`Categoria: ${row.categoryName ?? 'Sem categoria'}`} title={row.categoryName ?? 'Sem categoria'} className="grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-[#e3f0dc]"><span aria-hidden="true" className="h-3 w-3 rounded-[3px]" style={{ backgroundColor: categoryColor(row.categoryName) }} /></span> : <span aria-hidden="true" className={`grid h-8 w-8 shrink-0 place-items-center rounded-xl ${row.kind === 'receipt' ? 'bg-[#e3f0dc] text-[#315b37]' : 'bg-[#f1e8db] text-[#594c43]'}`}>{row.kind === 'receipt' ? '↙' : '↗'}</span>}
                        <span className="max-w-64 truncate font-medium text-[#302b24]">{row.description}</span>{row.pending && <span className="rounded-full bg-[#f8e8d7] px-2 py-1 text-[10px] font-semibold text-[#96501f]">Revisar</span>}
                    </div></td>
                    <td className="px-3 py-3.5 text-[#766b5d]">{row.paymentMethodName ?? (row.kind === 'receipt' ? 'Recebimento' : '—')}</td>
                    <td className="px-3 py-3.5 text-[#766b5d]">{row.participantName ?? '—'}</td>
                    <td className={`whitespace-nowrap px-6 py-3.5 text-right font-semibold ${row.kind === 'receipt' ? 'text-[#315b37]' : 'text-[#594c43]'}`}>{row.kind === 'receipt' ? '+' : '−'}{formatMoney(row.amountCents)}</td>
                </tr>)}</tbody>
            </table>
        </div>}
    </section>;
}

function InvoiceCategoryChart({ selectedMonth, rows }: { selectedMonth: string; rows: ChartRow[] }) {
    const total = rows.reduce((sum, row) => sum + row.amountCents, 0);
    let offset = 0;
    const segments = rows.map((row) => {
        const start = offset;
        offset += total > 0 ? (row.amountCents / total) * 100 : 0;
        return `${categoryColor(row.name)} ${start.toFixed(2)}% ${offset.toFixed(2)}%`;
    });
    const chartBackground = total > 0 ? `conic-gradient(${segments.join(', ')})` : '#293429';

    return <section className="mt-5 rounded-[1.75rem] border border-[#594c43]/10 bg-[#fffdf8] p-5 shadow-[0_12px_30px_rgba(65,48,34,0.05)] sm:p-6">
        <div className="flex items-start justify-between gap-3"><div><h2 className="font-serif text-xl font-semibold text-[#302b24]">Gastos por categoria da fatura</h2><p className="mt-1 text-sm text-[#8b8072]">Consumo próprio na fatura com vencimento em {formatMonth(selectedMonth)}</p></div><span aria-hidden="true" className="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-[#f1e8db] text-lg text-[#594c43]">◉</span></div>
        {rows.length === 0 ? <p className="mt-6 rounded-2xl border border-dashed border-[#594c43]/20 px-5 py-8 text-center text-sm text-[#8b8072]">Ainda não há gastos próprios nesta fatura.</p> : <div className="mt-6 grid gap-6 md:grid-cols-[minmax(160px,0.7fr)_minmax(0,1.3fr)] md:items-center">
            <div className="mx-auto grid h-44 w-44 place-items-center rounded-full p-3" style={{ background: chartBackground }} role="img" aria-label={`Distribuição de gastos por categoria da fatura: ${rows.map((row) => `${row.name}, ${formatMoney(row.amountCents)}`).join('; ')}`}>
                <div className="grid h-full w-full place-items-center rounded-full border border-[#594c43]/10 bg-[#fffdf8] text-center shadow-inner"><div><p className="text-[11px] text-[#8b8072]">Total na fatura</p><p className="mt-1 font-serif text-xl font-semibold text-[#302b24]">{formatMoney(total)}</p></div></div>
            </div>
            <div className="divide-y divide-[#594c43]/10" role="list" aria-label="Categorias da fatura">{rows.map((row) => <div key={row.name} role="listitem" className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                <span aria-hidden="true" className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: categoryColor(row.name) }} />
                <span className="min-w-0 flex-1 truncate text-sm text-[#594c43]">{row.name === 'Sem informação' ? 'Sem categoria' : row.name}</span>
                <span className="text-xs text-[#8b8072]">{total > 0 ? Math.round((row.amountCents / total) * 100) : 0}%</span>
                <strong className="whitespace-nowrap text-sm text-[#302b24]">{formatMoney(row.amountCents)}</strong>
            </div>)}</div>
        </div>}
    </section>;
}

function categoryColor(name: string | null): string {
    if (!name) {
        return brandColors[4];
    }

    const hash = Array.from(name.trim().toLocaleLowerCase('pt-BR')).reduce((sum, character) => sum + character.charCodeAt(0), 0);

    return brandColors[hash % brandColors.length];
}

function PaymentMethodChart({ rows }: { rows: ChartRow[] }) {
    const total = rows.reduce((sum, row) => sum + row.amountCents, 0);
    let offset = 0;
    const segments = rows.map((row, index) => {
        const start = offset;
        offset += total > 0 ? (row.amountCents / total) * 100 : 0;
        return `${brandColors[index % brandColors.length]} ${start.toFixed(2)}% ${offset.toFixed(2)}%`;
    });
    const chartBackground = rows.length > 0 ? `conic-gradient(${segments.join(', ')})` : '#e9e1d4';

    return <section className="rounded-[1.75rem] border border-[#594c43]/10 bg-[#fffdf8] p-5 shadow-[0_12px_30px_rgba(65,48,34,0.05)] sm:p-6">
        <div className="flex items-start justify-between gap-3"><div><h2 className="font-serif text-xl font-semibold text-[#302b24]">Gastos por forma de pagamento</h2><p className="mt-1 text-sm text-[#8b8072]">Lançamentos válidos do mês</p></div><span aria-hidden="true" className="grid h-10 w-10 place-items-center rounded-2xl bg-[#f1e8db] text-lg text-[#594c43]">◉</span></div>
        {rows.length === 0 ? <p className="mt-6 rounded-2xl border border-dashed border-[#594c43]/20 px-5 py-8 text-center text-sm text-[#8b8072]">Ainda não há lançamentos válidos neste mês.</p> : <div className="mt-6 grid gap-6 md:grid-cols-[minmax(160px,0.7fr)_minmax(0,1.3fr)] md:items-center">
            <div className="mx-auto grid h-44 w-44 place-items-center rounded-full p-3" style={{ background: chartBackground }} role="img" aria-label={`Distribuição de gastos por forma de pagamento: ${rows.map((row) => `${row.name}, ${formatMoney(row.amountCents)}`).join('; ')}`}>
                <div className="grid h-full w-full place-items-center rounded-full border border-[#594c43]/10 bg-[#fffdf8] text-center shadow-inner"><div><p className="text-[11px] text-[#8b8072]">Total no mês</p><p className="mt-1 font-serif text-xl font-semibold text-[#302b24]">{formatMoney(total)}</p></div></div>
            </div>
            <div className="divide-y divide-[#594c43]/10">{rows.map((row, index) => <div key={row.name} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                <span aria-hidden="true" className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: brandColors[index % brandColors.length] }} />
                <span className="min-w-0 flex-1 truncate text-sm text-[#594c43]">{row.name}</span>
                <span className="text-xs text-[#8b8072]">{total > 0 ? Math.round((row.amountCents / total) * 100) : 0}%</span>
                <strong className="whitespace-nowrap text-sm text-[#302b24]">{formatMoney(row.amountCents)}</strong>
            </div>)}</div>
        </div>}
    </section>;
}

function ParticipantChart({ chart, balancesUrl }: { chart: PersonChartData; balancesUrl: string }) {
    const [mode, setMode] = useState<'expenses' | 'balances'>('expenses');
    const items = mode === 'expenses' ? chart.expenses : chart.balances;
    const maximum = Math.max(...items.map((item) => Math.abs(item.amountCents)), 1);
    const emptyMessage = mode === 'expenses' ? 'Nenhum gasto atribuído a pessoas neste mês.' : 'Nenhum saldo líquido para exibir neste período.';

    return <section className="rounded-[1.75rem] border border-[#594c43]/10 bg-[#fffdf8] p-5 shadow-[0_12px_30px_rgba(65,48,34,0.05)] sm:p-6" id="pessoas">
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
            <div><h2 className="font-serif text-xl font-semibold text-[#302b24]">Gastos por pessoa</h2><p className="mt-1 text-sm text-[#8b8072]">Veja o total atribuído ou o saldo após recebimentos.</p></div>
            <Link href={balancesUrl} className="text-sm font-semibold text-[#4F844D]">Ver detalhes →</Link>
        </div>
        <div className="mt-5 inline-flex rounded-xl bg-[#eee7db] p-1" role="tablist" aria-label="Visualização por pessoa">
            <button type="button" role="tab" aria-selected={mode === 'expenses'} onClick={() => setMode('expenses')} className={`rounded-lg px-3 py-2 text-sm transition ${mode === 'expenses' ? 'bg-[#fffdf8] font-semibold text-[#24452b] shadow-sm' : 'text-[#766b5d]'}`}>Gastos</button>
            <button type="button" role="tab" aria-selected={mode === 'balances'} onClick={() => setMode('balances')} className={`rounded-lg px-3 py-2 text-sm transition ${mode === 'balances' ? 'bg-[#fffdf8] font-semibold text-[#24452b] shadow-sm' : 'text-[#766b5d]'}`}>Saldos</button>
        </div>
        {items.length === 0 ? <p className="mt-6 rounded-2xl border border-dashed border-[#594c43]/20 px-5 py-8 text-center text-sm text-[#8b8072]">{emptyMessage}</p> : <div className="mt-5 space-y-4" role="list">
            {items.map((item, index) => {
                const percentage = Math.max(6, Math.round((Math.abs(item.amountCents) / maximum) * 100));
                const isCredit = mode === 'balances' && (item.creditCents ?? 0) > 0 && (item.netCents ?? 0) <= 0;
                const color = mode === 'expenses' ? brandColors[index % brandColors.length] : item.amountCents >= 0 ? '#4F844D' : isCredit ? '#5036D9' : '#D97B36';

                return <div key={item.id} role="listitem">
                    <div className="flex items-center gap-3 text-sm"><span aria-hidden="true" className="grid h-9 w-9 shrink-0 place-items-center rounded-full font-semibold text-white" style={{ backgroundColor: color }}>{item.name.trim().slice(0, 1).toLocaleUpperCase('pt-BR')}</span><div className="min-w-0 flex-1"><div className="flex items-center justify-between gap-2"><span className="truncate font-medium text-[#302b24]">{item.name}</span><span className="whitespace-nowrap text-right text-[#594c43]">{mode === 'expenses' ? formatMoney(item.amountCents) : balanceLabel(item)}</span></div><div className="mt-2 h-2 overflow-hidden rounded-full bg-[#eee8dd]"><div className="h-full rounded-full transition-[width]" style={{ width: `${percentage}%`, backgroundColor: color }} aria-label={`${item.name}: ${formatMoney(Math.abs(item.amountCents))}`} /></div></div></div>
                </div>;
            })}
        </div>}
    </section>;
}

function balanceLabel(item: PersonChartItem): string {
    if (item.status === 'settled') {
        return 'Quitado';
    }

    if ((item.creditCents ?? 0) > 0 && (item.netCents ?? 0) <= 0) {
        return `Crédito ${formatMoney(item.creditCents ?? 0)}`;
    }

    return `${item.amountCents >= 0 ? 'A receber' : 'A pagar'} ${formatMoney(Math.abs(item.amountCents))}`;
}

function formatMoney(cents: number): string { return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100); }
function formatMonth(value: string): string { return new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' }).format(new Date(`${value}-15T12:00:00`)); }
function formatDate(value: string): string { return new Intl.DateTimeFormat('pt-BR').format(new Date(`${value}T12:00:00`)); }

function SummaryCard({ label, value, note, accent }: { label: string; value: string; note: string; accent: 'emerald' | 'violet' | 'amber' }) {
    const accents = { emerald: 'text-[#315b37] bg-[#e3f0dc]', violet: 'text-[#5036D9] bg-[#ece8ff]', amber: 'text-[#96501f] bg-[#f8e8d7]' };
    return <div className="rounded-3xl border border-[#594c43]/10 bg-[#fffdf8] p-5 shadow-[0_12px_30px_rgba(65,48,34,0.05)]"><div className="flex items-start justify-between"><p className="text-sm text-[#766b5d]">{label}</p><span className={`rounded-lg px-2 py-1 text-xs ${accents[accent]}`}>●</span></div><p className="mt-5 font-serif text-2xl font-semibold text-[#302b24]">{value}</p><p className="mt-2 text-xs text-[#8b8072]">{note}</p></div>;
}

function SalarySummaryCard({ salaryCents, remainingCents, salaryUrl }: { salaryCents: number | null; remainingCents: number | null; salaryUrl: string }) {
    const hasDeficit = remainingCents !== null && remainingCents < 0;

    return <div className="rounded-3xl border border-[#594c43]/10 bg-[#fffdf8] p-5 shadow-[0_12px_30px_rgba(65,48,34,0.05)]"><div className="flex items-start justify-between"><p className="text-sm text-[#766b5d]">Restante do salário</p><span className={`rounded-lg px-2 py-1 text-xs ${hasDeficit ? 'bg-[#f8e8d7] text-[#96501f]' : 'bg-[#e3f0dc] text-[#315b37]'}`}>●</span></div><p className={`mt-5 font-serif text-2xl font-semibold ${hasDeficit ? 'text-[#96501f]' : 'text-[#302b24]'}`}>{remainingCents === null ? 'Salário não configurado' : formatMoney(remainingCents)}</p>{salaryCents === null ? <Link href={salaryUrl} className="mt-3 inline-block text-sm font-semibold text-[#4F844D]">Configurar salário →</Link> : <p className={`mt-2 text-xs ${hasDeficit ? 'text-[#96501f]' : 'text-[#8b8072]'}`}>{hasDeficit ? 'Déficit do salário' : `Salário configurado: ${formatMoney(salaryCents)}`}</p>}</div>;
}

function OwnPurchasesSummary({ ownSummary, selectedMonth }: { ownSummary: Props['ownSummary']; selectedMonth: string }) {
    const [message, setMessage] = useState(ownSummary?.message ?? '');
    const [copied, setCopied] = useState(false);
    const [copyError, setCopyError] = useState(false);

    useEffect(() => {
        setMessage(ownSummary?.message ?? '');
        setCopied(false);
        setCopyError(false);
    }, [ownSummary?.message, selectedMonth]);

    const copyMessage = async () => {
        setCopyError(false);
        if (!navigator.clipboard) {
            setCopyError(true);
            return;
        }

        try {
            await navigator.clipboard.writeText(message);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1800);
        } catch {
            setCopyError(true);
        }
    };

    return <section className="mt-5 rounded-3xl border border-[#594c43]/10 bg-[#fffdf8] p-6 shadow-[0_12px_30px_rgba(65,48,34,0.05)]">
        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
            <div><h2 className="font-serif font-semibold text-[#302b24]">Suas compras</h2><p className="mt-1 text-sm text-[#8b8072]">Resumo próprio de {formatMonth(selectedMonth)} · sem cobrança.</p></div>
            {ownSummary && <strong className="text-sm text-[#315b37]">{formatMoney(ownSummary.grossCents)}</strong>}
        </div>
        {!ownSummary ? <p className="mt-5 rounded-xl border border-dashed border-[#594c43]/20 px-4 py-6 text-center text-sm text-[#8b8072]">Nenhuma compra própria neste mês.</p> : <>
            <textarea aria-label="Resumo das suas compras" value={message} onChange={(event) => setMessage(event.target.value)} className="mt-5 min-h-40 w-full resize-y rounded-xl border border-[#594c43]/15 bg-[#f8f4ec] px-3 py-3 text-sm leading-6 text-[#302b24] outline-none focus:border-[#4F844D]/60" />
            <button type="button" onClick={copyMessage} className="mt-3 rounded-xl bg-[#3BD936] px-4 py-2.5 text-sm font-semibold text-[#17351e]">{copied ? 'Copiado' : 'Copiar resumo das minhas compras'}</button>
            {copyError && <p role="alert" className="mt-3 text-sm text-[#96501f]">Não foi possível copiar automaticamente. Selecione e copie o texto manualmente.</p>}
        </>}
    </section>;
}
