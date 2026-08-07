import ConfirmActionButton from '@/Components/ConfirmActionButton';
import ProjectBatchSubmissionModal from '@/Components/ProjectBatchSubmissionModal';
import ProjectIdentity from '@/Components/ProjectIdentity';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { projectEap } from '@/Utils/projectEap';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArchiveX, CheckCircle2, ChevronDown, Download, Eye, FileDown, Files, FileUp, Filter, FolderOpen, MessageSquare, RefreshCw, Search, Send, Trash2, TriangleAlert, UploadCloud, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const PROJECT_SEQUENCE_LENGTH = 3;
const PROJECTS_PER_PAGE = 20;

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

function buildProjectCode(contract, obra, trecho, disciplina, projectPhase, documentType, documentTypeCodes, documentNumber) {
    const documentTypeCode = documentTypeCodes?.[documentType] || documentType;

    return [contract?.code, obra?.codigo, trecho?.codigo, disciplina?.sigla, projectPhase?.code, documentTypeCode, documentNumber]
        .map(normalizeCodePart)
        .filter(Boolean)
        .join('-');
}

function normalizeDocumentNumber(value) {
    const digits = String(value || '').replace(/\D+/g, '').slice(0, PROJECT_SEQUENCE_LENGTH);

    return digits ? digits.padStart(PROJECT_SEQUENCE_LENGTH, '0') : '';
}

