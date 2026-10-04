import { Head, Link } from '@inertiajs/react';

type Summary = { ownConsumptionCents: number; paidForOthersCents: number; owedToOthersCents: number };
type Item = { key: string; description: string; purchased_at: string; amount_cents: number; applied_cents: number; outstanding_cents: number };
type ParticipantBalance = { id: number; name: string; receivableCents: number; payableCents: number; netCents: number; creditCents: number; receivableItems: Item[]; payableItems: Item[] };
type Receipt = { id: number; participant: string; receivedAt: string; amountCents: number; appliedCents: number; creditCents: number; note: string | null; manual: boolean };

type Props = {
    selectedMonth: string;
    summary: Summary;
    participants: ParticipantBalance[];
    receipts: Receipt[];
    flash?: { success?: string };
};

export default function Balances({ selectedMonth, summary, participants, receipts, flash }: Props) {
    return (
        <>
            <Head title="Saldos e recebimentos" />
            <main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10">
                <div className="mx-auto max-w-7xl">
                    <Link href="/" className="text-sm text-emerald-300">← Voltar para visão geral</Link>
                    <header className="mt-6 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                        <div>
                            <p className="text-sm font-medium text-emerald-300">Fechamento financeiro</p>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Saldos, recebimentos e créditos</h1>
                            <p className="mt-2 text-sm text-slate-400">Consulte o que foi consumido, pago por terceiros e o que ainda precisa ser compensado.</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Link href={`/receipts/create?month=${selectedMonth}`} className="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-bold text-slate-950">Registrar recebimento</Link>
                            <form method="get" action="/balances" className="flex items-center gap-2">
                                <input type="month" name="month" defaultValue={selectedMonth} className="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                                <button className="rounded-xl border border-white/10 px-3 py-2 text-sm text-slate-200">Filtrar</button>
                            </form>
                        </div>
                    </header>
                    {flash?.success && <p role="status" className="mt-5 rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200">{flash.success}</p>}
                    <section className="mt-8 grid gap-4 md:grid-cols-3">
                        <SummaryCard label="Meu consumo próprio" value={summary.ownConsumptionCents} note="Rateios atribuídos a Eu" />
                        <SummaryCard label="Paguei para terceiros" value={summary.paidForOthersCents} note="Valores a receber" />
                        <SummaryCard label="Pago por terceiros" value={summary.owedToOthersCents} note="Valores que devo" />
                    </section>
                    <section className="mt-5 rounded-3xl border border-white/10 bg-slate-900/70 p-6">
                        <h2 className="font-semibold">Pessoas e saldos líquidos</h2>
                        <p className="mt-1 text-sm text-slate-400">Os dois lados permanecem visíveis para conferência antes da compensação.</p>
                        <div className="mt-5 grid gap-4 lg:grid-cols-2">
                            {participants.length === 0 && <p className="rounded-2xl border border-dashed border-white/10 px-5 py-8 text-center text-sm text-slate-500 lg:col-span-2">Nenhum participante com saldo.</p>}
                            {participants.map((participant) => <ParticipantCard key={participant.id} participant={participant} />)}
                        </div>
                    </section>
                    <section className="mt-5 rounded-3xl border border-white/10 bg-slate-900/70 p-6">
                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                            <div>
                                <h2 className="font-semibold">Recebimentos do período</h2>
                                <p className="mt-1 text-sm text-slate-400">Consulte os valores registrados ou ajuste a aplicação de um recebimento existente.</p>
                            </div>
                            <Link href={`/receipts/create?month=${selectedMonth}`} className="text-sm font-semibold text-emerald-300">+ Registrar recebimento</Link>
                        </div>
                        <div className="mt-5 flex flex-col gap-3">
                            {receipts.length === 0 && <p className="rounded-2xl border border-dashed border-white/10 px-5 py-8 text-center text-sm text-slate-500">Nenhum recebimento registrado.</p>}
                            {receipts.map((receipt) => <div key={receipt.id} className="flex flex-col gap-3 rounded-2xl border border-white/5 bg-white/[0.03] p-4 sm:flex-row sm:items-center sm:justify-between"><div><p className="font-medium text-slate-200">{receipt.participant} · {formatDate(receipt.receivedAt)}</p><p className="mt-1 text-xs text-slate-500">{receipt.note ?? 'Sem observação'} · aplicado {formatMoney(receipt.appliedCents)}{receipt.creditCents > 0 ? ` · crédito ${formatMoney(receipt.creditCents)}` : ''}</p></div><Link href={`/receipts/${receipt.id}/edit`} className="text-xs font-semibold text-emerald-300">Ajustar aplicação</Link></div>)}
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}

function ParticipantCard({ participant }: { participant: ParticipantBalance }) {
    return <article className="rounded-2xl border border-white/5 bg-white/[0.03] p-5"><div className="flex items-start justify-between gap-3"><div><h3 className="font-semibold text-white">{participant.name}</h3><p className="mt-1 text-xs text-slate-500">Saldo líquido: <span className={participant.netCents >= 0 ? 'text-emerald-300' : 'text-amber-300'}>{formatMoney(Math.abs(participant.netCents))} {participant.netCents >= 0 ? 'a receber' : 'a pagar'}</span></p></div>{participant.creditCents > 0 && <span className="rounded-full bg-violet-300/10 px-2 py-1 text-[10px] font-semibold text-violet-300">Crédito {formatMoney(participant.creditCents)}</span>}</div><div className="mt-4 grid grid-cols-2 gap-3 text-sm"><Metric label="A receber" cents={participant.receivableCents} /><Metric label="A pagar" cents={participant.payableCents} /></div><div className="mt-4 grid gap-4 border-t border-white/5 pt-4 md:grid-cols-2"><DetailList title="Itens a receber" items={participant.receivableItems} /><DetailList title="Itens a pagar" items={participant.payableItems} /></div></article>;
}

function DetailList({ title, items }: { title: string; items: Item[] }) {
    return <div><p className="text-xs font-semibold uppercase tracking-wider text-slate-500">{title}</p><div className="mt-2 flex flex-col gap-2">{items.length === 0 && <p className="text-xs text-slate-600">Nenhum item</p>}{items.map((item) => <div key={item.key} className="rounded-xl border border-white/5 bg-slate-950/30 p-3"><p className="text-xs text-slate-300">{item.description}</p><p className="mt-1 text-[11px] text-slate-500">{formatDate(item.purchased_at)} · bruto {formatMoney(item.amount_cents)}</p><p className="mt-1 text-[11px] text-emerald-300">Aplicado {formatMoney(item.applied_cents)} · restante {formatMoney(item.outstanding_cents)}</p></div>)}</div></div>;
}

function Metric({ label, cents }: { label: string; cents: number }) { return <div className="rounded-xl border border-white/5 bg-slate-950/40 p-3"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 font-semibold text-slate-200">{formatMoney(cents)}</p></div>; }
function SummaryCard({ label, value, note }: { label: string; value: number; note: string }) { return <div className="rounded-3xl border border-white/10 bg-slate-900/70 p-5"><p className="text-sm text-slate-400">{label}</p><p className="mt-5 text-2xl font-bold text-white">{formatMoney(value)}</p><p className="mt-2 text-xs text-slate-500">{note}</p></div>; }
function formatMoney(cents: number): string { return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100); }
function formatDate(value: string): string { return new Intl.DateTimeFormat('pt-BR').format(new Date(`${value}T12:00:00`)); }
