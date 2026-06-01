import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArchiveX, CheckCircle2, ChevronDown, Download, Eye, FileUp, Filter, FolderOpen, MessageSquare, Search, Send, Trash2, TriangleAlert, UploadCloud, X } from 'lucide-react';
import { useMemo, useRef } from 'react';
import { useState } from 'react';

const PROJECT_SEQUENCE_LENGTH = 3;

function contractLabel(contract) {
    return `${contract.code} - ${contract.name}`;
}

function normalizeCodePart(value) {
    return String(value || '')
        .trim()
        .toUpperCase()
        .replace(/\s+/g, '')
        .replace(/[^A-Z0-9]/g, '');
}

function buildProjectCode(contract, obra, disciplina, projectPhase, documentType, documentTypeCodes, documentNumber) {
    const documentTypeCode = documentTypeCodes?.[documentType] || documentType;

    return [contract?.code, obra?.codigo, disciplina?.sigla, projectPhase?.code, documentTypeCode, documentNumber]
        .map(normalizeCodePart)
        .filter(Boolean)
        .join('-');
}

function normalizeDocumentNumber(value) {
    const digits = String(value || '').replace(/\D+/g, '').slice(0, PROJECT_SEQUENCE_LENGTH);

    return digits ? digits.padStart(PROJECT_SEQUENCE_LENGTH, '0') : '';
}

function nextRevisionLabel(revision) {
    const match = String(revision || '').match(/^R?(\d+)$/i);

    if (!match) {
        return 'R00';
    }

    return `R${String(Number(match[1]) + 1).padStart(2, '0')}`;
}

function fileDisplayName(version) {
    return version?.stored_name || version?.original_name || '';
}

function shouldShowOriginalName(version) {
    return Boolean(version?.original_name && version?.stored_name && version.original_name !== version.stored_name);
}

function isManuallyInactiveDocument(document) {
    return Boolean(document?.inactive_at || document?.status === 'inativo');
}

function isTreeActiveDocument(document) {
    return document?.status === 'ativo' && !isManuallyInactiveDocument(document);
}

function isInactiveDocument(document) {
    return !isTreeActiveDocument(document);
}

function OpenRncBadge({ tenant, document }) {
    const count = Number(document?.open_rncs_count || 0);
    const firstOpenRnc = document?.open_rncs?.[0];

    if (!count) {
        return null;
    }

    const content = (
        <>
            <TriangleAlert size={12} />
            {count} {count === 1 ? 'RNC aberta' : 'RNCs abertas'}
        </>
    );

    if (firstOpenRnc?.id) {
        return (
            <Link
                href={route('tenant.qualidade.rnc.show', [tenant.slug, firstOpenRnc.id])}
                className="sig-pill sig-pill-red inline-flex items-center gap-1 hover:underline"
                title={`Abrir RNC ${firstOpenRnc.formatted_number || ''}`.trim()}
            >
                {content}
            </Link>
        );
    }

    return (
        <span className="sig-pill sig-pill-red inline-flex items-center gap-1">
            {content}
        </span>
    );
}

function isApsWaiting(version) {
    return ['queued', 'processing'].includes(version?.derivative_status);
}

function viewerWorkspaceUrl(tenant, version, workspace = 'view') {
    return `${route('tenant.projects.viewer', [tenant.slug, version.id])}?workspace=${workspace}`;
}

const derivativeLabels = {
    not_submitted: 'Aguardando APS',
    queued: 'Na fila APS',
    processing: 'Processando',
    ready: 'Pronto para viewer',
    failed: 'Erro no APS',
};

const MAX_PROJECT_FILE_SIZE = 50 * 1024 * 1024;

const statusClasses = {
    em_analise: 'sig-pill-blue',
    em_aprovacao: 'sig-pill-amber',
    ativo: 'sig-pill-green',
    inativo: 'sig-pill-red',
    reprovado: 'sig-pill-red',
};

