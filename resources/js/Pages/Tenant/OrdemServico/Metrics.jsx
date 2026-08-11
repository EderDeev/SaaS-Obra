import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import ReactECharts from 'echarts-for-react';
import {
    AlertTriangle,
    ArrowLeft,
    BarChart3,
    CheckCircle2,
    CircleDollarSign,
    ClipboardList,
    Clock3,
    Filter,
    Gauge,
    PlayCircle,
    RotateCcw,
    TrendingUp,
} from 'lucide-react';
import { useState } from 'react';

const numberFormatter = new Intl.NumberFormat('pt-BR');
const currencyFormatter = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    maximumFractionDigits: 2,
});
const percentFormatter = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 });

const periodOptions = [
    { value: '30', label: 'Últimos 30 dias' },
    { value: '90', label: 'Últimos 90 dias' },
    { value: '180', label: 'Últimos 180 dias' },
    { value: '365', label: 'Últimos 12 meses' },
    { value: 'all', label: 'Todo o histórico' },
];

const statusColors = {
    rascunho: '#64748b',
    em_analise: '#2563eb',
    em_aprovacao: '#d97706',
    aprovada: '#0891b2',
    em_execucao: '#7c3aed',
    concluida: '#059669',
    cancelada: '#dc2626',
    recusada: '#be123c',
};

const deadlineColors = {
    overdue: '#dc2626',
    due_soon: '#d97706',
    on_track: '#2563eb',
    completed_on_time: '#059669',
    completed_late: '#be123c',
    without_deadline: '#64748b',
};

const formatNumber = (value) => numberFormatter.format(Number(value || 0));
const formatCurrency = (value) => currencyFormatter.format(Number(value || 0));
const formatPercent = (value) => `${percentFormatter.format(Number(value || 0))}%`;
const formatDays = (value) => Number(value || 0) === 1 ? '1 dia' : `${Number(value || 0).toLocaleString('pt-BR')} dias`;

function emptyChartGraphic(message = 'Sem dados no período') {
    return [{
        type: 'text',
        left: 'center',
        top: 'middle',
        style: { text: message, fill: '#64748b', fontSize: 13 },
    }];
}

function donutOption(data, colors) {
    const visibleData = data.filter((item) => Number(item.value) > 0);

    return {
        color: visibleData.map((item) => colors[item.key] || '#64748b'),
        tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
        legend: {
            type: 'scroll',
            bottom: 0,
            left: 'center',
            textStyle: { color: '#475569' },
        },
        graphic: visibleData.length ? undefined : emptyChartGraphic(),
        series: visibleData.length ? [{
            type: 'pie',
            radius: ['48%', '70%'],
            center: ['50%', '42%'],
            minAngle: 4,
            itemStyle: { borderColor: '#fff', borderWidth: 3 },
            label: { show: false },
            emphasis: { label: { show: true, fontWeight: 700, formatter: '{b}\n{c}' } },
            data: visibleData,
        }] : [],
    };
}

function trendOption(data) {
    return {
        color: ['#2563eb', '#059669'],
        tooltip: { trigger: 'axis' },
        legend: { top: 0, textStyle: { color: '#475569' } },
        grid: { left: 36, right: 18, bottom: 28, top: 44, containLabel: true },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: data.map((item) => item.label),
            axisLine: { lineStyle: { color: '#dbe1ea' } },
            axisLabel: { color: '#64748b' },
        },
        yAxis: {
            type: 'value',
            minInterval: 1,
            splitLine: { lineStyle: { color: '#edf1f6' } },
            axisLabel: { color: '#64748b' },
        },
        series: [
            {
                name: 'Abertas',
                type: 'line',
                smooth: true,
                symbolSize: 7,
                data: data.map((item) => item.created),
                areaStyle: { opacity: 0.08 },
            },
            {
                name: 'Concluídas',
                type: 'line',
                smooth: true,
                symbolSize: 7,
                data: data.map((item) => item.completed),
            },
        ],
    };
}

