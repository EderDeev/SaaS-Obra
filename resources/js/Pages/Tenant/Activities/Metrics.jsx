import ActivityTour from '@/Components/ActivityTour';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import ReactECharts from 'echarts-for-react';
import {
    AlertTriangle,
    ArrowLeft,
    BarChart3,
    CalendarCheck2,
    CheckCircle2,
    Clock3,
    Filter,
    Gauge,
    ListTodo,
    RotateCcw,
    Tag,
    TrendingUp,
    UserRound,
    Users,
} from 'lucide-react';
import { useState } from 'react';

const numberFormatter = new Intl.NumberFormat('pt-BR');
const percentFormatter = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 });
const dateFormatter = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' });

const chartColors = ['#2563eb', '#059669', '#f59e0b', '#dc2626', '#64748b', '#7c3aed'];

const statusColors = {
    todo: '#64748b',
    in_progress: '#2563eb',
    review: '#f59e0b',
    done: '#059669',
};

const deadlineColors = {
    on_time: '#059669',
    late: '#f59e0b',
    overdue_open: '#dc2626',
    without_due_date: '#64748b',
};

const resultMeta = {
    on_time: { label: 'No prazo', className: 'sig-pill-green' },
    late: { label: 'Com atraso', className: 'sig-pill-red' },
    without_due_date: { label: 'Sem prazo', className: '' },
};

const periodOptions = [
    { value: '30', label: 'Últimos 30 dias' },
    { value: '90', label: 'Últimos 90 dias' },
    { value: '180', label: 'Últimos 180 dias' },
    { value: '365', label: 'Últimos 12 meses' },
    { value: 'all', label: 'Todo o histórico' },
];

const formatNumber = (value) => numberFormatter.format(Number(value || 0));
const formatPercent = (value) => `${percentFormatter.format(Number(value || 0))}%`;
const formatDate = (value) => value ? dateFormatter.format(new Date(value)) : 'Sem prazo';

const initials = (name = '?') => name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase();

