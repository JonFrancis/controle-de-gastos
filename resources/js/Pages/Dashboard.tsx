import { Form, Head, Link } from '@inertiajs/react';

type Props = {
    monthLabel: string;
    selectedMonth: string;
    monthTotalCents: number;
    view: 'calendar' | 'invoice';
    purchases: ExpenseItem[];
    occurrences: ExpenseItem[];
    invoiceGroups: InvoiceGroup[];
    pendingReview: number;
    summary: {
        ownConsumptionCents: number;
        salaryCents: number | null;
        salaryRemainingCents: number | null;
    };
    catalogs: {
        participants: { id: number; name: string }[];
        categories: { id: number; name: string }[];
        paymentMethods: { id: number; name: string; type: string; closing_day: number | null }[];
    };
    flash?: { success?: string };
};
type ExpenseItem = { id: number; origin: 'manual' | 'purchase' | 'installment' | 'recurrence'; editUrl: string; occurrenceNumber?: number | null; occurrenceCount?: number | null; purchasedAt: string; description: string; cardName: string | null; amountCents: number; payer: string | null; participant: string | null; paymentMethod: string | null; category: string | null; isAdjusted?: boolean };
type InvoiceGroup = { paymentMethod: string; closingDate: string; totalCents: number; purchases: ExpenseItem[] };

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

