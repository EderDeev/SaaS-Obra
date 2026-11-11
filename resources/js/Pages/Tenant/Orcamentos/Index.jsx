import { Link, router, usePage } from '@inertiajs/react';
import { Eye, FilePlus2, FolderOpen, Plane, Upload, UsersRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { startBudgetTour } from '@/Components/BudgetTour';
import BudgetAccessDialog from './Partials/BudgetAccessDialog';
import OrcamentoShell from './Partials/OrcamentoShell';

export default function OrcamentosIndex({
    tenant,
    filteredContract = null,
    orcamentos = { data: [], links: [] },
    filters = {},
    clients = [],
    contracts = [],
    types = [],
    canCreateOrcamentos = false,
    canImportOrcamentos = false,
}) {
    const page = usePage();
    const [search, setSearch] = useState(filters.search || '');
    const [tipo, setTipo] = useState(filters.tipo || '');
    const [client, setClient] = useState(filters.cliente_empresa_id || '');
    const [contract, setContract] = useState(filters.contract_id || '');
    const previousSearch = useRef(search);
    useEffect(() => {
        const changedSearch = previousSearch.current !== search;
        previousSearch.current = search;
        if (search === (filters.search || '') && tipo === (filters.tipo || '')
            && String(client) === String(filters.cliente_empresa_id || '')
            && String(contract) === String(filters.contract_id || '')) return;
        let cancel;
        const timer = setTimeout(() => router.get(route('tenant.orcamentos.index', tenant.slug), {
            search, tipo, cliente_empresa_id: client, contract_id: contract,
        }, { preserveState: true, preserveScroll: true, replace: true,
            onCancelToken: token => { cancel = token; } }), changedSearch ? 350 : 0);
        return () => { clearTimeout(timer); cancel?.cancel(); };
    }, [search, tipo, client, contract, tenant.slug]);
    useEffect(() => {
        setSearch(filters.search || ''); setTipo(filters.tipo || '');
        setClient(filters.cliente_empresa_id || ''); setContract(filters.contract_id || '');
    }, [filters.search, filters.tipo, filters.cliente_empresa_id, filters.contract_id]);
    const [accessBudget, setAccessBudget] = useState(null);

    return (
        <OrcamentoShell
            tenant={tenant}
            active="orcamentos"
            title="Listar Orçamentos"
            subtitle="Crie orçamentos, defina bases de referência, BDI, encargos e acompanhe os rascunhos antes da montagem analítica."
            showNav={false}
        >
            {page.props.flash?.success && (
                <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                    {page.props.flash.success}
                </div>
            )}

            {filteredContract && (
                <div className="sig-card mb-4 flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                    <p>Orçamentos do contrato <strong>{filteredContract.code} · {filteredContract.name}</strong></p>
                    <Link className="sig-btn sig-btn-secondary" href={route('tenant.orcamentos.index', tenant.slug)}>
                        Ver todos os orçamentos
                    </Link>
                </div>
            )}

            <div className="mb-5 flex flex-wrap items-center justify-end gap-3">
                <div className="flex flex-wrap gap-2">
                    <button className="sig-btn sig-btn-secondary" type="button" onClick={() => startBudgetTour(tenant.slug)}>
                        <Plane size={16} />
                        Iniciar tour
                    </button>
                    {(canCreateOrcamentos || canImportOrcamentos) && (
                        <>
                        {canImportOrcamentos && (
                            <Link className="sig-btn sig-btn-secondary" href={route('tenant.orcamentos.import.create', tenant.slug)}>
                                <Upload size={16} />
                                Importar Orçamento
                            </Link>
                        )}
                        {canCreateOrcamentos && (
                            <Link className="sig-btn sig-btn-primary" href={route('tenant.orcamentos.create', tenant.slug)}>
                                <FilePlus2 size={16} />
                                Criar orçamento
                            </Link>
                        )}
                        </>
                    )}
                </div>
            </div>

            <section className="sig-card grid gap-3 p-4 md:grid-cols-2 lg:grid-cols-4" aria-label="Filtros dos orçamentos">
                <label className="text-xs">Código ou descrição<input className="sig-input mt-1" value={search} onChange={event => setSearch(event.target.value)} placeholder="Pesquisar orçamento" /></label>
                <label className="text-xs">Contrato<select className="sig-input mt-1" value={contract} onChange={event => { setContract(event.target.value); setClient(''); }}><option value="">Todos os contratos</option>{contracts.map(item => <option key={item.id} value={item.id}>{item.code} — {item.name}</option>)}</select></label>
                <label className="text-xs">Tipo<select className="sig-input mt-1" value={tipo} onChange={event => setTipo(event.target.value)}><option value="">Todos os tipos</option>{types.map(type => <option key={type} value={type}>{type}</option>)}</select></label>
                <label className="text-xs">Cliente<select className="sig-input mt-1" value={client} onChange={event => setClient(event.target.value)}><option value="">Todos os clientes</option>{clients.map(item => <option key={item.id} value={item.id}>{item.nome}</option>)}</select></label>
                <div className="flex justify-end md:col-span-2 lg:col-span-4"><button className="sig-btn sig-btn-secondary" type="button" onClick={() => { setSearch(''); setTipo(''); setClient(''); setContract(''); }}>Limpar filtros</button></div>
            </section>

            <section className="sig-card mt-5 overflow-hidden">
                <header className="border-b border-[var(--border)] px-5 py-4">
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Lista de orçamentos</h2>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                        A próxima etapa será detalhar itens, composições e memória de cálculo de cada orçamento.
                    </p>
                </header>

                {orcamentos.data.length === 0 ? (
                    <div className="p-8 text-center">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                            <FolderOpen size={22} />
                        </div>
                        <p className="mt-3 text-sm font-semibold text-[var(--ink-900)]">{filteredContract ? 'Nenhum orçamento disponível para este contrato' : 'Nenhum orçamento cadastrado'}</p>
                        {!filteredContract && <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Use o botão de criar orçamento para iniciar a primeira base de orçamento.
                        </p>}
                    </div>
                ) : (
                    <div className="divide-y divide-[var(--border)]">
                        {orcamentos.data.map((orcamento) => (
                            <article
                                key={orcamento.id}
                                className="grid gap-4 px-5 py-4 lg:grid-cols-[120px_minmax(220px,1.5fr)_minmax(180px,1fr)_minmax(160px,0.8fr)_minmax(180px,1fr)] lg:items-center"
                            >
                                <div>
                                    <Link
                                        className="mono text-sm font-bold text-[var(--primary)] hover:underline"
                                        href={route('tenant.orcamentos.show', [tenant.slug, orcamento.id])}
                                    >
                                        {orcamento.codigo}
                                    </Link>
                                    <p className="mt-1 text-[11px] font-bold uppercase tracking-[0.06em] text-[var(--ink-400)]">Código</p>
                                </div>

                                <div className="min-w-0">
                                    <h3 className="text-sm font-bold text-[var(--ink-900)]">{orcamento.descricao}</h3>
                                    <p className="mt-1 text-xs text-[var(--ink-500)]">{orcamento.categoria}</p>
                                </div>

                                <div>
                                    <p className="text-sm font-semibold text-[var(--ink-800)]">{orcamento.cliente ?? 'Sem cliente'}</p>
                                    <p className="mt-1 text-[11px] font-bold uppercase tracking-[0.06em] text-[var(--ink-400)]">Cliente</p>
                                </div>

                                <div>
                                    <span className={`inline-flex rounded-full px-3 py-1 text-xs font-bold ${
                                        orcamento.status === 'closed'
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-[var(--primary-50)] text-[var(--primary)]'
                                    }`}>
                                        {orcamento.status_label}
                                    </span>
                                    <p className="mt-2 text-xs text-[var(--ink-500)]">{orcamento.prazo_entrega ?? 'Sem prazo'}</p>
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    {(orcamento.base_references ?? []).map((reference) => (
                                        <span
                                            key={reference.codigo}
                                            className="rounded-md bg-[var(--surface-muted)] px-2 py-1 text-[11px] font-bold text-[var(--ink-600)]"
                                        >
                                            {reference.nome} {reference.uf} {reference.data}
                                        </span>
                                    ))}
                                    <div className="flex flex-wrap items-center gap-2">
                                        {orcamento.can_manage_accesses && (
                                            <button
                                                type="button"
                                                className="sig-btn sig-btn-secondary px-3 py-2 text-xs"
                                                onClick={() => setAccessBudget(orcamento)}
                                            >
                                                <UsersRound size={14} />
                                                Acessos
                                            </button>
                                        )}
                                        <Link
                                            className="sig-btn sig-btn-secondary px-3 py-2 text-xs"
                                            href={route('tenant.orcamentos.show', [tenant.slug, orcamento.id])}
                                        >
                                            <Eye size={14} />
                                            Abrir
                                        </Link>
                                    </div>
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </section>

            {orcamentos.last_page > 1 && <nav className="mt-4 flex flex-wrap items-center justify-between gap-3" aria-label="Paginação dos orçamentos">
                <span className="text-xs text-[var(--ink-500)]">{orcamentos.from}–{orcamentos.to} de {orcamentos.total} orçamentos</span>
                <div className="flex flex-wrap gap-2">{orcamentos.links.map((link, index) => link.url ? <Link key={index} className={`sig-btn ${link.active ? 'sig-btn-primary' : 'sig-btn-secondary'}`} href={link.url} preserveScroll aria-current={link.active ? 'page' : undefined} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="sig-btn sig-btn-secondary opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>
            </nav>}
            {accessBudget && (
                <BudgetAccessDialog
                    key={accessBudget.id}
                    tenant={tenant}
                    orcamento={accessBudget}
                    onCancel={() => setAccessBudget(null)}
                />
            )}
        </OrcamentoShell>
    );
}
