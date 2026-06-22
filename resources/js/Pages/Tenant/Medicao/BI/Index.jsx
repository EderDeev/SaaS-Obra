import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import ReactECharts from 'echarts-for-react';
import { BarChart3, Clock, FileText, Filter, LineChart, PieChart, TrendingUp } from 'lucide-react';
import { useMemo } from 'react';

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const number = new Intl.NumberFormat('pt-BR');

function formatCurrency(value) {
    return currency.format(Number(value || 0));
}
function formatNumber(value) {
    return number.format(Number(value || 0));
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

function KpiCard({ label, value, hint, icon: Icon, tone = 'blue' }) {
    const tones = {
        blue: 'bg-blue-50 text-blue-700',
        green: 'bg-emerald-50 text-emerald-700',
        amber: 'bg-amber-50 text-amber-700',
        red: 'bg-red-50 text-red-700',
        slate: 'bg-slate-100 text-slate-700',
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

export default function MedicaoBIIndex({ filters = {}, contracts = [], boletins = [], dashboard = {} }) {
    const { props } = usePage();
    const tenant = props.currentTenant;
    const cards = dashboard.cards || {};
    const charts = dashboard.charts || {};
    const itens = dashboard.itens || [];
    const recentes = dashboard.recentes || [];

    const selectedContractId = filters.contract_id || '';
    const selectedBoletimId = filters.boletim_id || '';

    function updateFilter(nextFilters) {
        router.get(
            route('tenant.medicao.bi.index', tenant.slug),
            {
                contract_id: nextFilters.contract_id || undefined,
                boletim_id: nextFilters.boletim_id || undefined,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    }

    const statusOption = useMemo(() => ({
        tooltip: {
            trigger: 'item',
            formatter: '{b}: {c} FR(s) ({d}%)',
        },
        legend: {
            bottom: 0,
            left: 'center',
            type: 'scroll',
        },
        color: ['#2563eb', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444', '#64748b', '#14b8a6'],
        series: [{
            type: 'pie',
            radius: ['48%', '72%'],
            center: ['50%', '44%'],
            avoidLabelOverlap: true,
            itemStyle: {
                borderRadius: 8,
                borderColor: '#fff',
                borderWidth: 2,
            },
            label: {
                formatter: '{b}\n{c}',
                fontWeight: 700,
            },
            data: charts.status || [],
        }],
    }), [charts.status]);

    const barOption = (data, valueLabel = 'Valor pleiteado') => ({
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            valueFormatter: (value) => formatCurrency(value),
        },
        grid: {
            left: 12,
            right: 20,
            bottom: 16,
            top: 20,
            containLabel: true,
        },
        xAxis: {
            type: 'value',
            axisLabel: {
                formatter: (value) => Intl.NumberFormat('pt-BR', { notation: 'compact' }).format(value),
            },
        },
        yAxis: {
            type: 'category',
            data: (data || []).map((item) => item.name),
            axisLabel: {
                width: 160,
                overflow: 'truncate',
                fontWeight: 700,
            },
        },
        color: ['#2563eb'],
        series: [{
            name: valueLabel,
            type: 'bar',
            data: (data || []).map((item) => item.value),
            barMaxWidth: 28,
            itemStyle: {
                borderRadius: [0, 8, 8, 0],
            },
        }],
    });

    const lineOption = useMemo(() => ({
        tooltip: {
            trigger: 'axis',
            valueFormatter: (value) => formatCurrency(value),
        },
        grid: {
            left: 12,
            right: 20,
            bottom: 16,
            top: 24,
            containLabel: true,
        },
        xAxis: {
            type: 'category',
            data: (charts.evolucao_mensal || []).map((item) => item.periodo),
            boundaryGap: false,
        },
        yAxis: {
            type: 'value',
            axisLabel: {
                formatter: (value) => Intl.NumberFormat('pt-BR', { notation: 'compact' }).format(value),
            },
        },
        color: ['#10b981'],
        series: [{
            name: 'Valor pleiteado',
            type: 'line',
            smooth: true,
            areaStyle: {
                opacity: 0.14,
            },
            symbolSize: 8,
            data: (charts.evolucao_mensal || []).map((item) => item.valor),
        }],
    }), [charts.evolucao_mensal]);

    const analysisOption = useMemo(() => ({
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            valueFormatter: (value) => `${value} FR(s)`,
        },
        grid: {
            left: 12,
            right: 20,
            bottom: 16,
            top: 20,
            containLabel: true,
        },
        xAxis: {
            type: 'category',
            data: (charts.analise_setor || []).map((item) => item.name),
            axisLabel: { fontWeight: 700 },
        },
        yAxis: {
            type: 'value',
            minInterval: 1,
        },
        color: ['#f59e0b'],
        series: [{
            name: 'FRs em análise',
            type: 'bar',
            data: (charts.analise_setor || []).map((item) => item.value),
            barMaxWidth: 42,
            itemStyle: {
                borderRadius: [8, 8, 0, 0],
            },
        }],
    }), [charts.analise_setor]);

    const abcOption = useMemo(() => ({
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'cross' },
        },
        legend: {
            top: 0,
            right: 0,
        },
        grid: {
            left: 12,
            right: 48,
            bottom: 72,
            top: 42,
            containLabel: true,
        },
        xAxis: {
            type: 'category',
            data: (charts.itens_abc || []).map((item) => item.name),
            axisLabel: {
                rotate: 40,
                fontWeight: 700,
            },
        },
        yAxis: [
            {
                type: 'value',
                name: 'Valor',
                axisLabel: {
                    formatter: (value) => Intl.NumberFormat('pt-BR', { notation: 'compact' }).format(value),
                },
            },
            {
                type: 'value',
                name: 'Acumulado',
                min: 0,
                max: 100,
                axisLabel: {
                    formatter: '{value}%',
                },
            },
        ],
        color: ['#2563eb', '#f59e0b'],
        series: [
            {
                name: 'Valor pleiteado',
                type: 'bar',
                data: (charts.itens_abc || []).map((item) => item.valor),
                itemStyle: {
                    borderRadius: [8, 8, 0, 0],
                },
            },
            {
                name: '% acumulado',
                type: 'line',
                yAxisIndex: 1,
                smooth: true,
                symbolSize: 8,
                data: (charts.itens_abc || []).map((item) => item.acumulado),
            },
        ],
    }), [charts.itens_abc]);

    const unidadeOption = useMemo(() => ({
        tooltip: {
            trigger: 'item',
            formatter: (params) => `${params.name}: ${formatCurrency(params.value)} (${params.percent}%)`,
        },
        legend: {
            bottom: 0,
            left: 'center',
            type: 'scroll',
        },
        color: ['#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#14b8a6', '#64748b'],
        series: [{
            type: 'pie',
            radius: ['44%', '70%'],
            center: ['50%', '43%'],
            itemStyle: {
                borderRadius: 8,
                borderColor: '#fff',
                borderWidth: 2,
            },
            label: {
                formatter: '{b}\n{d}%',
                fontWeight: 700,
            },
            data: charts.itens_unidades || [],
        }],
    }), [charts.itens_unidades]);

    return (
        <AuthenticatedLayout>
            <Head title="B.I Medição" />

            <div className="p-6">
                <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-[0.2em] text-[var(--ink-500)]">Medição</p>
                        <h1 className="mt-2 text-3xl font-black text-[var(--ink-900)]">B.I</h1>
                        <p className="mt-2 max-w-3xl text-sm text-[var(--ink-600)]">
                            Painel dinâmico com indicadores das Folhas de Rosto, boletins, pleitos e etapas de análise.
                        </p>
                    </div>

                    <div className="rounded-2xl border border-[var(--border)] bg-white p-3 shadow-sm">
                        <div className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.16em] text-[var(--ink-500)]">
                            <Filter size={14} />
                            Filtros
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <select
                                className="rounded-xl border-[var(--border)] text-sm font-semibold text-[var(--ink-700)]"
                                value={selectedContractId}
                                onChange={(event) => updateFilter({ contract_id: event.target.value, boletim_id: '' })}
                            >
                                <option value="">Todos os contratos</option>
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>
                                        {contract.code} {contract.name ? `- ${contract.name}` : ''}
                                    </option>
                                ))}
                            </select>

                            <select
                                className="rounded-xl border-[var(--border)] text-sm font-semibold text-[var(--ink-700)]"
                                value={selectedBoletimId}
                                onChange={(event) => updateFilter({ contract_id: selectedContractId, boletim_id: event.target.value })}
                            >
                                <option value="">Todos os BMs</option>
                                {boletins.map((boletim) => (
                                    <option key={boletim.id} value={boletim.id}>
                                        {boletim.codigo} · {boletim.periodo} · {boletim.tipo}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <KpiCard label="FRs no filtro" value={formatNumber(cards.total_frs)} hint="Folhas de Rosto encontradas" icon={FileText} />
                    <KpiCard label="Total pleiteado" value={formatCurrency(cards.total_pleiteado)} hint="Valor solicitado pela construtora" icon={TrendingUp} tone="green" />
                    <KpiCard label="FRs em análise" value={formatNumber(cards.frs_em_analise)} hint={`${cards.prazo_medio_analise || 0} dia(s) em média`} icon={Clock} tone="amber" />
                    <KpiCard label="FRs atrasadas" value={formatNumber(cards.frs_atrasadas)} hint="Acima de 10 dias em análise" icon={BarChart3} tone={cards.frs_atrasadas > 0 ? 'red' : 'slate'} />
                </div>

                <div className="mt-4 grid gap-4 md:grid-cols-3">
                    <KpiCard label="Aprovado fiscal" value={formatCurrency(cards.total_aprovado_fiscal)} hint="Com base nos quantitativos fiscais" icon={BarChart3} tone="blue" />
                    <KpiCard label="Aprovado qualidade" value={formatCurrency(cards.total_aprovado_qualidade)} hint="Com base nos quantitativos da qualidade" icon={BarChart3} tone="blue" />
                    <KpiCard label="Aprovado medição" value={formatCurrency(cards.total_aprovado_medicao)} hint="Com base nos quantitativos da medição" icon={BarChart3} tone="green" />
                </div>

                <div className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <KpiCard label="Itens pleiteados" value={formatNumber(cards.itens_pleiteados)} hint="Itens distintos no filtro atual" icon={BarChart3} tone="slate" />
                    <KpiCard
                        label="Maior impacto"
                        value={cards.item_maior_impacto ? formatCurrency(cards.item_maior_impacto.valor) : formatCurrency(0)}
                        hint={cards.item_maior_impacto ? `${cards.item_maior_impacto.item} · ${cards.item_maior_impacto.percentual}% do total` : 'Sem item no filtro'}
                        icon={TrendingUp}
                        tone="green"
                    />
                    <KpiCard label="Concentração top 10" value={`${formatNumber(cards.concentracao_top_10)}%`} hint="Peso financeiro dos 10 maiores itens" icon={PieChart} tone="amber" />
                    <KpiCard label="Classe A" value={`${formatNumber(cards.classe_a_itens)} item(ns)`} hint={`${formatNumber(cards.classe_a_percentual)}% do valor pleiteado`} icon={BarChart3} tone="blue" />
                </div>

                <div className="mt-6 grid gap-5 xl:grid-cols-2">
                    <ChartCard title="FRs por status" subtitle="Distribuição atual do fluxo de medição" icon={PieChart}>
                        {(charts.status || []).length > 0 ? (
                            <ReactECharts option={statusOption} style={{ height: 320 }} notMerge lazyUpdate />
                        ) : <EmptyChart />}
                    </ChartCard>

                    <ChartCard title="Valor pleiteado por obra" subtitle="Top 10 obras com maior valor pleiteado" icon={BarChart3}>
                        {(charts.obras || []).length > 0 ? (
                            <ReactECharts option={barOption(charts.obras)} style={{ height: 320 }} notMerge lazyUpdate />
                        ) : <EmptyChart />}
                    </ChartCard>

                    <ChartCard title="Valor pleiteado por construtora" subtitle="Ranking por empresa solicitante" icon={BarChart3}>
                        {(charts.construtoras || []).length > 0 ? (
                            <ReactECharts option={barOption(charts.construtoras)} style={{ height: 320 }} notMerge lazyUpdate />
                        ) : <EmptyChart />}
                    </ChartCard>

                    <ChartCard title="Valor pleiteado por BM" subtitle="Boletins com maior volume financeiro" icon={BarChart3}>
                        {(charts.boletins || []).length > 0 ? (
                            <ReactECharts option={barOption(charts.boletins)} style={{ height: 320 }} notMerge lazyUpdate />
                        ) : <EmptyChart />}
                    </ChartCard>

                    <ChartCard title="Evolução mensal" subtitle="Soma pleiteada por mês de referência" icon={LineChart}>
                        {(charts.evolucao_mensal || []).length > 0 ? (
                            <ReactECharts option={lineOption} style={{ height: 320 }} notMerge lazyUpdate />
                        ) : <EmptyChart />}
                    </ChartCard>

                    <ChartCard title="Gargalo de análise" subtitle="FRs abertas por setor de análise" icon={Clock}>
                        {(charts.analise_setor || []).length > 0 ? (
                            <ReactECharts option={analysisOption} style={{ height: 320 }} notMerge lazyUpdate />
                        ) : <EmptyChart />}
                    </ChartCard>
                </div>

                <div className="mt-6">
                    <div className="mb-4">
                        <p className="text-xs font-bold uppercase tracking-[0.2em] text-[var(--ink-500)]">Itens</p>
                        <h2 className="mt-1 text-2xl font-black text-[var(--ink-900)]">Análise dos itens pleiteados</h2>
                        <p className="mt-1 text-sm text-[var(--ink-600)]">
                            Curva ABC para enxergar quais itens concentram o valor do pleito e merecem maior atenção na análise.
                        </p>
                    </div>

                    <div className="grid gap-5 xl:grid-cols-2">
                        <ChartCard title="Curva ABC dos itens" subtitle="Valor pleiteado por item e percentual acumulado" icon={LineChart}>
                            {(charts.itens_abc || []).length > 0 ? (
                                <ReactECharts option={abcOption} style={{ height: 380 }} notMerge lazyUpdate />
                            ) : <EmptyChart />}
                        </ChartCard>

                        <ChartCard title="Top itens por valor" subtitle="Itens com maior impacto financeiro no filtro" icon={BarChart3}>
                            {(charts.itens_top || []).length > 0 ? (
                                <ReactECharts option={barOption(charts.itens_top)} style={{ height: 380 }} notMerge lazyUpdate />
                            ) : <EmptyChart />}
                        </ChartCard>

                        <ChartCard title="Valor por unidade" subtitle="Distribuição financeira por unidade de medição" icon={PieChart}>
                            {(charts.itens_unidades || []).length > 0 ? (
                                <ReactECharts option={unidadeOption} style={{ height: 340 }} notMerge lazyUpdate />
                            ) : <EmptyChart />}
                        </ChartCard>

                        <section className="overflow-hidden rounded-2xl border border-[var(--border)] bg-white shadow-sm">
                            <div className="border-b border-[var(--border)] px-5 py-4">
                                <h3 className="text-base font-black text-[var(--ink-900)]">Ranking ABC</h3>
                                <p className="mt-1 text-sm text-[var(--ink-500)]">Top 30 itens por valor pleiteado.</p>
                            </div>

                            {itens.length > 0 ? (
                                <div className="max-h-[340px] overflow-auto">
                                    <table className="min-w-full divide-y divide-[var(--border)] text-sm">
                                        <thead className="sticky top-0 z-10 bg-[var(--surface-muted)] text-left text-xs font-black uppercase tracking-[0.12em] text-[var(--ink-500)]">
                                            <tr>
                                                <th className="px-4 py-3">Classe</th>
                                                <th className="px-4 py-3">Item</th>
                                                <th className="px-4 py-3">Código</th>
                                                <th className="px-4 py-3 text-right">Qtd.</th>
                                                <th className="px-4 py-3 text-right">Valor</th>
                                                <th className="px-4 py-3 text-right">%</th>
                                                <th className="px-4 py-3 text-right">% acum.</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-[var(--border)]">
                                            {itens.map((item, index) => (
                                                <tr key={`${item.item}-${item.codigo}-${index}`}>
                                                    <td className="px-4 py-3">
                                                        <span className={`rounded-full px-3 py-1 text-xs font-black ${
                                                            item.classe === 'A'
                                                                ? 'bg-blue-50 text-blue-700'
                                                                : item.classe === 'B'
                                                                    ? 'bg-amber-50 text-amber-700'
                                                                    : 'bg-slate-100 text-slate-700'
                                                        }`}>
                                                            {item.classe}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 font-mono text-xs font-black text-[var(--ink-900)]">{item.item}</td>
                                                    <td className="px-4 py-3">
                                                        <div className="max-w-[220px]">
                                                            <p className="font-black text-[var(--ink-900)]">{item.codigo}</p>
                                                            <p className="truncate text-xs text-[var(--ink-500)]">{item.descricao}</p>
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3 text-right font-semibold text-[var(--ink-700)]">
                                                        {formatNumber(item.quantidade)} {item.unidade}
                                                    </td>
                                                    <td className="px-4 py-3 text-right font-black text-emerald-700">{formatCurrency(item.valor)}</td>
                                                    <td className="px-4 py-3 text-right font-semibold text-[var(--ink-700)]">{formatNumber(item.percentual)}%</td>
                                                    <td className="px-4 py-3 text-right font-semibold text-[var(--ink-700)]">{formatNumber(item.acumulado)}%</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="px-5 py-10 text-center text-sm font-semibold text-[var(--ink-500)]">
                                    Nenhum item encontrado para o filtro atual.
                                </div>
                            )}
                        </section>
                    </div>
                </div>

                <section className="mt-6 overflow-hidden rounded-2xl border border-[var(--border)] bg-white shadow-sm">
                    <div className="border-b border-[var(--border)] px-5 py-4">
                        <h2 className="text-base font-black text-[var(--ink-900)]">Últimas FRs no filtro</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">Lista rápida para dar contexto aos números do painel.</p>
                    </div>

                    {recentes.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-[var(--border)] text-sm">
                                <thead className="bg-[var(--surface-muted)] text-left text-xs font-black uppercase tracking-[0.12em] text-[var(--ink-500)]">
                                    <tr>
                                        <th className="px-5 py-3">FR</th>
                                        <th className="px-5 py-3">Comentário</th>
                                        <th className="px-5 py-3">BM</th>
                                        <th className="px-5 py-3">Obra</th>
                                        <th className="px-5 py-3">Construtora</th>
                                        <th className="px-5 py-3">Status</th>
                                        <th className="px-5 py-3 text-right">Valor</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[var(--border)]">
                                    {recentes.map((folha) => (
                                        <tr key={folha.codigo}>
                                            <td className="px-5 py-4 font-mono text-xs font-black text-blue-700">{folha.codigo}</td>
                                            <td className="max-w-[320px] truncate px-5 py-4 font-semibold text-[var(--ink-900)]">{folha.comentario}</td>
                                            <td className="px-5 py-4 text-[var(--ink-600)]">{folha.boletim}</td>
                                            <td className="px-5 py-4 text-[var(--ink-600)]">{folha.obra}</td>
                                            <td className="px-5 py-4 text-[var(--ink-600)]">{folha.construtora}</td>
                                            <td className="px-5 py-4">
                                                <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-black text-blue-700">{folha.status}</span>
                                                {folha.dias_analise !== null && (
                                                    <span className="ml-2 rounded-full bg-amber-50 px-3 py-1 text-xs font-black text-amber-700">
                                                        {folha.dias_analise} dia(s)
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-5 py-4 text-right font-black text-emerald-700">{formatCurrency(folha.valor)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="px-5 py-10 text-center text-sm font-semibold text-[var(--ink-500)]">
                            Nenhuma Folha de Rosto encontrada para o filtro atual.
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
