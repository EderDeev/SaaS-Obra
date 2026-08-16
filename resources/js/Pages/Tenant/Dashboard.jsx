import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import OverviewTour from '@/Components/OverviewTour';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    FileText,
    FileWarning,
    FolderOpen,
    HardHat,
    Inbox,
    ListChecks,
    Plus,
    ReceiptText,
    Send,
    Tag,
} from 'lucide-react';

const eventIcons = {
    Atividade: Activity,
    Projeto: FolderOpen,
    RNC: FileWarning,
    Documento: FileText,
    RDO: HardHat,
    Medição: ReceiptText,
    'Ordem de serviço': ClipboardCheck,
    Aditivo: ClipboardList,
};

const toneClasses = {
    red: 'bg-[var(--red-50)] text-[var(--red)]',
    amber: 'bg-[var(--amber-50)] text-[var(--amber)]',
    blue: 'bg-[var(--primary-50)] text-[var(--primary)]',
    green: 'bg-[var(--green-50)] text-[var(--green)]',
};

const attentionAccent = {
    red: 'border-l-[var(--red)] bg-[var(--red-50)]/40',
    amber: 'border-l-[var(--amber)] bg-[var(--amber-50)]/35',
    blue: 'border-l-[var(--primary)] bg-[var(--primary-50)]/35',
};

const activityStatus = {
    todo: { label: 'A fazer', className: 'bg-[var(--ink-100)] text-[var(--ink-700)]' },
    in_progress: { label: 'Em andamento', className: 'bg-[var(--primary-50)] text-[var(--primary)]' },
    review: { label: 'Em revisao', className: 'bg-[var(--amber-50)] text-[var(--amber)]' },
    done: { label: 'Concluida', className: 'bg-[var(--green-50)] text-[var(--green)]' },
};

const activityPriority = {
    low: { label: 'Baixa', className: 'bg-[var(--ink-100)] text-[var(--ink-600)]' },
    normal: { label: 'Normal', className: 'bg-[var(--primary-50)] text-[var(--primary)]' },
    high: { label: 'Alta', className: 'bg-[var(--amber-50)] text-[var(--amber)]' },
    urgent: { label: 'Urgente', className: 'bg-[var(--red-50)] text-[var(--red)]' },
};

