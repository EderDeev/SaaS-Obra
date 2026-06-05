import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Calculator, ClipboardList, Layers3, Package } from 'lucide-react';

const navItems = [
    {
        key: 'orcamentos',
        label: 'Listar Orçamentos',
        routeName: 'tenant.orcamentos.index',
        icon: ClipboardList,
    },
    {
        key: 'composicoes',
        label: 'Composições',
        routeName: 'tenant.orcamentos.composicoes.index',
        icon: Layers3,
    },
    {
        key: 'insumos',
        label: 'Insumos',
        routeName: 'tenant.orcamentos.insumos.index',
        icon: Package,
    },
];

export default function OrcamentoShell({ tenant, active, title, subtitle, children, showNav = true }) {
    return (
        <AuthenticatedLayout>
            <Head title={title} />

            <section className="sig-content fade-in">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 flex-1">
                        <div className="eyebrow flex items-center gap-2">
                            <Calculator size={14} />
                            Orçamentos
                        </div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">{title}</h1>
                        <p className="mt-1 max-w-3xl text-sm leading-6 text-[var(--ink-500)]">{subtitle}</p>
                    </div>
                </header>

                {showNav && (
                    <nav className="mt-6 flex flex-wrap gap-2">
                        {navItems.map((item) => {
                            const Icon = item.icon;

                            return (
                                <Link
                                    key={item.key}
                                    href={route(item.routeName, tenant.slug)}
                                    className={`sig-btn ${active === item.key ? 'sig-btn-primary' : 'sig-btn-secondary'}`}
                                >
                                    <Icon size={15} />
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>
                )}

                <div className="mt-6">{children}</div>
            </section>
        </AuthenticatedLayout>
    );
}
