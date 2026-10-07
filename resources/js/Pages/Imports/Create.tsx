import { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';

export default function CreateImport() {
    const form = useForm<{ file: File | null }>({ file: null });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/imports', { forceFormData: true });
    };

    return <><Head title="Importar planilha" /><main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10"><div className="mx-auto max-w-3xl"><Link href="/" className="text-sm text-emerald-300">← Voltar para visão geral</Link><header className="mt-8"><p className="text-sm font-medium text-emerald-300">Migração</p><h1 className="mt-1 text-3xl font-bold tracking-tight">Importar planilha</h1><p className="mt-2 text-sm text-slate-400">O arquivo original será preservado. Você verá uma prévia e revisará as linhas antes de salvar qualquer compra.</p></header><form onSubmit={submit} className="mt-8 rounded-3xl border border-white/10 bg-slate-900/70 p-6"><label className="block"><span className="mb-2 block text-sm font-medium text-slate-300">Arquivo CSV, TSV ou XLSX</span><input required type="file" accept=".csv,.tsv,.txt,.xlsx" onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)} className="block w-full rounded-xl border border-white/10 bg-white/5 px-3 py-3 text-sm text-slate-300 file:mr-4 file:rounded-lg file:border-0 file:bg-emerald-300 file:px-3 file:py-2 file:font-semibold file:text-slate-950" />{form.errors.file && <p className="mt-2 text-xs text-rose-300">{form.errors.file}</p>}</label><div className="mt-6 flex items-center justify-end gap-3"><Link href="/" className="rounded-xl px-4 py-2.5 text-sm text-slate-400">Cancelar</Link><button disabled={form.processing} className="rounded-xl bg-emerald-400 px-5 py-2.5 text-sm font-bold text-slate-950">{form.processing ? 'Lendo...' : 'Ver prévia'}</button></div></form></div></main></>;
}
