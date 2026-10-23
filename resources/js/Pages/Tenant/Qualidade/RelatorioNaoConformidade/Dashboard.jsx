import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import ReactECharts from 'echarts-for-react';
import { AlertTriangle, ArrowRight, BarChart3, CheckCircle2, ClipboardX, Clock3, Gauge, SearchCheck, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Errors } from '../InstrucaoCampo/Ui';

const shortDate = (date) => date ? new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(/^\d{4}-\d{2}-\d{2}$/.test(date) ? `${date}T12:00:00` : date)) : '—';
const chartColors = ['#0b5fff', '#11805a', '#b58105', '#c8364a', '#64748b', '#7c3aed', '#0891b2'];
const metricCards = [
    ['total', 'Total de RNCs', ClipboardX, 'blue'],
    ['resolvidas', 'Resolvidas', CheckCircle2, 'green'],
    ['parcialmenteResolvidas', 'Parcialmente resolvidas', SearchCheck, 'amber'],
    ['naoResolvidas', 'Não resolvidas', AlertTriangle, 'red'],
    ['finalizadas', 'Finalizadas', CheckCircle2, 'green'],
    ['emAnalise', 'Em análise da proposta', SearchCheck, 'amber'],
    ['aguardandoEvidencia', 'Aguardando evidências', Clock3, 'amber'],
    ['aguardandoAssinaturas', 'Aguardando assinaturas', Clock3, 'amber'],
    ['atrasoResposta', 'Atraso de resposta', AlertTriangle, 'red'],
    ['atrasoExecucao', 'Atraso de execução', AlertTriangle, 'red'],
];