function financialOption(data) {
    const visibleData = data.filter((item) => Number(item.planned) > 0 || Number(item.actual) > 0);

    return {
        color: ['#1d4ed8', '#059669'],
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            valueFormatter: (value) => formatCurrency(value),
        },
        legend: { top: 0, textStyle: { color: '#475569' } },
        grid: { left: 84, right: 18, bottom: 24, top: 44, containLabel: false },
        graphic: visibleData.length ? undefined : emptyChartGraphic('Sem valores financeiros no período'),
        xAxis: {
            type: 'value',
            splitLine: { lineStyle: { color: '#edf1f6' } },
            axisLabel: {
                color: '#64748b',
                formatter: (value) => `${numberFormatter.format(value / 1000)} mil`,
            },
        },
        yAxis: {
            type: 'category',
            inverse: true,
            data: visibleData.map((item) => item.label),
            axisLabel: { color: '#475569', width: 74, overflow: 'truncate' },
            axisLine: { lineStyle: { color: '#dbe1ea' } },
        },
        series: visibleData.length ? [
            {
                name: 'Previsto',
                type: 'bar',
                barMaxWidth: 16,
                data: visibleData.map((item) => item.planned),
                itemStyle: { borderRadius: [0, 3, 3, 0] },
            },
            {
                name: 'Real medido',
                type: 'bar',
                barMaxWidth: 16,
                data: visibleData.map((item) => item.actual),
                itemStyle: { borderRadius: [0, 3, 3, 0] },
            },
        ] : [],
    };
}

function MetricCard({ icon: Icon, label, value, hint, tone = 'blue' }) {
    const tones = {
        blue: 'bg-blue-50 text-blue-700',
        amber: 'bg-amber-50 text-amber-700',
        violet: 'bg-violet-50 text-violet-700',
        green: 'bg-emerald-50 text-emerald-700',
        red: 'bg-red-50 text-red-700',
        cyan: 'bg-cyan-50 text-cyan-700',
    };

    return (
        <article className="sig-card min-w-0 p-4">
            <div className={`flex h-9 w-9 items-center justify-center rounded-md ${tones[tone]}`}>
                <Icon size={18} />
            </div>
            <div className="mt-4 text-[11px] font-semibold uppercase text-[var(--ink-500)]">{label}</div>
            <div className="mt-1 text-2xl font-semibold text-[var(--ink-900)]">{value}</div>
            <div className="mt-1 truncate text-xs text-[var(--ink-500)]" title={hint}>{hint}</div>
        </article>
    );
}

function ChartPanel({ title, subtitle, children }) {
    return (
        <section className="sig-card min-w-0 p-5">
            <h2 className="text-sm font-semibold text-[var(--ink-900)]">{title}</h2>
            <p className="mt-1 text-xs text-[var(--ink-500)]">{subtitle}</p>
            <div className="mt-3 h-[310px] min-w-0">{children}</div>
        </section>
    );
}

function AttentionList({ title, subtitle, icon: Icon, rows, emptyText, variant }) {
    return (
        <section className="sig-card min-w-0 overflow-hidden">
            <header className="flex items-center gap-3 border-b border-[var(--line)] px-5 py-4">
                <div className={`flex h-9 w-9 items-center justify-center rounded-md ${variant === 'danger' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700'}`}>
                    <Icon size={18} />
                </div>
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold text-[var(--ink-900)]">{title}</h2>
                    <p className="mt-0.5 text-xs text-[var(--ink-500)]">{subtitle}</p>
                </div>
            </header>
            {rows.length ? (
                <div className="divide-y divide-[var(--line)]">
                    {rows.map((row) => (
                        <Link key={row.id} href={row.url} className="flex items-center gap-4 px-5 py-3 transition-colors hover:bg-slate-50">
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-sm font-semibold text-blue-700">{row.code}</div>
                                <div className="mt-0.5 truncate text-xs text-[var(--ink-600)]">{row.title}</div>
                                <div className="mt-1 truncate text-[11px] text-[var(--ink-400)]">{row.contract} · {row.obra}</div>
                            </div>
                            <div className={`shrink-0 text-right text-xs font-semibold ${variant === 'danger' ? 'text-red-700' : 'text-amber-700'}`}>
                                {formatDays(row.days)}
                                <div className="mt-0.5 text-[10px] font-normal text-[var(--ink-400)]">{row.deadline || row.stage}</div>
                            </div>
                        </Link>
                    ))}
                </div>
            ) : (
                <div className="px-5 py-9 text-center text-sm text-[var(--ink-500)]">{emptyText}</div>
            )}
        </section>
    );
}

