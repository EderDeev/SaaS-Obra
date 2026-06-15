import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { FileSpreadsheet, FileText, Filter, ListChecks, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';

const statusClasses = {
    em_analise: 'sig-pill-blue',
    em_aprovacao: 'sig-pill-amber',
    ativo: 'sig-pill-green',
    inativo: 'sig-pill-red',
    reprovado: 'sig-pill-red',
};

function contractLabel(contract) {
    return `${contract.code} - ${contract.name}`;
}

function cleanFilters(filters) {
    return Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== undefined && value !== null && value !== '' && value !== 'todos'),
    );
}

function exportUrl(tenant, routeName, filters) {
    const params = new URLSearchParams(cleanFilters(filters));
    const query = params.toString();

    return `${route(routeName, tenant.slug)}${query ? `?${query}` : ''}`;
}

export default function ProjectMasterList({
    tenant,
    contracts,
    obras,
    disciplinas,
    projectPhases,
    documents,
    documentTypes,
    statusLabels,
    filters,
    totalDocuments,
}) {
    const pagination = documents?.data ? documents : null;
    const rows = pagination?.data ?? [];
    const [filterState, setFilterState] = useState({
        contract_id: filters.contract_id || 'todos',
        obra_id: filters.obra_id || 'todos',
        disciplina_id: filters.disciplina_id || 'todos',
        project_phase_id: filters.project_phase_id || 'todos',
        document_type: filters.document_type || 'todos',
        status: filters.status || 'todos',
        q: filters.q || '',
    });

    const obrasForFilter = useMemo(
        () => filterState.contract_id === 'todos'
            ? obras
            : obras.filter((obra) => String(obra.contract_id) === String(filterState.contract_id)),
        [obras, filterState.contract_id],
    );

    const disciplinasForFilter = useMemo(
        () => filterState.contract_id === 'todos'
            ? disciplinas
            : disciplinas.filter((disciplina) => String(disciplina.contract_id) === String(filterState.contract_id)),
        [disciplinas, filterState.contract_id],
    );

    const appliedFilters = useMemo(() => cleanFilters(filters), [filters]);
    const hasAppliedFilters = Object.keys(appliedFilters).length > 0;

    const updateFilter = (key, value) => {
        setFilterState((current) => {
            const next = { ...current, [key]: value };

            if (key === 'contract_id') {
                next.obra_id = 'todos';
                next.disciplina_id = 'todos';
            }

            return next;
        });
    };

    const search = (event) => {
        event.preventDefault();
        router.get(route('tenant.projects.master-list.index', tenant.slug), cleanFilters(filterState), {
            preserveScroll: true,
        });
    };

    const clearFilters = () => {
        router.get(route('tenant.projects.master-list.index', tenant.slug), {}, {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Lista Mestra de Projetos" />

            <section className="sig-content grid gap-5">
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <ListChecks size={15} />
                            <span className="eyebrow">Projetos</span>
                        </div>
                        <h1 className="mt-1 text-xl font-semibold text-[var(--ink-900)]">Lista Mestra</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Relação controlada dos projetos por contrato, obra, disciplina, fase, tipo e revisão.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <a
                            href={exportUrl(tenant, 'tenant.projects.master-list.pdf', filters)}
                            className="sig-btn sig-btn-secondary"
                        >
                            <FileText size={15} />
                            Baixar PDF
                        </a>
                        <a
                            href={exportUrl(tenant, 'tenant.projects.master-list.excel', filters)}
                            className="sig-btn sig-btn-primary"
                        >
                            <FileSpreadsheet size={15} />
                            Baixar Excel
                        </a>
                    </div>
                </header>

                <section className="sig-card overflow-hidden">
                    <form onSubmit={search}>
                        <div className="flex items-center gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                            <span className="grid h-10 w-10 place-items-center rounded-lg bg-white text-[var(--primary)] shadow-sm">
                                <Filter size={18} />
                            </span>
                            <div>
                                <h2 className="text-sm font-semibold text-[var(--ink-900)]">Filtros da lista</h2>
                                <p className="text-sm text-[var(--ink-500)]">Refine a Lista Mestra antes de gerar PDF ou Excel.</p>
                            </div>
                        </div>

                        <div className="grid gap-3 px-5 py-4 md:grid-cols-2 xl:grid-cols-4">
                            <FilterSelect label="Contrato" value={filterState.contract_id} onChange={(value) => updateFilter('contract_id', value)}>
                                <option value="todos">Todos os contratos</option>
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>{contractLabel(contract)}</option>
                                ))}
                            </FilterSelect>

                            <FilterSelect label="Obra" value={filterState.obra_id} onChange={(value) => updateFilter('obra_id', value)}>
                                <option value="todos">Todas as obras</option>
                                {obrasForFilter.map((obra) => (
                                    <option key={obra.id} value={obra.id}>{obra.codigo} - {obra.nome}</option>
                                ))}
                            </FilterSelect>

                            <FilterSelect label="Disciplina" value={filterState.disciplina_id} onChange={(value) => updateFilter('disciplina_id', value)}>
                                <option value="todos">Todas as disciplinas</option>
                                {disciplinasForFilter.map((disciplina) => (
                                    <option key={disciplina.id} value={disciplina.id}>{disciplina.sigla} - {disciplina.nome}</option>
                                ))}
                            </FilterSelect>

                            <FilterSelect label="Fase" value={filterState.project_phase_id} onChange={(value) => updateFilter('project_phase_id', value)}>
                                <option value="todos">Todas as fases</option>
                                {projectPhases.map((phase) => (
                                    <option key={phase.id} value={phase.id}>{phase.code} - {phase.name}</option>
                                ))}
                            </FilterSelect>

                            <FilterSelect label="Tipo" value={filterState.document_type} onChange={(value) => updateFilter('document_type', value)}>
                                <option value="todos">Todos os tipos</option>
                                {Object.entries(documentTypes).map(([key, label]) => (
                                    <option key={key} value={key}>{label}</option>
                                ))}
                            </FilterSelect>

                            <FilterSelect label="Status" value={filterState.status} onChange={(value) => updateFilter('status', value)}>
                                <option value="todos">Todos os status</option>
                                {Object.entries(statusLabels).map(([key, label]) => (
                                    <option key={key} value={key}>{label}</option>
                                ))}
                            </FilterSelect>

                            <label className="md:col-span-2">
                                <span className="eyebrow mb-1 flex items-center gap-1">
                                    <Search size={12} />
                                    Busca
                                </span>
                                <span className="sig-input bg-white">
                                    <input
                                        value={filterState.q}
                                        onChange={(event) => updateFilter('q', event.target.value)}
                                        placeholder="Código, título, arquivo, obra ou disciplina"
                                    />
                                </span>
                            </label>
                        </div>

                        <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] px-5 py-4">
                            {hasAppliedFilters && (
                                <button type="button" className="sig-btn sig-btn-secondary" onClick={clearFilters}>
                                    <X size={15} />
                                    Limpar
                                </button>
                            )}
                            <button type="submit" className="sig-btn sig-btn-primary">
                                <Search size={15} />
                                Buscar
                            </button>
                        </div>
                    </form>
                </section>

                <section className="sig-card overflow-hidden">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <h2 className="text-base font-semibold text-[var(--ink-900)]">Projetos encontrados</h2>
                            <p className="text-sm text-[var(--ink-500)]">
                                {pagination?.total ?? rows.length} resultado(s) filtrado(s) de {totalDocuments} documento(s) acessível(is).
                            </p>
                        </div>
                        {pagination && (
                            <span className="sig-pill sig-pill-blue">
                                Página {pagination.current_page} de {pagination.last_page}
                            </span>
                        )}
                    </div>

                    {rows.length > 0 ? (
                        <>
                            <div className="projects-wide-only overflow-x-auto">
                                <table className="sig-table min-w-[1180px]">
                                    <thead>
                                        <tr>
                                            <th>Código</th>
                                            <th>Documento</th>
                                            <th>Contrato</th>
                                            <th>Obra</th>
                                            <th>Disciplina</th>
                                            <th>Fase</th>
                                            <th>Tipo</th>
                                            <th>Revisão</th>
                                            <th>Status</th>
                                            <th>Arquivo</th>
                                            <th>Datas</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map((document) => (
                                            <tr key={document.id}>
                                                <td className="mono text-xs font-semibold text-[var(--primary)]">{document.code || '-'}</td>
                                                <td>
                                                    <div className="font-semibold text-[var(--ink-900)]">{document.title || 'Sem título'}</div>
                                                    <div className="text-xs text-[var(--ink-500)]">Sequencial {document.document_number || '-'}</div>
                                                </td>
                                                <td>
                                                    <div className="mono text-xs">{document.contract?.code || '-'}</div>
                                                    <div className="text-xs text-[var(--ink-500)]">{document.contract?.name || 'Sem contrato'}</div>
                                                </td>
                                                <td>
                                                    <div className="mono text-xs">{document.obra?.codigo || '-'}</div>
                                                    <div className="text-xs text-[var(--ink-500)]">{document.obra?.nome || 'Sem obra'}</div>
                                                </td>
                                                <td>
                                                    <span className="inline-flex items-center gap-2 text-sm font-semibold text-[var(--ink-700)]">
                                                        <span className="h-3.5 w-3.5 rounded-full border border-[var(--border)]" style={{ backgroundColor: document.disciplina?.cor || '#2563eb' }} />
                                                        {document.disciplina?.sigla || '-'}
                                                    </span>
                                                    <div className="text-xs text-[var(--ink-500)]">{document.disciplina?.nome || 'Sem disciplina'}</div>
                                                </td>
                                                <td>
                                                    <div className="mono text-xs">{document.phase?.code || '-'}</div>
                                                    <div className="text-xs text-[var(--ink-500)]">{document.phase?.name || 'Sem fase'}</div>
                                                </td>
                                                <td>{document.document_type_label || '-'}</td>
                                                <td>
                                                    <span className="sig-pill sig-pill-blue font-semibold">{document.revision || 'Sem revisão'}</span>
                                                </td>
                                                <td>
                                                    <span className={`sig-pill ${statusClasses[document.status] || 'sig-pill-blue'}`}>
                                                        {document.status_label || document.status}
                                                    </span>
                                                    {document.open_rncs_count > 0 && (
                                                        <div className="mt-1 text-xs font-semibold text-[var(--red)]">
                                                            {document.open_rncs_count} RNC aberta(s)
                                                        </div>
                                                    )}
                                                </td>
                                                <td>
                                                    <div className="max-w-[220px] truncate text-sm font-semibold">{document.file_name || 'Sem arquivo'}</div>
                                                    <div className="text-xs text-[var(--ink-500)]">{document.file_size || '-'}</div>
                                                </td>
                                                <td>
                                                    <DateStack document={document} />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <div className="projects-compact-only divide-y divide-[var(--border)]">
                                {rows.map((document) => (
                                    <article key={document.id} className="px-5 py-4">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="mono break-all text-sm font-semibold text-[var(--primary)]">{document.code || '-'}</span>
                                            <span className="sig-pill sig-pill-blue">{document.revision || 'Sem revisão'}</span>
                                            <span className={`sig-pill ${statusClasses[document.status] || 'sig-pill-blue'}`}>
                                                {document.status_label || document.status}
                                            </span>
                                        </div>
                                        <h3 className="mt-2 text-sm font-semibold text-[var(--ink-900)]">{document.title || 'Sem título'}</h3>
                                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                            <CompactInfo label="Contrato" value={`${document.contract?.code || '-'} - ${document.contract?.name || 'Sem contrato'}`} />
                                            <CompactInfo label="Obra" value={`${document.obra?.codigo || '-'} - ${document.obra?.nome || 'Sem obra'}`} />
                                            <CompactInfo label="Disciplina" value={`${document.disciplina?.sigla || '-'} - ${document.disciplina?.nome || 'Sem disciplina'}`} />
                                            <CompactInfo label="Fase" value={document.phase ? `${document.phase.code} - ${document.phase.name}` : 'Sem fase'} />
                                            <CompactInfo label="Tipo" value={document.document_type_label || '-'} />
                                            <CompactInfo label="Arquivo" value={document.file_name || 'Sem arquivo'} />
                                        </div>
                                    </article>
                                ))}
                            </div>

                            {pagination && <Pagination pagination={pagination} />}
                        </>
                    ) : (
                        <div className="px-5 py-12 text-center text-sm text-[var(--ink-500)]">
                            Nenhum projeto encontrado para os filtros selecionados.
                        </div>
                    )}
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function DateStack({ document }) {
    return (
        <div className="space-y-1 text-xs text-[var(--ink-500)]">
            <div><span className="font-semibold text-[var(--ink-700)]">Criado:</span> {document.created_at || '-'}</div>
            <div><span className="font-semibold text-[var(--ink-700)]">Análise:</span> {document.reviewed_at || '-'}</div>
            <div><span className="font-semibold text-[var(--ink-700)]">Aprovação:</span> {document.approved_at || '-'}</div>
        </div>
    );
}

function FilterSelect({ label, value, onChange, children }) {
    return (
        <label>
            <span className="eyebrow mb-1 block">{label}</span>
            <select className="sig-input w-full bg-white" value={value} onChange={(event) => onChange(event.target.value)}>
                {children}
            </select>
        </label>
    );
}

function CompactInfo({ label, value }) {
    return (
        <div>
            <div className="eyebrow text-[10px]">{label}</div>
            <div className="mt-1 text-sm text-[var(--ink-700)]">{value}</div>
        </div>
    );
}

function Pagination({ pagination }) {
    const goTo = (url) => {
        if (!url) {
            return;
        }

        router.visit(url, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border)] px-5 py-4">
            <div className="text-sm text-[var(--ink-500)]">
                Exibindo {pagination.from ?? 0} a {pagination.to ?? 0} de {pagination.total ?? 0} resultado(s).
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    className={`sig-btn sig-btn-secondary sig-btn-sm ${!pagination.prev_page_url ? 'cursor-not-allowed opacity-45' : ''}`}
                    disabled={!pagination.prev_page_url}
                    onClick={() => goTo(pagination.prev_page_url)}
                >
                    Anterior
                </button>
                <button
                    type="button"
                    className={`sig-btn sig-btn-primary sig-btn-sm ${!pagination.next_page_url ? 'cursor-not-allowed opacity-45' : ''}`}
                    disabled={!pagination.next_page_url}
                    onClick={() => goTo(pagination.next_page_url)}
                >
                    Próxima
                </button>
            </div>
        </div>
    );
}
