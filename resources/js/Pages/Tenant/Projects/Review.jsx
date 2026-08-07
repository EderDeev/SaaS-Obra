import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ProjectCapModal from '@/Components/ProjectCapModal';
import ProjectIdentity from '@/Components/ProjectIdentity';
import { projectEap } from '@/Utils/projectEap';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ChevronDown, Download, Eye, FileText, Files, FileSearch, Filter, LoaderCircle, Play, Search, Send, X, XCircle } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const statusClasses = {
    em_analise: 'sig-pill-blue',
    em_aprovacao: 'sig-pill-amber',
    em_revisao: 'sig-pill-amber',
    ativo: 'sig-pill-green',
    reprovado: 'sig-pill-red',
};

function projectDisplayStatus(document) {
    return Boolean(Number(document?.has_approved_version || 0))
        && ['em_analise', 'em_aprovacao'].includes(document?.status)
        ? 'em_revisao'
        : document?.status;
}

function projectStatusLabel(document, statusLabels) {
    const status = projectDisplayStatus(document);

    return status === 'em_revisao' ? 'Em revisão' : (statusLabels[status] || status);
}

const derivativeLabels = {
    not_submitted: 'Aguardando APS',
    queued: 'Na fila APS',
    processing: 'Processando',
    ready: 'Pronto para viewer',
    failed: 'Erro no APS',
};

function contractLabel(contract) {
    return `${contract.code} - ${contract.name}`;
}

