import { useMemo } from 'react';
import { removeAllocationRow } from './installmentAllocationRows.mjs';

export type AllocationOption = { id: number; name: string; is_default?: boolean };
export type InstallmentAllocationRow = { participant_id: string; category_id: string; amount: string; percentage: string };

type Props = {
    participants: AllocationOption[];
    categories: AllocationOption[];
    mode: string;
    rows: InstallmentAllocationRow[];
    onModeChange: (mode: string) => void;
    onRowsChange: (rows: InstallmentAllocationRow[]) => void;
    error?: string;
};

const inputClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-300';
const selectClass = 'w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-300 [&>option]:bg-slate-800 [&>option]:text-white';

export default function InstallmentAllocationFields({ participants, categories, mode, rows, onModeChange, onRowsChange, error }: Props) {
    const selfId = participants.find((participant) => participant.is_default)?.id.toString() ?? '';
    const availableParticipantId = useMemo(() => participants.find((participant) => !rows.some((row) => row.participant_id === participant.id.toString()))?.id.toString() ?? selfId, [participants, rows, selfId]);
    const updateRow = (index: number, key: keyof InstallmentAllocationRow, value: string) => onRowsChange(rows.map((row, rowIndex) => rowIndex === index ? { ...row, [key]: value } : row));

    return <section className="rounded-2xl border border-white/10 bg-white/[0.03] p-4 sm:col-span-2">
        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
            <div>
                <h2 className="text-sm font-semibold text-white">Rateio do parcelamento</h2>
                <p className="mt-1 text-xs text-slate-400">Os valores representam o total da compra e serão materializados em cada Ocorrência.</p>
            </div>
            <select aria-label="Modo de rateio" value={mode} onChange={(event) => onModeChange(event.target.value)} className={selectClass}>
                <option value="equal">Partes iguais</option>
                <option value="amount">Valores definidos</option>
                <option value="percentage">Percentuais</option>
            </select>
        </div>
        <div className="mt-4 space-y-3">
            {rows.map((row, index) => <div key={index} className="grid gap-3 rounded-xl border border-white/5 bg-white/[0.03] p-3 sm:grid-cols-[1fr_1fr_9rem_auto] sm:items-center">
                <select required aria-label={`Participante do rateio ${index + 1}`} value={row.participant_id} onChange={(event) => updateRow(index, 'participant_id', event.target.value)} className={selectClass}>
                    <option value="">Selecione o participante</option>
                    {participants.map((participant) => <option key={participant.id} value={participant.id}>{participant.name}</option>)}
                </select>
                {row.participant_id === selfId ? <select aria-label={`Categoria do rateio ${index + 1}`} value={row.category_id} onChange={(event) => updateRow(index, 'category_id', event.target.value)} className={selectClass}>
                    <option value="">Categoria do meu consumo</option>
                    {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                </select> : <div />}
                {mode === 'amount' && <input aria-label={`Valor do rateio ${index + 1}`} value={row.amount} onChange={(event) => updateRow(index, 'amount', event.target.value)} placeholder="Valor total" inputMode="decimal" className={inputClass} />}
                {mode === 'percentage' && <input aria-label={`Percentual do rateio ${index + 1}`} value={row.percentage} onChange={(event) => updateRow(index, 'percentage', event.target.value)} placeholder="% total" inputMode="decimal" className={inputClass} />}
                {rows.length > 1 && <button type="button" aria-label={`Remover participante do rateio ${index + 1}`} onClick={() => onRowsChange(removeAllocationRow(rows, index))} className="rounded-xl border border-rose-300/30 px-3 py-2.5 text-sm font-semibold text-rose-300">Remover</button>}
            </div>)}
        </div>
        {error && <p className="mt-3 text-sm text-rose-300">{error}</p>}
        <button type="button" onClick={() => onRowsChange([...rows, { participant_id: availableParticipantId, category_id: '', amount: '', percentage: '' }])} className="mt-4 rounded-xl border border-emerald-300/30 px-4 py-2.5 text-sm font-semibold text-emerald-300">＋ Adicionar participante</button>
    </section>;
}
