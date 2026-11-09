import { partyLabels } from './WorkflowEditor';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowDown, ArrowLeft, ArrowUp, Camera, CheckCircle2, ClipboardList, ClipboardPenLine, CloudSun, Construction, Eye, FileText, GitCompareArrows, History, ImagePlus, MessageSquareText, Plus, RefreshCw, Save, Send, Trash2, Upload, Users, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import PdfDownloadModal from './PdfDownloadModal';
import DocumentWorkspace, { isImpracticableDay, RdoSection, ReadSection } from './DocumentWorkspace';
import RdoCatalogPicker from '@/Components/RdoCatalogPicker';
import InspectionEvidenceEditor from './InspectionEvidenceEditor';
import InspectionDocumentFile from './InspectionDocumentFile';
import ManagerFlowActions from './ManagerFlowActions';
import ClientReviewPanel from './ClientReviewPanel';
import { rdoParty } from './partyAppearance';
import './partyAppearance.css';

const sections = [
    { key: 'clima', label: 'Condições do tempo', icon: CloudSun, description: 'Manhã, tarde, noite, chuva e condições impeditivas.' },
    { key: 'mao_obra', label: 'Mão de obra', icon: Users, description: 'Efetivo direto, indireto e empresas terceirizadas.' },
    { key: 'equipamentos', label: 'Equipamentos', icon: Construction, description: 'Máquinas, equipamentos, utilização e paralisações.' },
    { key: 'atividades', label: 'Atividades e ocorrências', icon: FileText, description: 'Serviços executados, fatos relevantes e interferências.' },
    { key: 'fotos', label: 'Registro fotográfico', icon: Camera, description: 'Fotos de campo com data, comentário e sincronização mobile.' },
    { key: 'comentarios', label: 'Comentários', icon: MessageSquareText, description: 'Construtora, gerenciadora/fiscalização e cliente.' },
];

const emptySection = {
    clima: {
        manha: '',
        tarde: '',
        noite: '',
        precipitacao_manha_mm: '',
        precipitacao_tarde_mm: '',
        precipitacao_noite_mm: '',
        observacoes: '',
        dia_impraticavel: false,
    },
    mao_obra: { efetivos: {}, subcontratadas: {}, observacoes: '' },
    equipamentos: { registros: {}, observacoes: '' },
    atividades: { atividades: [] },
    fotos: { arquivos: [], novas_fotos: [], ordem_fotos: [] },
    comentarios: { construtora: '', gerenciadora: '', cliente: '' },
};

