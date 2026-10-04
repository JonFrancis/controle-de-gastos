import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

type Participant = { id: number; name: string };
type Props = { selectedMonth: string; participants: Participant[] };

const inputClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-300/60';

export default function CreateReceipt({ selectedMonth, participants }: Props) {
    const form = useForm({ participant_id: '', received_at: new Date().toISOString().slice(0, 10), amount: '', note: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/receipts?month=${selectedMonth}`, { onSuccess: () => form.reset('amount', 'note') });
    };

    return (
        <>
            <Head title="Registrar recebimento" />
            <main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10">
                <div className="mx-auto max-w-3xl">
                    <Link href={`/balances?month=${selectedMonth}`} className="text-sm text-emerald-300">← Voltar para saldos</Link>
                    <header className="mt-6">
                        <p className="text-sm font-medium text-emerald-300">Saldos e recebimentos</p>
                        <h1 className="mt-1 text-3xl font-bold tracking-tight">Registrar recebimento</h1>
                        <p className="mt-2 text-sm text-slate-400">O sistema aplica automaticamente o valor aos saldos mais antigos da pessoa selecionada.</p>
                    </header>
                    <form onSubmit={submit} className="mt-8 rounded-3xl border border-white/10 bg-slate-900/70 p-6">
                        <div className="flex flex-col gap-4">
                            <Field label="Pessoa">
                                <select required value={form.data.participant_id} onChange={(event) => form.setData('participant_id', event.target.value)} className={inputClass}>
                                    <option value="">Selecione</option>
                                    {participants.map((participant) => <option key={participant.id} value={participant.id}>{participant.name}</option>)}
                                </select>
                                <Error text={form.errors.participant_id} />
                            </Field>
                            <Field label="Data">
                                <input required type="date" value={form.data.received_at} onChange={(event) => form.setData('received_at', event.target.value)} className={inputClass} />
                                <Error text={form.errors.received_at} />
                            </Field>
                            <Field label="Valor">
                                <input required inputMode="decimal" placeholder="0,00" value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} className={inputClass} />
                                <Error text={form.errors.amount} />
                            </Field>
                            <Field label="Observação">
                                <input maxLength={255} placeholder="Ex.: Pix" value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} className={inputClass} />
                                <Error text={form.errors.note} />
                            </Field>
                        </div>
                        <div className="mt-6 flex justify-end gap-3">
                            <Link href={`/balances?month=${selectedMonth}`} className="rounded-xl px-4 py-2.5 text-sm text-slate-400">Cancelar</Link>
                            <button disabled={form.processing} className="rounded-xl bg-emerald-400 px-5 py-2.5 text-sm font-bold text-slate-950 disabled:opacity-50">Salvar recebimento</button>
                        </div>
                    </form>
                </div>
            </main>
        </>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return <label className="block"><span className="mb-2 block text-sm font-medium text-slate-300">{label}</span>{children}</label>;
}

function Error({ text }: { text?: string }) {
    return text ? <p className="mt-1 text-xs text-rose-300">{text}</p> : null;
}
