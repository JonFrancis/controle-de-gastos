import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type LifecycleProps = {
    local?: boolean;
    running?: boolean;
    owned?: boolean;
};

export default function LifecycleControls({ initialLifecycle }: { initialLifecycle?: LifecycleProps }) {
    const [lifecycle, setLifecycle] = useState(initialLifecycle);
    const [closed, setClosed] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        return router.on('success', (event) => {
            setLifecycle(event.detail.page.props.lifecycle as LifecycleProps | undefined);
            setClosed(false);
        });
    }, []);

    if (!lifecycle?.local || !lifecycle.running || !lifecycle.owned || closed) {
        return null;
    }

    const hasPendingOperation = document.querySelector('form:focus-within') !== null;

    const shutdown = async () => {
        if (processing) {
            return;
        }

        if (hasPendingOperation && !window.confirm('Existe uma operação ou formulário em andamento. Encerrar mesmo assim?')) {
            return;
        }

        setProcessing(true);
        setError(null);

        try {
            const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
            const response = await fetch('/application/shutdown', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                },
                body: JSON.stringify({
                    has_pending_operation: hasPendingOperation,
                    confirmed: hasPendingOperation,
                }),
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message ?? 'Não foi possível encerrar a aplicação.');
            }

            setClosed(true);
        } catch (shutdownError) {
            setError(shutdownError instanceof Error ? shutdownError.message : 'Não foi possível encerrar a aplicação.');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <div className="fixed right-4 top-4 z-50 flex max-w-sm flex-col items-end gap-2">
            <button
                type="button"
                onClick={shutdown}
                disabled={processing}
                className="rounded-xl border border-rose-300/30 bg-slate-950/95 px-4 py-2 text-sm font-semibold text-rose-200 shadow-lg backdrop-blur disabled:cursor-wait disabled:opacity-60"
            >
                {processing ? 'Encerrando...' : 'Encerrar aplicação'}
            </button>
            {error && <p role="alert" className="rounded-lg bg-rose-950/95 px-3 py-2 text-xs text-rose-200">{error}</p>}
        </div>
    );
}
