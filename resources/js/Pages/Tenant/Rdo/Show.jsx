import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowLeft, ArrowUp, Camera, CheckCircle2, ClipboardList, CloudSun, Construction, FileText, History, ImagePlus, MessageSquareText, Plus, RefreshCw, Save, Send, Trash2, Users, X } from 'lucide-react';
import { useEffect, useState } from 'react';

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

export default function Show({ rdo, catalogs = {} }) {
    const { currentTenant, flash = {} } = usePage().props;
    const [activeSection, setActiveSection] = useState(null);
    const [activeObraId, setActiveObraId] = useState(null);
    const [completeOpen, setCompleteOpen] = useState(false);
    const [flowProcessing, setFlowProcessing] = useState(false);
    const [signatureProcessing, setSignatureProcessing] = useState(false);
    const [signatureRefreshProcessing, setSignatureRefreshProcessing] = useState(false);
    const sectionForm = useForm({ obra_id: '', dados: {}, fotos: [] });
    const completeForm = useForm({ obra_id: '', secoes: {}, fotos: [] });
    const flowForm = useForm({ comment: '', obra_ids: rdo.flow_obra_ids || [] });
    const allObras = rdo.obras || [rdo.obra];
    const editableObras = rdo.can_edit
        ? allObras.filter((obra) => rdo.editable_obra_ids?.includes(Number(obra.id)))
        : allObras;

    const loadSectionForObra = (key, obraId) => {
        sectionForm.clearErrors();
        sectionForm.setData({
            obra_id: obraId,
            dados: structuredClone(rdo.sections?.[obraId]?.[key] || emptySection[key]),
            fotos: [],
        });
        setActiveObraId(obraId);
    };

    const openSection = (key) => {
        const firstObraId = editableObras[0]?.id;
        loadSectionForObra(key, firstObraId);
        setActiveSection(key);
    };

    const loadCompleteForObra = (obraId) => {
        completeForm.clearErrors();
        completeForm.setData({
            obra_id: obraId,
            secoes: Object.fromEntries(sections.map(({ key }) => [
                key,
                structuredClone(rdo.sections?.[obraId]?.[key] || emptySection[key]),
            ])),
            fotos: [],
        });
        setActiveObraId(obraId);
    };

    const openComplete = () => {
        const firstObraId = editableObras[0]?.id;
        loadCompleteForObra(firstObraId);
        setCompleteOpen(true);
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
        completeForm.post(route('tenant.diario-obra.rdo.sections.store-all', [currentTenant.slug, rdo.id]), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => setCompleteOpen(false),
        });
    };

    const changeFlow = (action) => {
        flowForm.clearErrors();
        setFlowProcessing(true);

        router.post(route('tenant.diario-obra.rdo.flow', [currentTenant.slug, rdo.id]), {
            action,
            comment: action === 'submit' && rdo.status === 'rascunho' ? '' : flowForm.data.comment,
            obra_ids: action === 'submit'
                ? (rdo.flow_obra_ids || [])
                : (flowForm.data.obra_ids?.length ? flowForm.data.obra_ids : (rdo.flow_obra_ids || [])),
        }, {
            preserveScroll: true,
            preserveState: false,
            onError: (errors) => flowForm.setError(errors),
            onSuccess: () => flowForm.setData('comment', ''),
            onFinish: () => setFlowProcessing(false),
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

    useEffect(() => {
        if (!activeSection) return undefined;
        const onKeyDown = (event) => event.key === 'Escape' && closeSection();
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [activeSection, sectionForm.processing]);

    useEffect(() => {
        flowForm.setData('obra_ids', rdo.flow_obra_ids || []);
    }, [rdo.status, JSON.stringify(rdo.flow_obra_ids || [])]);

    return (
        <AuthenticatedLayout>
            <Head title={`${rdo.code} - RDO`} />
            <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6">
                <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Diário de Obra · {rdo.reference_date_formatted}</span>
                        <h1 className="mt-2 text-3xl font-bold">{rdo.code}</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">{rdo.obra?.codigo} - {rdo.obra?.nome}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <a href={route('tenant.diario-obra.rdo.pdf', [currentTenant.slug, rdo.id])}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-2 rounded-lg border border-[var(--border)] bg-white px-4 py-2.5 font-bold">
                            <FileText size={17} /> Gerar PDF
                        </a>
                    <Link href={route('tenant.diario-obra.rdo.calendar', { tenant: currentTenant.slug, month: rdo.reference_date.slice(0, 7), contract_id: rdo.contract?.id, obra_id: rdo.obra?.id })}
                        className="inline-flex items-center gap-2 rounded-lg border border-[var(--border)] bg-white px-4 py-2.5 font-bold">
                        <ArrowLeft size={17} /> Voltar ao calendário
                    </Link>
                    </div>
                </div>

                {flash.success && <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 font-semibold text-emerald-700">{flash.success}</div>}

                <div className="mb-4 grid gap-3 rounded-xl border border-[var(--border)] bg-white p-4 shadow-sm md:grid-cols-4">
                    <Meta label="Contrato" value={`${rdo.contract?.code || ''} - ${rdo.contract?.name || ''}`} />
                    <Meta label="Status" value={rdo.status_label} />
                    <Meta label="Responsável" value={rdo.responsible?.name || 'Não definido'} />
                    <Meta label="Origem" value={rdo.generated_automatically ? 'Geração automática' : 'Criação manual'} />
                </div>

                {rdo.obras?.length > 0 && (
                    <div className="mb-4 rounded-xl border border-[var(--border)] bg-white p-4 shadow-sm">
                        <span className="eyebrow">Obras / frentes participantes</span>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {rdo.obras.map((obra) => (
                                <span key={obra.id} className="rounded-full bg-[var(--primary-50)] px-3 py-1.5 text-xs font-bold text-[var(--primary)]">
                                    {obra.codigo} - {obra.nome}
                                </span>
                            ))}
                        </div>
                    </div>
                )}

                {rdo.can_edit && (
                    <div className="mb-4 flex justify-end">
                        <button type="button" onClick={openComplete} className="sig-btn sig-btn-primary">
                            <ClipboardList size={17} /> Preencher RDO completo
                        </button>
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {sections.map(({ key, label, icon: Icon, description }) => {
                        const totalObras = rdo.obras?.length || 1;
                        const filledCount = (rdo.obras || [rdo.obra]).filter((obra) => Boolean(rdo.sections?.[obra.id]?.[key])).length;
                        const filled = filledCount === totalObras;
                        return (
                            <button key={key} type="button" onClick={() => openSection(key)}
                                className="rounded-xl border border-[var(--border)] bg-white p-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-[var(--primary)]">
                                <div className="flex items-start justify-between gap-3">
                                    <Icon size={24} className="text-[var(--primary)]" />
                                    <span className={`rounded-full px-2.5 py-1 text-[10px] font-bold ${filled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                                        {filledCount}/{totalObras} preenchida(s)
                                    </span>
                                </div>
                                <h2 className="mt-4 text-lg font-bold">{label}</h2>
                                <p className="mt-1 text-sm text-[var(--ink-500)]">{description}</p>
                                <span className="mt-4 inline-block text-xs font-bold text-[var(--primary)]">
                                    {rdo.can_edit ? (filled ? 'Editar seção →' : 'Abrir seção →') : 'Visualizar seção →'}
                                </span>
                            </button>
                        );
                    })}
                </div>

                <div className="mt-5">
                    <WorkflowPanel rdo={rdo} form={flowForm} processing={flowProcessing} onAction={changeFlow} />
                </div>

                <SignaturePanel
                    rdo={rdo}
                    processing={signatureProcessing}
                    refreshProcessing={signatureRefreshProcessing}
                    onSend={sendSignature}
                    onRefresh={refreshSignature}
                />
            </div>

            {activeSection && (
                <SectionModal
                    section={activeSection}
                    form={sectionForm}
                    catalogs={catalogs}
                    obras={editableObras}
                    activeObraId={activeObraId}
                    onChangeObra={(obraId) => loadSectionForObra(activeSection, obraId)}
                    onClose={closeSection}
                    onSubmit={saveSection}
                    readOnly={!rdo.can_edit}
                />
            )}
            {completeOpen && (
                <CompleteModal
                    form={completeForm}
                    catalogs={catalogs}
                    obras={editableObras}
                    activeObraId={activeObraId}
                    onChangeObra={loadCompleteForObra}
                    onClose={() => !completeForm.processing && setCompleteOpen(false)}
                    onSubmit={saveComplete}
                />
            )}
        </AuthenticatedLayout>
    );
}

function CompleteModal({ form, catalogs, obras, activeObraId, onChangeObra, onClose, onSubmit }) {
    const activeObra = obras.find((obra) => Number(obra.id) === Number(activeObraId));
    const scoped = (key) => ({
        data: {
            dados: form.data.secoes?.[key] || emptySection[key],
            fotos: form.data.fotos,
        },
        errors: form.errors,
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

    return (
        <div className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <form onSubmit={onSubmit} className="flex max-h-[96vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Preencher RDO completo">
                <header className="flex items-center justify-between border-b border-[var(--border)] px-5 py-4">
                    <div className="flex items-center gap-3">
                        <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]"><ClipboardList size={21} /></span>
                        <div><span className="eyebrow">Preenchimento unificado</span><h2 className="text-xl font-bold">Preencher RDO completo</h2></div>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-2 hover:bg-slate-100" aria-label="Fechar"><X size={21} /></button>
                </header>
                <div className="flex min-h-[82px] items-stretch gap-3 overflow-x-auto border-b border-[var(--border)] bg-slate-50 px-5 py-3">
                    {obras.map((obra) => (
                        <button key={obra.id} type="button" onClick={() => onChangeObra(obra.id)} disabled={form.processing}
                            className={`inline-flex min-h-[56px] min-w-[210px] shrink-0 flex-col items-start justify-center rounded-lg border px-4 py-2 text-left leading-tight shadow-sm ${
                                Number(activeObraId) === Number(obra.id)
                                    ? 'border-[var(--primary)] bg-[var(--primary)] text-white'
                                    : 'border-[var(--border)] bg-white text-[var(--ink-700)] hover:border-[var(--primary)]'
                            }`}>
                            <span className="mono block text-[10px] font-bold leading-none opacity-75">{obra.codigo}</span>
                            <span className="mt-1 block max-w-[250px] whitespace-normal text-sm font-bold leading-tight">{obra.nome}</span>
                        </button>
                    ))}
                </div>
                <div className="overflow-y-auto bg-slate-50/60 p-5">
                    <div className="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                        Preenchendo todas as seções de: <strong>{activeObra?.codigo} - {activeObra?.nome}</strong>
                    </div>
                    <div className="space-y-4">
                        <FullSection title="Condições do tempo" icon={CloudSun}><WeatherFields form={scoped('clima')} /></FullSection>
                        <FullSection title="Mão de obra" icon={Users}><LaborFields form={scoped('mao_obra')} catalogs={catalogs} /></FullSection>
                        <FullSection title="Equipamentos" icon={Construction}><EquipmentFields form={scoped('equipamentos')} catalogs={catalogs} /></FullSection>
                        <FullSection title="Atividades e ocorrências" icon={FileText}><ActivityFields form={scoped('atividades')} /></FullSection>
                        <FullSection title="Registro fotográfico" icon={Camera}><PhotoFields form={scoped('fotos')} /></FullSection>
                        <FullSection title="Comentários" icon={MessageSquareText}><CommentFields form={scoped('comentarios')} /></FullSection>
                    </div>
                    {Object.values(form.errors).length > 0 && <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">{Object.values(form.errors)[0]}</p>}
                </div>
                <footer className="flex justify-end gap-2 border-t border-[var(--border)] px-5 py-4">
                    <button type="button" onClick={onClose} className="sig-btn">Cancelar</button>
                    <button type="submit" disabled={form.processing} className="sig-btn sig-btn-primary"><Save size={16} /> Salvar preenchimento completo</button>
                </footer>
            </form>
        </div>
    );
}

function SignaturePanel({ rdo, processing = false, refreshProcessing = false, onSend, onRefresh }) {
    const signature = rdo.signature;

    if (rdo.status !== 'arquivado' && !signature) {
        return null;
    }

    const canSend = rdo.status === 'arquivado' && (!signature || ['failed', 'cancelled'].includes(signature.status));
    const canRefresh = signature && ['sent', 'pending', 'completed'].includes(signature.status) && (!signature.signed_download_url || (signature.signers || []).some((signer) => signer.status !== 'completed'));

    return (
        <section className="mt-5 overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-sm">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                <div>
                    <span className="eyebrow">Assinatura digital</span>
                    <h2 className="mt-1 text-lg font-bold">{signature?.status_label || 'Pronto para assinatura'}</h2>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        Após o RDO ser aprovado, envie o PDF para assinatura da construtora, gerenciadora e cliente.
                    </p>
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
            ) : (
                <div className="p-5 text-sm text-[var(--ink-500)]">
                    Nenhuma solicitação de assinatura foi criada para este RDO.
                </div>
            )}
        </section>
    );
}

function WorkflowPanel({ rdo, form, processing = false, onAction }) {
    const steps = [
        ['construtora', 'Construtora', rdo.contract?.construtora?.nome],
        ['aprovacao', 'Aprovação conjunta', [rdo.contract?.gerenciadora?.nome, rdo.contract?.cliente?.nome].filter(Boolean).join(' + ')],
        ['arquivo', 'Arquivo', 'RDO aprovado'],
    ];
    const activeIndex = {
        rascunho: 0,
        devolvido_construtora: 0,
        pendente_comprovacao: 0,
        em_aprovacao: 1,
        arquivado: 2,
    }[rdo.status] ?? 0;
    const actions = Object.entries(rdo.flow_actions || {});
    const needsComment = actions.some(([, action]) => action.comment_required);
    const isSubmitOnly = actions.length > 0 && actions.every(([key]) => key === 'submit');
    const isInitialSubmitOnly = actions.length > 0 && actions.every(([key]) => key === 'submit') && rdo.status === 'rascunho';
    const showCommentField = !isInitialSubmitOnly;
    const actionObras = (rdo.obras || []).filter((obra) => rdo.flow_obra_ids?.includes(Number(obra.id)));
    const selectedFlowObraIds = form.data.obra_ids?.length ? form.data.obra_ids : (rdo.flow_obra_ids || []);

    return (
        <section className="mb-5 overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-sm">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                <div>
                    <span className="eyebrow">Fluxo de análise e aprovação</span>
                    <h2 className="mt-1 text-lg font-bold">{rdo.status_label}</h2>
                </div>
                <span className={`rounded-full px-3 py-1.5 text-xs font-bold ${rdo.status === 'arquivado' ? 'bg-emerald-50 text-emerald-700' : rdo.status.includes('devolvido') || rdo.status.includes('pendente') ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700'}`}>
                    {rdo.status_label}
                </span>
            </header>

            <div className="grid gap-2 border-b border-[var(--border)] bg-slate-50/70 p-4 md:grid-cols-3">
                {steps.map(([key, label, company], index) => (
                    <div key={key} className={`rounded-lg border p-3 ${index === activeIndex ? 'border-[var(--primary)] bg-white shadow-sm' : index < activeIndex ? 'border-emerald-200 bg-emerald-50/60' : 'border-slate-200 bg-slate-50'}`}>
                        <div className="flex items-center gap-2">
                            {index < activeIndex || rdo.status === 'arquivado' ? <CheckCircle2 size={17} className="text-emerald-600" /> : <span className={`h-3 w-3 rounded-full ${index === activeIndex ? 'bg-[var(--primary)]' : 'bg-slate-300'}`} />}
                            <strong className="text-sm">{label}</strong>
                        </div>
                        <p className="mt-1 truncate text-xs text-[var(--ink-500)]">{company || 'Não vinculada'}</p>
                    </div>
                ))}
            </div>

            {actions.length > 0 && (
                <div className="border-b border-[var(--border)] p-5">
                    {isSubmitOnly && (
                        <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                            Todas as frentes de serviço foram preenchidas. O envio seguirá como RDO consolidado.
                        </div>
                    )}
                    {!isSubmitOnly && actionObras.length > 0 && (
                        <div className="mb-4">
                            <span className="eyebrow">Selecione as frentes desta decisão</span>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {actionObras.map((obra) => (
                                    <label key={obra.id} className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-bold text-blue-800">
                                        <input
                                            type="checkbox"
                                            checked={form.data.obra_ids?.includes(Number(obra.id))}
                                            onChange={(event) => form.setData(
                                                'obra_ids',
                                                event.target.checked
                                                    ? [...(form.data.obra_ids || []), Number(obra.id)]
                                                    : (form.data.obra_ids || []).filter((id) => Number(id) !== Number(obra.id))
                                            )}
                                        />
                                        {obra.codigo} - {obra.nome}
                                    </label>
                                ))}
                            </div>
                            {form.errors.obra_ids && <p className="mt-2 text-sm font-semibold text-red-600">{form.errors.obra_ids}</p>}
                        </div>
                    )}
                    {showCommentField && (
                        <>
                    <Field label={rdo.status === 'rascunho' ? 'Comentário de envio (opcional)' : needsComment ? 'Parecer / resposta' : 'Comentário (opcional)'}>
                        <textarea
                            className="sig-input min-h-24"
                            value={form.data.comment}
                            onChange={(event) => form.setData('comment', event.target.value)}
                            placeholder={needsComment ? 'Descreva as ressalvas, o motivo da devolução ou a resposta da construtora.' : 'Registre uma observação para acompanhar a decisão.'}
                        />
                    </Field>
                    {form.errors.comment && <p className="mt-2 text-sm font-semibold text-red-600">{form.errors.comment}</p>}
                        </>
                    )}
                    {form.errors.action && <p className="mt-2 text-sm font-semibold text-red-600">{form.errors.action}</p>}
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
            {actions.length === 0 && rdo.status === 'rascunho' && !rdo.submission_ready && (
                <div className="border-b border-[var(--border)] p-5">
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
                        Preencha todas as frentes de serviço antes de enviar o RDO para análise.
                    </div>
                </div>
            )}

            <div className="p-5">
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
        </section>
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

function SectionModal({ section, form, catalogs, obras, activeObraId, onChangeObra, onClose, onSubmit, readOnly = false }) {
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
                    {section === 'atividades' && <ActivityFields form={form} />}
                    {section === 'fotos' && <PhotoFields form={form} />}
                    {section === 'comentarios' && <CommentFields form={form} />}
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
    return <div className="grid gap-4 md:grid-cols-3">
        {['manha', 'tarde', 'noite'].map((period) => (
            <section key={period} className="rounded-lg border border-[var(--border)] bg-slate-50 p-4">
                <h4 className="mb-3 font-bold">{periodLabels[period]}</h4>
                <div className="grid gap-3">
                    <Field label="Condição climática">
                        <select className="sig-input" value={form.data.dados[period] || ''} onChange={(e) => set(period, e.target.value)}>
                            <option value="">Selecione</option>
                            <option value="ensolarado">Ensolarado</option>
                            <option value="nublado">Nublado</option>
                            <option value="chuvoso">Chuvoso</option>
                            <option value="nao_aplicavel">Não aplicável</option>
                        </select>
                    </Field>
                    <Field label="Pluviosidade (mm)">
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            className="sig-input"
                            value={form.data.dados[`precipitacao_${period}_mm`] || ''}
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
        <label className="flex items-center gap-3 rounded-lg border border-[var(--border)] px-4 py-3"><input type="checkbox" className="h-5 w-5 rounded text-[var(--primary)]" checked={Boolean(form.data.dados.dia_impraticavel)} onChange={(e) => set('dia_impraticavel', e.target.checked)} /><span className="font-semibold">Dia impraticável</span></label>
        <Field label="Observações" className="md:col-span-3"><textarea className="sig-input min-h-28" value={form.data.dados.observacoes || ''} onChange={(e) => set('observacoes', e.target.value)} /></Field>
    </div>;
}

function LaborFields({ form, catalogs }) {
    const [selectedDirectId, setSelectedDirectId] = useState('');
    const [selectedIndirectId, setSelectedIndirectId] = useState('');
    const [selectedSubcontractorId, setSelectedSubcontractorId] = useState('');
    const effectiveEntries = Object.entries(form.data.dados.efetivos || {});
    const subcontractorEntries = Object.entries(form.data.dados.subcontratadas || {});
    const availableLabor = (type) => (catalogs.mao_obra || []).filter((item) =>
        item.tipo === type
        && !Object.prototype.hasOwnProperty.call(form.data.dados.efetivos || {}, String(item.id)));
    const availableSubcontractors = (catalogs.subcontratadas || []).filter((item) =>
        !Object.prototype.hasOwnProperty.call(form.data.dados.subcontratadas || {}, String(item.id)));
    const addLabor = (selectedId, clear) => {
        if (!selectedId) return;
        form.setData('dados', { ...form.data.dados, efetivos: { ...(form.data.dados.efetivos || {}), [selectedId]: '' } });
        clear('');
    };
    const addSubcontractor = () => {
        if (!selectedSubcontractorId) return;
        form.setData('dados', {
            ...form.data.dados,
            subcontratadas: { ...(form.data.dados.subcontratadas || {}), [selectedSubcontractorId]: '' },
        });
        setSelectedSubcontractorId('');
    };
    const removeLabor = (id) => {
        const next = { ...(form.data.dados.efetivos || {}) };
        delete next[id];
        form.setData('dados', { ...form.data.dados, efetivos: next });
    };
    const removeSubcontractor = (id) => {
        const next = { ...(form.data.dados.subcontratadas || {}) };
        delete next[id];
        form.setData('dados', { ...form.data.dados, subcontratadas: next });
    };
    const setQuantity = (id, value) => form.setData('dados', { ...form.data.dados, efetivos: { ...(form.data.dados.efetivos || {}), [id]: value } });
    const setSubcontractorQuantity = (id, value) => form.setData('dados', {
        ...form.data.dados,
        subcontratadas: { ...(form.data.dados.subcontratadas || {}), [id]: value },
    });
    const set = (key, value) => form.setData('dados', { ...form.data.dados, [key]: value });

    const laborGroup = (type, title, selectedId, setSelectedId) => {
        const entries = effectiveEntries.filter(([id]) =>
            (catalogs.mao_obra || []).find((item) => String(item.id) === String(id))?.tipo === type);
        return (
            <LaborGroup
                title={title}
                options={availableLabor(type)}
                selectedId={selectedId}
                setSelectedId={setSelectedId}
                onAdd={() => addLabor(selectedId, setSelectedId)}
                entries={entries}
                catalogs={catalogs.mao_obra || []}
                onQuantity={setQuantity}
                onRemove={removeLabor}
            />
        );
    };

    return <div className="space-y-4">
        <div className="grid gap-4 xl:grid-cols-3">
            {laborGroup('direta', 'Mão de obra direta', selectedDirectId, setSelectedDirectId)}
            {laborGroup('indireta', 'Mão de obra indireta', selectedIndirectId, setSelectedIndirectId)}
            <section className="overflow-hidden rounded-lg border border-[var(--border)] bg-white">
                <header className="border-b border-[var(--border)] bg-slate-50 px-4 py-3">
                    <h4 className="font-bold">Subcontratadas</h4>
                </header>
                <div className="flex gap-2 border-b border-[var(--border)] p-3">
                    <select className="sig-input min-w-0 flex-1" value={selectedSubcontractorId} onChange={(e) => setSelectedSubcontractorId(e.target.value)}>
                        <option value="">Selecionar empresa</option>
                        {availableSubcontractors.map((item) => <option key={item.id} value={item.id}>{item.nome_fantasia || item.razao_social}</option>)}
                    </select>
                    <button type="button" onClick={addSubcontractor} disabled={!selectedSubcontractorId} className="sig-btn sig-btn-primary !px-3"><Plus size={15} /></button>
                </div>
                {subcontractorEntries.map(([id, quantity]) => {
                    const item = (catalogs.subcontratadas || []).find((candidate) => String(candidate.id) === String(id));
                    return <div key={id} className="grid grid-cols-[1fr_80px_36px] items-center gap-2 border-b border-[var(--border)] px-3 py-3 last:border-0">
                        <strong className="min-w-0 truncate text-sm">{item?.nome_fantasia || item?.razao_social || `Empresa ${id}`}</strong>
                        <input type="number" min="0" step="1" className="sig-input !px-2" placeholder="Qtd." value={quantity} onChange={(e) => setSubcontractorQuantity(id, e.target.value)} />
                        <button type="button" onClick={() => removeSubcontractor(id)} className="rounded-lg p-2 text-red-600 hover:bg-red-50" aria-label="Remover subcontratada"><Trash2 size={15} /></button>
                    </div>;
                })}
                {subcontractorEntries.length === 0 && <p className="p-4 text-center text-xs text-[var(--ink-500)]">Nenhuma subcontratada adicionada.</p>}
                <footer className="border-t border-[var(--border)] bg-slate-50 px-4 py-2 text-right text-xs font-bold">
                    Total: {sumQuantities(subcontractorEntries)}
                </footer>
            </section>
        </div>
        <Field label="Observações"><textarea className="sig-input min-h-24" value={form.data.dados.observacoes || ''} onChange={(e) => set('observacoes', e.target.value)} /></Field>
    </div>;
}

function LaborGroup({ title, options, selectedId, setSelectedId, onAdd, entries, catalogs, onQuantity, onRemove }) {
    return (
        <section className="overflow-hidden rounded-lg border border-[var(--border)] bg-white">
            <header className="border-b border-[var(--border)] bg-slate-50 px-4 py-3">
                <h4 className="font-bold">{title}</h4>
            </header>
            <div className="flex gap-2 border-b border-[var(--border)] p-3">
                <select className="sig-input min-w-0 flex-1" value={selectedId} onChange={(e) => setSelectedId(e.target.value)}>
                    <option value="">Selecionar função</option>
                    {options.map((item) => <option key={item.id} value={item.id}>{item.descricao}</option>)}
                </select>
                <button type="button" onClick={onAdd} disabled={!selectedId} className="sig-btn sig-btn-primary !px-3"><Plus size={15} /></button>
            </div>
            {entries.map(([id, quantity]) => {
                const item = catalogs.find((candidate) => String(candidate.id) === String(id));
                return <div key={id} className="grid grid-cols-[1fr_80px_36px] items-center gap-2 border-b border-[var(--border)] px-3 py-3 last:border-0">
                    <div className="min-w-0"><strong className="block truncate text-sm">{item?.descricao || `Item ${id}`}</strong><span className="text-[10px] text-[var(--ink-500)]">{item?.unidade}</span></div>
                    <input type="number" min="0" step="1" className="sig-input !px-2" placeholder="Qtd." value={quantity} onChange={(e) => onQuantity(id, e.target.value)} />
                    <button type="button" onClick={() => onRemove(id)} className="rounded-lg p-2 text-red-600 hover:bg-red-50" aria-label={`Remover ${title}`}><Trash2 size={15} /></button>
                </div>;
            })}
            {entries.length === 0 && <p className="p-4 text-center text-xs text-[var(--ink-500)]">Nenhum item adicionado.</p>}
            <footer className="border-t border-[var(--border)] bg-slate-50 px-4 py-2 text-right text-xs font-bold">
                Total: {sumQuantities(entries)}
            </footer>
        </section>
    );
}

function sumQuantities(entries) {
    return entries.reduce((total, [, value]) => total + (Number(value) || 0), 0)
        .toLocaleString('pt-BR', { maximumFractionDigits: 2 });
}

function EquipmentFields({ form, catalogs }) {
    const [selectedId, setSelectedId] = useState('');
    const recordEntries = Object.entries(form.data.dados.registros || {});
    const available = (catalogs.equipamentos || []).filter((item) => !Object.prototype.hasOwnProperty.call(form.data.dados.registros || {}, String(item.id)));
    const add = () => {
        if (!selectedId) return;
        form.setData('dados', {
            ...form.data.dados,
            registros: { ...(form.data.dados.registros || {}), [selectedId]: { quantidade: '', situacao: '' } },
        });
        setSelectedId('');
    };
    const remove = (id) => {
        const next = { ...(form.data.dados.registros || {}) };
        delete next[id];
        form.setData('dados', { ...form.data.dados, registros: next });
    };
    const setRecord = (id, key, value) => form.setData('dados', {
        ...form.data.dados, registros: { ...(form.data.dados.registros || {}), [id]: { ...(form.data.dados.registros?.[id] || {}), [key]: value } },
    });
    const set = (key, value) => form.setData('dados', { ...form.data.dados, [key]: value });
    return <div className="space-y-4">
        <div className="flex flex-col gap-2 rounded-lg border border-[var(--border)] bg-slate-50 p-3 sm:flex-row">
            <select className="sig-input min-w-0 flex-1" value={selectedId} onChange={(e) => setSelectedId(e.target.value)}>
                <option value="">Selecione um equipamento para adicionar</option>
                {available.map((item) => <option key={item.id} value={item.id}>{item.codigo ? `${item.codigo} - ` : ''}{item.descricao}</option>)}
            </select>
            <button type="button" onClick={add} disabled={!selectedId} className="sig-btn sig-btn-primary"><Plus size={15} /> Adicionar</button>
        </div>
        <div className="overflow-hidden rounded-lg border border-[var(--border)]">
            {recordEntries.map(([id, record]) => {
                const item = (catalogs.equipamentos || []).find((candidate) => String(candidate.id) === String(id));
                return <div key={id} className="grid gap-3 border-b border-[var(--border)] px-4 py-3 last:border-0 md:grid-cols-[1fr_120px_180px_40px] md:items-center">
                    <div><strong>{item ? `${item.codigo ? `${item.codigo} - ` : ''}${item.descricao}` : `Item ${id}`}</strong><p className="text-xs text-[var(--ink-500)]">{item?.unidade || 'Cadastro não disponível'}</p></div>
                    <input type="number" min="0" step="0.01" className="sig-input" placeholder="Horas/Qtd." value={record?.quantidade || ''} onChange={(e) => setRecord(id, 'quantidade', e.target.value)} />
                    <select className="sig-input" value={record?.situacao || ''} onChange={(e) => setRecord(id, 'situacao', e.target.value)}><option value="">Situação</option><option value="operando">Operando</option><option value="parado">Parado</option><option value="manutencao">Manutenção</option></select>
                    <button type="button" onClick={() => remove(id)} className="rounded-lg p-2 text-red-600 hover:bg-red-50" aria-label="Remover equipamento"><Trash2 size={16} /></button>
                </div>;
            })}
            {recordEntries.length === 0 && <p className="p-5 text-center text-sm text-[var(--ink-500)]">Nenhum equipamento adicionado.</p>}
            <footer className="border-t border-[var(--border)] bg-slate-50 px-4 py-2 text-right text-xs font-bold">
                Total equipamentos: {sumQuantities(recordEntries.map(([id, record]) => [id, record?.quantidade]))}
            </footer>
        </div>
        <Field label="Observações"><textarea className="sig-input min-h-24" value={form.data.dados.observacoes || ''} onChange={(e) => set('observacoes', e.target.value)} /></Field>
    </div>;
}

function ActivityFields({ form }) {
    const legacyActivity = form.data.dados.atividades_executadas
        ? [{ titulo: 'Atividade executada', ocorrencia: form.data.dados.atividades_executadas }]
        : [];
    const legacyOccurrences = [form.data.dados.ocorrencias, form.data.dados.interferencias, form.data.dados.acidentes].filter(Boolean).join('\n\n');
    const activities = form.data.dados.atividades?.length
        ? form.data.dados.atividades
        : (legacyOccurrences ? [{ titulo: 'Ocorrências importantes', ocorrencia: legacyOccurrences }] : legacyActivity);
    const normalize = (next) => next.map((activity) => ({
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

function PhotoFields({ form }) {
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
        if (files.length === 0) return;

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
                <p className="mt-1 text-xs text-[var(--ink-500)]">Organize a posição, comente ou exclua cada imagem.</p>
            </div>
            <label className="sig-btn sig-btn-secondary sig-btn-sm">
                <ImagePlus size={14} /> Adicionar fotos
                <input className="sr-only" type="file" multiple accept="image/jpeg,image/png,image/webp" onChange={addPhotos} />
            </label>
        </div>
        {photos.length > 0 ? (
            <div className="grid gap-3">
                {photos.map((photo, index) => (
                    <div key={photo.key} className="grid gap-3 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-2 sm:grid-cols-[100px_minmax(0,1fr)]">
                        <a href={photo.previewUrl} target="_blank" rel="noreferrer">
                            <img src={photo.previewUrl} alt={photo.name} className="h-24 w-24 rounded-md object-cover" />
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

function CommentFields({ form }) {
    const set = (key, value) => form.setData('dados', { ...form.data.dados, [key]: value });
    return <div className="grid gap-4">
        <Field label="Comentário da construtora"><textarea className="sig-input min-h-28" value={form.data.dados.construtora || ''} onChange={(e) => set('construtora', e.target.value)} /></Field>
        {(form.data.dados.gerenciadora || form.data.dados.cliente) && (
            <p className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Comentários antigos da gerenciadora e do cliente foram preservados. Os novos pareceres são registrados no histórico do fluxo de aprovação.
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
