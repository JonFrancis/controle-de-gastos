type Notice = {
    message: string;
    created_at: string;
};

export default function DesktopMigrationNotice({ notice }: { notice?: Notice | null }) {
    if (!notice) {
        return null;
    }

    return (
        <div role="alert" className="fixed bottom-4 left-4 z-50 max-w-lg rounded-2xl border border-amber-300/30 bg-slate-950/95 px-4 py-3 text-sm text-amber-100 shadow-lg backdrop-blur">
            <p className="font-semibold text-amber-200">Os dados existentes não foram migrados.</p>
            <p className="mt-1">{notice.message}</p>
            <p className="mt-2 text-xs text-slate-400">Não apague o banco original. Corrija o problema e use o iniciador do navegador (`php artisan app:launch`) para manutenção e recuperação.</p>
        </div>
    );
}
