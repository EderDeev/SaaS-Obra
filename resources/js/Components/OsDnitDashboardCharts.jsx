import ReactECharts from 'echarts-for-react';

const count = (value) => Number(value || 0).toLocaleString('pt-BR');
const days = (value) => Number(value || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 });
const shortNames = { draft: 'Demanda', awaiting_concremat: 'Análise Supervisão', awaiting_contract: 'Fiscal do contrato', approved: 'Entrega do produto', completed: 'Finalizada' };

export function monthlyOption(monthly) {
    return {
        color: ['#0b5fff', '#059669', '#dc2626', '#94a3b8'], aria: { enabled: true },
        tooltip: { trigger: 'axis', confine: true, axisPointer: { type: 'shadow' } },
        legend: { top: 0, left: 0, itemWidth: 12, itemHeight: 10 },
        grid: { top: 50, left: 8, right: 20, bottom: 8, containLabel: true },
        xAxis: { type: 'category', data: monthly.map((month) => month.label), axisTick: { show: false }, axisLabel: { color: '#64748b', hideOverlap: true } },
        yAxis: { type: 'value', minInterval: 1, axisLabel: { color: '#64748b' }, splitLine: { lineStyle: { color: '#edf0f4' } } },
        series: [
            { name: 'Abertas', type: 'bar', barMaxWidth: 32, itemStyle: { borderRadius: [5, 5, 0, 0] }, data: monthly.map((month) => month.opened) },
            { name: 'Finalizadas no prazo', type: 'bar', stack: 'completed', barMaxWidth: 32, data: monthly.map((month) => month.completed_on_time) },
            { name: 'Finalizadas em atraso', type: 'bar', stack: 'completed', barMaxWidth: 32, itemStyle: { borderRadius: [5, 5, 0, 0] }, data: monthly.map((month) => month.completed_late) },
            ...(monthly.some((month) => month.unapproved) ? [{ name: 'Sem prazo aprovado', type: 'bar', stack: 'completed', barMaxWidth: 32, data: monthly.map((month) => month.unapproved) }] : []),
        ],
    };
}

export function waitingOption(waiting) {
    return {
        aria: { enabled: true },
        tooltip: { trigger: 'axis', confine: true, axisPointer: { type: 'shadow' }, valueFormatter: (value) => `${count(value)} OS(s)` },
        grid: { left: 8, right: 80, top: 12, bottom: 12, containLabel: true },
        xAxis: { type: 'value', minInterval: 1, name: 'OSs', nameLocation: 'end', axisLabel: { color: '#64748b' }, splitLine: { lineStyle: { color: '#edf0f4' } } },
        yAxis: { type: 'category', inverse: true, data: waiting.map((stage) => shortNames[stage.key]), axisTick: { show: false }, axisLine: { show: false }, axisLabel: { color: '#475569', fontSize: 12 } },
        series: [{ name: 'Prazo vencido', type: 'bar', barMaxWidth: 26, showBackground: true, backgroundStyle: { color: '#f5f7fa', borderRadius: 5 },
            label: { show: true, position: 'right', formatter: ({ value }) => `${count(value)} OS(s)`, color: '#334155' },
            data: waiting.map((stage) => ({ value: stage.overdue, itemStyle: { color: stage.overdue ? '#ef4444' : '#cbd5e1', borderRadius: [0, 5, 5, 0] } })),
        }],
    };
}

export default function OsDnitDashboardCharts({ monthly, waiting }) {
    const hasMonthly = monthly.some((month) => month.opened || month.completed_on_time || month.completed_late || month.unapproved);
    const hasPending = waiting.some((stage) => stage.overdue);
    return <div className="grid min-w-0 gap-5 xl:grid-cols-2">
        <section className="sig-card min-w-0 overflow-hidden p-5 sm:p-6" aria-labelledby="monthly-chart-title">
            <h2 id="monthly-chart-title" className="text-lg font-semibold">Aberturas e entregas por mês</h2><p className="mt-1 text-xs leading-relaxed text-slate-500">Compare as aberturas com as finalizações no prazo e em atraso. A finalização conta no mês em que ocorreu, mesmo para OSs abertas antes do período.</p>
            {hasMonthly ? <><div className="mt-6"><ReactECharts option={monthlyOption(monthly)} notMerge opts={{ renderer: 'svg' }} style={{ height: 350, width: '100%' }} /></div><details className="mt-5 text-xs text-slate-500"><summary className="cursor-pointer font-medium">Ver valores do gráfico</summary><dl className="mt-3 space-y-2">{monthly.map((month) => <div key={month.month} className="flex flex-wrap justify-between gap-2 border-t border-slate-100 pt-2"><dt>{month.label}</dt><dd>{count(month.opened)} aberta(s) · {count(month.completed_on_time)} no prazo · {count(month.completed_late)} em atraso{month.unapproved ? ` · ${count(month.unapproved)} sem prazo aprovado` : ''}</dd></div>)}</dl></details></> : <p role="status" className="mt-6 flex min-h-72 items-center justify-center rounded-xl border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500">Nenhuma abertura ou finalização no período selecionado.</p>}
        </section>
        <section className="sig-card min-w-0 overflow-hidden p-5 sm:p-6" aria-labelledby="waiting-chart-title">
            <h2 id="waiting-chart-title" className="text-lg font-semibold">Prazos vencidos por etapa</h2><p className="mt-1 text-xs text-slate-500">OSs em aberto com prazo vencido, agrupadas pela etapa atual.</p>
            {hasPending ? <><div className="mt-5"><ReactECharts option={waitingOption(waiting)} notMerge opts={{ renderer: 'svg' }} style={{ height: 250, width: '100%' }} /></div><div className="mt-3 grid gap-3 border-t border-slate-100 pt-4 text-xs text-slate-500 sm:grid-cols-2 xl:grid-cols-4">{waiting.map((stage) => <p key={stage.key}><strong className="block text-slate-700">{shortNames[stage.key]}</strong>{count(stage.overdue)} vencida(s) · maior atraso: {days(stage.max_overdue_days)} dias</p>)}</div></> : <p role="status" className="mt-6 rounded-xl bg-emerald-50 p-6 text-sm text-emerald-800">Nenhuma OS com prazo vencido nos filtros selecionados.</p>}
        </section>
    </div>;
}
