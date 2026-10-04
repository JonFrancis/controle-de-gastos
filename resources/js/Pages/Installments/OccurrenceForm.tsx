import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Props = { occurrence: { id: number; installment_number: number; purchased_at: string; amount_cents: number; installment: { description: string; installment_count: number } } };
const inputClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-300';

export default function OccurrenceForm({ occurrence }: Props) {
    const form = useForm({ amount: (occurrence.amount_cents / 100).toFixed(2) });
    const submit = (event: FormEvent) => { event.preventDefault(); form.patch(`/installment-occurrences/${occurrence.id}`); };
    return <><Head title="Ajustar parcela" /><main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10"><div className="mx-auto max-w-xl"><Link href="/installments" className="text-sm text-emerald-300">← Voltar para parcelamentos</Link><header className="mt-6"><p className="text-sm font-medium text-emerald-300">Parcela {occurrence.installment_number}/{occurrence.installment.installment_count}</p><h1 className="mt-1 text-3xl font-bold tracking-tight">Ajustar parcela</h1><p className="mt-2 text-sm text-slate-400">{occurrence.installment.description} · {formatDate(occurrence.purchased_at)}</p></header><form onSubmit={submit} className="mt-8 rounded-3xl border border-white/10 bg-slate-900/70 p-6"><label><span className="mb-2 block text-sm font-medium text-slate-300">Novo valor da parcela</span><input required inputMode="decimal" value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} className={inputClass} />{form.errors.amount && <p className="mt-1 text-xs text-rose-300">{form.errors.amount}</p>}</label><p className="mt-4 text-xs text-slate-500">Este ajuste altera somente esta ocorrência e não recalcula as demais parcelas.</p><div className="mt-6 flex justify-end gap-3"><Link href="/installments" className="rounded-xl px-4 py-2.5 text-sm text-slate-400">Cancelar</Link><button disabled={form.processing} className="rounded-xl bg-emerald-400 px-5 py-2.5 text-sm font-bold text-slate-950">Salvar ajuste</button></div></form></div></main></>;
}

function formatDate(value: string): string { return new Intl.DateTimeFormat('pt-BR').format(new Date(`${value.slice(0, 10)}T12:00:00`)); }
