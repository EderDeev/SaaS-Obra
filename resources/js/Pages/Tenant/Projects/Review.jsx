import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ProjectCapModal from '@/Components/ProjectCapModal';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, ChevronDown, Download, Eye, FileSearch, Filter, Play, Search, Send, X, XCircle } from 'lucide-react';
import { useMemo, useState } from 'react';

const statusClasses = {
    em_analise: 'sig-pill-blue',
    em_aprovacao: 'sig-pill-amber',
    ativo: 'sig-pill-green',
    reprovado: 'sig-pill-red',
};

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

function shouldShowOriginalName(version) {
    return Boolean(version?.original_name && version?.stored_name && version.original_name !== version.stored_name);
}

function isApsWaiting(version) {
    return ['queued', 'processing'].includes(version?.derivative_status);
}

function noteKey(document) {
    return `${document.id}:${document.status}`;
}

export default function ProjectReview({ tenant, contracts, documents, statusLabels, capImpactLabels = {}, stats }) {
    const [contractFilter, setContractFilter] = useState('todos');
    const [statusFilter, setStatusFilter] = useState('todos');
    const [query, setQuery] = useState('');
    const [notes, setNotes] = useState({});
    const [analysisDocument, setAnalysisDocument] = useState(null);
    const [capDocument, setCapDocument] = useState(null);
    const [expandedDocumentIds, setExpandedDocumentIds] = useState([]);

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

            return `${document.title} ${document.code || ''} ${fileDisplayName(document.latest_version)} ${document.latest_version?.original_name || ''} ${document.contract?.code || ''} ${document.obra?.nome || ''} ${document.disciplina?.nome || ''} ${document.phase?.name || ''} ${document.phase?.code || ''}`
                .toLowerCase()
                .includes(term);
        });
    }, [documents, contractFilter, statusFilter, query]);

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

    const toggleDocumentDetails = (documentId) => {
        setExpandedDocumentIds((currentIds) => currentIds.includes(documentId)
            ? currentIds.filter((currentId) => currentId !== documentId)
            : [...currentIds, documentId]);
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
                                        const positiveLabel = isApprovalStep ? 'Aprovar para arvore' : 'Enviar para aprovacao';
                                        const placeholder = isApprovalStep ? 'Observacao da aprovacao' : 'Observacao da analise';
                                        const currentNoteKey = noteKey(document);

                                        return (
                                            <tr key={document.id}>
                                                <td>
                                                    <div className="font-semibold">{document.title}</div>
                                                    <div className="mono mt-1 text-xs text-[var(--ink-500)]">{document.code || 'Sem codigo'}</div>
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
                                                    <span className={`sig-pill ${statusClasses[document.status] || 'sig-pill-blue'}`}>
                                                        {statusLabels[document.status] || document.status}
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
                                                    {shouldShowOriginalName(version) && (
                                                        <div className="max-w-[240px] truncate text-xs text-[var(--ink-500)]">Original: {version.original_name}</div>
                                                    )}
                                                    <div className="text-xs text-[var(--ink-500)]">{version?.size_label}</div>
                                                    {version?.url && (
                                                        <a href={version.url} download={fileDisplayName(version)} className="sig-btn sig-btn-secondary sig-btn-sm mt-2">
                                                            <Download size={13} />
                                                            Baixar
                                                        </a>
                                                    )}
                                                    {version && (
                                                        version.aps_urn ? (
                                                             <Link href={`${route('tenant.projects.viewer', [tenant.slug, version.id])}?workspace=review`} className="sig-btn sig-btn-primary sig-btn-sm mt-2">
                                                                 <Eye size={13} />
                                                                 Checklist
                                                             </Link>
                                                        ) : isApsWaiting(version) ? (
                                                            <span className="sig-pill bg-[var(--surface-muted)] text-[var(--ink-600)] mt-2">
                                                                Processando APS
                                                            </span>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="sig-btn sig-btn-secondary sig-btn-sm mt-2"
                                                                onClick={() => router.post(route('tenant.projects.process-aps', [tenant.slug, version.id]), {}, { preserveScroll: true })}
                                                            >
                                                                <Play size={13} />
                                                                Processar APS
                                                            </button>
                                                        )
                                                    )}
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
                                                                <button type="button" onClick={() => reviewDocument(document, 'reprovar')} className="sig-btn sig-btn-secondary sig-btn-sm text-[var(--red)]">
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
                                                    <span className="text-sm font-semibold text-[var(--ink-900)]">{document.title}</span>
                                                    <span className="sig-pill sig-pill-blue font-semibold">{version?.revision || 'Sem revisao'}</span>
                                                    <span className={`sig-pill ${statusClasses[document.status] || 'sig-pill-blue'}`}>
                                                        {statusLabels[document.status] || document.status}
                                                    </span>
                                                </span>
                                                <span className="mono mt-1 block break-all text-xs text-[var(--ink-500)]">{document.code || 'Sem codigo'}</span>
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
                            Nenhum projeto encontrado para analise.
                        </div>
                    )}
                </section>
            </section>

            {analysisDocument && (
                <AnalysisModal document={analysisDocument} onClose={() => setAnalysisDocument(null)} />
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
            {version && (
                version.aps_urn ? (
                    <Link href={`${route('tenant.projects.viewer', [tenant.slug, version.id])}?workspace=review`} className="sig-btn sig-btn-primary sig-btn-sm">
                        <Eye size={13} />
                        Checklist
                    </Link>
                ) : isApsWaiting(version) ? (
                    <span className="sig-pill bg-white text-[var(--ink-600)]">
                        Processando APS
                    </span>
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
            )}
        </div>
    );
}

function ReviewDecisionPanel({ document, version, notes, setNotes, onAnalysis, onCap, onReview }) {
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
                <button type="button" onClick={() => onReview(document, 'reprovar')} className="sig-btn sig-btn-secondary sig-btn-sm text-[var(--red)]">
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
                        <h2 id="project-analysis-title" className="mt-1 truncate text-[17px] font-semibold text-[var(--ink-900)]">
                            {document.title}
                        </h2>
                        <p className="mt-1 text-[12.5px] text-[var(--ink-500)]">
                            {document.code || 'Sem codigo'} · {document.contract?.code} - {document.contract?.name}
                        </p>
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