export default function RelatorioNaoConformidadeDashboard({ tenant, contracts = [], obras = [], disciplinas = [], filters = {}, statuses = {}, metrics, resolutionCounts = {}, statusCounts, gravidadeCounts, disciplinaCounts, monthlyCounts, monthlyRange, responseOverdueRncs = [], executionOverdueRncs = [] }) {
    const [values, setValues] = useState(filters);
    const current = useRef(filters);
    const cancel = useRef(null);
    const { errors } = usePage().props;
    useEffect(() => { current.current = filters; setValues(filters); }, [filters]);
    useEffect(() => () => cancel.current?.cancel(), []);
    const apply = (changes, reset = false) => {
        cancel.current?.cancel();
        const next = reset ? {} : { ...current.current, ...changes };
        current.current = next;
        setValues(next);
        router.get(route('tenant.qualidade.rnc.dashboard', tenant.slug), Object.fromEntries(Object.entries(next).filter(([, value]) => value !== '' && value != null)), {
            preserveScroll: true, preserveState: true, replace: true,
            onCancelToken: (token) => { cancel.current = token; },
        });
    };
    const options = (items) => items.filter((item) => !values.contract_id || String(item.contract_id) === String(values.contract_id));
    return <AuthenticatedLayout><Head title="Dashboard RNC" /><main className="sig-content quality-mobile-page grid gap-6">
        <header className="quality-page-header flex flex-wrap items-start justify-between gap-4">
            <div className="min-w-0"><div className="eyebrow flex items-center gap-2"><Gauge size={14} />Relatório de Não Conformidade</div><h1 className="mt-2 text-2xl font-semibold">Dashboard RNC</h1><p className="mt-1 text-sm text-[var(--ink-500)]">Acompanhe as correções, os resultados e as assinaturas das RNCs em {tenant.name}.</p></div>
            <Link href={route('tenant.qualidade.rnc.index', tenant.slug)} className="sig-btn sig-btn-secondary">Ver RNCs<ArrowRight size={15} /></Link>
        </header>
        <section aria-label="Filtros do dashboard RNC" className="sig-card grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-4">
            <label className="grid min-w-0 gap-2 text-sm font-medium">Contrato<select className="sig-input w-full min-w-0" value={values.contract_id || ''} onChange={(event) => apply({ contract_id: event.target.value, obra_id: '', disciplina_id: '' })}><option value="">Todos os contratos</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} · {contract.name}</option>)}</select></label>
            <label className="grid min-w-0 gap-2 text-sm font-medium">Obra<select className="sig-input w-full min-w-0" value={values.obra_id || ''} onChange={(event) => apply({ obra_id: event.target.value })}><option value="">Todas as obras</option>{options(obras).map((obra) => <option key={obra.id} value={obra.id}>{obra.codigo} · {obra.nome}</option>)}</select></label>
            <label className="grid min-w-0 gap-2 text-sm font-medium">Disciplina<select className="sig-input w-full min-w-0" value={values.disciplina_id || ''} onChange={(event) => apply({ disciplina_id: event.target.value })}><option value="">Todas as disciplinas</option>{options(disciplinas).map((disciplina) => <option key={disciplina.id} value={disciplina.id}>{disciplina.nome}</option>)}</select></label>
            <label className="grid min-w-0 gap-2 text-sm font-medium">Gravidade<select className="sig-input w-full min-w-0" value={values.gravidade || ''} onChange={(event) => apply({ gravidade: event.target.value })}><option value="">Todas as gravidades</option>{['Leve', 'Média', 'Grave', 'Gravíssima'].map((value) => <option key={value}>{value}</option>)}</select></label>
            <label className="grid min-w-0 gap-2 text-sm font-medium">Situação<select className="sig-input w-full min-w-0" value={values.status || ''} onChange={(event) => apply({ status: event.target.value })}><option value="">Todas as situações</option>{Object.entries(statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
            <label className="grid min-w-0 gap-2 text-sm font-medium">Resultado<select className="sig-input w-full min-w-0" value={values.resolution_result || ''} onChange={(event) => apply({ resolution_result: event.target.value })}><option value="">Todos os resultados</option><option value="yes">Resolvidas</option><option value="partial">Parcialmente resolvidas</option><option value="no">Não resolvidas</option></select></label>
            <label className="grid min-w-0 gap-2 text-sm font-medium">Abertura: de<input type="date" className="sig-input w-full min-w-0" value={values.date_from || ''} max={values.date_to || undefined} onChange={(event) => apply({ date_from: event.target.value })} /></label>
            <label className="grid min-w-0 gap-2 text-sm font-medium">Abertura: até<input type="date" className="sig-input w-full min-w-0" value={values.date_to || ''} min={values.date_from || undefined} onChange={(event) => apply({ date_to: event.target.value })} /></label>
            {Object.values(values).some((value) => value !== '' && value != null) && <div className="sm:col-span-2 xl:col-span-4"><button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" onClick={() => apply({}, true)}><X size={14} />Limpar filtros</button></div>}
        </section>
        <Errors errors={errors} />
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 2xl:grid-cols-5">{metricCards.map(([key, label, Icon, tone]) => <MetricCard key={key} icon={Icon} label={label} value={metrics[key] || 0} tone={tone} />)}</div>
        <div className="grid items-start gap-5 xl:grid-cols-2">
            <section className="sig-card min-w-0 p-5"><h2 className="text-base font-semibold">Resultado das correções</h2><div className="mt-4"><ResolutionPie counts={resolutionCounts} /></div></section>
            <BarPanel title="Situação das RNCs" counts={statusCounts} />
            <section className="grid min-w-0 gap-2"><ColumnPanel title="Aberturas por mês" counts={monthlyCounts} /><p className="px-1 text-xs text-[var(--ink-500)]">Últimos 6 meses até a data final do período: {monthlyRange}. Os filtros também se aplicam a este gráfico.</p></section>
            <BarPanel title="Gravidade" counts={gravidadeCounts} />
            <BarPanel title="Disciplina" counts={disciplinaCounts} />
        </div>
        <div className="grid items-start gap-5 xl:grid-cols-2">
            <DelayPanel tenant={tenant} title="Resposta de ação corretiva vencida" eyebrow="Atraso de resposta" items={responseOverdueRncs} type="response" />
            <DelayPanel tenant={tenant} title="Execução da ação fora do prazo" eyebrow="Atraso de execução" items={executionOverdueRncs} type="execution" />
        </div>
    </main></AuthenticatedLayout>;
}

function ResolutionPie({ counts }) {
    const rows = [
        { name: 'Resolvidas', value: counts.yes || 0, color: '#15803d' },
        { name: 'Parcialmente resolvidas', value: counts.partial || 0, color: '#b45309' },
        { name: 'Não resolvidas', value: counts.no || 0, color: '#b91c1c' },
    ];
    const total = rows.reduce((sum, row) => sum + row.value, 0);
    return <div className="grid gap-4">
        {total ? <ReactECharts option={{
            color: rows.map((row) => row.color),
            aria: { enabled: true },
            tooltip: { trigger: 'item', renderMode: 'richText', formatter: '{b}: {c} ({d}%)' },
            series: [{ type: 'pie', radius: '82%', center: ['50%', '50%'],
                data: rows.filter((row) => row.value > 0).map((row) => ({ name: row.name, value: row.value, itemStyle: { color: row.color } })),
                label: { position: 'inside', formatter: '{d}%', color: '#fff', fontWeight: 600 },
                minShowLabelAngle: 12, itemStyle: { borderColor: '#fff', borderWidth: 2 },
                emphasis: { scaleSize: 6 },
            }],
        }} style={{ height: 260, width: '100%' }} notMerge />
            : <p className="flex min-h-48 items-center justify-center text-center text-sm text-[var(--ink-500)]">Nenhuma RNC com resultado informado para estes filtros.</p>}
        <ul className="grid gap-3">{rows.map((row) => <li key={row.name} className="flex items-center justify-between gap-3 text-sm"><span className="flex min-w-0 items-center gap-2"><span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: row.color }} /><span>{row.name}</span></span><span className="shrink-0 tabular-nums"><strong>{row.value}</strong><span className="ml-2 text-[var(--ink-500)]">{(total ? row.value / total * 100 : 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%</span></span></li>)}</ul>
        <p className="text-xs text-[var(--ink-500)]">{total} RNC(s) com resultado informado nas evidências.</p>
    </div>;
}

function DelayPanel({ tenant, title, eyebrow, items, type }) {
    const isExecution = type === 'execution';
    const emptyText = isExecution
        ? 'Nenhuma execucao atrasada no momento.'
        : 'Nenhuma resposta atrasada no momento.';

    return (
        <section className="sig-card overflow-hidden">
            <header className="border-b border-[var(--border)] px-5 py-4">
                <div className="flex items-center gap-2 text-[var(--ink-500)]">
                    <AlertTriangle size={14} />
                    <span className="eyebrow">{eyebrow}</span>
                </div>
                <h2 className="mt-1 text-[15px] font-semibold">{title}</h2>
            </header>
            {items.length > 0 ? (
                <div className="grid gap-3 p-4">
                    {items.map((rnc) => {
                        const latestAction = rnc.acoes_corretivas?.[0];
                        const approvedAction = latestApprovedAction(rnc);
                        const evidence = evidenceForAction(rnc, approvedAction);
                        const deadline = isExecution ? approvedAction?.prazo_execucao_proposto : rnc.prazo_resposta_acao_corretiva;
                        const completedLate = isExecution ? evidence?.submitted_at : latestAction?.submitted_at;
                        const badge = completedLate ? 'Fora do prazo' : 'Vencida';

                        return (
                            <Link
                                key={rnc.id}
                                href={route('tenant.qualidade.rnc.show', [tenant.slug, rnc.id])}
                                className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3 hover:border-[var(--primary)]"
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <span className="mono font-semibold text-[var(--ink-900)]">{rnc.formatted_number}</span>
                                    <span className="sig-pill sig-pill-red">{badge}</span>
                                </div>
                                <div className="mt-2 text-[13px] font-semibold text-[var(--ink-900)]">
                                    {rnc.assunto || rnc.descricao_problema}
                                </div><div className="mt-1 text-xs text-[var(--ink-500)]">{rnc.obra?.codigo || '-'} - {rnc.obra?.nome || '-'}
                                </div>
                                <div className="mt-1 text-[12px] text-[var(--ink-500)]">
                                    {isExecution ? 'Prazo execucao' : 'Prazo resposta'}: {shortDate(deadline)}
                                </div>
                                {isExecution ? (
                                    <div className="mt-1 text-[12px] text-[var(--ink-500)]">
                                        Evidencia: {evidence?.submitted_at ? shortDate(evidence.submitted_at) : 'nao enviada'}
                                    </div>
                                ) : latestAction?.submitted_at ? (
                                    <div className="mt-1 text-[12px] text-[var(--ink-500)]">
                                        Resposta: {shortDate(latestAction.submitted_at)}
                                    </div>
                                ) : null}
                            </Link>
                        );
                    })}
                </div>
            ) : (
                <EmptyState text={emptyText} />
            )}
        </section>
    );
}

function latestApprovedAction(rnc) {
    return rnc.acoes_corretivas?.find((acao) => acao.status === 'approved');
}

function evidenceForAction(rnc, action) {
    return rnc.evidencias?.find((evidencia) => evidencia.relatorio_nao_conformidade_acao_corretiva_id === action?.id)
        || rnc.evidencias?.[0];
}

function MetricCard({ icon: Icon, label, value, tone }) {
    const tones = {
        blue: 'text-[var(--primary)] bg-[var(--blue-50)]',
        red: 'text-[var(--red)] bg-[var(--red-50)]',
        amber: 'text-[var(--amber)] bg-[var(--amber-50)]',
        green: 'text-[var(--green)] bg-[var(--green-50)]',
    };

    return (
        <section className="sig-card min-w-0 p-3 sm:p-4">
            <div className="flex items-center justify-between gap-3">
                <div className="min-w-0">
                    <div className="eyebrow break-words">{label}</div>
                    <div className="mt-2 text-2xl font-semibold text-[var(--ink-900)] sm:text-3xl">{value}</div>
                </div>
                <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg sm:h-11 sm:w-11 ${tones[tone] || tones.blue}`}>
                    <Icon size={21} />
                </div>
            </div>
        </section>
    );
}

function BarPanel({ title, counts }) {
    const entries = Object.entries(counts || {});
    const max = Math.max(1, ...entries.map(([, value]) => value));

    return (
        <section className="sig-card p-5">
            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                <BarChart3 size={14} />
                <span className="eyebrow">{title}</span>
            </div>
            <div className="mt-4 grid gap-3">
                {entries.length > 0 ? entries.map(([label, value]) => (
                    <div key={label}>
                        <div className="flex items-center justify-between gap-3 text-[12.5px]">
                            <span className="font-semibold text-[var(--ink-800)]">{label}</span>
                            <span className="mono text-[var(--ink-500)]">{value}</span>
                        </div>
                        <div className="mt-1 h-2 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                            <div
                                className="h-full rounded-full bg-[var(--primary)]"
                                style={{ width: `${(value / max) * 100}%` }}
                            />
                        </div>
                    </div>
                )) : (
                    <div className="rounded-lg border border-dashed border-[var(--border-strong)] p-6 text-center text-sm text-[var(--ink-500)]">
                        Sem dados.
                    </div>
                )}
            </div>
        </section>
    );
}

function ColumnPanel({ title, counts }) {
    const entries = Object.entries(counts || {});
    const max = Math.max(1, ...entries.map(([, value]) => value));

    return (
        <section className="sig-card p-5">
            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                <BarChart3 size={14} />
                <span className="eyebrow">{title}</span>
            </div>
            <div className="mt-5 flex h-56 items-end gap-3 border-b border-[var(--border)] px-1 pb-2">
                {entries.length > 0 ? entries.map(([label, value], index) => (
                    <div key={label} className="flex min-w-0 flex-1 flex-col items-center gap-2">
                        <span className="mono text-[11px] font-semibold text-[var(--ink-600)]">{value}</span>
                        <div className="flex h-40 w-full items-end rounded-t-lg bg-[var(--surface-muted)]">
                            <div
                                className="w-full rounded-t-lg"
                                style={{
                                    height: `${Math.max(value > 0 ? 10 : 0, (value / max) * 100)}%`,
                                    background: chartColors[index % chartColors.length],
                                }}
                            />
                        </div>
                        <span className="max-w-full truncate text-[11px] font-semibold text-[var(--ink-500)]">{label}</span>
                    </div>
                )) : (
                    <div className="flex h-full w-full items-center justify-center text-sm text-[var(--ink-500)]">
                        Sem dados para montar o grafico.
                    </div>
                )}
            </div>
        </section>
    );
}

function EmptyState({ text }) {
    return (
        <div className="p-10 text-center text-sm text-[var(--ink-500)]">
            {text}
        </div>
    );
}
