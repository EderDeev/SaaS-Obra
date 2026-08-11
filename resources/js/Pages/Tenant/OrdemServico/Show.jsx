import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Ban,
    Building2,
    CalendarDays,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    ClipboardList,
    Clock3,
    Download,
    FileText,
    History,
    MessageSquare,
    Paperclip,
    Pencil,
    Play,
    Search,
    Send,
    ShieldCheck,
    UserRound,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

const statusLabels = {
    rascunho: 'Rascunho',
    solicitada: 'Solicitada',
    em_analise: 'Em análise',
    em_aprovacao: 'Em aprovação',
    aprovada: 'Aprovada',
    recusada: 'Recusada',
    em_execucao: 'Em execução',
    concluida: 'Concluída',
    cancelada: 'Cancelada',
};

const statusClasses = {
    rascunho: 'bg-slate-100 text-slate-700',
    solicitada: 'bg-blue-50 text-blue-700',
    em_analise: 'bg-amber-50 text-amber-700',
    em_aprovacao: 'bg-indigo-50 text-indigo-700',
    aprovada: 'bg-emerald-50 text-emerald-700',
    recusada: 'bg-red-50 text-red-700',
    em_execucao: 'bg-amber-50 text-amber-700',
    concluida: 'bg-emerald-100 text-emerald-800',
    cancelada: 'bg-slate-200 text-slate-700',
};

const tabs = [
    { id: 'overview', label: 'Visão geral', icon: ClipboardList },
    { id: 'items', label: 'Itens e medição', icon: CircleDollarSign },
    { id: 'execution', label: 'Execução', icon: Play },
    { id: 'documents', label: 'Documentos', icon: Paperclip },
    { id: 'conversation', label: 'Conversas', icon: MessageSquare },
    { id: 'history', label: 'Histórico', icon: History },
];

const formatCurrency = (value) => new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
}).format(Number(value || 0));

const formatPercentage = (value) => new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
}).format(Number(value || 0));