function buildCapNumber(documentCode, revision) {
    const parts = String(documentCode || '').split('-').filter(Boolean);

    if (parts.length >= 2) {
        parts[parts.length - 2] = 'CAP';
    }

    return [...parts, revision].filter(Boolean).join('-');
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

function originalFileName(version) {
    return version?.original_name || version?.stored_name || '';
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

function capPdfUrl(tenant, version) {
    return route('tenant.projects.cap.pdf', [tenant.slug, version.id]);
}

function batchCapPdfUrl(tenant, batch) {
    return route('tenant.projects.batches.cap.pdf', [tenant.slug, batch.id]);
}

function documentSubmissionBatches(document) {
    const batches = Array.isArray(document?.submission_batches) ? document.submission_batches : [];
    const currentBatch = document?.latest_version?.submission_batch;
    const uniqueBatches = new Map();

    if (currentBatch?.id) {
        uniqueBatches.set(String(currentBatch.id), currentBatch);
    }

    batches.forEach((batch) => {
        if (batch?.id) uniqueBatches.set(String(batch.id), batch);
    });

    return [...uniqueBatches.values()];
}

function ProjectBatchBadge({ document }) {
    const currentBatch = document?.latest_version?.submission_batch;
    const batch = currentBatch || documentSubmissionBatches(document)[0];

    if (!batch) return null;

    const label = currentBatch ? `Lote ${batch.package_number}` : `Origem: ${batch.package_number}`;

    return (
        <span
            className="sig-pill sig-pill-green inline-flex items-center gap-1"
            title={currentBatch ? 'A versão atual foi submetida neste lote.' : 'Uma versão anterior deste projeto foi submetida neste lote.'}
        >
            <Files size={12} />
            {label}
        </span>
    );
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
    em_revisao: 'sig-pill-amber',
    ativo: 'sig-pill-green',
    inativo: 'sig-pill-red',
    reprovado: 'sig-pill-red',
};

function isRevisionInProgress(document) {
    return Boolean(Number(document?.has_approved_version || 0))
        && ['em_analise', 'em_aprovacao'].includes(document?.status);
}

function projectDisplayStatus(document, manuallyInactive = isManuallyInactiveDocument(document)) {
    if (manuallyInactive) {
        return 'inativo';
    }

    return isRevisionInProgress(document) ? 'em_revisao' : document?.status;
}

function projectStatusLabel(status, statusLabels) {
    return status === 'em_revisao' ? 'Em revisão' : (statusLabels[status] || status);
}

export default function ProjectsIndex({
    tenant,
    contracts,
    obras,
    trechos = [],
    disciplinas,
    documents,
    projectPhases = [],
    documentTypes,
    documentTypeCodes,
    statusLabels,
    capImpactLabels = {},
    allowedExtensions,
    canUploadProjects,
    canUploadProjectBatches,
    canAnalyzeProjects,
    canDeleteProjects,
}) {
    const page = usePage();
    const defaultContract = contracts[0] ?? null;
    const defaultContractId = defaultContract?.id ?? '';
    const defaultObra = obras.find((obra) => String(obra.contract_id) === String(defaultContractId)) ?? null;
    const defaultObraId = defaultObra?.id ?? '';
    const defaultTrecho = trechos.find((trecho) => String(trecho.obra_id) === String(defaultObraId)) ?? null;
    const defaultTrechoId = defaultTrecho?.id ?? '';
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
    const [trechoFilter, setTrechoFilter] = useState('todos');
    const [disciplinaFilter, setDisciplinaFilter] = useState('todos');
    const [statusFilter, setStatusFilter] = useState('todos');
    const [batchFilter, setBatchFilter] = useState('todos');
    const [query, setQuery] = useState('');
    const [currentPage, setCurrentPage] = useState(1);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [submitPanelOpen, setSubmitPanelOpen] = useState(false);
    const [batchPanelOpen, setBatchPanelOpen] = useState(false);
    const [statusDocument, setStatusDocument] = useState(null);
    const [expandedDocumentIds, setExpandedDocumentIds] = useState([]);
    const confirmedSubmitRef = useRef(false);
    const projectsListRef = useRef(null);
    const form = useForm({
        contract_id: defaultContractId,
        obra_id: defaultObraId,
        trecho_id: defaultTrechoId,
        disciplina_id: defaultDisciplinaId,
        project_phase_id: defaultProjectPhaseId,
        title: '',
        document_number: '001',
        code: buildProjectCode(defaultContract, defaultObra, defaultTrecho, defaultDisciplina, defaultProjectPhase, defaultDocumentType, documentTypeCodes, '001'),
        document_type: defaultDocumentType,
        revision: 'Automatica',
        revision_change_summary: '',
        cap_reason: '',
        cap_description: '',
        cap_impacts: [],
        file: null,
    });
    const statusForm = useForm({
        project_status: 'inativo',
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

    const selectedTrecho = useMemo(
        () => trechos.find((trecho) => String(trecho.id) === String(form.data.trecho_id)) ?? null,
        [trechos, form.data.trecho_id],
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
            const legacyCodeWithNumber = buildProjectCode(selectedContract, selectedObra, selectedTrecho, selectedDisciplina, null, form.data.document_type, documentTypeCodes, normalizedSequential);
            const legacyCodeWithoutNumber = buildProjectCode(selectedContract, selectedObra, selectedTrecho, selectedDisciplina, null, form.data.document_type, documentTypeCodes);

            return documents.find((document) => {
                const documentCode = String(document.code || '');

                if (documentCode === String(form.data.code || '')) {
                    return true;
                }

                return !document.project_phase_id
                    && (documentCode === legacyCodeWithNumber || (normalizedSequential === '001' && documentCode === legacyCodeWithoutNumber));
            }) ?? null;
        },
        [documents, form.data.code, form.data.document_type, selectedContract, selectedObra, selectedTrecho, selectedDisciplina, documentTypeCodes, normalizedSequential],
    );
    const isRevision = Boolean(existingDocumentForEap);
    const requiresCap = isRevision && Boolean(Number(existingDocumentForEap?.has_approved_version || 0));
    const requiresCorrectionSummary = isRevision && !requiresCap;
    const revisionPreview = existingDocumentForEap
        ? nextRevisionLabel(existingDocumentForEap.latest_version?.revision)
        : 'R00';
    const fullEapPreview = [form.data.code, revisionPreview].filter(Boolean).join('-');
    const capNumberPreview = buildCapNumber(form.data.code, revisionPreview);
    const submissionTitle = existingDocumentForEap?.title || form.data.title;

    const disciplinasForForm = useMemo(
        () => disciplinas.filter((disciplina) => String(disciplina.contract_id) === String(form.data.contract_id)),
        [disciplinas, form.data.contract_id],
    );

    const obrasForForm = useMemo(
        () => obras.filter((obra) => String(obra.contract_id) === String(form.data.contract_id)),
        [obras, form.data.contract_id],
    );
    const trechosForForm = useMemo(
        () => trechos.filter((trecho) => String(trecho.obra_id) === String(form.data.obra_id)),
        [trechos, form.data.obra_id],
    );
    const canTrySubmit = Boolean(
        canUploadProjects
        && contracts.length > 0
        && obrasForForm.length > 0
        && trechosForForm.length > 0
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

    const trechosForFilter = useMemo(
        () => obraFilter === 'todos'
            ? trechos.filter((trecho) => obrasForFilter.some((obra) => String(obra.id) === String(trecho.obra_id)))
            : trechos.filter((trecho) => String(trecho.obra_id) === String(obraFilter)),
        [trechos, obrasForFilter, obraFilter],
    );

    const disciplinasForFilter = useMemo(
        () => contractFilter === 'todos'
            ? disciplinas
            : disciplinas.filter((disciplina) => String(disciplina.contract_id) === String(contractFilter)),
        [disciplinas, contractFilter],
    );

    const batchesForFilter = useMemo(() => {
        const batches = new Map();

        documents
            .filter((document) => contractFilter === 'todos' || String(document.contract_id) === String(contractFilter))
            .forEach((document) => {
                documentSubmissionBatches(document).forEach((batch) => {
                    batches.set(String(batch.id), batch);
                });
            });

        return [...batches.values()].sort((first, second) => String(second.package_number || '').localeCompare(
            String(first.package_number || ''),
            'pt-BR',
            { numeric: true },
        ));
    }, [documents, contractFilter]);

    const filteredDocuments = useMemo(() => {
        const term = query.trim().toLowerCase();

        return documents.filter((document) => {
            if (contractFilter !== 'todos' && String(document.contract_id) !== String(contractFilter)) {
                return false;
            }

            if (obraFilter !== 'todos' && String(document.obra_id) !== String(obraFilter)) {
                return false;
            }

            if (trechoFilter !== 'todos' && String(document.trecho_id) !== String(trechoFilter)) {
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

            const documentBatches = documentSubmissionBatches(document);

            if (batchFilter === 'com_lote' && documentBatches.length === 0) {
                return false;
            }

            if (batchFilter === 'sem_lote' && documentBatches.length > 0) {
                return false;
            }

            if (batchFilter.startsWith('lote:') && !documentBatches.some((batch) => `lote:${batch.id}` === batchFilter)) {
                return false;
            }

            if (!term) {
                return true;
            }

            return `${document.title} ${projectEap(document)} ${fileDisplayName(document.latest_version)} ${document.latest_version?.original_name || ''} ${document.obra?.nome || ''} ${document.obra?.codigo || ''} ${document.trecho?.nome || ''} ${document.trecho?.codigo || ''} ${document.disciplina?.nome || ''} ${document.phase?.name || ''} ${document.phase?.code || ''} ${documentBatches.map((batch) => batch.package_number).join(' ')}`
                .toLowerCase()
                .includes(term);
        });
    }, [documents, contractFilter, obraFilter, trechoFilter, disciplinaFilter, statusFilter, batchFilter, query]);

    const totalPages = Math.max(1, Math.ceil(filteredDocuments.length / PROJECTS_PER_PAGE));
    const paginatedDocuments = useMemo(() => {
        const start = (currentPage - 1) * PROJECTS_PER_PAGE;

        return filteredDocuments.slice(start, start + PROJECTS_PER_PAGE);
    }, [filteredDocuments, currentPage]);

    useEffect(() => {
        setCurrentPage(1);
    }, [contractFilter, obraFilter, trechoFilter, disciplinaFilter, statusFilter, batchFilter, query]);

    useEffect(() => {
        setCurrentPage((pageNumber) => Math.min(pageNumber, totalPages));
    }, [totalPages]);

    const goToPage = (pageNumber) => {
        const nextPage = Math.min(Math.max(pageNumber, 1), totalPages);

        setCurrentPage(nextPage);
        projectsListRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const updateContract = (contractId) => {
        const nextContract = contracts.find((contract) => String(contract.id) === String(contractId)) ?? null;
        const nextObra = obras.find((obra) => String(obra.contract_id) === String(contractId)) ?? null;
        const nextTrecho = trechos.find((trecho) => String(trecho.obra_id) === String(nextObra?.id)) ?? null;
        const nextDisciplina = disciplinas.find((disciplina) => String(disciplina.contract_id) === String(contractId)) ?? null;

        form.setData({
            ...form.data,
            contract_id: contractId,
            obra_id: nextObra?.id ?? '',
            trecho_id: nextTrecho?.id ?? '',
            disciplina_id: nextDisciplina?.id ?? '',
            code: buildProjectCode(nextContract, nextObra, nextTrecho, nextDisciplina, selectedProjectPhase, form.data.document_type, documentTypeCodes, normalizeDocumentNumber(form.data.document_number)),
        });
    };

    const updateObra = (obraId) => {
        const currentContract = contracts.find((contract) => String(contract.id) === String(form.data.contract_id)) ?? null;
        const nextObra = obras.find((obra) => String(obra.id) === String(obraId)) ?? null;
        const nextTrecho = trechos.find((trecho) => String(trecho.obra_id) === String(obraId)) ?? null;
        const currentDisciplina = disciplinas.find((disciplina) => String(disciplina.id) === String(form.data.disciplina_id)) ?? null;

        form.setData({
            ...form.data,
            obra_id: obraId,
            trecho_id: nextTrecho?.id ?? '',
            code: buildProjectCode(currentContract, nextObra, nextTrecho, currentDisciplina, selectedProjectPhase, form.data.document_type, documentTypeCodes, normalizeDocumentNumber(form.data.document_number)),
        });
    };

    const updateTrecho = (trechoId) => {
        const currentContract = contracts.find((contract) => String(contract.id) === String(form.data.contract_id)) ?? null;
        const currentObra = obras.find((obra) => String(obra.id) === String(form.data.obra_id)) ?? null;
        const nextTrecho = trechos.find((trecho) => String(trecho.id) === String(trechoId)) ?? null;
        const currentDisciplina = disciplinas.find((disciplina) => String(disciplina.id) === String(form.data.disciplina_id)) ?? null;

        form.setData({
            ...form.data,
            trecho_id: trechoId,
            code: buildProjectCode(currentContract, currentObra, nextTrecho, currentDisciplina, selectedProjectPhase, form.data.document_type, documentTypeCodes, normalizeDocumentNumber(form.data.document_number)),
        });
    };

    const updateDisciplina = (disciplinaId) => {
        const currentContract = contracts.find((contract) => String(contract.id) === String(form.data.contract_id)) ?? null;
        const currentObra = obras.find((obra) => String(obra.id) === String(form.data.obra_id)) ?? null;
        const nextDisciplina = disciplinas.find((disciplina) => String(disciplina.id) === String(disciplinaId)) ?? null;

        form.setData({
            ...form.data,
            disciplina_id: disciplinaId,
            code: buildProjectCode(currentContract, currentObra, selectedTrecho, nextDisciplina, selectedProjectPhase, form.data.document_type, documentTypeCodes, normalizeDocumentNumber(form.data.document_number)),
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
            code: buildProjectCode(currentContract, currentObra, selectedTrecho, currentDisciplina, nextProjectPhase, form.data.document_type, documentTypeCodes, normalizeDocumentNumber(form.data.document_number)),
        });
    };

    const updateDocumentType = (documentType) => {
        const currentContract = contracts.find((contract) => String(contract.id) === String(form.data.contract_id)) ?? null;
        const currentObra = obras.find((obra) => String(obra.id) === String(form.data.obra_id)) ?? null;
        const currentDisciplina = disciplinas.find((disciplina) => String(disciplina.id) === String(form.data.disciplina_id)) ?? null;

        form.setData({
            ...form.data,
            document_type: documentType,
            code: buildProjectCode(currentContract, currentObra, selectedTrecho, currentDisciplina, selectedProjectPhase, documentType, documentTypeCodes, normalizeDocumentNumber(form.data.document_number)),
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
            code: buildProjectCode(currentContract, currentObra, selectedTrecho, currentDisciplina, selectedProjectPhase, form.data.document_type, documentTypeCodes, codeDocumentNumber),
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
        setTrechoFilter('todos');
        setDisciplinaFilter('todos');
        setBatchFilter('todos');
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

        if (requiresCorrectionSummary && !form.data.revision_change_summary.trim()) {
            errors.revision_change_summary = 'Descreva as correções realizadas para esta nova revisão.';
        }

        if (requiresCap && !form.data.cap_reason.trim()) {
            errors.cap_reason = 'Informe o motivo da alteracao desta revisao.';
        }

        if (requiresCap && !form.data.cap_description.trim()) {
            errors.cap_description = 'Descreva o que foi alterado nesta revisao.';
        }

        if (requiresCap && !form.data.cap_impacts.length) {
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

    const openStatusModal = (document) => {
        statusForm.clearErrors();
        statusForm.setData({
            project_status: isManuallyInactiveDocument(document) ? 'ativo' : 'inativo',
            inactive_reason: '',
        });
        setStatusDocument(document);
    };

    const closeStatusModal = () => {
        if (statusForm.processing) {
            return;
        }

        setStatusDocument(null);
        statusForm.reset();
        statusForm.clearErrors();
    };

    const submitStatusChange = () => {
        if (!statusDocument) {
            return;
        }

        statusForm.patch(route('tenant.projects.status.update', [tenant.slug, statusDocument.id]), {
            preserveScroll: true,
            onSuccess: closeStatusModal,
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
                        className="fixed inset-0 z-[110] grid place-items-center overflow-y-auto bg-[rgba(11,16,32,0.42)] p-4 sm:p-6"
                        role="presentation"
                        onMouseDown={() => setSubmitPanelOpen(false)}
                    >
                        <form
                            className="my-auto max-h-[calc(100vh-2rem)] w-full max-w-[760px] overflow-y-auto rounded-lg border border-[var(--border)] bg-white p-5 shadow-[0_24px_80px_rgba(11,16,32,0.24)] sm:max-h-[calc(100vh-3rem)] sm:p-6"
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
                                Envie arquivos tecnicos por contrato, obra, trecho, disciplina e revisao. Todo envio passa por analise e aprovacao antes de aparecer na arvore principal.
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

                    <div className="mt-5 grid gap-3 sm:grid-cols-2">
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

                        <Field label="Trecho" error={form.errors.trecho_id}>
                            <select value={form.data.trecho_id} onChange={(event) => updateTrecho(event.target.value)} required>
                                <option value="">Selecione o trecho</option>
                                {trechosForForm.map((trecho) => (
                                    <option key={trecho.id} value={trecho.id}>
                                        {trecho.codigo} - {trecho.nome}
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

                        <Field label="Tipo de documento" error={form.errors.document_type}>
                            <select value={form.data.document_type} onChange={(event) => updateDocumentType(event.target.value)} required>
                                {Object.entries(documentTypes).map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </select>
                        </Field>

                        <Field
                            label="Titulo"
                            error={form.errors.title}
                            hint={isRevision ? 'Revisões mantêm o título do projeto anterior.' : null}
                        >
                            <input
                                value={submissionTitle}
                                onChange={(event) => !isRevision && form.setData('title', event.target.value)}
                                placeholder="Ex: Projeto estrutural - Bloco A"
                                readOnly={isRevision}
                                required={!isRevision}
                            />
                        </Field>

                        <div className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
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

                        <div className="sm:col-span-2">
                            <EapPreview fullEap={fullEapPreview} />
                        </div>

                        {requiresCap && (
                            <div className="sm:col-span-2">
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
                            </div>
                        )}

                        {requiresCorrectionSummary && (
                            <div className="sm:col-span-2">
                                <RevisionCorrectionFields
                                    value={form.data.revision_change_summary}
                                    error={form.errors.revision_change_summary}
                                    onChange={(value) => form.setData('revision_change_summary', value)}
                                />
                            </div>
                        )}

                        <div className="sm:col-span-2">
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

                    <div className="mt-5 flex justify-end">
                        <button
                            className="sig-btn sig-btn-primary"
                            disabled={!canTrySubmit}
                        >
                            <Send size={15} />
                            Revisar e confirmar
                        </button>
                    </div>
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

                <section ref={projectsListRef} className="projects-list-card sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <FileUp size={14} />
                                <span className="eyebrow">Projetos submetidos</span>
                            </div>
                            <h2 className="mt-1 text-[15px] font-semibold">{filteredDocuments.length} de {documents.length} documentos</h2>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {canUploadProjectBatches && (
                                <button
                                    type="button"
                                    className="sig-btn sig-btn-success sig-btn-sm"
                                    onClick={() => setBatchPanelOpen(true)}
                                >
                                    <Files size={13} />
                                    Submeter em lote
                                </button>
                            )}
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

                    <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 lg:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7">
                        <FilterSelect label="Contrato" value={contractFilter} onChange={updateContractFilter}>
                            <option value="todos">Todos os contratos</option>
                            {contracts.map((contract) => (
                                <option key={contract.id} value={contract.id}>{contractLabel(contract)}</option>
                            ))}
                        </FilterSelect>

                        <FilterSelect label="Obra" value={obraFilter} onChange={(value) => { setObraFilter(value); setTrechoFilter('todos'); }}>
                            <option value="todos">Todas as obras</option>
                            {obrasForFilter.map((obra) => (
                                <option key={obra.id} value={obra.id}>{obra.codigo} - {obra.nome}</option>
                            ))}
                        </FilterSelect>

                        <FilterSelect label="Trecho" value={trechoFilter} onChange={setTrechoFilter}>
                            <option value="todos">Todos os trechos</option>
                            {trechosForFilter.map((trecho) => (
                                <option key={trecho.id} value={trecho.id}>{trecho.codigo} - {trecho.nome}</option>
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
                            <option value="ativo">Disponiveis na arvore</option>
                            <option value="inativo">Fora da arvore</option>
                        </FilterSelect>

                        <FilterSelect label="Lote" value={batchFilter} onChange={setBatchFilter}>
                            <option value="todos">Todos os envios</option>
                            <option value="com_lote">Todos os lotes</option>
                            <option value="sem_lote">Somente avulsos</option>
                            {batchesForFilter.map((batch) => (
                                <option key={batch.id} value={`lote:${batch.id}`}>{batch.package_number}</option>
                            ))}
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
                                {paginatedDocuments.map((document) => {
                                    const version = document.latest_version;
                                    const treeActive = isTreeActiveDocument(document);
                                    const inactive = isInactiveDocument(document);
                                    const manuallyInactive = isManuallyInactiveDocument(document);
                                    const displayStatus = projectDisplayStatus(document, manuallyInactive);

                                    return (
                                        <tr key={document.id}>
                                            <td>
                                                <ProjectIdentity
                                                    eap={projectEap(document, version)}
                                                    fileName={originalFileName(version)}
                                                    title={document.title}
                                                />
                                                 {documentSubmissionBatches(document).length > 0 && (
                                                     <div className="mt-2">
                                                         <ProjectBatchBadge document={document} />
                                                     </div>
                                                 )}
                                                 <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                     Fase: {document.phase ? `${document.phase.code} - ${document.phase.name}` : 'Sem fase'}
                                                 </div>
                                                 <div className="mt-1 text-xs text-[var(--ink-500)]">{documentTypes[document.document_type] || document.document_type}</div>
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
                                                    {projectStatusLabel(displayStatus, statusLabels)}
                                                </span>
                                                {inactive && document.inactive_at && (
                                                    <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                        Indisponibilizado por {document.inactive_by?.name || 'usuario'} em {new Date(document.inactive_at).toLocaleDateString('pt-BR')}
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
                                                    {document.latest_cap_version?.cap_number && (
                                                        <a
                                                            href={capPdfUrl(tenant, document.latest_cap_version)}
                                                            download={`${document.latest_cap_version.cap_number}.pdf`}
                                                            className="sig-btn sig-btn-secondary sig-btn-sm"
                                                        >
                                                            <FileDown size={13} />
                                                            Baixar CAP
                                                        </a>
                                                    )}
                                                    {version?.submission_batch?.cap_number && (
                                                        <a href={batchCapPdfUrl(tenant, version.submission_batch)} target="_blank" rel="noreferrer" className="sig-btn sig-btn-secondary sig-btn-sm">
                                                            <FileDown size={13} />
                                                            CAP do pacote
                                                        </a>
                                                    )}
                                                    {Boolean(document.can_manage_status) && (treeActive || manuallyInactive) && (
                                                        <button
                                                            type="button"
                                                            className="sig-btn sig-btn-secondary sig-btn-sm"
                                                            onClick={() => openStatusModal(document)}
                                                        >
                                                            <RefreshCw size={13} />
                                                            Alterar status
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
                            {paginatedDocuments.map((document) => {
                                const version = document.latest_version;
                                const treeActive = isTreeActiveDocument(document);
                                const inactive = isInactiveDocument(document);
                                const manuallyInactive = isManuallyInactiveDocument(document);
                                const displayStatus = projectDisplayStatus(document, manuallyInactive);
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
                                                    <span className={`sig-pill ${statusClasses[displayStatus] || 'sig-pill-blue'}`}>
                                                        {projectStatusLabel(displayStatus, statusLabels)}
                                                    </span>
                                                    <ProjectBatchBadge document={document} />
                                                </div>

                                                <ProjectIdentity
                                                    className="mt-2"
                                                    eap={projectEap(document, version)}
                                                    fileName={originalFileName(version)}
                                                    title={document.title}
                                                />

                                                <div className="mt-3 grid gap-x-4 gap-y-2 text-xs text-[var(--ink-500)] sm:grid-cols-2 lg:grid-cols-4">
                                                    <CompactInfo label="Contrato" value={`${document.contract?.code || '-'} - ${document.contract?.name || 'Sem contrato'}`} />
                                                    <CompactInfo label="Obra" value={`${document.obra?.codigo || '-'} - ${document.obra?.nome || 'Sem obra'}`} />
                                                    <CompactInfo label="Trecho" value={document.trecho ? `${document.trecho.codigo} - ${document.trecho.nome}` : 'Projeto legado'} />
                                                    <CompactInfo label="Disciplina" value={`${document.disciplina?.sigla || '-'} - ${document.disciplina?.nome || 'Sem disciplina'}`} />
                                                    <CompactInfo label="Fase" value={document.phase ? `${document.phase.code} - ${document.phase.name}` : 'Sem fase'} />
                                                </div>
                                            </div>

                                            <ChevronDown size={18} className={`mt-1 shrink-0 text-[var(--ink-500)] transition-transform ${expanded ? 'rotate-180' : ''}`} />
                                        </button>

                                        {(Number(document.open_rncs_count || 0) > 0 || document.latest_cap_version?.cap_number || version?.submission_batch?.cap_number) && (
                                            <div className="flex flex-wrap items-center justify-end gap-2 px-5 pb-4">
                                                <OpenRncBadge tenant={tenant} document={document} />
                                                {document.latest_cap_version?.cap_number && (
                                                    <a
                                                        href={capPdfUrl(tenant, document.latest_cap_version)}
                                                        download={`${document.latest_cap_version.cap_number}.pdf`}
                                                        className="sig-btn sig-btn-secondary sig-btn-sm"
                                                    >
                                                        <FileDown size={13} />
                                                        Baixar CAP
                                                    </a>
                                                )}
                                                {version?.submission_batch?.cap_number && (
                                                    <a href={batchCapPdfUrl(tenant, version.submission_batch)} target="_blank" rel="noreferrer" className="sig-btn sig-btn-secondary sig-btn-sm">
                                                        <FileDown size={13} />
                                                        CAP do pacote
                                                    </a>
                                                )}
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
                                                        onOpenStatusModal={openStatusModal}
                                                        onDeleteDocument={deleteDocument}
                                                    />
                                                </div>
                                            </div>
                                        )}
                                    </article>
                                );
                            })}
                        </div>
                        <ProjectsPagination
                            currentPage={currentPage}
                            totalPages={totalPages}
                            totalItems={filteredDocuments.length}
                            pageSize={PROJECTS_PER_PAGE}
                            onPageChange={goToPage}
                        />
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
                    trechoLabel={selectedTrecho ? `${selectedTrecho.codigo} - ${selectedTrecho.nome}` : 'Trecho nao informado'}
                    disciplinaLabel={selectedDisciplina ? `${selectedDisciplina.sigla} - ${selectedDisciplina.nome}` : 'Disciplina nao informada'}
                    projectPhaseLabel={selectedProjectPhaseLabel}
                    documentTypeLabel={selectedDocumentTypeLabel}
                    eap={fullEapPreview}
                    existingDocument={existingDocumentForEap}
                    requiresCap={requiresCap}
                    revision={revisionPreview}
                    revisionChangeSummary={form.data.revision_change_summary}
                    capReason={form.data.cap_reason}
                    capDescription={form.data.cap_description}
                    capImpacts={form.data.cap_impacts}
                    capImpactLabels={capImpactLabels}
                    capNumber={capNumberPreview}
                    processing={form.processing}
                    onClose={() => setConfirmOpen(false)}
                    onConfirm={confirmSubmit}
                />
            )}

            {batchPanelOpen && (
                <ProjectBatchSubmissionModal
                    tenant={tenant}
                    contracts={contracts}
                    obras={obras}
                    trechos={trechos}
                    disciplinas={disciplinas}
                    documents={documents}
                    projectPhases={projectPhases}
                    documentTypes={documentTypes}
                    documentTypeCodes={documentTypeCodes}
                    allowedExtensions={allowedExtensions}
                    capImpactLabels={capImpactLabels}
                    onClose={() => setBatchPanelOpen(false)}
                />
            )}

            {statusDocument && (
                <ProjectStatusModal
                    document={statusDocument}
                    targetStatus={statusForm.data.project_status}
                    reason={statusForm.data.inactive_reason}
                    error={statusForm.errors.inactive_reason}
                    processing={statusForm.processing}
                    onReasonChange={(value) => statusForm.setData('inactive_reason', value)}
                    onClose={closeStatusModal}
                    onConfirm={submitStatusChange}
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
                    placeholder="Contrato-Obra-Trecho-Disciplina-Fase-Tipo-Sequencial-Revisao"
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

function RevisionCorrectionFields({ value, error, onChange }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4">
            <div>
                <span className="eyebrow block">Nova revisão</span>
                <h3 className="mt-1 text-sm font-semibold text-[var(--ink-900)]">Correções realizadas</h3>
                <p className="mt-1 text-xs leading-5 text-[var(--ink-500)]">
                    Esta revisão ainda não possui uma versão aprovada e, por isso, não exige CAP.
                </p>
            </div>
            <div className="mt-4">
                <Field label="Resposta à reprovação" error={error}>
                    <textarea
                        value={value}
                        onChange={(event) => onChange(event.target.value)}
                        placeholder="Descreva as correções realizadas antes do novo envio"
                        rows={4}
                        required
                        className="min-h-24 resize-y"
                    />
                </Field>
            </div>
        </div>
    );
}

function ConfirmProjectSubmitModal({
    title,
    fileName,
    contractLabel,
    obraLabel,
    trechoLabel,
    disciplinaLabel,
    projectPhaseLabel,
    documentTypeLabel,
    eap,
    existingDocument,
    requiresCap,
    revision,
    revisionChangeSummary,
    capReason,
    capDescription,
    capImpacts,
    capImpactLabels,
    capNumber,
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
                className="flex min-w-0 max-h-[calc(100dvh-1.5rem)] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)] sm:max-h-[calc(100dvh-3rem)]"
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
                        <p className="mt-1 break-words text-[13px] text-[var(--ink-500)] [overflow-wrap:anywhere]">
                            {existingDocument ? `Este envio sera registrado como revisao ${revision} do mesmo sequencial.` : `Este envio criara um novo projeto na revisao ${revision}.`}
                        </p>
                    </div>
                    <button type="button" className="sig-btn sig-btn-ghost !min-h-9 !px-2" title="Fechar" onClick={onClose}>
                        <X size={18} />
                    </button>
                </header>

                <div className="grid min-h-0 min-w-0 flex-1 gap-3 overflow-x-hidden overflow-y-auto px-4 py-4 sm:grid-cols-2 sm:px-5 sm:py-5">
                    <div className="sm:col-span-2 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4">
                        <ProjectIdentity eap={eap} fileName={fileName} title={title} eapClassName="text-base" />
                    </div>
                    <ConfirmInfo label="Titulo" value={title} />
                    <ConfirmInfo label="Contrato" value={contractLabel} />
                    <ConfirmInfo label="Obra" value={obraLabel} />
                    <ConfirmInfo label="Trecho" value={trechoLabel} />
                    <ConfirmInfo label="Disciplina" value={disciplinaLabel} />
                    <ConfirmInfo label="Fase" value={projectPhaseLabel} />
                    <ConfirmInfo label="Tipo" value={documentTypeLabel} />
                    {requiresCap && (
                        <div className="grid gap-3 sm:col-span-2">
                            <ConfirmInfo label="Número CAP" value={capNumber} mono />
                            <ConfirmInfo label="Motivo da alteracao" value={capReason} />
                            <ConfirmInfo label="Descricao da alteracao" value={capDescription} />
                            <ConfirmInfo
                                label="Impactos"
                                value={(capImpacts || []).map((impact) => capImpactLabels[impact] || impact).join(', ')}
                            />
                        </div>
                    )}
                    {existingDocument && !requiresCap && (
                        <div className="sm:col-span-2">
                            <ConfirmInfo label="Correções realizadas" value={revisionChangeSummary} />
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

function ProjectStatusModal({
    document,
    targetStatus,
    reason,
    error,
    processing,
    onReasonChange,
    onClose,
    onConfirm,
}) {
    const canConfirm = reason.trim().length > 0 && !processing;
    const activating = targetStatus === 'ativo';

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
                aria-labelledby="project-status-title"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            {activating ? <RefreshCw size={15} /> : <ArchiveX size={15} />}
                            <span className="eyebrow">Alterar status</span>
                        </div>
                        <h2 id="project-status-title" className="mt-1 text-[17px] font-semibold text-[var(--ink-900)]">
                            {activating ? 'Reativar projeto na arvore' : 'Tornar projeto indisponivel'}
                        </h2>
                        <p className="mt-1 text-[13px] text-[var(--ink-500)]">
                            {activating
                                ? 'A ultima revisao aprovada voltara a ficar disponivel para os usuarios do contrato.'
                                : 'O projeto deixara de ser visualizado na arvore imediatamente. Arquivos e revisoes serao preservados.'}
                        </p>
                    </div>
                    <button type="button" className="sig-btn sig-btn-ghost !min-h-9 !px-2" title="Fechar" onClick={onClose}>
                        <X size={18} />
                    </button>
                </header>

                <div className="grid gap-4 px-5 py-5">
                    <ProjectIdentity
                        eap={projectEap(document, document.latest_version)}
                        fileName={originalFileName(document.latest_version)}
                        title={document.title}
                    />
                    <label>
                        <span className="eyebrow mb-1 block">Motivo da alteracao</span>
                        <textarea
                            className="min-h-32 w-full resize-y rounded-lg border border-[var(--border)] bg-white px-3 py-2 text-sm outline-none focus:border-[var(--primary)] focus:ring-2 focus:ring-[rgba(37,99,235,0.16)]"
                            value={reason}
                            onChange={(event) => onReasonChange(event.target.value)}
                            placeholder={activating
                                ? 'Informe por que o projeto pode voltar a ficar disponivel'
                                : 'Descreva o problema que exige a indisponibilidade imediata'}
                            autoFocus
                        />
                        {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
                    </label>
                </div>

                <footer className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onClose} disabled={processing}>
                        Cancelar
                    </button>
                    <button
                        type="button"
                        className={`sig-btn ${activating ? 'sig-btn-success' : 'sig-btn-primary'}`}
                        onClick={onConfirm}
                        disabled={!canConfirm}
                    >
                        {activating ? <RefreshCw size={15} /> : <ArchiveX size={15} />}
                        {activating ? 'Reativar projeto' : 'Tornar indisponivel'}
                    </button>
                </footer>
            </section>
        </div>
    );
}

function ConfirmInfo({ label, value, mono = false }) {
    return (
        <div className="min-w-0 max-w-full rounded-lg border border-[var(--border)] bg-white p-3">
            <div className="eyebrow">{label}</div>
            <div className={`mt-1 min-w-0 max-w-full whitespace-pre-wrap break-words text-sm font-semibold text-[var(--ink-900)] [overflow-wrap:anywhere] ${mono ? 'mono break-all' : ''}`}>
                {value || 'Nao informado'}
            </div>
        </div>
    );
}

function Field({ label, error, hint = null, children }) {
    return (
        <label>
            <span className="eyebrow mb-1 block">{label}</span>
            <span className="sig-input">{children}</span>
            {hint && <span className="mt-1 block text-xs text-[var(--ink-500)]">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
        </label>
    );
}

function ProjectsPagination({ currentPage, totalPages, totalItems, pageSize, onPageChange }) {
    const endPage = Math.min(totalPages, Math.max(5, currentPage + 2));
    const startPage = Math.max(1, endPage - 4);
    const visiblePages = Array.from(
        { length: Math.min(5, totalPages - startPage + 1) },
        (_, index) => startPage + index,
    );
    const from = totalItems ? ((currentPage - 1) * pageSize) + 1 : 0;
    const to = Math.min(currentPage * pageSize, totalItems);

    return (
        <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border)] px-5 py-4">
            <div className="text-sm text-[var(--ink-500)]">
                Exibindo {from} a {to} de {totalItems} projeto(s).
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    className="sig-btn sig-btn-secondary sig-btn-sm"
                    disabled={currentPage === 1}
                    onClick={() => onPageChange(currentPage - 1)}
                >
                    Anterior
                </button>
                {visiblePages.map((pageNumber) => (
                    <button
                        key={pageNumber}
                        type="button"
                        className={`sig-btn sig-btn-sm !min-w-8 !px-2 ${pageNumber === currentPage ? 'sig-btn-primary' : 'sig-btn-secondary'}`}
                        aria-current={pageNumber === currentPage ? 'page' : undefined}
                        disabled={pageNumber === currentPage}
                        onClick={() => onPageChange(pageNumber)}
                    >
                        {pageNumber}
                    </button>
                ))}
                <button
                    type="button"
                    className="sig-btn sig-btn-primary sig-btn-sm"
                    disabled={currentPage === totalPages}
                    onClick={() => onPageChange(currentPage + 1)}
                >
                    Próxima
                </button>
            </div>
        </footer>
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
                    Indisponibilizado por {document.inactive_by?.name || 'usuario'} em {new Date(document.inactive_at).toLocaleDateString('pt-BR')}
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
    onOpenStatusModal,
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
            {Boolean(document.can_manage_status) && (treeActive || isManuallyInactiveDocument(document)) && (
                <button
                    type="button"
                    className="sig-btn sig-btn-secondary sig-btn-sm"
                    onClick={() => onOpenStatusModal(document)}
                >
                    <RefreshCw size={13} />
                    Alterar status
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