export default function Metrics({
    tenant,
    filters = {},
    filterOptions = {},
    summary = {},
    averageTimes = {},
    charts = {},
    attention = {},
}) {
    const [form, setForm] = useState({
        period: filters.period || '180',
        contract_id: filters.contract_id ? String(filters.contract_id) : '',
    });
    const contracts = filterOptions.contracts || [];
    const statusData = charts.statuses || [];
    const deadlineData = charts.deadlines || [];
    const trendData = charts.trend || [];
    const financialData = charts.financial_by_contract || [];
    const overdueRows = attention.overdue || [];
    const reviewRows = attention.review || [];

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(route('tenant.ordem-servico.metrics.index', tenant.slug), {
            period: form.period,
            contract_id: form.contract_id || undefined,
        }, { preserveScroll: true, replace: true });
    };

    const clearFilters = () => {
        setForm({ period: '180', contract_id: '' });
        router.get(route('tenant.ordem-servico.metrics.index', tenant.slug), { period: '180' }, {
            replace: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Métricas da Ordem de Serviço" />

            <section className="sig-content fade-in">
                <header className="flex flex-wrap items-end gap-5">
                    <div className="min-w-0 flex-1">
                        <div className="eyebrow">Ordem de Serviço</div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">Métricas</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Custos, prazos e evolução das ordens de serviço acessíveis.
                        </p>
                    </div>
                    <Link href={route('tenant.ordem-servico.os.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                        <ArrowLeft size={15} />
                        Voltar para OS
                    </Link>
                </header>

                <form className="sig-card mt-5 grid gap-4 p-4 md:grid-cols-[1fr_1.5fr_auto]" onSubmit={applyFilters}>
                    <label className="sig-field min-w-0">
                        <span>Período</span>
                        <select className="w-full min-w-0" value={form.period} onChange={(event) => setForm((current) => ({ ...current, period: event.target.value }))}>
                            {periodOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </select>
                    </label>
                    <label className="sig-field min-w-0">
                        <span>Contrato</span>
                        <select className="w-full min-w-0" value={form.contract_id} onChange={(event) => setForm((current) => ({ ...current, contract_id: event.target.value }))}>
                            <option value="">Todos os contratos acessíveis</option>
                            {contracts.map((contract) => (
                                <option key={contract.id} value={contract.id}>{contract.code} - {contract.name}</option>
                            ))}
                        </select>
                    </label>
                    <div className="flex items-end gap-2">
                        <button type="submit" className="sig-btn sig-btn-primary"><Filter size={15} /> Aplicar</button>
                        <button type="button" className="sig-btn sig-btn-secondary" onClick={clearFilters} title="Limpar filtros"><RotateCcw size={15} /></button>
                    </div>
                </form>

                <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    <MetricCard icon={ClipboardList} label="OS no período" value={formatNumber(summary.total)} hint={`${formatNumber(summary.active)} em fluxo`} />
                    <MetricCard icon={Clock3} label="Aguardando decisão" value={formatNumber(summary.awaiting_decision)} hint="Em análise ou aprovação" tone="amber" />
                    <MetricCard icon={PlayCircle} label="Em execução" value={formatNumber(summary.in_execution)} hint="Execução em andamento" tone="violet" />
                    <MetricCard icon={CheckCircle2} label="Concluídas" value={formatNumber(summary.completed)} hint={`${formatPercent(summary.completion_rate)} do período`} tone="green" />
                    <MetricCard icon={AlertTriangle} label="Prazos vencidos" value={formatNumber(summary.overdue)} hint="OS abertas fora do prazo" tone="red" />
                    <MetricCard icon={Gauge} label="Avanço financeiro" value={formatPercent(summary.financial_progress)} hint={`${formatCurrency(summary.actual_cost)} medido`} tone="cyan" />
                </div>

                <section className="sig-card mt-5 grid divide-y divide-[var(--line)] overflow-hidden md:grid-cols-3 md:divide-x md:divide-y-0">
                    <div className="flex items-center gap-4 p-5">
                        <div className="flex h-10 w-10 items-center justify-center rounded-md bg-blue-50 text-blue-700"><Clock3 size={19} /></div>
                        <div><div className="text-xs font-semibold uppercase text-[var(--ink-500)]">Tempo médio de análise</div><div className="mt-1 text-xl font-semibold text-[var(--ink-900)]">{formatDays(averageTimes.analysis_days)}</div></div>
                    </div>
                    <div className="flex items-center gap-4 p-5">
                        <div className="flex h-10 w-10 items-center justify-center rounded-md bg-amber-50 text-amber-700"><CheckCircle2 size={19} /></div>
                        <div><div className="text-xs font-semibold uppercase text-[var(--ink-500)]">Tempo médio de aprovação</div><div className="mt-1 text-xl font-semibold text-[var(--ink-900)]">{formatDays(averageTimes.approval_days)}</div></div>
                    </div>
                    <div className="flex items-center gap-4 p-5">
                        <div className="flex h-10 w-10 items-center justify-center rounded-md bg-emerald-50 text-emerald-700"><TrendingUp size={19} /></div>
                        <div><div className="text-xs font-semibold uppercase text-[var(--ink-500)]">Tempo médio de execução</div><div className="mt-1 text-xl font-semibold text-[var(--ink-900)]">{formatDays(averageTimes.execution_days)}</div></div>
                    </div>
                </section>

                <div className="mt-5 grid gap-5 xl:grid-cols-[1.4fr_0.8fr]">
                    <ChartPanel title="Evolução mensal" subtitle="Ordens abertas e concluídas por mês.">
                        <ReactECharts option={trendOption(trendData)} style={{ height: '100%', width: '100%' }} notMerge />
                    </ChartPanel>
                    <ChartPanel title="Distribuição por status" subtitle="Situação atual das ordens do período.">
                        <ReactECharts option={donutOption(statusData, statusColors)} style={{ height: '100%', width: '100%' }} notMerge />
                    </ChartPanel>
                </div>

                <div className="mt-5 grid gap-5 xl:grid-cols-[0.8fr_1.4fr]">
                    <ChartPanel title="Cumprimento de prazos" subtitle="Prazos das ordens abertas e concluídas.">
                        <ReactECharts option={donutOption(deadlineData, deadlineColors)} style={{ height: '100%', width: '100%' }} notMerge />
                    </ChartPanel>
                    <ChartPanel title="Previsto x real medido" subtitle="Comparação financeira por contrato.">
                        <ReactECharts option={financialOption(financialData)} style={{ height: '100%', width: '100%' }} notMerge />
                    </ChartPanel>
                </div>

                <div className="mt-5 grid gap-5 xl:grid-cols-2">
                    <AttentionList title="Prazos vencidos" subtitle="Ordens abertas que exigem atenção imediata." icon={AlertTriangle} rows={overdueRows} emptyText="Nenhuma OS aberta com prazo vencido." variant="danger" />
                    <AttentionList title="Análises mais antigas" subtitle="Ordens aguardando análise ou aprovação há mais tempo." icon={BarChart3} rows={reviewRows} emptyText="Nenhuma OS aguardando decisão." variant="warning" />
                </div>

                <section className="sig-card mt-5 flex flex-wrap items-center justify-between gap-4 p-5">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-md bg-emerald-50 text-emerald-700"><CircleDollarSign size={19} /></div>
                        <div><div className="text-xs font-semibold uppercase text-[var(--ink-500)]">Custo previsto</div><div className="mt-1 text-lg font-semibold text-[var(--ink-900)]">{formatCurrency(summary.planned_cost)}</div></div>
                    </div>
                    <div className="text-right"><div className="text-xs font-semibold uppercase text-[var(--ink-500)]">Custo real medido</div><div className="mt-1 text-lg font-semibold text-emerald-700">{formatCurrency(summary.actual_cost)}</div></div>
                </section>
            </section>
        </AuthenticatedLayout>
    );
}