const formatBytes = (value) => {
    const bytes = Number(value || 0);
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

export default function OrdemServicoShow({ ordem, items = [], itemPagination = {}, itemFilters = {}, options = {}, can = {} }) {
    const page = usePage();
    const tenant = page.props.currentTenant;
    const [activeTab, setActiveTab] = useState('overview');
    const [submitConfirm, setSubmitConfirm] = useState(false);
    const [executionAction, setExecutionAction] = useState(null);
    const [submittingAnalysis, setSubmittingAnalysis] = useState(false);
    const [itemsLoading, setItemsLoading] = useState(false);
    const [itemSearch, setItemSearch] = useState(itemFilters.search || '');
    const actionForm = useForm({ completion_summary: '', evidencias: [], motivo: '' });
    const users = options.users || [];
    const openPending = (ordem.comentarios || []).filter((comment) => comment.tipo === 'pendencia' && comment.status !== 'resolvida').length;

    const closeExecutionAction = () => {
        setExecutionAction(null);
        actionForm.reset();
        actionForm.clearErrors();
    };

    const submitForAnalysis = () => {
        if (submittingAnalysis) return;
        setSubmittingAnalysis(true);
        router.patch(route('tenant.ordem-servico.os.submit-analysis', [tenant.slug, ordem.id]), {}, {
            preserveScroll: true,
            onFinish: () => {
                setSubmittingAnalysis(false);
                setSubmitConfirm(false);
            },
        });
    };

    const submitExecutionAction = (event) => {
        event.preventDefault();
        if (!executionAction) return;

        if (executionAction === 'start') {
            router.patch(route('tenant.ordem-servico.os.start-execution', [tenant.slug, ordem.id]), {}, {
                preserveScroll: true,
                onFinish: closeExecutionAction,
            });
            return;
        }

        if (executionAction === 'complete') {
            actionForm.post(route('tenant.ordem-servico.os.complete', [tenant.slug, ordem.id]), {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: closeExecutionAction,
            });
            return;
        }

        actionForm.patch(route('tenant.ordem-servico.os.cancel', [tenant.slug, ordem.id]), {
            preserveScroll: true,
            onSuccess: closeExecutionAction,
        });
    };

    useEffect(() => {
        setItemSearch(itemFilters.search || '');
    }, [itemFilters.search]);

    const changeItemsPage = (pageNumber, searchTerm = itemSearch) => {
        if (itemsLoading) return;

        const targetPage = Math.max(1, Math.min(Number(itemPagination.last_page || 1), Number(pageNumber || 1)));
        const normalizedSearch = searchTerm.trim();
        setItemsLoading(true);

        router.get(route('tenant.ordem-servico.os.show', [tenant.slug, ordem.id]), {
            items_page: targetPage,
            items_search: normalizedSearch || undefined,
        }, {
            only: ['items', 'itemPagination', 'itemFilters'],
            preserveScroll: false,
            preserveState: true,
            onFinish: () => setItemsLoading(false),
        });
    };

    const submitItemsSearch = (event) => {
        event.preventDefault();
        changeItemsPage(1, itemSearch);
    };

    const clearItemsSearch = () => {
        setItemSearch('');
        changeItemsPage(1, '');
    };

    return (
        <AuthenticatedLayout>
            <Head title={`${ordem.codigo} - Ordem de Serviço`} />

            <div className="space-y-5 p-4 sm:p-6 lg:p-8">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="min-w-0">
                        <Link
                            href={route('tenant.ordem-servico.os.index', tenant.slug)}
                            data={{ contract_id: ordem.contract?.id }}
                            className="mb-4 inline-flex items-center gap-2 text-sm font-bold text-[var(--ink-500)] hover:text-[var(--primary)]"
                        >
                            <ArrowLeft size={16} />
                            Ordens de serviço
                        </Link>
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="mono text-sm font-bold text-[var(--primary)]">{ordem.codigo}</span>
                            <span className={`rounded-full px-3 py-1 text-xs font-bold ${statusClasses[ordem.status] || statusClasses.rascunho}`}>
                                {statusLabels[ordem.status] || ordem.status}
                            </span>
                        </div>
                        <h1 className="mt-2 break-words text-2xl font-bold text-[var(--ink-900)] sm:text-3xl">{ordem.titulo}</h1>
                        <p className="mt-2 text-sm text-[var(--ink-500)]">
                            {ordem.contract ? `${ordem.contract.code} - ${ordem.contract.name}` : 'Contrato não informado'}
                            {' · '}
                            {ordem.obra ? `${ordem.obra.codigo} - ${ordem.obra.nome}` : 'Obra não informada'}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2 lg:max-w-xl lg:justify-end">
                        {ordem.status === 'rascunho' && can.manage_drafts && (
                            <>
                                <Link
                                    href={route('tenant.ordem-servico.os.index', tenant.slug)}
                                    data={{ contract_id: ordem.contract?.id, edit: ordem.id }}
                                    className="sig-btn sig-btn-secondary"
                                >
                                    <Pencil size={16} />
                                    Editar
                                </Link>
                                <button type="button" onClick={() => setSubmitConfirm(true)} className="sig-btn sig-btn-primary">
                                    <Send size={16} />
                                    Enviar para análise
                                </button>
                            </>
                        )}
                        {ordem.status === 'aprovada' && can.execute && (
                            <button type="button" onClick={() => setExecutionAction('start')} className="sig-btn sig-btn-primary">
                                <Play size={16} />
                                Iniciar execução
                            </button>
                        )}
                        {ordem.status === 'em_execucao' && can.complete && (
                            <button type="button" onClick={() => setExecutionAction('complete')} className="sig-btn bg-emerald-600 text-white hover:bg-emerald-700">
                                <CheckCircle2 size={16} />
                                Concluir OS
                            </button>
                        )}
                        {['rascunho', 'aprovada', 'em_execucao'].includes(ordem.status) && can.complete && (
                            <button type="button" onClick={() => setExecutionAction('cancel')} className="sig-btn sig-btn-secondary text-red-700">
                                <Ban size={16} />
                                Cancelar
                            </button>
                        )}
                        {ordem.status === 'concluida' && (
                            <a href={route('tenant.ordem-servico.os.pdf', [tenant.slug, ordem.id])} className="sig-btn sig-btn-secondary">
                                <Download size={16} />
                                PDF final
                            </a>
                        )}
                    </div>
                </header>

                <section className="grid gap-px overflow-hidden rounded-lg border border-[var(--border)] bg-[var(--border)] sm:grid-cols-2 xl:grid-cols-4">
                    <SummaryMetric label="Custo previsto" value={formatCurrency(ordem.custo_previsto)} />
                    <SummaryMetric label="Custo real medido" value={formatCurrency(ordem.custo_real)} tone="success" />
                    <SummaryMetric label="Itens medidos" value={`${ordem.itens_medidos_count || 0} de ${ordem.itens_count || 0}`} />
                    <SummaryMetric label="Pendências abertas" value={openPending} tone={openPending ? 'warning' : 'default'} />
                </section>

                <nav className="overflow-x-auto border-b border-[var(--border)]" aria-label="Seções da ordem de serviço">
                    <div className="flex min-w-max gap-1">
                        {tabs.map(({ id, label, icon: Icon }) => (
                            <button
                                key={id}
                                type="button"
                                onClick={() => setActiveTab(id)}
                                className={`flex h-12 items-center gap-2 border-b-2 px-4 text-sm font-bold transition ${activeTab === id ? 'border-[var(--primary)] text-[var(--primary)]' : 'border-transparent text-[var(--ink-500)] hover:text-[var(--ink-900)]'}`}
                            >
                                <Icon size={16} />
                                {label}
                                {id === 'conversation' && openPending > 0 && (
                                    <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] text-amber-800">{openPending}</span>
                                )}
                            </button>
                        ))}
                    </div>
                </nav>

                {activeTab === 'overview' && <OverviewTab ordem={ordem} />}
                {activeTab === 'items' && (
                    <ItemsTab
                        ordem={ordem}
                        items={items}
                        pagination={itemPagination}
                        loading={itemsLoading}
                        search={itemSearch}
                        onSearchChange={setItemSearch}
                        onSearchSubmit={submitItemsSearch}
                        onSearchClear={clearItemsSearch}
                        onPageChange={changeItemsPage}
                    />
                )}
                {activeTab === 'execution' && <ExecutionTab ordem={ordem} />}
                {activeTab === 'documents' && <DocumentsTab ordem={ordem} tenant={tenant} />}
                {activeTab === 'conversation' && <OrderConversation ordem={ordem} tenant={tenant} users={users} />}
                {activeTab === 'history' && <HistoryTab ordem={ordem} />}
            </div>

            {submitConfirm && (
                <ConfirmDialog
                    title="Enviar OS para análise?"
                    description="A OS deixará de ser editável e seguirá para os fiscais responsáveis pela obra."
                    confirmLabel={submittingAnalysis ? 'Enviando...' : 'Enviar para análise'}
                    disabled={submittingAnalysis}
                    onCancel={() => setSubmitConfirm(false)}
                    onConfirm={submitForAnalysis}
                />
            )}

            {executionAction && (
                <ExecutionDialog
                    type={executionAction}
                    ordem={ordem}
                    form={actionForm}
                    onClose={closeExecutionAction}
                    onSubmit={submitExecutionAction}
                />
            )}
        </AuthenticatedLayout>
    );
}

