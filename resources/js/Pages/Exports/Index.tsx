import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';

type Summary = {
    purchaseTotalCents: number;
    allocationTotalCents: number;
    receiptTotalCents: number;
    occurrenceTotalCents: number;
    totalDisbursedCents: number;
    reconciliationPassed: boolean;
};

type Counts = { purchases: number; allocations: number; receipts: number; recurrences: number; installments: number };
type Props = { mode: 'period' | 'history'; startDate: string | null; endDate: string | null; summary: Summary; counts: Counts; prompt: string; flash?: { openai?: { response?: string } } };

const cardClass = 'rounded-3xl border border-white/10 bg-slate-900/70 p-5';
const inputClass = 'rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-300/50';

export default function Exports({ mode: initialMode, startDate: initialStartDate, endDate: initialEndDate, summary, counts, prompt: initialPrompt, flash }: Props) {
    const [mode, setMode] = useState<'period' | 'history'>(initialMode);
    const [startDate, setStartDate] = useState(initialStartDate ?? '');
    const [endDate, setEndDate] = useState(initialEndDate ?? '');
    const [prompt, setPrompt] = useState(initialPrompt);
    const [copied, setCopied] = useState(false);
    const [copyError, setCopyError] = useState(false);
    const openAiForm = useForm<{ content: string; openai?: string }>({ content: initialPrompt });

    const query = useMemo(() => {
        const params = new URLSearchParams({ mode });
        if (mode === 'period') {
            params.set('start_date', startDate);
            params.set('end_date', endDate);
        }
        return `?${params.toString()}`;
    }, [mode, startDate, endDate]);

    const copyPrompt = async () => {
        setCopyError(false);
        if (!navigator.clipboard) {
            setCopyError(true);
            return;
        }
        try {
            await navigator.clipboard.writeText(prompt);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1800);
        } catch {
            setCopied(false);
            setCopyError(true);
        }
    };

    const sendPrompt = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        openAiForm.setData('content', prompt);
        openAiForm.post('/openai/responses');
    };

    return <><Head title="Exportações" /><main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10"><div className="mx-auto max-w-7xl"><Link href="/" className="text-sm text-emerald-300">← Voltar para visão geral</Link><header className="mt-6 flex flex-col justify-between gap-4 lg:flex-row lg:items-end"><div><p className="text-sm font-medium text-emerald-300">Dados oficiais</p><h1 className="mt-1 text-3xl font-bold tracking-tight">Exportações</h1><p className="mt-2 max-w-2xl text-sm text-slate-400">Escolha um período ou exporte todo o histórico sem alterar compras, rateios ou recebimentos.</p></div><Link href={`/analysis?month=${(startDate || new Date().toISOString().slice(0, 7)).slice(0, 7)}`} className="text-sm text-slate-300 underline decoration-white/20 underline-offset-4">Abrir análise mensal</Link></header><section className={`${cardClass} mt-8`}><div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"><div><h2 className="font-semibold">Escopo da exportação</h2><p className="mt-1 text-sm text-slate-400">A seleção também será usada nos links de download.</p></div><form method="get" action="/exports" className="flex flex-wrap items-center gap-2"><label className="flex items-center gap-2 rounded-xl border border-white/10 px-3 py-2 text-sm text-slate-200"><input type="radio" name="mode" value="period" checked={mode === 'period'} onChange={() => setMode('period')} />Período</label><label className="flex items-center gap-2 rounded-xl border border-white/10 px-3 py-2 text-sm text-slate-200"><input type="radio" name="mode" value="history" checked={mode === 'history'} onChange={() => setMode('history')} />Histórico completo</label><input type="hidden" name="start_date" value={startDate} /><input type="hidden" name="end_date" value={endDate} /><button type="submit" className="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-semibold text-slate-950">Aplicar</button></form></div>{mode === 'period' && <div className="mt-5 grid gap-4 sm:grid-cols-2"><label className="flex flex-col gap-2 text-sm text-slate-300">Data inicial<input type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} className={inputClass} /></label><label className="flex flex-col gap-2 text-sm text-slate-300">Data final<input type="date" value={endDate} onChange={(event) => setEndDate(event.target.value)} className={inputClass} /></label></div>}</section><section className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><SummaryCard label="Compras" value={formatMoney(summary.purchaseTotalCents)} count={`${counts.purchases} registro(s)`} /><SummaryCard label="Rateios" value={formatMoney(summary.allocationTotalCents)} count={`${counts.allocations} registro(s)`} /><SummaryCard label="Recebimentos" value={formatMoney(summary.receiptTotalCents)} count={`${counts.receipts} registro(s)`} /><SummaryCard label="Ocorrências" value={formatMoney(summary.occurrenceTotalCents)} count={`${counts.recurrences + counts.installments} regra(s)`} /><div className={cardClass}><p className="text-sm text-slate-400">Conferência</p><p className={`mt-4 text-xl font-bold ${summary.reconciliationPassed ? 'text-emerald-300' : 'text-amber-300'}`}>{summary.reconciliationPassed ? 'OK' : 'Revisar'}</p><p className="mt-1 text-xs text-slate-500">Total desembolsado {formatMoney(summary.totalDisbursedCents)}</p></div></section><section className={`${cardClass} mt-5`}><h2 className="font-semibold">Baixar dados estruturados</h2><p className="mt-1 text-sm text-slate-400">Os CSVs são separados por entidade para conferência e análise posterior.</p><div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">{[['purchases', 'Compras'], ['allocations', 'Rateios'], ['receipts', 'Recebimentos'], ['recurrences', 'Recorrências'], ['installments', 'Parcelamentos']].map(([type, label]) => <a key={type} href={`/exports/csv/${type}${query}`} className="rounded-xl border border-white/10 bg-white/[0.03] px-3 py-3 text-center text-sm font-semibold text-slate-200 transition hover:border-emerald-300/40 hover:text-emerald-300">CSV de {label}</a>)}</div><div className="mt-4 flex flex-wrap gap-3"><a href={`/exports/excel${query}`} className="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-semibold text-slate-950">Excel consolidado</a><a href={`/exports/markdown${query}`} className="rounded-xl border border-emerald-300/30 px-4 py-2.5 text-sm font-semibold text-emerald-300">Markdown da análise</a></div></section><section className={`${cardClass} mt-5`}><div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start"><div><h2 className="font-semibold">Prompt para IA</h2><p className="mt-1 max-w-2xl text-sm text-slate-400">Edite o texto, copie para o chat de sua preferência ou baixe uma versão inicial. Nenhuma API é chamada por esta tela.</p></div><div className="flex gap-2"><button type="button" onClick={copyPrompt} className="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-semibold text-slate-950">{copied ? 'Copiado' : 'Copiar prompt'}</button><a href={`/exports/prompt${query}`} className="rounded-xl border border-white/10 px-4 py-2.5 text-sm font-semibold text-slate-200">Baixar prompt</a></div></div><textarea aria-label="Prompt editável para IA" value={prompt} onChange={(event) => { setPrompt(event.target.value); openAiForm.setData('content', event.target.value); }} className="mt-5 min-h-72 w-full resize-y rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 font-mono text-sm leading-6 text-slate-200 outline-none focus:border-emerald-300/50" />{copyError && <p role="alert" className="mt-3 text-sm text-amber-300">Não foi possível copiar automaticamente; selecione o texto e copie manualmente.</p>}<form onSubmit={sendPrompt} className="mt-4 flex flex-wrap items-center gap-3"><button type="submit" disabled={openAiForm.processing} className="rounded-xl border border-emerald-300/30 px-4 py-2.5 text-sm font-semibold text-emerald-300 disabled:opacity-50">{openAiForm.processing ? 'Enviando...' : 'Enviar prompt preenchido à OpenAI'}</button><span className="text-xs text-slate-500">Nada é enviado sem esta confirmação.</span></form>{openAiForm.errors.openai && <p role="alert" className="mt-3 text-sm text-rose-300">{openAiForm.errors.openai}</p>}{flash?.openai?.response && <div className="mt-5 rounded-2xl border border-white/10 bg-slate-950/60 p-4"><h3 className="text-sm font-semibold text-emerald-300">Resposta da OpenAI</h3><pre className="mt-3 max-h-96 overflow-auto whitespace-pre-wrap text-sm leading-6 text-slate-200">{flash.openai.response}</pre></div>}</section></div></main></>;
}

function SummaryCard({ label, value, count }: { label: string; value: string; count: string }) { return <div className={cardClass}><p className="text-sm text-slate-400">{label}</p><p className="mt-4 text-xl font-bold text-white">{value}</p><p className="mt-1 text-xs text-slate-500">{count}</p></div>; }
function formatMoney(cents: number): string { return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100); }
