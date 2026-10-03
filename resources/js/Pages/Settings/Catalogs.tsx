import { Form, Head, Link } from '@inertiajs/react';

type CatalogItem = { id: number; name: string; active: boolean };
type PaymentMethod = CatalogItem & { type: string; closing_day: number | null };

type Props = {
    participants: CatalogItem[];
    categories: CatalogItem[];
    paymentMethods: PaymentMethod[];
    paymentMethodTypes: string[];
};

const typeLabels: Record<string, string> = { credit: 'Crédito', debit: 'Débito', pix: 'Pix', cash: 'Dinheiro', other: 'Outro' };

function Status({ active }: { active: boolean }) {
    return <span className={`rounded-full px-2 py-1 text-xs ${active ? 'bg-emerald-400/10 text-emerald-300' : 'bg-slate-700 text-slate-400'}`}>{active ? 'Ativo' : 'Inativo'}</span>;
}

function SimpleCatalog({ title, description, endpoint, items }: { title: string; description: string; endpoint: string; items: CatalogItem[] }) {
    return (
        <section className="rounded-3xl border border-white/10 bg-slate-900/70 p-6">
            <div><h2 className="font-semibold text-white">{title}</h2><p className="mt-1 text-sm text-slate-400">{description}</p></div>
            <Form action={endpoint} method="post" className="mt-5 flex gap-3">
                <input name="name" required maxLength={100} placeholder={`Nome de ${title.toLowerCase()}`} className="min-w-0 flex-1 rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-300" />
                <button className="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-bold text-slate-950">Adicionar</button>
            </Form>
            <div className="mt-5 space-y-2">
                {items.length === 0 && <p className="rounded-xl border border-dashed border-white/10 px-4 py-5 text-center text-sm text-slate-500">Nenhum cadastro ainda.</p>}
                {items.map((item) => <div key={item.id} className="flex items-center justify-between gap-3 rounded-xl border border-white/5 bg-white/[0.03] p-3">
                    <span className={item.active ? 'text-sm text-slate-200' : 'text-sm text-slate-500 line-through'}>{item.name}</span>
                    <div className="flex items-center gap-2"><Status active={item.active} /><Form action={`${endpoint}/${item.id}`} method="patch"><input type="hidden" name="name" value={item.name} /><input type="hidden" name="active" value={item.active ? '0' : '1'} /><button className="text-xs font-semibold text-emerald-300">{item.active ? 'Desativar' : 'Ativar'}</button></Form></div>
                </div>)}
            </div>
        </section>
    );
}

export default function Catalogs({ participants, categories, paymentMethods, paymentMethodTypes }: Props) {
    return <><Head title="Configurações" /><main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10">
        <div className="mx-auto max-w-7xl">
            <Link href="/" className="text-sm text-emerald-300">← Voltar para visão geral</Link>
            <header className="mt-6"><p className="text-sm font-medium text-emerald-300">Configurações</p><h1 className="mt-1 text-3xl font-bold tracking-tight">Cadastros básicos</h1><p className="mt-2 text-sm text-slate-400">Organize pessoas, categorias e formas de pagamento sem apagar seu histórico.</p></header>
            <div className="mt-8 grid gap-5 xl:grid-cols-3">
                <SimpleCatalog title="Pessoas" description="Quem participa das compras." endpoint="/participants" items={participants} />
                <SimpleCatalog title="Categorias" description="Como seus gastos são classificados." endpoint="/categories" items={categories} />
                <section className="rounded-3xl border border-white/10 bg-slate-900/70 p-6">
                    <h2 className="font-semibold">Formas de pagamento</h2><p className="mt-1 text-sm text-slate-400">Cartões, Pix, débito e dinheiro.</p>
                    <Form action="/payment-methods" method="post" className="mt-5 space-y-3">
                        <input name="name" required maxLength={100} placeholder="Nome da forma" className="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-300" />
                        <div className="flex gap-3"><select name="type" defaultValue={paymentMethodTypes[0]} className="min-w-0 flex-1 rounded-xl border border-white/10 bg-slate-800 px-3 py-2.5 text-sm text-white">{paymentMethodTypes.map((type) => <option key={type} value={type}>{typeLabels[type] ?? type}</option>)}</select><input name="closing_day" type="number" min="1" max="31" placeholder="Fechamento" className="w-32 rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white placeholder:text-slate-500" /></div>
                        <p className="text-xs text-slate-500">O dia de fechamento é usado apenas para cartão de crédito.</p><button className="w-full rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-bold text-slate-950">Adicionar forma</button>
                    </Form>
                    <div className="mt-5 space-y-2">{paymentMethods.length === 0 && <p className="rounded-xl border border-dashed border-white/10 px-4 py-5 text-center text-sm text-slate-500">Nenhum cadastro ainda.</p>}{paymentMethods.map((item) => <div key={item.id} className="flex items-center justify-between gap-3 rounded-xl border border-white/5 bg-white/[0.03] p-3"><div><p className={item.active ? 'text-sm text-slate-200' : 'text-sm text-slate-500 line-through'}>{item.name}</p><p className="text-xs text-slate-500">{typeLabels[item.type] ?? item.type}{item.closing_day ? ` · fecha dia ${item.closing_day}` : ''}</p></div><div className="flex items-center gap-2"><Status active={item.active} /><Form action={`/payment-methods/${item.id}`} method="patch"><input type="hidden" name="name" value={item.name} /><input type="hidden" name="type" value={item.type} /><input type="hidden" name="closing_day" value={item.closing_day ?? ''} /><input type="hidden" name="active" value={item.active ? '0' : '1'} /><button className="text-xs font-semibold text-emerald-300">{item.active ? 'Desativar' : 'Ativar'}</button></Form></div></div>)}</div>
                </section>
            </div>
        </div>
    </main></>;
}