const activityCategories = {
    project: 'Projetos',
    quality: 'Qualidade',
    budget: 'Orcamento',
    measurement: 'Medicao',
    documentation: 'Documentacao',
    service_order: 'Ordem de servico',
    construction_diary: 'Diario de obra',
    contract: 'Contrato',
    administrative: 'Administrativo',
    field: 'Campo',
    client: 'Cliente',
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
    capabilities,
}) {
    const [attentionFilter, setAttentionFilter] = useState('all');
    const filteredAttentionItems = attentionFilter === 'all'
        ? attentionItems
        : attentionItems.filter((item) => item.group === attentionFilter);

    return (
        <AuthenticatedLayout>
            <Head title={`Visão geral - ${tenant.name}`} />

            <section className="sig-content fade-in">
                <header data-tour="overview-header" className="flex flex-wrap items-end gap-5">
                    <div className="min-w-0 flex-1">
                        <div className="eyebrow">Workspace · Visão geral</div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">{tenant.name}</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">{role || 'Participante'} · acompanhamento consolidado</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <OverviewTour />
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

                <section data-tour="overview-metrics" className="mt-6">
                    <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-[16px] font-semibold text-[var(--ink-900)]">Resumo das pendências</h2>
                            <p className="text-sm text-[var(--ink-500)]">O que exige decisão ou acompanhamento primeiro.</p>
                        </div>
                        <span className="text-xs text-[var(--ink-500)]">{stats.activeContracts} de {stats.contracts} contrato(s) ativo(s)</span>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <SummaryCard icon={AlertTriangle} label="Atividades vencidas" value={stats.overdueActivities} detail="Fora do prazo" tone="red" href={route('tenant.activities.index', tenant.slug)} />
                        <SummaryCard icon={CalendarClock} label="Próximas do prazo" value={stats.activitiesDueSoon} detail="Hoje ou nos próximos 7 dias" tone="amber" href={route('tenant.activities.index', tenant.slug)} />
                        <SummaryCard icon={FolderOpen} label="Projetos em fluxo" value={stats.pendingProjects} detail="Em análise ou aprovação" tone="blue" href={route('tenant.projects.review.index', tenant.slug)} />
                        <SummaryCard icon={FileWarning} label="RNCs abertas" value={stats.openRncs} detail={`${stats.overdueRncs} com resposta atrasada`} tone={stats.overdueRncs > 0 ? 'red' : 'amber'} href={route('tenant.qualidade.rnc.dashboard', tenant.slug)} />
                    </div>
                </section>

                <section data-tour="overview-operation" className="mt-6">
                    <div className="mb-3">
                        <h2 className="text-[16px] font-semibold text-[var(--ink-900)]">Operação do contrato</h2>
                        <p className="text-sm text-[var(--ink-500)]">Fluxos que passaram a integrar o workspace.</p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {capabilities.documentation && <OperationalCard icon={Inbox} title="Documentação" value={stats.documents} detail={stats.pendingTriage > 0 ? `${stats.pendingTriage} e-mail(s) em triagem` : `${stats.documentsInProgress} em processamento OCR`} tone={stats.pendingTriage > 0 ? 'amber' : stats.documentsInProgress > 0 ? 'blue' : 'green'} href={route(stats.pendingTriage > 0 && capabilities.documentationEmail ? 'tenant.ged.triage' : 'tenant.ged.index', tenant.slug)} />}
                        {capabilities.rdo && <OperationalCard icon={HardHat} title="Diário de obra" value={stats.rdoAwaitingReview} detail={stats.rdoAwaitingReview > 0 ? 'RDO(s) aguardando fluxo' : 'Nenhum RDO pendente'} tone={stats.rdoAwaitingReview > 0 ? 'amber' : 'green'} href={route('tenant.diario-obra.rdo.dashboard', tenant.slug)} />}
                        {capabilities.measurements && <OperationalCard icon={ReceiptText} title="Medição" value={stats.openBoletins} detail={stats.openBoletins > 0 ? 'boletim(ns) em lançamento' : 'Nenhum boletim aberto'} tone={stats.openBoletins > 0 ? 'blue' : 'green'} href={route('tenant.medicao.boletim-medicao.index', tenant.slug)} />}
                        {capabilities.serviceOrders && <OperationalCard icon={ClipboardCheck} title="Ordem de serviço" value={stats.pendingOrders} detail={stats.pendingOrders > 0 ? 'OS(s) em análise ou aprovação' : 'Nenhuma OS pendente'} tone={stats.pendingOrders > 0 ? 'amber' : 'green'} href={route('tenant.ordem-servico.analise.index', tenant.slug)} />}
                    </div>
                </section>

                <section data-tour="overview-monitoring" className="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1.25fr)_minmax(340px,0.75fr)]">
                    <section className="sig-card overflow-hidden">
                        <header className="border-b border-[var(--border)] px-5 py-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="flex items-start gap-3">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--red-50)] text-[var(--red)]">
                                        <AlertTriangle size={16} />
                                    </span>
                                    <span>
                                        <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Pontos de atenção</h2>
                                        <p className="text-[12.5px] text-[var(--ink-500)]">Somente prazos próximos, atrasos e decisões pendentes.</p>
                                    </span>
                                </div>
                                <div className="inline-flex rounded-md border border-[var(--border)] bg-white p-0.5">
                                    {[
                                        ['all', 'Todos'],
                                        ['critical', 'Críticos'],
                                        ['due', 'Prazos'],
                                        ['workflow', 'Fluxos'],
                                    ].map(([value, label]) => (
                                        <button
                                            key={value}
                                            type="button"
                                            onClick={() => setAttentionFilter(value)}
                                            className={`min-h-8 px-2.5 text-xs font-semibold ${attentionFilter === value ? 'rounded bg-[var(--ink-900)] text-white' : 'text-[var(--ink-500)] hover:text-[var(--ink-900)]'}`}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </header>
                        {filteredAttentionItems.length > 0 ? (
                            <div className="divide-y divide-[var(--border)]">
                                {filteredAttentionItems.map((item, index) => (
                                    <Link key={`${item.type}-${item.title}-${index}`} href={item.url} className={`group flex items-center gap-3 border-l-[3px] px-5 py-3.5 transition hover:brightness-[0.98] ${attentionAccent[item.tone] || attentionAccent.blue}`}>
                                        <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${toneClasses[item.tone] || toneClasses.blue}`}>
                                            <AlertTriangle size={15} />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="eyebrow">{item.type}</span>
                                                <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${toneClasses[item.tone] || toneClasses.blue}`}>{item.badge}</span>
                                            </span>
                                            <span className="mt-0.5 block truncate text-sm font-semibold text-[var(--ink-900)]">{item.title}</span>
                                            <span className="block truncate text-xs text-[var(--ink-500)]">{item.subtitle}</span>
                                        </span>
                                        <ArrowRight size={15} className="shrink-0 text-[var(--ink-400)] transition group-hover:translate-x-0.5" />
                                    </Link>
                                ))}
                            </div>
                        ) : (
                            <EmptyState text="Nenhuma pendência neste filtro." />
                        )}
                    </section>

                    <aside className="grid content-start gap-5">
                        <Panel title="Minhas atividades" subtitle="Prioridades atribuídas diretamente a você." icon={Activity}>
                            {myActivities.length > 0 ? (
                                <div className="divide-y divide-[var(--border)]">
                                    {myActivities.map((activity) => (
                                        <ActivitySummaryRow key={activity.id} activity={activity} tenant={tenant} />
                                    ))}
                                </div>
                            ) : (
                                <EmptyState text="Nenhuma atividade aberta atribuída a você." />
                            )}
                        </Panel>

                        <Panel title="Eventos recentes" subtitle="Histórico do que mudou no workspace." icon={CheckCircle2}>
                            {recentEvents.length > 0 ? (
                                <div className="px-5 py-2">
                                    {recentEvents.map((event, index) => (
                                        <TimelineEvent key={`${event.type}-${event.title}-${index}`} event={event} last={index === recentEvents.length - 1} />
                                    ))}
                                </div>
                            ) : (
                                <EmptyState text="Nenhum evento registrado ainda." />
                            )}
                        </Panel>
                    </aside>
                </section>

                <section data-tour="overview-indicators" className="mt-6">
                    <div className="mb-3">
                        <h2 className="text-[16px] font-semibold text-[var(--ink-900)]">Indicadores do workspace</h2>
                        <p className="text-sm text-[var(--ink-500)]">Distribuição dos registros acessíveis por etapa e categoria.</p>
                    </div>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <ChartPanel title="Atividades por status" items={charts.activitiesByStatus} />
                        <ChartPanel title="Projetos por etapa" items={charts.projectsByStatus} />
                        <ChartPanel title="Atividades por categoria" items={charts.activitiesByCategory} />
                        <ChartPanel title="RNCs por status" items={charts.rncsByStatus} />
                    </div>
                </section>

            </section>
        </AuthenticatedLayout>
    );
}

function SummaryCard({ icon: Icon, label, value, detail, tone, href }) {
    return (
        <Link href={href} className="sig-card group relative overflow-hidden p-[18px] transition hover:border-[var(--ink-300)] hover:shadow-sm">
            <span className={`absolute inset-x-0 top-0 h-[3px] ${tone === 'red' ? 'bg-[var(--red)]' : tone === 'amber' ? 'bg-[var(--amber)]' : 'bg-[var(--primary)]'}`} />
            <div className="flex items-start justify-between gap-3">
                <span className={`flex h-9 w-9 items-center justify-center rounded-lg ${toneClasses[tone] || toneClasses.blue}`}>
                    <Icon size={16} />
                </span>
                <ArrowRight size={15} className="text-[var(--ink-400)] transition group-hover:translate-x-0.5" />
            </div>
            <div className="mono mt-3 text-[28px] font-semibold text-[var(--ink-900)]">{value}</div>
            <div className="mt-0.5 text-sm font-semibold text-[var(--ink-900)]">{label}</div>
            <p className="mt-0.5 text-[12px] text-[var(--ink-500)]">{detail}</p>
        </Link>
    );
}

function ActivitySummaryRow({ activity, tenant }) {
    const status = activityStatus[activity.status] || activityStatus.todo;
    const priority = activityPriority[activity.priority] || activityPriority.normal;
    const checklistTotal = Number(activity.checklist_items_count || 0);
    const checklistDone = Number(activity.completed_checklist_items_count || 0);
    const progress = checklistTotal > 0 ? Math.round((checklistDone / checklistTotal) * 100) : 0;
    const isOverdue = activity.due_date && new Date(activity.due_date) < new Date(new Date().toDateString());

    return (
        <Link href={route('tenant.activities.index', tenant.slug)} className="group block px-5 py-3.5 transition hover:bg-[var(--surface-muted)]">
            <span className="flex flex-wrap items-center gap-1.5">
                <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${status.className}`}>{status.label}</span>
                <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${priority.className}`}>{priority.label}</span>
                {activity.activity_type === 'checklist' && (
                    <span className="flex items-center gap-1 rounded bg-[var(--green-50)] px-1.5 py-0.5 text-[10px] font-semibold text-[var(--green)]">
                        <ListChecks size={10} /> Checklist
                    </span>
                )}
            </span>
            <span className="mt-2 flex items-start justify-between gap-3">
                <span className="min-w-0">
                    <span className="block truncate text-sm font-semibold text-[var(--ink-900)]">{activity.title}</span>
                    <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11.5px] text-[var(--ink-500)]">
                        <span className="flex items-center gap-1"><Tag size={11} />{activityCategories[activity.category] || activity.category}</span>
                        <span className={`flex items-center gap-1 ${isOverdue ? 'font-semibold text-[var(--red)]' : ''}`}><CalendarClock size={11} />{shortDate(activity.due_date)}</span>
                    </span>
                </span>
                <span className="mono shrink-0 text-[11px] text-[var(--ink-500)]">{activity.contract?.code}</span>
            </span>
            {activity.activity_type === 'checklist' && checklistTotal > 0 && (
                <span className="mt-3 block">
                    <span className="mb-1 flex items-center justify-between text-[10.5px] text-[var(--ink-500)]">
                        <span>{checklistDone} de {checklistTotal} etapas</span>
                        <span>{progress}%</span>
                    </span>
                    <span className="block h-1.5 overflow-hidden rounded bg-[var(--ink-100)]">
                        <span className="block h-full rounded bg-[var(--green)]" style={{ width: `${progress}%` }} />
                    </span>
                </span>
            )}
        </Link>
    );
}

