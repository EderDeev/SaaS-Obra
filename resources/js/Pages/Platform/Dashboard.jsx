import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Building2, ClipboardList, Users, Wallet } from 'lucide-react';

const statusLabels = {
    active: 'Ativo',
    trial: 'Em teste',
    suspended: 'Suspenso',
};

const statusPills = {
    active: 'sig-pill-green',
    trial: 'sig-pill-blue',
    suspended: 'sig-pill-red',
};

export default function PlatformDashboard({ stats, recentTenants }) {
    return (
        <AuthenticatedLayout>
            <Head title="Super Admin" />

            <section className="sig-content fade-in">
                <div className="flex flex-wrap items-end gap-6">
                    <div className="min-w-0 flex-1">
                        <div className="eyebrow">Platform · SaaS</div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">Super Admin</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">Visão global de tenants, contratos e usuários.</p>
                    </div>
                    <Link href={route('platform.tenants.index')} className="sig-btn sig-btn-primary">
                        Gerenciar tenants
                    </Link>
                </div>

                <div className="mt-6 grid gap-3 lg:grid-cols-4 sm:grid-cols-2">
                    <Metric icon={Building2} label="Tenants" value={stats.tenants} sub={`${stats.activeTenants} ativos`} />
                    <Metric icon={Wallet} label="MRR" value="Demo" sub="Billing entra na próxima fase" />
                    <Metric icon={ClipboardList} label="Contratos" value={stats.contracts} sub="Contratos cadastrados" />
                    <Metric icon={Users} label="Usuários" value={stats.users} sub="Usuários globais" />
                </div>

                <section className="sig-card mt-7 overflow-hidden">
                    <header className="border-b border-[var(--border)] px-5 py-4">
                        <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Tenants recentes</h2>
                    </header>
                    <table className="sig-table">
                        <thead>
                            <tr><th>Tenant</th><th>Plano</th><th>Status</th><th>Uso</th><th></th></tr>
                        </thead>
                        <tbody>
                            {recentTenants.map((tenant) => (
                                <tr key={tenant.id}>
                                    <td>
                                        <div className="font-semibold">{tenant.name}</div>
                                        <div className="mono text-xs text-[var(--ink-500)]">{tenant.slug}</div>
                                    </td>
                                    <td>{tenant.plan}</td>
                                    <td>
                                        <span className={`sig-pill ${statusPills[tenant.status] || 'sig-pill-muted'}`}>
                                            <span className="sig-pill-dot" />
                                            {statusLabels[tenant.status] || tenant.status}
                                        </span>
                                    </td>
                                    <td>{tenant.users_count} usuários · {tenant.contracts_count} contratos</td>
                                    <td className="text-right">
                                        <Link href={route('tenant.dashboard', tenant.slug)} className="sig-btn sig-btn-secondary sig-btn-sm">Abrir</Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function Metric({ icon: Icon, label, value, sub }) {
    return (
        <div className="sig-card p-[18px]">
            <div className="flex items-center gap-2 text-[var(--ink-500)]"><Icon size={14} /><span className="eyebrow">{label}</span></div>
            <div className="mono mt-2 text-[28px] font-semibold">{value}</div>
            <p className="text-[12.5px] text-[var(--ink-500)]">{sub}</p>
        </div>
    );
}
