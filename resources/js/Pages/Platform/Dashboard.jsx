import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    Building2,
    ClipboardList,
    HardDrive,
    Layers3,
    Users,
} from 'lucide-react';

const statusLabels = {
    active: 'Ativos',
    trial: 'Em teste',
    suspended: 'Suspensos',
};

const statusColors = {
    active: 'bg-emerald-500',
    trial: 'bg-amber-400',
    suspended: 'bg-rose-500',
};

const planLabels = {
    starter: 'Starter',
    growth: 'Growth',
    enterprise: 'Enterprise',
};

const planColors = {
    starter: 'bg-sky-500',
    growth: 'bg-indigo-500',
    enterprise: 'bg-slate-700',
};

const statusPills = {
    active: 'sig-pill-green',
    trial: 'sig-pill-blue',
    suspended: 'sig-pill-red',
};

const recentStatusLabels = {
    active: 'Ativo',
    trial: 'Em teste',
    suspended: 'Suspenso',
};

const storageModules = [
    ['documentation', 'Documentação'],
    ['projects', 'Projetos'],
    ['rnc', 'RNC'],
    ['activities', 'Atividades'],
    ['contracts', 'Contratos'],
    ['measurements', 'Medição'],
    ['service_orders', 'OS'],
];

export default function PlatformDashboard({ stats, tenantStatuses, tenantPlans, storageUsage, recentTenants }) {
    return (
        <AuthenticatedLayout>
            <Head title="Visão da Plataforma" />

            <section className="sig-content fade-in">
                <header className="flex flex-col items-stretch gap-4 sm:flex-row sm:items-end">
                    <div className="min-w-0 flex-1">
                        <div className="eyebrow">Plataforma</div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">Visão da Plataforma</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Acompanhe a operação global, a adoção dos tenants e o consumo de armazenamento.
                        </p>
                    </div>
                    <Link href={route('platform.tenants.index')} className="sig-btn sig-btn-primary self-start">
                        <Building2 size={16} />
                        Gerenciar tenants
                    </Link>
                </header>

                <div className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        icon={Building2}
                        label="Tenants"
                        value={stats.tenants}
                        sub={`${stats.active_tenants} em operação`}
                        tone="text-emerald-700 bg-emerald-50"
                    />
                    <Metric
                        icon={ClipboardList}
                        label="Contratos"
                        value={stats.contracts}
                        sub="Cadastrados na plataforma"
                        tone="text-blue-700 bg-blue-50"
                    />
                    <Metric
                        icon={Users}
                        label="Usuários"
                        value={stats.users}
                        sub="Contas em todos os tenants"
                        tone="text-violet-700 bg-violet-50"
                    />
                    <Metric
                        icon={HardDrive}
                        label="Armazenamento"
                        value={formatGigabytes(stats.storage_bytes)}
                        sub="Total registrado nos módulos"
                        tone="text-amber-700 bg-amber-50"
                    />
                </div>

                <div className="mt-6 grid gap-4 xl:grid-cols-[minmax(260px,0.65fr)_minmax(0,1.35fr)]">
                    <section className="sig-card p-5">
                        <div className="flex items-start gap-3">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-700">
                                <Layers3 size={18} />
                            </span>
                            <div>
                                <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Distribuição dos tenants</h2>
                                <p className="mt-0.5 text-xs text-[var(--ink-500)]">Situação operacional e planos contratados.</p>
                            </div>
                        </div>

                        <div className="mt-5 grid gap-6 sm:grid-cols-2">
                            <Distribution title="Por situação" items={tenantStatuses} labels={statusLabels} colors={statusColors} />
                            <Distribution title="Por plano" items={tenantPlans} labels={planLabels} colors={planColors} />
                        </div>
                    </section>

                    <section className="sig-card min-w-0 overflow-hidden">
                        <header className="flex items-start gap-3 border-b border-[var(--border)] px-5 py-4">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-amber-50 text-amber-700">
                                <HardDrive size={18} />
                            </span>
                            <div>
                                <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Consumo por tenant e módulo</h2>
                                <p className="mt-0.5 text-xs text-[var(--ink-500)]">Armazenamento registrado em gigabytes.</p>
                            </div>
                        </header>

                        {storageUsage.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="sig-table min-w-[940px]">
                                    <thead>
                                        <tr>
                                            <th>Tenant</th>
                                            {storageModules.map(([key, label]) => <th key={key} className="text-right">{label}</th>)}
                                            <th className="text-right">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {storageUsage.map((tenant) => (
                                            <tr key={tenant.id}>
                                                <td>
                                                    <div className="max-w-[180px] truncate font-semibold">{tenant.name}</div>
                                                    <div className="max-w-[180px] truncate text-xs text-[var(--ink-500)]">{tenant.slug}</div>
                                                </td>
                                                {storageModules.map(([key]) => (
                                                    <td key={key} className="mono whitespace-nowrap text-right text-xs">
                                                        {formatGigabytes(tenant.modules[key])}
                                                    </td>
                                                ))}
                                                <td className="mono whitespace-nowrap text-right text-xs font-semibold text-[var(--ink-900)]">
                                                    {formatGigabytes(tenant.total_bytes)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <p className="px-5 py-8 text-center text-sm text-[var(--ink-500)]">Nenhum tenant cadastrado.</p>
                        )}
                    </section>
                </div>

                <section className="sig-card mt-4 overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Tenants adicionados recentemente</h2>
                            <p className="mt-0.5 text-xs text-[var(--ink-500)]">Últimas entradas na plataforma.</p>
                        </div>
                        <Link href={route('platform.tenants.index')} className="text-sm font-semibold text-[var(--primary)] hover:underline">
                            Ver todos
                        </Link>
                    </header>

                    {recentTenants.length > 0 ? (
                        <div className="divide-y divide-[var(--border)]">
                            {recentTenants.map((tenant) => (
                                <div key={tenant.id} className="grid gap-3 px-5 py-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center">
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-semibold text-[var(--ink-900)]">{tenant.name}</div>
                                        <div className="mt-0.5 truncate text-xs text-[var(--ink-500)]">{tenant.slug}</div>
                                    </div>
                                    <span className={`sig-pill ${statusPills[tenant.status] || 'sig-pill-muted'}`}>
                                        <span className="sig-pill-dot" />
                                        {recentStatusLabels[tenant.status] || tenant.status}
                                    </span>
                                    <time className="text-xs text-[var(--ink-500)]">{formatDate(tenant.created_at)}</time>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="px-5 py-8 text-center text-sm text-[var(--ink-500)]">Nenhum tenant cadastrado.</p>
                    )}
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function Metric({ icon: Icon, label, value, sub, tone }) {
    return (
        <div className="sig-card p-[18px]">
            <div className="flex items-center justify-between gap-3">
                <span className="eyebrow">{label}</span>
                <span className={`flex h-8 w-8 items-center justify-center rounded-md ${tone}`}>
                    <Icon size={16} />
                </span>
            </div>
            <div className="mono mt-2 text-[26px] font-semibold text-[var(--ink-900)]">{value}</div>
            <p className="mt-0.5 text-[12.5px] text-[var(--ink-500)]">{sub}</p>
        </div>
    );
}

function Distribution({ title, items, labels, colors }) {
    const total = items.reduce((sum, item) => sum + item.total, 0);

    return (
        <div>
            <h3 className="text-xs font-semibold uppercase text-[var(--ink-500)]">{title}</h3>
            <div className="mt-3 space-y-3">
                {items.map((item) => {
                    const percentage = total > 0 ? Math.round((item.total / total) * 100) : 0;

                    return (
                        <div key={item.key}>
                            <div className="mb-1.5 flex items-center justify-between gap-3 text-xs">
                                <span className="font-medium text-[var(--ink-700)]">{labels[item.key] || item.key}</span>
                                <span className="mono text-[var(--ink-500)]">{item.total}</span>
                            </div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
                                <div className={`h-full rounded-full ${colors[item.key] || 'bg-slate-500'}`} style={{ width: `${percentage}%` }} />
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function formatGigabytes(bytes) {
    const gigabytes = Number(bytes || 0) / (1024 ** 3);
    const precision = gigabytes > 0 && gigabytes < 0.001 ? 6 : gigabytes < 0.1 ? 4 : 2;

    return `${new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: precision,
    }).format(gigabytes)} GB`;
}

function formatDate(value) {
    if (!value) return '';

    return new Intl.DateTimeFormat('pt-BR', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}
