import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Download, Eye, FileText, Filter, Folder, FolderOpen, GitBranch, MessageSquare, Search, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';

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

function approvedDate(document) {
    const approvedAt = document.latest_approved_version?.approved_at || document.approved_at;

    if (!approvedAt) {
        return 'Sem data';
    }

    return new Date(approvedAt).toLocaleDateString('pt-BR');
}

function fileDisplayName(version) {
    return version?.stored_name || version?.original_name || '';
}

function viewerWorkspaceUrl(tenant, version, workspace) {
    return `${route('tenant.projects.viewer', [tenant.slug, version.id])}?workspace=${workspace}`;
}

function isApsWaiting(version) {
    return ['queued', 'processing'].includes(version?.derivative_status);
}

export default function ProjectTree({ tenant, contracts, obras, disciplinas, documents, documentTypes }) {
    const [contractFilter, setContractFilter] = useState('todos');
    const [obraFilter, setObraFilter] = useState('todos');
    const [disciplinaFilter, setDisciplinaFilter] = useState('todos');
    const [query, setQuery] = useState('');
    const [openNodes, setOpenNodes] = useState(() => new Set());

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

            if (!term) {
                return true;
            }

            const version = document.latest_approved_version || document.latest_version;

            return `${document.title} ${document.code || ''} ${fileDisplayName(version)} ${version?.original_name || ''} ${document.contract?.code || ''} ${document.obra?.codigo || ''} ${document.obra?.nome || ''} ${document.disciplina?.sigla || ''} ${document.disciplina?.nome || ''} ${document.phase?.code || ''} ${document.phase?.name || ''}`
                .toLowerCase()
                .includes(term);
        });
    }, [documents, contractFilter, obraFilter, disciplinaFilter, query]);

    const tree = useMemo(() => buildTree(filteredDocuments, documentTypes), [filteredDocuments, documentTypes]);
    const expandableNodeIds = useMemo(() => collectExpandableNodeIds(tree), [tree]);

    const updateContractFilter = (contractId) => {
        setContractFilter(contractId);
        setObraFilter('todos');
        setDisciplinaFilter('todos');
        setOpenNodes(new Set());
    };

    const toggleNode = (nodeId) => {
        setOpenNodes((current) => {
            const next = new Set(current);

            if (next.has(nodeId)) {
                next.delete(nodeId);
            } else {
                next.add(nodeId);
            }

            return next;
        });
    };

    const processVersion = (version) => {
        router.post(route('tenant.projects.process-aps', [tenant.slug, version.id]), {}, {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Visualizar projetos" />

            <section className="sig-content grid gap-5">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <GitBranch size={15} />
                            <span className="eyebrow">Projetos</span>
                        </div>
                        <h1 className="mt-1 text-xl font-semibold text-[var(--ink-900)]">Visualizar projetos</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Arvore principal com os documentos aprovados por contrato, obra, disciplina, fase e tipo.
                        </p>
                    </div>

                    <Link href={route('tenant.projects.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                        <Upload size={15} />
                        Submeter projeto
                    </Link>
                </header>

                <div className="grid gap-3 md:grid-cols-4">
                    <Metric label="Projetos aprovados" value={documents.length} />
                    <Metric label="Contratos" value={contracts.length} />
                    <Metric label="Obras" value={obras.length} />
                    <Metric label="Disciplinas" value={disciplinas.length} />
                </div>

                <section className="sig-card overflow-hidden">
                    <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 xl:grid-cols-4">
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

                        <label>
                            <span className="eyebrow mb-1 flex items-center gap-1">
                                <Search size={12} />
                                Busca
                            </span>
                            <span className="sig-input bg-white">
                                <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar na arvore" />
                            </span>
                        </label>
                    </div>

                    {tree.length > 0 ? (
                        <div>
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-3">
                                <div className="text-sm font-semibold text-[var(--ink-700)]">
                                    {filteredDocuments.length} projeto(s) aprovado(s) na arvore
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        className="sig-btn sig-btn-secondary sig-btn-sm"
                                        onClick={() => setOpenNodes(new Set(expandableNodeIds))}
                                    >
                                        Expandir tudo
                                    </button>
                                    <button
                                        type="button"
                                        className="sig-btn sig-btn-secondary sig-btn-sm"
                                        onClick={() => setOpenNodes(new Set())}
                                    >
                                        Recolher tudo
                                    </button>
                                </div>
                            </div>

                            <div className="overflow-x-auto bg-white px-3 py-3">
                                <div className="min-w-[980px]">
                                    {tree.map((node) => (
                                        <TreeNode
                                            key={node.id}
                                            node={node}
                                            level={0}
                                            openNodes={openNodes}
                                            toggleNode={toggleNode}
                                            processVersion={processVersion}
                                            tenant={tenant}
                                        />
                                    ))}
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="p-12 text-center text-sm text-[var(--ink-500)]">
                            Nenhum projeto aprovado encontrado para os filtros selecionados.
                        </div>
                    )}
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function buildTree(documents, documentTypes) {
    const contracts = new Map();

    documents.forEach((document) => {
        const contractKey = document.contract?.id || document.contract_id || 'sem-contrato';
        const obraKey = document.obra?.id || document.obra_id || 'sem-obra';
        const disciplinaKey = document.disciplina?.id || document.disciplina_id || 'sem-disciplina';
        const phaseKey = document.phase?.id || document.project_phase_id || 'sem-fase';
        const typeKey = document.document_type || 'outro';
        if (!contracts.has(contractKey)) {
            contracts.set(contractKey, {
                id: `contract:${contractKey}`,
                type: 'contract',
                label: document.contract?.code || 'Sem contrato',
                description: document.contract?.name || 'Contrato nao informado',
                obras: new Map(),
            });
        }

        const contract = contracts.get(contractKey);

        if (!contract.obras.has(obraKey)) {
            contract.obras.set(obraKey, {
                id: `obra:${contractKey}:${obraKey}`,
                type: 'obra',
                label: document.obra?.codigo || 'S/C',
                description: document.obra?.nome || 'Sem obra',
                disciplinas: new Map(),
            });
        }

        const obra = contract.obras.get(obraKey);

        if (!obra.disciplinas.has(disciplinaKey)) {
            obra.disciplinas.set(disciplinaKey, {
                id: `disciplina:${contractKey}:${obraKey}:${disciplinaKey}`,
                type: 'disciplina',
                label: document.disciplina?.sigla || 'S/D',
                description: document.disciplina?.nome || 'Sem disciplina',
                cor: document.disciplina?.cor || '#2563eb',
                phases: new Map(),
            });
        }

        const disciplina = obra.disciplinas.get(disciplinaKey);

        if (!disciplina.phases.has(phaseKey)) {
            disciplina.phases.set(phaseKey, {
                id: `phase:${contractKey}:${obraKey}:${disciplinaKey}:${phaseKey}`,
                type: 'phase',
                label: document.phase?.code || 'S/F',
                description: document.phase?.name || 'Sem fase',
                documentTypes: new Map(),
            });
        }

        const phase = disciplina.phases.get(phaseKey);

        if (!phase.documentTypes.has(typeKey)) {
            phase.documentTypes.set(typeKey, {
                id: `document-type:${contractKey}:${obraKey}:${disciplinaKey}:${phaseKey}:${typeKey}`,
                type: 'documentType',
                label: documentTypes[typeKey] || typeKey,
                description: 'Tipo de documento',
                documents: [],
            });
        }

        phase.documentTypes.get(typeKey).documents.push({
            id: `document:${document.id}`,
            type: 'document',
            label: document.title,
            description: document.code || 'Sem codigo',
            document,
        });
    });

    return Array.from(contracts.values()).map((contract) => normalizeNode({
        ...contract,
        children: Array.from(contract.obras.values()).map((obra) => ({
            ...obra,
            children: Array.from(obra.disciplinas.values()).map((disciplina) => ({
                ...disciplina,
                children: Array.from(disciplina.phases.values()).map((phase) => ({
                    ...phase,
                    children: Array.from(phase.documentTypes.values()).map((documentType) => ({
                        ...documentType,
                        children: documentType.documents,
                    })),
                })),
            })),
        })),
    }));
}

function normalizeNode(node) {
    const children = (node.children || []).map(normalizeNode);
    const count = node.type === 'document'
        ? 1
        : children.reduce((total, child) => total + child.count, 0);

    return {
        ...node,
        children,
        count,
    };
}

function collectExpandableNodeIds(nodes) {
    return nodes.flatMap((node) => [
        ...(node.children?.length ? [node.id] : []),
        ...collectExpandableNodeIds(node.children || []),
    ]);
}

function TreeNode({ node, level, openNodes, toggleNode, tenant, processVersion }) {
    const hasChildren = node.children?.length > 0;
    const isOpen = openNodes.has(node.id);
    const isDocument = node.type === 'document';
    const document = node.document;
    const version = document?.latest_approved_version || document?.latest_version;
    const indent = level * 28 + 8;
    const connectorLeft = (level - 1) * 28 + 18;

    return (
        <div>
            <div
                className={`group relative flex min-h-11 items-center gap-2 rounded-md pr-3 text-sm transition ${isDocument ? 'hover:bg-[var(--surface-muted)]' : 'hover:bg-[var(--primary-50)]'}`}
                style={{ paddingLeft: `${indent}px` }}
            >
                {level > 0 && (
                    <span
                        className="absolute top-0 h-full border-l border-[var(--border-strong)]"
                        style={{ left: `${connectorLeft}px` }}
                    />
                )}
                {level > 0 && (
                    <span
                        className="absolute top-1/2 w-5 border-t border-[var(--border-strong)]"
                        style={{ left: `${connectorLeft}px` }}
                    />
                )}

                {hasChildren ? (
                    <button
                        type="button"
                        className="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-[var(--border)] bg-white text-[var(--ink-600)] group-hover:border-[var(--primary)] group-hover:text-[var(--primary)]"
                        onClick={() => toggleNode(node.id)}
                        title={isOpen ? 'Recolher' : 'Expandir'}
                    >
                        <ChevronRight size={15} className={`transition-transform ${isOpen ? 'rotate-90' : ''}`} />
                    </button>
                ) : (
                    <span className="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-[var(--border)] bg-white text-[var(--ink-500)]">
                        <FileText size={15} />
                    </span>
                )}

                <button
                    type="button"
                    className={`relative z-10 flex min-w-0 flex-1 items-center gap-2 py-2 text-left ${hasChildren ? '' : 'cursor-default'}`}
                    onClick={() => hasChildren && toggleNode(node.id)}
                >
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-[var(--surface-muted)] text-[var(--ink-600)]">
                        {node.type === 'disciplina' ? (
                            <span className="h-3.5 w-3.5 rounded-full border border-[var(--border)]" style={{ backgroundColor: node.cor }} />
                        ) : hasChildren ? (
                            isOpen ? <FolderOpen size={16} /> : <Folder size={16} />
                        ) : (
                            <FileText size={16} />
                        )}
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="flex min-w-0 flex-wrap items-center gap-2">
                            <span className={`${node.type === 'contract' ? 'text-[15px]' : 'text-sm'} truncate font-semibold text-[var(--ink-900)]`}>
                                {node.label}
                            </span>
                            {node.description && (
                                <span className="truncate text-[12.5px] text-[var(--ink-500)]">
                                    {node.description}
                                </span>
                            )}
                        </span>
                        {isDocument && (
                            <span className="mt-1 flex flex-wrap items-center gap-2 text-[12px] text-[var(--ink-500)]">
                                <span>Revisao {version?.revision || 'sem revisao'}</span>
                                <span>Aprovado em {approvedDate(document)}</span>
                                <span>{derivativeLabels[version?.derivative_status] || version?.derivative_status || 'Sem APS'}</span>
                            </span>
                        )}
                    </span>
                </button>

                <div className="relative z-10 flex shrink-0 items-center gap-2">
                    {!isDocument && (
                        <span className="sig-pill sig-pill-green">{node.count} projeto(s)</span>
                    )}
                    {isDocument && version?.aps_urn && (
                        <Link href={viewerWorkspaceUrl(tenant, version, 'view')} className="sig-btn sig-btn-primary sig-btn-sm">
                            <Eye size={13} />
                            Visualizar
                        </Link>
                    )}
                    {isDocument && version?.aps_urn && (
                        <Link href={viewerWorkspaceUrl(tenant, version, 'comments')} className="sig-btn sig-btn-secondary sig-btn-sm">
                            <MessageSquare size={13} />
                            Comentários
                        </Link>
                    )}
                    {isDocument && version && !version.aps_urn && isApsWaiting(version) && (
                        <span className="sig-pill bg-[var(--surface-muted)] text-[var(--ink-600)]">
                            Processando APS
                        </span>
                    )}
                    {isDocument && version && !version.aps_urn && !isApsWaiting(version) && (
                        <button type="button" onClick={() => processVersion(version)} className="sig-btn sig-btn-primary sig-btn-sm">
                            <Eye size={13} />
                            Processar APS
                        </button>
                    )}
                    {isDocument && version?.url && (
                        <a href={version.url} download={fileDisplayName(version)} className="sig-btn sig-btn-secondary sig-btn-sm">
                            <Download size={13} />
                            Baixar
                        </a>
                    )}
                </div>
            </div>

            {hasChildren && isOpen && (
                <div>
                    {node.children.map((child) => (
                        <TreeNode
                            key={child.id}
                            node={child}
                            level={level + 1}
                            openNodes={openNodes}
                            toggleNode={toggleNode}
                            tenant={tenant}
                            processVersion={processVersion}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

function Metric({ label, value }) {
    return (
        <div className="sig-card p-4">
            <div className="eyebrow">{label}</div>
            <div className="mt-3 inline-flex h-10 min-w-10 items-center justify-center rounded-lg bg-[var(--green-50)] px-3 text-lg font-semibold text-[var(--green)]">
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
