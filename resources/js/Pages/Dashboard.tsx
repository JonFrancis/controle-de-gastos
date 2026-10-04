import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    selectedMonth: string;
    pendingReview: number;
    pendingReviewUrl: string | null;
    summary: {
        ownConsumptionCents: number;
        salaryCents: number | null;
        salaryRemainingCents: number | null;
    };
    charts: {
        paymentMethodTotals: ChartRow[];
        movement: MovementRow[];
        categories: { id: number; name: string }[];
        selectedCategoryId: number | null;
    };
    personChart: PersonChartData;
    flash?: { success?: string };
};
type ChartRow = { name: string; amountCents: number };
type MovementRow = { week: number; label: string; amountCents: number };
type PersonChartItem = { id: number; name: string; amountCents: number; netCents?: number; creditCents?: number };
type PersonChartData = { expenses: PersonChartItem[]; balances: PersonChartItem[] };

const navigation = [
    ['Visão geral', '/'],
    ['Compras', '/purchases'],
    ['Parcelamentos', '/installments'],
    ['Recorrentes', '/recurrences'],
    ['Saldos', '/balances'],
    ['Análise', '/analysis'],
    ['Exportações', '/exports'],
    ['Pessoas', '#pessoas'],
    ['Configurações', '/settings/catalogs'],
];

export default function Dashboard({ selectedMonth, pendingReview, pendingReviewUrl, summary, charts, personChart, flash }: Props) {
    return (
        <>
            <Head title="Visão geral" />
            <div className="min-h-screen bg-slate-950">
                <aside className="fixed inset-y-0 left-0 hidden w-64 border-r border-white/10 bg-slate-900/80 px-5 py-6 lg:block">
                    <div className="flex items-center gap-3 px-2">
                        <div className="grid h-10 w-10 place-items-center rounded-xl bg-emerald-400 font-black text-slate-950">CG</div>
                        <div>
                            <p className="font-semibold text-white">Controle de Gastos</p>
                            <p className="text-xs text-slate-400">seu dinheiro, mais claro</p>
                        </div>
                    </div>
                    <nav className="mt-10 space-y-2">
                        {navigation.map(([label, href], index) => (
                            <Link
                                key={label}
                                href={href}
                                className={`flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition ${index === 0 ? 'bg-emerald-400/10 font-semibold text-emerald-300' : 'text-slate-400 hover:bg-white/5 hover:text-white'}`}
                            >
                                <span className="w-5 text-center text-xs">{['⌂', '＋', '◫', '↻', '∑', '▤', '⇩', '◎', '⚙'][index]}</span>
                                {label}
                            </Link>
                        ))}
                    </nav>
                </aside>

                <main className="min-h-screen lg:pl-64">
                    <div className="mx-auto max-w-7xl px-5 py-6 sm:px-8 lg:px-12 lg:py-10">
                        <header className="flex flex-col justify-between gap-5 sm:flex-row sm:items-center">
                            <div>
                                <p className="text-sm font-medium text-emerald-300">Visão geral</p>
                                <h1 className="mt-1 text-3xl font-bold tracking-tight text-white">Olá, João <span aria-hidden>👋</span></h1>
                                <p className="mt-2 text-sm text-slate-400">Acompanhe seu mês sem depender de fórmulas.</p>
                            </div>
                            <div className="flex flex-wrap items-center gap-3">
                                <form method="get" action="/" className="flex items-center gap-2">
                                    <label htmlFor="dashboard-month" className="sr-only">Mês</label>
                                    <input id="dashboard-month" type="month" name="month" defaultValue={selectedMonth} className="rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white" />
                                    <button className="rounded-xl border border-white/10 px-3 py-2.5 text-sm text-slate-200">Aplicar</button>
                                </form>
                                <Link href="/purchases/create" className="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-bold text-slate-950 shadow-lg shadow-emerald-400/10">＋ Nova compra</Link>
                                <Link href={`/receipts/create?month=${selectedMonth}`} className="rounded-xl border border-emerald-300/30 px-4 py-2.5 text-sm font-semibold text-emerald-300">＋ Registrar recebimento</Link>
                            </div>
                        </header>

                        {flash?.success && <p role="status" className="mt-5 rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200">{flash.success}</p>}

                        <section className="mt-8 grid gap-4 md:grid-cols-2">
                            <SummaryCard label="Meus gastos totais do mês" value={formatMoney(summary.ownConsumptionCents)} note="Consumo próprio atribuído a Eu" accent="emerald" />
                            <SalarySummaryCard salaryCents={summary.salaryCents} remainingCents={summary.salaryRemainingCents} selectedMonth={selectedMonth} />
                        </section>

                        <MovementChart selectedMonth={selectedMonth} movement={charts.movement} categories={charts.categories} selectedCategoryId={charts.selectedCategoryId} />

                        <PaymentMethodChart rows={charts.paymentMethodTotals} />

                        <ParticipantChart chart={personChart} />

                        {pendingReview > 0 && pendingReviewUrl && <div className="mt-6 flex flex-col justify-between gap-3 rounded-2xl border border-amber-300/20 bg-amber-300/10 px-4 py-3 text-sm text-amber-100 sm:flex-row sm:items-center"><span>{pendingReview} item(ns) aguardando revisão.</span><Link href={pendingReviewUrl} className="font-semibold text-amber-200 hover:text-white">Revisar agora →</Link></div>}

                        <footer className="mt-6 flex flex-wrap items-center justify-end gap-3 text-xs text-slate-500"><span>Dados locais · BRL · America/Sao_Paulo</span></footer>
                    </div>
                </main>
            </div>
        </>
    );
}

