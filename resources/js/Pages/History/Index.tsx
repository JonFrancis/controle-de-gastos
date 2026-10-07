import { Head, Link } from '@inertiajs/react';

type Log = { id: number; action: string; auditableType: string | null; auditableId: number | null; oldValues: Record<string, unknown> | null; newValues: Record<string, unknown> | null; metadata: Record<string, unknown> | null; createdAt: string | null };
type Props = { logs: Log[]; actions: string[]; selectedAction: string | null };
const labels: Record<string, string> = { create: 'Criação', update: 'Edição', archive: 'Arquivamento', restore: 'Restauração', import: 'Importação' };

export default function History({ logs, actions, selectedAction }: Props) {
    return <>
        <Head title="Histórico" />
        <main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10">
            <div className="mx-auto max-w-5xl">
                <div className="flex flex-wrap items-center justify-between gap-3"><Link href="/" className="text-sm text-emerald-300">← Visão geral</Link><Link href="/settings/backups" className="text-sm font-semibold text-emerald-300">Backups →</Link></div>
                <header className="mt-6"><p className="text-sm font-medium text-emerald-300">Integridade</p><h1 className="mt-1 text-3xl font-bold tracking-tight">Histórico de alterações</h1><p className="mt-2 text-sm text-slate-400">Consulte as mudanças relevantes sem apagar o estado anterior.</p></header>
                <form method="get" action="/history" className="mt-8 flex flex-wrap items-end gap-3 rounded-3xl border border-white/10 bg-slate-900/70 p-5"><div><label htmlFor="action" className="block text-xs font-semibold uppercase tracking-wide text-slate-400">Ação</label><select id="action" name="action" defaultValue={selectedAction ?? ''} className="mt-2 rounded-xl border border-white/10 bg-slate-800 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-300"><option value="">Todas</option>{actions.map((action) => <option key={action} value={action}>{labels[action] ?? action}</option>)}</select></div><button className="rounded-xl border border-white/10 px-4 py-2.5 text-sm font-semibold text-slate-200">Filtrar histórico</button></form>
                <section className="mt-5 overflow-hidden rounded-3xl border border-white/10 bg-slate-900/70"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="border-b border-white/10 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-4">Data</th><th className="px-5 py-4">Ação</th><th className="px-5 py-4">Registro</th><th className="px-5 py-4">Detalhes</th></tr></thead><tbody className="divide-y divide-white/5">{logs.length === 0 && <tr><td colSpan={4} className="px-5 py-10 text-center text-slate-500">Nenhuma alteração encontrada.</td></tr>}{logs.map((log) => <tr key={log.id}><td className="whitespace-nowrap px-5 py-4 text-xs text-slate-500">{log.createdAt ? formatDate(log.createdAt) : '—'}</td><td className="px-5 py-4 font-semibold text-emerald-300">{labels[log.action] ?? log.action}</td><td className="px-5 py-4 text-slate-300">{log.auditableType ? `${log.auditableType} #${log.auditableId}` : 'Sistema'}</td><td className="px-5 py-4 text-xs text-slate-400">{detail(log)}</td></tr>)}</tbody></table></div></section>
            </div>
        </main>
    </>;
}

function detail(log: Log): string { if (log.action === 'import') return `${String(log.metadata?.source ?? 'arquivo')} · ${String(log.metadata?.records ?? 0)} registro(s)`; return log.action === 'update' ? 'Valores anteriores preservados.' : 'Alteração registrada.'; }
function formatDate(value: string): string { return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)); }
