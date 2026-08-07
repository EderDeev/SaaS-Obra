import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ProjectIdentity from '@/Components/ProjectIdentity';
import { projectEap } from '@/Utils/projectEap';
import { Head, Link } from '@inertiajs/react';
import { ChevronDown, ClipboardList, Columns2, Download, Eye, FileText, Filter, GitBranch, History, MessageSquare, Search, UserRound, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const REVISIONS_PER_PAGE = 10;

const statusClasses = {
    em_analise: 'sig-pill-blue',
    em_aprovacao: 'sig-pill-amber',
    ativo: 'sig-pill-green',
    reprovado: 'sig-pill-red',
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

function personName(person) {
    return person?.name || person?.email || 'Nao informado';
}

function versionNumber(version) {
    const match = String(version?.revision || '').match(/^R?(\d+)$/i);

    return match ? Number(match[1]) : 0;
}

function sortedVersions(document) {
    return [...(document.versions || [])].sort((left, right) => (
        versionNumber(left) - versionNumber(right) || Number(left.id) - Number(right.id)
    ));
}

function previousVersionFor(document, version) {
    const versions = sortedVersions(document);
    const index = versions.findIndex((candidate) => Number(candidate.id) === Number(version.id));

    return index > 0 ? versions[index - 1] : null;
}

function latestVersionFor(document) {
    const versions = sortedVersions(document);

    return versions[versions.length - 1] || null;
}

function versionComments(version) {
    return version?.review_markups || [];
}

function fileDisplayName(version) {
    return version?.stored_name || version?.original_name || 'arquivo';
}

function originalFileName(version) {
    return version?.original_name || version?.stored_name || 'arquivo';
}

function viewerUrl(tenant, version, workspace = 'view') {
    return `${route('tenant.projects.viewer', [tenant.slug, version.id])}?workspace=${workspace}`;
}

function capPdfUrl(tenant, version) {
    return route('tenant.projects.cap.pdf', [tenant.slug, version.id]);
}

function comparisonUrl(tenant, version, baseVersion) {
    return route('tenant.projects.compare', [tenant.slug, version.id, baseVersion.id]);
}

function canCompareVersions(version, baseVersion) {
    return Boolean(
        version?.aps_urn
        && baseVersion?.aps_urn
        && version.derivative_status === 'ready'
        && baseVersion.derivative_status === 'ready',
    );
}

export default function ProjectRevisions({
    tenant,
    contracts,
    documents,
    batches = [],
    documentTypes,
    statusLabels,
    capImpactLabels = {},
    canReviewProjects = false,
}) {
    const [contractFilter, setContractFilter] = useState('todos');
    const [query, setQuery] = useState('');
    const [historyRow, setHistoryRow] = useState(null);
    const [expandedRowIds, setExpandedRowIds] = useState([]);
    const [currentPage, setCurrentPage] = useState(1);
    const revisionsListRef = useRef(null);

    const batchVersionIds = useMemo(() => new Set(
        batches.flatMap((batch) => (batch.versions || []).map((version) => Number(version.id))),
    ), [batches]);

    const standaloneRows = useMemo(() => documents.flatMap((document) => (
        (document.versions || [])
            .filter((version) => version.cap_number && !batchVersionIds.has(Number(version.id)))
            .map((version) => ({
            id: `${document.id}-${version.id}`,
            document,
            version,
            batch: null,
        }))
    )), [documents, batchVersionIds]);

    const batchRows = useMemo(() => batches.flatMap((batch) => (
        (batch.versions || []).map((version) => ({
            id: `batch-${batch.id}-${version.id}`,
            batch,
            document: {
                ...version.document,
                contract: batch.contract,
                obra: batch.obra,
                trecho: batch.trecho,
                phase: batch.phase,
            },
            version: {
                ...version,
                status: batch.status,
                cap_number: batch.cap_number,
                cap_requested_at: batch.created_at,
                cap_requester: batch.submitter,
            },
        }))
    )), [batches]);

    const rows = useMemo(() => [...batchRows, ...standaloneRows], [batchRows, standaloneRows]);
    const registeredCapsCount = standaloneRows.length + batches.length;

    const filteredRows = useMemo(() => {
        const term = query.trim().toLowerCase();

        return rows.filter(({ document, version, batch }) => {
            if (contractFilter !== 'todos' && String(document.contract_id) !== String(contractFilter)) {
                return false;
            }

            if (!term) {
                return true;
            }

            return `${version.cap_number || ''} ${batch?.package_number || ''} ${batch?.title || ''} ${document.title} ${projectEap(document, version)} ${document.contract?.code || ''} ${document.obra?.nome || ''} ${document.disciplina?.nome || ''} ${document.phase?.name || ''}`
                .toLowerCase()
                .includes(term);
        });
    }, [rows, contractFilter, query]);

    const totalPages = Math.max(1, Math.ceil(filteredRows.length / REVISIONS_PER_PAGE));
    const paginatedRows = useMemo(() => {
        const start = (currentPage - 1) * REVISIONS_PER_PAGE;

        return filteredRows.slice(start, start + REVISIONS_PER_PAGE);
    }, [filteredRows, currentPage]);

    useEffect(() => {
        setCurrentPage(1);
    }, [contractFilter, query]);

    useEffect(() => {
        setCurrentPage((page) => Math.min(page, totalPages));
    }, [totalPages]);

    const goToPage = (pageNumber) => {
        setCurrentPage(Math.min(Math.max(pageNumber, 1), totalPages));
        revisionsListRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const toggleRowDetails = (rowId) => {
        setExpandedRowIds((currentIds) => currentIds.includes(rowId)
            ? currentIds.filter((currentId) => currentId !== rowId)
            : [...currentIds, rowId]);
    };

    return (
        <AuthenticatedLayout>
            <Head title="Projetos revisados" />

            <section className="sig-content grid gap-5">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <ClipboardList size={15} />
                            <span className="eyebrow">Projetos</span>
                        </div>
                        <h1 className="mt-1 text-xl font-semibold text-[var(--ink-900)]">Projetos revisados</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Historico das revisoes que geraram CAP, com motivo, impactos e registros de analise/aprovacao.
                        </p>
                    </div>
                    <div className="sig-card px-4 py-3">
                        <div className="eyebrow">CAPs registradas</div>
                        <div className="mt-1 text-lg font-semibold text-[var(--ink-900)]">{registeredCapsCount}</div>
                    </div>
                </header>

                <section ref={revisionsListRef} className="projects-module-card sig-card scroll-mt-4 overflow-hidden">
                    <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 lg:grid-cols-[minmax(220px,320px)_1fr]">
                        <FilterSelect label="Contrato" value={contractFilter} onChange={setContractFilter}>
                            <option value="todos">Todos os contratos</option>
                            {contracts.map((contract) => (
                                <option key={contract.id} value={contract.id}>{contractLabel(contract)}</option>
                            ))}
                        </FilterSelect>

                        <label>
                            <span className="eyebrow mb-1 flex items-center gap-1">
                                <Search size={12} />
                                Busca
                            </span>
                            <span className="sig-input bg-white">
                                <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar por CAP, EAP, obra ou disciplina" />
                            </span>
                        </label>
                    </div>

                    {filteredRows.length > 0 ? (
                        <>
                        <div className="projects-wide-only overflow-x-auto">
                            <table className="sig-table min-w-[1320px]">
                                <thead>
                                    <tr>
                                        <th>CAP</th>
                                        <th>Projeto</th>
                                        <th>Histórico</th>
                                        <th>Contrato</th>
                                        <th>Obra</th>
                                        <th>Disciplina / fase</th>
                                        <th>Solicitante</th>
                                        <th>Status</th>
                                        <th>Acoes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {paginatedRows.map(({ id, document, version, batch }) => {
                                        const previousVersion = batch ? null : previousVersionFor(document, version);
                                        const latestVersion = batch ? version : latestVersionFor(document);
                                        const commentsCount = batch ? 0 : versionComments(version).length;
                                        const comparisonAvailable = canCompareVersions(version, previousVersion);

                                        return (
                                        <tr key={id}>
                                            <td>
                                                <div className="font-semibold">{version.cap_number}</div>
                                                {batch && <div className="mt-1 mono text-xs font-semibold text-[var(--primary)]">{batch.package_number}</div>}
                                                <div className="mt-1 text-xs text-[var(--ink-500)]">{formatDateTime(version.cap_requested_at || version.created_at)}</div>
                                            </td>
                                            <td>
                                                <ProjectIdentity
                                                    eap={projectEap(document, version)}
                                                    fileName={originalFileName(version)}
                                                    title={document.title}
                                                />
                                                {batch && <span className="mt-2 inline-flex sig-pill sig-pill-blue">CAP consolidada</span>}
                                                <div className="mt-1 text-xs text-[var(--ink-500)]">{documentTypes[document.document_type] || document.document_type}</div>
                                            </td>
                                            <td>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {batch ? (
                                                        <span className="font-semibold text-[var(--ink-700)]">{batch.title}</span>
                                                    ) : previousVersion ? (
                                                        <>
                                                            <span className="sig-pill sig-pill-muted">{previousVersion.revision}</span>
                                                            <GitBranch size={13} className="text-[var(--ink-400)]" />
                                                        </>
                                                    ) : (
                                                        <span className="text-xs text-[var(--ink-500)]">Sem revisão anterior</span>
                                                    )}
                                                    <span className="sig-pill sig-pill-blue font-semibold">{version.revision}</span>
                                                </div>
                                                {!batch && <><div className="mt-2 text-xs text-[var(--ink-500)]">
                                                    Atual: {latestVersion?.revision || version.revision}
                                                </div></>}
                                                {!batch && <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                    {commentsCount} comentário(s) nesta revisão
                                                </div>}
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
                                                <div className="text-sm font-semibold">{document.disciplina?.sigla} - {document.disciplina?.nome}</div>
                                                <div className="mt-1 text-xs text-[var(--ink-500)]">{document.phase ? `${document.phase.code} - ${document.phase.name}` : 'Sem fase'}</div>
                                            </td>
                                            <td>
                                                <div className="font-semibold">{personName(version.cap_requester || version.uploader)}</div>
                                                <div className="text-xs text-[var(--ink-500)]">{version.uploader?.email}</div>
                                            </td>
                                            <td>
                                                <span className={`sig-pill ${statusClasses[version.status] || 'sig-pill-blue'}`}>
                                                    {statusLabels[version.status] || version.status}
                                                </span>
                                            </td>
                                            <td>
                                                <div className="flex flex-wrap gap-2">
                                                    {!batch && <button
                                                        type="button"
                                                        className="sig-btn sig-btn-primary sig-btn-sm"
                                                        onClick={() => setHistoryRow({ document, version })}
                                                    >
                                                        <History size={13} />
                                                        Histórico
                                                    </button>}
                                                    <a
                                                        href={batch
                                                            ? route('tenant.projects.batches.cap.pdf', [tenant.slug, batch.id])
                                                            : capPdfUrl(tenant, version)}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="sig-btn sig-btn-secondary sig-btn-sm"
                                                    >
                                                        <FileText size={13} />
                                                        CAP PDF
                                                    </a>
                                                    {!batch && previousVersion && (comparisonAvailable ? (
                                                        <Link href={comparisonUrl(tenant, version, previousVersion)} className="sig-btn sig-btn-secondary sig-btn-sm">
                                                            <Columns2 size={13} />
                                                            Comparar
                                                        </Link>
                                                    ) : (
                                                        <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" disabled title="As duas revisoes precisam estar processadas no APS">
                                                            <Columns2 size={13} />
                                                            Comparar
                                                        </button>
                                                    ))}
                                                </div>
                                            </td>
                                        </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <div className="projects-compact-only">
                            {paginatedRows.map(({ id, document, version, batch }) => {
                                const previousVersion = batch ? null : previousVersionFor(document, version);
                                const latestVersion = batch ? version : latestVersionFor(document);
                                const commentsCount = batch ? 0 : versionComments(version).length;
                                const expanded = expandedRowIds.includes(id);
                                const comparisonAvailable = canCompareVersions(version, previousVersion);

                                return (
                                    <article key={id} className="border-b border-[var(--border)] last:border-b-0">
                                        <button
                                            type="button"
                                            className="flex w-full items-start justify-between gap-3 px-5 py-4 text-left transition-colors hover:bg-[var(--surface-muted)]"
                                            aria-expanded={expanded}
                                            onClick={() => toggleRowDetails(id)}
                                        >
                                            <span className="min-w-0 flex-1">
                                                <span className="flex flex-wrap items-center gap-2">
                                                    <span className="sig-pill sig-pill-amber">{version.cap_number}</span>
                                                    {batch && <span className="sig-pill sig-pill-blue">{batch.package_number}</span>}
                                                    <span className={`sig-pill ${statusClasses[version.status] || 'sig-pill-blue'}`}>
                                                        {statusLabels[version.status] || version.status}
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
                                                    <CompactInfo label={batch ? 'Lote' : 'Revisao'} value={batch ? batch.title : `${previousVersion?.revision || 'Inicial'} -> ${version.revision}`} />
                                                </span>
                                            </span>
                                            <ChevronDown size={18} className={`mt-1 shrink-0 text-[var(--ink-500)] transition-transform ${expanded ? 'rotate-180' : ''}`} />
                                        </button>

                                        {expanded && (
                                            <div className="border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                                    <CompactInfo label="Solicitante" value={personName(version.cap_requester || version.uploader)} />
                                                    <CompactInfo label="CAP registrada em" value={formatDateTime(version.cap_requested_at || version.created_at)} />
                                                    <CompactInfo label="Projeto atual" value={latestVersion?.revision || version.revision} />
                                                    {!batch && <CompactInfo label="Comentarios nesta revisao" value={`${commentsCount} comentario(s)`} />}
                                                </div>
                                                <div className="mt-4 flex flex-wrap gap-2 border-t border-[var(--border)] pt-4">
                                                    {!batch && <button type="button" className="sig-btn sig-btn-primary sig-btn-sm" onClick={() => setHistoryRow({ document, version })}>
                                                        <History size={13} />
                                                        Historico
                                                    </button>}
                                                    <a href={batch ? route('tenant.projects.batches.cap.pdf', [tenant.slug, batch.id]) : capPdfUrl(tenant, version)} target="_blank" rel="noreferrer" className="sig-btn sig-btn-secondary sig-btn-sm">
                                                        <FileText size={13} />
                                                        CAP PDF
                                                    </a>
                                                    {!batch && previousVersion && (comparisonAvailable ? (
                                                        <Link href={comparisonUrl(tenant, version, previousVersion)} className="sig-btn sig-btn-secondary sig-btn-sm">
                                                            <Columns2 size={13} />
                                                            Comparar
                                                        </Link>
                                                    ) : (
                                                        <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" disabled title="As duas revisoes precisam estar processadas no APS">
                                                            <Columns2 size={13} />
                                                            Comparar
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </article>
                                );
                            })}
                        </div>
                        <RevisionsPagination
                            currentPage={currentPage}
                            totalPages={totalPages}
                            totalItems={filteredRows.length}
                            onPageChange={goToPage}
                        />
                        </>
                    ) : (
                        <div className="p-12 text-center text-sm text-[var(--ink-500)]">
                            {rows.length === 0 ? 'Nenhum projeto revisado com CAP ainda.' : 'Nenhuma CAP encontrada para os filtros selecionados.'}
                        </div>
                    )}
                </section>
            </section>

            {historyRow && (
                <RevisionHistoryModal
                    tenant={tenant}
                    document={historyRow.document}
                    currentVersion={historyRow.version}
                    statusLabels={statusLabels}
                    capImpactLabels={capImpactLabels}
                    canReviewProjects={canReviewProjects}
                    onClose={() => setHistoryRow(null)}
                />
            )}
        </AuthenticatedLayout>
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
        <span className="min-w-0">
            <span className="eyebrow block">{label}</span>
            <span className="mt-1 block break-words text-sm font-medium text-[var(--ink-700)]">{value || '-'}</span>
        </span>
    );
}

function RevisionsPagination({ currentPage, totalPages, totalItems, onPageChange }) {
    const endPage = Math.min(totalPages, Math.max(5, currentPage + 2));
    const startPage = Math.max(1, endPage - 4);
    const visiblePages = Array.from(
        { length: Math.min(5, totalPages - startPage + 1) },
        (_, index) => startPage + index,
    );
    const from = totalItems ? ((currentPage - 1) * REVISIONS_PER_PAGE) + 1 : 0;
    const to = Math.min(currentPage * REVISIONS_PER_PAGE, totalItems);

    return (
        <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border)] px-5 py-4">
            <div className="text-sm text-[var(--ink-500)]">
                Exibindo {from} a {to} de {totalItems} projeto(s) revisado(s).
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" disabled={currentPage === 1} onClick={() => onPageChange(currentPage - 1)}>
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
                <button type="button" className="sig-btn sig-btn-primary sig-btn-sm" disabled={currentPage === totalPages} onClick={() => onPageChange(currentPage + 1)}>
                    Proxima
                </button>
            </div>
        </footer>
    );
}

function RevisionHistoryModal({ tenant, document, currentVersion, statusLabels, capImpactLabels, canReviewProjects, onClose }) {
    const versions = sortedVersions(document);
    const latestVersion = latestVersionFor(document);
    const previousVersion = previousVersionFor(document, currentVersion);

    return (
        <div
            className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.48)] px-4 py-6"
            role="presentation"
            onMouseDown={onClose}
        >
            <section
                className="max-h-[92vh] w-full max-w-6xl overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="project-revision-history-title"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <History size={15} />
                            <span className="eyebrow">Histórico de revisões</span>
                        </div>
                        <ProjectIdentity
                            className="mt-2"
                            eap={projectEap(document, currentVersion)}
                            fileName={originalFileName(currentVersion)}
                            title={document.title}
                            eapClassName="text-[17px]"
                        />
                    </div>
                    <button type="button" className="sig-btn sig-btn-ghost !min-h-9 !px-2" title="Fechar" onClick={onClose}>
                        <X size={18} />
                    </button>
                </header>

                <div className="max-h-[calc(92vh-130px)] overflow-y-auto px-5 py-5">
                    <div className="grid gap-3 md:grid-cols-3">
                        <InfoCard label="Projeto antigo" value={previousVersion ? `${previousVersion.revision} - ${fileDisplayName(previousVersion)}` : 'Sem revisão anterior'} />
                        <InfoCard label="Revisão selecionada" value={`${currentVersion.revision} - ${formatDateTime(currentVersion.cap_requested_at || currentVersion.created_at)}`} />
                        <InfoCard label="Projeto atual" value={latestVersion ? `${latestVersion.revision} - ${fileDisplayName(latestVersion)}` : 'Nao informado'} />
                    </div>

                    <div className="mt-5 grid gap-4">
                        {versions.map((version) => (
                            <RevisionHistoryItem
                                key={version.id}
                                tenant={tenant}
                                document={document}
                                version={version}
                                active={Number(version.id) === Number(currentVersion.id)}
                                latest={Number(version.id) === Number(latestVersion?.id)}
                                statusLabels={statusLabels}
                                capImpactLabels={capImpactLabels}
                                canReviewProjects={canReviewProjects}
                            />
                        ))}
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

function RevisionHistoryItem({ tenant, document, version, active, latest, statusLabels, capImpactLabels, canReviewProjects }) {
    const comments = versionComments(version);
    const checklistItems = version.review_checklist?.items || [];
    const checkedItems = checklistItems.filter((item) => item.checked).length;
    const impacts = Array.isArray(version.cap_impacts) ? version.cap_impacts : [];
    const canOpenViewer = version.aps_urn && (version.status === 'ativo' || canReviewProjects);
    const previousVersion = previousVersionFor(document, version);
    const comparisonAvailable = canCompareVersions(version, previousVersion);

    return (
        <article className={`rounded-xl border bg-white p-4 ${active ? 'border-[var(--primary)] shadow-sm' : 'border-[var(--border)]'}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className={`sig-pill ${active ? 'sig-pill-blue' : 'sig-pill-muted'} font-semibold`}>{version.revision}</span>
                        {latest && <span className="sig-pill sig-pill-green">Projeto atual</span>}
                        {version.cap_number && <span className="sig-pill sig-pill-amber">{version.cap_number}</span>}
                        <span className={`sig-pill ${statusClasses[version.status] || 'sig-pill-blue'}`}>
                            {statusLabels[version.status] || version.status}
                        </span>
                    </div>
                    <ProjectIdentity
                        className="mt-2"
                        eap={projectEap(version.document, version)}
                        fileName={originalFileName(version)}
                        title={version.document?.title}
                    />
                    <div className="mt-1 text-xs text-[var(--ink-500)]">
                        Enviado em {formatDateTime(version.created_at)} por {personName(version.uploader)}
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    {canOpenViewer && (
                        <>
                            <Link href={viewerUrl(tenant, version, 'view')} className="sig-btn sig-btn-primary sig-btn-sm">
                                <Eye size={13} />
                                Visualizar
                            </Link>
                            <Link href={viewerUrl(tenant, version, 'comments')} className="sig-btn sig-btn-secondary sig-btn-sm">
                                <MessageSquare size={13} />
                                Comentários
                            </Link>
                        </>
                    )}
                    {!canOpenViewer && (
                        <span className="sig-pill sig-pill-muted">
                            {version.aps_urn ? 'Acesso restrito à análise' : 'APS não processada'}
                        </span>
                    )}
                    {version.url && (
                        <a href={version.url} download={fileDisplayName(version)} className="sig-btn sig-btn-secondary sig-btn-sm">
                            <Download size={13} />
                            Baixar
                        </a>
                    )}
                    {version.cap_number && (
                        <a href={capPdfUrl(tenant, version)} target="_blank" rel="noreferrer" className="sig-btn sig-btn-secondary sig-btn-sm">
                            <FileText size={13} />
                            CAP PDF
                        </a>
                    )}
                    {previousVersion && (comparisonAvailable ? (
                        <Link href={comparisonUrl(tenant, version, previousVersion)} className="sig-btn sig-btn-secondary sig-btn-sm">
                            <Columns2 size={13} />
                            Comparar
                        </Link>
                    ) : (
                        <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" disabled title="As duas revisoes precisam estar processadas no APS">
                            <Columns2 size={13} />
                            Comparar
                        </button>
                    ))}
                </div>
            </div>

            <div className="mt-4 grid gap-3 md:grid-cols-3">
                <InfoCard label="Responsável pela revisão" value={personName(version.cap_requester || version.uploader)} />
                <InfoCard label="Revisado por" value={`${personName(version.reviewer)} - ${formatDateTime(version.reviewed_at)}`} />
                <InfoCard label="Aprovado por" value={`${personName(version.approver)} - ${formatDateTime(version.approved_at)}`} />
            </div>

            {(version.cap_reason || version.cap_description || impacts.length > 0) && (
                <div className="mt-4 grid gap-3 md:grid-cols-2">
                    <Narrative label="Motivo" value={version.cap_reason} />
                    <Narrative label="Alterações / comentários da CAP" value={version.cap_description || version.revision_change_summary} />
                    <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3 md:col-span-2">
                        <span className="eyebrow">Impactos</span>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {impacts.length > 0 ? impacts.map((impact) => (
                                <span key={impact} className="sig-pill sig-pill-blue">{capImpactLabels[impact] || impact}</span>
                            )) : (
                                <span className="text-sm text-[var(--ink-500)]">Nenhum impacto informado.</span>
                            )}
                        </div>
                    </div>
                </div>
            )}

            <div className="mt-4 grid gap-3 md:grid-cols-2">
                <Narrative label="Comentário da análise" value={version.review_notes} />
                <Narrative label="Comentário da aprovação" value={version.approval_notes} />
            </div>

            <section className="mt-4 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="eyebrow flex items-center gap-1">
                        <MessageSquare size={13} />
                        Comentários visuais
                    </span>
                    <span className="text-xs font-semibold text-[var(--ink-500)]">
                        {comments.length} comentário(s) · checklist {checkedItems}/{checklistItems.length}
                    </span>
                </div>
                {comments.length > 0 ? (
                    <div className="mt-3 grid gap-2">
                        {comments.slice(0, 4).map((comment) => (
                            <div key={comment.id} className="rounded-lg border border-[var(--border)] bg-white p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="text-sm font-semibold text-[var(--ink-900)]">{comment.title}</div>
                                    <span className="text-xs text-[var(--ink-500)]">{formatDateTime(comment.created_at)}</span>
                                </div>
                                {comment.description && (
                                    <p className="mt-1 line-clamp-2 text-sm text-[var(--ink-500)]">{comment.description}</p>
                                )}
                                {(comment.replies || []).length > 0 && (
                                    <div className="mt-2 rounded-lg border-l-2 border-[var(--primary-100)] bg-[var(--surface-muted)] px-3 py-2">
                                        <div className="text-xs font-semibold text-[var(--ink-700)]">
                                            Última definição · {comment.replies.length} resposta(s)
                                        </div>
                                        <p className="mt-1 line-clamp-2 text-sm text-[var(--ink-500)]">
                                            {comment.replies[comment.replies.length - 1].body}
                                        </p>
                                    </div>
                                )}
                                <div className="mt-2 flex flex-wrap gap-2 text-xs text-[var(--ink-500)]">
                                    <span className="flex items-center gap-1">
                                        <UserRound size={12} />
                                        Criado por {personName(comment.creator)}
                                    </span>
                                    <span>Responsável: {personName(comment.assignee)}</span>
                                </div>
                            </div>
                        ))}
                        {comments.length > 4 && (
                            <div className="text-xs font-semibold text-[var(--ink-500)]">
                                + {comments.length - 4} comentário(s). Abra a revisão em Comentários para ver todos.
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="mt-3 rounded-lg border border-dashed border-[var(--border)] bg-white p-3 text-sm text-[var(--ink-500)]">
                        Nenhum comentário visual registrado nesta revisão.
                    </div>
                )}
            </section>
        </article>
    );
}

function InfoCard({ label, value }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
            <div className="eyebrow">{label}</div>
            <div className="mt-1 text-sm font-semibold text-[var(--ink-900)]">{value || 'Nao informado'}</div>
        </div>
    );
}

function Narrative({ label, value }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
            <div className="eyebrow">{label}</div>
            <p className="mt-2 whitespace-pre-line text-sm leading-6 text-[var(--ink-700)]">
                {value || 'Nao informado'}
            </p>
        </div>
    );
}