export default function Dashboard({ monthLabel, selectedMonth, monthTotalCents, view, purchases, occurrences, invoiceGroups, pendingReview, summary, catalogs, flash }: Props) {
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
                    {catalogs.participants.length === 0 && catalogs.categories.length === 0 && catalogs.paymentMethods.length === 0 && <div className="absolute bottom-6 left-5 right-5 rounded-2xl border border-emerald-400/20 bg-emerald-400/10 p-4">
                        <p className="text-xs font-semibold uppercase tracking-wider text-emerald-300">Próximo passo</p>
                        <p className="mt-2 text-sm leading-5 text-slate-300">Cadastre uma forma de pagamento para começar.</p>
                        <Link href="/settings/catalogs" className="mt-3 inline-block text-sm font-semibold text-emerald-300">Configurar agora →</Link>
                    </div>}
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
                                <div className="flex rounded-xl border border-white/10 bg-white/5 p-1 text-sm"><Link href={`/?month=${selectedMonth}&view=calendar`} className={`rounded-lg px-3 py-2 ${view === 'calendar' ? 'bg-emerald-400 font-semibold text-slate-950' : 'text-slate-300'}`}>Mês</Link><Link href={`/?month=${selectedMonth}&view=invoice`} className={`rounded-lg px-3 py-2 ${view === 'invoice' ? 'bg-emerald-400 font-semibold text-slate-950' : 'text-slate-300'}`}>Faturas</Link></div>
                                <span className="rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm font-medium text-slate-200">{monthLabel}</span>
                                <Link href="/purchases/create" className="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-bold text-slate-950 shadow-lg shadow-emerald-400/10">＋ Nova compra</Link>
                            </div>
                        </header>

                        {flash?.success && <p role="status" className="mt-5 rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200">{flash.success}</p>}

                        <section className="mt-8 grid gap-4 md:grid-cols-2">
                            <SummaryCard label="Meus gastos totais do mês" value={formatMoney(summary.ownConsumptionCents)} note="Consumo próprio atribuído a Eu" accent="emerald" />
                            <SalarySummaryCard salaryCents={summary.salaryCents} remainingCents={summary.salaryRemainingCents} selectedMonth={selectedMonth} />
                        </section>

                        <section className="mt-5 rounded-3xl border border-white/10 bg-slate-900/70 p-6">
                            <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"><div><h2 className="font-semibold text-white">{view === 'invoice' ? 'Faturas de cartão' : 'Compras do período'}</h2><p className="mt-1 text-sm text-slate-400">{view === 'invoice' ? `${invoiceGroups.length} fatura(s)` : `${purchases.length + occurrences.length} item(ns)`} · total de <strong className="text-emerald-300">{formatMoney(monthTotalCents)}</strong></p></div><form method="get" action="/" className="flex items-center gap-2"><input type="hidden" name="view" value={view} /><input type="month" name="month" defaultValue={selectedMonth} className="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" /><button className="rounded-xl border border-white/10 px-3 py-2 text-sm text-slate-200">Filtrar</button></form></div>
                            {view === 'invoice' ? <InvoiceGroups groups={invoiceGroups} /> : <CalendarPurchases purchases={purchases} occurrences={occurrences} />}
                        </section>

                        <section className="mt-8 grid gap-5 xl:grid-cols-[1.35fr_1fr]">
                            <div className="rounded-3xl border border-white/10 bg-slate-900/70 p-6">
                            <div className="flex items-center justify-between">
                                    <div><h2 className="font-semibold text-white">Movimentação do mês</h2><p className="mt-1 text-sm text-slate-400">Seus gastos por semana</p></div>
                                    <button className="rounded-lg border border-white/10 px-3 py-2 text-xs text-slate-300">Por categoria⌄</button>
                                </div>
                                <div className="mt-8 flex h-48 items-end justify-between gap-3 px-2">
                                    {[28, 44, 36, 65, 52, 78, 60, 86, 42, 68, 55, 72].map((height, index) => <div key={index} className="flex flex-1 flex-col items-center gap-2"><div className="w-full rounded-t-lg bg-gradient-to-t from-emerald-500/40 to-emerald-300" style={{ height: `${height}%` }} /><span className="text-[10px] text-slate-500">{index + 1}</span></div>)}
                                </div>
                            </div>
                            <div className="rounded-3xl border border-white/10 bg-slate-900/70 p-6" id="compras">
                                <div className="flex items-center justify-between"><div><h2 className="font-semibold text-white">Atalhos</h2><p className="mt-1 text-sm text-slate-400">Ações frequentes</p></div><span className="text-xl text-slate-500">⋯</span></div>
                                <div className="mt-6 grid grid-cols-2 gap-3">
                                    {['Registrar compra', 'Cadastrar pessoa', 'Adicionar recebimento', 'Importar planilha'].map((label, index) => <Link key={label} href={label === 'Cadastrar pessoa' ? '/settings/catalogs' : label === 'Adicionar recebimento' ? '/receipts/create' : label === 'Importar planilha' ? '/imports/create' : '/purchases/create'} className="rounded-2xl border border-white/10 bg-white/[0.03] p-4 transition hover:border-emerald-300/40 hover:bg-emerald-300/5"><span className="text-xl">{['＋', '◎', '↗', '↥'][index]}</span><p className="mt-3 text-sm font-medium text-slate-200">{label}</p></Link>)}
                                </div>
                            </div>
                        </section>

                        <section className="mt-5 rounded-3xl border border-white/10 bg-slate-900/70 p-6" id="pessoas">
                            <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center"><div><h2 className="font-semibold text-white">Pessoas e saldos</h2><p className="mt-1 text-sm text-slate-400">Quando houver dados, seus saldos aparecerão aqui.</p></div><Link href="/balances" className="text-sm font-semibold text-emerald-300">Ver detalhes →</Link></div>
                            <div className="mt-6 rounded-2xl border border-dashed border-white/10 bg-white/[0.02] px-5 py-8 text-center"><p className="text-sm text-slate-400">Ainda não há compras ou pessoas cadastradas.</p><button className="mt-4 rounded-xl border border-emerald-300/30 px-4 py-2 text-sm font-semibold text-emerald-300">Começar configuração</button></div>
                        </section>

                        <footer className="mt-6 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500"><span>{pendingReview} itens aguardando revisão</span><span>Dados locais · BRL · America/Sao_Paulo</span></footer>
                    </div>
                </main>
            </div>
        </>
    );
}

function CalendarPurchases({ purchases, occurrences }: { purchases: ExpenseItem[]; occurrences: ExpenseItem[] }) {
    const items = [...purchases, ...occurrences].sort((left, right) => right.purchasedAt.localeCompare(left.purchasedAt));
    return <div className="mt-5 space-y-2">{items.length === 0 && <p className="rounded-2xl border border-dashed border-white/10 px-5 py-8 text-center text-sm text-slate-500">Nenhuma compra ativa neste mês.</p>}{items.map((purchase) => <PurchaseRow key={`${purchase.origin}-${purchase.id}`} purchase={purchase} />)}</div>;
}

function InvoiceGroups({ groups }: { groups: InvoiceGroup[] }) {
    return <div className="mt-5 space-y-4">{groups.length === 0 && <p className="rounded-2xl border border-dashed border-white/10 px-5 py-8 text-center text-sm text-slate-500">Nenhuma fatura de cartão neste mês.</p>}{groups.map((group) => <article key={`${group.paymentMethod}-${group.closingDate}`} className="rounded-2xl border border-white/10 bg-white/[0.02] p-4"><div className="flex items-center justify-between gap-3"><div><h3 className="text-sm font-semibold text-white">{group.paymentMethod}</h3><p className="mt-1 text-xs text-slate-400">Fechamento em {formatDate(group.closingDate)}</p></div><strong className="text-sm text-emerald-300">{formatMoney(group.totalCents)}</strong></div><div className="mt-3 space-y-2">{group.purchases.map((purchase) => <PurchaseRow key={`${purchase.origin}-${purchase.id}`} purchase={purchase} />)}</div></article>)}</div>;
}

