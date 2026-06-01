import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    Building2,
    CalendarClock,
    CheckCircle2,
    ClipboardList,
    FileWarning,
    FolderOpen,
    ListTodo,
    Plus,
    Send,
    Users,
} from 'lucide-react';

const eventIcons = {
    Atividade: Activity,
    Projeto: FolderOpen,
    RNC: FileWarning,
};

const toneClasses = {
    red: 'bg-[var(--red-50)] text-[var(--red)]',
    amber: 'bg-[var(--amber-50)] text-[var(--amber)]',
    blue: 'bg-[var(--primary-50)] text-[var(--primary)]',
    green: 'bg-[var(--green-50)] text-[var(--green)]',
};

const contractStatusLabels = {
    planning: 'Planejamento',
    active: 'Em andamento',
    paused: 'Paralisado',
    completed: 'Concluído',
    cancelled: 'Cancelado',
};

const shortDate = (date) => date
    ? new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date))
    : 'Sem prazo';

const timeAgo = (date) => {
    if (!date) return '';

    const elapsedMinutes = Math.max(0, Math.floor((Date.now() - new Date(date).getTime()) / 60000));

    if (elapsedMinutes < 60) return `há ${elapsedMinutes || 1} min`;
    if (elapsedMinutes < 1440) return `há ${Math.floor(elapsedMinutes / 60)} h`;

    return shortDate(date);
};

