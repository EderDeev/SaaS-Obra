import ConfirmActionButton from '@/Components/ConfirmActionButton';
import { Pagination, dateLabel } from '@/Components/OsDnitOrderUi';
import { startRncTour } from '@/Components/RncTour';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { rncDisciplinaLabel } from '@/Support/rnc';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Bell, ClipboardCheck, ClipboardX, Download, Eye, ImagePlus, MapPin, Pencil, Plane, Plus, SearchCheck, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const gravityClass = { Leve: 'sig-pill-blue', Media: 'sig-pill-amber', 'Média': 'sig-pill-amber', 'MÃ©dia': 'sig-pill-amber',
    Grave: 'sig-pill-red', Gravissima: 'sig-pill-red', 'Gravíssima': 'sig-pill-red', 'GravÃ­ssima': 'sig-pill-red' };
const stages = [ ['all', 'Todas as RNCs'], ['draft', 'Rascunhos'], ['corrective_action', 'Ação corretiva'],
    ['analysis', 'Em análise'], ['evidence', 'Aguardando evidências'], ['completed', 'Finalizadas'] ];
const statusClass = { draft: '', awaiting_action: 'sig-pill-blue', returned: 'sig-pill-amber',
    awaiting_review: 'sig-pill-blue', awaiting_evidence: 'sig-pill-blue', completed: 'sig-pill-green' };
const pdfUrl = (tenant, rnc) => `${route('tenant.qualidade.rnc.pdf', [tenant.slug, rnc.id])}?v=${encodeURIComponent(rnc.updated_at || rnc.notified_at || rnc.id)}`;
const can = (rnc, permission) => rnc.user_permissions?.includes(permission);

function RncActions({ rnc, tenant }) {
    let workflowAction = null;
    if (rnc.listing_status === 'draft' && can(rnc, 'notify_rnc')) {
        workflowAction = <Link href={route('tenant.qualidade.rnc.notify', [tenant.slug, rnc.id])} method="post" as="button" preserveScroll className="sig-btn sig-btn-primary"><Bell size={16} />Notificar responsáveis</Link>;
    } else if (['awaiting_action', 'returned'].includes(rnc.listing_status) && can(rnc, 'corrective_action_rnc')) {
        workflowAction = <Link href={route('tenant.qualidade.rnc.acao-corretiva.create', [tenant.slug, rnc.id])} className="sig-btn sig-btn-primary"><ClipboardCheck size={16} />{rnc.listing_status === 'returned' ? 'Revisar proposta' : 'Ação corretiva'}</Link>;
    } else if (rnc.listing_status === 'awaiting_review' && can(rnc, 'review_rnc')) {
        workflowAction = <Link href={route('tenant.qualidade.rnc.analisar-proposta.create', [tenant.slug, rnc.id])} className="sig-btn sig-btn-primary"><SearchCheck size={16} />Analisar proposta</Link>;
    } else if (rnc.listing_status === 'awaiting_evidence' && can(rnc, 'evidence_rnc')) {
        workflowAction = <Link href={route('tenant.qualidade.rnc.evidencias.create', [tenant.slug, rnc.id])} className="sig-btn sig-btn-primary"><ImagePlus size={16} />Evidenciar correção</Link>;
    }
    return <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:justify-end">
        <Link href={route('tenant.qualidade.rnc.show', [tenant.slug, rnc.id])} className="sig-btn sig-btn-secondary" aria-label={`Acessar RNC ${rnc.formatted_number}`}><Eye size={16} />Acessar</Link>
        {can(rnc, 'edit_rnc') && <Link href={route('tenant.qualidade.rnc.edit', [tenant.slug, rnc.id])} className="sig-btn sig-btn-secondary"><Pencil size={16} />Editar</Link>}
        <a href={pdfUrl(tenant, rnc)} className="sig-btn sig-btn-secondary" target="_blank" rel="noreferrer"><Download size={16} />PDF</a>
        {can(rnc, 'delete_rnc') && <ConfirmActionButton title="Excluir RNC" message={`Deseja excluir a RNC ${rnc.formatted_number}? Ela sairá da listagem, mas o histórico será mantido.`}
            confirmLabel="Excluir RNC" className="sig-btn sig-btn-secondary text-[var(--red)]" onConfirm={() => router.delete(route('tenant.qualidade.rnc.destroy', [tenant.slug, rnc.id]), { preserveScroll: true })}><Trash2 size={16} />Excluir</ConfirmActionButton>}
        {workflowAction}
    </div>;
}

