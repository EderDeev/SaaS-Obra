import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import OverviewTour from '@/Components/OverviewTour';
import OsDnitManagementCard from '@/Components/OsDnitManagementCard';
import { Head, Link, router, usePage } from '@inertiajs/react';
import ReactECharts from 'echarts-for-react';
import './Dashboard.css';
import { useEffect, useRef, useState } from 'react';
import {
    Activity,
    ArrowUpRight,
    Building2,
    Filter,
    LayoutDashboard,
    X,
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
    'Liberação de Serviço': ClipboardCheck,
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
    service_order: 'Liberação de Serviço',
    os_dnit: 'Ordem de Serviço',
    construction_diary: 'Diario de obra',
    contract: 'Contrato',
    administrative: 'Administrativo',
    field: 'Campo',
    client: 'Cliente',
};

const shortDate = (date) => date
    ? new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(/^\d{4}-\d{2}-\d{2}$/.test(date) ? `${date}T12:00:00` : date))
    : 'Sem prazo';

const timeAgo = (date) => {
    if (!date) return '';

    const elapsedMinutes = Math.max(0, Math.floor((Date.now() - new Date(date).getTime()) / 60000));

    if (elapsedMinutes < 60) return `há ${elapsedMinutes || 1} min`;
    if (elapsedMinutes < 1440) return `há ${Math.floor(elapsedMinutes / 60)} h`;

    return shortDate(date);
};

