import { Form, Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

type CatalogItem = { id: number; name: string; active: boolean };
type PaymentMethod = CatalogItem & { type: string; closing_day: number | null };
type Props = { participants: CatalogItem[]; categories: CatalogItem[]; paymentMethods: PaymentMethod[]; paymentMethodTypes: string[] };
type CatalogFormData = { name: string; active: boolean };

const typeLabels: Record<string, string> = { credit: 'Crédito', debit: 'Débito', pix: 'Pix', cash: 'Dinheiro', other: 'Outro' };
const inputClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-300';
const selectClass = 'rounded-xl border border-white/10 bg-slate-800 px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-300 [&>option]:bg-slate-800 [&>option]:text-white';

function ErrorMessage({ message }: { message?: string }) {
    return message ? <p className="mt-1 text-xs text-rose-300">{message}</p> : null;
}

function Status({ active }: { active: boolean }) {
    return <span className={`rounded-full px-2 py-1 text-xs ${active ? 'bg-emerald-400/10 text-emerald-300' : 'bg-slate-700 text-slate-400'}`}>{active ? 'Ativo' : 'Inativo'}</span>;
}

function SimpleCatalog({ title, description, endpoint, items }: { title: string; description: string; endpoint: string; items: CatalogItem[] }) {
    const [editingId, setEditingId] = useState<number | null>(null);
    return <section className="rounded-3xl border border-white/10 bg-slate-900/70 p-6">
        <div><h2 className="font-semibold text-white">{title}</h2><p className="mt-1 text-sm text-slate-400">{description}</p></div>
        <Form action={endpoint} method="post" className="mt-5" resetOnSuccess>
            {({ errors }) => <><div className="flex gap-3"><input name="name" required maxLength={100} placeholder={`Nome de ${title.toLowerCase()}`} className={`${inputClass} min-w-0 flex-1`} /><button className="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-bold text-slate-950">Adicionar</button></div><ErrorMessage message={errors.name} /></>}
        </Form>
        <div className="mt-5 space-y-2">
            {items.length === 0 && <p className="rounded-xl border border-dashed border-white/10 px-4 py-5 text-center text-sm text-slate-500">Nenhum cadastro ainda.</p>}
            {items.map((item) => editingId === item.id ? <EditCatalogItem key={item.id} item={item} endpoint={endpoint} onCancel={() => setEditingId(null)} /> : <div key={item.id} className="flex items-center justify-between gap-3 rounded-xl border border-white/5 bg-white/[0.03] p-3"><span className={item.active ? 'text-sm text-slate-200' : 'text-sm text-slate-500 line-through'}>{item.name}</span><div className="flex items-center gap-2"><Status active={item.active} /><button onClick={() => setEditingId(item.id)} className="text-xs font-semibold text-slate-300">Editar</button><Form action={`${endpoint}/${item.id}`} method="delete" onBefore={() => window.confirm(`Excluir ${item.name} permanentemente?`)}><button className="text-xs font-semibold text-rose-300">Excluir</button></Form></div></div>)}
        </div>
    </section>;
}

function EditCatalogItem({ item, endpoint, onCancel }: { item: CatalogItem; endpoint: string; onCancel: () => void }) {
    const form = useForm<CatalogFormData>({ name: item.name, active: item.active });
    return <div className="rounded-xl border border-emerald-300/20 bg-emerald-300/5 p-3"><form onSubmit={(event) => { event.preventDefault(); form.patch(`${endpoint}/${item.id}`, { onSuccess: onCancel }); }} className="space-y-2"><input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} className={inputClass} /><label className="flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" checked={form.data.active} onChange={(event) => form.setData('active', event.target.checked)} /> Ativo</label><ErrorMessage message={form.errors.name} /><div className="flex justify-end gap-2"><button type="button" onClick={onCancel} className="rounded-lg px-3 py-2 text-xs text-slate-400">Cancelar</button><button disabled={form.processing} className="rounded-lg bg-emerald-400 px-3 py-2 text-xs font-bold text-slate-950">Salvar</button></div></form></div>;
}

