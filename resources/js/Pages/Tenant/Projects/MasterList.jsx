import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { Check, ChevronDown, FileSpreadsheet, FileText, Filter, ListChecks, ListFilter, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

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
        Object.entries(filters).filter(([, value]) => {
            if (Array.isArray(value)) return value.length > 0;

            return value !== undefined && value !== null && value !== '' && value !== 'todos';
        }),
    );
}

function normalizeFilterArray(value) {
    if (Array.isArray(value)) {
        return value.filter((item) => item !== undefined && item !== null && item !== '' && item !== 'todos').map(String);
    }

    return value && value !== 'todos' ? [String(value)] : [];
}

function filterSignature(filters) {
    return JSON.stringify(Object.fromEntries(
        Object.entries(cleanFilters(filters)).map(([key, value]) => [
            key,
            Array.isArray(value) ? [...value].map(String).sort() : String(value).trim(),
        ]),
    ));
}

function exportUrl(tenant, routeName, filters) {
    const params = new URLSearchParams();

    Object.entries(cleanFilters(filters)).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            value.forEach((item) => params.append(`${key}[]`, item));
            return;
        }

        params.set(key, value);
    });
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
    filtersApplied,
    totalDocuments,
}) {
    useEffect(() => {
        const navigation = window.performance.getEntriesByType('navigation')[0];

        if (navigation?.type === 'reload' && window.location.search) {
            window.location.replace(window.location.pathname);
        }
    }, []);

    const pagination = documents?.data ? documents : null;
    const rows = pagination?.data ?? [];
    const [filterState, setFilterState] = useState({
        contract_ids: normalizeFilterArray(filters.contract_ids),
        obra_ids: normalizeFilterArray(filters.obra_ids),
        disciplina_ids: normalizeFilterArray(filters.disciplina_ids),
        project_phase_ids: normalizeFilterArray(filters.project_phase_ids),
        document_types: normalizeFilterArray(filters.document_types),
        statuses: normalizeFilterArray(filters.statuses),
    });

    const obrasForFilter = useMemo(
        () => filterState.contract_ids.length === 0
            ? obras
            : obras.filter((obra) => filterState.contract_ids.includes(String(obra.contract_id))),
        [obras, filterState.contract_ids],
    );

    const disciplinasForFilter = useMemo(
        () => filterState.contract_ids.length === 0
            ? disciplinas
            : disciplinas.filter((disciplina) => filterState.contract_ids.includes(String(disciplina.contract_id))),
        [disciplinas, filterState.contract_ids],
    );

    const appliedFilters = useMemo(() => cleanFilters(filters), [filters]);
    const hasAppliedFilters = Object.keys(appliedFilters).length > 0;
    const filtersAreDirty = filterSignature(filterState) !== filterSignature(filters);

    const toggleFilter = (key, value) => {
        setFilterState((current) => {
            const normalizedValue = String(value);
            const currentValues = current[key] || [];
            const nextValues = currentValues.includes(normalizedValue)
                ? currentValues.filter((item) => item !== normalizedValue)
                : [...currentValues, normalizedValue];
            const next = { ...current, [key]: nextValues };

            if (key === 'contract_ids' && nextValues.length > 0) {
                next.obra_ids = current.obra_ids.filter((obraId) => obras.some(
                    (obra) => String(obra.id) === String(obraId) && nextValues.includes(String(obra.contract_id)),
                ));
                next.disciplina_ids = current.disciplina_ids.filter((disciplinaId) => disciplinas.some(
                    (disciplina) => String(disciplina.id) === String(disciplinaId) && nextValues.includes(String(disciplina.contract_id)),
                ));
            }

            return next;
        });
    };

    const clearFilter = (key) => setFilterState((current) => ({ ...current, [key]: [] }));

    const search = (event) => {
        event.preventDefault();
        router.get(route('tenant.projects.master-list.index', tenant.slug), {
            ...cleanFilters(filterState),
            applied: 1,
        }, {
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

                </header>

                <section className="sig-card overflow-visible">
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

                        <div className="grid gap-3 px-5 py-4 md:grid-cols-2 xl:grid-cols-3">
                            <MultiFilter
                                label="Contrato"
                                allLabel="Todos os contratos"
                                options={contracts.map((contract) => ({ value: String(contract.id), label: contractLabel(contract) }))}
                                selected={filterState.contract_ids}
                                onToggle={(value) => toggleFilter('contract_ids', value)}
                                onClear={() => clearFilter('contract_ids')}
                            />

                            <MultiFilter
                                label="Obra"
                                allLabel="Todas as obras"
                                options={obrasForFilter.map((obra) => ({ value: String(obra.id), label: `${obra.codigo} - ${obra.nome}` }))}
                                selected={filterState.obra_ids}
                                onToggle={(value) => toggleFilter('obra_ids', value)}
                                onClear={() => clearFilter('obra_ids')}
                            />

                            <MultiFilter
                                label="Disciplina"
                                allLabel="Todas as disciplinas"
                                options={disciplinasForFilter.map((disciplina) => ({
                                    value: String(disciplina.id),
                                    label: `${disciplina.sigla} - ${disciplina.nome}`,
                                    color: disciplina.cor,
                                }))}
                                selected={filterState.disciplina_ids}
                                onToggle={(value) => toggleFilter('disciplina_ids', value)}
                                onClear={() => clearFilter('disciplina_ids')}
                            />

                            <MultiFilter
                                label="Fase"
                                allLabel="Todas as fases"
                                options={projectPhases.map((phase) => ({ value: String(phase.id), label: `${phase.code} - ${phase.name}` }))}
                                selected={filterState.project_phase_ids}
                                onToggle={(value) => toggleFilter('project_phase_ids', value)}
                                onClear={() => clearFilter('project_phase_ids')}
                            />

                            <MultiFilter
                                label="Tipo"
                                allLabel="Todos os tipos"
                                options={Object.entries(documentTypes).map(([value, label]) => ({ value, label }))}
                                selected={filterState.document_types}
                                onToggle={(value) => toggleFilter('document_types', value)}
                                onClear={() => clearFilter('document_types')}
                            />

                            <MultiFilter
                                label="Status"
                                allLabel="Todos os status"
                                options={Object.entries(statusLabels).map(([value, label]) => ({ value, label }))}
                                selected={filterState.statuses}
                                onToggle={(value) => toggleFilter('statuses', value)}
                                onClear={() => clearFilter('statuses')}
                            />

                        </div>

                        <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] px-5 py-4">
                            {(filtersApplied || hasAppliedFilters) && (
                                <button type="button" className="sig-btn sig-btn-secondary" onClick={clearFilters}>
                                    <X size={15} />
                                    Limpar
                                </button>
                            )}
                            <button type="submit" className="sig-btn sig-btn-primary">
                                <ListFilter size={15} />
                                Gerar Lista
                            </button>
                        </div>
                    </form>
                </section>

                <section className="sig-card projects-list-card overflow-hidden">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-4 py-3">
                        <div>
                            <h2 className="text-base font-semibold text-[var(--ink-900)]">Projetos encontrados</h2>
                            <p className="text-sm text-[var(--ink-500)]">
                                {filtersApplied
                                    ? `${pagination?.total ?? rows.length} resultado(s) filtrado(s) de ${totalDocuments} documento(s) acessível(is).`
                                    : 'Selecione os filtros desejados e clique em Gerar Lista para carregar os projetos.'}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center justify-end gap-2">
                            {filtersApplied && pagination && (
                                <span className="sig-pill sig-pill-blue">
                                    Página {pagination.current_page} de {pagination.last_page}
                                </span>
                            )}
                            {filtersApplied && rows.length > 0 && !filtersAreDirty && (
                                <>
                                    <a
                                        href={exportUrl(tenant, 'tenant.projects.master-list.pdf', filters)}
                                        className="sig-btn sig-btn-secondary sig-btn-sm"
                                    >
                                        <FileText size={15} />
                                        Baixar PDF
                                    </a>
                                    <a
                                        href={exportUrl(tenant, 'tenant.projects.master-list.excel', filters)}
                                        className="sig-btn sig-btn-primary sig-btn-sm"
                                    >
                                        <FileSpreadsheet size={15} />
                                        Baixar Excel
                                    </a>
                                </>
                            )}
                            {filtersApplied && rows.length > 0 && filtersAreDirty && (
                                <span className="text-xs font-medium text-[var(--ink-500)]">
                                    Aplique os filtros para atualizar os downloads.
                                </span>
                            )}
                        </div>
                    </div>

                    {!filtersApplied ? (
                        <div className="px-5 py-12 text-center text-sm text-[var(--ink-500)]">
                            A lista será exibida depois que os filtros forem aplicados.
                        </div>
                    ) : rows.length > 0 ? (
                        <>
                            <div className="projects-wide-only overflow-x-auto">
                                <table className="sig-table sig-table-compact min-w-[1120px]">
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
                                                    <div className="max-w-[220px] truncate font-semibold text-[var(--ink-900)]" title={document.title || 'Sem título'}>{document.title || 'Sem título'}</div>
                                                    <div className="text-xs text-[var(--ink-500)]">Sequencial {document.document_number || '-'}</div>
                                                </td>
                                                <td>
                                                    <div className="mono text-xs">{document.contract?.code || '-'}</div>
                                                    <div className="max-w-[150px] truncate text-xs text-[var(--ink-500)]" title={document.contract?.name || 'Sem contrato'}>{document.contract?.name || 'Sem contrato'}</div>
                                                </td>
                                                <td>
                                                    <div className="mono text-xs">{document.obra?.codigo || '-'}</div>
                                                    <div className="max-w-[150px] truncate text-xs text-[var(--ink-500)]" title={document.obra?.nome || 'Sem obra'}>{document.obra?.nome || 'Sem obra'}</div>
                                                </td>
                                                <td>
                                                    <span className="inline-flex items-center gap-2 text-sm font-semibold text-[var(--ink-700)]">
                                                        <span className="h-3.5 w-3.5 rounded-full border border-[var(--border)]" style={{ backgroundColor: document.disciplina?.cor || '#2563eb' }} />
                                                        {document.disciplina?.sigla || '-'}
                                                    </span>
                                                    <div className="max-w-[130px] truncate text-xs text-[var(--ink-500)]" title={document.disciplina?.nome || 'Sem disciplina'}>{document.disciplina?.nome || 'Sem disciplina'}</div>
                                                </td>
                                                <td>
                                                    <div className="mono text-xs">{document.phase?.code || '-'}</div>
                                                    <div className="max-w-[120px] truncate text-xs text-[var(--ink-500)]" title={document.phase?.name || 'Sem fase'}>{document.phase?.name || 'Sem fase'}</div>
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
                                                    <div className="max-w-[180px] truncate text-xs font-semibold" title={document.file_name || 'Sem arquivo'}>{document.file_name || 'Sem arquivo'}</div>
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
                                    <article key={document.id} className="px-4 py-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="mono break-all text-sm font-semibold text-[var(--primary)]">{document.code || '-'}</span>
                                            <span className="sig-pill sig-pill-blue">{document.revision || 'Sem revisão'}</span>
                                            <span className={`sig-pill ${statusClasses[document.status] || 'sig-pill-blue'}`}>
                                                {document.status_label || document.status}
                                            </span>
                                        </div>
                                        <h3 className="mt-1.5 truncate text-sm font-semibold text-[var(--ink-900)]" title={document.title || 'Sem título'}>{document.title || 'Sem título'}</h3>
                                        <div className="mt-2 grid gap-x-4 gap-y-2 sm:grid-cols-2">
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

function MultiFilter({ label, allLabel, options, selected, onToggle, onClear }) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef(null);
    const selectedOptions = options.filter((option) => selected.includes(String(option.value)));
    const summary = selectedOptions.length === 0
        ? allLabel
            : selectedOptions.length === 1
                ? selectedOptions[0].label
                : `${selectedOptions.length} selecionados`;

    useEffect(() => {
        if (!open) return undefined;

        const closeOnOutsideClick = (event) => {
            if (!containerRef.current?.contains(event.target)) setOpen(false);
        };
        const closeOnEscape = (event) => {
            if (event.key === 'Escape') setOpen(false);
        };

        document.addEventListener('pointerdown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('pointerdown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [open]);

    return (
        <div ref={containerRef} className="relative min-w-0">
            <span className="eyebrow mb-1 block">{label}</span>
            <div className="relative">
                <button
                    type="button"
                    className="sig-input flex w-full items-center justify-between gap-2 bg-white text-left"
                    aria-expanded={open}
                    onClick={() => setOpen((current) => !current)}
                >
                    <span className="truncate">{summary}</span>
                    <ChevronDown size={15} className={`shrink-0 text-[var(--ink-500)] transition-transform ${open ? 'rotate-180' : ''}`} />
                </button>
                {open && <div className="absolute left-0 right-0 z-30 mt-1 overflow-hidden rounded-md border border-[var(--border)] bg-white shadow-xl">
                    <div className="flex items-center justify-between gap-2 border-b border-[var(--border)] px-3 py-2">
                        <span className="text-xs font-semibold text-[var(--ink-700)]">
                            {selected.length > 0 ? `${selected.length} selecionado(s)` : allLabel}
                        </span>
                        {selected.length > 0 && (
                            <button type="button" className="text-xs font-semibold text-[var(--primary)]" onClick={onClear}>
                                Limpar
                            </button>
                        )}
                    </div>
                    <div className="max-h-60 overflow-y-auto p-1">
                        {options.length > 0 ? options.map((option) => {
                            const checked = selected.includes(String(option.value));

                            return (
                                <label key={option.value} className="flex cursor-pointer items-center gap-2 rounded px-2.5 py-2 text-sm hover:bg-[var(--surface-muted)]">
                                    <span className={`grid h-5 w-5 shrink-0 place-items-center rounded border ${checked ? 'border-[var(--primary)] bg-[var(--primary)] text-white' : 'border-[var(--border-strong)] text-transparent'}`}>
                                        <Check size={13} />
                                    </span>
                                    {option.color && <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: option.color }} />}
                                    <span className="min-w-0 flex-1 truncate">{option.label}</span>
                                    <input
                                        type="checkbox"
                                        className="sr-only"
                                        checked={checked}
                                        onChange={() => onToggle(option.value)}
                                    />
                                </label>
                            );
                        }) : (
                            <div className="px-3 py-5 text-center text-xs text-[var(--ink-500)]">Nenhuma opcao disponivel.</div>
                        )}
                    </div>
                </div>}
            </div>
            {selectedOptions.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {selectedOptions.map((option) => (
                        <span key={option.value} className="inline-flex max-w-full items-center gap-1.5 rounded-md border border-[var(--border)] bg-[var(--surface-muted)] px-2 py-1 text-[11px] font-semibold text-[var(--ink-700)]">
                            {option.color && <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: option.color }} />}
                            <span className="truncate">{option.label}</span>
                            <button
                                type="button"
                                className="grid h-4 w-4 shrink-0 place-items-center rounded text-[var(--ink-500)] hover:bg-white hover:text-[var(--red)]"
                                title={`Remover ${option.label}`}
                                aria-label={`Remover ${option.label}`}
                                onClick={() => onToggle(option.value)}
                            >
                                <X size={11} />
                            </button>
                        </span>
                    ))}
                </div>
            )}
        </div>
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
    const pageLinks = (pagination.links || []).filter((link) => /^\d+$/.test(String(link.label)));

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
                {pageLinks.map((link) => (
                    <button
                        key={link.label}
                        type="button"
                        className={`sig-btn sig-btn-sm ${link.active ? 'sig-btn-primary' : 'sig-btn-secondary'}`}
                        disabled={link.active || !link.url}
                        aria-current={link.active ? 'page' : undefined}
                        onClick={() => goTo(link.url)}
                    >
                        {link.label}
                    </button>
                ))}
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