export default function TenantDashboard({ tenant, role, stats, charts, myActivities, attentionItems, recentEvents, capabilities, osSummary = null, contracts = [], filters = {} }) {
    const [attentionFilter, setAttentionFilter] = useState('all');
    const [contractId, setContractId] = useState(filters.contract_id || '');
    const [filtering, setFiltering] = useState(false);
    const cancel = useRef(null);
    const { errors } = usePage().props;
    useEffect(() => { setContractId(filters.contract_id || ''); setAttentionFilter('all'); }, [filters.contract_id]);
    useEffect(() => () => cancel.current?.cancel(), []);
    const selectedContract = contracts.find((contract) => String(contract.id) === String(contractId));
    const changeContract = (value) => {
        cancel.current?.cancel(); setContractId(value); setFiltering(true);
        router.get(route('tenant.dashboard', tenant.slug), value ? { contract_id: value } : {}, {
            preserveState: true, preserveScroll: true, replace: true,
            onCancelToken: (token) => { cancel.current = token; }, onFinish: () => setFiltering(false),
        });
    };
    const url = (name) => route(name, { tenant: tenant.slug, ...(contractId ? { contract_id: contractId } : {}) });
    const filteredAttentionItems = attentionFilter === 'all' ? attentionItems : attentionItems.filter((item) => item.group === attentionFilter);
    const completed = charts.activitiesByStatus.find((item) => item.key === 'done')?.value || 0;
    const activityTotal = (charts.activityTrend || []).reduce((sum, item) => sum + item.total, 0);
    return <AuthenticatedLayout><Head title={`Visão geral - ${tenant.name}`} />
        <section className="overview-page fade-in" aria-busy={filtering}>
            <header data-tour="overview-header" className="overview-hero">
                <svg className="overview-hero-network" viewBox="0 0 1200 280" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><g fill="none" stroke="currentColor" strokeWidth="1"><path d="M-20 215 140 90 320 195 470 35 650 170 810 55 1010 180 1230 45M-20 215 320 195 650 170 1010 180 1230 240M140 90 230 270 470 35 530 275 810 55 915 280 1010 180M-20 10 140 90 470 35 810 55 1230 45" /></g>{[[140,90],[320,195],[470,35],[650,170],[810,55],[1010,180],[230,270]].map(([cx,cy]) => <circle key={cx} cx={cx} cy={cy} r="4" fill="currentColor" />)}</svg>
                <div className="overview-hero-top"><span className="flex items-center gap-2 text-xs font-medium text-white/75"><LayoutDashboard size={15} />SigWorks<span className="text-white/35">/</span>Gestão</span><span className="text-xs text-white/70">{new Intl.DateTimeFormat('pt-BR', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date())}</span></div>
                <div className="overview-hero-heading"><div className="min-w-0"><h1 className="text-3xl font-semibold tracking-tight sm:text-[34px]">Visão geral</h1><p className="mt-2 text-sm text-white/80">{tenant.name}<span className="mx-2 text-white/40">·</span>{role || 'Participante'}</p></div>
                    <div className="flex w-full flex-wrap items-end gap-3 sm:w-auto"><label className="grid min-w-0 flex-1 gap-2 sm:w-80"><span className="flex items-center gap-1.5 text-xs font-medium text-white/85"><Filter size={13} />Filtrar por contrato</span><select aria-label="Filtrar por contrato" className="overview-contract-select" value={contractId} onChange={(event) => changeContract(event.target.value)}><option value="">Todos os contratos</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} · {contract.name}</option>)}</select></label><OverviewTour /></div>
                </div>
            </header>
            <div className="overview-body">
                {errors?.contract_id && <p role="alert" className="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{errors.contract_id}</p>}
                {contractId && <div className="overview-active-filter"><span className="min-w-0 truncate">Exibindo: <strong>{selectedContract?.code}</strong> · {selectedContract?.name}</span><button type="button" onClick={() => changeContract('')} className="flex shrink-0 items-center gap-1 text-xs font-semibold"><X size={13} />Limpar</button></div>}
                <section data-tour="overview-metrics" className="overview-bento">
                    <section className="overview-card overview-trend">
                        <div className="flex items-start justify-between gap-3"><div><h2 className="text-base font-semibold text-slate-800">Atividades</h2><p className="mt-1 text-xs text-slate-400">Registros dos últimos 6 meses</p></div><span className="overview-soft-icon"><Activity size={18} /></span></div>
                        <div className="mt-5 flex items-baseline gap-2"><strong className="text-4xl font-semibold tracking-tight text-slate-900">{capabilities.activities ? activityTotal.toLocaleString('pt-BR') : '—'}</strong><span className="text-xs text-slate-400">no seu acesso</span></div>
                        {capabilities.activities ? <ActivityTrend rows={charts.activityTrend || []} /> : <p className="my-auto py-8 text-sm text-slate-400">Você não tem acesso às atividades neste filtro.</p>}
                        <div className="mt-auto grid gap-4 border-t border-slate-100 pt-5"><OverviewCount icon={AlertTriangle} label="Vencidas" value={stats.overdueActivities} tone="rose" /><OverviewCount icon={CalendarClock} label="Vencem em até 7 dias" value={stats.activitiesDueSoon} tone="amber" /><OverviewCount icon={CheckCircle2} label="Concluídas" value={completed} tone="green" /></div>
                    </section>
                    <section className="overview-card overview-welcome"><div className="min-w-0"><span className="overview-kicker">{selectedContract ? 'Contrato selecionado' : 'Sua operação'}</span><h2 className="mt-2 text-xl font-semibold tracking-tight text-slate-800">{selectedContract?.code || 'Todos os contratos'}</h2><p className="mt-2 text-sm text-slate-500">{selectedContract?.name || `${stats.contracts} contrato(s) disponíveis para acompanhamento.`}</p></div><div className="flex flex-wrap gap-2">{capabilities.createActivity && <Link href={url('tenant.activities.index')} className="overview-action"><Plus size={14} />Atividade</Link>}{capabilities.createRnc && <Link href={url('tenant.qualidade.rnc.create')} className="overview-action overview-action-primary"><Plus size={14} />RNC</Link>}{capabilities.uploadProject && <Link href={url('tenant.projects.index')} className="overview-action"><Send size={14} />Projeto</Link>}</div></section>
                    <section className="overview-deadlines"><div className="relative z-[1]"><span className="text-xs font-medium text-white/75">Prazos das atividades</span><div className="mt-3 flex items-baseline gap-2"><strong className="text-4xl font-semibold tracking-tight">{capabilities.activities ? stats.overdueActivities : '—'}</strong><span className="text-sm text-white/80">vencidas</span></div><p className="mt-3 text-xs text-white/80">{stats.activitiesDueSoon} próxima(s) do prazo · {stats.openActivities} em aberto</p></div>{capabilities.activities && <Link href={url('tenant.activities.index')} className="relative z-[1] mt-5 inline-flex items-center gap-2 text-xs font-semibold text-white">Acompanhar atividades<ArrowUpRight size={15} /></Link>}</section>
                    <section className="overview-contract-card"><BuildingArtwork /><div className="relative z-[1]"><div className="flex items-center justify-between gap-3"><span className="text-xs font-medium text-blue-200">{selectedContract ? 'Contrato em foco' : 'Contratos em operação'}</span><Building2 size={20} className="text-blue-300" /></div><strong className="mt-5 block text-5xl font-semibold tracking-tight">{selectedContract ? '1' : stats.activeContracts}</strong><p className="mt-2 text-sm text-white/80">{selectedContract ? selectedContract.code : `ativos de ${stats.contracts} disponíveis`}</p><p className="mt-5 max-w-52 text-xs leading-5 text-blue-200/80">{selectedContract?.name || 'Informações de cada módulo, reunidas por contrato.'}</p></div>{capabilities.contracts && <Link href={selectedContract ? route('tenant.contracts.show', [tenant.slug, selectedContract.id]) : route('tenant.contracts.index', tenant.slug)} className="relative z-[1] mt-auto inline-flex items-center gap-2 pt-8 text-xs font-semibold text-white">{selectedContract ? 'Acessar contrato' : 'Ver contratos'}<ArrowRight size={14} /></Link>}</section>
                    <MiniFeature icon={FileWarning} label="RNCs abertas" value={stats.openRncs} detail={`${stats.overdueRncs} com prazo vencido`} href={url('tenant.qualidade.rnc.dashboard')} allowed={capabilities.rncs} />
                    <MiniFeature icon={FolderOpen} label="Projetos em fluxo" value={stats.pendingProjects} detail="Análise e aprovação" href={url('tenant.projects.review.index')} allowed={capabilities.projects} blue />
                </section>
                <section data-tour="overview-operation" className="mt-7">
                    <div className="mb-3">
                        <h2 className="text-[16px] font-semibold text-[var(--ink-900)]">Operação do contrato</h2>
                        <p className="text-sm text-[var(--ink-500)]">Fluxos que passaram a integrar o workspace.</p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {capabilities.documentation && <OperationalCard icon={Inbox} title="Documentação" value={stats.documents} detail={stats.pendingTriage > 0 ? `${stats.pendingTriage} e-mail(s) em triagem` : `${stats.documentsInProgress} em processamento OCR`} tone={stats.pendingTriage > 0 ? 'amber' : stats.documentsInProgress > 0 ? 'blue' : 'green'} href={route(stats.pendingTriage > 0 && capabilities.documentationEmail ? 'tenant.ged.triage' : 'tenant.ged.index', tenant.slug)} />}
                        {capabilities.rdo && <OperationalCard icon={HardHat} title="Diário de obra" value={stats.rdoAwaitingReview} detail={stats.rdoAwaitingReview > 0 ? 'RDO(s) aguardando fluxo' : 'Nenhum RDO pendente'} tone={stats.rdoAwaitingReview > 0 ? 'amber' : 'green'} href={route('tenant.diario-obra.rdo.dashboard', tenant.slug)} />}
                        {capabilities.measurements && <OperationalCard icon={ReceiptText} title="Medição" value={stats.openBoletins} detail={stats.openBoletins > 0 ? 'boletim(ns) em lançamento' : 'Nenhum boletim aberto'} tone={stats.openBoletins > 0 ? 'blue' : 'green'} href={route('tenant.medicao.boletim-medicao.index', tenant.slug)} />}
                        {capabilities.serviceOrders && <OperationalCard icon={ClipboardCheck} title="Liberação de Serviço" value={stats.pendingOrders} detail={stats.pendingOrders > 0 ? 'Liberações em análise ou aprovação' : 'Nenhuma liberação pendente'} tone={stats.pendingOrders > 0 ? 'amber' : 'green'} href={route('tenant.ordem-servico.analise.index', tenant.slug)} />}
                    </div>
                </section>

                {osSummary && <div className="mt-6"><OsDnitManagementCard tenant={tenant} summary={osSummary} contractId={contractId || undefined} /></div>}
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
            </div>
        </section>
    </AuthenticatedLayout>;
}