export default function ProjectsIndex({
    tenant,
    contracts,
    obras,
    disciplinas,
    documents,
    projectPhases = [],
    documentTypes,
    documentTypeCodes,
    statusLabels,
    capImpactLabels = {},
    allowedExtensions,
    canUploadProjects,
    canAnalyzeProjects,
    canDeleteProjects,
}) {
    const page = usePage();
    const defaultContract = contracts[0] ?? null;
    const defaultContractId = defaultContract?.id ?? '';
    const defaultObra = obras.find((obra) => String(obra.contract_id) === String(defaultContractId)) ?? null;
    const defaultObraId = defaultObra?.id ?? '';
    const defaultDisciplina = disciplinas.find((disciplina) => String(disciplina.contract_id) === String(defaultContractId)) ?? null;
    const defaultDisciplinaId = defaultDisciplina?.id ?? '';
    const defaultProjectPhase = projectPhases[0] ?? null;
    const defaultProjectPhaseId = defaultProjectPhase?.id ?? '';
    const defaultDocumentType = Object.keys(documentTypes)[0] ?? 'projeto';
    const acceptedProjectExtensions = useMemo(
        () => allowedExtensions.map((extension) => `.${extension}`).join(','),
        [allowedExtensions],
    );
    const allowedProjectExtensions = useMemo(
        () => allowedExtensions.map((extension) => String(extension).toLowerCase()),
        [allowedExtensions],
    );
    const [contractFilter, setContractFilter] = useState('todos');
    const [obraFilter, setObraFilter] = useState('todos');
    const [disciplinaFilter, setDisciplinaFilter] = useState('todos');
    const [statusFilter, setStatusFilter] = useState('todos');
    const [query, setQuery] = useState('');
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [submitPanelOpen, setSubmitPanelOpen] = useState(false);
    const [inactivateDocument, setInactivateDocument] = useState(null);
    const [expandedDocumentIds, setExpandedDocumentIds] = useState([]);
    const confirmedSubmitRef = useRef(false);
    const form = useForm({
        contract_id: defaultContractId,
        obra_id: defaultObraId,
        disciplina_id: defaultDisciplinaId,
        project_phase_id: defaultProjectPhaseId,
        title: '',
        document_number: '001',
        code: buildProjectCode(defaultContract, defaultObra, defaultDisciplina, defaultProjectPhase, defaultDocumentType, documentTypeCodes, '001'),
        document_type: defaultDocumentType,
        revision: 'Automatica',
        revision_change_summary: '',
        cap_reason: '',
        cap_description: '',
        cap_impacts: [],
        file: null,
    });
    const inactivateForm = useForm({
        inactive_reason: '',
    });

    const selectedContract = useMemo(
        () => contracts.find((contract) => String(contract.id) === String(form.data.contract_id)) ?? null,
        [contracts, form.data.contract_id],
    );

    const selectedObra = useMemo(
        () => obras.find((obra) => String(obra.id) === String(form.data.obra_id)) ?? null,
        [obras, form.data.obra_id],
    );

    const selectedDisciplina = useMemo(
        () => disciplinas.find((disciplina) => String(disciplina.id) === String(form.data.disciplina_id)) ?? null,
        [disciplinas, form.data.disciplina_id],
    );

    const selectedProjectPhase = useMemo(
        () => projectPhases.find((phase) => String(phase.id) === String(form.data.project_phase_id)) ?? null,
        [projectPhases, form.data.project_phase_id],
    );

    const selectedDocumentTypeLabel = documentTypes?.[form.data.document_type] || form.data.document_type;
    const selectedProjectPhaseLabel = selectedProjectPhase ? `${selectedProjectPhase.code} - ${selectedProjectPhase.name}` : '';
    const normalizedSequential = normalizeDocumentNumber(form.data.document_number);
    const existingDocumentForEap = useMemo(
        () => {
            const legacyCodeWithNumber = buildProjectCode(selectedContract, selectedObra, selectedDisciplina, null, form.data.document_type, documentTypeCodes, normalizedSequential);
            const legacyCodeWithoutNumber = buildProjectCode(selectedContract, selectedObra, selectedDisciplina, null, form.data.document_type, documentTypeCodes);

            return documents.find((document) => {
                const documentCode = String(document.code || '');

                if (documentCode === String(form.data.code || '')) {
                    return true;
                }

                return !document.project_phase_id
                    && (documentCode === legacyCodeWithNumber || (normalizedSequential === '001' && documentCode === legacyCodeWithoutNumber));
            }) ?? null;
        },
        [documents, form.data.code, form.data.document_type, selectedContract, selectedObra, selectedDisciplina, documentTypeCodes, normalizedSequential],
    );
    const isRevision = Boolean(existingDocumentForEap);
    const revisionPreview = existingDocumentForEap
        ? nextRevisionLabel(existingDocumentForEap.latest_version?.revision)
        : 'R00';
    const fullEapPreview = [form.data.code, revisionPreview].filter(Boolean).join('-');
    const submissionTitle = existingDocumentForEap?.title || form.data.title;

    const disciplinasForForm = useMemo(
        () => disciplinas.filter((disciplina) => String(disciplina.contract_id) === String(form.data.contract_id)),
        [disciplinas, form.data.contract_id],
    );

    const obrasForForm = useMemo(
        () => obras.filter((obra) => String(obra.contract_id) === String(form.data.contract_id)),
        [obras, form.data.contract_id],
    );
    const canTrySubmit = Boolean(
        canUploadProjects
        && contracts.length > 0
        && obrasForForm.length > 0
        && disciplinasForForm.length > 0
        && projectPhases.length > 0
        && !form.processing,
    );

    const obrasForFilter = useMemo(
        () => contractFilter === 'todos'
            ? obras
            : obras.filter((obra) => String(obra.contract_id) === String(contractFilter)),
        [obras, contractFilter],
    );

    const disciplinasForFilter = useMemo(
        () => contractFilter === 'todos'
            ? disciplinas
            : disciplinas.filter((disciplina) => String(disciplina.contract_id) === String(contractFilter)),
        [disciplinas, contractFilter],
    );

    const filteredDocuments = useMemo(() => {
        const term = query.trim().toLowerCase();

        return documents.filter((document) => {
            if (contractFilter !== 'todos' && String(document.contract_id) !== String(contractFilter)) {
                return false;
            }

            if (obraFilter !== 'todos' && String(document.obra_id) !== String(obraFilter)) {
                return false;
            }

            if (disciplinaFilter !== 'todos' && String(document.disciplina_id) !== String(disciplinaFilter)) {
                return false;
            }

            if (statusFilter === 'ativo' && !isTreeActiveDocument(document)) {
                return false;
            }

            if (statusFilter === 'inativo' && isTreeActiveDocument(document)) {
                return false;
            }

            if (!term) {
                return true;
            }

            return `${document.title} ${document.code || ''} ${fileDisplayName(document.latest_version)} ${document.latest_version?.original_name || ''} ${document.obra?.nome || ''} ${document.obra?.codigo || ''} ${document.disciplina?.nome || ''} ${document.phase?.name || ''} ${document.phase?.code || ''}`
                .toLowerCase()
                .includes(term);
        });
    }, [documents, contractFilter, obraFilter, disciplinaFilter, statusFilter, query]);

    const updateContract = (contractId) => {
        const nextContract = contracts.find((contract) => String(contract.id) === String(contractId)) ?? null;
        const nextObra = obras.find((obra) => String(obra.contract_id) === String(contractId)) ?? null;
        const nextDisciplina = disciplinas.find((disciplina) => String(disciplina.contract_id) === String(contractId)) ?? null;

        form.setData({
            ...form.data,
            contract_id: contractId,
            obra_id: nextObra?.id ?? '',
            disciplina_id: nextDisciplina?.id ?? '',
            code: buildProjectCode(nextContract, nextObra, nextDisciplina, selectedProjectPhase, form.data.document_type, documentTypeCodes, normalizeDocumentNumber(form.data.document_number)),
        });
    };

    const updateObra = (obraId) => {
        const currentContract = contracts.find((contract) => String(contract.id) === String(form.data.contract_id)) ?? null;
        const nextObra = obras.find((obra) => String(obra.id) === String(obraId)) ?? null;
        const currentDisciplina = disciplinas.find((disciplina) => String(disciplina.id) === String(form.data.disciplina_id)) ?? null;

        form.setData({
            ...form.data,
            obra_id: obraId,
            code: buildProjectCode(currentContract, nextObra, currentDisciplina, selectedProjectPhase, form.data.document_type, documentTypeCodes, normalizeDocumentNumber(form.data.document_number)),
        });
    };

    const updateDisciplina = (disciplinaId) => {
        const currentContract = contracts.find((contract) => String(contract.id) === String(form.data.contract_id)) ?? null;
        const currentObra = obras.find((obra) => String(obra.id) === String(form.data.obra_id)) ?? null;
        const nextDisciplina = disciplinas.find((disciplina) => String(disciplina.id) === String(disciplinaId)) ?? null;

        form.setData({
            ...form.data,
            disciplina_id: disciplinaId,
            code: buildProjectCode(currentContract, currentObra, nextDisciplina, selectedProjectPhase, form.data.document_type, documentTypeCodes, normalizeDocumentNumber(form.data.document_number)),
        });
    };

    const updateProjectPhase = (projectPhaseId) => {
        const currentContract = contracts.find((contract) => String(contract.id) === String(form.data.contract_id)) ?? null;
        const currentObra = obras.find((obra) => String(obra.id) === String(form.data.obra_id)) ?? null;
        const currentDisciplina = disciplinas.find((disciplina) => String(disciplina.id) === String(form.data.disciplina_id)) ?? null;
        const nextProjectPhase = projectPhases.find((phase) => String(phase.id) === String(projectPhaseId)) ?? null;

        form.setData({
            ...form.data,
            project_phase_id: projectPhaseId,
            code: buildProjectCode(currentContract, currentObra, currentDisciplina, nextProjectPhase, form.data.document_type, documentTypeCodes, normalizeDocumentNumber(form.data.document_number)),
        });
    };

    const updateDocumentType = (documentType) => {
        const currentContract = contracts.find((contract) => String(contract.id) === String(form.data.contract_id)) ?? null;
        const currentObra = obras.find((obra) => String(obra.id) === String(form.data.obra_id)) ?? null;
        const currentDisciplina = disciplinas.find((disciplina) => String(disciplina.id) === String(form.data.disciplina_id)) ?? null;

        form.setData({
            ...form.data,
            document_type: documentType,
            code: buildProjectCode(currentContract, currentObra, currentDisciplina, selectedProjectPhase, documentType, documentTypeCodes, normalizeDocumentNumber(form.data.document_number)),
        });
    };

    const updateDocumentNumber = (value) => {
        const documentNumber = String(value || '').replace(/\D+/g, '').slice(0, PROJECT_SEQUENCE_LENGTH);
        const codeDocumentNumber = normalizeDocumentNumber(documentNumber);
        const currentContract = contracts.find((contract) => String(contract.id) === String(form.data.contract_id)) ?? null;
        const currentObra = obras.find((obra) => String(obra.id) === String(form.data.obra_id)) ?? null;
        const currentDisciplina = disciplinas.find((disciplina) => String(disciplina.id) === String(form.data.disciplina_id)) ?? null;

        form.setData({
            ...form.data,
            document_number: documentNumber,
            code: buildProjectCode(currentContract, currentObra, currentDisciplina, selectedProjectPhase, form.data.document_type, documentTypeCodes, codeDocumentNumber),
        });
    };

    const finishDocumentNumber = () => {
        const documentNumber = normalizeDocumentNumber(form.data.document_number);

        if (!documentNumber) {
            return;
        }

        form.setData({
            ...form.data,
            document_number: documentNumber,
        });
    };

    const updateContractFilter = (contractId) => {
        setContractFilter(contractId);
        setObraFilter('todos');
        setDisciplinaFilter('todos');
    };

    const toggleDocumentDetails = (documentId) => {
        setExpandedDocumentIds((currentIds) => currentIds.includes(documentId)
            ? currentIds.filter((currentId) => currentId !== documentId)
            : [...currentIds, documentId]);
    };

    const toggleCapImpact = (impact) => {
        const impacts = Array.isArray(form.data.cap_impacts) ? form.data.cap_impacts : [];

        form.setData('cap_impacts', impacts.includes(impact)
            ? impacts.filter((current) => current !== impact)
            : [...impacts, impact]);
    };

    const validateBeforeConfirmation = () => {
        form.clearErrors(
            'contract_id',
            'obra_id',
            'disciplina_id',
            'project_phase_id',
            'title',
            'document_number',
            'document_type',
            'revision_change_summary',
            'cap_reason',
            'cap_description',
            'cap_impacts',
            'file',
        );

        const errors = {};

        if (!form.data.contract_id) {
            errors.contract_id = 'Selecione o contrato.';
        }

        if (!form.data.obra_id) {
            errors.obra_id = 'Selecione a obra.';
        }

        if (!form.data.disciplina_id) {
            errors.disciplina_id = 'Selecione a disciplina.';
        }

        if (!form.data.project_phase_id) {
            errors.project_phase_id = 'Selecione a fase do projeto.';
        }

        if (!isRevision && !form.data.title.trim()) {
            errors.title = 'Informe o titulo do projeto.';
        }

        if (!normalizedSequential) {
            errors.document_number = 'Informe o sequencial do projeto.';
        }

        if (!form.data.document_type) {
            errors.document_type = 'Selecione o tipo de documento.';
        }

        if (isRevision && !form.data.cap_reason.trim()) {
            errors.cap_reason = 'Informe o motivo da alteracao desta revisao.';
        }

        if (isRevision && !form.data.cap_description.trim()) {
            errors.cap_description = 'Descreva o que foi alterado nesta revisao.';
        }

        if (isRevision && !form.data.cap_impacts.length) {
            errors.cap_impacts = 'Selecione ao menos um impacto da alteracao.';
        }

        if (!form.data.file) {
            errors.file = 'Selecione um arquivo de projeto.';
        } else if (form.data.file.size > MAX_PROJECT_FILE_SIZE) {
            errors.file = 'O arquivo deve ter no maximo 50 MB.';
        }

        Object.entries(errors).forEach(([field, message]) => form.setError(field, message));

        return Object.keys(errors).length === 0;
    };

    const submit = (event) => {
        event.preventDefault();

        if (!validateBeforeConfirmation()) {
            setConfirmOpen(false);
            confirmedSubmitRef.current = false;
            return;
        }

        if (!confirmedSubmitRef.current) {
            setConfirmOpen(true);
            return;
        }

        confirmedSubmitRef.current = false;
        form.post(route('tenant.projects.store', tenant.slug), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setConfirmOpen(false);
                setSubmitPanelOpen(false);
                form.reset('title', 'revision_change_summary', 'cap_reason', 'cap_description', 'cap_impacts', 'file');
            },
            onError: () => setConfirmOpen(false),
        });
    };

    const confirmSubmit = () => {
        confirmedSubmitRef.current = true;
        setConfirmOpen(false);
        form.post(route('tenant.projects.store', tenant.slug), {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => setConfirmOpen(false),
            onSuccess: () => {
                setSubmitPanelOpen(false);
                form.reset('title', 'revision_change_summary', 'cap_reason', 'cap_description', 'cap_impacts', 'file');
            },
        });
    };

    const deleteDocument = (document) => {
        router.delete(route('tenant.projects.destroy', [tenant.slug, document.id]), {
            preserveScroll: true,
        });
    };

    const openInactivateModal = (document) => {
        inactivateForm.clearErrors();
        inactivateForm.setData('inactive_reason', '');
        setInactivateDocument(document);
    };

    const closeInactivateModal = () => {
        if (inactivateForm.processing) {
            return;
        }

        setInactivateDocument(null);
        inactivateForm.reset();
        inactivateForm.clearErrors();
    };

    const submitInactivation = () => {
        if (!inactivateDocument) {
            return;
        }

        inactivateForm.patch(route('tenant.projects.inactivate', [tenant.slug, inactivateDocument.id]), {
            preserveScroll: true,
            onSuccess: closeInactivateModal,
        });
    };

    const processVersion = (version) => {
        router.post(route('tenant.projects.process-aps', [tenant.slug, version.id]), {}, {
            preserveScroll: true,
        });
    };

    const updateFile = (file) => {
        form.clearErrors('file');

        if (file) {
            const extension = String(file.name || '').split('.').pop()?.toLowerCase() || '';

            if (!allowedProjectExtensions.includes(extension)) {
                form.setError('file', `Formato nao permitido. Use: ${allowedExtensions.map((item) => `.${item}`).join(', ')}.`);
                form.setData('file', null);
                return;
            }
        }

        if (file && file.size > MAX_PROJECT_FILE_SIZE) {
            form.setError('file', 'O arquivo deve ter no maximo 50 MB.');
            form.setData('file', null);
            return;
        }

        form.setData('file', file);
    };

    return (
        <AuthenticatedLayout>
            <Head title="Submeter projeto" />

            <section className="sig-content grid gap-6">
                {submitPanelOpen && (
                    <div
                        className="fixed inset-0 z-[110] flex justify-end bg-[rgba(11,16,32,0.42)]"
                        role="presentation"
                        onMouseDown={() => setSubmitPanelOpen(false)}
                    >
                        <form
                            className="h-full w-full max-w-[500px] overflow-y-auto border-l border-[var(--border)] bg-white p-5 shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                            onSubmit={submit}
                            noValidate
                            onMouseDown={(event) => event.stopPropagation()}
                        >
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <FolderOpen size={14} />
                                <span className="eyebrow">Projetos</span>
                            </div>
                            <h1 className="mt-2 text-xl font-semibold text-[var(--ink-900)]">Submeter projeto</h1>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                Envie arquivos tecnicos por contrato, obra, disciplina e revisao. Todo envio passa por analise e aprovacao antes de aparecer na arvore principal.
                            </p>
                        </div>
                        <button
                            type="button"
                            className="sig-btn sig-btn-ghost !min-h-9 !px-2"
                            title="Fechar"
                            aria-label="Fechar formulário de submissão"
                            onClick={() => setSubmitPanelOpen(false)}
                        >
                            <X size={18} />
                        </button>
                    </div>

                    {page.props.flash.success && (
                        <div className="mt-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}
                    {page.props.flash.error && (
                        <div className="mt-4 rounded-lg bg-[var(--red-50)] px-3 py-2 text-sm text-[var(--red)]">
                            {page.props.flash.error}
                        </div>
                    )}

                    <div className="mt-5 grid gap-3">
                        <Field label="Contrato" error={form.errors.contract_id}>
                            <select value={form.data.contract_id} onChange={(event) => updateContract(event.target.value)} required>
                                <option value="">Selecione o contrato</option>
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>
                                        {contractLabel(contract)}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Obra" error={form.errors.obra_id}>
                            <select value={form.data.obra_id} onChange={(event) => updateObra(event.target.value)} required>
                                <option value="">Selecione a obra</option>
                                {obrasForForm.map((obra) => (
                                    <option key={obra.id} value={obra.id}>
                                        {obra.codigo} - {obra.nome}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Disciplina" error={form.errors.disciplina_id}>
                            <select value={form.data.disciplina_id} onChange={(event) => updateDisciplina(event.target.value)} required>
                                <option value="">Selecione a disciplina</option>
                                {disciplinasForForm.map((disciplina) => (
                                    <option key={disciplina.id} value={disciplina.id}>
                                        {disciplina.sigla} - {disciplina.nome}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Fase do projeto" error={form.errors.project_phase_id}>
                            <select value={form.data.project_phase_id} onChange={(event) => updateProjectPhase(event.target.value)} required>
                                <option value="">Selecione a fase</option>
                                {projectPhases.map((phase) => (
                                    <option key={phase.id} value={phase.id}>
                                        {phase.code} - {phase.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Titulo" error={form.errors.title}>
                            <input
                                value={submissionTitle}
                                onChange={(event) => !isRevision && form.setData('title', event.target.value)}
                                placeholder="Ex: Projeto estrutural - Bloco A"
                                readOnly={isRevision}
                                required={!isRevision}
                            />
                            {isRevision && (
                                <span className="mt-1 block text-xs text-[var(--ink-500)]">
                                    Revisões mantêm o título do projeto anterior.
                                </span>
                            )}
                        </Field>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Sequencial" error={form.errors.document_number}>
                                <input
                                    value={form.data.document_number}
                                    onChange={(event) => updateDocumentNumber(event.target.value)}
                                    onBlur={finishDocumentNumber}
                                    placeholder="001"
                                    inputMode="numeric"
                                    maxLength={PROJECT_SEQUENCE_LENGTH}
                                    required
                                />
                            </Field>

                            <Field label="Proxima revisao" error={form.errors.revision}>
                                <input value={revisionPreview} readOnly placeholder="Automatica" maxLength={30} />
                            </Field>
                        </div>

                        <Field label="Tipo de documento" error={form.errors.document_type}>
                            <select value={form.data.document_type} onChange={(event) => updateDocumentType(event.target.value)} required>
                                {Object.entries(documentTypes).map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </select>
                        </Field>

                        <EapPreview fullEap={fullEapPreview} />

                        {isRevision && (
                            <CapFields
                                capReason={form.data.cap_reason}
                                capDescription={form.data.cap_description}
                                capImpacts={form.data.cap_impacts}
                                capImpactLabels={capImpactLabels}
                                errors={form.errors}
                                onReasonChange={(value) => form.setData('cap_reason', value)}
                                onDescriptionChange={(value) => form.setData('cap_description', value)}
                                onImpactToggle={toggleCapImpact}
                            />
                        )}

                        <div>
                            <span className="eyebrow mb-1 block">Arquivo</span>
                            <label className="flex cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-[var(--border)] bg-[var(--surface-muted)] px-4 py-6 text-center hover:bg-white">
                                <UploadCloud size={28} className="text-[var(--primary)]" />
                                <span className="mt-2 text-sm font-semibold text-[var(--ink-900)]">
                                    {form.data.file?.name || 'Selecionar arquivo'}
                                </span>
                                <span className="mt-1 text-[12px] text-[var(--ink-500)]">
                                    Formatos: {allowedExtensions.map((extension) => `.${extension}`).join(', ')}. Maximo 50 MB.
                                </span>
                                <input
                                    className="sr-only"
                                    type="file"
                                    accept={acceptedProjectExtensions}
                                    onChange={(event) => updateFile(event.target.files?.[0] || null)}
                                    required
                                />
                            </label>
                            {form.errors.file && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.file}</span>}
                        </div>
                    </div>

                    <button
                        className="sig-btn sig-btn-primary mt-5"
                        disabled={!canTrySubmit}
                    >
                        <Send size={15} />
                        Revisar e confirmar
                    </button>
                        </form>
                    </div>
                )}

                {!submitPanelOpen && page.props.flash.success && (
                    <div className="rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                        {page.props.flash.success}
                    </div>
                )}
                {!submitPanelOpen && page.props.flash.error && (
                    <div className="rounded-lg bg-[var(--red-50)] px-3 py-2 text-sm text-[var(--red)]">
                        {page.props.flash.error}
                    </div>
                )}

                <section className="projects-list-card sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <FileUp size={14} />
                                <span className="eyebrow">Projetos submetidos</span>
                            </div>
                            <h2 className="mt-1 text-[15px] font-semibold">{filteredDocuments.length} de {documents.length} documentos</h2>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {canUploadProjects && (
                                <button
                                    type="button"
                                    className="sig-btn sig-btn-primary sig-btn-sm"
                                    onClick={() => setSubmitPanelOpen(true)}
                                >
                                    <UploadCloud size={13} />
                                    Submeter projeto
                                </button>
                            )}
                            {canAnalyzeProjects && (
                                <Link href={route('tenant.projects.review.index', tenant.slug)} className="sig-btn sig-btn-secondary sig-btn-sm">
                                    <Eye size={13} />
                                    Analisar projeto
                                </Link>
                            )}
                        </div>
                    </header>

                    <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 xl:grid-cols-5">
                        <FilterSelect label="Contrato" value={contractFilter} onChange={updateContractFilter}>
                            <option value="todos">Todos os contratos</option>
                            {contracts.map((contract) => (
                                <option key={contract.id} value={contract.id}>{contractLabel(contract)}</option>
                            ))}
                        </FilterSelect>

                        <FilterSelect label="Obra" value={obraFilter} onChange={setObraFilter}>
                            <option value="todos">Todas as obras</option>
                            {obrasForFilter.map((obra) => (
                                <option key={obra.id} value={obra.id}>{obra.codigo} - {obra.nome}</option>
                            ))}
                        </FilterSelect>

                        <FilterSelect label="Disciplina" value={disciplinaFilter} onChange={setDisciplinaFilter}>
                            <option value="todos">Todas as disciplinas</option>
                            {disciplinasForFilter.map((disciplina) => (
                                <option key={disciplina.id} value={disciplina.id}>{disciplina.sigla} - {disciplina.nome}</option>
                            ))}
                        </FilterSelect>

                        <FilterSelect label="Situacao" value={statusFilter} onChange={setStatusFilter}>
                            <option value="todos">Ativos e inativos</option>
                            <option value="ativo">Ativos na arvore</option>
                            <option value="inativo">Inativos na arvore</option>
                        </FilterSelect>

                        <label>
                            <span className="eyebrow mb-1 flex items-center gap-1">
                                <Search size={12} />
                                Busca
                            </span>
                            <span className="sig-input bg-white">
                                <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar documento" />
                            </span>
                        </label>
                    </div>

                    {filteredDocuments.length > 0 ? (
                        <>
                        <div className="projects-desktop-table overflow-x-auto">
                        <table className="sig-table min-w-[1280px]">
                            <thead>
                                <tr>
                                    <th>Documento</th>
                                    <th>Contrato</th>
                                    <th>Obra</th>
                                    <th>Disciplina</th>
                                    <th>Revisao</th>
                                    <th>Status</th>
                                    <th>Arquivo</th>
                                    <th>Status APS</th>
                                    <th>Acoes</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredDocuments.map((document) => {
                                    const version = document.latest_version;
                                    const treeActive = isTreeActiveDocument(document);
                                    const inactive = isInactiveDocument(document);
                                    const manuallyInactive = isManuallyInactiveDocument(document);
                                    const displayStatus = manuallyInactive ? 'inativo' : document.status;

                                    return (
                                        <tr key={document.id}>
                                            <td>
                                                <div className="font-semibold">{document.title}</div>
                                                 <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                     Fase: {document.phase ? `${document.phase.code} - ${document.phase.name}` : 'Sem fase'}
                                                 </div>
                                                 <div className="mono mt-1 text-xs text-[var(--ink-500)]">{document.code || 'Sem codigo'} · {documentTypes[document.document_type] || document.document_type}</div>
                                                 {Number(document.open_rncs_count || 0) > 0 && (
                                                     <div className="mt-2">
                                                         <OpenRncBadge tenant={tenant} document={document} />
                                                     </div>
                                                 )}
                                            </td>
                                            <td>
                                                <div className="mono text-xs">{document.contract?.code}</div>
                                                <div className="text-xs text-[var(--ink-500)]">{document.contract?.name}</div>
                                            </td>
                                            <td>
                                                <div className="mono text-xs">{document.obra?.codigo}</div>
                                                <div className="text-xs text-[var(--ink-500)]">{document.obra?.nome || 'Sem obra'}</div>
                                            </td>
                                            <td>
                                                <span className="inline-flex items-center gap-2 text-sm font-semibold text-[var(--ink-700)]">
                                                    <span className="h-3.5 w-3.5 rounded-full border border-[var(--border)]" style={{ backgroundColor: document.disciplina?.cor || '#2563eb' }} />
                                                    {document.disciplina?.sigla} - {document.disciplina?.nome}
                                                </span>
                                            </td>
                                            <td className="font-semibold">{version?.revision}</td>
                                            <td>
                                                <span className={`sig-pill ${statusClasses[displayStatus] || 'sig-pill-blue'}`}>
                                                    {statusLabels[displayStatus] || displayStatus}
                                                </span>
                                                {inactive && document.inactive_at && (
                                                    <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                        Inativado por {document.inactive_by?.name || 'usuario'} em {new Date(document.inactive_at).toLocaleDateString('pt-BR')}
                                                    </div>
                                                )}
                                                {inactive && !manuallyInactive && (
                                                    <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                        Inativo na arvore ate a aprovacao.
                                                    </div>
                                                )}
                                                {inactive && document.inactive_reason && (
                                                    <div className="mt-1 max-w-[220px] truncate text-xs text-[var(--ink-500)]" title={document.inactive_reason}>
                                                        Motivo: {document.inactive_reason}
                                                    </div>
                                                )}
                                                {document.reviewed_at && (
                                                    <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                        {document.reviewer?.name || 'Revisado'} em {new Date(document.reviewed_at).toLocaleDateString('pt-BR')}
                                                    </div>
                                                )}
                                            </td>
                                            <td>
                                                <div className="max-w-[260px] truncate text-sm font-semibold">{fileDisplayName(version)}</div>
                                                {shouldShowOriginalName(version) && (
                                                    <div className="max-w-[260px] truncate text-xs text-[var(--ink-500)]">Original: {version.original_name}</div>
                                                )}
                                                <div className="text-xs text-[var(--ink-500)]">{version?.size_label}</div>
                                            </td>
                                            <td>
                                                <span className="sig-pill sig-pill-blue">{derivativeLabels[version?.derivative_status] || version?.derivative_status}</span>
                                            </td>
                                            <td>
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    {treeActive && version?.aps_urn ? (
                                                        <Link href={viewerWorkspaceUrl(tenant, version, 'view')} className="sig-btn sig-btn-primary sig-btn-sm">
                                                            <Eye size={13} />
                                                            Visualizar
                                                        </Link>
                                                    ) : treeActive && isApsWaiting(version) ? (
                                                        <span className="sig-pill bg-[var(--surface-muted)] text-[var(--ink-600)]">
                                                            Processando APS
                                                        </span>
                                                    ) : treeActive ? (
                                                        <button type="button" onClick={() => processVersion(version)} className="sig-btn sig-btn-primary sig-btn-sm">
                                                            <Eye size={13} />
                                                            Processar APS
                                                        </button>
                                                    ) : (
                                                        <span className="sig-pill bg-[var(--surface-muted)] text-[var(--ink-600)]">
                                                            Fora da arvore
                                                        </span>
                                                    )}
                                                    {version?.aps_urn && (document.status === 'ativo' || canAnalyzeProjects) && (
                                                        <Link href={viewerWorkspaceUrl(tenant, version, 'comments')} className="sig-btn sig-btn-secondary sig-btn-sm">
                                                            <MessageSquare size={13} />
                                                            Comentários
                                                        </Link>
                                                    )}
                                                    {version?.url && (
                                                        <a href={version.url} download={fileDisplayName(version)} className="sig-btn sig-btn-secondary sig-btn-sm">
                                                            <Download size={13} />
                                                            Baixar
                                                        </a>
                                                    )}
                                                    {canDeleteProjects && treeActive && (
                                                        <button
                                                            type="button"
                                                            className="sig-btn sig-btn-secondary sig-btn-sm"
                                                            onClick={() => openInactivateModal(document)}
                                                        >
                                                            <ArchiveX size={13} />
                                                            Inativar
                                                        </button>
                                                    )}
                                                    {canDeleteProjects && (
                                                        <ConfirmActionButton
                                                            title="Excluir projeto"
                                                            message={`Deseja mesmo excluir ${document.title}? O registro e o arquivo ficarao preservados no historico.`}
                                                            confirmLabel="Excluir projeto"
                                                            onConfirm={() => deleteDocument(document)}
                                                        >
                                                            <Trash2 size={13} />
                                                            Excluir
                                                        </ConfirmActionButton>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                        </div>

                        <div className="projects-responsive-list">
                            {filteredDocuments.map((document) => {
                                const version = document.latest_version;
                                const treeActive = isTreeActiveDocument(document);
                                const inactive = isInactiveDocument(document);
                                const manuallyInactive = isManuallyInactiveDocument(document);
                                const displayStatus = manuallyInactive ? 'inativo' : document.status;
                                const expanded = expandedDocumentIds.includes(document.id);

                                return (
                                    <article key={document.id} className="border-b border-[var(--border)] last:border-b-0">
                                        <button
                                            type="button"
                                            className="flex w-full items-start justify-between gap-4 px-5 py-4 text-left transition-colors hover:bg-[var(--surface-muted)]"
                                            aria-expanded={expanded}
                                            onClick={() => toggleDocumentDetails(document.id)}
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h3 className="text-sm font-semibold text-[var(--ink-900)]">{document.title}</h3>
                                                    <span className={`sig-pill ${statusClasses[displayStatus] || 'sig-pill-blue'}`}>
                                                        {statusLabels[displayStatus] || displayStatus}
                                                    </span>
                                                </div>

                                                <div className="mono mt-1 break-all text-xs text-[var(--ink-500)]">
                                                    {document.code || 'Sem codigo'} - {version?.revision || 'Sem revisao'}
                                                </div>

                                                <div className="mt-3 grid gap-x-4 gap-y-2 text-xs text-[var(--ink-500)] sm:grid-cols-2 lg:grid-cols-4">
                                                    <CompactInfo label="Contrato" value={`${document.contract?.code || '-'} - ${document.contract?.name || 'Sem contrato'}`} />
                                                    <CompactInfo label="Obra" value={`${document.obra?.codigo || '-'} - ${document.obra?.nome || 'Sem obra'}`} />
                                                    <CompactInfo label="Disciplina" value={`${document.disciplina?.sigla || '-'} - ${document.disciplina?.nome || 'Sem disciplina'}`} />
                                                    <CompactInfo label="Fase" value={document.phase ? `${document.phase.code} - ${document.phase.name}` : 'Sem fase'} />
                                                </div>
                                            </div>

                                            <ChevronDown size={18} className={`mt-1 shrink-0 text-[var(--ink-500)] transition-transform ${expanded ? 'rotate-180' : ''}`} />
                                        </button>

                                        {Number(document.open_rncs_count || 0) > 0 && (
                                            <div className="px-5 pb-4">
                                                <OpenRncBadge tenant={tenant} document={document} />
                                            </div>
                                        )}

                                        {expanded && (
                                            <div className="border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                                    <CompactInfo label="Tipo de documento" value={documentTypes[document.document_type] || document.document_type} />
                                                    <CompactInfo label="Arquivo" value={fileDisplayName(version) || 'Sem arquivo'} />
                                                    <CompactInfo label="Tamanho" value={version?.size_label || '-'} />
                                                    <CompactInfo label="Status APS" value={derivativeLabels[version?.derivative_status] || version?.derivative_status || '-'} />
                                                </div>

                                                {shouldShowOriginalName(version) && (
                                                    <div className="mt-3 break-all text-xs text-[var(--ink-500)]">
                                                        Original: {version.original_name}
                                                    </div>
                                                )}

                                                <ProjectStatusDetails document={document} inactive={inactive} manuallyInactive={manuallyInactive} />

                                                <div className="mt-4 border-t border-[var(--border)] pt-4">
                                                    <ProjectDocumentActions
                                                        tenant={tenant}
                                                        document={document}
                                                        version={version}
                                                        treeActive={treeActive}
                                                        canAnalyzeProjects={canAnalyzeProjects}
                                                        canDeleteProjects={canDeleteProjects}
                                                        onProcessVersion={processVersion}
                                                        onOpenInactivateModal={openInactivateModal}
                                                        onDeleteDocument={deleteDocument}
                                                    />
                                                </div>
                                            </div>
                                        )}
                                    </article>
                                );
                            })}
                        </div>
                        </>
                    ) : (
                        <div className="p-12 text-center text-sm text-[var(--ink-500)]">
                            {documents.length === 0 ? 'Nenhum projeto enviado ainda.' : 'Nenhum projeto encontrado para os filtros selecionados.'}
                        </div>
                    )}
                </section>
            </section>

            {confirmOpen && (
                <ConfirmProjectSubmitModal
                    title={submissionTitle}
                    fileName={form.data.file?.name}
                    contractLabel={selectedContract ? contractLabel(selectedContract) : 'Contrato nao informado'}
                    obraLabel={selectedObra ? `${selectedObra.codigo} - ${selectedObra.nome}` : 'Obra nao informada'}
                    disciplinaLabel={selectedDisciplina ? `${selectedDisciplina.sigla} - ${selectedDisciplina.nome}` : 'Disciplina nao informada'}
                    projectPhaseLabel={selectedProjectPhaseLabel}
                    documentTypeLabel={selectedDocumentTypeLabel}
                    eap={fullEapPreview}
                    existingDocument={existingDocumentForEap}
                    revision={revisionPreview}
                    capReason={form.data.cap_reason}
                    capDescription={form.data.cap_description}
                    capImpacts={form.data.cap_impacts}
                    capImpactLabels={capImpactLabels}
                    processing={form.processing}
                    onClose={() => setConfirmOpen(false)}
                    onConfirm={confirmSubmit}
                />
            )}

            {inactivateDocument && (
                <InactivateProjectModal
                    document={inactivateDocument}
                    reason={inactivateForm.data.inactive_reason}
                    error={inactivateForm.errors.inactive_reason}
                    processing={inactivateForm.processing}
                    onReasonChange={(value) => inactivateForm.setData('inactive_reason', value)}
                    onClose={closeInactivateModal}
                    onConfirm={submitInactivation}
                />
            )}
        </AuthenticatedLayout>
    );
}

function EapPreview({ fullEap }) {
    return (
        <label>
            <span className="eyebrow mb-1 block">EAP prevista</span>
            <span className="sig-input">
                <input
                    className="mono font-semibold"
                    value={fullEap || ''}
                    readOnly
                    placeholder="Contrato-Obra-Disciplina-Fase-Tipo-Sequencial-Revisao"
                />
            </span>
        </label>
    );
}

function CapFields({
    capReason,
    capDescription,
    capImpacts,
    capImpactLabels,
    errors,
    onReasonChange,
    onDescriptionChange,
    onImpactToggle,
}) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4">
            <div className="flex items-center justify-between gap-2">
                <div>
                    <span className="eyebrow block">CAP</span>
                    <h3 className="mt-1 text-sm font-semibold text-[var(--ink-900)]">Controle e Alteracao de Projetos</h3>
                </div>
                <span className="sig-pill sig-pill-amber">Revisao</span>
            </div>

            <div className="mt-4 grid gap-3">
                <Field label="Motivo da alteracao" error={errors.cap_reason}>
                    <textarea
                        value={capReason}
                        onChange={(event) => onReasonChange(event.target.value)}
                        placeholder="Informe o motivo da alteracao"
                        rows={3}
                        required
                        className="min-h-20 resize-y"
                    />
                </Field>

                <Field label="Descricao da alteracao" error={errors.cap_description}>
                    <textarea
                        value={capDescription}
                        onChange={(event) => onDescriptionChange(event.target.value)}
                        placeholder="Descreva o que foi alterado e quais pontos precisam de atencao"
                        rows={4}
                        required
                        className="min-h-24 resize-y"
                    />
                </Field>

                <div>
                    <span className="eyebrow mb-2 block">Impactos</span>
                    <div className="flex flex-wrap gap-2">
                        {Object.entries(capImpactLabels).map(([value, label]) => {
                            const active = capImpacts.includes(value);

                            return (
                                <button
                                    key={value}
                                    type="button"
                                    className={`sig-pill border ${active ? 'sig-pill-blue border-transparent' : 'border-[var(--border)] bg-white text-[var(--ink-600)]'}`}
                                    onClick={() => onImpactToggle(value)}
                                >
                                    {label}
                                </button>
                            );
                        })}
                    </div>
                    {errors.cap_impacts && <span className="mt-1 block text-xs text-[var(--red)]">{errors.cap_impacts}</span>}
                </div>
            </div>
        </div>
    );
}

function ConfirmProjectSubmitModal({
    title,
    fileName,
    contractLabel,
    obraLabel,
    disciplinaLabel,
    projectPhaseLabel,
    documentTypeLabel,
    eap,
    existingDocument,
    revision,
    capReason,
    capDescription,
    capImpacts,
    capImpactLabels,
    processing,
    onClose,
    onConfirm,
}) {
    return (
        <div
            className="fixed inset-0 z-[120] flex items-stretch justify-center bg-[rgba(11,16,32,0.48)] px-3 py-3 sm:items-center sm:px-4 sm:py-6"
            role="presentation"
            onClick={onClose}
        >
            <section
                className="flex max-h-[calc(100dvh-1.5rem)] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)] sm:max-h-[calc(100dvh-3rem)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="confirm-project-submit-title"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="flex shrink-0 items-start justify-between gap-4 border-b border-[var(--border)] px-4 py-3 sm:px-5 sm:py-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <CheckCircle2 size={15} />
                            <span className="eyebrow">Confirmar submissao</span>
                        </div>
                        <h2 id="confirm-project-submit-title" className="mt-1 text-[17px] font-semibold text-[var(--ink-900)]">
                            Conferir dados do projeto
                        </h2>
                        <p className="mt-1 text-[13px] text-[var(--ink-500)]">
                            {existingDocument ? `Este envio sera registrado como revisao ${revision} do mesmo sequencial.` : `Este envio criara um novo projeto na revisao ${revision}.`}
                        </p>
                    </div>
                    <button type="button" className="sig-btn sig-btn-ghost !min-h-9 !px-2" title="Fechar" onClick={onClose}>
                        <X size={18} />
                    </button>
                </header>

                <div className="grid min-h-0 flex-1 gap-3 overflow-y-auto px-4 py-4 sm:grid-cols-2 sm:px-5 sm:py-5">
                    <ConfirmInfo label="Titulo" value={title} />
                    <ConfirmInfo label="Arquivo" value={fileName} />
                    <ConfirmInfo label="Contrato" value={contractLabel} />
                    <ConfirmInfo label="Obra" value={obraLabel} />
                    <ConfirmInfo label="Disciplina" value={disciplinaLabel} />
                    <ConfirmInfo label="Fase" value={projectPhaseLabel} />
                    <ConfirmInfo label="Tipo" value={documentTypeLabel} />
                    <div className="sm:col-span-2">
                        <ConfirmInfo label="EAP da revisao" value={eap} mono />
                    </div>
                    {existingDocument && (
                        <div className="grid gap-3 sm:col-span-2">
                            <ConfirmInfo label="Numero CAP" value="Gerado automaticamente" />
                            <ConfirmInfo label="Motivo da alteracao" value={capReason} />
                            <ConfirmInfo label="Descricao da alteracao" value={capDescription} />
                            <ConfirmInfo
                                label="Impactos"
                                value={(capImpacts || []).map((impact) => capImpactLabels[impact] || impact).join(', ')}
                            />
                        </div>
                    )}
                </div>

                <footer className="grid shrink-0 gap-2 border-t border-[var(--border)] bg-[var(--surface-muted)] px-4 py-3 sm:flex sm:flex-wrap sm:justify-end sm:px-5 sm:py-4">
                    <button type="button" className="sig-btn sig-btn-secondary w-full sm:w-auto" onClick={onClose} disabled={processing}>
                        Cancelar
                    </button>
                    <button type="button" className="sig-btn sig-btn-primary w-full sm:w-auto" onClick={onConfirm} disabled={processing}>
                        <Send size={15} />
                        Confirmar submissao
                    </button>
                </footer>
            </section>
        </div>
    );
}

function InactivateProjectModal({
    document,
    reason,
    error,
    processing,
    onReasonChange,
    onClose,
    onConfirm,
}) {
    const canConfirm = reason.trim().length > 0 && !processing;

    return (
        <div
            className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.48)] px-4 py-6"
            role="presentation"
            onClick={onClose}
        >
            <section
                className="w-full max-w-xl overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="inactivate-project-title"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <ArchiveX size={15} />
                            <span className="eyebrow">Inativar projeto</span>
                        </div>
                        <h2 id="inactivate-project-title" className="mt-1 text-[17px] font-semibold text-[var(--ink-900)]">
                            Remover projeto da arvore principal
                        </h2>
                        <p className="mt-1 text-[13px] text-[var(--ink-500)]">
                            O registro, as revisoes e os arquivos continuam preservados no historico.
                        </p>
                    </div>
                    <button type="button" className="sig-btn sig-btn-ghost !min-h-9 !px-2" title="Fechar" onClick={onClose}>
                        <X size={18} />
                    </button>
                </header>

                <div className="grid gap-4 px-5 py-5">
                    <ConfirmInfo label="Projeto" value={document.title} />
                    <ConfirmInfo label="EAP" value={document.code} mono />
                    <label>
                        <span className="eyebrow mb-1 block">Motivo da decisao</span>
                        <textarea
                            className="min-h-32 w-full resize-y rounded-lg border border-[var(--border)] bg-white px-3 py-2 text-sm outline-none focus:border-[var(--primary)] focus:ring-2 focus:ring-[rgba(37,99,235,0.16)]"
                            value={reason}
                            onChange={(event) => onReasonChange(event.target.value)}
                            placeholder="Explique por que este projeto sera inativado"
                            autoFocus
                        />
                        {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
                    </label>
                </div>

                <footer className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onClose} disabled={processing}>
                        Cancelar
                    </button>
                    <button type="button" className="sig-btn sig-btn-primary" onClick={onConfirm} disabled={!canConfirm}>
                        <ArchiveX size={15} />
                        Inativar projeto
                    </button>
                </footer>
            </section>
        </div>
    );
}

function ConfirmInfo({ label, value, mono = false }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-white p-3">
            <div className="eyebrow">{label}</div>
            <div className={`mt-1 text-sm font-semibold text-[var(--ink-900)] ${mono ? 'mono break-all' : ''}`}>
                {value || 'Nao informado'}
            </div>
        </div>
    );
}

function Field({ label, error, children }) {
    return (
        <label>
            <span className="eyebrow mb-1 block">{label}</span>
            <span className="sig-input">{children}</span>
            {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
        </label>
    );
}

function FilterSelect({ label, value, onChange, children }) {
    return (
        <label>
            <span className="eyebrow mb-1 flex items-center gap-1">
                <Filter size={12} />
                {label}
            </span>
            <span className="sig-input bg-white">
                <select value={value} onChange={(event) => onChange(event.target.value)}>
                    {children}
                </select>
            </span>
        </label>
    );
}

function CompactInfo({ label, value }) {
    return (
        <div className="min-w-0">
            <div className="eyebrow">{label}</div>
            <div className="mt-1 break-words text-sm font-medium text-[var(--ink-700)]">{value || '-'}</div>
        </div>
    );
}

function ProjectStatusDetails({ document, inactive, manuallyInactive }) {
    return (
        <>
            {inactive && document.inactive_at && (
                <div className="mt-3 text-xs text-[var(--ink-500)]">
                    Inativado por {document.inactive_by?.name || 'usuario'} em {new Date(document.inactive_at).toLocaleDateString('pt-BR')}
                </div>
            )}
            {inactive && !manuallyInactive && (
                <div className="mt-3 text-xs text-[var(--ink-500)]">
                    Inativo na arvore ate a aprovacao.
                </div>
            )}
            {inactive && document.inactive_reason && (
                <div className="mt-1 text-xs text-[var(--ink-500)]">
                    Motivo: {document.inactive_reason}
                </div>
            )}
            {document.reviewed_at && (
                <div className="mt-1 text-xs text-[var(--ink-500)]">
                    {document.reviewer?.name || 'Revisado'} em {new Date(document.reviewed_at).toLocaleDateString('pt-BR')}
                </div>
            )}
        </>
    );
}

function ProjectDocumentActions({
    tenant,
    document,
    version,
    treeActive,
    canAnalyzeProjects,
    canDeleteProjects,
    onProcessVersion,
    onOpenInactivateModal,
    onDeleteDocument,
}) {
    return (
        <div className="flex flex-wrap gap-2">
            {treeActive && version?.aps_urn ? (
                <Link href={viewerWorkspaceUrl(tenant, version, 'view')} className="sig-btn sig-btn-primary sig-btn-sm">
                    <Eye size={13} />
                    Visualizar
                </Link>
            ) : treeActive && isApsWaiting(version) ? (
                <span className="sig-pill bg-white text-[var(--ink-600)]">
                    Processando APS
                </span>
            ) : treeActive ? (
                <button type="button" onClick={() => onProcessVersion(version)} className="sig-btn sig-btn-primary sig-btn-sm">
                    <Eye size={13} />
                    Processar APS
                </button>
            ) : (
                <span className="sig-pill bg-white text-[var(--ink-600)]">
                    Fora da arvore
                </span>
            )}
            {version?.aps_urn && (document.status === 'ativo' || canAnalyzeProjects) && (
                <Link href={viewerWorkspaceUrl(tenant, version, 'comments')} className="sig-btn sig-btn-secondary sig-btn-sm">
                    <MessageSquare size={13} />
                    Comentarios
                </Link>
            )}
            {version?.url && (
                <a href={version.url} download={fileDisplayName(version)} className="sig-btn sig-btn-secondary sig-btn-sm">
                    <Download size={13} />
                    Baixar
                </a>
            )}
            {canDeleteProjects && treeActive && (
                <button
                    type="button"
                    className="sig-btn sig-btn-secondary sig-btn-sm"
                    onClick={() => onOpenInactivateModal(document)}
                >
                    <ArchiveX size={13} />
                    Inativar
                </button>
            )}
            {canDeleteProjects && (
                <ConfirmActionButton
                    title="Excluir projeto"
                    message={`Deseja mesmo excluir ${document.title}? O registro e o arquivo ficarao preservados no historico.`}
                    confirmLabel="Excluir projeto"
                    onConfirm={() => onDeleteDocument(document)}
                >
                    <Trash2 size={13} />
                    Excluir
                </ConfirmActionButton>
            )}
        </div>
    );
}