function OverviewTab({ ordem }) {
    return (
        <div className="grid gap-5 xl:grid-cols-[minmax(0,1.5fr)_minmax(280px,0.7fr)]">
            <section className="sig-card p-5">
                <h2 className="text-lg font-bold text-[var(--ink-900)]">Escopo da ordem de serviço</h2>
                <p className="mt-3 whitespace-pre-wrap text-sm leading-7 text-[var(--ink-600)]">
                    {ordem.descricao || 'Nenhuma descrição informada.'}
                </p>
                {ordem.custo_observacao && (
                    <div className="mt-5 border-t border-[var(--border)] pt-4">
                        <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Observação de custos</span>
                        <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-[var(--ink-600)]">{ordem.custo_observacao}</p>
                    </div>
                )}
            </section>

            <section className="sig-card divide-y divide-[var(--border)]">
                <InfoRow icon={CalendarDays} label="Prazo para início" value={ordem.prazo_inicio_label || 'Sem prazo'} />
                <InfoRow icon={CalendarDays} label="Prazo para finalização" value={ordem.prazo_finalizacao_label || 'Sem prazo'} />
                <InfoRow icon={UserRound} label="Solicitante" value={ordem.solicitante?.name || 'Não identificado'} />
                <InfoRow icon={Building2} label="Gerenciadora" value={ordem.gerenciadora_empresa?.nome || 'Não definida'} />
                <InfoRow icon={Building2} label="Construtora" value={ordem.construtora_empresa?.nome || 'Não definida'} />
            </section>

            <section className="sig-card p-5 xl:col-span-2">
                <div className="flex items-center justify-between gap-3">
                    <h2 className="text-lg font-bold text-[var(--ink-900)]">Projetos vinculados</h2>
                    <span className="text-xs font-bold text-[var(--ink-500)]">{ordem.projects?.length || 0} projeto(s)</span>
                </div>
                {ordem.projects?.length ? (
                    <div className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        {ordem.projects.map((project) => (
                            <div key={project.id} className="min-w-0 rounded-md border border-[var(--border)] px-3 py-2">
                                <p className="mono truncate text-xs font-bold text-[var(--primary)]">{project.code || 'Sem EAP'}</p>
                                <p className="mt-1 truncate text-sm text-[var(--ink-700)]">{project.title}</p>
                            </div>
                        ))}
                    </div>
                ) : <EmptyState text="Nenhum projeto vinculado à OS." />}
            </section>
        </div>
    );
}