function MovementChart({ selectedMonth, movement, categories, selectedCategoryId }: { selectedMonth: string; movement: MovementRow[]; categories: { id: number; name: string }[]; selectedCategoryId: number | null }) {
    const maximum = Math.max(...movement.map((row) => row.amountCents), 0);

    return <section className="mt-5 rounded-3xl border border-white/10 bg-slate-900/70 p-6"><div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"><div><h2 className="font-semibold text-white">Movimentação do mês</h2><p className="mt-1 text-sm text-slate-400">Consumo próprio por semana</p></div><form method="get" action="/" className="flex items-center gap-2"><input type="hidden" name="month" value={selectedMonth} /><label htmlFor="movement-category" className="sr-only">Filtrar movimentação por categoria</label><select id="movement-category" name="category" defaultValue={selectedCategoryId ?? ''} className="max-w-48 rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white"><option value="">Todas as categorias</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select><button className="rounded-lg border border-white/10 px-3 py-2 text-xs text-slate-300">Filtrar</button></form></div>{movement.length === 0 ? <p className="mt-8 rounded-2xl border border-dashed border-white/10 px-5 py-8 text-center text-sm text-slate-500">Ainda não há consumo próprio neste mês para movimentar.</p> : <div className="mt-8 flex h-48 items-end justify-between gap-3 px-2" aria-label="Movimentação semanal"><div className="sr-only">{movement.map((row) => `${row.label}: ${formatMoney(row.amountCents)}`).join(', ')}</div>{movement.map((row) => <div key={row.week} className="flex min-w-0 flex-1 flex-col items-center gap-2"><div className="flex h-40 w-full items-end"><div className="w-full rounded-t-lg bg-gradient-to-t from-emerald-500/40 to-emerald-300" style={{ height: `${(row.amountCents / maximum) * 100}%` }} title={`${row.label}: ${formatMoney(row.amountCents)}`} /></div><span className="text-center text-[10px] text-slate-500">{row.label}</span></div>)}</div>}</section>;
}

function PaymentMethodChart({ rows }: { rows: ChartRow[] }) {
    const maximum = Math.max(...rows.map((row) => row.amountCents), 0);

    return <section className="mt-5 rounded-3xl border border-white/10 bg-slate-900/70 p-6"><div><h2 className="font-semibold text-white">Gastos por forma de pagamento</h2><p className="mt-1 text-sm text-slate-400">Todos os lançamentos válidos do mês selecionado</p></div>{rows.length === 0 ? <p className="mt-6 rounded-2xl border border-dashed border-white/10 px-5 py-8 text-center text-sm text-slate-500">Ainda não há lançamentos válidos neste mês.</p> : <div className="mt-6 space-y-4">{rows.map((row) => <div key={row.name}><div className="mb-2 flex items-center justify-between gap-3 text-sm"><span className="truncate text-slate-300">{row.name}</span><strong className="shrink-0 text-emerald-300">{formatMoney(row.amountCents)}</strong></div><div className="h-3 overflow-hidden rounded-full bg-white/5"><div className="h-full rounded-full bg-gradient-to-r from-violet-400 to-fuchsia-300" style={{ width: `${(row.amountCents / maximum) * 100}%` }} /></div></div>)}</div>}</section>;
}

