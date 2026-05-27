import ContractAccessCard from '@/Components/ContractAccessCard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { Building2, ClipboardList, Plus, Users, Wallet } from 'lucide-react';

const colors = ['#0b5fff', '#0e7c66', '#b58105', '#6a52d8', '#5b6479', '#c8364a'];
const statusMeta = {
    planning: { label: 'Planejamento', pill: 'sig-pill-blue' },
    active: { label: 'Em andamento', pill: 'sig-pill-green' },
    paused: { label: 'Paralisado', pill: 'sig-pill-amber' },
    completed: { label: 'Concluído', pill: 'sig-pill-blue' },
    cancelled: { label: 'Cancelado', pill: 'sig-pill-red' },
};

function enrichContract(contract, index) {
    const physical = contract.status === 'completed'
        ? 100
        : contract.status === 'paused'
            ? 42
            : contract.status === 'planning'
                ? 12
                : 58 + ((contract.id * 7) % 25);

    return {
        ...contract,
        meta: statusMeta[contract.status] || statusMeta.planning,
        physical,
        financial: contract.status === 'completed' ? 100 : Math.max(8, physical - 7),
        pinned: index < 2,
        color: colors[index % colors.length],
        badge: (contract.code || contract.name || '?').replace(/[^A-Za-z0-9]/g, '').slice(0, 2).toUpperCase(),
    };
}

const shortDate = (date) => {
    if (!date) return 'sem prazo';

    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date));
};

export default function TenantDashboard({ tenant, stats, recentContracts, role }) {
    const page = usePage();
    const canManageUsers = Boolean(page.props.userPermissions?.can?.view_users);
    const contracts = recentContracts.map(enrichContract);

    return (
        <AuthenticatedLayout>
            <Head title={tenant.name} />

            <section className="sig-content fade-in">
                <div className="flex flex-wrap items-end gap-6">
                    <div className="min-w-0 flex-1">
                        <div className="eyebrow">Workspace · Empresa</div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">{tenant.name}</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            {role || 'participante externo'} · {tenant.plan} · {tenant.status}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('tenant.contracts.index', tenant.slug)} className="sig-btn sig-btn-primary">
                            <ClipboardList size={15} />
                            Acessar contratos
                        </Link>
                        {canManageUsers && (
                            <Link href={route('tenant.users.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                                <Users size={15} />
                                Equipe
                            </Link>
                        )}
                    </div>
                </div>

                <div className="mt-6 grid gap-3 lg:grid-cols-4 sm:grid-cols-2">
                    <Metric icon={ClipboardList} label="Contratos" value={stats.contracts} sub={`${stats.activeContracts} ativos`} />
                    <Metric icon={Building2} label="Obras ativas" value={stats.activeContracts} sub="Acompanhamento operacional" />
                    <Metric icon={Users} label="Equipe interna" value={stats.users} sub="Usuários do tenant" />
                    <Metric icon={Wallet} label="Participantes externos" value={stats.externalParticipants} sub="Cliente e construtora" />
                </div>

                <section className="mt-7">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-[17px] font-semibold text-[var(--ink-900)]">Contratos recentes</h2>
                            <p className="text-sm text-[var(--ink-500)]">Acesso rápido aos ambientes de trabalho.</p>
                        </div>
                        <Link href={route('tenant.contracts.index', tenant.slug)} className="sig-btn sig-btn-secondary sig-btn-sm">
                            Ver todos
                        </Link>
                    </div>

                    <div className="grid gap-4 xl:grid-cols-3 lg:grid-cols-2">
                        {contracts.map((contract) => (
                            <ContractAccessCard key={contract.id} tenant={tenant} contract={contract} shortDate={shortDate} />
                        ))}
                    </div>

                    {contracts.length === 0 && (
                        <div className="sig-card p-12 text-center text-[var(--ink-500)]">
                            Nenhum contrato criado ainda.
                            {role && (
                                <Link href={route('tenant.contracts.index', tenant.slug)} className="sig-btn sig-btn-primary ml-3">
                                    <Plus size={14} />
                                    Criar primeiro contrato
                                </Link>
                            )}
                        </div>
                    )}
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function Metric({ icon: Icon, label, value, sub }) {
    return (
        <div className="sig-card p-[18px]">
            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                <Icon size={14} />
                <span className="eyebrow">{label}</span>
            </div>
            <div className="mono mt-2 text-[28px] font-semibold">{value}</div>
            <p className="text-[12.5px] text-[var(--ink-500)]">{sub}</p>
        </div>
    );
}