export default function RelatorioNaoConformidadeIndex({ tenant, rncs, canCreateRnc, contracts = [], obras = [], filters = {}, statuses = {}, stageCounts = {} }) {
    const { flash = {}, errors = {} } = usePage().props;
    const search = useForm({ q: filters.q || '', contract_id: filters.contract_id || '', obra_id: filters.obra_id || '', status: filters.status || '', stage: filters.stage || 'all' });
    const [filtering, setFiltering] = useState(false);
    const timer = useRef(null);
    const request = useRef(null);
    const version = useRef(0);
    const indexUrl = route('tenant.qualidade.rnc.index', tenant.slug);
    const availableObras = obras.filter((obra) => !search.data.contract_id || String(obra.contract_id) === String(search.data.contract_id));
    const hasFilters = Boolean(search.data.q || search.data.contract_id || search.data.obra_id || search.data.status || search.data.stage !== 'all');
    useEffect(() => () => { clearTimeout(timer.current); version.current++; request.current?.cancel(); }, []);
    useEffect(() => {
        search.setData({ q: filters.q || '', contract_id: filters.contract_id || '', obra_id: filters.obra_id || '', status: filters.status || '', stage: filters.stage || 'all' });
    }, [filters.q, filters.contract_id, filters.obra_id, filters.status, filters.stage]);

    function filterRncs(data, delay = 0) {
        clearTimeout(timer.current);
        const currentVersion = ++version.current;
        request.current?.cancel();
        request.current = null;
        search.setData(data);
        setFiltering(true);
        const visit = () => router.get(indexUrl, data, {
            preserveState: true, preserveScroll: true, replace: true,
            onCancelToken: (token) => { request.current = token; },
            onFinish: () => { if (version.current === currentVersion) { setFiltering(false); request.current = null; } },
        });
        if (delay) timer.current = setTimeout(visit, delay);
        else visit();
    }

    return <AuthenticatedLayout>
        <Head title="Relatório de Não Conformidade" />
        <div className="sig-content quality-mobile-page space-y-6">
            <header className="quality-page-header flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0"><div className="eyebrow">Qualidade</div><h1 className="mt-2 text-2xl font-semibold">Relatório de Não Conformidade</h1><p className="mt-2 text-sm text-[var(--ink-500)]">Acompanhe as RNCs, propostas e evidências da correção por contrato e obra.</p></div>
                <div className="quality-page-actions flex flex-wrap gap-2">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={() => startRncTour(tenant.slug)}><Plane size={16} />Iniciar tour</button>
                    {canCreateRnc && <Link href={route('tenant.qualidade.rnc.create', tenant.slug)} className="sig-btn sig-btn-primary"><Plus size={16} />Nova RNC</Link>}
                </div>
            </header>
            {flash.success && <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{flash.success}</div>}
            <nav className="flex flex-wrap gap-2" aria-label="Filtrar RNCs por etapa">{stages.map(([stage, label]) => {
                const active = search.data.stage === stage;
                return <button type="button" key={stage} onClick={() => filterRncs({ ...search.data, stage, status: '' })} className={`sig-btn ${active ? 'sig-btn-primary' : 'sig-btn-secondary'}`} aria-pressed={active}>{label}<span className={`rounded-full px-2 py-0.5 tabular-nums ${active ? 'bg-white/20' : 'bg-slate-100 text-slate-700'}`}>{stageCounts[stage] || 0}</span></button>;
            })}</nav>
            <form onSubmit={(event) => { event.preventDefault(); filterRncs(search.data); }} className="sig-card grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-4">
                <FilterField id="rnc-search" label="Buscar RNC"><input id="rnc-search" value={search.data.q} onChange={(event) => filterRncs({ ...search.data, q: event.target.value }, 350)} placeholder="Número ou descrição da RNC" maxLength={120} className="w-full rounded-lg border-slate-300 text-sm" /></FilterField>
                <FilterField id="rnc-contract" label="Contrato"><select id="rnc-contract" value={search.data.contract_id} onChange={(event) => filterRncs({ ...search.data, contract_id: event.target.value, obra_id: '' })} className="w-full min-w-0 rounded-lg border-slate-300 text-sm"><option value="">Todos os contratos</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} — {contract.name}</option>)}</select></FilterField>
                <FilterField id="rnc-status" label="Situação"><select id="rnc-status" value={search.data.status} onChange={(event) => filterRncs({ ...search.data, status: event.target.value, stage: 'all' })} className="w-full rounded-lg border-slate-300 text-sm"><option value="">Todas as situações</option>{Object.entries(statuses).map(([status, label]) => <option key={status} value={status}>{label}</option>)}</select></FilterField>
                <FilterField id="rnc-obra" label="Obra"><select id="rnc-obra" value={search.data.obra_id} onChange={(event) => filterRncs({ ...search.data, obra_id: event.target.value })} className="w-full min-w-0 rounded-lg border-slate-300 text-sm"><option value="">Todas as obras</option>{availableObras.map((obra) => <option key={obra.id} value={obra.id}>{obra.codigo} — {obra.nome}</option>)}</select></FilterField>
                {hasFilters && <div className="sm:col-span-2 xl:col-span-4"><button type="button" onClick={() => filterRncs({ q: '', contract_id: '', obra_id: '', status: '', stage: 'all' })} className="sig-btn sig-btn-secondary">Limpar filtros</button></div>}
            </form>
            {Object.keys(errors).length > 0 && <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{[...new Set(Object.values(errors))].map((error) => <p key={error}>{error}</p>)}</div>}
            <div role="status" aria-live="polite" className="text-sm text-[var(--ink-500)]">{filtering ? 'Atualizando resultados…' : `${rncs.total} ${rncs.total === 1 ? 'RNC encontrada' : 'RNCs encontradas'}`}</div>
            <Pagination data={rncs} label="RNCs" disabled={filtering} />
            <div className="space-y-3" aria-busy={filtering}>
                {rncs.data.map((rnc) => <article key={rnc.id} className="sig-card min-w-0 p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0 flex-1 basis-64"><span className="font-mono text-xs font-bold text-[var(--primary)]">RNC {rnc.formatted_number}</span><h2 className="mt-1 line-clamp-2 break-words font-semibold">{rnc.descricao_problema}</h2></div>
                        <span className={`sig-pill ${statusClass[rnc.listing_status] || ''}`}>{statuses[rnc.listing_status] || rnc.status}</span>
                    </div>
                    <p className="mt-3 break-words text-sm text-[var(--ink-500)]">{rnc.contract?.code}{rnc.contract?.name && ` — ${rnc.contract.name}`} · {[rnc.obra?.codigo, rnc.obra?.nome].filter(Boolean).join(' — ')}</p>
                    <div className="mt-3 flex flex-wrap items-center gap-2"><span className="sig-pill sig-pill-blue">{rncDisciplinaLabel(rnc)}</span><span className={`sig-pill ${gravityClass[rnc.gravidade] || ''}`}>{rnc.gravidade}</span></div>
                    <div className="mt-4 flex flex-wrap items-center justify-between gap-4">
                        <div className="flex min-w-0 flex-wrap gap-x-5 gap-y-2 text-xs text-[var(--ink-500)]"><span>Abertura: {dateLabel(rnc.opened_at)}</span><span>Prazo de resposta: {dateLabel(rnc.prazo_resposta_acao_corretiva)}</span><span>{rnc.photos_count || 0} foto(s)</span><span className="inline-flex min-w-0 items-center gap-1"><MapPin size={13} className="shrink-0" />{rnc.latitude != null && rnc.longitude != null ? `${rnc.latitude}, ${rnc.longitude}` : 'Sem coordenadas'}</span></div>
                        <RncActions rnc={rnc} tenant={tenant} />
                    </div>
                    <p className="mt-3 break-words text-xs text-[var(--ink-500)]">Contratante: {rnc.contratante?.sigla || rnc.contratante?.nome || '—'} · Contratada: {rnc.contratada?.sigla || rnc.contratada?.nome || '—'}</p>
                </article>)}
                {rncs.data.length === 0 && <div className="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center"><ClipboardX size={30} className="mx-auto text-slate-400" /><h2 className="mt-4 font-semibold">{hasFilters ? 'Nenhuma RNC encontrada' : 'Nenhuma RNC cadastrada'}</h2><p className="mt-2 text-sm text-[var(--ink-500)]">{hasFilters ? 'Ajuste os filtros para consultar outros registros.' : 'Crie uma RNC para registrar e acompanhar uma não conformidade.'}</p>{!hasFilters && canCreateRnc && <Link href={route('tenant.qualidade.rnc.create', tenant.slug)} className="sig-btn sig-btn-primary mt-5"><Plus size={16} />Nova RNC</Link>}</div>}
            </div>
            {rncs.last_page > 1 && <Pagination data={rncs} label="RNCs" disabled={filtering} />}
        </div>
    </AuthenticatedLayout>;
}

function FilterField({ id, label, children }) {
    return <div className="min-w-0"><label htmlFor={id} className="mb-1.5 block text-xs font-medium">{label}</label>{children}</div>;
}
