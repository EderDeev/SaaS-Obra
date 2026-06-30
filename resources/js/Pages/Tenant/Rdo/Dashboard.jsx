import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import ReactECharts from 'echarts-for-react';
import { CalendarDays, CheckCircle2, ClipboardList, Eye, FileSignature, RotateCcw, Send, TrendingUp } from 'lucide-react';

const number = new Intl.NumberFormat('pt-BR');

function formatNumber(value) {
    return number.format(Number(value || 0));
}

function KpiCard({ label, value, hint, icon: Icon, tone = 'blue' }) {
    const tones = {
        blue: 'bg-blue-50 text-blue-700',
        green: 'bg-emerald-50 text-emerald-700',
        amber: 'bg-amber-50 text-amber-700',
        red: 'bg-red-50 text-red-700',
        violet: 'bg-violet-50 text-violet-700',
    };

    return (
        <div className="rounded-2xl border border-[var(--border)] bg-white p-5 shadow-sm">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.16em] text-[var(--ink-500)]">{label}</p>
                    <p className="mt-2 text-2xl font-black text-[var(--ink-900)]">{value}</p>
                    {hint && <p className="mt-1 text-sm text-[var(--ink-500)]">{hint}</p>}
                </div>
                {Icon && (
                    <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl ${tones[tone] || tones.blue}`}>
                        <Icon size={19} />
                    </span>
                )}
            </div>
        </div>
    );
}

function ChartCard({ title, subtitle, icon: Icon, children }) {
    return (
        <section className="rounded-2xl border border-[var(--border)] bg-white p-5 shadow-sm">
            <div className="mb-4 flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-base font-black text-[var(--ink-900)]">{title}</h2>
                    {subtitle && <p className="mt-1 text-sm text-[var(--ink-500)]">{subtitle}</p>}
                </div>
                {Icon && (
                    <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                        <Icon size={18} />
                    </span>
                )}
            </div>
            {children}
        </section>
    );
}

function EmptyChart() {
    return (
        <div className="flex h-[280px] items-center justify-center rounded-xl border border-dashed border-[var(--border)] bg-[var(--surface-muted)] text-sm font-semibold text-[var(--ink-500)]">
            Sem dados para o filtro selecionado.
        </div>
    );
}

function statusChartOption(data) {
    return {
        tooltip: { trigger: 'item' },
        legend: { bottom: 0, type: 'scroll' },
        series: [{
            name: 'RDOs',
            type: 'pie',
            radius: ['45%', '70%'],
            center: ['50%', '44%'],
            avoidLabelOverlap: true,
            label: { formatter: '{b}: {c}' },
            data,
        }],
    };
}

function dailyChartOption(data) {
    return {
        tooltip: { trigger: 'axis' },
        legend: { top: 0 },
        grid: { left: 40, right: 16, bottom: 30, top: 42 },
        xAxis: { type: 'category', data: data.map((item) => item.date), boundaryGap: false },
        yAxis: { type: 'value', minInterval: 1 },
        series: [
            { name: 'Criados', type: 'line', smooth: true, data: data.map((item) => item.criados), areaStyle: {} },
            { name: 'Enviados', type: 'line', smooth: true, data: data.map((item) => item.enviados) },
            { name: 'Aprovados', type: 'line', smooth: true, data: data.map((item) => item.aprovados) },
        ],
    };
}

function obrasChartOption(data) {
    return {
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
        legend: { top: 0 },
        grid: { left: 120, right: 16, bottom: 24, top: 44 },
        xAxis: { type: 'value', minInterval: 1 },
        yAxis: {
            type: 'category',
            data: data.map((item) => item.label),
            axisLabel: { width: 110, overflow: 'truncate' },
        },
        series: [
            { name: 'Total', type: 'bar', data: data.map((item) => item.total) },
            { name: 'Em análise', type: 'bar', data: data.map((item) => item.em_analise) },
            { name: 'Aprovados', type: 'bar', data: data.map((item) => item.aprovados) },
        ],
    };
}

export default function Dashboard({ contracts = [], filters = {}, dashboard = {} }) {
    const { currentTenant } = usePage().props;
    const cards = dashboard.cards || {};
    const charts = dashboard.charts || {};
    const recent = dashboard.recent || [];

    const changeFilter = (key, value) => {
        router.get(route('tenant.diario-obra.rdo.dashboard', currentTenant.slug), {
            ...filters,
            [key]: value || undefined,
        }, {
            preserveScroll: true,
            preserveState: false,
            replace: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard RDO" />

            <div className="mx-auto max-w-[1700px] px-4 py-6 sm:px-6">
                <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Diário de Obra</span>
                        <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">Dashboard RDO</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">Visão gerencial dos RDOs por período, status e frente de serviço.</p>
                    </div>
                    <Link
                        href={route('tenant.diario-obra.rdo.calendar', currentTenant.slug)}
                        className="inline-flex items-center gap-2 rounded-lg bg-[var(--primary)] px-4 py-2.5 font-bold text-white"
                    >
                        <CalendarDays size={17} /> Abrir calendário
                    </Link>
                </div>

                <div className="mb-5 grid gap-3 rounded-xl border border-[var(--border)] bg-white p-4 shadow-sm md:grid-cols-2">
                    <label>
                        <span className="eyebrow mb-1.5 block">Contrato</span>
                        <select className="sig-input w-full" value={filters.contract_id || ''} onChange={(event) => changeFilter('contract_id', event.target.value)}>
                            {contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} - {contract.name}</option>)}
                        </select>
                    </label>
                    <label>
                        <span className="eyebrow mb-1.5 block">Mês de referência</span>
                        <input className="sig-input w-full" type="month" value={filters.month || ''} onChange={(event) => changeFilter('month', event.target.value)} />
                    </label>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    <KpiCard label="RDOs no mês" value={formatNumber(cards.total)} hint="Total gerado no filtro" icon={ClipboardList} />
                    <KpiCard label="Enviados" value={formatNumber(cards.submitted)} hint="Submetidos para análise" icon={Send} tone="blue" />
                    <KpiCard label="Aprovados" value={formatNumber(cards.approved)} hint="Arquivados/aprovados" icon={CheckCircle2} tone="green" />
                    <KpiCard label="Retornados" value={formatNumber(cards.returned)} hint="Voltaram para ajustes" icon={RotateCcw} tone="red" />
                    <KpiCard label="Preenchimento médio" value={`${formatNumber(cards.average_completion)}%`} hint="Campos obrigatórios" icon={TrendingUp} tone="violet" />
                </div>

                <div className="mt-5 grid gap-5 xl:grid-cols-2">
                    <ChartCard title="RDOs por status" subtitle="Distribuição operacional do período" icon={FileSignature}>
                        {(charts.status || []).length > 0
                            ? <ReactECharts option={statusChartOption(charts.status)} style={{ height: 320 }} />
                            : <EmptyChart />}
                    </ChartCard>

                    <ChartCard title="Evolução diária" subtitle="Criados, enviados e aprovados no mês" icon={TrendingUp}>
                        {(charts.daily || []).some((item) => item.criados || item.enviados || item.aprovados)
                            ? <ReactECharts option={dailyChartOption(charts.daily || [])} style={{ height: 320 }} />
                            : <EmptyChart />}
                    </ChartCard>
                </div>

                <div className="mt-5 grid gap-5 xl:grid-cols-[1.15fr_0.85fr]">
                    <ChartCard title="RDOs por frente de serviço" subtitle="Top 10 frentes com mais registros no filtro" icon={CalendarDays}>
                        {(charts.obras || []).length > 0
                            ? <ReactECharts option={obrasChartOption(charts.obras)} style={{ height: 360 }} />
                            : <EmptyChart />}
                    </ChartCard>

                    <section className="rounded-2xl border border-[var(--border)] bg-white shadow-sm">
                        <header className="border-b border-[var(--border)] px-5 py-4">
                            <h2 className="text-base font-black text-[var(--ink-900)]">RDOs recentes</h2>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">Últimos registros do período selecionado.</p>
                        </header>
                        <div className="divide-y divide-[var(--border)]">
                            {recent.length === 0 && (
                                <div className="px-5 py-8 text-center text-sm font-semibold text-[var(--ink-500)]">Nenhum RDO encontrado para o filtro.</div>
                            )}
                            {recent.map((rdo) => (
                                <div key={rdo.id} className="flex items-center justify-between gap-4 px-5 py-4">
                                    <div className="min-w-0">
                                        <Link href={rdo.url} className="truncate font-mono text-sm font-black text-[var(--primary)]">{rdo.code}</Link>
                                        <p className="mt-1 text-xs text-[var(--ink-500)]">{rdo.reference_date} · {rdo.status_label}</p>
                                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                            <div className="h-full rounded-full bg-[var(--primary)]" style={{ width: `${rdo.progress || 0}%` }} />
                                        </div>
                                    </div>
                                    <Link href={rdo.url} className="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700">
                                        <Eye size={14} /> Ver
                                    </Link>
                                </div>
                            ))}
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
