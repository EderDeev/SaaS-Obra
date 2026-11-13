import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import OsDnitTourButton from '@/Components/OsDnitTourButton';
import { dateLabel, Pagination } from '@/Components/OsDnitOrderUi';
import { StatusBadge } from '@/Components/OsDnitWorkflow';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import OsDnitDashboardCharts from '@/Components/OsDnitDashboardCharts';
import OsDnitStageDeadlines from '@/Components/OsDnitStageDeadlines';
import { ArrowRight, CheckCircle2, Clock3, Eye, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const count = (value) => Number(value).toLocaleString('pt-BR');

export default function Dashboard({ contracts, highways, lots, filters, monthly, pending, statuses, statusOptions, waiting }) {
    const { currentTenant } = usePage().props;
    const indexUrl = route('tenant.os-dnit.dashboard', currentTenant.slug);
    const search = useForm({ contract_id: filters.contract_id || '', highway_id: filters.highway_id || '', supervision_lot_id: filters.supervision_lot_id || '', status: filters.status || '', date_from: filters.date_from, date_to: filters.date_to });
    const [filtering, setFiltering] = useState(false);
    const timer = useRef(null);
    const request = useRef(null);
    const revision = useRef(0);
    useEffect(() => () => { clearTimeout(timer.current); revision.current++; request.current?.cancel(); }, []);
    useEffect(() => {
        search.setData({ contract_id: filters.contract_id || '', highway_id: filters.highway_id || '', supervision_lot_id: filters.supervision_lot_id || '', status: filters.status || '', date_from: filters.date_from, date_to: filters.date_to });
    }, [filters.contract_id, filters.highway_id, filters.supervision_lot_id, filters.status, filters.date_from, filters.date_to]);

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

    const availableHighways = highways.filter((highway) => !search.data.contract_id || String(highway.contract_id) === String(search.data.contract_id));
    const availableLots = lots.filter((lot) => !search.data.contract_id || String(lot.contract_id) === String(search.data.contract_id));
    return <AuthenticatedLayout>
        <Head title="Ordem de Serviço · Dashboard" />
        <div className="sig-content os-dnit-page space-y-6">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div><div className="eyebrow">Ordem de Serviço</div><h1 className="mt-2 text-2xl font-semibold">Dashboard</h1><p className="mt-2 max-w-2xl text-sm text-slate-500">Acompanhe as datas, o cumprimento dos prazos e os atrasos das ordens de serviço.</p></div>
                <div className="flex flex-wrap gap-2"><OsDnitTourButton tenantSlug={currentTenant.slug} /><Link href={route('tenant.os-dnit.orders.index', currentTenant.slug)} className="sig-btn sig-btn-secondary">Ver ordens de serviço<ArrowRight size={16} /></Link></div>
            </header>

            {contracts.length === 0 && <p role="status" className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Nenhum contrato parametrizado para OS está disponível para seu usuário.</p>}
            <form onSubmit={(event) => { event.preventDefault(); filterDashboard(search.data); }} className="sig-card grid min-w-0 gap-4 p-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
                <label className="min-w-0 text-xs font-semibold text-slate-600" htmlFor="dashboard-contract">Contrato<select id="dashboard-contract" value={search.data.contract_id} onChange={(event) => filterDashboard({ ...search.data, contract_id: event.target.value, highway_id: '', supervision_lot_id: '' })} className="mt-2 w-full rounded-lg border-slate-300 text-sm"><option value="">Todos os contratos</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} — {contract.name}</option>)}</select></label>
                <label className="min-w-0 text-xs font-semibold text-slate-600" htmlFor="dashboard-lot">Lote de supervisão<select id="dashboard-lot" value={search.data.supervision_lot_id} onChange={(event) => filterDashboard({ ...search.data, supervision_lot_id: event.target.value })} className="mt-2 w-full rounded-lg border-slate-300 text-sm"><option value="">Todos os lotes</option>{availableLots.map((lot) => <option key={lot.id} value={lot.id}>{lot.name}</option>)}</select></label>
                <label className="min-w-0 text-xs font-semibold text-slate-600" htmlFor="dashboard-highway">Rodovia<select id="dashboard-highway" value={search.data.highway_id} onChange={(event) => filterDashboard({ ...search.data, highway_id: event.target.value })} className="mt-2 w-full rounded-lg border-slate-300 text-sm"><option value="">Todas as rodovias</option>{availableHighways.map((highway) => <option key={highway.id} value={highway.id}>{highway.name}</option>)}</select></label>
                <label className="min-w-0 text-xs font-semibold text-slate-600" htmlFor="dashboard-status">Situação<select id="dashboard-status" value={search.data.status} onChange={(event) => filterDashboard({ ...search.data, status: event.target.value })} className="mt-2 w-full rounded-lg border-slate-300 text-sm"><option value="">Todas as situações</option>{Object.entries(statusOptions).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
                <label className="min-w-0 text-xs font-semibold text-slate-600" htmlFor="dashboard-from">Período inicial<input id="dashboard-from" required type="date" value={search.data.date_from} onChange={(event) => filterDashboard({ ...search.data, date_from: event.target.value }, 350)} className="mt-2 w-full rounded-lg border-slate-300 text-sm" /></label>
                <label className="min-w-0 text-xs font-semibold text-slate-600" htmlFor="dashboard-to">Período final<input id="dashboard-to" required type="date" min={search.data.date_from} value={search.data.date_to} onChange={(event) => filterDashboard({ ...search.data, date_to: event.target.value }, 350)} className="mt-2 w-full rounded-lg border-slate-300 text-sm" /></label>
                {Object.keys(search.errors).length > 0 && <div role="alert" className="col-span-full rounded-lg bg-red-50 p-3 text-sm text-red-800">{[...new Set(Object.values(search.errors))].map((error) => <p key={error}>{error}</p>)}</div>}
            </form>

            <p className="text-xs text-slate-500">Referência de prazo: data aprovada pelo fiscal do contrato. OSs sem aprovação não são classificadas como atendidas ou atrasadas. A data limite inclui o dia inteiro.</p>
            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500"><p>Prazos e pendências: OSs abertas de {dateLabel(filters.date_from)} a {dateLabel(filters.date_to)}.</p><span role="status" className="inline-flex items-center gap-2">{filtering && <><Loader2 size={14} className="animate-spin" />Atualizando painel…</>}</span></div>
            <div aria-busy={filtering} className="space-y-6">
                <OsDnitStageDeadlines tenantSlug={currentTenant.slug} contracts={contracts} initialFilters={filters} />
                <OsDnitDashboardCharts monthly={monthly} waiting={waiting} />

                <section className="sig-card min-w-0" aria-labelledby="pending-title">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4 sm:p-5"><div><h2 id="pending-title" className="font-semibold">Pendências que exigem atenção <span className="ml-1 text-sm font-normal text-slate-500">({count(pending.total)})</span></h2><p className="mt-1 text-xs text-slate-500">Acompanhe as OSs em aberto e os prazos de cada etapa.</p></div></header>
                    <div className="divide-y divide-slate-100">{pending.data.map((order) => <article key={order.id} className="space-y-3 p-4 sm:p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0"><span className="font-mono text-xs font-semibold text-blue-700">OS {order.request_number}</span><h3 className="mt-1 break-words text-sm font-semibold">{order.subject}</h3><p className="mt-1 text-xs text-slate-500">{order.contract.code} · {order.highway_name}</p></div><StatusBadge status={order.status} statuses={statuses} /></div>
                        <div className="flex flex-wrap items-center justify-between gap-3"><div className="space-y-1 text-xs text-slate-500"><p className="inline-flex items-center gap-1.5 font-medium text-slate-700"><Clock3 size={14} />{order.waiting_days === 0 ? 'Nesta etapa há menos de 1 dia' : `Há ${count(order.waiting_days)} dia(s) nesta etapa`}</p><p className={order.overdue_days ? 'font-semibold text-red-700' : ''}>Prazo aprovado: {order.planned_deadline ? `${dateLabel(order.planned_deadline)} · ${order.overdue_days ? `${count(order.overdue_days)} dia(s) de atraso` : 'Dentro do prazo'}` : 'Aguardando aprovação do fiscal'}</p></div><div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row"><Link href={route('tenant.os-dnit.orders.show', [currentTenant.slug, order.id])} className="sig-btn sig-btn-secondary" aria-label={`Acessar OS ${order.request_number}`}><Eye size={15} />Acessar</Link>{order.can_analyze && <Link href={route('tenant.os-dnit.orders.index', { tenant: currentTenant.slug, analysis_order_id: order.id })} className="sig-btn sig-btn-primary" aria-label={`Analisar OS ${order.request_number}`}><ArrowRight size={15} />Analisar OS</Link>}</div></div>
                    </article>)}{pending.data.length === 0 && <div className="p-8 text-center text-sm text-slate-500"><CheckCircle2 size={28} className="mx-auto mb-3 text-emerald-600" />Nenhuma pendência entre as OSs abertas no período selecionado.</div>}</div>
                </section>
                {pending.last_page > 1 && <Pagination data={pending} label="pendências" disabled={filtering} />}
            </div>
        </div>
    </AuthenticatedLayout>;
}
