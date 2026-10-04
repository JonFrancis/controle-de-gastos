import { Head, Link } from '@inertiajs/react';

const options = [
    { title: 'Compra simples', description: 'Registre uma compra feita uma única vez.', href: '/purchases/create/simple', icon: '＋', accent: 'emerald' },
    { title: 'Parcelamento', description: 'Gere as parcelas futuras e acompanhe cada ocorrência.', href: '/installments/create', icon: '◫', accent: 'violet' },
    { title: 'Compra recorrente', description: 'Cadastre uma regra mensal e acompanhe suas ocorrências.', href: '/recurrences/create', icon: '↻', accent: 'amber' },
];

export default function TypeSelector() {
    return <><Head title="Nova compra" /><main className="min-h-screen bg-slate-950 px-5 py-6 text-white sm:px-8 lg:px-12 lg:py-10"><div className="mx-auto max-w-4xl"><Link href="/" className="text-sm text-emerald-300">← Voltar para visão geral</Link><header className="mt-8"><p className="text-sm font-medium text-emerald-300">Nova compra</p><h1 className="mt-1 text-3xl font-bold tracking-tight">O que você quer cadastrar?</h1><p className="mt-2 text-sm text-slate-400">Escolha o tipo de compra para abrir o cadastro correto.</p></header><div className="mt-8 grid gap-4 md:grid-cols-3">{options.map((option) => <Link key={option.title} href={option.href} className="group rounded-3xl border border-white/10 bg-slate-900/70 p-6 transition hover:-translate-y-1 hover:border-emerald-300/40 hover:bg-white/[0.05]"><span className={`grid h-12 w-12 place-items-center rounded-2xl text-2xl ${option.accent === 'emerald' ? 'bg-emerald-300/10 text-emerald-300' : option.accent === 'violet' ? 'bg-violet-300/10 text-violet-300' : 'bg-amber-300/10 text-amber-300'}`}>{option.icon}</span><h2 className="mt-6 font-semibold text-white">{option.title}</h2><p className="mt-2 text-sm leading-6 text-slate-400">{option.description}</p><span className="mt-6 inline-block text-sm font-semibold text-emerald-300">Continuar →</span></Link>)}</div></div></main></>;
}