function PurchaseRow({ purchase }: { purchase: ExpenseItem }) {
    const label = purchase.origin === 'installment' ? `Parcela ${purchase.occurrenceNumber}/${purchase.occurrenceCount}${purchase.isAdjusted ? ' · ajustada' : ''}` : purchase.origin === 'recurrence' ? `Recorrência${purchase.isAdjusted ? ' · ajustada' : ''}` : null;
    return <div className="flex flex-col gap-3 rounded-2xl border border-white/5 bg-white/[0.03] p-4 sm:flex-row sm:items-center sm:justify-between"><div><p className="text-sm font-medium text-slate-200">{purchase.description}{label && <span className="ml-2 rounded-full bg-violet-300/10 px-2 py-1 text-[10px] font-semibold text-violet-300">{label}</span>}</p><p className="mt-1 text-xs text-slate-500">{formatDate(purchase.purchasedAt)} · {purchase.cardName ? `Fatura: ${purchase.cardName} · ` : ''}{purchase.category ?? 'Sem categoria'} · {purchase.paymentMethod ?? 'Sem forma'}</p><p className="mt-1 text-xs text-slate-500">Pagador: {purchase.payer ?? 'Eu'} · Participante: {purchase.participant ?? 'Eu'}</p></div><div className="flex items-center justify-between gap-4 sm:justify-end"><strong className="text-sm text-white">{formatMoney(purchase.amountCents)}</strong><Link href={purchase.editUrl} className="text-xs font-semibold text-emerald-300">{purchase.origin === 'installment' ? 'Ajustar' : 'Editar'}</Link>{purchase.origin !== 'installment' && <Form action={`/purchases/${purchase.id}`} method="delete" onBefore={() => window.confirm(`Excluir ${purchase.description} permanentemente?`)}><button type="submit" className="text-xs font-semibold text-rose-300">Excluir</button></Form>}</div></div>;
}

function formatMoney(cents: number): string { return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100); }
function formatDate(value: string): string { return new Intl.DateTimeFormat('pt-BR').format(new Date(`${value}T12:00:00`)); }

function SummaryCard({ label, value, note, accent }: { label: string; value: string; note: string; accent: 'emerald' | 'violet' | 'amber' }) {
    const accents = { emerald: 'text-emerald-300 bg-emerald-300/10', violet: 'text-violet-300 bg-violet-300/10', amber: 'text-amber-300 bg-amber-300/10' };
    return <div className="rounded-3xl border border-white/10 bg-slate-900/70 p-5"><div className="flex items-start justify-between"><p className="text-sm text-slate-400">{label}</p><span className={`rounded-lg px-2 py-1 text-xs ${accents[accent]}`}>●</span></div><p className="mt-5 text-2xl font-bold text-white">{value}</p><p className="mt-2 text-xs text-slate-500">{note}</p></div>;
}

function SalarySummaryCard({ salaryCents, remainingCents, selectedMonth }: { salaryCents: number | null; remainingCents: number | null; selectedMonth: string }) {
    const hasDeficit = remainingCents !== null && remainingCents < 0;

    return <div className="rounded-3xl border border-white/10 bg-slate-900/70 p-5"><div className="flex items-start justify-between"><p className="text-sm text-slate-400">Restante do salário</p><span className={`rounded-lg px-2 py-1 text-xs ${hasDeficit ? 'bg-rose-300/10 text-rose-300' : 'bg-emerald-300/10 text-emerald-300'}`}>●</span></div><p className={`mt-5 text-2xl font-bold ${hasDeficit ? 'text-rose-300' : 'text-white'}`}>{remainingCents === null ? 'Salário não configurado' : formatMoney(remainingCents)}</p>{salaryCents === null ? <Link href={`/analysis?month=${selectedMonth}#salary`} className="mt-3 inline-block text-sm font-semibold text-emerald-300">Configurar salário →</Link> : <p className={`mt-2 text-xs ${hasDeficit ? 'text-rose-300' : 'text-slate-500'}`}>{hasDeficit ? 'Déficit do salário' : `Salário configurado: ${formatMoney(salaryCents)}`}</p>}</div>;
}
