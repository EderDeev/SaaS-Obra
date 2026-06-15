import { Link, usePage } from '@inertiajs/react';
import { CalendarClock, ClipboardList, Eye, FilePlus2, FolderOpen, Scale } from 'lucide-react';
import OrcamentoShell from './Partials/OrcamentoShell';

export default function OrcamentosIndex({
    tenant,
    orcamentos = [],
    stats = {},
    canManageOrcamentos = false,
}) {
    const page = usePage();

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

            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold text-[var(--ink-900)]">Orçamentos cadastrados</h2>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        Estruture a base do orçamento antes de adicionar composições e insumos.
                    </p>
                </div>

                {canManageOrcamentos && (
                    <Link className="sig-btn sig-btn-primary" href={route('tenant.orcamentos.create', tenant.slug)}>
                        <FilePlus2 size={16} />
                        Criar orçamento
                    </Link>
                )}
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <InfoCard
                    description="Total de orçamentos registrados no tenant."
                    icon={ClipboardList}
                    title="Total"
                    value={stats.total ?? 0}
                />
                <InfoCard
                    description="Orçamentos em fase de montagem."
                    icon={CalendarClock}
                    title="Em elaboração"
                    value={stats.draft ?? 0}
                />
                <InfoCard
                    description="Orçamentos liberados para uso."
                    icon={Scale}
                    title="Aprovados"
                    value={stats.approved ?? 0}
                />
            </div>

            <section className="sig-card mt-5 overflow-hidden">
                <header className="border-b border-[var(--border)] px-5 py-4">
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Lista de orçamentos</h2>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                        A próxima etapa será detalhar itens, composições e memória de cálculo de cada orçamento.
                    </p>
                </header>

                {orcamentos.length === 0 ? (
                    <div className="p-8 text-center">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                            <FolderOpen size={22} />
                        </div>
                        <p className="mt-3 text-sm font-semibold text-[var(--ink-900)]">Nenhum orçamento cadastrado</p>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Use o botão de criar orçamento para iniciar a primeira base de orçamento.
                        </p>
                    </div>
                ) : (
                    <div className="divide-y divide-[var(--border)]">
                        {orcamentos.map((orcamento) => (
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
                                    <span className="inline-flex rounded-full bg-[var(--primary-50)] px-3 py-1 text-xs font-bold text-[var(--primary)]">
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
                                    <Link
                                        className="sig-btn sig-btn-secondary px-3 py-2 text-xs"
                                        href={route('tenant.orcamentos.show', [tenant.slug, orcamento.id])}
                                    >
                                        <Eye size={14} />
                                        Abrir
                                    </Link>
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </section>
        </OrcamentoShell>
    );
}

function InfoCard({ description, icon: Icon, title, value }) {
    return (
        <article className="sig-card p-5">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <span className="eyebrow">{title}</span>
                    <strong className="mono mt-3 block text-3xl text-[var(--ink-900)]">{value}</strong>
                </div>
                <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                    <Icon size={20} />
                </span>
            </div>
            <p className="mt-2 text-sm leading-6 text-[var(--ink-500)]">{description}</p>
        </article>
    );
}
