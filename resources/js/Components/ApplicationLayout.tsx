import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

const navigationItems = [
    { label: 'Visão Geral', href: '/', icon: '⌂' },
    { label: 'Compras', href: '/purchases', icon: 'R$' },
    { label: 'Parcelamentos', href: '/installments', icon: '∑' },
    { label: 'Recorrentes', href: '/recurrences', icon: '↻' },
    { label: 'Saldos', href: '/balances', icon: '±' },
    { label: 'Análise', href: '/analysis', icon: '◎' },
    { label: 'Exportações', href: '/exports', icon: '↗' },
    { label: 'Configurações', href: '/settings/catalogs', icon: '⚙' },
];

export default function ApplicationLayout({ children }: { children: ReactNode }) {
    const { url } = usePage();
    const pathname = url.split('?')[0];

    return <div className="app-content dashboard-dark min-h-screen bg-[#f6f1e8] text-[#302b24]">
        <aside className="app-sidebar fixed inset-y-0 left-0 z-30 w-16 border-r border-[#594c43]/15 bg-[#f1ebdf] px-2 py-5 shadow-[8px_0_28px_rgba(65,48,34,0.04)] lg:w-64 lg:px-5 lg:py-6">
            <Link href="/" aria-label="Controle de Gastos — visão geral" className="flex items-center justify-center gap-3 px-1 lg:justify-start lg:px-2">
                <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-[#3BD936] font-black tracking-tight text-[#17351e] shadow-[0_8px_18px_rgba(59,217,54,0.2)]">CG</span>
                <span className="hidden min-w-0 lg:block"><span className="block truncate font-semibold text-[#302b24]">Controle de Gastos</span><span className="block text-xs text-[#766b5d]">seu dinheiro, mais claro</span></span>
            </Link>
            <nav className="mt-8 space-y-1.5 lg:mt-10 lg:space-y-2" aria-label="Navegação principal">
                {navigationItems.map(({ label, href, icon }) => {
                    const isActive = pathname === href || (href !== '/' && pathname.startsWith(href));

                    return <Link key={href} href={href} aria-label={label} aria-current={isActive ? 'page' : undefined} title={label} className={`flex items-center justify-center gap-3 rounded-2xl px-2 py-3 text-sm transition lg:justify-start lg:px-3 ${isActive ? 'bg-[#e0edd9] font-semibold text-[#24452b] shadow-[inset_3px_0_0_#3BD936]' : 'text-[#776d60] hover:bg-white/70 hover:text-[#302b24]'}`}>
                        <span aria-hidden="true" className={`grid h-8 w-8 shrink-0 place-items-center rounded-xl text-base ${isActive ? 'bg-white/75 text-[#315b37]' : 'bg-[#e9e1d4] text-[#594c43]'}`}>{icon}</span>
                        <span className="hidden lg:inline">{label}</span>
                    </Link>;
                })}
            </nav>
        </aside>
        <div className="min-h-screen pl-16 lg:pl-64">
            <div className="mx-auto min-h-screen max-w-[1480px] px-4 py-6 sm:px-8 lg:px-10 lg:py-8">{children}</div>
        </div>
    </div>;
}