function trendOption(data) {
    return {
        color: ['#2563eb', '#059669'],
        tooltip: { trigger: 'axis' },
        legend: { top: 0, textStyle: { color: '#475569' } },
        grid: { left: 42, right: 18, bottom: 30, top: 44, containLabel: true },
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
            axisLine: { show: false },
            splitLine: { lineStyle: { color: '#edf1f6' } },
            axisLabel: { color: '#64748b' },
        },
        series: [
            {
                name: 'Criadas',
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

function categoryOption(data) {
    return {
        color: ['#2563eb', '#059669'],
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
        legend: { top: 0, textStyle: { color: '#475569' } },
        grid: { left: 118, right: 16, bottom: 22, top: 44, containLabel: false },
        xAxis: {
            type: 'value',
            minInterval: 1,
            splitLine: { lineStyle: { color: '#edf1f6' } },
            axisLabel: { color: '#64748b' },
        },
        yAxis: {
            type: 'category',
            inverse: true,
            data: data.map((item) => item.label),
            axisLine: { lineStyle: { color: '#dbe1ea' } },
            axisLabel: { width: 108, overflow: 'truncate', color: '#475569' },
        },
        series: [
            {
                name: 'Criadas',
                type: 'bar',
                barMaxWidth: 18,
                data: data.map((item) => item.total),
                itemStyle: { borderRadius: [0, 3, 3, 0] },
            },
            {
                name: 'Concluídas',
                type: 'bar',
                barMaxWidth: 18,
                data: data.map((item) => item.completed),
                itemStyle: { borderRadius: [0, 3, 3, 0] },
            },
        ],
    };
}

function donutOption(data, colors) {
    return {
        color: data.map((item, index) => colors[item.key] || chartColors[index % chartColors.length]),
        tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
        legend: {
            type: 'scroll',
            bottom: 0,
            left: 'center',
            textStyle: { color: '#475569' },
        },
        series: [{
            type: 'pie',
            radius: ['48%', '70%'],
            center: ['50%', '43%'],
            minAngle: 4,
            avoidLabelOverlap: true,
            itemStyle: { borderColor: '#fff', borderWidth: 3 },
            label: { show: false },
            emphasis: { label: { show: true, fontWeight: 700, formatter: '{b}\n{c}' } },
            data: data.filter((item) => item.value > 0),
        }],
    };
}

export default function ActivityMetrics({
    tenant,
    filters = {},
    filterOptions = {},
    summary = {},
    charts = {},
    responsibles = [],
    resolvedActivities = [],
    overdueActivities = [],
    tourSection = null,
}) {
    const [form, setForm] = useState({
        period: filters.period || '180',
        contract_id: filters.contract_id ? String(filters.contract_id) : '',
        category: filters.category || '',
        assignee_id: filters.assignee_id ? String(filters.assignee_id) : '',
    });
    const categories = filterOptions.categories || {};
    const contracts = filterOptions.contracts || [];
    const assignees = filterOptions.assignees || [];
    const trend = charts.trend || [];
    const categoryData = charts.categories || [];
    const statusData = charts.statuses || [];
    const deadlineData = charts.deadlines || [];

    const updateFilter = (key, value) => {
        setForm((current) => ({ ...current, [key]: value }));
    };

    const applyFilters = (event) => {
        event.preventDefault();

        if (tourSection) {
            return;
        }

        router.get(route('tenant.activities.metrics', tenant.slug), {
            period: form.period,
            contract_id: form.contract_id || undefined,
            category: form.category || undefined,
            assignee_id: form.assignee_id || undefined,
        }, {
            preserveScroll: true,
            preserveState: false,
            replace: true,
        });
    };

    const clearFilters = () => {
        if (tourSection) {
            return;
        }

        setForm({ period: '180', contract_id: '', category: '', assignee_id: '' });
        router.get(route('tenant.activities.metrics', tenant.slug), { period: '180' }, {
            preserveState: false,
            replace: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Métricas de atividades" />

            <section className="sig-content fade-in">
                <header data-tour="activities-metrics-header" className="flex flex-wrap items-end gap-5">
                    <div className="min-w-0 flex-1">
                        <div className="eyebrow">Workspace · Atividades</div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">Métricas de atividades</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Produtividade, cumprimento de prazos e distribuição das atividades acessíveis.
                        </p>
                    </div>
                    <Link
                        href={tourSection ? '#' : route('tenant.activities.index', tenant.slug)}
                        className="sig-btn sig-btn-secondary"
                        onClick={(event) => tourSection && event.preventDefault()}
                    >
                        <ArrowLeft size={15} />
                        Voltar às atividades
                    </Link>
                </header>

                <form data-tour="activities-metrics-filters" className="sig-card mt-6 grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-[1fr_1.25fr_1fr_1.15fr_auto]" onSubmit={applyFilters}>
                    <FilterField label="Período">
                        <select value={form.period} onChange={(event) => updateFilter('period', event.target.value)}>
                            {periodOptions.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                    </FilterField>
                    <FilterField label="Contrato">
                        <select value={form.contract_id} onChange={(event) => updateFilter('contract_id', event.target.value)}>
                            <option value="">Todos os contratos</option>
                            {contracts.map((contract) => (
                                <option key={contract.id} value={contract.id}>{contract.code} · {contract.name}</option>
                            ))}
                        </select>
                    </FilterField>
                    <FilterField label="Categoria">
                        <select value={form.category} onChange={(event) => updateFilter('category', event.target.value)}>
                            <option value="">Todas as categorias</option>
                            {Object.entries(categories).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </select>
                    </FilterField>
                    <FilterField label="Responsável">
                        <select value={form.assignee_id} onChange={(event) => updateFilter('assignee_id', event.target.value)}>
                            <option value="">Todos os responsáveis</option>
                            {assignees.map((assignee) => (
                                <option key={assignee.id} value={assignee.id}>{assignee.name}</option>
                            ))}
                        </select>
                    </FilterField>
                    <div className="flex items-end gap-2">
                        <button type="submit" className="sig-btn sig-btn-primary">
                            <Filter size={15} />
                            Aplicar
                        </button>
                        <button type="button" className="sig-btn sig-btn-ghost !px-2.5" onClick={clearFilters} title="Limpar filtros">
                            <RotateCcw size={16} />
                        </button>
                    </div>
                </form>

                <div data-tour="activities-metrics-summary" className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <MetricCard
                        icon={ListTodo}
                        label="Criadas no período"
                        value={formatNumber(summary.total)}
                        hint={`${formatNumber(summary.open)} ainda abertas`}
                    />
                    <MetricCard
                        icon={CheckCircle2}
                        label="Taxa de conclusão"
                        value={formatPercent(summary.completion_rate)}
                        hint={`${formatNumber(summary.completed)} concluídas`}
                        tone="green"
                    />
                    <MetricCard
                        icon={CalendarCheck2}
                        label="Conclusões no prazo"
                        value={formatPercent(summary.on_time_rate)}
                        hint="Entre as concluídas com prazo"
                        tone="green"
                    />
                    <MetricCard
                        icon={Clock3}
                        label="Resolução média"
                        value={`${Number(summary.average_resolution_days || 0).toLocaleString('pt-BR')} dias`}
                        hint="Da criação até a conclusão"
                        tone="blue"
                    />
                    <MetricCard
                        icon={AlertTriangle}
                        label="Abertas em atraso"
                        value={formatNumber(summary.overdue_open)}
                        hint="Exigem acompanhamento"
                        tone={summary.overdue_open > 0 ? 'red' : 'green'}
                    />
                </div>

                <div data-tour="activities-metrics-charts" className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,0.65fr)]">
                    <ChartPanel
                        title="Evolução da produtividade"
                        subtitle="Atividades criadas e concluídas por mês no recorte selecionado."
                        icon={TrendingUp}
                    >
                        {trend.length > 0
                            ? <ReactECharts option={trendOption(trend)} style={{ height: 330 }} notMerge lazyUpdate />
                            : <EmptyState height="330px" text="Nenhuma atividade encontrada no período." />}
                    </ChartPanel>

                    <ChartPanel
                        title="Cumprimento de prazos"
                        subtitle="Resultado das conclusões e pendências vencidas."
                        icon={Gauge}
                    >
                        {deadlineData.some((item) => item.value > 0)
                            ? <ReactECharts option={donutOption(deadlineData, deadlineColors)} style={{ height: 330 }} notMerge lazyUpdate />
                            : <EmptyState height="330px" text="Ainda não há dados de prazo." />}
                    </ChartPanel>
                </div>

                <div data-tour="activities-metrics-distribution" className="mt-5 grid gap-5 xl:grid-cols-2">
                    <ChartPanel
                        title="Categorias mais criadas"
                        subtitle="Volume total e quantidade concluída por categoria."
                        icon={Tag}
                    >
                        {categoryData.length > 0
                            ? (
                                <ReactECharts
                                    option={categoryOption(categoryData)}
                                    style={{ height: Math.max(310, categoryData.length * 44) }}
                                    notMerge
                                    lazyUpdate
                                />
                            )
                            : <EmptyState height="310px" text="Nenhuma categoria possui atividades neste recorte." />}
                    </ChartPanel>

                    <ChartPanel
                        title="Situação das atividades"
                        subtitle="Distribuição atual pelas etapas do fluxo."
                        icon={BarChart3}
                    >
                        {statusData.some((item) => item.value > 0)
                            ? <ReactECharts option={donutOption(statusData, statusColors)} style={{ height: 330 }} notMerge lazyUpdate />
                            : <EmptyState height="330px" text="Nenhuma atividade encontrada." />}
                    </ChartPanel>
                </div>

                <section data-tour="activities-metrics-responsibles" className="sig-card mt-5 overflow-hidden">
                    <header className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Produtividade por responsável</h2>
                            <p className="mt-1 text-[12.5px] text-[var(--ink-500)]">
                                Uma atividade com vários responsáveis é contabilizada para cada pessoa vinculada.
                            </p>
                        </div>
                        <span className="sig-pill">
                            <Users size={12} />
                            {formatNumber(responsibles.length)} responsável(is)
                        </span>
                    </header>
                    {responsibles.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[860px] text-left text-sm">
                                <thead className="bg-[var(--surface-muted)] text-[11px] uppercase text-[var(--ink-500)]">
                                    <tr>
                                        <th className="px-5 py-3 font-semibold">Responsável</th>
                                        <th className="px-4 py-3 text-center font-semibold">Atribuídas</th>
                                        <th className="px-4 py-3 text-center font-semibold">Concluídas</th>
                                        <th className="px-4 py-3 text-center font-semibold">No prazo</th>
                                        <th className="px-4 py-3 text-center font-semibold">Com atraso</th>
                                        <th className="px-4 py-3 text-center font-semibold">Abertas</th>
                                        <th className="px-5 py-3 font-semibold">Conclusão</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[var(--border)]">
                                    {responsibles.map((responsible) => (
                                        <tr key={responsible.id || 'unassigned'} className="hover:bg-[var(--surface-muted)]">
                                            <td className="px-5 py-3">
                                                <div className="flex items-center gap-3">
                                                    <UserAvatar user={responsible} />
                                                    <div className="min-w-0">
                                                        <div className="truncate font-semibold text-[var(--ink-900)]">{responsible.name}</div>
                                                        {responsible.email && <div className="truncate text-xs text-[var(--ink-500)]">{responsible.email}</div>}
                                                    </div>
                                                </div>
                                            </td>
                                            <MetricCell value={responsible.total} />
                                            <MetricCell value={responsible.completed} tone="green" />
                                            <MetricCell value={responsible.on_time} tone="green" />
                                            <MetricCell value={responsible.late} tone={responsible.late > 0 ? 'red' : undefined} />
                                            <MetricCell value={responsible.open} tone={responsible.overdue_open > 0 ? 'red' : undefined} />
                                            <td className="px-5 py-3">
                                                <div className="flex items-center gap-3">
                                                    <div className="h-1.5 min-w-[100px] flex-1 overflow-hidden rounded bg-[var(--ink-100)]">
                                                        <div className="h-full rounded bg-[var(--green)]" style={{ width: `${responsible.completion_rate}%` }} />
                                                    </div>
                                                    <span className="mono w-10 text-right text-xs font-semibold text-[var(--ink-700)]">
                                                        {formatPercent(responsible.completion_rate)}
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <EmptyState text="Nenhuma atribuição encontrada para os filtros selecionados." />
                    )}
                </section>

                <div data-tour="activities-metrics-results" className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.25fr)_minmax(320px,0.75fr)]">
                    <section className="sig-card overflow-hidden">
                        <header className="border-b border-[var(--border)] px-5 py-4">
                            <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Conclusões recentes</h2>
                            <p className="mt-1 text-[12.5px] text-[var(--ink-500)]">Quais atividades foram resolvidas no prazo ou com atraso.</p>
                        </header>
                        {resolvedActivities.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[760px] text-left text-sm">
                                    <thead className="bg-[var(--surface-muted)] text-[11px] uppercase text-[var(--ink-500)]">
                                        <tr>
                                            <th className="px-5 py-3 font-semibold">Atividade</th>
                                            <th className="px-4 py-3 font-semibold">Responsável</th>
                                            <th className="px-4 py-3 font-semibold">Prazo</th>
                                            <th className="px-4 py-3 font-semibold">Concluída em</th>
                                            <th className="px-5 py-3 font-semibold">Resultado</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[var(--border)]">
                                        {resolvedActivities.map((activity) => {
                                            const result = resultMeta[activity.result] || resultMeta.without_due_date;

                                            return (
                                                <tr key={activity.id}>
                                                    <td className="max-w-[280px] px-5 py-3">
                                                        <div className="truncate font-semibold text-[var(--ink-900)]">{activity.title}</div>
                                                        <div className="truncate text-xs text-[var(--ink-500)]">{activity.contract}</div>
                                                    </td>
                                                    <td className="max-w-[190px] px-4 py-3 text-xs text-[var(--ink-600)]">
                                                        <span className="block truncate">{activity.responsibles.join(', ') || 'Sem responsável'}</span>
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-3 text-xs text-[var(--ink-600)]">{formatDate(activity.due_date)}</td>
                                                    <td className="whitespace-nowrap px-4 py-3 text-xs text-[var(--ink-600)]">{formatDate(activity.completed_at)}</td>
                                                    <td className="px-5 py-3"><span className={`sig-pill ${result.className}`}>{result.label}</span></td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <EmptyState text="Nenhuma atividade concluída no período." />
                        )}
                    </section>

                    <section className="sig-card overflow-hidden">
                        <header className="border-b border-[var(--border)] px-5 py-4">
                            <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Atividades atrasadas</h2>
                            <p className="mt-1 text-[12.5px] text-[var(--ink-500)]">Pendências abertas ordenadas pelo prazo mais antigo.</p>
                        </header>
                        {overdueActivities.length > 0 ? (
                            <div className="divide-y divide-[var(--border)]">
                                {overdueActivities.map((activity) => (
                                    <div key={activity.id} className="flex items-start gap-3 px-5 py-3.5">
                                        <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[var(--red-50)] text-[var(--red)]">
                                            <AlertTriangle size={14} />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="truncate text-[13px] font-semibold text-[var(--ink-900)]">{activity.title}</div>
                                            <div className="mt-0.5 truncate text-[11.5px] text-[var(--ink-500)]">{activity.contract}</div>
                                            <div className="mt-1 truncate text-[11.5px] text-[var(--ink-500)]">
                                                {activity.responsibles.join(', ') || 'Sem responsável'}
                                            </div>
                                        </div>
                                        <span className="sig-pill sig-pill-red shrink-0">{activity.days_overdue} dia(s)</span>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <EmptyState text="Nenhuma atividade aberta está atrasada." />
                        )}
                    </section>
                </div>
            </section>

            {tourSection && <ActivityTour section={tourSection} tenantSlug={tenant.slug} />}
        </AuthenticatedLayout>
    );
}

function FilterField({ label, children }) {
    return (
        <label className="min-w-0">
            <span className="eyebrow mb-1.5 block">{label}</span>
            <span className="sig-input w-full">{children}</span>
        </label>
    );
}

function MetricCard({ icon: Icon, label, value, hint, tone = 'blue' }) {
    const tones = {
        blue: 'bg-[var(--primary-50)] text-[var(--primary)]',
        green: 'bg-[var(--green-50)] text-[var(--green)]',
        red: 'bg-[var(--red-50)] text-[var(--red)]',
        amber: 'bg-[var(--amber-50)] text-[var(--amber)]',
    };

    return (
        <section className="sig-card p-[18px]">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="eyebrow">{label}</div>
                    <div className="mono mt-2 truncate text-[27px] font-semibold text-[var(--ink-900)]">{value}</div>
                    <p className="mt-1 text-[12px] text-[var(--ink-500)]">{hint}</p>
                </div>
                <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${tones[tone] || tones.blue}`}>
                    <Icon size={16} />
                </span>
            </div>
        </section>
    );
}

function ChartPanel({ title, subtitle, icon: Icon, children }) {
    return (
        <section className="sig-card overflow-hidden">
            <header className="flex items-start gap-3 border-b border-[var(--border)] px-5 py-4">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--surface-muted)] text-[var(--ink-600)]">
                    <Icon size={16} />
                </span>
                <div>
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">{title}</h2>
                    <p className="mt-0.5 text-[12.5px] text-[var(--ink-500)]">{subtitle}</p>
                </div>
            </header>
            <div className="p-4">{children}</div>
        </section>
    );
}

function EmptyState({ text, height = 'auto' }) {
    return (
        <div
            className="flex items-center justify-center px-5 py-8 text-center text-sm text-[var(--ink-500)]"
            style={{ minHeight: height }}
        >
            {text}
        </div>
    );
}

function UserAvatar({ user }) {
    if (user.avatar_url) {
        return <img src={user.avatar_url} alt="" className="sig-avatar shrink-0 object-cover" />;
    }

    return (
        <span className="sig-avatar shrink-0">
            {user.id ? initials(user.name) : <UserRound size={14} />}
        </span>
    );
}

function MetricCell({ value, tone }) {
    const toneClass = tone === 'green'
        ? 'text-[var(--green)]'
        : tone === 'red'
            ? 'text-[var(--red)]'
            : 'text-[var(--ink-800)]';

    return <td className={`mono px-4 py-3 text-center text-sm font-semibold ${toneClass}`}>{formatNumber(value)}</td>;
}