export default function Show({ rdo, catalogs = {}, record_catalogs = null }) {
    const { currentTenant, flash = {} } = usePage().props;
    const [completeRdoOpen, setCompleteRdoOpen] = useState(false);
    const [pdfDownloadOpen, setPdfDownloadOpen] = useState(false);
    const [activeSection, setActiveSection] = useState(null);
    const [activeObraId, setActiveObraId] = useState(null);
    const [workspaceMode, setWorkspaceMode] = useState(rdo.can_edit && rdo.can_fill ? 'edit' : 'view');
    const [flowProcessing, setFlowProcessing] = useState(false);
    const [signatureProcessing, setSignatureProcessing] = useState(false);
    const [signatureRefreshProcessing, setSignatureRefreshProcessing] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [historyEvents, setHistoryEvents] = useState([]);
    const [historyError, setHistoryError] = useState('');
    const [deadlineModalOpen, setDeadlineModalOpen] = useState(false);
    const [inspectionOpen, setInspectionOpen] = useState(false);
    const [divergenceOpen, setDivergenceOpen] = useState(false);
    const [reviewDivergencesOpen, setReviewDivergencesOpen] = useState(false);
    const [divergenceAction, setDivergenceAction] = useState(null);
    const sectionForm = useForm({ obra_id: '', filling_party: rdo.fill_party, dados: {}, fotos: [] });
    const holidayForm = useForm({ is_holiday: rdo.is_holiday == null ? '' : rdo.is_holiday ? '1' : '0' });
    const completeForm = useForm({ obra_id: '', filling_party: rdo.fill_party, secoes: {}, fotos: [] });
    const fillDrafts = useRef({});
    const [fillDraftCount, setFillDraftCount] = useState(0);
    const photoPreviews = useRef(new Set());
    (completeForm.data.secoes?.fotos?.novas_fotos || []).forEach(photo => photoPreviews.current.add(photo.preview_url));
    useEffect(() => () => photoPreviews.current.forEach(url => URL.revokeObjectURL(url)), []);
    const flowForm = useForm({ comment: '', return_to: '', comments_by_obra: {}, reservations_by_obra: {}, reservation_obra_ids: [], obra_ids: rdo.flow_obra_ids || [] });
    const [pendingFlow, setPendingFlow] = useState(null);
    const managerOnly = rdo.workflow?.model === 'gerenciadora';
    const unsavedFilling = completeForm.isDirty || fillDraftCount > 0 || holidayForm.isDirty;
    const canFinalize = managerOnly && rdo.can_fill && Boolean(rdo.flow_actions?.submit);
    const finalizing = managerOnly && pendingFlow?.action === 'submit';
    const confirmationLabel = pendingFlow ? ({
        approve: rdo.active_stage === 'cliente' && rdo.workflow?.client_comment_only ? 'Confirmar comentário' : 'Confirmar aprovação',
        approve_with_reservations: 'Confirmar aprovação com ressalvas',
        return: 'Confirmar devolução',
        submit: finalizing ? 'Confirmar finalização'
            : rdo.status === 'pendente_comprovacao' ? 'Confirmar envio da comprovação'
            : rdo.status === 'devolvido_construtora' ? 'Confirmar reenvio' : 'Confirmar envio',
    }[pendingFlow.action] || 'Confirmar ação') : '';
    const inspectionForm = useForm(inspectionValues(null, rdo.flow_obra_ids?.[0] || ''));
    const divergenceForm = useForm({ obra_id: rdo.flow_obra_ids?.[0] || '', topico: 'Atividades e ocorrências', titulo: '', versao_construtora: '', versao_gerenciadora: '' });
    const divergenceActionForm = useForm({ action: '', response: '', conclusion: '', outcome: 'resolvida', client_decision: 'aceita_gerenciadora', client_comment: '' });
    const manualSignatureForm = useForm({ signed_pdf: null });
    const allObras = (rdo.obras?.length ? rdo.obras : [rdo.obra]).filter(Boolean);
    const fillLocked = rdo.can_edit && !rdo.can_fill;
    const editableObras = rdo.can_edit
        ? allObras.filter((obra) => rdo.editable_obra_ids?.includes(Number(obra.id)))
        : allObras;

    const loadSectionForObra = (key, obraId) => {
        sectionForm.clearErrors();
        sectionForm.setData({
            obra_id: obraId,
            filling_party: rdo.fill_party,
            dados: structuredClone(rdo.sections?.[obraId]?.[key] || emptySection[key]),
            fotos: [],
        });
        setActiveObraId(obraId);
    };

    const openSection = (key) => {
        if (fillLocked) {
            setDeadlineModalOpen(true);
            return;
        }

        const firstObraId = editableObras[0]?.id;
        loadSectionForObra(key, firstObraId);
        setActiveSection(key);
    };

    const loadCompleteForObra = (obraId, useDraft = false) => {
        completeForm.clearErrors();
        const next = { obra_id: obraId, filling_party: rdo.fill_party,
            secoes: Object.fromEntries(sections.map(({ key }) => [key, structuredClone(rdo.sections?.[obraId]?.[key] || emptySection[key])])), fotos: [] };
        completeForm.setData(useDraft && fillDrafts.current[obraId] ? fillDrafts.current[obraId] : next);
        completeForm.setDefaults(next);
        setActiveObraId(obraId);
    };

    const closeSection = () => {
        if (!sectionForm.processing) setActiveSection(null);
    };

    const saveSection = (event) => {
        event.preventDefault();
        sectionForm.post(route('tenant.diario-obra.rdo.sections.store', [currentTenant.slug, rdo.id, activeSection]), {
            preserveScroll: true,
            forceFormData: activeSection === 'fotos',
            onSuccess: closeSection,
        });
    };

    const saveComplete = (event) => {
        event.preventDefault();
        const drafts = { ...fillDrafts.current };
        if (completeForm.isDirty || !Object.keys(drafts).length) drafts[activeObraId] = completeForm.data;
        completeForm.transform(() => ({ filling_party: rdo.fill_party, is_holiday: holidayForm.data.is_holiday === '' ? null : holidayForm.data.is_holiday === '1', obras: Object.values(drafts) }));
        completeForm.post(route('tenant.diario-obra.rdo.sections.store-works', [currentTenant.slug, rdo.id]), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => { fillDrafts.current = {}; setFillDraftCount(0); holidayForm.setDefaults({ ...holidayForm.data }); },
        });
    };

    const changeFlow = (action, extras = {}) => setPendingFlow({ action, extras });
    const executeFlow = () => {
        if (!pendingFlow || flowProcessing) return;
        const { action, extras } = pendingFlow;
        flowForm.clearErrors();
        setFlowProcessing(true);

        router.post(route('tenant.diario-obra.rdo.flow', [currentTenant.slug, rdo.id]), {
            action,
            filling_party: rdo.fill_party,
            comment: action === 'submit' && rdo.status === 'rascunho' ? '' : flowForm.data.comment,
            comments_by_obra: ['em_analise_construtora', 'em_analise_gerenciadora', 'em_aprovacao_cliente'].includes(rdo.status) ? flowForm.data.comments_by_obra : {},
            reservations_by_obra: action === 'approve_with_reservations' ? flowForm.data.reservations_by_obra : {},
            ...(action === 'approve_with_reservations' && rdo.active_stage === 'cliente' ? { reservation_obra_ids: flowForm.data.reservation_obra_ids || [] } : {}),
            obra_ids: rdo.flow_obra_ids || [],
            ...extras,
        }, {
            preserveScroll: true,
            preserveState: 'errors',
            onError: (errors) => flowForm.setError(errors),
            onSuccess: () => { flowForm.setData({ ...flowForm.data, comment: '', return_to: '', comments_by_obra: {}, reservations_by_obra: {}, reservation_obra_ids: [] }); setPendingFlow(null); },
            onFinish: () => setFlowProcessing(false),
        });
    };

    const openInspection = (record = null) => {
        const allowedFronts = rdo.inspection_obra_ids ?? rdo.flow_obra_ids ?? [];
        const selected = allowedFronts.includes(Number(activeObraId)) ? activeObraId : allowedFronts[0];
        if (!record) record = (rdo.inspection_records || []).find(item => item.is_current_version && Number(item.obra_id) === Number(selected));
        const obraId = Number(record?.obra_id || selected || rdo.obras?.[0]?.id || '');
        inspectionForm.clearErrors();
        inspectionForm.setData(inspectionValues(record, obraId));
        setInspectionOpen(true);
    };

    const saveInspection = (event, drafts = {}) => {
        event.preventDefault();
        inspectionForm.transform(data => ({
            filling_party: rdo.fill_party,
            obras: Object.values({ ...drafts, [data.obra_id]: data }).map(record => {
                if (!rdo.parallel_manager_filling) return record;
                const { obra_id, status, evidencias, evidencias_metadados, evidencias_comentarios, evidencias_ordem } = record;
                return { obra_id, status, evidencias, evidencias_metadados, evidencias_comentarios, evidencias_ordem };
            }),
        }));
        inspectionForm.post(route('tenant.diario-obra.rdo.inspection.store-works', [currentTenant.slug, rdo.id]), {
            forceFormData: true,
            preserveScroll: true,
            preserveState: rdo.parallel_manager_filling,
            onSuccess: () => setInspectionOpen(false),
        });
    };

    const saveDivergence = (event) => {
        event.preventDefault();
        divergenceForm.post(route('tenant.diario-obra.rdo.divergences.store', [currentTenant.slug, rdo.id]), {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => {
                setDivergenceOpen(false);
                divergenceForm.reset();
            },
        });
    };

    const openDivergenceAction = (item, action) => {
        divergenceActionForm.clearErrors();
        divergenceActionForm.setData({ action, response: '', conclusion: '', outcome: 'resolvida', client_decision: 'aceita_gerenciadora', client_comment: '' });
        setDivergenceAction({ item, action });
    };

    const saveDivergenceAction = (event) => {
        event.preventDefault();
        divergenceActionForm.patch(route('tenant.diario-obra.rdo.divergences.update', [currentTenant.slug, rdo.id, divergenceAction.item.id]), {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => setDivergenceAction(null),
        });
    };

    const sendSignature = () => {
        setSignatureProcessing(true);
        router.post(route('tenant.diario-obra.rdo.signatures.store', [currentTenant.slug, rdo.id]), {}, {
            preserveScroll: true,
            preserveState: false,
            onFinish: () => setSignatureProcessing(false),
        });
    };

    const refreshSignature = (signatureId) => {
        setSignatureRefreshProcessing(true);
        router.post(route('tenant.diario-obra.rdo.signatures.refresh', [currentTenant.slug, rdo.id, signatureId]), {}, {
            preserveScroll: true,
            preserveState: false,
            onFinish: () => setSignatureRefreshProcessing(false),
        });
    };

    const uploadManualSignature = (event) => {
        event.preventDefault();
        manualSignatureForm.post(route('tenant.diario-obra.rdo.signatures.manual', [currentTenant.slug, rdo.id]), {
            forceFormData: true,
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => manualSignatureForm.reset(),
        });
    };

    const openHistory = async () => {
        setHistoryOpen(true);
        setHistoryLoading(true);
        setHistoryError('');

        try {
            const response = await fetch(`${route('tenant.diario-obra.rdo.history', [currentTenant.slug, rdo.id])}?limit=8`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error('Não foi possível carregar o histórico.');
            }

            const data = await response.json();
            setHistoryEvents(data.events || []);
        } catch (error) {
            setHistoryError(error.message || 'Não foi possível carregar o histórico.');
        } finally {
            setHistoryLoading(false);
        }
    };


    useEffect(() => {
        if (!activeSection) return undefined;
        const onKeyDown = (event) => event.key === 'Escape' && closeSection();
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [activeSection, sectionForm.processing]);

    useEffect(() => {
        flowForm.setData('obra_ids', rdo.flow_obra_ids || []);
    }, [rdo.status, JSON.stringify(rdo.flow_obra_ids || [])]);

    useEffect(() => {
        fillDrafts.current = {};
        setFillDraftCount(0);
        const id = (rdo.editable_obra_ids || []).includes(Number(activeObraId)) || !rdo.can_edit
            ? (activeObraId || allObras[0]?.id) : rdo.editable_obra_ids?.[0];
        if (id) loadCompleteForObra(id);
        setWorkspaceMode(rdo.can_edit && rdo.can_fill ? 'edit' : 'view');
    }, [rdo.id, rdo.fill_party, rdo.sections, rdo.can_edit, rdo.can_fill]);

    useEffect(() => {
        if (!completeForm.isDirty && !fillDraftCount && !holidayForm.isDirty) return;
        const unload = event => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', unload);
        const remove = router.on('before', event => {
            if (event.detail.visit.method === 'get' && !window.confirm('Existem alterações não salvas nesta frente. Deseja sair e descartá-las?')) event.preventDefault();
        });
        return () => { window.removeEventListener('beforeunload', unload); remove(); };
    }, [completeForm.isDirty, fillDraftCount, holidayForm.isDirty]);

    const renderWorkspaceField = key => {
        const form = scopeRdoForm(completeForm, key);
        if (key === 'clima') return <WeatherFields form={form} />;
        if (key === 'mao_obra') return <LaborFields form={form} catalogs={catalogs} historicalCatalogs={record_catalogs} />;
        if (key === 'equipamentos') return <EquipmentFields form={form} catalogs={catalogs} historicalCatalogs={record_catalogs} />;
        if (key === 'atividades') return <ActivityFields form={form} subjects={rdo.activity_subjects || []} />;
        if (key === 'comentarios') return <CommentFields form={form} fillParty={rdo.fill_party} />;
        return <PhotoFields form={form} preservePreviews />;
    };

    return (
        <AuthenticatedLayout>
            <Head title={`${rdo.code} - RDO`} />
            <div className="mx-auto max-w-6xl px-4 py-4 sm:px-6">
                <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <span className="eyebrow">Diário de Obra · {rdo.reference_date_formatted}</span>
                        <h1 className="mt-1 text-2xl font-bold">{rdo.code} <span className="text-sm font-medium text-[var(--ink-500)]">· {rdo.reference_date_formatted}</span></h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">{rdo.contract?.name}</p>
                    </div>
                    <div className="flex flex-wrap gap-2" data-rdo-document-actions>
                        <button type="button" onClick={() => setCompleteRdoOpen(true)} aria-haspopup="dialog"
                            className="inline-flex items-center gap-2 rounded-lg border px-4 py-2.5 font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 border-blue-200 bg-blue-100 text-blue-800 hover:bg-blue-200 focus-visible:ring-blue-500">
                            <Eye size={17} /> Visualizar RDO completo
                        </button>
                        <button type="button" onClick={() => setPdfDownloadOpen(true)} aria-haspopup="dialog"
                            className="inline-flex items-center gap-2 rounded-lg border px-4 py-2.5 font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 border-emerald-200 bg-emerald-100 text-emerald-800 hover:bg-emerald-200 focus-visible:ring-emerald-500">
                            <FileText size={17} /> Baixar PDF
                        </button>
                    <Link href={route('tenant.diario-obra.rdo.calendar', { tenant: currentTenant.slug, month: rdo.reference_date.slice(0, 7), contract_id: rdo.contract?.id, obra_id: rdo.obra?.id })}
                        className="inline-flex items-center gap-2 rounded-lg border px-4 py-2.5 font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 border-slate-200 bg-slate-100 text-slate-700 hover:bg-slate-200 focus-visible:ring-slate-500">
                        <ArrowLeft size={17} /> Voltar ao calendário
                    </Link>
                    </div>
                </div>

                {flash.success && <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 font-semibold text-emerald-700">{flash.success}</div>}

                {fillLocked && <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"><span>Prazo de preenchimento encerrado em {rdo.submission_deadline_formatted}.</span><button type="button" onClick={() => setDeadlineModalOpen(true)} className="sig-btn">Consultar prazo</button></div>}
                <DocumentWorkspace rdo={rdo} catalogs={catalogs} recordCatalogs={record_catalogs} form={completeForm} mode={workspaceMode}
                    canEdit={Boolean(rdo.can_edit && rdo.can_fill)} obraId={activeObraId}
                    partySelector={rdo.fill_parties?.length > 1 && (
                        <div className="flex flex-wrap gap-2" role="group" aria-label="Preenchimentos por parte">
                            {rdo.fill_parties.map(party => (
                                <button key={party} type="button" data-rdo-party={rdoParty(party)} aria-pressed={party === rdo.fill_party}
                                    className={`sig-btn ${party === rdo.fill_party ? 'sig-btn-primary rdo-party-selected' : ''}`}
                                    onClick={() => router.get(route('tenant.diario-obra.rdo.show', [currentTenant.slug, rdo.id]), { filling_party: party }, { preserveScroll: true, preserveState: false })}>
                                    {partyLabels[party]}
                                </button>
                            ))}
                        </div>
                    )}
                    onObraChange={id => { if (Number(id) === Number(activeObraId)) return true; if (workspaceMode === 'edit' && completeForm.isDirty) { fillDrafts.current[activeObraId] = completeForm.data; setFillDraftCount(Object.keys(fillDrafts.current).length); } loadCompleteForObra(id, workspaceMode === 'edit'); return true; }}
                    pendingWorks={fillDraftCount + (holidayForm.isDirty ? 1 : 0)}
                    renderDayFields={() => rdo.can_set_holiday === false
                        ? <p className="text-sm">Feriado (informado pela construtora): {rdo.is_holiday == null ? 'Não informado' : rdo.is_holiday ? 'Sim' : 'Não'}</p>
                        : <label className="grid max-w-sm gap-2 text-sm font-semibold">O dia do RDO foi feriado?<select aria-label="O dia do RDO foi feriado?" className="sig-input bg-white" value={holidayForm.data.is_holiday} onChange={event => holidayForm.setData('is_holiday', event.target.value)}><option value="">Não informado</option><option value="1">Sim</option><option value="0">Não</option></select></label>}
                    renderFields={renderWorkspaceField} onSave={saveComplete}
                    onAttachEvidence={rdo.can_manage_inspection && (rdo.inspection_obra_ids ?? rdo.flow_obra_ids ?? []).includes(Number(activeObraId)) ? () => openInspection() : undefined}
                    completeOpen={completeRdoOpen} onCompleteOpenChange={setCompleteRdoOpen}
                    onDownloadPdf={() => setPdfDownloadOpen(true)}
                    renderInspectionEvidence={files => <InspectionEvidenceList files={files} />}
                    renderCompleteExtras={() => <div className="space-y-4">{rdo.divergences?.length > 0 && <DivergencePanel rdo={{...rdo,can_create_divergence:false,can_respond_divergence:false,can_conclude_divergence:false,can_decide_divergence:false}} onCreate={() => {}} onAction={() => {}} />}<SignaturePanel rdo={{...rdo,can_manage_signatures:false}} manualForm={manualSignatureForm} /></div>} />

                {workspaceMode !== 'edit' && !rdo.parallel_manager_filling && <div className="mt-5">
                    {((rdo.workflow?.review_parties || ['gerenciadora', 'cliente']).includes('gerenciadora') || rdo.inspection_records?.length > 0) && <InspectionPanel key={rdo.id} rdo={rdo} onOpen={openInspection} filterable />}
                </div>}

                {rdo.active_stage === 'cliente' && rdo.status === 'em_aprovacao_cliente' && Object.keys(rdo.flow_actions || {}).length > 0 && <ClientReviewPanel rdo={rdo} form={flowForm} processing={flowProcessing} onAction={changeFlow} obraId={activeObraId} catalogs={record_catalogs || catalogs} renderEvidence={files => <InspectionEvidenceList files={files} />} renderFields={action => <ApprovalCommentFields rdo={rdo} form={flowForm} selectedObraIds={rdo.flow_obra_ids || []} needsComment showPrevious={false} showIntroduction={false} showReservations reservationChoices={Boolean(rdo.flow_actions?.approve_with_reservations)} />} />}

                <div className="mt-5">
                    {rdo.workflow?.model === 'gerenciadora_construtora' && rdo.divergences?.length > 0 && <button type="button" className="sig-btn mb-3" onClick={() => setReviewDivergencesOpen(true)}><MessageSquareText size={16} /> Consultar devoluções</button>}
                    {managerOnly ? (
                        <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[var(--border)] bg-white p-4">
                            <div>
                                <strong>{rdo.status === 'arquivado' ? 'RDO finalizado' : 'Finalização do RDO'}</strong>
                                <p className="mt-1 text-sm text-[var(--ink-500)]">{rdo.status === 'arquivado'
                                    ? 'Os registros estão disponíveis para consulta.'
                                    : 'Salve os registros de todas as frentes antes de finalizar. A finalização bloqueia a edição.'}</p>
                            </div>
                            {canFinalize && <button type="button" className="sig-btn sig-btn-primary disabled:opacity-50"
                                disabled={unsavedFilling || completeForm.processing || flowProcessing}
                                onClick={() => changeFlow('submit')}><CheckCircle2 size={16} /> Finalizar RDO</button>}
                        </section>
                    ) : <WorkflowPanel rdo={rdo} obraId={activeObraId} form={flowForm} processing={flowProcessing} onAction={changeFlow} />}
                </div>

                <SignaturePanel
                    rdo={rdo}
                    processing={signatureProcessing}
                    refreshProcessing={signatureRefreshProcessing}
                    onSend={sendSignature}
                    onRefresh={refreshSignature}
                    manualForm={manualSignatureForm}
                    onManualUpload={uploadManualSignature}
                />
            </div>

            {pdfDownloadOpen && <PdfDownloadModal rdo={rdo} pdfUrl={route('tenant.diario-obra.rdo.pdf', [currentTenant.slug, rdo.id])} onClose={() => setPdfDownloadOpen(false)} />}
            {pendingFlow && <div className="fixed inset-0 z-[150] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"><section role="dialog" aria-modal="true" data-rdo-flow-confirmation aria-label={confirmationLabel} className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl"><h2 className="text-lg font-bold">{confirmationLabel} do RDO?</h2><p className="mt-3 text-sm">{rdo.code} · {finalizing ? 'Todas as frentes serão finalizadas juntas e os registros ficarão bloqueados para edição.' : 'Todas as obras serão movimentadas juntas.'}{pendingFlow.extras.return_to && ` Destino: ${partyLabels[pendingFlow.extras.return_to]}.`}</p><p className="mt-2 text-sm text-[var(--ink-500)]">Os usuários envolvidos receberão um e-mail e uma notificação no sistema.</p>{Object.values(flowForm.errors).map((error, index) => <p key={index} role="alert" className="mt-2 text-sm text-red-600">{error}</p>)}<div className="mt-5 flex justify-end gap-2"><button type="button" disabled={flowProcessing} className="sig-btn" onClick={() => setPendingFlow(null)}>Cancelar</button><button type="button" disabled={flowProcessing} className="sig-btn sig-btn-primary" data-confirm-rdo-action onClick={executeFlow}>{flowProcessing ? 'Processando…' : confirmationLabel}</button></div></section></div>}
            {reviewDivergencesOpen && <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"><section role="dialog" aria-modal="true" aria-label="Devoluções do RDO" className="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-xl bg-white p-4 shadow-xl"><div className="mb-3 flex items-center justify-between"><h2 className="font-bold">Devoluções do RDO</h2><button type="button" className="sig-btn sig-btn-sm" onClick={() => setReviewDivergencesOpen(false)}><X size={17} /> Fechar</button></div><DivergencePanel rdo={rdo} onAction={openDivergenceAction} /></section></div>}
            {activeSection && (
                <SectionModal
                    activitySubjects={rdo.activity_subjects || []}
                    section={activeSection}
                    fillParty={rdo.fill_party}
                    form={sectionForm}
                    catalogs={catalogs}
                    obras={editableObras}
                    activeObraId={activeObraId}
                    onChangeObra={(obraId) => loadSectionForObra(activeSection, obraId)}
                    onClose={closeSection}
                    onSubmit={saveSection}
                    readOnly={!rdo.can_edit || fillLocked}
                />
            )}
            {historyOpen && (
                <HistoryModal
                    events={historyEvents}
                    loading={historyLoading}
                    error={historyError}
                    onClose={() => setHistoryOpen(false)}
                />
            )}
            {deadlineModalOpen && (
                <DeadlineExpiredModal
                    rdo={rdo}
                    onClose={() => setDeadlineModalOpen(false)}
                />
            )}
            {inspectionOpen && (
                <InspectionModal
                    rdo={rdo}
                    form={inspectionForm}
                    onClose={() => !inspectionForm.processing && setInspectionOpen(false)}
                    onSubmit={saveInspection}
                />
            )}
            {divergenceOpen && (
                <DivergenceModal
                    rdo={rdo}
                    form={divergenceForm}
                    onClose={() => !divergenceForm.processing && setDivergenceOpen(false)}
                    onSubmit={saveDivergence}
                />
            )}
            {divergenceAction && (
                <DivergenceActionModal
                    context={divergenceAction}
                    form={divergenceActionForm}
                    onClose={() => !divergenceActionForm.processing && setDivergenceAction(null)}
                    onSubmit={saveDivergenceAction}
                />
            )}
        </AuthenticatedLayout>
    );
}

function InspectionPanel({ rdo, onOpen, filterable = false }) {
    const allowedFronts = rdo.inspection_obra_ids ?? rdo.flow_obra_ids ?? [];
    const currentRecords = (rdo.inspection_records || []).filter((item) => item.is_current_version);
    const fronts = (rdo.obras?.length ? rdo.obras : [rdo.obra]).filter(Boolean);
    const [selectedFront, setSelectedFront] = useState(() => String(fronts[0]?.id || ''));
    const activeFront = selectedFront === 'all' || fronts.some(obra => String(obra.id) === selectedFront) ? selectedFront : String(fronts[0]?.id || '');
    const visibleRecords = filterable && activeFront !== 'all' ? currentRecords.filter(record => String(record.obra_id) === activeFront) : currentRecords;

    return (
        <section data-rdo-party={rdoParty('gerenciadora')} className="rdo-party-panel overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-sm">
            <header className="flex flex-col items-start gap-3 border-b border-[var(--border)] px-5 py-4">
                <div className="flex min-h-12 items-center gap-3">
                    <span className="rdo-party-accent rounded-lg bg-blue-50 p-2 text-blue-600"><ClipboardPenLine size={20} aria-hidden="true" /></span>
                    <div>
                    <h2 className="rdo-party-label text-base font-semibold">{rdo.parallel_manager_filling ? 'Evidências da gerenciadora' : 'Registros da gerenciadora'}</h2>
                    </div>
                </div>
                {rdo.can_manage_inspection && (
                    <button type="button" onClick={() => onOpen(filterable && allowedFronts.includes(Number(activeFront)) ? currentRecords.find(record => String(record.obra_id) === activeFront) || { obra_id: Number(activeFront) } : undefined)} className="sig-btn sig-btn-primary">
                        <Plus size={16} /> {rdo.parallel_manager_filling ? 'Anexar evidências' : 'Registrar'}
                    </button>
                )}
            </header>
            {filterable && <div className="flex flex-wrap gap-2 border-b border-[var(--border)] px-4 py-3" aria-label="Filtrar registros da gerenciadora por obra">
                {fronts.map(obra => <button key={obra.id} type="button" aria-pressed={activeFront === String(obra.id)} onClick={() => setSelectedFront(String(obra.id))} className={`sig-btn sig-btn-sm !whitespace-normal text-left ${activeFront === String(obra.id) ? 'rdo-party-selected sig-btn-primary ring-2 ring-blue-200 ring-offset-2' : 'border border-[var(--border)] bg-white'}`}>{activeFront === String(obra.id) && <CheckCircle2 size={17} className="shrink-0" />}<span>{obra.codigo} · {obra.nome}</span></button>)}
                {fronts.length > 1 && <button type="button" aria-pressed={activeFront === 'all'} onClick={() => setSelectedFront('all')} className={`sig-btn sig-btn-sm ${activeFront === 'all' ? 'rdo-party-selected sig-btn-primary' : 'border border-[var(--border)] bg-white'}`}>Todas as frentes</button>}
            </div>}
            <div className="divide-y divide-[var(--border)]">
                {visibleRecords.length ? visibleRecords.map((record) => (
                    <article key={record.id} className="p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="font-bold">{record.obra ? `${record.obra.codigo} - ${record.obra.nome}` : 'Frente de serviço'}</p>
                                <p className="mt-2 text-xs text-[var(--ink-500)]">
                                    {record.user?.name || 'Fiscalização'}{record.submitted_at ? ` · ${record.submitted_at}` : ''}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${record.status === 'concluido' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>{record.status_label}</span>
                                {rdo.can_manage_inspection && allowedFronts.includes(Number(record.obra_id)) && (
                                    <button type="button" onClick={() => onOpen(record)} className="sig-btn">Editar</button>
                                )}
                            </div>
                        </div>
                        <div className="mt-3 space-y-2">
                            {(!rdo.parallel_manager_filling || record.weather) && <RdoSection section="clima" title="Condições do tempo" collapsed>{record.weather ? <ReadSection section="clima" data={record.weather} catalogs={{}} rdo={rdo} obraId={record.obra_id} /> : <p className="text-sm text-[var(--ink-500)]">Nenhuma condição climática registrada pela gerenciadora nesta obra.</p>}</RdoSection>}
                            {(!rdo.parallel_manager_filling || record.activities?.length || record.summary || record.services_attested || record.occurrences || record.guidance) && <RdoSection section="atividades" title="Atividades" collapsed>
                                {record.activities?.length > 0 ? <div className="space-y-3">{record.activities.map((activity, index) => <div key={index} className="rounded-r-lg border-l-2 border-blue-500 bg-slate-50 px-4 py-3"><p className="text-xs font-semibold text-blue-600">Atividade {index + 1}</p><h3 className="mt-1 text-sm font-semibold">{activity.titulo}</h3><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-relaxed">{activity.ocorrencia}</p></div>)}</div> : <p className="whitespace-pre-wrap break-words text-sm text-[var(--ink-600)]">{record.summary || 'Nenhuma atividade registrada.'}</p>}
                                {(record.services_attested || record.occurrences || record.guidance) && <dl className="mt-3 grid gap-3 text-sm md:grid-cols-3">{[['Serviços atestados', record.services_attested], ['Ocorrências constatadas', record.occurrences], ['Orientações', record.guidance]].filter(([, value]) => value).map(([label, value]) => <div key={label}><dt className="font-semibold text-[var(--ink-700)]">{label}</dt><dd className="mt-1 whitespace-pre-wrap text-[var(--ink-600)]">{value}</dd></div>)}</dl>}
                            </RdoSection>}
                            {(!rdo.parallel_manager_filling || record.comment) && <RdoSection section="comentarios" title="Comentário" collapsed><p className="whitespace-pre-wrap break-words text-sm leading-relaxed">{record.comment || 'Nenhum comentário registrado.'}</p></RdoSection>}
                            <RdoSection section="fotos" title="Evidências" collapsed>{record.evidence?.length ? <InspectionEvidenceList files={record.evidence} /> : <p className="text-sm text-[var(--ink-500)]">Nenhuma evidência registrada.</p>}</RdoSection>
                        </div>
                    </article>
                )) : (
                    <p className="p-5 text-sm text-[var(--ink-500)]">
                        {rdo.parallel_manager_filling ? 'Nenhuma evidência complementar anexada nesta frente.' : rdo.status === 'rascunho' ? 'O registro será aberto na etapa de análise da gerenciadora.' : 'Nenhum registro da fiscalização foi concluído nesta versão.'}
                    </p>
                )}
            </div>
        </section>
    );
}

function DivergencePanel({ rdo, onCreate, onAction }) {
    const items = rdo.divergences || [];

    return (
        <section className="overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-sm">
            <header className="flex flex-col items-start gap-3 border-b border-[var(--border)] px-5 py-4">
                <div className="flex min-h-12 items-center gap-3">
                    <span className="rounded-lg bg-blue-50 p-2 text-blue-600"><GitCompareArrows size={20} aria-hidden="true" /></span>
                    <div>
                        <h2 className="text-base font-semibold">Divergências registradas</h2>
                    </div>
                </div>
            </header>
            <div className="max-h-[28rem] divide-y divide-[var(--border)] overflow-y-auto">
                {items.length ? items.map((item) => (
                    <article key={item.id} className="p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0 flex-1">
                                <p className="text-xs font-bold uppercase text-amber-700">{item.topic} · V{String(item.version_number).padStart(2, '0')}</p>
                                <h3 className="mt-1 font-black">{item.title}</h3>
                                <p className="mt-1 text-xs text-[var(--ink-500)]">{item.obra ? `${item.obra.codigo} - ${item.obra.nome}` : 'Frente não identificada'} · {item.created_at}</p>
                            </div>
                            <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{item.status_label}</span>
                        </div>
                        <div className="mt-3 grid gap-2 md:grid-cols-2">
                            <div className="rounded-lg bg-slate-50 p-3"><span className="eyebrow">Construtora</span><p className="mt-1 whitespace-pre-wrap text-sm">{item.contractor_version}</p></div>
                            <div className="rounded-lg bg-blue-50/60 p-3"><span className="eyebrow">Gerenciadora</span><p className="mt-1 whitespace-pre-wrap text-sm">{item.manager_version}</p></div>
                        </div>
                        {item.contractor_response && <p className="mt-2 rounded-lg border border-slate-200 p-3 text-sm"><strong>Resposta da construtora:</strong> {item.contractor_response}</p>}
                        {item.manager_conclusion && <p className="mt-2 rounded-lg border border-emerald-200 bg-emerald-50/50 p-3 text-sm"><strong>Conclusão da gerenciadora:</strong> {item.manager_conclusion}</p>}
                        {item.client_decision && <p className="mt-2 rounded-lg border border-blue-200 bg-blue-50/50 p-3 text-sm"><strong>Decisão do cliente:</strong> {item.client_decision.replaceAll('_', ' ')}{item.client_comment ? ` · ${item.client_comment}` : ''}</p>}
                        <div className="mt-3 flex flex-wrap justify-end gap-2">
                            {rdo.can_respond_divergence && item.status === 'aberta' && <button type="button" onClick={() => onAction(item, 'respond')} className="sig-btn sig-btn-primary">Responder</button>}
                            {rdo.can_conclude_divergence && item.status === 'respondida' && <button type="button" onClick={() => onAction(item, 'conclude')} className="sig-btn sig-btn-primary">Concluir análise</button>}
                            {rdo.can_decide_divergence && item.status === 'aguarda_cliente' && <button type="button" onClick={() => onAction(item, 'client_decide')} className="sig-btn sig-btn-primary">Registrar decisão</button>}
                        </div>
                    </article>
                )) : <p className="p-5 text-sm text-[var(--ink-500)]">Nenhuma divergência foi registrada neste RDO.</p>}
            </div>
        </section>
    );
}

function InspectionEvidenceList({ files = [] }) {
    if (!files?.length) return null;
    return <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{files.map((file, index) => !file.mime_type?.startsWith('image/') ? <div key={file.url || index} className="rounded-lg border border-[var(--border)] bg-white p-3 sm:col-span-2 lg:col-span-3"><div className="flex flex-wrap items-center gap-3"><InspectionDocumentFile file={file} /><a href={file.url} target="_blank" rel="noreferrer" className="sig-btn sig-btn-sm">Abrir arquivo</a></div>{file.comment && <p className="mt-2 whitespace-pre-wrap break-words text-sm text-[var(--ink-500)]">{file.comment}</p>}</div> : <a key={file.url || index} href={file.url} target="_blank" rel="noreferrer" className="overflow-hidden rounded-lg border border-slate-200 bg-slate-50 hover:border-blue-400">
        {file.mime_type?.startsWith('image/') ? <img src={file.url} alt={file.name} className="h-36 w-full bg-white object-contain" loading="lazy" /> : <div className="flex h-20 items-center justify-center text-slate-500"><FileText size={28} /></div>}
        <span className="block truncate p-2 text-xs font-medium" title={file.name}>{file.name}</span>{file.comment && <p className="px-2 pb-3 text-xs whitespace-pre-wrap break-words">{file.comment}</p>}
    </a>)}</div>;
}

function inspectionValues(record, obraId) {
    return {
        obra_id: obraId,
        atividades: record?.activities?.length ? record.activities : record?.summary ? [{ titulo: 'Registro da gerenciadora', ocorrencia: record.summary }] : [],
        comentario: record?.comment || '',
        clima: record?.weather || { ...emptySection.clima },
        servicos_atestados: record?.services_attested || '',
        ocorrencias: record?.occurrences || '',
        orientacoes: record?.guidance || '',
        status: 'concluido', evidencias: [], evidencias_metadados: [],
        evidencias_comentarios: Object.fromEntries((record?.evidence || []).map((file, i) => [i, file.comment || ''])),
        evidencias_ordem: (record?.evidence || []).map((_, i) => `existing:${i}`),
    };
}

function InspectionModal({ rdo, form, onClose, onSubmit }) {
    const title = rdo.parallel_manager_filling ? 'Evidências da gerenciadora' : 'Registro da gerenciadora';
    const drafts = useRef({});
    const current = (rdo.inspection_records || []).find(record => record.is_current_version && Number(record.obra_id) === Number(form.data.obra_id));
    const allowedFronts = rdo.inspection_obra_ids ?? rdo.flow_obra_ids ?? [];
    const fronts = (rdo.obras || []).filter(obra => allowedFronts.includes(Number(obra.id)));
    const selected = fronts.find(obra => Number(obra.id) === Number(form.data.obra_id));
    const changeFront = obraId => {
        if (Number(obraId) === Number(form.data.obra_id)) return;
        if (JSON.stringify(form.data) !== JSON.stringify(inspectionValues(current, form.data.obra_id))) drafts.current[form.data.obra_id] = form.data;
        const record = (rdo.inspection_records || []).find(item => item.is_current_version && Number(item.obra_id) === obraId);
        form.clearErrors();
        form.setData(drafts.current[obraId] || inspectionValues(record, obraId));
    };
    const activityForm = {
        data: { dados: { atividades: form.data.atividades } },
        setData: (_, value) => form.setData('atividades', value.atividades),
    };
    const weatherForm = { data: { dados: form.data.clima }, setData: (_, value) => form.setData('clima', value) };
    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" onMouseDown={event => event.target === event.currentTarget && onClose()}>
            <form role="dialog" aria-modal="true" aria-label={title} onSubmit={event => onSubmit(event, drafts.current)} className="max-h-[94vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <header className="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-[var(--border)] bg-white px-5 py-4">
                    <div><span className="eyebrow">Gerenciadora</span><h2 className="mt-1 text-xl font-black">{title}</h2></div>
                    <button type="button" disabled={form.processing} onClick={onClose} className="rounded-lg p-2 hover:bg-slate-100" aria-label="Fechar registro"><X size={20} /></button>
                </header>
                <div className="space-y-5 p-5">
                    <div className="flex flex-wrap gap-2" aria-label="Obras do registro da gerenciadora">{fronts.map(obra => {
                        const active = Number(obra.id) === Number(form.data.obra_id);
                        return <button type="button" key={obra.id} disabled={form.processing} aria-pressed={active} onClick={() => changeFront(Number(obra.id))} className={`sig-btn sig-btn-sm !whitespace-normal text-left ${active ? 'sig-btn-primary ring-2 ring-blue-200 ring-offset-2' : 'border border-[var(--border)] bg-white'}`}>{active && <CheckCircle2 size={17} className="shrink-0" />}<span>{obra.codigo} · {obra.nome}</span></button>;
                    })}</div>
                    <p className="text-xs text-[var(--ink-500)]">Preenchendo obra: <strong className="text-[var(--primary)]">{selected?.codigo} · {selected?.nome}</strong></p>
                    {!rdo.parallel_manager_filling && <>
                        <RdoSection section="clima" title="Condições do tempo" collapsed><WeatherFields form={weatherForm} /></RdoSection>
                        <ActivityFields form={activityForm} />
                        <Field label="Comentário"><textarea aria-label="Comentário do registro da gerenciadora" className="sig-input min-h-24" value={form.data.comentario} onChange={event => form.setData('comentario', event.target.value)} placeholder="Registre o comentário geral desta obra." maxLength={10000} /></Field>
                    </>}
                    <InspectionEvidenceEditor key={form.data.obra_id} form={form} existing={current?.evidence || []} />
                    {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm font-semibold text-red-600">{error}</p>)}
                </div>
                <footer className="sticky bottom-0 flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border)] bg-white px-5 py-4"><p className="text-xs text-[var(--ink-500)]">Alterne entre as obras sem perder o preenchimento. Os registros alterados serão salvos juntos.</p><div className="flex gap-2"><button type="button" disabled={form.processing} onClick={onClose} className="sig-btn">Cancelar</button><button type="submit" disabled={form.processing} className="sig-btn sig-btn-primary"><Save size={16} /> {form.processing ? 'Salvando...' : 'Salvar todas as obras'}</button></div></footer>
            </form>
        </div>
    );
}

function DivergenceModal({ rdo, form, onClose, onSubmit }) {
    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <form onSubmit={onSubmit} className="max-h-[94vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <header className="flex items-start justify-between gap-3 border-b border-[var(--border)] px-5 py-4"><div><span className="eyebrow">Matriz de divergências</span><h2 className="mt-1 text-xl font-black">Registrar versões distintas do fato</h2></div><button type="button" onClick={onClose} className="rounded-lg p-2 hover:bg-slate-100"><X size={20} /></button></header>
                <div className="grid gap-4 p-5 md:grid-cols-2">
                    <Field label="Obra / frente"><select className="sig-input" value={form.data.obra_id} onChange={(e) => form.setData('obra_id', Number(e.target.value))}>{(rdo.obras || []).filter((obra) => rdo.flow_obra_ids?.includes(Number(obra.id))).map((obra) => <option key={obra.id} value={obra.id}>{obra.codigo} - {obra.nome}</option>)}</select></Field>
                    <Field label="Tópico"><select className="sig-input" value={form.data.topico} onChange={(e) => form.setData('topico', e.target.value)}><option>Atividades e ocorrências</option><option>Mão de obra</option><option>Equipamentos</option><option>Clima</option><option>Prazo</option><option>Qualidade</option><option>Segurança</option><option>Outro</option></select></Field>
                    <div className="md:col-span-2"><Field label="Título"><input className="sig-input" value={form.data.titulo} onChange={(e) => form.setData('titulo', e.target.value)} required /></Field></div>
                    <Field label="Versão registrada pela construtora"><textarea className="sig-input min-h-32" value={form.data.versao_construtora} onChange={(e) => form.setData('versao_construtora', e.target.value)} required /></Field>
                    <Field label="Constatação da gerenciadora"><textarea className="sig-input min-h-32" value={form.data.versao_gerenciadora} onChange={(e) => form.setData('versao_gerenciadora', e.target.value)} required /></Field>
                    {Object.values(form.errors).map((error, index) => <p key={index} className="text-sm font-semibold text-red-600 md:col-span-2">{error}</p>)}
                </div>
                <footer className="flex justify-end gap-2 border-t border-[var(--border)] px-5 py-4"><button type="button" onClick={onClose} className="sig-btn">Cancelar</button><button type="submit" disabled={form.processing} className="sig-btn sig-btn-primary"><AlertTriangle size={16} /> Registrar divergência</button></footer>
            </form>
        </div>
    );
}

function DivergenceActionModal({ context, form, onClose, onSubmit }) {
    const { item, action } = context;
    const title = action === 'respond' ? 'Resposta da construtora' : action === 'conclude' ? 'Conclusão da gerenciadora' : 'Decisão do cliente';

    return (
        <div className="fixed inset-0 z-[125] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <form onSubmit={onSubmit} className="w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header className="flex items-start justify-between gap-3 border-b border-[var(--border)] px-5 py-4"><div><span className="eyebrow">{item.title}</span><h2 className="mt-1 text-xl font-black">{title}</h2></div><button type="button" onClick={onClose} className="rounded-lg p-2 hover:bg-slate-100"><X size={20} /></button></header>
                <div className="space-y-4 p-5">
                    {action === 'respond' && <Field label="Resposta"><textarea className="sig-input min-h-32" value={form.data.response} onChange={(e) => form.setData('response', e.target.value)} required /></Field>}
                    {action === 'conclude' && <><Field label="Conclusão"><textarea className="sig-input min-h-32" value={form.data.conclusion} onChange={(e) => form.setData('conclusion', e.target.value)} required /></Field><Field label="Encaminhamento"><select className="sig-input" value={form.data.outcome} onChange={(e) => form.setData('outcome', e.target.value)}><option value="resolvida">Divergência resolvida</option><option value="aguarda_cliente">Encaminhar ao cliente</option></select></Field></>}
                    {action === 'client_decide' && <><Field label="Decisão"><select className="sig-input" value={form.data.client_decision} onChange={(e) => form.setData('client_decision', e.target.value)}><option value="aceita_gerenciadora">Prevalece a constatação da gerenciadora</option><option value="aceita_construtora">Prevalece o relato da construtora</option><option value="aceita_com_ressalvas">Aceito com ressalvas</option></select></Field><Field label="Comentário do cliente"><textarea className="sig-input min-h-28" value={form.data.client_comment} onChange={(e) => form.setData('client_comment', e.target.value)} /></Field></>}
                    {Object.values(form.errors).map((error, index) => <p key={index} className="text-sm font-semibold text-red-600">{error}</p>)}
                </div>
                <footer className="flex justify-end gap-2 border-t border-[var(--border)] px-5 py-4"><button type="button" onClick={onClose} className="sig-btn">Cancelar</button><button type="submit" disabled={form.processing} className="sig-btn sig-btn-primary"><Save size={16} /> Salvar</button></footer>
            </form>
        </div>
    );
}

function DeadlineExpiredModal({ rdo, onClose }) {
    const { currentTenant } = usePage().props;

    return (
        <div className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <section className="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Prazo encerrado">
                <header className="flex items-start justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                    <div>
                        <span className="eyebrow">RDO bloqueado</span>
                        <h2 className="mt-1 text-xl font-black text-[var(--ink-900)]">Prazo de preenchimento encerrado</h2>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-2 hover:bg-slate-100" aria-label="Fechar"><X size={20} /></button>
                </header>
                <div className="space-y-4 p-5">
                    <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                        O prazo para preencher este RDO encerrou.
                    </div>
                    <p className="text-sm text-[var(--ink-500)]">
                        RDO: <strong className="text-[var(--ink-800)]">{rdo.code}</strong> · Data: <strong className="text-[var(--ink-800)]">{rdo.reference_date_formatted}</strong>
                    </p>
                    {rdo.submission_deadline_formatted && (
                        <p className="text-sm text-[var(--ink-500)]">
                            Prazo encerrado em: <strong className="text-[var(--ink-800)]">{rdo.submission_deadline_formatted}</strong>
                        </p>
                    )}
                </div>
                <footer className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] px-5 py-4">
                    <button type="button" onClick={onClose} className="sig-btn">Fechar</button>
                    <Link
                        href={route('tenant.diario-obra.rdo.calendar', {
                            tenant: currentTenant.slug,
                            month: rdo.reference_date?.slice(0, 7),
                            contract_id: rdo.contract?.id,
                        })}
                        className="sig-btn sig-btn-primary"
                    >
                        Ir para o calendário
                    </Link>
                </footer>
            </section>
        </div>
    );
}

function scopeRdoForm(form, key) {
    return ({
        data: {
            dados: form.data.secoes?.[key] || emptySection[key],
            fotos: form.data.fotos,
        },
        errors: form.errors,
        processing: form.processing,
        setData: (field, value) => {
            if (typeof field === 'function') {
                const next = field({
                    dados: form.data.secoes?.[key] || emptySection[key],
                    fotos: form.data.fotos || [],
                });
                form.setData({
                    ...form.data,
                    fotos: next.fotos ?? form.data.fotos,
                    secoes: {
                        ...form.data.secoes,
                        [key]: next.dados ?? form.data.secoes?.[key] ?? emptySection[key],
                    },
                });
                return;
            }
            if (typeof field === 'object' && field !== null) {
                form.setData({
                    ...form.data,
                    fotos: field.fotos ?? form.data.fotos,
                    secoes: {
                        ...form.data.secoes,
                        [key]: field.dados ?? form.data.secoes?.[key] ?? emptySection[key],
                    },
                });
                return;
            }
            if (field === 'fotos') {
                form.setData('fotos', value);
                return;
            }
            if (field === 'dados') {
                form.setData('secoes', { ...form.data.secoes, [key]: value });
            }
        },
    });
}

function SignaturePanel({ rdo, processing = false, refreshProcessing = false, onSend, onRefresh, manualForm, onManualUpload }) {
    const signature = rdo.signature;
    const digitalEnabled = rdo.digital_signature_enabled ?? true;

    if (rdo.status !== 'arquivado' && !signature) {
        return null;
    }

    const canManage = Boolean(rdo.can_manage_signatures);
    const canSend = canManage && digitalEnabled && rdo.status === 'arquivado' && (!signature || ['failed', 'cancelled'].includes(signature.status));
    const canManualUpload = canManage && !digitalEnabled && rdo.status === 'arquivado' && (!signature || !signature.signed_download_url);
    const canRefresh = canManage && digitalEnabled && signature && ['sent', 'pending', 'completed'].includes(signature.status) && (!signature.signed_download_url || (signature.signers || []).some((signer) => signer.status !== 'completed'));

    return (
        <section className="mt-5 overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-sm">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                <div>
                    <span className="eyebrow">{digitalEnabled ? 'Assinatura digital' : 'Assinatura manual'}</span>
                    <h2 className="mt-1 text-lg font-bold">
                        {signature?.status_label || (digitalEnabled ? 'Pronto para assinatura' : 'Aguardando upload do documento assinado')}
                    </h2>

                    {!digitalEnabled && (
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Este contrato nao utiliza assinatura digital. Faca upload do PDF assinado para arquivar o documento final.
                        </p>
                    )}
                </div>
                {canSend && (
                    <button type="button" disabled={processing} onClick={onSend} className="sig-btn sig-btn-primary">
                        <Send size={16} /> {processing ? 'Enviando...' : 'Enviar para assinatura'}
                    </button>
                )}
                {canRefresh && (
                    <button type="button" disabled={refreshProcessing} onClick={() => onRefresh(signature.id)} className="sig-btn">
                        <RefreshCw size={16} className={refreshProcessing ? 'animate-spin' : ''} /> {refreshProcessing ? 'Atualizando...' : 'Atualizar assinatura'}
                    </button>
                )}
            </header>

            {signature ? (
                <div className="space-y-4 p-5">
                    {signature.error_message && (
                        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                            {signature.error_message}
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span className={`rounded-full px-3 py-1 text-xs font-bold ${signature.status === 'completed' ? 'bg-emerald-50 text-emerald-700' : signature.status === 'failed' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700'}`}>
                            {signature.status_label}
                        </span>
                        {signature.sent_at && <span className="text-[var(--ink-500)]">Enviado em {signature.sent_at}</span>}
                        {signature.completed_at && <span className="text-[var(--ink-500)]">Concluído em {signature.completed_at}</span>}
                    </div>

                    {(signature.signers || []).length > 0 && (
                    <div className="grid gap-3 md:grid-cols-3">
                        {(signature.signers || []).map((signer) => (
                            <article key={signer.id} className="rounded-lg border border-[var(--border)] p-3">
                                <div className="flex items-center justify-between gap-2">
                                    <strong className="text-sm">{signer.role_label}</strong>
                                    <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${signer.status === 'completed' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>
                                        {signer.status_label}
                                    </span>
                                </div>
                                <p className="mt-2 text-sm font-semibold">{signer.name}</p>
                                <p className="text-xs text-[var(--ink-500)]">{signer.email}</p>
                                {signer.signing_url && (
                                    <a href={signer.signing_url} target="_blank" rel="noreferrer" className="mt-3 inline-flex text-xs font-bold text-[var(--primary)]">
                                        Abrir link de assinatura
                                    </a>
                                )}
                            </article>
                        ))}
                    </div>
                    )}

                    <div className="flex flex-wrap gap-2">
                        {signature.unsigned_download_url && (
                            <a href={signature.unsigned_download_url} className="sig-btn">
                                <FileText size={16} /> Baixar PDF enviado
                            </a>
                        )}
                        {signature.signed_download_url && (
                            <a href={signature.signed_download_url} className="sig-btn border-emerald-600 bg-emerald-600 text-white">
                                <CheckCircle2 size={16} /> Baixar PDF assinado
                            </a>
                        )}
                        {signature.signing_url && (
                            <a href={signature.signing_url} target="_blank" rel="noreferrer" className="sig-btn">
                                Abrir no provedor
                            </a>
                        )}
                    </div>
                </div>
            ) : canManualUpload ? (
                <form onSubmit={onManualUpload} className="space-y-3 p-5">
                    <div>
                        <label className="mb-2 block text-xs font-bold uppercase tracking-[0.18em] text-[var(--ink-500)]">
                            PDF assinado manualmente
                        </label>
                        <input
                            type="file"
                            accept="application/pdf"
                            className="sig-input w-full"
                            onChange={(event) => manualForm.setData('signed_pdf', event.target.files?.[0] || null)}
                        />
                        {manualForm.errors.signed_pdf && (
                            <p className="mt-1 text-xs font-semibold text-red-600">{manualForm.errors.signed_pdf}</p>
                        )}
                    </div>
                    <button type="submit" disabled={manualForm.processing || !manualForm.data.signed_pdf} className="sig-btn sig-btn-primary disabled:opacity-50">
                        <FileText size={16} /> {manualForm.processing ? 'Enviando...' : 'Enviar PDF assinado'}
                    </button>
                </form>
            ) : (
                <div className="p-5 text-sm text-[var(--ink-500)]">
                    Nenhuma solicitação de assinatura foi criada para este RDO.
                </div>
            )}
        </section>
    );
}

function WorkflowPanel({ rdo, obraId, form, processing = false, onAction }) {
    const fillingParties = rdo.fill_parties || [rdo.fill_party || 'construtora'];
    const reviews = rdo.workflow?.review_parties || ['gerenciadora', 'cliente'];
    const steps = [['preenchimento', 'Preenchimento', fillingParties.map(party => partyLabels[party]).join(' + ')], ...reviews.map(party => [party, party === 'cliente' && rdo.workflow?.client_comment_only ? 'Comentário do cliente' : partyLabels[party], rdo.contract?.[party]?.nome]), ['arquivo', 'Arquivo', 'RDO concluído']];
    const activeIndex = rdo.status === 'arquivado' ? steps.length - 1 : ['rascunho', 'devolvido_construtora', 'pendente_comprovacao'].includes(rdo.status) ? 0 : Math.max(0, reviews.indexOf(rdo.active_stage) + 1);
    const actions = Object.entries(rdo.flow_actions || {});
    const needsComment = actions.some(([, action]) => action.comment_required);
    const isSubmitOnly = actions.length > 0 && actions.every(([key]) => key === 'submit');
    const showCommentField = actions.some(([key]) => key !== 'submit');
    const actionObras = (rdo.obras || []).filter((obra) => rdo.flow_obra_ids?.includes(Number(obra.id)));
    const selectedFlowObraIds = rdo.flow_obra_ids || [];

    return (
        <section className="mb-5 overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-sm">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                <h2 className="font-bold">Fluxo do RDO</h2>
                {rdo.status !== 'rascunho' && <div className="flex flex-wrap items-center gap-2">
                    <span className={`rounded-full px-3 py-1.5 text-xs font-bold ${rdo.status === 'arquivado' ? 'bg-emerald-50 text-emerald-700' : rdo.status.includes('devolvido') || rdo.status.includes('pendente') ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700'}`}>
                        {rdo.status_label}
                    </span>
                </div>}
            </header>

            <div className="grid gap-2 border-b border-[var(--border)] bg-slate-50/70 p-4 sm:grid-cols-2 xl:grid-cols-4">
                {steps.map(([key, label, company], index) => (
                    <div key={key} data-rdo-party={rdoParty(key === 'preenchimento' ? rdo.fill_party : key)} className={`rdo-flow-party rounded-lg border p-3 ${index === activeIndex ? 'border-[var(--primary)] bg-white shadow-sm' : index < activeIndex ? 'border-emerald-200 bg-emerald-50/60' : 'border-slate-200 bg-slate-50'}`}>
                        <div className="flex items-center gap-2">
                            {index < activeIndex || rdo.status === 'arquivado' ? <CheckCircle2 size={17} className="text-emerald-600" /> : <span className={`h-3 w-3 rounded-full ${index === activeIndex ? 'bg-[var(--primary)]' : 'bg-slate-300'}`} />}
                            <strong className="text-sm">{label}</strong>
                        </div>
                        <p className="mt-1 truncate text-xs text-[var(--ink-500)]">{company || 'Não vinculada'}</p>
                    </div>
                ))}
            </div>

            {rdo.status === 'em_analise_gerenciadora' && actions.length > 0 && <ManagerFlowActions rdo={rdo} obraId={obraId} renderApprovalFields={managerForm => <ApprovalCommentFields rdo={rdo} form={managerForm} selectedObraIds={rdo.flow_obra_ids || []} needsComment showPrevious={false} showIntroduction={false} showReservations reservationChoices />} />}
            {actions.length > 0 && !['em_analise_gerenciadora', 'em_aprovacao_cliente'].includes(rdo.status) && (
                <div className="border-b border-[var(--border)] p-5">
                    {isSubmitOnly && (
                        <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                            Todas as frentes de serviço foram preenchidas. O envio seguirá como RDO consolidado.
                        </div>
                    )}
                    {!isSubmitOnly && actionObras.length > 0 && (
                        <div className="mb-4">
                            <span className="eyebrow">Obras deste RDO — movimentação conjunta</span>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {actionObras.map((obra) => (
                                    <span key={obra.id} className="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-bold text-blue-800">
                                        <CheckCircle2 size={14} />
                                        {obra.codigo} - {obra.nome}
                                    </span>
                                ))}
                            </div>
                            {form.errors.obra_ids && <p className="mt-2 text-sm font-semibold text-red-600">{form.errors.obra_ids}</p>}
                        </div>
                    )}
                    {showCommentField && ['em_analise_construtora', 'em_analise_gerenciadora', 'em_aprovacao_cliente'].includes(rdo.status) && (
                        <ApprovalCommentFields rdo={rdo} form={form} selectedObraIds={selectedFlowObraIds} needsComment={needsComment} />
                    )}
                    {showCommentField && !['em_analise_construtora', 'em_analise_gerenciadora', 'em_aprovacao_cliente'].includes(rdo.status) && (
                        <>
                    <Field label={needsComment ? 'Parecer / resposta' : 'Comentário'}>
                        <textarea
                            className="sig-input min-h-24"
                            value={form.data.comment}
                            onChange={(event) => form.setData('comment', event.target.value)}
                            placeholder={commentOnly ? `Registre o comentário para a frente ${obra.codigo}.` : needsComment ? 'Descreva as ressalvas, o motivo da devolução ou a resposta da parte responsável pelo preenchimento.' : 'Registre uma observação para acompanhar a decisão.'}
                        />
                    </Field>
                    {form.errors.comment && <p className="mt-2 text-sm font-semibold text-red-600">{form.errors.comment}</p>}
                        </>
                    )}
                    {form.errors.action && <p className="mt-2 text-sm font-semibold text-red-600">{form.errors.action}</p>}
                    {form.errors.reservations_by_obra && <p className="mt-2 text-sm font-semibold text-red-600">{form.errors.reservations_by_obra}</p>}
                    <div className="mt-3 flex flex-wrap justify-end gap-2">
                        {actions.map(([key, action]) => (
                            <button
                                key={key}
                                type="button"
                                disabled={processing || form.processing || (key !== 'submit' && !selectedFlowObraIds.length)}
                                onClick={() => onAction(key)}
                                className={`sig-btn ${action.tone === 'primary' ? 'sig-btn-primary' : action.tone === 'success' ? 'border-emerald-600 bg-emerald-600 text-white' : action.tone === 'warning' ? 'border-amber-500 bg-amber-50 text-amber-800' : 'border-red-500 bg-red-50 text-red-700'}`}
                            >
                                {key === 'submit' ? <Send size={16} /> : <CheckCircle2 size={16} />} {action.label}
                            </button>
                        ))}
                    </div>
                </div>
            )}
            {actions.length === 0 && rdo.status === 'rascunho' && (!rdo.submission_ready || (rdo.parallel_manager_filling && rdo.fill_party === 'gerenciadora')) && (
                <div className="border-b border-[var(--border)] p-5">
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
                        {rdo.parallel_manager_filling && rdo.fill_party === 'gerenciadora'
                            ? 'Salve os seus registros. Quando a construtora encaminhar o RDO, você poderá continuar o preenchimento durante a análise.'
                            : 'Preencha todas as frentes de serviço antes de enviar o RDO para análise.'}
                    </div>
                </div>
            )}

            {false && (
            <div>
                <div className="mb-3 flex items-center gap-2">
                    <History size={18} className="text-[var(--primary)]" />
                    <h3 className="font-bold">Histórico do fluxo</h3>
                </div>
                {rdo.analyses?.length ? (
                    <div className="space-y-3">
                        {rdo.analyses.map((analysis) => (
                            <article key={analysis.id} className="rounded-lg border border-[var(--border)] p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="text-sm font-bold">{analysis.stage_label} · {analysis.decision_label}</div>
                                    <time className="text-xs text-[var(--ink-500)]">{analysis.created_at}</time>
                                </div>
                                <p className="mt-1 text-xs text-[var(--ink-500)]">{analysis.user?.name}{analysis.company?.nome ? ` · ${analysis.company.nome}` : ''}</p>
                                {analysis.obra && <p className="mt-1 text-xs font-semibold text-[var(--ink-600)]">Frente: {analysis.obra.codigo} - {analysis.obra.nome}</p>}
                                {analysis.comment && <p className="mt-2 whitespace-pre-wrap rounded-md bg-slate-50 px-3 py-2 text-sm">{analysis.comment}</p>}
                            </article>
                        ))}
                    </div>
                ) : <p className="text-sm text-[var(--ink-500)]">O RDO ainda não foi submetido para análise.</p>}
            </div>
            )}
        </section>
    );
}

function ApprovalCommentFields({ rdo, form, selectedObraIds = [], needsComment = false, showPrevious = true, showIntroduction = true, showReservations = true, reservationChoices = false }) {
    const activeStage = rdo.active_stage;
    const comments = rdo.approval_comments || {};
    const stageLabel = partyLabels[activeStage]?.toLowerCase() || 'parte responsável';
    const commentOnly = activeStage === 'cliente' && rdo.workflow?.client_comment_only;
    const fieldLabel = commentOnly ? 'Comentário do cliente' : `Parecer — ${partyLabels[activeStage] || 'Responsável'}`;
    const company = rdo.contract?.[activeStage]?.nome;
    const selectedObras = (rdo.obras || []).filter((obra) => selectedObraIds.includes(Number(obra.id)));
    const reviews = rdo.workflow?.review_parties || ['gerenciadora', 'cliente'];
    const otherStage = reviews[reviews.indexOf(activeStage) - 1];
    const otherStageLabel = partyLabels[otherStage];

    const setObraComment = (obraId, value) => {
        form.setData('comments_by_obra', {
            ...(form.data.comments_by_obra || {}),
            [obraId]: value,
        });
    };
    const setReservation = (obraId, field, value) => {
        form.setData('reservations_by_obra', {
            ...(form.data.reservations_by_obra || {}),
            [obraId]: {
                ...(form.data.reservations_by_obra?.[obraId] || {}),
                [field]: value,
            },
        });
    };

    return (
        <div>
            {showIntroduction && <div className="mb-3">
                <span className="eyebrow block">{commentOnly ? 'Comentário por frente' : 'Parecer / resposta por frente'}</span>
                <p className="mt-1 text-sm text-[var(--ink-500)]">
                    {commentOnly ? 'Registre o comentário do cliente para cada obra do RDO.' : `Preencha o parecer ${activeStage === 'cliente' ? 'do' : 'da'} ${stageLabel} para cada obra do RDO.`}
                    {company ? ` Empresa: ${company}.` : ''}
                </p>
            </div>}

            <div className="grid gap-3">
                {selectedObras.map((obra) => {
                    const obraId = Number(obra.id);
                    const savedFromOtherStage = comments?.[otherStage]?.[obraId];
                    const value = form.data.comments_by_obra?.[obraId] ?? '';
                    const hasReservation = (form.data.reservation_obra_ids || []).includes(obraId);

                    return (
                        <section key={obra.id} className="rounded-xl border border-[var(--border)] bg-white p-4 shadow-sm">
                            <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <span className="eyebrow">{obra.codigo}</span>
                                    <h4 className="mt-1 text-base font-black text-[var(--ink-900)]">{obra.nome}</h4>
                                </div>
                                <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">{fieldLabel}</span>
                            </div>

                            {showPrevious && savedFromOtherStage?.comment && (
                                <div className="mb-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                    <p className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">Parecer da {otherStageLabel}</p>
                                    <p className="mt-1 whitespace-pre-wrap text-sm text-[var(--ink-700)]">{savedFromOtherStage.comment}</p>
                                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                                        {savedFromOtherStage.decision || 'Decisão registrada'}{savedFromOtherStage.user ? ` por ${savedFromOtherStage.user}` : ''}{savedFromOtherStage.created_at ? ` em ${savedFromOtherStage.created_at}` : ''}
                                    </p>
                                </div>
                            )}

                            <Field label={fieldLabel}>
                                <textarea
                                    aria-label={`${fieldLabel} · ${obra.codigo} · ${obra.nome}`}
                                    className="sig-input min-h-24"
                                    value={value}
                                    onChange={(event) => setObraComment(obraId, event.target.value)}
                                    placeholder={commentOnly ? `Registre o comentário para a frente ${obra.codigo}.` : needsComment
                                        ? `Descreva as ressalvas ou o motivo da devolução para a frente ${obra.codigo}.`
                                        : `Registre uma observação para a frente ${obra.codigo}.`}
                                />
                            </Field>
                            {reservationChoices && !commentOnly && <fieldset className="mt-3 rounded-lg border border-[var(--border)] bg-slate-50 p-3"><legend className="sr-only">Possui ressalvas na obra {obra.codigo}?</legend><p className="mb-2 text-sm font-semibold">Possui ressalvas?</p><div className="flex gap-4">{[[false, 'Não'], [true, 'Sim']].map(([checked, label]) => <label key={label} className="flex cursor-pointer items-center gap-2 text-sm"><input type="radio" name={`reservations-${obraId}`} value={String(checked)} checked={hasReservation === checked} onChange={() => form.setData('reservation_obra_ids', checked ? [...new Set([...(form.data.reservation_obra_ids || []), obraId])] : (form.data.reservation_obra_ids || []).filter(id => id !== obraId))} />{label}</label>)}</div></fieldset>}
                            {!commentOnly && showReservations && (!reservationChoices || hasReservation) && <details open className="mt-3 rounded-lg border border-amber-200 bg-amber-50/60 p-3">
                                <summary className="cursor-pointer text-xs font-black uppercase text-amber-800">Detalhes da ressalva, quando aplicável</summary>
                                <div className="mt-3 grid gap-3 md:grid-cols-2">
                                    <div className="md:col-span-2"><Field label="Obrigação a atender"><textarea className="sig-input min-h-20" value={form.data.reservations_by_obra?.[obraId]?.obligation || ''} onChange={(event) => setReservation(obraId, 'obligation', event.target.value)} /></Field></div>
                                    <Field label="Parte responsável"><select aria-label={`Parte responsável pela ressalva · ${obra.codigo}`} className="sig-input" value={form.data.reservations_by_obra?.[obraId]?.responsible || ''} onChange={event => setReservation(obraId, 'responsible', event.target.value)}><option value="">Selecione a parte</option><option value="Construtora">Construtora</option><option value="Gerenciadora">Gerenciadora</option></select></Field>
                                    <Field label="Prazo"><input type="date" className="sig-input" value={form.data.reservations_by_obra?.[obraId]?.due_date || ''} onChange={(event) => setReservation(obraId, 'due_date', event.target.value)} /></Field>
                                </div>
                            </details>}
                        </section>
                    );
                })}
            </div>
            {form.errors.comments_by_obra && <p className="mt-2 text-sm font-semibold text-red-600">{form.errors.comments_by_obra}</p>}
            {form.errors.comment && <p className="mt-2 text-sm font-semibold text-red-600">{form.errors.comment}</p>}
        </div>
    );
}

function HistoryModal({ events = [], loading = false, error = '', onClose }) {
    const typeLabel = (event) => {
        if (event.type === 'signature') return 'Assinatura';
        if (event.type === 'flow') return 'Fluxo';
        if (event.type === 'version') return 'Versão';
        if (event.type === 'inspection') return 'Fiscalização';
        if (event.type === 'divergence') return 'Divergência';
        return 'RDO';
    };

    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/50 p-2" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <section className="flex max-h-[min(520px,calc(100dvh-1rem))] w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-label="Histórico do RDO">
                <header className="flex shrink-0 items-center justify-between border-b border-[var(--border)] px-3 py-2">
                    <div className="min-w-0">
                        <span className="eyebrow">Auditoria do RDO</span>
                        <h2 className="text-base font-bold">Histórico</h2>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-2 hover:bg-slate-100" aria-label="Fechar"><X size={18} /></button>
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    {loading && <div className="rounded-lg bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">Carregando histórico...</div>}
                    {error && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">{error}</div>}
                    {!loading && !error && events.length === 0 && (
                        <div className="rounded-lg border border-[var(--border)] bg-white px-4 py-3 text-sm text-[var(--ink-500)]">Nenhum evento registrado para este RDO.</div>
                    )}
                    {!loading && !error && events.length > 0 && (
                        <ul className="divide-y divide-[var(--border)]">
                            {events.map((event) => (
                                <li key={event.id} className="px-3 py-2">
                                    <div className="flex min-w-0 items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="flex min-w-0 items-center gap-2">
                                                <span className="shrink-0 rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase text-[var(--ink-500)]">{typeLabel(event)}</span>
                                                <strong className="truncate text-sm">{event.title}</strong>
                                            </div>
                                            <p className="mt-0.5 truncate text-xs text-[var(--ink-500)]">
                                                {[event.actor, event.company, event.obra && `Frente: ${event.obra}`].filter(Boolean).join(' · ')}
                                            </p>
                                        </div>
                                        <time className="shrink-0 text-right text-[11px] font-semibold text-[var(--ink-500)]">{event.created_at}</time>
                                    </div>
                                    {event.comment && <p className="mt-1 line-clamp-1 text-xs text-[var(--ink-600)]">{event.comment}</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                {!loading && !error && events.length > 0 && (
                    <div className="shrink-0 border-t border-[var(--border)] px-3 py-2 text-xs text-[var(--ink-500)]">
                        Mostrando os últimos {events.length} eventos.
                    </div>
                )}
            </section>
        </div>
    );
}

function FullSection({ title, icon: Icon, children }) {
    return (
        <section className="overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-sm">
            <header className="flex items-center gap-3 border-b border-[var(--border)] px-5 py-4">
                <Icon size={20} className="text-[var(--primary)]" />
                <h3 className="text-lg font-bold">{title}</h3>
            </header>
            <div className="p-5">{children}</div>
        </section>
    );
}

function SectionModal({ section, form, fillParty, catalogs, obras, activeObraId, onChangeObra, onClose, onSubmit, readOnly = false, activitySubjects = [] }) {
    const definition = sections.find((item) => item.key === section);
    const Icon = definition.icon;

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <form onSubmit={onSubmit} className="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label={definition.label}>
                <header className="flex items-center justify-between border-b border-[var(--border)] px-5 py-4">
                    <div className="flex items-center gap-3">
                        <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]"><Icon size={21} /></span>
                        <div><span className="eyebrow">Preenchimento do RDO</span><h2 className="text-xl font-bold">{definition.label}</h2></div>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-2 hover:bg-slate-100" aria-label="Fechar"><X size={21} /></button>
                </header>
                <div className="flex min-h-[82px] items-stretch gap-3 overflow-x-auto border-b border-[var(--border)] bg-slate-50 px-5 py-3">
                    {obras.map((obra) => {
                        const active = Number(activeObraId) === Number(obra.id);
                        return (
                            <button
                                key={obra.id}
                                type="button"
                                onClick={() => onChangeObra(obra.id)}
                                disabled={form.processing}
                                className={`inline-flex min-h-[56px] min-w-[210px] shrink-0 flex-col items-start justify-center rounded-lg border px-4 py-2 text-left leading-tight shadow-sm ${
                                    active
                                        ? 'border-[var(--primary)] bg-[var(--primary)] text-white'
                                        : 'border-[var(--border)] bg-white text-[var(--ink-700)] hover:border-[var(--primary)]'
                                }`}
                            >
                                <span className="mono block text-[10px] font-bold leading-none opacity-75">{obra.codigo}</span>
                                <span className="mt-1 block max-w-[250px] whitespace-normal text-sm font-bold leading-tight">{obra.nome}</span>
                            </button>
                        );
                    })}
                </div>
                <div className={`overflow-y-auto p-5 ${readOnly ? '[&_input]:pointer-events-none [&_select]:pointer-events-none [&_textarea]:pointer-events-none [&_button]:pointer-events-none [&_input]:bg-slate-50 [&_select]:bg-slate-50 [&_textarea]:bg-slate-50' : ''}`}>
                    <div className="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                        Preenchendo: <strong>{obras.find((obra) => Number(obra.id) === Number(activeObraId))?.codigo} - {obras.find((obra) => Number(obra.id) === Number(activeObraId))?.nome}</strong>
                    </div>
                    {section === 'clima' && <WeatherFields form={form} />}
                    {section === 'mao_obra' && <LaborFields form={form} catalogs={catalogs} />}
                    {section === 'equipamentos' && <EquipmentFields form={form} catalogs={catalogs} />}
                    {section === 'atividades' && <ActivityFields form={form} subjects={activitySubjects} />}
                    {section === 'fotos' && <PhotoFields form={form} />}
                    {section === 'comentarios' && <CommentFields form={form} fillParty={fillParty} />}
                    {Object.values(form.errors).length > 0 && <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">{Object.values(form.errors)[0]}</p>}
                </div>
                <footer className="flex justify-end gap-2 border-t border-[var(--border)] px-5 py-4">
                    <button type="button" onClick={onClose} className="sig-btn">{readOnly ? 'Fechar' : 'Cancelar'}</button>
                    {!readOnly && <button type="submit" disabled={form.processing} className="sig-btn sig-btn-primary"><Save size={16} /> Salvar seção</button>}
                </footer>
            </form>
        </div>
    );
}

function WeatherFields({ form }) {
    const set = (key, value) => form.setData('dados', { ...form.data.dados, [key]: value });
    const periodLabels = { manha: 'Manhã', tarde: 'Tarde', noite: 'Noite' };
    const weatherOptions = [
        ['ensolarado', 'Ensolarado'],
        ['nublado', 'Nublado'],
        ['chuvoso', 'Chuvoso'],
        ['nao_aplicavel', 'Não aplicável'],
    ];
    const selectedFor = (period) => Array.isArray(form.data.dados[period]) ? (form.data.dados[period][0] || '') : (form.data.dados[period] || '');
    const toggleSituation = (period, option) => {
        set(period, selectedFor(period) === option ? '' : option);
    };
    return <div className="grid gap-2 md:grid-cols-3">
        {['manha', 'tarde', 'noite'].map((period) => (
            <section key={period} className="rounded-lg border border-[var(--border)] bg-slate-50 p-3">
                <h4 className="mb-2 text-sm font-semibold">{periodLabels[period]}</h4>
                <div className="grid gap-2">
                    <Field label="Condição climática">
                        <select className="sig-input !py-1.5" value={selectedFor(period)} onChange={event => set(period, event.target.value)}><option value="">Selecione</option>{weatherOptions.map(([value,label]) => <option key={value} value={value}>{label}</option>)}</select>
                    </Field>
                    <Field label="Pluviosidade (mm)">
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            className="sig-input"
                            value={form.data.dados[`precipitacao_${period}_mm`] ?? ''}
                            onChange={(e) => set(`precipitacao_${period}_mm`, e.target.value)}
                        />
                    </Field>
                </div>
            </section>
        ))}
        {form.data.dados.precipitacao_total_anterior_mm !== undefined && (
            <div className="md:col-span-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Registro anterior sem divisão por período: <strong>{form.data.dados.precipitacao_total_anterior_mm} mm</strong>.
            </div>
        )}
        <label className="flex items-center gap-3 rounded-lg border border-[var(--border)] px-4 py-3"><input type="checkbox" className="h-4 w-4 rounded text-[var(--primary)]" checked={isImpracticableDay(form.data.dados.dia_impraticavel)} onChange={(e) => set('dia_impraticavel', e.target.checked)} /><span className="font-semibold">Dia impraticável</span></label>
        <Field label="Observações" className="md:col-span-3"><textarea className="sig-input min-h-20" value={form.data.dados.observacoes || ''} onChange={(e) => set('observacoes', e.target.value)} /></Field>
    </div>;
}

// Keep historical selections visible without offering unused inactive catalog entries.
function selectedCatalogItems(active, historical, records, fallback) {
    const result = new Map((active || []).map(item => [String(item.id), item]));
    Object.keys(records || {}).forEach(id => {
        if (!result.has(id)) result.set(id, (historical || []).find(item => String(item.id) === id) || fallback(id));
    });
    return [...result.values()];
}

function LaborFields({ form, catalogs, historicalCatalogs }) {
    const data = form.data.dados;
    const set = (key, value) => form.setData('dados', { ...data, [key]: value });
    const labor = selectedCatalogItems(catalogs.mao_obra, historicalCatalogs?.mao_obra, data.efetivos,
        id => ({ id, descricao: `Cadastro ${id}` }));
    const subcontractors = selectedCatalogItems(catalogs.subcontratadas, historicalCatalogs?.subcontratadas, data.subcontratadas,
        id => ({ id, razao_social: `Empresa ${id}` }));
    const items = [
        ...labor.map(item => ({ id: `labor:${item.id}`, name: item.descricao, description: `${item.tipo === 'direta' ? 'Mão de obra direta' : item.tipo === 'indireta' ? 'Mão de obra indireta' : 'Mão de obra'} · Unidade: ${item.unidade || 'pessoa'}` })),
        ...subcontractors.map(item => ({ id: `sub:${item.id}`, name: item.nome_fantasia || item.razao_social, description: 'Subcontratada · Unidade: pessoa' })),
    ];
    const records = Object.fromEntries([
        ...Object.entries(data.efetivos || {}).map(([id, value]) => [`labor:${id}`, value]),
        ...Object.entries(data.subcontratadas || {}).map(([id, value]) => [`sub:${id}`, value]),
    ]);
    const change = next => form.setData('dados', {
        ...data,
        efetivos: Object.fromEntries(Object.entries(next).filter(([id]) => id.startsWith('labor:')).map(([id, value]) => [id.slice(6), value])),
        subcontratadas: Object.fromEntries(Object.entries(next).filter(([id]) => id.startsWith('sub:')).map(([id, value]) => [id.slice(4), value])),
    });
    return <div className="space-y-4">
        <RdoCatalogPicker title="Mão de obra e subcontratadas" items={items} records={records} onChange={change} disabled={form.processing} />
        <Field label="Observações"><textarea className="sig-input min-h-24" value={data.observacoes || ''} onChange={event => set('observacoes', event.target.value)} /></Field>
    </div>;
}

function EquipmentFields({ form, catalogs, historicalCatalogs }) {
    const data = form.data.dados;
    const records = data.registros || {};
    const equipment = selectedCatalogItems(catalogs.equipamentos, historicalCatalogs?.equipamentos, records,
        id => ({ id, descricao: `Equipamento ${id}` }));
    const set = (key, value) => form.setData('dados', { ...data, [key]: value });
    return <div className="space-y-4">
        <RdoCatalogPicker title="Equipamentos" items={equipment.map(item => ({ id: String(item.id), code: item.codigo, name: item.descricao, description: `Unidade: ${item.unidade || 'unidade'}` }))} records={records} onChange={next => set('registros', next)} equipment disabled={form.processing} />
        <Field label="Observações"><textarea className="sig-input min-h-24" value={data.observacoes || ''} onChange={event => set('observacoes', event.target.value)} /></Field>
    </div>;
}

function ActivityFields({ form, subjects = [] }) {
    const legacyActivity = form.data.dados.atividades_executadas
        ? [{ titulo: 'Atividade executada', ocorrencia: form.data.dados.atividades_executadas }]
        : [];
    const legacyOccurrences = [form.data.dados.ocorrencias, form.data.dados.interferencias, form.data.dados.acidentes].filter(Boolean).join('\n\n');
    const activities = form.data.dados.atividades?.length
        ? form.data.dados.atividades
        : (legacyOccurrences ? [{ titulo: 'Ocorrências importantes', ocorrencia: legacyOccurrences }] : legacyActivity);
    const normalize = (next) => next.map((activity) => ({
        ...(subjects.length ? { assunto: activity?.assunto || (/seguran[cç]a|sst/i.test(activity?.titulo || '') ? 'seguranca_trabalho' : /ambiente|ambiental|res[ií]duo/i.test(activity?.titulo || '') ? 'meio_ambiente' : 'engenharia') } : activity?.assunto ? { assunto: activity.assunto } : {}),
        titulo: activity?.titulo || '',
        ocorrencia: activity?.ocorrencia ?? activity?.descricao ?? '',
    }));
    const setActivities = (next) => form.setData('dados', {
        ...form.data.dados,
        atividades: normalize(next),
        atividades_executadas: undefined,
        interferencias: undefined,
        acidentes: undefined,
    });
    const setActivity = (index, key, value) => {
        const next = normalize(activities);
        next[index] = { ...(next[index] || { titulo: '', descricao: '' }), [key]: value };
        setActivities(next);
    };
    const addActivity = () => setActivities([...activities, { titulo: '', ocorrencia: '' }]);
    const removeActivity = (index) => setActivities(activities.filter((_, currentIndex) => currentIndex !== index));

    return <div className="space-y-4">
        <section className="overflow-hidden rounded-xl border border-[var(--border)] bg-white">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] bg-slate-50 px-4 py-3">
                <div>
                    <h4 className="font-bold">Atividades executadas</h4>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">Adicione uma atividade por linha, com título e ocorrência.</p>
                </div>
                <button type="button" onClick={addActivity} className="sig-btn sig-btn-primary sig-btn-sm">
                    <Plus size={14} /> Adicionar atividade
                </button>
            </header>
            <div className="grid gap-3 p-4">
                {activities.length > 0 ? activities.map((activity, index) => (
                    <article key={index} className="rounded-lg border border-[var(--border)] bg-slate-50/70 p-3">
                        <div className="flex items-start justify-between gap-3">
                            <span className="sig-pill">Atividade {index + 1}</span>
                            <button type="button" onClick={() => removeActivity(index)} className="rounded-lg p-2 text-red-600 hover:bg-red-50" aria-label="Remover atividade">
                                <Trash2 size={15} />
                            </button>
                        </div>
                        {subjects.length > 0 && <div className="mt-3 max-w-sm"><Field label="Assunto"><select className="sig-input" aria-label={`Assunto da atividade ${index + 1}`} value={normalize([activity])[0].assunto} onChange={event => setActivity(index, 'assunto', event.target.value)}>{subjects.map(subject => <option key={subject.value} value={subject.value}>{subject.label}</option>)}</select></Field></div>}
                        <div className="mt-3 grid gap-3 md:grid-cols-[minmax(0,280px)_1fr]">
                            <Field label="Título da atividade">
                                <input
                                    type="text"
                                    className="sig-input"
                                    value={activity.titulo || ''}
                                    onChange={(event) => setActivity(index, 'titulo', event.target.value)}
                                    placeholder="Ex.: Concretagem da base"
                                />
                            </Field>
                            <Field label="Ocorrência">
                                <textarea
                                    className="sig-input min-h-24"
                                    value={activity.ocorrencia ?? activity.descricao ?? ''}
                                    onChange={(event) => setActivity(index, 'ocorrencia', event.target.value)}
                                    placeholder="Registre a ocorrência, serviço executado, interferência, paralisação, acidente ou fato relevante."
                                />
                            </Field>
                        </div>
                    </article>
                )) : (
                    <div className="rounded-lg border border-dashed border-[var(--border-strong)] px-3 py-8 text-center text-sm text-[var(--ink-500)]">
                        Nenhuma atividade adicionada.
                    </div>
                )}
            </div>
        </section>
    </div>;
}

function PhotoFields({ form, preservePreviews = false }) {
    const [photoError, setPhotoError] = useState('');
    const urls = useRef(new Set());
    useEffect(() => {
        const active = new Set((form.data.dados.novas_fotos || []).map(photo => photo.preview_url));
        urls.current.forEach(url => { if (!active.has(url)) URL.revokeObjectURL(url); });
        urls.current = active;
    }, [form.data.dados.novas_fotos]);
    useEffect(() => () => { if (!preservePreviews) urls.current.forEach(url => URL.revokeObjectURL(url)); }, [preservePreviews]);
    const existing = form.data.dados.arquivos || [];
    const newPhotos = form.data.dados.novas_fotos || [];
    const defaultOrder = [
        ...existing.map((photo) => `existing:${photo.path}`),
        ...newPhotos.map((photo) => `new:${photo.client_id}`),
    ];
    const order = (form.data.dados.ordem_fotos || []).filter((key) => defaultOrder.includes(key));
    const orderedKeys = [...order, ...defaultOrder.filter((key) => !order.includes(key))];

    const addPhotos = (event) => {
        const files = Array.from(event.target.files || []);
        event.target.value = '';
        if (files.length === 0) return;
        if (existing.length + newPhotos.length + files.length > 20) { setPhotoError('Adicione até 20 imagens por frente.'); return; }
        if (files.some(file => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 12 * 1024 * 1024)) { setPhotoError('Envie imagens JPG, PNG ou WebP de até 12 MB por arquivo.'); return; }
        setPhotoError('');

        const metadata = files.map((file, index) => ({
            client_id: `${Date.now()}-${index}-${Math.random().toString(36).slice(2)}`,
            nome: file.name,
            comment: '',
            preview_url: URL.createObjectURL(file),
        }));
        const keys = metadata.map((photo) => `new:${photo.client_id}`);

        form.setData({
            ...form.data,
            fotos: [...(form.data.fotos || []), ...files],
            dados: {
                ...form.data.dados,
                novas_fotos: [...(form.data.dados.novas_fotos || []), ...metadata],
                ordem_fotos: [...orderedKeys, ...keys],
            },
        });
        event.target.value = '';
    };

    const updateComment = (key, comment) => {
        if (key.startsWith('existing:')) {
            const path = key.slice('existing:'.length);
            form.setData('dados', {
                ...form.data.dados,
                arquivos: existing.map((photo) => photo.path === path ? { ...photo, comment, legenda: comment } : photo),
                ordem_fotos: orderedKeys,
            });
            return;
        }

        const clientId = key.slice('new:'.length);
        form.setData('dados', {
            ...form.data.dados,
            novas_fotos: newPhotos.map((photo) => photo.client_id === clientId ? { ...photo, comment } : photo),
            ordem_fotos: orderedKeys,
        });
    };

    const removePhoto = (key) => {
        if (key.startsWith('existing:')) {
            const path = key.slice('existing:'.length);
            form.setData('dados', {
                ...form.data.dados,
                arquivos: existing.filter((photo) => photo.path !== path),
                ordem_fotos: orderedKeys.filter((item) => item !== key),
            });
            return;
        }

        const clientId = key.slice('new:'.length);
        const index = newPhotos.findIndex((photo) => photo.client_id === clientId);
        if (index < 0) return;
        URL.revokeObjectURL(newPhotos[index].preview_url);
        form.setData({
            ...form.data,
            fotos: (form.data.fotos || []).filter((_, fileIndex) => fileIndex !== index),
            dados: {
                ...form.data.dados,
                novas_fotos: (form.data.dados.novas_fotos || []).filter((photo) => photo.client_id !== clientId),
                ordem_fotos: orderedKeys.filter((item) => item !== key),
            },
        });
    };

    const movePhoto = (key, direction) => {
        const index = orderedKeys.indexOf(key);
        const nextIndex = index + direction;
        if (index < 0 || nextIndex < 0 || nextIndex >= orderedKeys.length) return;
        const next = [...orderedKeys];
        [next[index], next[nextIndex]] = [next[nextIndex], next[index]];
        form.setData('dados', { ...form.data.dados, ordem_fotos: next });
    };

    const photoFromKey = (key) => {
        if (key.startsWith('existing:')) {
            const photo = existing.find((item) => item.path === key.slice('existing:'.length));
            return photo ? {
                key,
                previewUrl: `/storage/${photo.path}`,
                name: photo.nome,
                comment: photo.comment ?? photo.legenda ?? '',
            } : null;
        }
        const photo = newPhotos.find((item) => item.client_id === key.slice('new:'.length));
        return photo ? { key, previewUrl: photo.preview_url, name: photo.nome, comment: photo.comment || '' } : null;
    };

    const photos = orderedKeys.map(photoFromKey).filter(Boolean);

    return <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
                <span className="eyebrow">Registro fotográfico</span>
                <p className="mt-1 text-xs text-[var(--ink-500)]">Fotos são opcionais. Adicione até 20 imagens, organize a posição e comente cada foto. JPG, PNG ou WebP de até 12 MB.</p>
            </div>
            <label className="sig-btn sig-btn-secondary sig-btn-sm">
                <ImagePlus size={14} /> Adicionar fotos
                <input className="sr-only" type="file" multiple accept="image/jpeg,image/png,image/webp" onChange={addPhotos} />
            </label>
        </div>
        {photoError && <p role="alert" className="text-sm text-red-600">{photoError}</p>}
        {photos.length > 0 ? (
            <div className="grid gap-3">
                {photos.map((photo, index) => (
                    <div key={photo.key} className="grid gap-3 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-2 sm:grid-cols-[88px_minmax(0,1fr)]">
                        <a href={photo.previewUrl} target="_blank" rel="noreferrer">
                            <img src={photo.previewUrl} alt={photo.name} className="h-20 w-20 rounded-md object-cover" />
                        </a>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="sig-pill">Posição {index + 1}</span>
                                <button type="button" onClick={() => movePhoto(photo.key, -1)} disabled={index === 0} className="sig-btn sig-btn-ghost !min-h-8 !px-2" title="Mover para cima"><ArrowUp size={14} /></button>
                                <button type="button" onClick={() => movePhoto(photo.key, 1)} disabled={index === photos.length - 1} className="sig-btn sig-btn-ghost !min-h-8 !px-2" title="Mover para baixo"><ArrowDown size={14} /></button>
                                <button type="button" onClick={() => removePhoto(photo.key)} className="sig-btn sig-btn-ghost !min-h-8 !px-2 text-[var(--red)]" title="Excluir foto"><Trash2 size={14} /></button>
                                <span className="min-w-0 truncate text-xs text-[var(--ink-500)]">{photo.name}</span>
                            </div>
                            <textarea
                                className="mt-2 w-full rounded-md border border-[var(--border)] bg-white px-3 py-2 text-[12.5px] outline-none focus:border-[var(--primary)]"
                                value={photo.comment}
                                onChange={(event) => updateComment(photo.key, event.target.value)}
                                placeholder="Comentário da imagem"
                                rows={2}
                            />
                        </div>
                    </div>
                ))}
            </div>
        ) : (
            <div className="rounded-lg border border-dashed border-[var(--border-strong)] px-3 py-8 text-center text-sm text-[var(--ink-500)]">
                Nenhuma imagem adicionada.
            </div>
        )}
    </div>;
}

function CommentFields({ form, fillParty = 'construtora' }) {
    const set = (key, value) => form.setData('dados', { ...form.data.dados, [key]: value });
    return <div className="grid gap-4">
        <Field label={`Comentário — ${partyLabels[fillParty]}`}><textarea className="sig-input min-h-28" value={form.data.dados[fillParty] || ''} onChange={(e) => set(fillParty, e.target.value)} /></Field>
        {Object.keys(partyLabels).some(party => party !== fillParty && form.data.dados[party]) && (
            <p className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Comentários anteriores das demais partes foram preservados. Os novos pareceres são registrados no histórico do fluxo de aprovação.
            </p>
        )}
    </div>;
}

function Field({ label, children, className = '' }) {
    return <label className={`grid gap-1.5 ${className}`}><span className="eyebrow">{label}</span>{children}</label>;
}

function Meta({ label, value }) {
    return <div><span className="eyebrow block">{label}</span><span className="mt-1 block font-semibold">{value}</span></div>;
}