function ParticipantChart({ chart }: { chart: PersonChartData }) {
    const [mode, setMode] = useState<'expenses' | 'balances'>('expenses');
    const items = mode === 'expenses' ? chart.expenses : chart.balances;
    const maximum = Math.max(...items.map((item) => Math.abs(item.amountCents)), 1);
    const emptyMessage = mode === 'expenses' ? 'Nenhum gasto atribuído a pessoas neste mês.' : 'Nenhum saldo líquido para exibir neste período.';

    return <section className="mt-5 rounded-3xl border border-white/10 bg-slate-900/70 p-6" id="pessoas">
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
            <div><h2 className="font-semibold text-white">Gastos e saldos por pessoa</h2><p className="mt-1 text-sm text-slate-400">Alterne entre o valor atribuído e o saldo líquido após recebimentos.</p></div>
            <Link href="/balances" className="text-sm font-semibold text-emerald-300">Ver detalhes →</Link>
        </div>
        <div className="mt-5 inline-flex rounded-xl border border-white/10 bg-white/5 p-1" role="tablist" aria-label="Visualização por pessoa">
            <button type="button" role="tab" aria-selected={mode === 'expenses'} onClick={() => setMode('expenses')} className={`rounded-lg px-3 py-2 text-sm ${mode === 'expenses' ? 'bg-emerald-400 font-semibold text-slate-950' : 'text-slate-300'}`}>Gastos</button>
            <button type="button" role="tab" aria-selected={mode === 'balances'} onClick={() => setMode('balances')} className={`rounded-lg px-3 py-2 text-sm ${mode === 'balances' ? 'bg-emerald-400 font-semibold text-slate-950' : 'text-slate-300'}`}>Saldos</button>
        </div>
        {items.length === 0 ? <p className="mt-6 rounded-2xl border border-dashed border-white/10 px-5 py-8 text-center text-sm text-slate-500">{emptyMessage}</p> : <div className="mt-6 space-y-4" role="list">
            {items.map((item) => {
                const percentage = Math.max(6, Math.round((Math.abs(item.amountCents) / maximum) * 100));
                const isCredit = mode === 'balances' && (item.creditCents ?? 0) > 0 && (item.netCents ?? 0) <= 0;
                const barClass = mode === 'expenses' ? 'bg-emerald-300' : item.amountCents >= 0 ? 'bg-emerald-300' : isCredit ? 'bg-violet-300' : 'bg-amber-300';

                return <div key={item.id} role="listitem">
                    <div className="flex items-center justify-between gap-3 text-sm"><span className="font-medium text-slate-200">{item.name}</span><span className="text-right text-slate-300">{mode === 'expenses' ? formatMoney(item.amountCents) : balanceLabel(item)}</span></div>
                    <div className="mt-2 h-3 overflow-hidden rounded-full bg-white/5"><div className={`h-full rounded-full ${barClass}`} style={{ width: `${percentage}%` }} aria-label={`${item.name}: ${formatMoney(Math.abs(item.amountCents))}`} /></div>
                </div>;
            })}
        </div>}
    </section>;
}

function balanceLabel(item: PersonChartItem): string {
    if ((item.creditCents ?? 0) > 0 && (item.netCents ?? 0) <= 0) {
        return `Crédito ${formatMoney(item.creditCents ?? 0)}`;
    }

    return `${item.amountCents >= 0 ? 'A receber' : 'A pagar'} ${formatMoney(Math.abs(item.amountCents))}`;
}

function formatMoney(cents: number): string { return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100); }

function SummaryCard({ label, value, note, accent }: { label: string; value: string; note: string; accent: 'emerald' | 'violet' | 'amber' }) {
    const accents = { emerald: 'text-emerald-300 bg-emerald-300/10', violet: 'text-violet-300 bg-violet-300/10', amber: 'text-amber-300 bg-amber-300/10' };
    return <div className="rounded-3xl border border-white/10 bg-slate-900/70 p-5"><div className="flex items-start justify-between"><p className="text-sm text-slate-400">{label}</p><span className={`rounded-lg px-2 py-1 text-xs ${accents[accent]}`}>●</span></div><p className="mt-5 text-2xl font-bold text-white">{value}</p><p className="mt-2 text-xs text-slate-500">{note}</p></div>;
}

function SalarySummaryCard({ salaryCents, remainingCents, selectedMonth }: { salaryCents: number | null; remainingCents: number | null; selectedMonth: string }) {
    const hasDeficit = remainingCents !== null && remainingCents < 0;

    return <div className="rounded-3xl border border-white/10 bg-slate-900/70 p-5"><div className="flex items-start justify-between"><p className="text-sm text-slate-400">Restante do salário</p><span className={`rounded-lg px-2 py-1 text-xs ${hasDeficit ? 'bg-rose-300/10 text-rose-300' : 'bg-emerald-300/10 text-emerald-300'}`}>●</span></div><p className={`mt-5 text-2xl font-bold ${hasDeficit ? 'text-rose-300' : 'text-white'}`}>{remainingCents === null ? 'Salário não configurado' : formatMoney(remainingCents)}</p>{salaryCents === null ? <Link href={`/analysis?month=${selectedMonth}#salary`} className="mt-3 inline-block text-sm font-semibold text-emerald-300">Configurar salário →</Link> : <p className={`mt-2 text-xs ${hasDeficit ? 'text-rose-300' : 'text-slate-500'}`}>{hasDeficit ? 'Déficit do salário' : `Salário configurado: ${formatMoney(salaryCents)}`}</p>}</div>;
}