function TimelineEvent({ event, last }) {
    const Icon = eventIcons[event.type] || Activity;

    return (
        <Link href={event.url} className="group relative flex gap-3 py-2.5">
            {!last && <span className="absolute bottom-[-10px] left-[15px] top-9 w-px bg-[var(--border)]" />}
            <span className="relative z-[1] flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-[var(--border)] bg-white text-[var(--ink-500)] transition group-hover:border-[var(--primary-200)] group-hover:text-[var(--primary)]">
                <Icon size={13} />
            </span>
            <span className="min-w-0 flex-1">
                <span className="flex items-start justify-between gap-2">
                    <span className="truncate text-[12.5px] font-semibold text-[var(--ink-900)]">{event.title}</span>
                    <span className="shrink-0 text-[10.5px] text-[var(--ink-400)]">{timeAgo(event.created_at)}</span>
                </span>
                <span className="block truncate text-[11.5px] text-[var(--ink-500)]">{event.subtitle}</span>
            </span>
        </Link>
    );
}

function OperationalCard({ icon: Icon, title, value, detail, tone, href }) {
    return (
        <Link href={href} className="sig-card group flex min-h-[132px] flex-col justify-between p-[18px] transition hover:-translate-y-0.5 hover:border-[var(--primary-200)] hover:shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <span className={`flex h-9 w-9 items-center justify-center rounded-lg ${toneClasses[tone] || toneClasses.blue}`}>
                    <Icon size={16} />
                </span>
                <ArrowRight size={15} className="mt-1 text-[var(--ink-400)] transition group-hover:translate-x-0.5 group-hover:text-[var(--primary)]" />
            </div>
            <div>
                <div className="mono text-[26px] font-semibold text-[var(--ink-900)]">{value}</div>
                <div className="mt-1 text-sm font-semibold text-[var(--ink-900)]">{title}</div>
                <div className="mt-0.5 text-[12px] text-[var(--ink-500)]">{detail}</div>
            </div>
        </Link>
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

function EmptyState({ text }) {
    return <div className="px-5 py-8 text-center text-sm text-[var(--ink-500)]">{text}</div>;
}