function ActivityTrend({ rows }) {
    const hasData = rows.some((row) => row.total > 0);
    if (!hasData) return <div className="flex min-h-40 flex-1 items-center justify-center text-center text-xs text-slate-400">Nenhuma atividade registrada nos últimos 6 meses.</div>;
    return <ReactECharts option={{ aria: { enabled: true }, grid: { left: 4, right: 4, top: 20, bottom: 25, containLabel: true }, tooltip: { trigger: 'axis', renderMode: 'richText' },
        xAxis: { type: 'category', data: rows.map((row) => row.label), boundaryGap: false, axisLine: { show: false }, axisTick: { show: false }, axisLabel: { color: '#94a3b8', fontSize: 10 } },
        yAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: '#f1f5f9' } }, axisLabel: { color: '#94a3b8', fontSize: 10 } },
        series: [{ name: 'Atividades registradas', type: 'line', smooth: true, symbolSize: 5, data: rows.map((row) => row.total), lineStyle: { width: 2.5, color: '#45bd9d' }, itemStyle: { color: '#45bd9d' }, areaStyle: { color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1, colorStops: [{ offset: 0, color: '#b8f2dc' }, { offset: 1, color: '#f8fffc' }] } } }],
    }} style={{ width: '100%', height: 185, minHeight: 185 }} notMerge />;
}

