import ProjectCapModal from '@/Components/ProjectCapModal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { ClipboardList, Eye, Filter, Search } from 'lucide-react';
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

export default function ProjectRevisions({
    tenant,
    contracts,
    documents,
    documentTypes,
    statusLabels,
    capImpactLabels = {},
}) {
    const [contractFilter, setContractFilter] = useState('todos');
    const [query, setQuery] = useState('');
    const [capRow, setCapRow] = useState(null);

    const rows = useMemo(() => documents.flatMap((document) => (
        (document.versions || []).map((version) => ({
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

                <section className="sig-card overflow-hidden">
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
                        <div className="overflow-x-auto">
                            <table className="sig-table min-w-[1180px]">
                                <thead>
                                    <tr>
                                        <th>CAP</th>
                                        <th>Projeto</th>
                                        <th>Revisao</th>
                                        <th>Contrato</th>
                                        <th>Obra</th>
                                        <th>Disciplina / fase</th>
                                        <th>Solicitante</th>
                                        <th>Status</th>
                                        <th>Acoes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredRows.map(({ id, document, version }) => (
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
                                                <span className="sig-pill sig-pill-blue font-semibold">{version.revision}</span>
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
                                                <button
                                                    type="button"
                                                    className="sig-btn sig-btn-primary sig-btn-sm"
                                                    onClick={() => setCapRow({ document, version })}
                                                >
                                                    <Eye size={13} />
                                                    Visualizar CAP
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
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
