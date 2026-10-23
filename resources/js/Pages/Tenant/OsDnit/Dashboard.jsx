import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import OsDnitTourButton from '@/Components/OsDnitTourButton';
import { dateLabel, Pagination } from '@/Components/OsDnitOrderUi';
import { StatusBadge } from '@/Components/OsDnitWorkflow';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import ReactECharts from 'echarts-for-react';
import { ArrowRight, CheckCircle2, ClipboardList, Clock3, Eye, FilePenLine, Loader2, RotateCcw, ShieldCheck, TrendingUp } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const stages = [
    { key: 'draft', label: 'Demandas', icon: FilePenLine, color: '#64748b', tone: 'bg-slate-100 text-slate-600' },
    { key: 'concremat', label: 'Em análise Supervisão', icon: Clock3, color: '#0b5fff', tone: 'bg-blue-50 text-blue-700' },
    { key: 'inspector', label: 'Com o fiscal do contrato', icon: ShieldCheck, color: '#7c3aed', tone: 'bg-violet-50 text-violet-700' },
    { key: 'approved', label: 'Aprovadas', icon: CheckCircle2, color: '#059669', tone: 'bg-emerald-50 text-emerald-700' },
    { key: 'completed', label: 'Finalizadas', icon: CheckCircle2, color: '#047857', tone: 'bg-emerald-50 text-emerald-700' },
    { key: 'returned', label: 'Devolvidas para correção', icon: RotateCcw, color: '#d97706', tone: 'bg-amber-50 text-amber-700' },
];
const count = (value) => Number(value).toLocaleString('pt-BR');

function Metric({ label, value, hint, icon: Icon, tone }) {
    return <article className="sig-card min-w-0 p-4 sm:p-5">
        <div className="flex min-h-10 items-start justify-between gap-3 sm:min-h-0"><p className="min-w-0 text-xs font-medium text-slate-600 sm:text-sm">{label}</p><span className={`hidden shrink-0 rounded-xl p-2 sm:inline-flex ${tone}`}><Icon size={19} /></span></div>
        <p className="mt-3 text-3xl font-semibold tabular-nums text-slate-900">{count(value)}</p>
        <p className="mt-2 text-xs leading-relaxed text-slate-500">{hint}</p>
    </article>;
}

function monthlyOption(monthly) {
    return {
        color: ['#0b5fff', '#059669'],
        aria: { enabled: true, label: { description: 'Evolução mensal das OSs enviadas e aprovadas. Os valores de cada mês estão disponíveis em Ver valores do gráfico.' } },
        tooltip: { trigger: 'axis', confine: true, axisPointer: { type: 'shadow' } },
        legend: { top: 0, left: 0, itemWidth: 12, itemHeight: 10 },
        grid: { top: 48, left: 8, right: 8, bottom: 8, containLabel: true },
        xAxis: { type: 'category', data: monthly.map((m) => m.label), axisTick: { show: false }, axisLabel: { color: '#64748b', hideOverlap: true, showMaxLabel: true } },
        yAxis: { type: 'value', minInterval: 1, axisLabel: { color: '#64748b' }, splitLine: { lineStyle: { color: '#edf0f4' } } },
        series: [
            { name: 'Enviadas', type: 'bar', barMaxWidth: 24, itemStyle: { borderRadius: [4, 4, 0, 0] }, data: monthly.map((m) => m.submitted) },
            { name: 'Aprovadas', type: 'bar', barMaxWidth: 24, itemStyle: { borderRadius: [4, 4, 0, 0] }, data: monthly.map((m) => m.approved) },
        ],
    };
}