export default function TenantDashboard({
    tenant,
    role,
    stats,
    charts,
    myActivities,
    attentionItems,
    recentEvents,
    recentContracts,
    capabilities,
}) {
    return (
        <AuthenticatedLayout>
            <Head title={`Visão geral - ${tenant.name}`} />

            <section className="sig-content fade-in">
                <header className="flex flex-wrap items-end gap-5">
                    <div className="min-w-0 flex-1">
                        <div className="eyebrow">Workspace · Visão geral</div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">{tenant.name}</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">{role || 'Participante'} · acompanhamento consolidado</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {capabilities.createActivity && (
                            <Link href={route('tenant.activities.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                                <Plus size={15} />
                                Atividade
                            </Link>
                        )}
                        {capabilities.uploadProject && (
                            <Link href={route('tenant.projects.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                                <Send size={15} />
                                Submeter projeto
                            </Link>
                        )}
                        {capabilities.createRnc && (
                            <Link href={route('tenant.qualidade.rnc.create', tenant.slug)} className="sig-btn sig-btn-primary">
                                <FileWarning size={15} />
                                Nova RNC
                            </Link>
                        )}
                    </div>
                </header>

                <div className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric icon={ClipboardList} label="Contratos ativos" value={stats.activeContracts} sub={`${stats.contracts} contrato(s) acessível(is)`} />
                    <Metric icon={ListTodo} label="Atividades abertas" value={stats.openActivities} sub={`${stats.overdueActivities} em atraso · ${stats.activitiesDueToday} vencem hoje`} accent={stats.overdueActivities > 0 ? 'red' : 'blue'} />
                    <Metric icon={FileWarning} label="RNCs abertas" value={stats.openRncs} sub={`${stats.overdueRncs} com resposta em atraso`} accent={stats.overdueRncs > 0 ? 'red' : 'amber'} />
                    <Metric icon={FolderOpen} label="Projetos pendentes" value={stats.pendingProjects} sub="Em análise ou aprovação" accent={stats.pendingProjects > 0 ? 'amber' : 'green'} />
                </div>

                <section className="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(320px,0.8fr)]">
                    <div className="grid gap-5">
                        <Panel title="Pontos de atenção" subtitle="Pendências que pedem acompanhamento agora." icon={AlertTriangle}>
                            {attentionItems.length > 0 ? (
                                <div className="divide-y divide-[var(--border)]">
                                    {attentionItems.map((item, index) => (
                                        <Link key={`${item.type}-${item.title}-${index}`} href={item.url} className="flex items-center gap-3 px-5 py-3.5 hover:bg-[var(--surface-muted)]">
                                            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${toneClasses[item.tone] || toneClasses.blue}`}>
                                                <AlertTriangle size={15} />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="eyebrow">{item.type}</span>
                                                <span className="block truncate text-sm font-semibold text-[var(--ink-900)]">{item.title}</span>
                                                <span className="block truncate text-xs text-[var(--ink-500)]">{item.subtitle}</span>
                                            </span>
                                            <ArrowRight size={15} className="shrink-0 text-[var(--ink-400)]" />
                                        </Link>
                                    ))}
                                </div>
                            ) : (
                                <EmptyState text="Nenhuma pendência encontrada para o seu acesso." />
                            )}
                        </Panel>

                        <div className="grid gap-5 lg:grid-cols-2">
                            <ChartPanel title="Atividades por status" items={charts.activitiesByStatus} />
                            <ChartPanel title="Projetos por etapa" items={charts.projectsByStatus} />
                            <ChartPanel title="Atividades por categoria" items={charts.activitiesByCategory} />
                            <ChartPanel title="RNCs por status" items={charts.rncsByStatus} />
                        </div>
                    </div>

                    <aside className="grid content-start gap-5">
                        <Panel title="Minhas atividades" subtitle="Tarefas atribuídas diretamente a você." icon={Activity}>
                            {myActivities.length > 0 ? (
                                <div className="divide-y divide-[var(--border)]">
                                    {myActivities.map((activity) => (
                                        <Link key={activity.id} href={route('tenant.activities.index', tenant.slug)} className="block px-5 py-3.5 hover:bg-[var(--surface-muted)]">
                                            <span className="block truncate text-sm font-semibold text-[var(--ink-900)]">{activity.title}</span>
                                            <span className="mt-1 flex items-center gap-1.5 text-xs text-[var(--ink-500)]">
                                                <CalendarClock size={12} />
                                                {activity.contract?.code} · {shortDate(activity.due_date)}
                                            </span>
                                        </Link>
                                    ))}
                                </div>
                            ) : (
                                <EmptyState text="Nenhuma atividade aberta atribuída a você." />
                            )}
                        </Panel>

                        <Panel title="Eventos recentes" subtitle="Movimentações registradas no workspace." icon={CheckCircle2}>
                            {recentEvents.length > 0 ? (
                                <div className="divide-y divide-[var(--border)]">
                                    {recentEvents.map((event, index) => {
                                        const Icon = eventIcons[event.type] || Activity;

                                        return (
                                            <Link key={`${event.type}-${event.title}-${index}`} href={event.url} className="flex gap-3 px-5 py-3 hover:bg-[var(--surface-muted)]">
                                                <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[var(--surface-muted)] text-[var(--ink-600)]">
                                                    <Icon size={14} />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-[13px] font-semibold text-[var(--ink-900)]">{event.title}</span>
                                                    <span className="block truncate text-[11.5px] text-[var(--ink-500)]">{event.subtitle}</span>
                                                    <span className="block text-[11px] text-[var(--ink-400)]">{timeAgo(event.created_at)}</span>
                                                </span>
                                            </Link>
                                        );
                                    })}
                                </div>
                            ) : (
                                <EmptyState text="Nenhum evento registrado ainda." />
                            )}
                        </Panel>
                    </aside>
                </section>

                <section className="mt-6">
                    <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-[16px] font-semibold text-[var(--ink-900)]">Contratos recentes</h2>
                            <p className="text-sm text-[var(--ink-500)]">Acesso rápido aos ambientes operacionais.</p>
                        </div>
                        <Link href={route('tenant.contracts.index', tenant.slug)} className="sig-btn sig-btn-secondary sig-btn-sm">
                            Ver portfólio
                            <ArrowRight size={14} />
                        </Link>
                    </div>
                    <div className="sig-card overflow-hidden">
                        {recentContracts.length > 0 ? recentContracts.map((contract) => (
                            <CompactContract key={contract.id} tenant={tenant} contract={contract} />
                        )) : (
                            <EmptyState text="Nenhum contrato cadastrado ainda." />
                        )}
                    </div>
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function Metric({ icon: Icon, label, value, sub, accent = 'blue' }) {
    return (
        <div className="sig-card p-[18px]">
            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                <Icon size={14} />
                <span className="eyebrow">{label}</span>
            </div>
            <div className={`mono mt-2 text-[28px] font-semibold ${toneClasses[accent]?.split(' ').at(-1) || 'text-[var(--ink-900)]'}`}>{value}</div>
            <p className="mt-1 text-[12.5px] text-[var(--ink-500)]">{sub}</p>
        </div>
    );
}

function Panel({ title, subtitle, icon: Icon, children }) {
    return (
        <section className="sig-card overflow-hidden">
            <header className="flex items-start gap-3 border-b border-[var(--border)] px-5 py-4">
                <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                    <Icon size={16} />
                </span>
                <span>
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">{title}</h2>
                    <p className="text-[12.5px] text-[var(--ink-500)]">{subtitle}</p>
                </span>
            </header>
            {children}
        </section>
    );
}

function ChartPanel({ title, items }) {
    const total = items.reduce((sum, item) => sum + item.value, 0);

    return (
        <section className="sig-card p-5">
            <h3 className="text-[14px] font-semibold text-[var(--ink-900)]">{title}</h3>
            <p className="mt-1 text-xs text-[var(--ink-500)]">{total} registro(s)</p>
            <div className="mt-4 grid gap-3">
                {items.map((item) => (
                    <div key={item.key}>
                        <div className="mb-1 flex items-center justify-between gap-3 text-xs">
                            <span className="text-[var(--ink-600)]">{item.label}</span>
                            <span className="mono font-semibold text-[var(--ink-900)]">{item.value}</span>
                        </div>
                        <div className="h-1.5 overflow-hidden rounded bg-[var(--ink-100)]">
                            <div className="h-full rounded bg-[var(--primary)]" style={{ width: `${total ? Math.max(4, (item.value / total) * 100) : 0}%` }} />
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

function CompactContract({ tenant, contract }) {
    return (
        <Link href={route('tenant.contracts.show', [tenant.slug, contract.id])} className="flex flex-wrap items-center gap-4 border-b border-[var(--border)] px-5 py-4 last:border-b-0 hover:bg-[var(--surface-muted)]">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                <Building2 size={17} />
            </span>
            <span className="min-w-[180px] flex-1">
                <span className="mono block text-xs text-[var(--ink-500)]">{contract.code}</span>
                <span className="block truncate text-sm font-semibold text-[var(--ink-900)]">{contract.obra?.nome || contract.name}</span>
            </span>
            <span className="grid grid-cols-3 gap-4 text-center text-xs">
                <CompactCount label="Atividades" value={contract.open_activities_count} />
                <CompactCount label="RNCs" value={contract.open_rncs_count} />
                <CompactCount label="Projetos" value={contract.pending_projects_count} />
            </span>
            <span className="sig-pill">{contractStatusLabels[contract.status] || contract.status}</span>
            <ArrowRight size={15} className="shrink-0 text-[var(--ink-400)]" />
        </Link>
    );
}

function CompactCount({ label, value }) {
    return (
        <span>
            <span className="mono block font-semibold text-[var(--ink-900)]">{value || 0}</span>
            <span className="text-[11px] text-[var(--ink-500)]">{label}</span>
        </span>
    );
}

function EmptyState({ text }) {
    return <div className="px-5 py-8 text-center text-sm text-[var(--ink-500)]">{text}</div>;
}
