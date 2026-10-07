import { Head, Link } from '@inertiajs/react';

type PendingImport = { id: number; filename: string; status: string; pendingRows: number; reviewUrl: string };
type Props = { pendingReview: number; imports: PendingImport[] };

export default function Queue({ pendingReview, imports }: Props) {
    return (
        <>
            <Head title="Fila de revisão" />
            <main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10">
                <div className="mx-auto max-w-4xl">
                    <Link href="/" className="text-sm text-emerald-300">← Voltar para visão geral</Link>
                    <header className="mt-8">
                        <p className="text-sm font-medium text-emerald-300">Importações</p>
                        <h1 className="mt-1 text-3xl font-bold tracking-tight">Fila de revisão</h1>
                        <p className="mt-2 text-sm text-slate-400">{pendingReview} item(ns) aguardando revisão em {imports.length} importação(ões).</p>
                    </header>
                    <section className="mt-8 space-y-4">
                        {imports.map((importData) => (
                            <article key={importData.id} className="flex flex-col justify-between gap-4 rounded-3xl border border-white/10 bg-slate-900/70 p-5 sm:flex-row sm:items-center">
                                <div>
                                    <h2 className="font-semibold text-white">{importData.filename}</h2>
                                    <p className="mt-1 text-sm text-slate-400">{importData.pendingRows} item(ns) pendente(s) · Status: {importData.status}</p>
                                </div>
                                <Link href={importData.reviewUrl} className="rounded-xl bg-emerald-400 px-4 py-2.5 text-center text-sm font-bold text-slate-950">Revisar importação →</Link>
                            </article>
                        ))}
                        {imports.length === 0 && <p className="rounded-3xl border border-dashed border-white/10 px-5 py-12 text-center text-sm text-slate-500">Nenhuma importação aguarda revisão.</p>}
                    </section>
                </div>
            </main>
        </>
    );
}