export default function Dashboard({ contracts, highways, lots, filters, stats, monthly, pending, statuses }) {
    const { currentTenant } = usePage().props;
    const indexUrl = route('tenant.os-dnit.dashboard', currentTenant.slug);
    const search = useForm({ contract_id: filters.contract_id || '', highway_id: filters.highway_id || '', supervision_lot_id: filters.supervision_lot_id || '', date_from: filters.date_from, date_to: filters.date_to });
    const [filtering, setFiltering] = useState(false);
    const timer = useRef(null);
    const request = useRef(null);
    const revision = useRef(0);
    useEffect(() => () => { clearTimeout(timer.current); revision.current++; request.current?.cancel(); }, []);
    useEffect(() => {
        search.setData({ contract_id: filters.contract_id || '', highway_id: filters.highway_id || '', supervision_lot_id: filters.supervision_lot_id || '', date_from: filters.date_from, date_to: filters.date_to });
    }, [filters.contract_id, filters.highway_id, filters.supervision_lot_id, filters.date_from, filters.date_to]);

    function filterDashboard(data, delay = 0) {
        clearTimeout(timer.current);
        const current = ++revision.current;
        request.current?.cancel();
        request.current = null;
        search.setData(data);
        search.clearErrors();
        if (!data.date_from || !data.date_to) { setFiltering(false); return; }
        if (data.date_to < data.date_from) {
            search.setError('date_to', 'A data final deve ser igual ou posterior à data inicial.');
            setFiltering(false);
            return;
        }
        setFiltering(true);
        const visit = () => router.get(indexUrl, data, {
            preserveState: true, preserveScroll: true, replace: true,
            onCancelToken: (token) => { request.current = token; },
            onError: (errors) => { if (revision.current === current) search.setError(errors); },
            onFinish: () => { if (revision.current === current) { setFiltering(false); request.current = null; } },
        });
        if (delay) timer.current = setTimeout(visit, delay); else visit();
    }

    const hasMovements = monthly.some((month) => month.submitted || month.approved);
    const availableHighways = highways.filter((highway) => !search.data.contract_id || String(highway.contract_id) === String(search.data.contract_id));
    const availableLots = lots.filter((lot) => !search.data.contract_id || String(lot.contract_id) === String(search.data.contract_id));
    return <AuthenticatedLayout>
        <Head title="Ordem de Serviço · Dashboard" />
        <div className="sig-content os-dnit-page space-y-6">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div><div className="eyebrow">Ordem de Serviço</div><h1 className="mt-2 text-2xl font-semibold">Dashboard</h1><p className="mt-2 max-w-2xl text-sm text-slate-500">Acompanhe as solicitações, as etapas de análise e as aprovações dos contratos.</p></div>
                <div className="flex flex-wrap gap-2"><OsDnitTourButton tenantSlug={currentTenant.slug} /><Link href={route('tenant.os-dnit.orders.index', currentTenant.slug)} className="sig-btn sig-btn-secondary">Ver ordens de serviço<ArrowRight size={16} /></Link></div>
            </header>

            {contracts.length === 0 && <p role="status" className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Você não possui vínculo ativo com um contrato desta empresa.</p>}
            <form onSubmit={(event) => { event.preventDefault(); filterDashboard(search.data); }} className="sig-card grid min-w-0 gap-4 p-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5">
                <label className="min-w-0 text-xs font-semibold text-slate-600" htmlFor="dashboard-contract">Contrato<select id="dashboard-contract" value={search.data.contract_id} onChange={(event) => filterDashboard({ ...search.data, contract_id: event.target.value, highway_id: '', supervision_lot_id: '' })} className="mt-2 w-full rounded-lg border-slate-300 text-sm"><option value="">Todos os meus contratos</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} — {contract.name}</option>)}</select></label>
                <label className="min-w-0 text-xs font-semibold text-slate-600" htmlFor="dashboard-lot">Lote de supervisão<select id="dashboard-lot" value={search.data.supervision_lot_id} onChange={(event) => filterDashboard({ ...search.data, supervision_lot_id: event.target.value })} className="mt-2 w-full rounded-lg border-slate-300 text-sm"><option value="">Todos os lotes</option>{availableLots.map((lot) => <option key={lot.id} value={lot.id}>{lot.name}{!search.data.contract_id && contracts.length > 1 ? ` · ${lot.contract.code}` : ''}</option>)}</select></label>
                <label className="min-w-0 text-xs font-semibold text-slate-600" htmlFor="dashboard-highway">Rodovia<select id="dashboard-highway" value={search.data.highway_id} onChange={(event) => filterDashboard({ ...search.data, highway_id: event.target.value })} className="mt-2 w-full rounded-lg border-slate-300 text-sm"><option value="">Todas as rodovias</option>{availableHighways.map((highway) => <option key={highway.id} value={highway.id}>{highway.name}{!search.data.contract_id && contracts.length > 1 ? ` · ${highway.contract.code}` : ''}</option>)}</select></label>
                <label className="min-w-0 text-xs font-semibold text-slate-600" htmlFor="dashboard-from">Período inicial<input id="dashboard-from" required type="date" value={search.data.date_from} onChange={(event) => filterDashboard({ ...search.data, date_from: event.target.value }, 350)} className="mt-2 w-full rounded-lg border-slate-300 text-sm" /></label>
                <label className="min-w-0 text-xs font-semibold text-slate-600" htmlFor="dashboard-to">Período final<input id="dashboard-to" required type="date" min={search.data.date_from} value={search.data.date_to} onChange={(event) => filterDashboard({ ...search.data, date_to: event.target.value }, 350)} className="mt-2 w-full rounded-lg border-slate-300 text-sm" /></label>
                {Object.keys(search.errors).length > 0 && <div role="alert" className="col-span-full rounded-lg bg-red-50 p-3 text-sm text-red-800">{[...new Set(Object.values(search.errors))].map((error) => <p key={error}>{error}</p>)}</div>}
            </form>

            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500"><p>Indicadores e pendências: OSs abertas de {dateLabel(filters.date_from)} a {dateLabel(filters.date_to)}.</p><span role="status" className="inline-flex items-center gap-2">{filtering && <><Loader2 size={14} className="animate-spin" />Atualizando painel…</>}</span></div>
            <div aria-busy={filtering} className="space-y-6">
                <section aria-label="Indicadores de OS" className="grid grid-cols-2 gap-3 xl:grid-cols-3 2xl:grid-cols-6">
                    <Metric label="Total de OSs" value={stats.total} hint="Solicitações abertas no período" icon={ClipboardList} tone="bg-blue-50 text-blue-700" />
                    {stages.map((stage) => <Metric key={stage.key} {...stage} value={stats[stage.key]} hint={stage.key === 'draft' ? 'Ainda não enviadas para análise' : stage.key === 'concremat' ? 'Aguardando avaliação da Supervisão' : stage.key === 'inspector' ? 'Aguardando decisão do fiscal' : stage.key === 'approved' ? 'Aguardando conclusão do serviço' : stage.key === 'completed' ? 'Serviço concluído'  : `${count(stats.returned_analyst)} ao analista · ${count(stats.returned_concremat)} à Supervisão`} />)}
                </section>

                <div className="grid min-w-0 gap-5 xl:grid-cols-2">
                    <section className="sig-card min-w-0 p-4 sm:p-5" aria-labelledby="stage-chart-title">
                        <h2 id="stage-chart-title" className="font-semibold">Distribuição por etapa</h2><p className="mt-1 text-xs text-slate-500">Situação atual das solicitações abertas no período.</p>
                        <div className="mt-6 space-y-5">{stages.map((stage) => <div key={stage.key}><div className="mb-2 flex items-center justify-between gap-3 text-sm"><span>{stage.label}</span><strong className="shrink-0 tabular-nums">{count(stats[stage.key])}<span className="ml-2 text-xs font-normal text-slate-500">{stats.total ? Math.round(stats[stage.key] / stats.total * 100) : 0}%</span></strong></div><div className="h-2.5 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div className="h-full rounded-full transition-all" style={{ width: `${stats.total ? stats[stage.key] / stats.total * 100 : 0}%`, backgroundColor: stage.color }} /></div></div>)}</div>
                        {stats.total === 0 && <p className="mt-5 text-sm text-slate-500">Nenhuma OS aberta no período selecionado.</p>}
                    </section>
                    <section className="sig-card min-w-0 overflow-hidden p-4 sm:p-5" aria-labelledby="monthly-chart-title">
                        <h2 id="monthly-chart-title" className="flex items-center gap-2 font-semibold"><TrendingUp size={18} className="shrink-0 text-blue-600" />Evolução mensal</h2><p className="mt-1 text-xs leading-relaxed text-slate-500">OSs únicas por mês de envio ou aprovação, incluindo pedidos abertos antes do período.</p>
                        {hasMovements ? <div className="mt-5 min-w-0 overflow-hidden"><ReactECharts option={monthlyOption(monthly)} notMerge style={{ height: 290, width: '100%' }} /><details className="mt-3 text-xs text-slate-500"><summary className="cursor-pointer py-2 font-medium">Ver valores do gráfico</summary><dl className="mt-2 space-y-2">{monthly.map((month) => <div key={month.month} className="flex flex-wrap justify-between gap-2 border-t border-slate-100 pt-2"><dt>{month.label}</dt><dd>{month.submitted} enviada(s) · {month.approved} aprovada(s)</dd></div>)}</dl></details></div> : <div className="mt-5 flex min-h-64 items-center justify-center rounded-xl border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500">Nenhum envio ou aprovação registrado no período selecionado.</div>}
                    </section>
                </div>

                <section className="sig-card min-w-0" aria-labelledby="pending-title">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4 sm:p-5"><div><h2 id="pending-title" className="font-semibold">Pendências que exigem atenção <span className="ml-1 text-sm font-normal text-slate-500">({count(pending.total)})</span></h2><p className="mt-1 text-xs text-slate-500">OSs ainda não aprovadas, da maior para a menor espera na etapa atual.</p></div></header>
                    <div className="divide-y divide-slate-100">{pending.data.map((order) => <article key={order.id} className="space-y-3 p-4 sm:p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0"><span className="font-mono text-xs font-semibold text-blue-700">OS {order.request_number}</span><h3 className="mt-1 break-words text-sm font-semibold">{order.subject}</h3><p className="mt-1 text-xs text-slate-500">{order.contract.code} · {order.highway_name}</p></div><StatusBadge status={order.status} statuses={statuses} /></div>
                        <div className="flex flex-wrap items-center justify-between gap-3"><div className="space-y-1 text-xs text-slate-500"><p className="inline-flex items-center gap-1.5 font-medium text-slate-700"><Clock3 size={14} />{order.waiting_days === 0 ? 'Nesta etapa há menos de 1 dia' : `Há ${count(order.waiting_days)} dia(s) nesta etapa`}</p><p>Prazo solicitado: {dateLabel(order.desired_deadline)}</p></div><div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row"><Link href={route('tenant.os-dnit.orders.show', [currentTenant.slug, order.id])} className="sig-btn sig-btn-secondary" aria-label={`Acessar OS ${order.request_number}`}><Eye size={15} />Acessar</Link>{order.can_analyze && <Link href={route('tenant.os-dnit.orders.index', { tenant: currentTenant.slug, analysis_order_id: order.id })} className="sig-btn sig-btn-primary" aria-label={`Analisar OS ${order.request_number}`}><ArrowRight size={15} />Analisar OS</Link>}</div></div>
                    </article>)}{pending.data.length === 0 && <div className="p-8 text-center text-sm text-slate-500"><CheckCircle2 size={28} className="mx-auto mb-3 text-emerald-600" />Nenhuma pendência entre as OSs abertas no período selecionado.</div>}</div>
                </section>
                {pending.last_page > 1 && <Pagination data={pending} label="pendências" disabled={filtering} />}
            </div>
        </div>
    </AuthenticatedLayout>;
}
