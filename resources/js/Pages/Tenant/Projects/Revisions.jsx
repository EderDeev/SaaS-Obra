import ProjectCapModal from '@/Components/ProjectCapModal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { ChevronDown, ClipboardList, Download, Eye, Filter, GitBranch, History, MessageSquare, Search, UserRound, X } from 'lucide-react';
import { useMemo, useState } from 'react';

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

function viewerUrl(tenant, version, workspace = 'view') {
    return `${route('tenant.projects.viewer', [tenant.slug, version.id])}?workspace=${workspace}`;
}

export default function ProjectRevisions({
    tenant,
    contracts,
    documents,
    documentTypes,
    statusLabels,
    capImpactLabels = {},
    canReviewProjects = false,
}) {
    const [contractFilter, setContractFilter] = useState('todos');
    const [query, setQuery] = useState('');
    const [capRow, setCapRow] = useState(null);
    const [historyRow, setHistoryRow] = useState(null);
    const [expandedRowIds, setExpandedRowIds] = useState([]);

    const rows = useMemo(() => documents.flatMap((document) => (
        (document.versions || []).filter((version) => version.cap_number).map((version) => ({
            id: `${document.id}-${version.id}`,
            document,
            version,
        }))
    )), [documents]);

    const filteredRows = useMemo(() => {
        const term = query.trim().toLowerCase();

        return rows.filter(({ document, version }) => {
            if (contractFilter !== 'todos' && String(document.contract_id) !== String(contractFilter)) {
                return false;
            }

            if (!term) {
                return true;
            }

            return `${version.cap_number || ''} ${document.title} ${document.code || ''} ${version.revision || ''} ${document.contract?.code || ''} ${document.obra?.nome || ''} ${document.disciplina?.nome || ''} ${document.phase?.name || ''}`
                .toLowerCase()
                .includes(term);
        });
    }, [rows, contractFilter, query]);

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
                        <div className="mt-1 text-lg font-semibold text-[var(--ink-900)]">{rows.length}</div>
                    </div>
                </header>

                <section className="projects-module-card sig-card overflow-hidden">
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
                                    {filteredRows.map(({ id, document, version }) => {
                                        const previousVersion = previousVersionFor(document, version);
                                        const latestVersion = latestVersionFor(document);
                                        const commentsCount = versionComments(version).length;

                                        return (
                                        <tr key={id}>
                                            <td>
                                                <div className="font-semibold">{version.cap_number}</div>
                                                <div className="mt-1 text-xs text-[var(--ink-500)]">{formatDateTime(version.cap_requested_at || version.created_at)}</div>
                                            </td>
                                            <td>
                                                <div className="font-semibold">{document.title}</div>
                                                <div className="mono mt-1 text-xs text-[var(--ink-500)]">{document.code || 'Sem EAP'}</div>
                                                <div className="mt-1 text-xs text-[var(--ink-500)]">{documentTypes[document.document_type] || document.document_type}</div>
                                            </td>
                                            <td>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {previousVersion ? (
                                                        <>
                                                            <span className="sig-pill sig-pill-muted">{previousVersion.revision}</span>
                                                            <GitBranch size={13} className="text-[var(--ink-400)]" />
                                                        </>
                                                    ) : (
                                                        <span className="text-xs text-[var(--ink-500)]">Sem revisão anterior</span>
                                                    )}
                                                    <span className="sig-pill sig-pill-blue font-semibold">{version.revision}</span>
                                                </div>
                                                <div className="mt-2 text-xs text-[var(--ink-500)]">
                                                    Atual: {latestVersion?.revision || version.revision}
                                                </div>
                                                <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                    {commentsCount} comentário(s) nesta revisão
                                                </div>
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
                                                    <button
                                                        type="button"
                                                        className="sig-btn sig-btn-primary sig-btn-sm"
                                                        onClick={() => setHistoryRow({ document, version })}
                                                    >
                                                        <History size={13} />
                                                        Histórico
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="sig-btn sig-btn-secondary sig-btn-sm"
                                                        onClick={() => setCapRow({ document, version })}
                                                    >
                                                        <Eye size={13} />
                                                        CAP
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <div className="projects-compact-only">
                            {filteredRows.map(({ id, document, version }) => {
                                const previousVersion = previousVersionFor(document, version);
                                const latestVersion = latestVersionFor(document);
                                const commentsCount = versionComments(version).length;
                                const expanded = expandedRowIds.includes(id);

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
                                                    <span className="text-sm font-semibold text-[var(--ink-900)]">{document.title}</span>
                                                    <span className="sig-pill sig-pill-amber">{version.cap_number}</span>
                                                    <span className={`sig-pill ${statusClasses[version.status] || 'sig-pill-blue'}`}>
                                                        {statusLabels[version.status] || version.status}
                                                    </span>
                                                </span>
                                                <span className="mono mt-1 block break-all text-xs text-[var(--ink-500)]">{document.code || 'Sem EAP'}</span>
                                                <span className="mt-3 grid gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-4">
                                                    <CompactInfo label="Contrato" value={`${document.contract?.code || '-'} - ${document.contract?.name || 'Sem contrato'}`} />
                                                    <CompactInfo label="Obra" value={`${document.obra?.codigo || '-'} - ${document.obra?.nome || 'Sem obra'}`} />
                                                    <CompactInfo label="Disciplina" value={`${document.disciplina?.sigla || '-'} - ${document.disciplina?.nome || 'Sem disciplina'}`} />
                                                    <CompactInfo label="Revisao" value={`${previousVersion?.revision || 'Inicial'} -> ${version.revision}`} />
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
                                                    <CompactInfo label="Comentarios nesta revisao" value={`${commentsCount} comentario(s)`} />
                                                </div>
                                                <div className="mt-4 flex flex-wrap gap-2 border-t border-[var(--border)] pt-4">
                                                    <button type="button" className="sig-btn sig-btn-primary sig-btn-sm" onClick={() => setHistoryRow({ document, version })}>
                                                        <History size={13} />
                                                        Historico
                                                    </button>
                                                    <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" onClick={() => setCapRow({ document, version })}>
                                                        <Eye size={13} />
                                                        CAP
                                                    </button>
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
                            {rows.length === 0 ? 'Nenhum projeto revisado com CAP ainda.' : 'Nenhuma CAP encontrada para os filtros selecionados.'}
                        </div>
                    )}
                </section>
            </section>

            {capRow && (
                <ProjectCapModal
                    document={capRow.document}
                    version={capRow.version}
                    capImpactLabels={capImpactLabels}
                    onClose={() => setCapRow(null)}
                />
            )}
            {historyRow && (
                <RevisionHistoryModal
                    tenant={tenant}
                    document={historyRow.document}
                    currentVersion={historyRow.version}
                    statusLabels={statusLabels}
                    capImpactLabels={capImpactLabels}
                    canReviewProjects={canReviewProjects}
                    onCap={(version) => setCapRow({ document: historyRow.document, version })}
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

function RevisionHistoryModal({ tenant, document, currentVersion, statusLabels, capImpactLabels, canReviewProjects, onCap, onClose }) {
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
                        <h2 id="project-revision-history-title" className="mt-1 truncate text-[17px] font-semibold text-[var(--ink-900)]">
                            {document.title}
                        </h2>
                        <p className="mono mt-1 text-[12.5px] text-[var(--ink-500)]">{document.code || 'Sem EAP'}</p>
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
                                onCap={onCap}
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

function RevisionHistoryItem({ tenant, document, version, active, latest, statusLabels, capImpactLabels, canReviewProjects, onCap }) {
    const comments = versionComments(version);
    const checklistItems = version.review_checklist?.items || [];
    const checkedItems = checklistItems.filter((item) => item.checked).length;
    const impacts = Array.isArray(version.cap_impacts) ? version.cap_impacts : [];
    const canOpenViewer = version.aps_urn && (version.status === 'ativo' || canReviewProjects);

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
                    <div className="mt-2 text-sm font-semibold text-[var(--ink-900)]">{fileDisplayName(version)}</div>
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
                        <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" onClick={() => onCap(version)}>
                            <ClipboardList size={13} />
                            CAP
                        </button>
                    )}
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
