import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import OverviewTour from '@/Components/OverviewTour';
import { Head, Link, router, usePage } from '@inertiajs/react';
import ReactECharts from 'echarts-for-react';
import { useEffect, useRef, useState } from 'react';
import {
    Activity, ArrowUpRight, Building2, Filter, LayoutDashboard, X, AlertTriangle,
    ArrowRight, CalendarClock, CheckCircle2, ClipboardCheck, ClipboardList,
    FileWarning, FolderOpen, HardHat, Inbox, ReceiptText, ShieldCheck, Calculator,
} from 'lucide-react';
import './Dashboard.css';

export default function TenantDashboard({ tenant, role, stats, charts, capabilities, osSummary = null, contracts = [], filters = {} }) {
    const [contractId, setContractId] = useState(filters.contract_id || '');
    const [filtering, setFiltering] = useState(false);
    const cancel = useRef(null);
    const { errors } = usePage().props;
    useEffect(() => { setContractId(filters.contract_id || ''); }, [filters.contract_id]);
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
                        <div className="flex items-start justify-between gap-3"><div><h2 className="text-base font-semibold text-slate-800">Ritmo de atividades</h2><p className="mt-1 text-xs text-slate-400">Registros dos últimos 6 meses</p></div><span className="overview-soft-icon"><Activity size={18} /></span></div>
                        <div className="mt-5 flex items-baseline gap-2"><strong className="text-4xl font-semibold tracking-tight text-slate-900">{capabilities.activities ? activityTotal.toLocaleString('pt-BR') : '—'}</strong><span className="text-xs text-slate-400">no seu acesso</span></div>
                        {capabilities.activities ? <ActivityTrend rows={charts.activityTrend || []} /> : <p className="my-auto py-8 text-sm text-slate-400">Você não tem acesso às atividades neste filtro.</p>}
                        <div className="mt-5 grid gap-4 border-t border-slate-100 pt-5"><OverviewCount icon={AlertTriangle} label="Vencidas" value={stats.overdueActivities} tone="rose" /><OverviewCount icon={CalendarClock} label="Vencem em até 7 dias" value={stats.activitiesDueSoon} tone="amber" /><OverviewCount icon={CheckCircle2} label="Concluídas" value={completed} tone="green" /></div>
                        {capabilities.activities && <Link href={url('tenant.activities.index')} className="overview-activity-link">Ver atividades<ArrowUpRight size={14} /></Link>}
                    </section>
                    <div className="overview-highlights">
                        <section className="overview-card overview-contract-summary">
                            <div className="overview-contract-heading"><span className="overview-soft-icon"><Building2 size={19} /></span><div><span className="overview-kicker">{selectedContract ? 'Contrato selecionado' : 'Sua operação'}</span><h2>{selectedContract?.code || 'Todos os contratos'}</h2></div></div>
                            <p>{selectedContract?.name || `${stats.contracts} ${stats.contracts === 1 ? 'contrato disponível' : 'contratos disponíveis'} para acompanhamento.`}</p>
                            {capabilities.contracts && <Link href={selectedContract ? route('tenant.contracts.show', [tenant.slug, selectedContract.id]) : route('tenant.contracts.index', tenant.slug)} className="overview-contract-link">{selectedContract ? 'Acessar contrato' : 'Ver contratos'}<ArrowRight size={14} /></Link>}
                        </section>
                        <div className="overview-quick-grid">
                            {osSummary && <ModuleCard compact icon={ClipboardList} title="Ordem de Serviço" tone="cyan" href={url('tenant.os-dnit.orders.index')}
                                value={osSummary.pending} label="pendentes de análise" total={`${osSummary.total} no total`}
                                metrics={[[osSummary.stages.approved, 'Aguardam conclusão'], [osSummary.stages.completed, 'Finalizadas']]}
                                note={`${osSummary.overdue} com prazo vencido`} />}
                            {capabilities.rncs && <ModuleCard compact icon={FileWarning} title="RNC" tone="rose" href={url('tenant.qualidade.rnc.index')}
                                value={stats.openRncs} label="RNCs pendentes" total="Não conformidades"
                                metrics={[[stats.rncsAwaitingSignature, 'Em assinatura'], [stats.overdueRncs, 'Prazo vencido']]}
                                note={`${stats.closedRncs} finalizada(s)`} />}
                            {capabilities.rdo && <ModuleCard compact icon={HardHat} title="RDOs" tone="amber" href={url('tenant.diario-obra.rdo.calendar')}
                                value={stats.rdoAwaitingReview} label="pendentes no fluxo" total="Diário de Obra"
                                metrics={[[stats.totalRdos, 'RDOs registrados']]}
                                note="Ver diários de obra" />}
                        </div>
                    </div>
                </section>
                <section data-tour="overview-modules" className="overview-modules">
                    <div className="overview-section-heading">
                        <div><span className="overview-kicker">Acompanhamento</span><h2>Módulos do contrato</h2><p>{selectedContract ? `${selectedContract.code} · ${selectedContract.name}` : 'Resumo dos contratos aos quais você tem acesso.'}</p></div>
                        <span className="overview-scope-note"><Filter size={12} />{selectedContract ? 'Contrato selecionado' : 'Todos os contratos'}</span>
                    </div>
                    <div className="overview-module-grid">
                        {capabilities.projects && <ModuleCard icon={FolderOpen} title="Projetos" tone="indigo" href={url('tenant.projects.index')}
                            value={stats.activeProjects} label="projetos ativos" total={`${stats.totalProjects} no total`}
                            metrics={[[stats.pendingProjects, 'Em revisão'], [stats.totalProjects - stats.activeProjects - stats.pendingProjects, 'Outras situações']]} />}
                        {capabilities.ics && <ModuleCard icon={ShieldCheck} title="IC" tone="green" href={url('tenant.qualidade.ic.index')}
                            value={stats.openIcs} label="instruções em aberto" total="Instruções de campo"
                            metrics={[[stats.icsAwaitingEvidence, 'Aguardam evidências'], [stats.closedIcs, 'Finalizadas']]} />}
                        {capabilities.measurements && <ModuleCard icon={ReceiptText} title="Medição" tone="teal" href={url('tenant.medicao.boletim-medicao.index')}
                            value={stats.openBoletins} label="boletins em lançamento" total="Boletins de medição"
                            metrics={[[stats.totalBoletins, 'Boletins registrados']]} />}
                        {capabilities.serviceOrders && <ModuleCard icon={ClipboardCheck} title="Liberação de Serviço" tone="violet" href={url('tenant.ordem-servico.os.index')}
                            value={stats.pendingOrders} label="liberações pendentes" total="Análise e aprovação"
                            metrics={[[stats.totalOrders, 'Liberações registradas']]} />}
                        {capabilities.documentation && <ModuleCard icon={Inbox} title="Documentação" tone="slate" href={url('tenant.ged.index')}
                            value={stats.documents} label="documentos no acervo" total="Gestão documental"
                            metrics={[[stats.documentsInProgress, 'Em processamento'], [stats.pendingTriage, 'E-mails em triagem']]} />}
                        {capabilities.budgets && <ModuleCard icon={Calculator} title="Orçamentos" tone="orange" href={route('tenant.orcamentos.index', tenant.slug)}
                            value={stats.draftBudgets} label="orçamentos em elaboração" total="Dados do tenant"
                            metrics={[[stats.closedBudgets, 'Finalizados']]}
                            note="Sem vínculo com contrato; o filtro não se aplica." />}
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

function ModuleCard({ icon: Icon, title, tone, href, value, label, total, metrics, note, compact = false }) {
    return (
        <Link href={href} className={`overview-module overview-module-${tone}${compact ? ' overview-quick' : ''}`} aria-label={`Acessar ${title}`}>
            <div className="overview-module-heading"><span className="overview-module-icon"><Icon size={21} /></span><h3>{title}</h3><ArrowUpRight size={17} className="overview-module-arrow" /></div>
            <div className="overview-module-primary"><strong>{Number(value || 0).toLocaleString('pt-BR')}</strong><span>{label}</span></div>
            <span className="overview-module-context">{total}</span>
            <dl className="overview-module-metrics">{metrics.map(([count, text]) => <div key={text}><dt>{text}</dt><dd>{Number(count || 0).toLocaleString('pt-BR')}</dd></div>)}</dl>
            <div className="overview-module-footer"><span>{note || 'Ver registros'}</span><ArrowRight size={15} /></div>
        </Link>
    );
}