function OverviewCount({ icon: Icon, label, value, tone }) {
    const colors = { rose: 'bg-rose-50 text-rose-500', amber: 'bg-amber-50 text-amber-500', green: 'bg-emerald-50 text-emerald-500' };
    return <div className="flex items-center gap-3"><span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${colors[tone]}`}><Icon size={17} /></span><span className="min-w-0 flex-1 text-xs text-slate-500">{label}</span><strong className="text-sm font-semibold tabular-nums text-slate-700">{value}</strong></div>;
}

function MiniFeature({ icon: Icon, label, value, detail, href, allowed, blue }) {
    const Tag = allowed ? Link : 'section';
    return <Tag {...(allowed ? { href } : {})} className={`overview-mini ${blue ? 'overview-mini-blue' : 'overview-card'}`}><Icon size={22} className={blue ? 'text-white/80' : 'text-cyan-500'} /><strong className="mt-4 block text-2xl font-semibold tracking-tight">{allowed ? value : '—'}</strong><span className="mt-1 block text-xs font-medium">{label}</span><span className={`mt-1 block text-[11px] ${blue ? 'text-white/65' : 'text-slate-400'}`}>{allowed ? detail : 'Sem acesso neste filtro'}</span></Tag>;
}

function BuildingArtwork() {
    return <svg viewBox="0 0 300 300" className="overview-building" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" strokeWidth="1.3"><path d="M60 290V90L145 45l100 50v195M60 90l100 45 85-40M160 135v155M85 103v180M110 115v168M135 126v157M185 123v160M210 111v172M235 99v184M60 128l100 45 85-40M60 167l100 45 85-40M60 206l100 45 85-40M60 245l100 45 85-40" /><path d="M15 290h280M30 290V170l30-15M245 155l40 25v110" /></g></svg>;
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
                            <div className="h-full rounded bg-[var(--primary)]" style={{ width: `${total ? (item.value / total) * 100 : 0}%` }} />
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