function ItemsTab({
    ordem,
    items = [],
    pagination = {},
    loading = false,
    search = '',
    onSearchChange,
    onSearchSubmit,
    onSearchClear,
    onPageChange,
}) {
    const currentPage = Number(pagination.current_page || 1);
    const lastPage = Number(pagination.last_page || 1);

    return (
        <section className="sig-card overflow-hidden">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                <div>
                    <h2 className="text-lg font-bold text-[var(--ink-900)]">Itens vinculados</h2>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">Quantidades, valores e avanço medido da OS.</p>
                </div>
                <span className="rounded-full bg-[var(--surface-muted)] px-3 py-1 text-xs font-bold text-[var(--ink-600)]">
                    {pagination.total || ordem.itens_count || 0} item(ns)
                </span>
            </header>

            <div className="flex flex-col gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                <form onSubmit={onSearchSubmit} className="flex min-w-0 flex-1 items-center gap-2 lg:max-w-2xl">
                    <div className="relative min-w-0 flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--ink-400)]" size={17} />
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => onSearchChange(event.target.value)}
                            placeholder="Buscar por item, código ou descrição"
                            className="sig-input h-10 w-full pl-10 pr-10"
                            style={{ paddingLeft: 40, paddingRight: 40 }}
                            disabled={loading}
                        />
                        {search && (
                            <button
                                type="button"
                                onClick={onSearchClear}
                                className="absolute right-2 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center text-[var(--ink-500)] hover:text-[var(--ink-900)]"
                                title="Limpar busca"
                                disabled={loading}
                            >
                                <X size={15} />
                            </button>
                        )}
                    </div>
                    <button type="submit" className="sig-btn sig-btn-primary h-10 px-4" disabled={loading}>
                        <Search size={16} />
                        Buscar
                    </button>
                </form>

                {Number(pagination.total || 0) > 0 && (
                    <div className="flex shrink-0 items-center gap-2">
                        <button
                            type="button"
                            onClick={() => onPageChange(currentPage - 1)}
                            disabled={loading || currentPage <= 1}
                            className="sig-btn sig-btn-secondary h-9 px-3 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <ChevronLeft size={15} />
                            Anterior
                        </button>
                        <span className="min-w-24 text-center text-xs font-bold text-[var(--ink-600)]">
                            {loading ? 'Carregando...' : `Página ${currentPage} de ${lastPage}`}
                        </span>
                        <button
                            type="button"
                            onClick={() => onPageChange(currentPage + 1)}
                            disabled={loading || currentPage >= lastPage}
                            className="sig-btn sig-btn-secondary h-9 px-3 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Próxima
                            <ChevronRight size={15} />
                        </button>
                    </div>
                )}
            </div>

            {!items.length ? <EmptyState text={search ? 'Nenhum item encontrado para a busca.' : 'Nenhum item vinculado à OS.'} /> : (
                <div className="divide-y divide-[var(--border)]">
                    {items.map((item) => (
                        <article key={item.id} className="grid gap-4 px-5 py-4 lg:grid-cols-[minmax(0,1fr)_130px_130px_190px] lg:items-center">
                            <div className="min-w-0">
                                <p className="font-bold text-[var(--ink-900)]">{item.item} - {item.codigo || 'sem código'}</p>
                                <p className="mt-1 text-sm leading-6 text-[var(--ink-500)]">{item.descricao}</p>
                            </div>
                            <ValueBlock label="Valor P0" value={formatCurrency(item.valor_previsto)} />
                            <ValueBlock label="Reajustado" value={formatCurrency(item.valor_reajustado)} tone="success" />
                            <div>
                                <div className="flex items-center justify-between gap-3 text-xs font-bold">
                                    <span className="uppercase text-[var(--ink-500)]">Medido</span>
                                    <span className="text-[var(--primary)]">{formatPercentage(item.percentual_medido)}%</span>
                                </div>
                                <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                    <span className="block h-full rounded-full bg-[var(--primary)]" style={{ width: `${Math.max(0, Math.min(100, Number(item.percentual_medido || 0)))}%` }} />
                                </div>
                                <p className="mt-2 text-xs font-semibold text-emerald-700">Custo real: {formatCurrency(item.custo_real)}</p>
                            </div>
                        </article>
                    ))}
                </div>
            )}

            {Number(pagination.total || 0) > 0 && (
                <footer className="border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                    <p className="text-sm text-[var(--ink-500)]">
                        Exibindo <strong className="text-[var(--ink-800)]">{pagination.from || 0}–{pagination.to || 0}</strong> de{' '}
                        <strong className="text-[var(--ink-800)]">{pagination.total || 0}</strong> itens
                    </p>
                </footer>
            )}
        </section>
    );
}

