import OsDnitPdfDownload from '@/Components/OsDnitPdfDownload';
import { StatusBadge } from '@/Components/OsDnitWorkflow';
import OsDnitAnalysisModal from '@/Components/OsDnitAnalysisModal';
import OsDnitConfirmDialog from '@/Components/OsDnitConfirmDialog';
import OsDnitDeleteButton from '@/Components/OsDnitDeleteButton';
import OsDnitTourButton from '@/Components/OsDnitTourButton';
import { useEffect, useRef, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { dateLabel, numberLabel, Pagination } from '@/Components/OsDnitOrderUi';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ClipboardList, Eye, Plus, Send } from 'lucide-react';

function OrderActions({ order, tenantSlug, onAnalyze }) {
    const form = useForm({ version: order.workflow_version });
    const [confirmSubmit, setConfirmSubmit] = useState(false);
    return <div className="w-full min-w-0 space-y-2 sm:w-auto">
        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:justify-end">
            <Link href={route('tenant.os-dnit.orders.show', [tenantSlug, order.id])} className="sig-btn sig-btn-secondary" aria-label={`Acessar OS ${order.request_number}`}><Eye size={16} />Acessar</Link>
            <OsDnitPdfDownload order={order} tenantSlug={tenantSlug} />
            {order.can_delete && <OsDnitDeleteButton url={route('tenant.os-dnit.orders.destroy', [tenantSlug, order.id])} data={{ version: order.workflow_version }} label={`Excluir OS ${order.request_number}`} title="Excluir rascunho?" message={`Deseja excluir o rascunho da OS ${order.request_number}? Ele deixará de aparecer nas listagens.`} />}
            {order.can_analyze && <button type="button" onClick={() => onAnalyze(order.id)} className="sig-btn sig-btn-primary" aria-label={`Analisar OS ${order.request_number}`}><Eye size={16} />Analisar OS</button>}
            {order.can_submit && <button type="button" disabled={form.processing} onClick={() => setConfirmSubmit(true)} className="sig-btn sig-btn-primary" aria-label={`Enviar OS ${order.request_number} para análise`}><Send size={16} />{form.processing ? 'Enviando…' : 'Enviar para análise'}</button>}
        </div>
        {Object.keys(form.errors).length > 0 && <div role="alert" className="max-w-md rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{[...new Set(Object.values(form.errors))].map((error) => <p key={error}>{error}</p>)}</div>}
        <OsDnitConfirmDialog open={confirmSubmit} title="Enviar OS para análise?"
            message={`Deseja enviar a OS ${order.request_number} ao fiscal Concremat? Após o envio, a solicitação ficará bloqueada para edição.`}
            disabled={form.processing} onCancel={() => setConfirmSubmit(false)} onConfirm={() => {
                setConfirmSubmit(false);
                if (!form.processing) form.post(route('tenant.os-dnit.orders.submit', [tenantSlug, order.id]), { preserveScroll: true });
            }} />
    </div>;
}

export default function Orders({ orders, contracts, filters, canCreate, statuses, stageCounts, analysisOrderId = null }) {
    const { currentTenant, flash = {} } = usePage().props;
    const search = useForm({ q: filters.q || '', contract_id: filters.contract_id || '', status: filters.status || '', pending: filters.pending ? 1 : 0, stage: filters.stage || 'all' });
    const indexUrl = route('tenant.os-dnit.orders.index', currentTenant.slug);
    const title = 'Ordem de Serviço';
    const [selectedOrderId, setSelectedOrderId] = useState(analysisOrderId);
    const [filtering, setFiltering] = useState(false);
    const filterTimer = useRef(null);
    const filterRequest = useRef(null);
    const filterVersion = useRef(0);
    useEffect(() => { setSelectedOrderId(analysisOrderId); }, [analysisOrderId]);
    useEffect(() => () => {
        clearTimeout(filterTimer.current);
        filterVersion.current++;
        filterRequest.current?.cancel();
    }, []);
    useEffect(() => {
        search.setData({ q: filters.q || '', contract_id: filters.contract_id || '', status: filters.status || '', pending: filters.pending ? 1 : 0, stage: filters.stage || 'all' });
    }, [filters.q, filters.contract_id, filters.status, filters.pending, filters.stage]);

    function filterOrders(data, delay = 0) {
        clearTimeout(filterTimer.current);
        const version = ++filterVersion.current;
        filterRequest.current?.cancel();
        filterRequest.current = null;
        search.setData(data);
        setFiltering(true);
        const visit = () => router.get(indexUrl, data, {
            preserveState: true, preserveScroll: true, replace: true,
            onCancelToken: (token) => { filterRequest.current = token; },
            onFinish: () => {
                if (filterVersion.current === version) {
                    setFiltering(false);
                    filterRequest.current = null;
                }
            },
        });
        if (delay) filterTimer.current = setTimeout(visit, delay);
        else visit();
    }

    return <AuthenticatedLayout>
        <Head title={`OS DNIT · ${title}`} />
        <div className="sig-content os-dnit-page space-y-6">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div><div className="eyebrow">OS DNIT</div><h1 className="mt-2 text-2xl font-semibold">{title}</h1><p className="mt-2 text-sm text-[var(--ink-500)]">Solicitações, análise e autorização dos serviços por contrato.</p></div>
                <div className="flex flex-wrap gap-2"><OsDnitTourButton tenantSlug={currentTenant.slug} />{canCreate && <Link href={route('tenant.os-dnit.orders.create', currentTenant.slug)} className="sig-btn sig-btn-primary"><Plus size={16} />Criar OS</Link>}</div>
            </header>
            {flash.success && <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{flash.success}</div>}
            {!canCreate && <p className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Para criar uma OS, você precisa ser cadastrado como Analista DNIT em Responsáveis e ter vínculo ativo com o contrato.</p>}
            <nav className="flex flex-wrap gap-2" aria-label="Filtrar OSs por etapa">{[
                ['all', 'Todas as OSs'], ['analyst', 'Rascunho (Analista DNIT)'], ['concremat', 'Análise Concremat'], ['contract_inspector', 'Fiscal do Contrato'], ['completed', 'Finalizadas'],
            ].map(([stage, label]) => {
                const active = search.data.stage === stage && !search.data.pending;
                return <button type="button" key={stage} onClick={() => filterOrders({ ...search.data, stage, status: '', pending: 0 })} className={`sig-btn ${active ? 'sig-btn-primary' : 'sig-btn-secondary'}`} aria-pressed={active}>{label}<span className={`rounded-full px-2 py-0.5 tabular-nums ${active ? 'bg-white/20' : 'bg-slate-100 text-slate-700'}`}>{stageCounts[stage]}</span></button>;
            })}</nav>
            <form onSubmit={(event) => { event.preventDefault(); filterOrders(search.data); }} className="sig-card flex flex-wrap items-end gap-3 p-4">
                <div className="min-w-48 flex-1"><label htmlFor="order-search" className="mb-1.5 block text-xs font-medium">Buscar OS</label><input id="order-search" value={search.data.q} onChange={(event) => filterOrders({ ...search.data, q: event.target.value }, 350)} maxLength={120} placeholder="Número ou assunto da solicitação" className="w-full rounded-lg border-slate-300 text-sm" /></div>
                <div className="min-w-48 flex-1"><label htmlFor="order-contract" className="mb-1.5 block text-xs font-medium">Contrato de supervisão</label><select id="order-contract" value={search.data.contract_id} onChange={(event) => filterOrders({ ...search.data, contract_id: event.target.value })} className="w-full rounded-lg border-slate-300 text-sm"><option value="">Todos os contratos</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} — {contract.name}</option>)}</select></div>
                <div className="min-w-48 flex-1"><label htmlFor="order-status" className="mb-1.5 block text-xs font-medium">Situação</label><select id="order-status" value={search.data.status} onChange={(event) => filterOrders({ ...search.data, status: event.target.value })} className="w-full rounded-lg border-slate-300 text-sm"><option value="">Todas as situações</option>{Object.entries(statuses).map(([key, value]) => <option key={key} value={key}>{value}</option>)}</select></div>
                {(search.data.q || search.data.contract_id || search.data.status || search.data.pending || search.data.stage !== 'all') && <button type="button" onClick={() => filterOrders({ q: '', contract_id: '', status: '', pending: 0, stage: 'all' })} className="sig-btn sig-btn-secondary">Limpar</button>}
            </form>
            <div role="status" aria-live="polite" className="text-sm text-[var(--ink-500)]">{filtering ? 'Atualizando resultados…' : `${orders.total} ${orders.total === 1 ? 'OS encontrada' : 'OS encontradas'}`}</div>
            <Pagination data={orders} disabled={filtering} />
            <div className="space-y-3" aria-busy={filtering}>
                {orders.data.map((order) => <article key={order.id} className="rounded-xl border border-slate-200 bg-white p-5">
                    <div className="flex flex-wrap justify-between gap-3"><div className="min-w-0"><span className="font-mono text-xs font-bold text-[var(--primary)]">OS {order.request_number}</span><h2 className="mt-1 break-words font-semibold">{order.subject}</h2></div><StatusBadge status={order.status} statuses={statuses} /></div>
                    <p className="mt-3 text-sm text-[var(--ink-500)]">{order.contract.code} · Lote {order.supervision_lot} · {order.highway_name} · Km {numberLabel(order.km_start)} a {numberLabel(order.km_end)}</p>
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-4">
                        <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-[var(--ink-500)]"><span>Prazo: {dateLabel(order.desired_deadline)}</span><span>{order.items_count} produto(s)</span><span>{order.attachments_count} anexo(s)</span><span>Criada em {dateLabel(order.created_at)}</span></div>
                        <OrderActions key={`${order.id}-${order.workflow_version}`} order={order} tenantSlug={currentTenant.slug} onAnalyze={setSelectedOrderId} />
                    </div>
                </article>)}
                {orders.data.length === 0 && <div className="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center"><ClipboardList size={30} className="mx-auto text-slate-400" /><h2 className="mt-4 font-semibold">{filters.pending ? 'Nenhuma análise pendente' : filters.q || filters.contract_id || filters.status || filters.stage !== 'all' ? 'Nenhuma OS encontrada' : 'Nenhuma OS cadastrada'}</h2><p className="mt-2 text-sm text-[var(--ink-500)]">{filters.pending ? 'Você não tem OSs para analisar com os filtros selecionados.' : filters.q || filters.contract_id || filters.status || filters.stage !== 'all' ? 'Ajuste os filtros para consultar outras solicitações.' : 'Clique em Criar OS para registrar a primeira solicitação.'}</p></div>}
            </div>
            {orders.last_page > 1 && <Pagination data={orders} disabled={filtering} />}
            {selectedOrderId && <OsDnitAnalysisModal orderId={selectedOrderId} tenantSlug={currentTenant.slug} onClose={() => setSelectedOrderId(null)} />}
        </div>
    </AuthenticatedLayout>;
}
