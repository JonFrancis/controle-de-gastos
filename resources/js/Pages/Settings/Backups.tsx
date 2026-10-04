import { Head, Link, useForm } from '@inertiajs/react';
import type { ChangeEvent, FormEvent } from 'react';

type Settings = { automaticBackupEnabled: boolean; backupPath: string | null };
type Backup = { filename: string; path: string; size: number; createdAt: string };
type Props = { settings: Settings; backups: Backup[]; flash?: { success?: string } };

const inputClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-300';

export default function Backups({ settings, backups, flash }: Props) {
    const settingsForm = useForm({ automatic_backup_enabled: settings.automaticBackupEnabled, backup_path: settings.backupPath ?? '' });
    const restoreForm = useForm<{ backup: File | null; confirmation: boolean }>({ backup: null, confirmation: false });

    function saveSettings(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        settingsForm.patch('/settings/backups');
    }

    function restore(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        restoreForm.post('/settings/backups/restore', { forceFormData: true });
    }

    function selectBackup(event: ChangeEvent<HTMLInputElement>) {
        restoreForm.setData('backup', event.target.files?.[0] ?? null);
    }

    return <>
        <Head title="Backups" />
        <main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10">
            <div className="mx-auto max-w-5xl">
                <div className="flex flex-wrap items-center justify-between gap-3"><Link href="/settings/catalogs" className="text-sm text-emerald-300">← Cadastros</Link><Link href="/history" className="text-sm font-semibold text-emerald-300">Ver histórico →</Link></div>
                <header className="mt-6"><p className="text-sm font-medium text-emerald-300">Configurações</p><h1 className="mt-1 text-3xl font-bold tracking-tight">Backup e restauração</h1><p className="mt-2 text-sm text-slate-400">Proteja o banco local e recupere uma cópia validada sem perder o estado anterior.</p></header>
                {flash?.success && <p role="status" className="mt-5 rounded-xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-200">{flash.success}</p>}
                {(settingsForm.errors.backup_path || restoreForm.errors.backup || restoreForm.errors.confirmation) && <p role="alert" className="mt-5 rounded-xl border border-rose-300/20 bg-rose-300/10 px-4 py-3 text-sm text-rose-200">{settingsForm.errors.backup_path ?? restoreForm.errors.backup ?? restoreForm.errors.confirmation}</p>}
                <div className="mt-8 grid gap-5 lg:grid-cols-2">
                    <section className="rounded-3xl border border-white/10 bg-slate-900/70 p-6"><h2 className="font-semibold">Backup automático</h2><p className="mt-1 text-sm text-slate-400">Quando ativo, uma cópia é criada após cada alteração auditável.</p><form onSubmit={saveSettings} className="mt-5 space-y-4"><label className="flex items-center gap-3 text-sm text-slate-200"><input type="checkbox" checked={settingsForm.data.automatic_backup_enabled} onChange={(event) => settingsForm.setData('automatic_backup_enabled', event.target.checked)} />Ativar backup automático</label><div><label htmlFor="backup-path" className="text-xs font-semibold uppercase tracking-wide text-slate-400">Local dos arquivos</label><input id="backup-path" value={settingsForm.data.backup_path} onChange={(event) => settingsForm.setData('backup_path', event.target.value)} placeholder="Padrão: storage/app/private/backups" className={`${inputClass} mt-2`} /><p className="mt-2 text-xs text-slate-500">Use um caminho local gravável. Deixe vazio para usar o local padrão.</p></div><button disabled={settingsForm.processing} className="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-bold text-slate-950">Salvar configurações</button></form></section>
                    <section className="rounded-3xl border border-white/10 bg-slate-900/70 p-6"><h2 className="font-semibold">Backup manual</h2><p className="mt-1 text-sm text-slate-400">Gere uma cópia completa e identificável do banco local.</p><form action="/settings/backups" method="post" className="mt-5"><button className="rounded-xl border border-emerald-300/30 px-4 py-2.5 text-sm font-semibold text-emerald-300">Criar backup agora</button></form><p className="mt-4 text-xs text-slate-500">Os arquivos são JSON portáveis e incluem os dados das tabelas atuais.</p></section>
                    <section className="rounded-3xl border border-white/10 bg-slate-900/70 p-6 lg:col-span-2"><h2 className="font-semibold">Restaurar backup</h2><p className="mt-1 text-sm text-slate-400">A restauração valida o arquivo e salva automaticamente uma cópia do estado atual antes de substituir os dados.</p><form onSubmit={restore} className="mt-5 space-y-4"><input type="file" accept=".json,application/json" onChange={selectBackup} className="block w-full text-sm text-slate-400 file:mr-4 file:rounded-xl file:border-0 file:bg-white/10 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white" /><label className="flex items-start gap-3 text-sm text-slate-300"><input type="checkbox" checked={restoreForm.data.confirmation} onChange={(event) => restoreForm.setData('confirmation', event.target.checked)} className="mt-1" />Confirmo que desejo substituir os dados atuais pelo backup selecionado.</label><button disabled={restoreForm.processing} className="rounded-xl bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950">Restaurar cópia</button></form></section>
                </div>
                <section className="mt-5 rounded-3xl border border-white/10 bg-slate-900/70 p-6"><h2 className="font-semibold">Cópias disponíveis</h2><div className="mt-4 space-y-2">{backups.length === 0 && <p className="rounded-xl border border-dashed border-white/10 px-4 py-5 text-center text-sm text-slate-500">Nenhum backup criado ainda.</p>}{backups.map((backup) => <div key={backup.path} className="flex flex-col gap-1 rounded-xl border border-white/5 bg-white/[0.03] p-3 sm:flex-row sm:items-center sm:justify-between"><span className="text-sm text-slate-200">{backup.filename}</span><span className="text-xs text-slate-500">{formatBytes(backup.size)} · {formatDate(backup.createdAt)}</span></div>)}</div></section>
            </div>
        </main>
    </>;
}

function formatBytes(bytes: number): string { return `${Math.max(1, Math.round(bytes / 1024))} KB`; }
function formatDate(value: string): string { return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)); }