function ExecutionTab({ ordem }) {
    return (
        <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
            <section className="sig-card p-5">
                <h2 className="text-lg font-bold text-[var(--ink-900)]">Registro da execução</h2>
                <div className="mt-5 space-y-0">
                    <TimelineStep label="OS criada" date={ordem.created_at} user={ordem.solicitante?.name} complete />
                    <TimelineStep label="Enviada para análise" date={ordem.submitted_for_review_at} user={ordem.submitted_by?.name} complete={Boolean(ordem.submitted_for_review_at)} />
                    <TimelineStep label="Analisada" date={ordem.analyzed_at} user={ordem.analyzed_by?.name} complete={Boolean(ordem.analyzed_at)} />
                    <TimelineStep label="Aprovação decidida" date={ordem.approval_decided_at} user={ordem.approval_decided_by?.name} complete={Boolean(ordem.approval_decided_at)} />
                    <TimelineStep label="Execução iniciada" date={ordem.execution_started_at} user={ordem.execution_started_by?.name} complete={Boolean(ordem.execution_started_at)} />
                    <TimelineStep label="OS concluída" date={ordem.completed_at} user={ordem.completed_by?.name} complete={Boolean(ordem.completed_at)} last />
                </div>
                {ordem.completion_summary && (
                    <div className="mt-5 border-t border-[var(--border)] pt-4">
                        <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Resumo da conclusão</span>
                        <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-[var(--ink-700)]">{ordem.completion_summary}</p>
                    </div>
                )}
                {ordem.cancellation_reason && (
                    <div className="mt-5 border-l-4 border-red-500 bg-red-50 px-4 py-3">
                        <span className="text-xs font-bold uppercase text-red-700">Motivo do cancelamento</span>
                        <p className="mt-1 whitespace-pre-wrap text-sm text-red-800">{ordem.cancellation_reason}</p>
                    </div>
                )}
            </section>

            <section className="sig-card p-5">
                <div className="flex items-center gap-2">
                    <Users size={18} className="text-[var(--primary)]" />
                    <h2 className="text-lg font-bold text-[var(--ink-900)]">Responsáveis</h2>
                </div>
                {ordem.responsaveis?.length ? (
                    <div className="mt-4 divide-y divide-[var(--border)]">
                        {ordem.responsaveis.map((responsavel) => (
                            <div key={responsavel.id} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                <Avatar user={responsavel} />
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-bold text-[var(--ink-900)]">{responsavel.name}</p>
                                    <p className="truncate text-xs text-[var(--ink-500)]">{responsavel.email}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : <EmptyState text="Nenhum responsável pela execução." compact />}
            </section>
        </div>
    );
}

function DocumentsTab({ ordem, tenant }) {
    const categories = {
        execucao: 'Documento da OS',
        conclusao: 'Evidência de conclusão',
        comentario: 'Anexo de conversa',
    };

    return (
        <section className="sig-card overflow-hidden">
            <header className="border-b border-[var(--border)] px-5 py-4">
                <h2 className="text-lg font-bold text-[var(--ink-900)]">Documentos e evidências</h2>
                <p className="mt-1 text-sm text-[var(--ink-500)]">Arquivos do escopo, execução, conclusão e conversas.</p>
            </header>
            {!ordem.documentos?.length ? <EmptyState text="Nenhum documento anexado à OS." /> : (
                <div className="divide-y divide-[var(--border)]">
                    {ordem.documentos.map((document) => (
                        <article key={document.id} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex min-w-0 items-center gap-3">
                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-[var(--primary-50)] text-[var(--primary)]">
                                    <FileText size={18} />
                                </span>
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-bold text-[var(--ink-900)]">{document.nome_original}</p>
                                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                                        {categories[document.categoria] || 'Documento'} · {formatBytes(document.size)}
                                        {document.uploader?.name ? ` · ${document.uploader.name}` : ''}
                                    </p>
                                </div>
                            </div>
                            <a href={route('tenant.ordem-servico.os.documents.download', [tenant.slug, ordem.id, document.id])} className="sig-btn sig-btn-secondary shrink-0">
                                <Download size={15} />
                                Baixar
                            </a>
                        </article>
                    ))}
                </div>
            )}
        </section>
    );
}

function HistoryTab({ ordem }) {
    const records = [
        { label: 'OS criada', date: ordem.created_at, user: ordem.solicitante?.name },
        { label: 'Enviada para análise', date: ordem.submitted_for_review_at, user: ordem.submitted_by?.name },
        ...(ordem.analises || []).map((analysis) => ({
            label: analysis.decisao === 'aprovada' ? 'Parecer aprovado' : analysis.decisao === 'reprovada' ? 'OS devolvida' : 'Análise registrada',
            date: analysis.created_at,
            user: analysis.user?.name,
            observation: analysis.observacao,
        })),
        { label: 'Execução iniciada', date: ordem.execution_started_at, user: ordem.execution_started_by?.name },
        { label: 'OS concluída', date: ordem.completed_at, user: ordem.completed_by?.name },
        { label: 'OS cancelada', date: ordem.cancelled_at, user: ordem.cancelled_by?.name, observation: ordem.cancellation_reason },
    ].filter((record) => record.date);

    return (
        <section className="sig-card p-5">
            <h2 className="text-lg font-bold text-[var(--ink-900)]">Histórico da OS</h2>
            <p className="mt-1 text-sm text-[var(--ink-500)]">Registro cronológico das principais decisões e mudanças de etapa.</p>
            <div className="mt-5 divide-y divide-[var(--border)]">
                {records.map((record, index) => (
                    <article key={`${record.label}-${index}`} className="grid gap-2 py-4 first:pt-0 sm:grid-cols-[170px_minmax(0,1fr)]">
                        <p className="mono text-xs font-bold text-[var(--ink-500)]">{record.date}</p>
                        <div>
                            <p className="text-sm font-bold text-[var(--ink-900)]">{record.label}</p>
                            {record.user && <p className="mt-1 text-sm text-[var(--ink-500)]">Por {record.user}</p>}
                            {record.observation && <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-[var(--ink-600)]">{record.observation}</p>}
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}

function OrderConversation({ ordem, tenant, users = [] }) {
    const [replyTo, setReplyTo] = useState(null);
    const form = useForm({ tipo: 'comentario', body: '', parent_id: '', mention_user_ids: [], anexos: [] });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tenant.ordem-servico.os.comments.store', [tenant.slug, ordem.id]), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setReplyTo(null);
            },
        });
    };

    const beginReply = (comment) => {
        setReplyTo(comment);
        form.setData({
            ...form.data,
            tipo: comment.tipo,
            parent_id: comment.id,
            body: '',
            mention_user_ids: comment.user?.id ? [comment.user.id] : [],
            anexos: [],
        });
    };

    const toggleResolved = (comment) => router.patch(
        route('tenant.ordem-servico.os.comments.resolve', [tenant.slug, ordem.id, comment.id]),
        {},
        { preserveScroll: true }
    );

    return (
        <section className="sig-card overflow-hidden">
            <header className="flex items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                <div>
                    <h2 className="text-lg font-bold text-[var(--ink-900)]">Conversas e pendências</h2>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">Atualizações operacionais, respostas e decisões da equipe.</p>
                </div>
                <span className="text-xs font-bold text-[var(--ink-500)]">{ordem.comentarios?.length || 0} registro(s)</span>
            </header>

            <div className="max-h-[34rem] divide-y divide-[var(--border)] overflow-auto">
                {!ordem.comentarios?.length ? <EmptyState text="Nenhum comentário ou pendência registrado." /> : ordem.comentarios.map((comment) => (
                    <article key={comment.id} className={`p-5 ${comment.tipo === 'pendencia' ? 'bg-amber-50/50' : ''}`}>
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="flex min-w-0 items-center gap-3">
                                <Avatar user={comment.user} />
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-bold text-[var(--ink-900)]">{comment.user?.name || 'Usuário removido'}</p>
                                    <p className="text-xs text-[var(--ink-500)]">{comment.created_at}</p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                {comment.tipo === 'pendencia' && (
                                    <button type="button" onClick={() => toggleResolved(comment)} className={`rounded-full px-3 py-1 text-xs font-bold ${comment.status === 'resolvida' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}`}>
                                        {comment.status === 'resolvida' ? 'Resolvida' : 'Pendente'}
                                    </button>
                                )}
                                <button type="button" onClick={() => beginReply(comment)} className="text-xs font-bold text-[var(--primary)] hover:underline">Responder</button>
                            </div>
                        </div>
                        <p className="mt-3 whitespace-pre-wrap text-sm leading-6 text-[var(--ink-700)]">{comment.body}</p>
                        <AttachmentLinks attachments={comment.attachments} ordem={ordem} tenant={tenant} />
                        {(comment.replies || []).map((reply) => (
                            <div key={reply.id} className="ml-4 mt-4 border-l-2 border-blue-200 pl-4 sm:ml-10">
                                <p className="text-xs font-bold text-[var(--ink-900)]">{reply.user?.name || 'Usuário removido'} <span className="font-normal text-[var(--ink-500)]">· {reply.created_at}</span></p>
                                <p className="mt-1 whitespace-pre-wrap text-sm leading-6 text-[var(--ink-700)]">{reply.body}</p>
                                <AttachmentLinks attachments={reply.attachments} ordem={ordem} tenant={tenant} />
                            </div>
                        ))}
                    </article>
                ))}
            </div>

            <form onSubmit={submit} className="grid gap-3 border-t border-[var(--border)] bg-[var(--surface-muted)] p-5">
                {replyTo ? (
                    <div className="flex items-center justify-between rounded-md bg-blue-50 px-3 py-2 text-xs text-blue-800">
                        <span>Respondendo a {replyTo.user?.name || 'usuário'}</span>
                        <button type="button" onClick={() => { setReplyTo(null); form.setData({ ...form.data, parent_id: '', body: '', mention_user_ids: [], anexos: [] }); }} className="font-bold">Cancelar resposta</button>
                    </div>
                ) : (
                    <div className="flex gap-2">
                        {['comentario', 'pendencia'].map((type) => (
                            <button key={type} type="button" onClick={() => form.setData('tipo', type)} className={`rounded-md px-3 py-1.5 text-xs font-bold ${form.data.tipo === type ? 'bg-[var(--primary)] text-white' : 'border border-[var(--border)] bg-white text-[var(--ink-700)]'}`}>
                                {type === 'comentario' ? 'Comentário' : 'Pendência'}
                            </button>
                        ))}
                    </div>
                )}
                <textarea value={form.data.body} onChange={(event) => form.setData('body', event.target.value)} className="sig-input min-h-24 bg-white" placeholder={replyTo ? 'Escreva a resposta...' : 'Registre uma atualização, orientação ou pendência...'} />
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="grid gap-1 text-xs font-bold uppercase text-[var(--ink-500)]">
                        Mencionar usuários
                        <select multiple value={form.data.mention_user_ids.map(String)} onChange={(event) => form.setData('mention_user_ids', Array.from(event.target.selectedOptions).map((option) => Number(option.value)))} className="sig-input min-h-20 bg-white normal-case">
                            {users.map((user) => <option key={user.id} value={user.id}>{user.name} - {user.email}</option>)}
                        </select>
                    </label>
                    <label className="grid content-start gap-1 text-xs font-bold uppercase text-[var(--ink-500)]">
                        Anexos
                        <input type="file" multiple onChange={(event) => form.setData('anexos', Array.from(event.target.files || []))} className="sig-input bg-white normal-case" />
                    </label>
                </div>
                {form.errors.body && <p className="text-xs font-semibold text-red-600">{form.errors.body}</p>}
                <button type="submit" disabled={form.processing || !form.data.body.trim()} className="sig-btn sig-btn-primary w-fit justify-self-end">
                    <Send size={15} />
                    {replyTo ? 'Enviar resposta' : 'Registrar'}
                </button>
            </form>
        </section>
    );
}

function ExecutionDialog({ type, ordem, form, onClose, onSubmit }) {
    return (
        <div className="fixed inset-0 z-[125] flex items-center justify-center bg-slate-950/55 p-4" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <form onSubmit={onSubmit} onMouseDown={(event) => event.stopPropagation()} className="w-full max-w-xl overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-2xl" role="dialog" aria-modal="true">
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                    <div>
                        <span className="eyebrow">{ordem.codigo}</span>
                        <h2 className="mt-1 text-lg font-bold text-[var(--ink-900)]">
                            {type === 'start' ? 'Iniciar execução da OS?' : type === 'complete' ? 'Concluir ordem de serviço' : 'Cancelar ordem de serviço'}
                        </h2>
                    </div>
                    <button type="button" onClick={onClose} className="flex h-9 w-9 items-center justify-center rounded-md hover:bg-[var(--surface-muted)]"><X size={18} /></button>
                </header>
                <div className="grid gap-4 p-5">
                    {type === 'start' && <p className="text-sm leading-6 text-[var(--ink-600)]">A OS passará para <strong>Em execução</strong> e os envolvidos serão notificados.</p>}
                    {type === 'complete' && (
                        <>
                            <label className="grid gap-1.5 text-sm font-bold text-[var(--ink-600)]">
                                Resumo da execução
                                <textarea value={form.data.completion_summary} onChange={(event) => form.setData('completion_summary', event.target.value)} className="sig-input min-h-32 font-normal" placeholder="Registre o serviço executado, ocorrências e resultado final." />
                                {form.errors.completion_summary && <span className="text-xs text-red-600">{form.errors.completion_summary}</span>}
                            </label>
                            <label className="grid gap-1.5 text-sm font-bold text-[var(--ink-600)]">
                                Evidências de conclusão
                                <input type="file" multiple onChange={(event) => form.setData('evidencias', Array.from(event.target.files || []))} className="sig-input font-normal" />
                                {form.errors.evidencias && <span className="text-xs text-red-600">{form.errors.evidencias}</span>}
                            </label>
                        </>
                    )}
                    {type === 'cancel' && (
                        <label className="grid gap-1.5 text-sm font-bold text-[var(--ink-600)]">
                            Motivo do cancelamento
                            <textarea value={form.data.motivo} onChange={(event) => form.setData('motivo', event.target.value)} className="sig-input min-h-28 font-normal" placeholder="Explique por que a OS está sendo cancelada." />
                            {form.errors.motivo && <span className="text-xs text-red-600">{form.errors.motivo}</span>}
                        </label>
                    )}
                </div>
                <footer className="flex justify-end gap-3 border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                    <button type="button" onClick={onClose} className="sig-btn sig-btn-secondary">Voltar</button>
                    <button type="submit" disabled={form.processing} className={`sig-btn ${type === 'cancel' ? 'bg-red-600 text-white hover:bg-red-700' : 'sig-btn-primary'}`}>
                        {type === 'start' ? <Play size={16} /> : type === 'complete' ? <CheckCircle2 size={16} /> : <Ban size={16} />}
                        {form.processing ? 'Processando...' : type === 'start' ? 'Iniciar execução' : type === 'complete' ? 'Concluir OS' : 'Confirmar cancelamento'}
                    </button>
                </footer>
            </form>
        </div>
    );
}

function ConfirmDialog({ title, description, confirmLabel, disabled, onCancel, onConfirm }) {
    return (
        <div className="fixed inset-0 z-[125] flex items-center justify-center bg-slate-950/55 p-4" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onCancel()}>
            <section className="w-full max-w-md overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-2xl" role="dialog" aria-modal="true">
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                    <div>
                        <span className="eyebrow">Confirmar</span>
                        <h2 className="mt-1 text-lg font-bold text-[var(--ink-900)]">{title}</h2>
                    </div>
                    <button type="button" onClick={onCancel} className="flex h-9 w-9 items-center justify-center rounded-md hover:bg-[var(--surface-muted)]"><X size={18} /></button>
                </header>
                <p className="p-5 text-sm leading-6 text-[var(--ink-600)]">{description}</p>
                <footer className="flex justify-end gap-3 border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                    <button type="button" onClick={onCancel} disabled={disabled} className="sig-btn sig-btn-secondary">Cancelar</button>
                    <button type="button" onClick={onConfirm} disabled={disabled} className="sig-btn sig-btn-primary"><Send size={16} />{confirmLabel}</button>
                </footer>
            </section>
        </div>
    );
}

function SummaryMetric({ label, value, tone = 'default' }) {
    const toneClass = tone === 'success' ? 'text-emerald-700' : tone === 'warning' ? 'text-amber-700' : 'text-[var(--ink-900)]';
    return <div className="bg-white px-5 py-4"><span className="text-xs font-bold uppercase text-[var(--ink-500)]">{label}</span><strong className={`mt-1 block text-xl ${toneClass}`}>{value}</strong></div>;
}

function InfoRow({ icon: Icon, label, value }) {
    return <div className="flex items-start gap-3 px-5 py-4"><Icon size={17} className="mt-0.5 shrink-0 text-[var(--primary)]" /><div className="min-w-0"><span className="text-xs font-bold uppercase text-[var(--ink-500)]">{label}</span><p className="mt-1 break-words text-sm font-semibold text-[var(--ink-800)]">{value}</p></div></div>;
}

function ValueBlock({ label, value, tone }) {
    return <div><span className={`text-[10px] font-bold uppercase ${tone === 'success' ? 'text-emerald-700' : 'text-[var(--ink-500)]'}`}>{label}</span><strong className={`mt-1 block text-sm ${tone === 'success' ? 'text-emerald-700' : 'text-[var(--ink-900)]'}`}>{value}</strong></div>;
}

function TimelineStep({ label, date, user, complete, last }) {
    return (
        <div className="grid grid-cols-[28px_minmax(0,1fr)] gap-3">
            <div className="flex flex-col items-center">
                <span className={`mt-1 flex h-5 w-5 items-center justify-center rounded-full ${complete ? 'bg-emerald-600 text-white' : 'border-2 border-slate-300 bg-white'}`}>{complete && <CheckCircle2 size={13} />}</span>
                {!last && <span className={`min-h-12 w-px flex-1 ${complete ? 'bg-emerald-300' : 'bg-slate-200'}`} />}
            </div>
            <div className="pb-5">
                <p className={`text-sm font-bold ${complete ? 'text-[var(--ink-900)]' : 'text-[var(--ink-400)]'}`}>{label}</p>
                {date && <p className="mt-1 text-xs text-[var(--ink-500)]">{date}{user ? ` · ${user}` : ''}</p>}
            </div>
        </div>
    );
}

function AttachmentLinks({ attachments = [], ordem, tenant }) {
    if (!attachments.length) return null;
    return <div className="mt-2 flex flex-wrap gap-2">{attachments.map((attachment) => <a key={attachment.id} href={route('tenant.ordem-servico.os.documents.download', [tenant.slug, ordem.id, attachment.id])} className="inline-flex max-w-full items-center gap-1 rounded-md border border-[var(--border)] bg-white px-2 py-1 text-xs font-semibold text-[var(--primary)]"><Paperclip size={13} /><span className="truncate">{attachment.nome_original}</span></a>)}</div>;
}

function Avatar({ user }) {
    const initials = (user?.name || '?').split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    return user?.avatar_url
        ? <img src={user.avatar_url} alt={user.name} className="h-9 w-9 shrink-0 rounded-full object-cover" />
        : <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--primary-100)] text-xs font-bold text-[var(--primary)]">{initials}</span>;
}

function EmptyState({ text, compact = false }) {
    return <p className={`${compact ? 'py-5' : 'p-10'} text-center text-sm text-[var(--ink-500)]`}>{text}</p>;
}
