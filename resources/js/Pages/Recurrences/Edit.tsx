import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Props = { recurrence: { id: number; description: string; startDate: string; endDate: string | null; active: boolean } };
const inputClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-300';

export default function RecurrencesEdit({ recurrence }: Props) {
    const form = useForm({ end_date: recurrence.endDate ?? '', active: recurrence.active });
    const submit = (event: FormEvent) => { event.preventDefault(); form.patch(`/recurrences/${recurrence.id}`); };

    return <><Head title="Editar recorrência" /><main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10"><div className="mx-auto max-w-xl"><Link href="/recurrences" className="text-sm text-emerald-300">← Voltar para recorrências</Link><header className="mt-6"><p className="text-sm font-medium text-emerald-300">Configuração da regra</p><h1 className="mt-1 text-3xl font-bold tracking-tight">{recurrence.description}</h1><p className="mt-2 text-sm text-slate-400">Início em {formatDate(recurrence.startDate)}.</p></header><form onSubmit={submit} className="mt-8 rounded-3xl border border-white/10 bg-slate-900/70 p-6"><label><span className="mb-2 block text-sm font-medium text-slate-300">Encerrar em (opcional)</span><input type="date" value={form.data.end_date} onChange={(event) => form.setData('end_date', event.target.value)} className={inputClass} />{form.errors.end_date && <p className="mt-1 text-xs text-rose-300">{form.errors.end_date}</p>}</label><label className="mt-5 flex items-center gap-3 text-sm text-slate-300"><input type="checkbox" checked={form.data.active} onChange={(event) => form.setData('active', event.target.checked)} className="h-4 w-4 accent-emerald-400" /> Regra ativa</label><p className="mt-4 text-xs text-slate-500">Ocorrências passadas são preservadas. Desativar oculta as futuras até a regra ser ativada novamente.</p><div className="mt-6 flex justify-end gap-3"><Link href="/recurrences" className="rounded-xl px-4 py-2.5 text-sm text-slate-400">Cancelar</Link><button disabled={form.processing} className="rounded-xl bg-emerald-400 px-5 py-2.5 text-sm font-bold text-slate-950">Salvar configuração</button></div></form></div></main></>;
}

function formatDate(value: string): string { return new Intl.DateTimeFormat('pt-BR').format(new Date(`${value}T12:00:00`)); }