function formatDateTime(value) {
    if (!value) {
        return 'Data nao registrada';
    }

    return new Date(value).toLocaleString('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
}

function fileDisplayName(version) {
    return version?.stored_name || version?.original_name || '';
}

function originalFileName(version) {
    return version?.original_name || version?.stored_name || '';
}

function isApsWaiting(version) {
    return ['queued', 'processing'].includes(version?.derivative_status);
}

function isFileRemoving(version) {
    return version?.derivative_status === 'removing';
}

function noteKey(document) {
    return `${document.id}:${document.status}`;
}

export default function ProjectReview({ tenant, contracts, documents, batches = [], statusLabels, capImpactLabels = {}, stats }) {
    const [contractFilter, setContractFilter] = useState('todos');
    const [statusFilter, setStatusFilter] = useState('todos');
    const [query, setQuery] = useState('');
    const [notes, setNotes] = useState({});
    const [analysisDocument, setAnalysisDocument] = useState(null);
    const [capDocument, setCapDocument] = useState(null);
    const [expandedDocumentIds, setExpandedDocumentIds] = useState([]);
    const [rejectionDocument, setRejectionDocument] = useState(null);
    const [rejectionReason, setRejectionReason] = useState('');
    const [rejectionError, setRejectionError] = useState('');
    const [rejecting, setRejecting] = useState(false);
    const [batchNotes, setBatchNotes] = useState({});
    const [rejectionBatch, setRejectionBatch] = useState(null);
    const [batchRejectionReason, setBatchRejectionReason] = useState('');
    const [batchRejectionError, setBatchRejectionError] = useState('');

    const apsPendingVersionIds = useMemo(() => [
        ...documents.map((document) => document.latest_version).filter(isApsWaiting).map((version) => version.id),
        ...batches.flatMap((batch) => (batch.versions || []).filter(isApsWaiting).map((version) => version.id)),
    ].filter((id, index, ids) => id && ids.indexOf(id) === index), [documents, batches]);
    const apsPendingVersionKey = apsPendingVersionIds.join(',');

    useEffect(() => {
        if (!apsPendingVersionKey) return undefined;

        let active = true;
        let polling = false;

        const syncApsStatuses = async () => {
            if (polling) return;
            polling = true;

            await Promise.allSettled(apsPendingVersionIds.map((versionId) => fetch(
                route('tenant.projects.aps-status', [tenant.slug, versionId]),
                {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            )));

            if (active) {
                router.reload({
                    only: ['documents', 'batches'],
                    preserveScroll: true,
                    preserveState: true,
                });
            }

            polling = false;
        };

        const interval = window.setInterval(syncApsStatuses, 8000);

        return () => {
            active = false;
            window.clearInterval(interval);
        };
    }, [apsPendingVersionKey, tenant.slug]);

    const filteredDocuments = useMemo(() => {
        const term = query.trim().toLowerCase();

        return documents.filter((document) => {
            if (contractFilter !== 'todos' && String(document.contract_id) !== String(contractFilter)) {
                return false;
            }

            if (statusFilter !== 'todos' && document.status !== statusFilter) {
                return false;
            }

            if (!term) {
                return true;
            }

            return `${document.title} ${projectEap(document)} ${fileDisplayName(document.latest_version)} ${document.latest_version?.original_name || ''} ${document.contract?.code || ''} ${document.obra?.nome || ''} ${document.disciplina?.nome || ''} ${document.phase?.name || ''} ${document.phase?.code || ''}`
                .toLowerCase()
                .includes(term);
        });
    }, [documents, contractFilter, statusFilter, query]);

    const filteredBatches = useMemo(() => {
        const term = query.trim().toLowerCase();

        return batches.filter((batch) => {
            if (contractFilter !== 'todos' && String(batch.contract_id) !== String(contractFilter)) return false;
            if (statusFilter !== 'todos' && batch.status !== statusFilter) return false;
            if (!term) return true;

            return `${batch.package_number} ${batch.cap_number} ${batch.title} ${batch.contract?.code || ''} ${(batch.versions || []).map((version) => `${version.document?.title || ''} ${projectEap(version.document, version)}`).join(' ')}`
                .toLowerCase().includes(term);
        });
    }, [batches, contractFilter, statusFilter, query]);

    const reviewDocument = (document, action) => {
        const key = noteKey(document);

        router.patch(route('tenant.projects.review.update', [tenant.slug, document.id]), {
            action,
            review_notes: notes[key] || '',
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setNotes((current) => {
                    const next = { ...current };
                    delete next[key];

                    return next;
                });
            },
        });
    };

    const openRejectionModal = (document) => {
        setRejectionDocument(document);
        setRejectionReason(notes[noteKey(document)] || '');
        setRejectionError('');
    };

    const closeRejectionModal = () => {
        if (rejecting) return;

        setRejectionDocument(null);
        setRejectionReason('');
        setRejectionError('');
    };

    const submitRejection = () => {
        const reason = rejectionReason.trim();

        if (!rejectionDocument || !reason) {
            setRejectionError('Informe o motivo da reprovação.');
            return;
        }

        const key = noteKey(rejectionDocument);
        setRejectionError('');

        router.patch(route('tenant.projects.review.update', [tenant.slug, rejectionDocument.id]), {
            action: 'reprovar',
            review_notes: reason,
        }, {
            preserveScroll: true,
            onStart: () => setRejecting(true),
            onSuccess: () => {
                setNotes((current) => {
                    const next = { ...current };
                    delete next[key];

                    return next;
                });
                setRejectionDocument(null);
                setRejectionReason('');
            },
            onError: (errors) => setRejectionError(errors.review_notes || 'Não foi possível reprovar o projeto.'),
            onFinish: () => setRejecting(false),
        });
    };

    const toggleDocumentDetails = (documentId) => {
        setExpandedDocumentIds((currentIds) => currentIds.includes(documentId)
            ? currentIds.filter((currentId) => currentId !== documentId)
            : [...currentIds, documentId]);
    };

    const reviewBatch = (batch, action, reason = null) => {
        router.patch(route('tenant.projects.batches.review.update', [tenant.slug, batch.id]), {
            action,
            review_notes: reason ?? batchNotes[batch.id] ?? '',
        }, {
            preserveScroll: true,
            onStart: () => setRejecting(true),
            onSuccess: () => {
                setBatchNotes((current) => ({ ...current, [batch.id]: '' }));
                setRejectionBatch(null);
                setBatchRejectionReason('');
            },
            onError: (errors) => setBatchRejectionError(errors.review_notes || 'Não foi possível atualizar o pacote.'),
            onFinish: () => setRejecting(false),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Analisar projeto" />

            <section className="sig-content grid gap-5">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <FileSearch size={15} />
                            <span className="eyebrow">Projetos</span>
                        </div>
                        <h1 className="mt-1 text-xl font-semibold text-[var(--ink-900)]">Analisar projeto</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Verifique os projetos submetidos e aprove a entrada na arvore principal somente depois da analise.
                        </p>
                    </div>

                    <Link href={route('tenant.projects.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                        <Send size={15} />
                        Submeter projeto
                    </Link>
                </header>

                <div className="grid gap-3 md:grid-cols-4">
                    <StatCard label="Em analise" value={stats.pending} tone="blue" />
                    <StatCard label="Em aprovacao" value={stats.approval} tone="amber" />
                    <StatCard label="Aprovados" value={stats.approved} tone="green" />
                    <StatCard label="Reprovados" value={stats.rejected} tone="red" />
                </div>

                <section className="projects-module-card sig-card overflow-hidden">
                    <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 lg:grid-cols-3">
                        <FilterSelect label="Contrato" value={contractFilter} onChange={setContractFilter}>
                            <option value="todos">Todos os contratos</option>
                            {contracts.map((contract) => (
                                <option key={contract.id} value={contract.id}>{contractLabel(contract)}</option>
                            ))}
                        </FilterSelect>

                        <FilterSelect label="Status" value={statusFilter} onChange={setStatusFilter}>
                            <option value="todos">Todos os status</option>
                            <option value="em_analise">Em analise</option>
                            <option value="em_aprovacao">Em aprovacao</option>
                            <option value="ativo">Aprovado</option>
                            <option value="reprovado">Reprovado</option>
                        </FilterSelect>

                        <label>
                            <span className="eyebrow mb-1 flex items-center gap-1">
                                <Search size={12} />
                                Busca
                            </span>
                            <span className="sig-input bg-white">
                                <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar projeto" />
                            </span>
                        </label>
                    </div>

                    {filteredBatches.length > 0 && (
                        <div className="grid gap-3 border-b border-[var(--border)] p-4 sm:p-5">
                            {filteredBatches.map((batch) => (
                                <BatchReviewCard
                                    key={batch.id}
                                    tenant={tenant}
                                    batch={batch}
                                    statusLabels={statusLabels}
                                    note={batchNotes[batch.id] || ''}
                                    onNoteChange={(value) => setBatchNotes((current) => ({ ...current, [batch.id]: value }))}
                                    onApprove={() => reviewBatch(batch, 'aprovar')}
                                    onReject={() => {
                                        setRejectionBatch(batch);
                                        setBatchRejectionReason(batchNotes[batch.id] || '');
                                        setBatchRejectionError('');
                                    }}
                                />
                            ))}
                        </div>
                    )}

                    {filteredDocuments.length > 0 ? (
                        <>
                        <div className="projects-wide-only overflow-x-auto">
                            <table className="sig-table min-w-[1260px]">
                                <thead>
                                    <tr>
                                        <th>Documento</th>
                                        <th>Revisao</th>
                                        <th>Contrato</th>
                                        <th>Obra</th>
                                        <th>Disciplina</th>
                                        <th>Submetido por</th>
                                        <th>Status</th>
                                        <th>Arquivo</th>
                                        <th>Analise</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredDocuments.map((document) => {
                                        const version = document.latest_version;
                                        const actionable = ['em_analise', 'em_aprovacao'].includes(document.status);
                                        const isApprovalStep = document.status === 'em_aprovacao';
                                        const displayStatus = projectDisplayStatus(document);
                                        const positiveLabel = isApprovalStep ? 'Aprovar para arvore' : 'Enviar para aprovacao';
                                        const placeholder = isApprovalStep ? 'Observacao da aprovacao' : 'Observacao da analise';
                                        const currentNoteKey = noteKey(document);

                                        return (
                                            <tr key={document.id}>
                                                <td>
                                                    <ProjectIdentity
                                                        eap={projectEap(document, version)}
                                                        fileName={originalFileName(version)}
                                                        title={document.title}
                                                    />
                                                    <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                        Fase: {document.phase ? `${document.phase.code} - ${document.phase.name}` : 'Sem fase'}
                                                    </div>
                                                </td>
                                                <td>
                                                    <span className="sig-pill sig-pill-blue font-semibold">
                                                        {version?.revision || 'Sem revisao'}
                                                    </span>
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
                                                <td>
                                                    <div className="font-semibold">{document.creator?.name || 'Sistema'}</div>
                                                    <div className="text-xs text-[var(--ink-500)]">{new Date(document.created_at).toLocaleDateString('pt-BR')}</div>
                                                </td>
                                                <td>
                                                    <span className={`sig-pill ${statusClasses[displayStatus] || 'sig-pill-blue'}`}>
                                                        {projectStatusLabel(document, statusLabels)}
                                                    </span>
                                                    {document.reviewed_at && (
                                                        <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                            {document.reviewer?.name || 'Revisado'} em {new Date(document.reviewed_at).toLocaleDateString('pt-BR')}
                                                        </div>
                                                    )}
                                                    {document.approved_at && (
                                                        <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                            {document.approver?.name || 'Aprovado'} em {new Date(document.approved_at).toLocaleDateString('pt-BR')}
                                                        </div>
                                                    )}
                                                </td>
                                                <td>
                                                    <div className="max-w-[240px] truncate text-sm font-semibold">{fileDisplayName(version)}</div>
                                                    <div className="text-xs text-[var(--ink-500)]">{version?.size_label}</div>
                                                    <div className="mt-2 flex flex-wrap gap-2">
                                                        {version?.url && (
                                                            <a href={version.url} download={fileDisplayName(version)} className="sig-btn sig-btn-secondary sig-btn-sm">
                                                                <Download size={13} />
                                                                Baixar
                                                            </a>
                                                        )}
                                                        {isFileRemoving(version) ? (
                                                            <span className="sig-pill bg-[var(--surface-muted)] text-[var(--ink-600)]">
                                                                Removendo da APS
                                                            </span>
                                                        ) : version?.status === 'reprovado' ? (
                                                            <span className="sig-pill bg-[var(--surface-muted)] text-[var(--ink-600)]">
                                                                Original preservado
                                                            </span>
                                                        ) : version?.file_path ? (
                                                            isApsWaiting(version) ? (
                                                                <span className="sig-pill inline-flex items-center gap-1.5 bg-[var(--surface-muted)] text-[var(--ink-600)]">
                                                                    <LoaderCircle size={13} className="animate-spin" />
                                                                    Processando APS
                                                                </span>
                                                            ) : version.aps_urn ? (
                                                                 <Link href={`${route('tenant.projects.viewer', [tenant.slug, version.id])}?workspace=review`} className="sig-btn sig-btn-primary sig-btn-sm">
                                                                     <Eye size={13} />
                                                                     Checklist
                                                                 </Link>
                                                            ) : (
                                                                <button
                                                                    type="button"
                                                                    className="sig-btn sig-btn-secondary sig-btn-sm"
                                                                    onClick={() => router.post(route('tenant.projects.process-aps', [tenant.slug, version.id]), {}, { preserveScroll: true })}
                                                                >
                                                                    <Play size={13} />
                                                                    Processar APS
                                                                </button>
                                                            )
                                                        ) : version ? (
                                                            <span className="sig-pill bg-[var(--surface-muted)] text-[var(--ink-600)]">
                                                                Arquivo removido
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                </td>
                                                <td>
                                                    {actionable ? (
                                                        <div className="grid min-w-[300px] gap-2">
                                                            {isApprovalStep && (
                                                                <button
                                                                    type="button"
                                                                    className="sig-btn sig-btn-secondary sig-btn-sm justify-self-start"
                                                                    onClick={() => setAnalysisDocument(document)}
                                                                >
                                                                    <Eye size={13} />
                                                                    Ver analise
                                                                </button>
                                                            )}
                                                            {version?.cap_number && (
                                                                <button
                                                                    type="button"
                                                                    className="sig-btn sig-btn-secondary sig-btn-sm justify-self-start"
                                                                    onClick={() => setCapDocument(document)}
                                                                >
                                                                    <Eye size={13} />
                                                                    Visualizar CAP
                                                                </button>
                                                            )}
                                                            <textarea
                                                                value={notes[currentNoteKey] || ''}
                                                                onChange={(event) => setNotes((current) => ({ ...current, [currentNoteKey]: event.target.value }))}
                                                                placeholder={placeholder}
                                                                rows={3}
                                                                className="w-full rounded-lg border border-[var(--border)] px-3 py-2 text-sm outline-none focus:border-[var(--primary)]"
                                                            />
                                                            <div className="flex flex-wrap justify-end gap-2">
                                                                <button type="button" onClick={() => openRejectionModal(document)} className="sig-btn sig-btn-secondary sig-btn-sm text-[var(--red)]">
                                                                    <XCircle size={13} />
                                                                    Reprovar
                                                                </button>
                                                                <button type="button" onClick={() => reviewDocument(document, 'aprovar')} className="sig-btn sig-btn-primary sig-btn-sm">
                                                                    <CheckCircle2 size={13} />
                                                                    {positiveLabel}
                                                                </button>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <div className="max-w-[320px] text-sm text-[var(--ink-600)]">
                                                            <div>{document.review_notes || 'Sem observacao de analise registrada.'}</div>
                                                            {document.approval_notes && (
                                                                <div className="mt-2 border-t border-[var(--border)] pt-2">
                                                                    {document.approval_notes}
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <div className="projects-compact-only">
                            {filteredDocuments.map((document) => {
                                const version = document.latest_version;
                                const expanded = expandedDocumentIds.includes(document.id);
                                const displayStatus = projectDisplayStatus(document);

                                return (
                                    <article key={document.id} className="border-b border-[var(--border)] last:border-b-0">
                                        <button
                                            type="button"
                                            className="flex w-full items-start justify-between gap-3 px-5 py-4 text-left transition-colors hover:bg-[var(--surface-muted)]"
                                            aria-expanded={expanded}
                                            onClick={() => toggleDocumentDetails(document.id)}
                                        >
                                            <span className="min-w-0 flex-1">
                                                <span className="flex flex-wrap items-center gap-2">
                                                    <span className="sig-pill sig-pill-blue font-semibold">{version?.revision || 'Sem revisao'}</span>
                                                    <span className={`sig-pill ${statusClasses[displayStatus] || 'sig-pill-blue'}`}>
                                                        {projectStatusLabel(document, statusLabels)}
                                                    </span>
                                                </span>
                                                <ProjectIdentity
                                                    className="mt-2"
                                                    eap={projectEap(document, version)}
                                                    fileName={originalFileName(version)}
                                                    title={document.title}
                                                />
                                                <span className="mt-3 grid gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-4">
                                                    <CompactInfo label="Contrato" value={`${document.contract?.code || '-'} - ${document.contract?.name || 'Sem contrato'}`} />
                                                    <CompactInfo label="Obra" value={`${document.obra?.codigo || '-'} - ${document.obra?.nome || 'Sem obra'}`} />
                                                    <CompactInfo label="Disciplina" value={`${document.disciplina?.sigla || '-'} - ${document.disciplina?.nome || 'Sem disciplina'}`} />
                                                    <CompactInfo label="Fase" value={document.phase ? `${document.phase.code} - ${document.phase.name}` : 'Sem fase'} />
                                                </span>
                                            </span>
                                            <ChevronDown size={18} className={`mt-1 shrink-0 text-[var(--ink-500)] transition-transform ${expanded ? 'rotate-180' : ''}`} />
                                        </button>

                                        {expanded && (
                                            <div className="border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                                    <CompactInfo label="Submetido por" value={`${document.creator?.name || 'Sistema'} - ${new Date(document.created_at).toLocaleDateString('pt-BR')}`} />
                                                    <CompactInfo label="Arquivo" value={fileDisplayName(version) || 'Sem arquivo'} />
                                                    <CompactInfo label="Tamanho" value={version?.size_label || '-'} />
                                                    <CompactInfo label="Status APS" value={derivativeLabels[version?.derivative_status] || version?.derivative_status || '-'} />
                                                </div>

                                                {document.reviewed_at && (
                                                    <div className="mt-3 text-xs text-[var(--ink-500)]">
                                                        {document.reviewer?.name || 'Revisado'} em {new Date(document.reviewed_at).toLocaleDateString('pt-BR')}
                                                    </div>
                                                )}
                                                {document.approved_at && (
                                                    <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                        {document.approver?.name || 'Aprovado'} em {new Date(document.approved_at).toLocaleDateString('pt-BR')}
                                                    </div>
                                                )}

                                                <div className="mt-4 border-t border-[var(--border)] pt-4">
                                                    <ReviewFileActions tenant={tenant} version={version} />
                                                </div>
                                                <div className="mt-4 border-t border-[var(--border)] pt-4">
                                                    <ReviewDecisionPanel
                                                        document={document}
                                                        version={version}
                                                        notes={notes}
                                                        setNotes={setNotes}
                                                        onAnalysis={() => setAnalysisDocument(document)}
                                                        onCap={() => setCapDocument(document)}
                                                        onReview={reviewDocument}
                                                        onReject={openRejectionModal}
                                                    />
                                                </div>
                                            </div>
                                        )}
                                    </article>
                                );
                            })}
                        </div>
                        </>
                    ) : filteredBatches.length === 0 ? (
                        <div className="p-12 text-center text-sm text-[var(--ink-500)]">
                            Nenhum projeto encontrado para analise.
                        </div>
                    ) : null}
                </section>
            </section>

            {analysisDocument && (
                <AnalysisModal document={analysisDocument} onClose={() => setAnalysisDocument(null)} />
            )}
            {rejectionDocument && (
                <ProjectRejectionModal
                    document={rejectionDocument}
                    reason={rejectionReason}
                    error={rejectionError}
                    processing={rejecting}
                    onReasonChange={(value) => {
                        setRejectionReason(value);
                        if (value.trim()) setRejectionError('');
                    }}
                    onCancel={closeRejectionModal}
                    onConfirm={submitRejection}
                />
            )}
            {rejectionBatch && (
                <BatchRejectionModal
                    batch={rejectionBatch}
                    reason={batchRejectionReason}
                    error={batchRejectionError}
                    processing={rejecting}
                    onReasonChange={(value) => {
                        setBatchRejectionReason(value);
                        if (value.trim()) setBatchRejectionError('');
                    }}
                    onCancel={() => !rejecting && setRejectionBatch(null)}
                    onConfirm={() => {
                        if (!batchRejectionReason.trim()) {
                            setBatchRejectionError('Informe o motivo da devolução.');
                            return;
                        }
                        reviewBatch(rejectionBatch, 'reprovar', batchRejectionReason.trim());
                    }}
                />
            )}
            {capDocument && (
                <ProjectCapModal
                    document={capDocument}
                    version={capDocument.latest_version}
                    capImpactLabels={capImpactLabels}
                    onClose={() => setCapDocument(null)}
                />
            )}
        </AuthenticatedLayout>
    );
}

function BatchReviewCard({ tenant, batch, statusLabels, note, onNoteChange, onApprove, onReject }) {
    const actionable = ['em_analise', 'em_aprovacao'].includes(batch.status);
    const isApproval = batch.status === 'em_aprovacao';
    const versions = batch.versions || [];
    const [expanded, setExpanded] = useState(false);

    return (
        <article className="overflow-hidden rounded-lg border border-[var(--primary-200)] bg-white">
            <header className="flex flex-wrap items-start justify-between gap-3 bg-[var(--primary-50)] px-4 py-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <Files size={15} className="text-[var(--primary)]" />
                        <span className="mono text-sm font-semibold text-[var(--primary)]">{batch.package_number}</span>
                        <span className={`sig-pill ${statusClasses[batch.status] || 'sig-pill-blue'}`}>{statusLabels[batch.status] || batch.status}</span>
                        <span className="sig-pill sig-pill-blue">{versions.length} projetos</span>
                    </div>
                    <h3 className="mt-1 font-semibold">{batch.title}</h3>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">{batch.contract?.code} · {batch.obra?.codigo} - {batch.obra?.nome}{batch.cap_number ? ` · CAP ${batch.cap_number}` : ' · Primeira submissão'}</p>
                </div>
                <button
                    type="button"
                    className="sig-btn sig-btn-secondary sig-btn-sm"
                    aria-expanded={expanded}
                    onClick={() => setExpanded((current) => !current)}
                >
                    {expanded ? 'Recolher' : 'Expandir'}
                    <ChevronDown size={14} className={`transition-transform ${expanded ? 'rotate-180' : ''}`} />
                </button>
            </header>

            {expanded && (
                <>
                    <div className="divide-y divide-[var(--border)]">
                        {versions.map((version) => (
                            <div key={version.id} className="grid gap-3 px-4 py-3 md:grid-cols-[minmax(0,1.5fr)_150px_auto] md:items-center">
                                <ProjectIdentity
                                    eap={projectEap(version.document, version)}
                                    fileName={originalFileName(version)}
                                    title={version.document?.title}
                                />
                                <div className="text-xs"><span className="font-semibold">{version.document?.disciplina?.sigla}</span><div className="text-[var(--ink-500)]">{version.revision} · {version.size_label}</div></div>
                                <BatchProjectActions tenant={tenant} batch={batch} version={version} />
                            </div>
                        ))}
                    </div>

                    {actionable && (
                        <footer className="grid gap-4 border-t border-[var(--border)] bg-[var(--surface-muted)] p-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                            <label className="min-w-0">
                                <span className="eyebrow mb-1 block">Análise do pacote</span>
                                <textarea
                                    rows={4}
                                    className="min-h-[120px] w-full resize-y"
                                    value={note}
                                    onChange={(event) => onNoteChange(event.target.value)}
                                    placeholder={isApproval ? 'Observação da aprovação do pacote' : 'Observação da análise do pacote'}
                                />
                            </label>
                            <div className="flex flex-wrap justify-end gap-2">
                                {!batch.can_act && <span className="max-w-[260px] text-xs text-[var(--ink-500)]">A decisão exige responsabilidade em todas as disciplinas deste pacote.</span>}
                                <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm text-[var(--red)]" onClick={onReject} disabled={!batch.can_act}><XCircle size={13} />Reprovar pacote</button>
                                <button type="button" className="sig-btn sig-btn-primary sig-btn-sm" onClick={onApprove} disabled={!batch.can_act}><CheckCircle2 size={13} />{isApproval ? 'Aprovar pacote' : 'Enviar pacote para aprovação'}</button>
                            </div>
                        </footer>
                    )}
                </>
            )}
        </article>
    );
}

function BatchProjectActions({ tenant, batch, version }) {
    const revisionNumber = Number.parseInt(String(version?.revision || '').replace(/\D+/g, ''), 10);
    const isRevision = Number.isFinite(revisionNumber) && revisionNumber > 0;

    return (
        <div className="flex flex-wrap items-center gap-2 md:justify-end">
            {version?.url && (
                <a href={version.url} download={fileDisplayName(version)} className="sig-btn sig-btn-secondary sig-btn-sm">
                    <Download size={13} />
                    Baixar
                </a>
            )}
            {isApsWaiting(version) ? (
                <span className="sig-pill inline-flex items-center gap-1.5 bg-[var(--surface-muted)] text-[var(--ink-600)]">
                    <LoaderCircle size={13} className="animate-spin" />
                    {derivativeLabels[version.derivative_status] || 'Processando APS'}
                </span>
            ) : version?.aps_urn ? (
                <Link href={`${route('tenant.projects.viewer', [tenant.slug, version.id])}?workspace=review`} className="sig-btn sig-btn-primary sig-btn-sm">
                    <Eye size={13} />
                    Checklist
                </Link>
            ) : version?.file_path ? (
                <button
                    type="button"
                    className="sig-btn sig-btn-secondary sig-btn-sm"
                    onClick={() => router.post(route('tenant.projects.process-aps', [tenant.slug, version.id]), {}, { preserveScroll: true })}
                >
                    <Play size={13} />
                    Processar APS
                </button>
            ) : null}
            {isRevision && batch.cap_number && (
                <a
                    href={route('tenant.projects.batches.cap.pdf', [tenant.slug, batch.id])}
                    target="_blank"
                    rel="noreferrer"
                    className="sig-btn sig-btn-secondary sig-btn-sm"
                >
                    <FileText size={13} />
                    CAP
                </a>
            )}
        </div>
    );
}

function BatchRejectionModal({ batch, reason, error, processing, onReasonChange, onCancel, onConfirm }) {
    return (
        <div className="fixed inset-0 z-[130] grid place-items-center bg-[rgba(11,16,32,0.45)] p-4" onMouseDown={onCancel}>
            <div className="w-full max-w-[520px] rounded-lg border border-[var(--border)] bg-white shadow-xl" onMouseDown={(event) => event.stopPropagation()}>
                <header className="flex items-start justify-between gap-3 border-b border-[var(--border)] px-5 py-4"><div><span className="eyebrow">{batch.package_number}</span><h2 className="mt-1 text-lg font-semibold">Reprovar pacote completo</h2></div><button type="button" className="sig-btn sig-btn-ghost !min-h-9 !px-2" onClick={onCancel}><X size={17} /></button></header>
                <div className="p-5"><p className="text-sm text-[var(--ink-600)]">Todos os {batch.versions?.length || 0} projetos voltarão para correção e nenhum será liberado isoladamente.</p><label className="mt-4 block"><span className="eyebrow mb-1 block">Motivo</span><textarea rows={4} value={reason} onChange={(event) => onReasonChange(event.target.value)} autoFocus /></label>{error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}</div>
                <footer className="flex justify-end gap-2 border-t border-[var(--border)] px-5 py-4"><button type="button" className="sig-btn sig-btn-secondary" onClick={onCancel} disabled={processing}>Cancelar</button><button type="button" className="sig-btn bg-[var(--red)] text-white" onClick={onConfirm} disabled={processing}><XCircle size={14} />Devolver pacote</button></footer>
            </div>
        </div>
    );
}

function CompactInfo({ label, value }) {
    return (
        <span className="min-w-0">
            <span className="eyebrow block">{label}</span>
            <span className="mt-1 block break-words text-sm font-medium text-[var(--ink-700)]">{value || '-'}</span>
        </span>
    );
}

function ReviewFileActions({ tenant, version }) {
    return (
        <div className="flex flex-wrap gap-2">
            {version?.url && (
                <a href={version.url} download={fileDisplayName(version)} className="sig-btn sig-btn-secondary sig-btn-sm">
                    <Download size={13} />
                    Baixar
                </a>
            )}
            {isFileRemoving(version) ? (
                <span className="sig-pill bg-white text-[var(--ink-600)]">
                    Removendo da APS
                </span>
            ) : version?.status === 'reprovado' ? (
                <span className="sig-pill bg-white text-[var(--ink-600)]">
                    Original preservado
                </span>
            ) : version?.file_path ? (
                isApsWaiting(version) ? (
                    <span className="sig-pill inline-flex items-center gap-1.5 bg-white text-[var(--ink-600)]">
                        <LoaderCircle size={13} className="animate-spin" />
                        Processando APS
                    </span>
                ) : version.aps_urn ? (
                    <Link href={`${route('tenant.projects.viewer', [tenant.slug, version.id])}?workspace=review`} className="sig-btn sig-btn-primary sig-btn-sm">
                        <Eye size={13} />
                        Checklist
                    </Link>
                ) : (
                    <button
                        type="button"
                        className="sig-btn sig-btn-secondary sig-btn-sm"
                        onClick={() => router.post(route('tenant.projects.process-aps', [tenant.slug, version.id]), {}, { preserveScroll: true })}
                    >
                        <Play size={13} />
                        Processar APS
                    </button>
                )
            ) : version ? (
                <span className="sig-pill bg-white text-[var(--ink-600)]">
                    Arquivo removido
                </span>
            ) : null}
        </div>
    );
}

function ReviewDecisionPanel({ document, version, notes, setNotes, onAnalysis, onCap, onReview, onReject }) {
    const actionable = ['em_analise', 'em_aprovacao'].includes(document.status);
    const isApprovalStep = document.status === 'em_aprovacao';
    const positiveLabel = isApprovalStep ? 'Aprovar para arvore' : 'Enviar para aprovacao';
    const placeholder = isApprovalStep ? 'Observacao da aprovacao' : 'Observacao da analise';
    const currentNoteKey = noteKey(document);

    if (!actionable) {
        return (
            <div className="text-sm text-[var(--ink-600)]">
                <div>{document.review_notes || 'Sem observacao de analise registrada.'}</div>
                {document.approval_notes && (
                    <div className="mt-2 border-t border-[var(--border)] pt-2">
                        {document.approval_notes}
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            {isApprovalStep && (
                <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm justify-self-start" onClick={onAnalysis}>
                    <Eye size={13} />
                    Ver analise
                </button>
            )}
            {version?.cap_number && (
                <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm justify-self-start" onClick={onCap}>
                    <Eye size={13} />
                    Visualizar CAP
                </button>
            )}
            <textarea
                value={notes[currentNoteKey] || ''}
                onChange={(event) => setNotes((current) => ({ ...current, [currentNoteKey]: event.target.value }))}
                placeholder={placeholder}
                rows={3}
                className="w-full rounded-lg border border-[var(--border)] px-3 py-2 text-sm outline-none focus:border-[var(--primary)]"
            />
            <div className="flex flex-wrap justify-end gap-2">
                <button type="button" onClick={() => onReject(document)} className="sig-btn sig-btn-secondary sig-btn-sm text-[var(--red)]">
                    <XCircle size={13} />
                    Reprovar
                </button>
                <button type="button" onClick={() => onReview(document, 'aprovar')} className="sig-btn sig-btn-primary sig-btn-sm">
                    <CheckCircle2 size={13} />
                    {positiveLabel}
                </button>
            </div>
        </div>
    );
}

function ProjectRejectionModal({ document, reason, error, processing, onReasonChange, onCancel, onConfirm }) {
    return (
        <div
            className="fixed inset-0 z-[130] flex items-center justify-center bg-[rgba(11,16,32,0.52)] px-4 py-6"
            role="presentation"
            onMouseDown={onCancel}
        >
            <section
                className="w-full max-w-xl overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.28)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="project-rejection-title"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 text-[var(--red)]">
                            <AlertTriangle size={15} />
                            <span className="eyebrow">Confirmação</span>
                        </div>
                        <h2 id="project-rejection-title" className="mt-1 text-lg font-semibold text-[var(--ink-900)]">Reprovar projeto</h2>
                        <ProjectIdentity
                            className="mt-2"
                            eap={projectEap(document, document.latest_version)}
                            fileName={originalFileName(document.latest_version)}
                            title={document.title}
                        />
                    </div>
                    <button type="button" className="sig-btn sig-btn-ghost !min-h-9 !px-2" aria-label="Fechar" onClick={onCancel} disabled={processing}>
                        <X size={18} />
                    </button>
                </header>

                <div className="px-5 py-5">
                    <p className="mb-4 text-sm leading-6 text-[var(--ink-600)]">
                        O usuário que submeteu esta versão receberá um e-mail com o motivo informado abaixo.
                    </p>
                    <label>
                        <span className="eyebrow mb-1 block">Motivo da reprovação</span>
                        <textarea
                            value={reason}
                            onChange={(event) => onReasonChange(event.target.value)}
                            rows={5}
                            maxLength={5000}
                            autoFocus
                            required
                            className={`w-full rounded-lg border px-3 py-2 text-sm outline-none ${error ? 'border-[var(--red)]' : 'border-[var(--border)] focus:border-[var(--primary)]'}`}
                            placeholder="Explique o que precisa ser corrigido antes de uma nova submissão."
                        />
                    </label>
                    <div className="mt-1 flex items-start justify-between gap-3 text-xs">
                        <span className="text-[var(--red)]">{error}</span>
                        <span className="shrink-0 text-[var(--ink-400)]">{reason.length}/5000</span>
                    </div>
                </div>

                <footer className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onCancel} disabled={processing}>Cancelar</button>
                    <button type="button" className="sig-btn sig-btn-primary !bg-[var(--red)]" onClick={onConfirm} disabled={processing || !reason.trim()}>
                        <XCircle size={15} />
                        {processing ? 'Reprovando...' : 'Reprovar e notificar'}
                    </button>
                </footer>
            </section>
        </div>
    );
}

function AnalysisModal({ document, onClose }) {
    return (
        <div
            className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.48)] px-4 py-6"
            role="presentation"
            onMouseDown={onClose}
        >
            <section
                className="w-full max-w-2xl overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="project-analysis-title"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <FileSearch size={14} />
                            <span className="eyebrow">Analise do responsavel</span>
                        </div>
                        <ProjectIdentity
                            className="mt-2"
                            eap={projectEap(document, document.latest_version)}
                            fileName={originalFileName(document.latest_version)}
                            title={document.title}
                            eapClassName="text-[17px]"
                        />
                        <p className="mt-1 text-[12.5px] text-[var(--ink-500)]">{document.contract?.code} - {document.contract?.name}</p>
                    </div>
                    <button type="button" className="sig-btn sig-btn-ghost !min-h-9 !px-2" title="Fechar" onClick={onClose}>
                        <X size={18} />
                    </button>
                </header>

                <div className="grid gap-4 px-5 py-5">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <InfoBlock label="Responsavel" value={document.reviewer?.name || 'Nao informado'} />
                        <InfoBlock label="Data da analise" value={formatDateTime(document.reviewed_at)} />
                        <InfoBlock label="Disciplina" value={`${document.disciplina?.sigla || ''} - ${document.disciplina?.nome || 'Sem disciplina'}`} />
                    </div>

                    <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4">
                        <span className="eyebrow">Observacao registrada</span>
                        <p className="mt-2 whitespace-pre-line text-sm leading-6 text-[var(--ink-700)]">
                            {document.review_notes || 'Sem observacao de analise registrada.'}
                        </p>
                    </div>
                </div>

                <footer className="flex justify-end border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onClose}>
                        Fechar
                    </button>
                </footer>
            </section>
        </div>
    );
}

function InfoBlock({ label, value }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-white p-3">
            <div className="eyebrow">{label}</div>
            <div className="mt-1 text-sm font-semibold text-[var(--ink-900)]">{value}</div>
        </div>
    );
}

function StatCard({ label, value, tone }) {
    const toneClass = {
        blue: 'text-[var(--primary)] bg-[var(--primary-50)]',
        green: 'text-[var(--green)] bg-[var(--green-50)]',
        amber: 'text-[var(--amber)] bg-[var(--amber-50)]',
        red: 'text-[var(--red)] bg-[var(--red-50)]',
    }[tone];

    return (
        <div className="sig-card p-4">
            <div className="eyebrow">{label}</div>
            <div className={`mt-3 inline-flex h-10 min-w-10 items-center justify-center rounded-lg px-3 text-lg font-semibold ${toneClass}`}>
                {value}
            </div>
        </div>
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