function PaymentMethodEditor({ item, endpoint, types, onCancel }: { item: PaymentMethod; endpoint: string; types: string[]; onCancel: () => void }) {
    const form = useForm({ name: item.name, type: item.type, closing_day: item.closing_day?.toString() ?? '', active: item.active });
    const isCredit = form.data.type === 'credit';
    return <div className="rounded-xl border border-emerald-300/20 bg-emerald-300/5 p-3"><form onSubmit={(event) => { event.preventDefault(); form.patch(`${endpoint}/${item.id}`, { onSuccess: onCancel }); }} className="space-y-2"><input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} className={inputClass} /><div className="flex gap-2"><select value={form.data.type} onChange={(event) => form.setData('type', event.target.value)} className={`${selectClass} min-w-[9rem] flex-1`}>{types.map((type) => <option key={type} value={type}>{typeLabels[type] ?? type}</option>)}</select>{isCredit && <input value={form.data.closing_day} onChange={(event) => form.setData('closing_day', event.target.value)} type="number" min="1" max="31" placeholder="Fechamento" className={`${inputClass} w-28 shrink-0`} />}</div><label className="flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" checked={form.data.active} onChange={(event) => form.setData('active', event.target.checked)} /> Ativo</label><ErrorMessage message={form.errors.name ?? form.errors.closing_day} /><div className="flex justify-end gap-2"><button type="button" onClick={onCancel} className="rounded-lg px-3 py-2 text-xs text-slate-400">Cancelar</button><button disabled={form.processing} className="rounded-lg bg-emerald-400 px-3 py-2 text-xs font-bold text-slate-950">Salvar</button></div></form></div>;
}

export default function Catalogs({ participants, categories, paymentMethods, paymentMethodTypes }: Props) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [createType, setCreateType] = useState(paymentMethodTypes[0] ?? '');
    const isCredit = createType === 'credit';
    return <><Head title="Configurações" /><main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10"><div className="mx-auto max-w-7xl"><Link href="/" className="text-sm text-emerald-300">← Voltar para visão geral</Link><header className="mt-6"><p className="text-sm font-medium text-emerald-300">Configurações</p><h1 className="mt-1 text-3xl font-bold tracking-tight">Cadastros básicos</h1><p className="mt-2 text-sm text-slate-400">Organize pessoas, categorias e formas de pagamento.</p></header><div className="mt-8 grid gap-5 xl:grid-cols-3"><SimpleCatalog title="Pessoas" description="Quem participa das compras." endpoint="/participants" items={participants} /><SimpleCatalog title="Categorias" description="Como seus gastos são classificados." endpoint="/categories" items={categories} /><section className="rounded-3xl border border-white/10 bg-slate-900/70 p-6"><h2 className="font-semibold">Formas de pagamento</h2><p className="mt-1 text-sm text-slate-400">Cartões, Pix, débito e dinheiro.</p><Form action="/payment-methods" method="post" className="mt-5 space-y-3" resetOnSuccess>{({ errors }) => <><input name="name" required maxLength={100} placeholder="Nome da forma" className={inputClass} /><div className="flex gap-3"><select name="type" value={createType} onChange={(event) => setCreateType(event.target.value)} className={`${selectClass} min-w-[9rem] flex-1`}><option value="">Selecione o tipo</option>{paymentMethodTypes.map((type) => <option key={type} value={type}>{typeLabels[type] ?? type}</option>)}</select>{isCredit && <input name="closing_day" type="number" min="1" max="31" placeholder="Fechamento" className={`${inputClass} w-28 shrink-0`} />}</div><p className="text-xs text-slate-500">O dia de fechamento aparece e é usado apenas para cartão de crédito.</p><ErrorMessage message={errors.name ?? errors.closing_day} /><button className="w-full rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-bold text-slate-950">Adicionar forma</button></>}</Form><div className="mt-5 space-y-2">{paymentMethods.length === 0 && <p className="rounded-xl border border-dashed border-white/10 px-4 py-5 text-center text-sm text-slate-500">Nenhum cadastro ainda.</p>}{paymentMethods.map((item) => editingId === item.id ? <PaymentMethodEditor key={item.id} item={item} endpoint="/payment-methods" types={paymentMethodTypes} onCancel={() => setEditingId(null)} /> : <div key={item.id} className="flex items-center justify-between gap-3 rounded-xl border border-white/5 bg-white/[0.03] p-3"><div><p className={item.active ? 'text-sm text-slate-200' : 'text-sm text-slate-500 line-through'}>{item.name}</p><p className="text-xs text-slate-500">{typeLabels[item.type] ?? item.type}{item.closing_day ? ` · fecha dia ${item.closing_day}` : ''}</p></div><div className="flex items-center gap-2"><Status active={item.active} /><button onClick={() => setEditingId(item.id)} className="text-xs font-semibold text-slate-300">Editar</button><Form action={`/payment-methods/${item.id}`} method="delete" onBefore={() => window.confirm(`Excluir ${item.name} permanentemente?`)}><button className="text-xs font-semibold text-rose-300">Excluir</button></Form></div></div>)}</div></section></div></div></main></>;
}
