import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { consumeAssistantDraft } from '@/Utils/assistantDraft';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Building2,
    Ban,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    CircleDollarSign,
    ClipboardCheck,
    ClipboardList,
    Download,
    FileText,
    FolderKanban,
    HardHat,
    MessageSquare,
    Paperclip,
    Pencil,
    Play,
    Plus,
    Search,
    Send,
    Users,
    UserRound,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

const formatCurrency = (value) =>
    new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value || 0));

const formatPercentage = (value) =>
    new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(Number(value || 0));

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

function Field({ label, error, children }) {
    return (
        <label className="grid gap-1.5 text-sm">
            <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">{label}</span>
            {children}
            {error ? <span className="text-xs font-semibold text-red-600">{error}</span> : null}
        </label>
    );
}

function initials(name = '') {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase() || '?';
}

const orderFormDefaults = (contractId = '') => ({
    contract_id: contractId,
    obra_id: '',
    project_document_ids: [],
    gerenciadora_empresa_id: '',
    construtora_empresa_id: '',
    titulo: '',
    descricao: '',
    prazo_inicio: '',
    prazo_finalizacao: '',
    custo_previsto: '',
    custo_observacao: '',
    item_ids: [],
    responsavel_ids: [],
    documentos: [],
});

export default function OrdemServicoIndex({
    selectedContractId,
    editOrderId = null,
    editOrder = null,
    contracts = [],
    ordens = [],
    options = {},
    can = {},
}) {
    const page = usePage();
    const tenant = page.props.currentTenant;
    const currentUser = page.props.auth?.user;
    const [showForm, setShowForm] = useState(false);
    const [editingOrder, setEditingOrder] = useState(null);
    const [itemSearch, setItemSearch] = useState('');
    const [debouncedItemSearch, setDebouncedItemSearch] = useState('');
    const [planilhaFilter, setPlanilhaFilter] = useState('todas');
    const [itemResults, setItemResults] = useState([]);
    const [itemPlanilhas, setItemPlanilhas] = useState([]);
    const [itemPage, setItemPage] = useState(1);
    const [itemMeta, setItemMeta] = useState({
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: 0,
        from: null,
        to: null,
    });
    const [itemsLoading, setItemsLoading] = useState(false);
    const [itemsLoadError, setItemsLoadError] = useState('');
    const [selectedItemMap, setSelectedItemMap] = useState({});
    const [expandedOrderId, setExpandedOrderId] = useState(null);
    const [analysisOrder, setAnalysisOrder] = useState(null);
    const [analysisSubmitting, setAnalysisSubmitting] = useState(false);
    const [executionAction, setExecutionAction] = useState(null);
    const actionForm = useForm({ completion_summary: '', evidencias: [], motivo: '' });

    const form = useForm(orderFormDefaults(selectedContractId || ''));

    useEffect(() => {
        const assistantDraft = consumeAssistantDraft(tenant.id, 'service_order');

        if (!assistantDraft) {
            return;
        }

        setEditingOrder(null);
        setSelectedItemMap({});
        form.setData({
            ...orderFormDefaults(assistantDraft.contract_id || selectedContractId || ''),
            ...assistantDraft,
        });
        setShowForm(true);
    }, []);

    const selectedContract = contracts.find((contract) => Number(contract.id) === Number(form.data.contract_id));
    const obras = options.obras || [];
    const projects = options.projects || [];
    const empresas = options.empresas || [];
    const users = options.users || [];

    const empresaMatchesTipo = (empresa, tipo) => {
        const haystack = `${empresa.tipo_slug || ''} ${empresa.tipo_nome || ''}`.toLowerCase();

        return haystack.includes(tipo);
    };

    const gerenciadoras = empresas.filter((empresa) => empresaMatchesTipo(empresa, 'gerenciadora'));
    const construtoras = empresas.filter((empresa) => empresaMatchesTipo(empresa, 'construtora'));
    const gerenciadoraOptions = gerenciadoras.length ? gerenciadoras : empresas;
    const construtoraOptions = construtoras.length ? construtoras : empresas;

    const filteredProjects = projects.filter((project) => {
        if (form.data.obra_id && Number(project.obra_id) !== Number(form.data.obra_id)) {
            return false;
        }

        return true;
    });
    const selectedProjects = projects.filter((project) => form.data.project_document_ids.includes(project.id));

    const selectedItems = Object.values(selectedItemMap);
    const estimatedTotalP0 = selectedItems.reduce(
        (total, item) => total + Math.round(Number(item.valor_total_p0 ?? item.valor_total ?? 0) * 100) / 100,
        0
    );
    const estimatedTotalAdjusted = selectedItems.reduce(
        (total, item) => total + Number(item.valor_total_reajustado ?? item.valor_total ?? 0),
        0
    );

    useEffect(() => {
        const timeoutId = window.setTimeout(() => {
            setDebouncedItemSearch(itemSearch.trim());
            setItemPage(1);
        }, 300);

        return () => window.clearTimeout(timeoutId);
    }, [itemSearch]);

    useEffect(() => {
        if (!showForm || !form.data.contract_id) {
            return undefined;
        }

        const controller = new AbortController();
        const params = new URLSearchParams({
            contract_id: String(form.data.contract_id),
            page: String(itemPage),
        });

        if (debouncedItemSearch) {
            params.set('search', debouncedItemSearch);
        }

        if (planilhaFilter !== 'todas') {
            params.set('planilha', planilhaFilter);
        }

        setItemsLoading(true);
        setItemsLoadError('');

        fetch(`${route('tenant.ordem-servico.os.items', tenant.slug)}?${params.toString()}`, {
            headers: {
                Accept: 'application/json',
            },
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Não foi possível carregar os itens do contrato.');
                }

                return response.json();
            })
            .then((payload) => {
                setItemResults(payload.data || []);
                setItemMeta(payload.meta || {});
                setItemPlanilhas(payload.filters?.planilhas || []);
            })
            .catch((error) => {
                if (error.name !== 'AbortError') {
                    setItemsLoadError(error.message);
                    setItemResults([]);
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setItemsLoading(false);
                }
            });

        return () => controller.abort();
    }, [
        showForm,
        form.data.contract_id,
        itemPage,
        debouncedItemSearch,
        planilhaFilter,
        tenant.slug,
    ]);

    const changeContract = (contractId, keepFormOpen = false) => {
        if (keepFormOpen) {
            setItemSearch('');
            setDebouncedItemSearch('');
            setPlanilhaFilter('todas');
            setItemPage(1);
            setSelectedItemMap({});
            form.setData({
                ...form.data,
                contract_id: contractId,
                obra_id: '',
                project_document_ids: [],
                gerenciadora_empresa_id: '',
                construtora_empresa_id: '',
                item_ids: [],
            });
        }

        router.get(
            route('tenant.ordem-servico.os.index', tenant.slug),
            { contract_id: contractId },
            { preserveScroll: true, preserveState: keepFormOpen }
        );
    };

    useEffect(() => {
        if (!showForm) {
            return undefined;
        }

        const previousOverflow = document.body.style.overflow;
        const handleKeyDown = (event) => {
            if (event.key === 'Escape' && !form.processing) {
                setShowForm(false);
                setEditingOrder(null);
                form.clearErrors();
            }
        };

        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', handleKeyDown);

        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [showForm, form.processing]);

    useEffect(() => {
        if (!analysisOrder) {
            return undefined;
        }

        const previousOverflow = document.body.style.overflow;
        const handleKeyDown = (event) => {
            if (event.key === 'Escape' && !analysisSubmitting) {
                setAnalysisOrder(null);
            }
        };

        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', handleKeyDown);

        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [analysisOrder, analysisSubmitting]);

    const resetItemSelector = () => {
        setItemSearch('');
        setDebouncedItemSearch('');
        setPlanilhaFilter('todas');
        setItemPage(1);
        setSelectedItemMap({});
    };

    const closeForm = () => {
        if (form.processing) {
            return;
        }

        setShowForm(false);
        setEditingOrder(null);
        form.clearErrors();
    };

    const openCreateForm = () => {
        setEditingOrder(null);
        resetItemSelector();
        form.clearErrors();
        form.setData(orderFormDefaults(selectedContractId || ''));
        setShowForm(true);
    };

    const openEditForm = (ordem) => {
        const orderItems = (ordem.itens || []).map((item) => ({
            ...item,
            id: item.medicao_item_id,
            planilha: String(item.item || '').split('.')[0] || null,
        }));

        setEditingOrder(ordem);
        setItemSearch('');
        setDebouncedItemSearch('');
        setPlanilhaFilter('todas');
        setItemPage(1);
        setSelectedItemMap(Object.fromEntries(orderItems.map((item) => [item.id, item])));
        form.clearErrors();
        form.setData({
            contract_id: ordem.contract?.id || selectedContractId || '',
            obra_id: ordem.obra?.id || '',
            project_document_ids: (ordem.projects || []).map((project) => project.id),
            gerenciadora_empresa_id: ordem.gerenciadora_empresa?.id || '',
            construtora_empresa_id: ordem.construtora_empresa?.id || '',
            titulo: ordem.titulo || '',
            descricao: ordem.descricao || '',
            prazo_inicio: ordem.prazo_inicio || '',
            prazo_finalizacao: ordem.prazo_finalizacao || '',
            custo_previsto: formatCurrency(ordem.custo_previsto || 0),
            custo_observacao: ordem.custo_observacao || '',
            item_ids: orderItems.map((item) => item.id),
            responsavel_ids: (ordem.responsaveis || []).map((responsavel) => responsavel.user_id),
            documentos: [],
        });
        setShowForm(true);
    };

    const requestEditOrder = (ordem) => {
        router.get(
            route('tenant.ordem-servico.os.index', tenant.slug),
            { contract_id: selectedContractId, edit: ordem.id },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['editOrderId', 'editOrder'],
            },
        );
    };

    useEffect(() => {
        if (!editOrderId || editOrder?.status !== 'rascunho') {
            return;
        }

        openEditForm(editOrder);
    }, [editOrderId, editOrder]);

    const toggleId = (field, id) => {
        const current = form.data[field] || [];
        const next = current.includes(id)
            ? current.filter((value) => value !== id)
            : [...current, id];

        form.setData(field, next);
    };

    const toggleItem = (item) => {
        const checked = form.data.item_ids.includes(item.id);
        const nextIds = checked
            ? form.data.item_ids.filter((id) => id !== item.id)
            : [...form.data.item_ids, item.id];

        form.setData('item_ids', nextIds);
        setSelectedItemMap((current) => {
            const next = { ...current };

            if (checked) {
                delete next[item.id];
            } else {
                next[item.id] = item;
            }

            return next;
        });
    };

    const submit = (event) => {
        event.preventDefault();

        const isEditing = Boolean(editingOrder);
        const target = isEditing
            ? route('tenant.ordem-servico.os.update', [tenant.slug, editingOrder.id])
            : route('tenant.ordem-servico.os.store', tenant.slug);

        form.transform((data) => isEditing ? { ...data, _method: 'patch' } : data);
        form.post(target, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setShowForm(false);
                setEditingOrder(null);
                resetItemSelector();
                form.setData(orderFormDefaults(selectedContractId || ''));
            },
            onFinish: () => form.transform((data) => data),
        });
    };

    const submitForAnalysis = (ordem) => {
        setAnalysisOrder(ordem);
    };

    const confirmSubmitForAnalysis = () => {
        if (!analysisOrder || analysisSubmitting) {
            return;
        }

        setAnalysisSubmitting(true);
        router.patch(route('tenant.ordem-servico.os.submit-analysis', [tenant.slug, analysisOrder.id]), {}, {
            preserveScroll: true,
            onFinish: () => {
                setAnalysisSubmitting(false);
                setAnalysisOrder(null);
            },
        });
    };

    const closeExecutionAction = () => {
        setExecutionAction(null);
        actionForm.reset();
        actionForm.clearErrors();
    };

    const submitExecutionAction = (event) => {
        event.preventDefault();

        if (!executionAction) {
            return;
        }

        const { type, ordem } = executionAction;

        if (type === 'start') {
            router.patch(route('tenant.ordem-servico.os.start-execution', [tenant.slug, ordem.id]), {}, {
                preserveScroll: true,
                onFinish: closeExecutionAction,
            });
            return;
        }

        if (type === 'complete') {
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

    return (
        <AuthenticatedLayout>
            <Head title="Ordem de Serviço" />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                <section className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <span className="eyebrow">Execução</span>
                        <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">Ordem de Serviço</h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-[var(--ink-500)]">
                            Solicite a execução de serviços vinculando contrato, obra, projeto, itens, empresas responsáveis,
                            documentos e custos previstos.
                        </p>
                    </div>

                    {can.manage_drafts && (
                        <button
                            type="button"
                            onClick={openCreateForm}
                            className="sig-btn sig-btn-primary"
                        >
                            <Plus size={16} />
                            Nova OS
                        </button>
                    )}
                </section>

                {page.props.flash?.success && (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                        {page.props.flash.success}
                    </div>
                )}

                {Object.values(page.props.errors || {}).length > 0 && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                        {Object.values(page.props.errors)[0]}
                    </div>
                )}

                {analysisOrder && (
                    <div
                        className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.52)] p-4 backdrop-blur-[1px]"
                        role="presentation"
                        onMouseDown={(event) => {
                            if (event.target === event.currentTarget && !analysisSubmitting) {
                                setAnalysisOrder(null);
                            }
                        }}
                    >
                        <section
                            className="w-full max-w-lg overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="submit-analysis-title"
                            onMouseDown={(event) => event.stopPropagation()}
                        >
                            <header className="flex items-start gap-4 border-b border-[var(--border)] px-5 py-5">
                                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[var(--primary-50)] text-[var(--primary)]">
                                    <Send size={20} />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <span className="eyebrow">Ordem de serviço</span>
                                    <h2 id="submit-analysis-title" className="mt-1 text-lg font-bold text-[var(--ink-900)]">
                                        Enviar para análise?
                                    </h2>
                                    <p className="mt-2 text-sm leading-6 text-[var(--ink-500)]">
                                        A OS <strong className="text-[var(--ink-900)]">{analysisOrder.codigo}</strong> será
                                        encaminhada aos fiscais responsáveis pela obra.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-[var(--ink-500)] transition hover:bg-[var(--surface-muted)] hover:text-[var(--ink-900)] disabled:cursor-not-allowed disabled:opacity-50"
                                    aria-label="Fechar confirmação de envio"
                                    disabled={analysisSubmitting}
                                    onClick={() => setAnalysisOrder(null)}
                                >
                                    <X size={18} />
                                </button>
                            </header>

                            <div className="px-5 py-4">
                                <div className="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm leading-5 text-blue-800">
                                    Após o envio, a OS deixará de ser um rascunho e não poderá ser editada enquanto estiver em análise.
                                </div>
                            </div>

                            <footer className="flex flex-wrap justify-end gap-2 bg-[var(--surface-muted)] px-5 py-4">
                                <button
                                    type="button"
                                    className="sig-btn sig-btn-secondary"
                                    disabled={analysisSubmitting}
                                    onClick={() => setAnalysisOrder(null)}
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="button"
                                    className="sig-btn sig-btn-primary"
                                    disabled={analysisSubmitting}
                                    onClick={confirmSubmitForAnalysis}
                                    autoFocus
                                >
                                    <Send size={16} />
                                    {analysisSubmitting ? 'Enviando...' : 'Confirmar envio'}
                                </button>
                            </footer>
                        </section>
                    </div>
                )}

                {executionAction && (
                    <div
                        className="fixed inset-0 z-[125] flex items-center justify-center bg-slate-950/55 p-4"
                        role="presentation"
                        onMouseDown={(event) => event.target === event.currentTarget && closeExecutionAction()}
                    >
                        <form
                            onSubmit={submitExecutionAction}
                            onMouseDown={(event) => event.stopPropagation()}
                            className="w-full max-w-xl overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-2xl"
                            role="dialog"
                            aria-modal="true"
                        >
                            <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                                <div>
                                    <span className="eyebrow">{executionAction.ordem.codigo}</span>
                                    <h2 className="mt-1 text-lg font-bold text-[var(--ink-900)]">
                                        {executionAction.type === 'start' && 'Iniciar execução da OS?'}
                                        {executionAction.type === 'complete' && 'Concluir ordem de serviço'}
                                        {executionAction.type === 'cancel' && 'Cancelar ordem de serviço'}
                                    </h2>
                                </div>
                                <button type="button" onClick={closeExecutionAction} className="flex h-9 w-9 items-center justify-center rounded-md hover:bg-[var(--surface-muted)]">
                                    <X size={18} />
                                </button>
                            </header>

                            <div className="grid gap-4 p-5">
                                {executionAction.type === 'start' && (
                                    <p className="text-sm leading-6 text-[var(--ink-600)]">
                                        A OS passará para <strong>Em execução</strong>. Responsáveis, fiscais e aprovadores serão avisados.
                                    </p>
                                )}
                                {executionAction.type === 'complete' && (
                                    <>
                                        <Field label="Resumo da execução" error={actionForm.errors.completion_summary}>
                                            <textarea
                                                value={actionForm.data.completion_summary}
                                                onChange={(event) => actionForm.setData('completion_summary', event.target.value)}
                                                className="sig-input min-h-32"
                                                placeholder="Registre o serviço executado, ocorrências e resultado final."
                                            />
                                        </Field>
                                        <Field label="Evidências da conclusão" error={actionForm.errors.evidencias}>
                                            <input
                                                type="file"
                                                multiple
                                                onChange={(event) => actionForm.setData('evidencias', Array.from(event.target.files || []))}
                                                className="sig-input file:mr-4 file:rounded-md file:border-0 file:bg-[var(--primary-50)] file:px-3 file:py-2 file:text-sm file:font-bold file:text-[var(--primary)]"
                                            />
                                            <span className="text-xs text-[var(--ink-500)]">Ao menos uma evidência é obrigatória.</span>
                                        </Field>
                                    </>
                                )}
                                {executionAction.type === 'cancel' && (
                                    <Field label="Motivo do cancelamento" error={actionForm.errors.motivo}>
                                        <textarea
                                            value={actionForm.data.motivo}
                                            onChange={(event) => actionForm.setData('motivo', event.target.value)}
                                            className="sig-input min-h-28"
                                            placeholder="Explique por que a OS não seguirá para execução."
                                        />
                                    </Field>
                                )}
                            </div>

                            <footer className="flex justify-end gap-2 border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                                <button type="button" onClick={closeExecutionAction} className="sig-btn sig-btn-secondary">Voltar</button>
                                <button
                                    type="submit"
                                    disabled={actionForm.processing}
                                    className={`sig-btn ${executionAction.type === 'cancel' ? 'bg-red-600 text-white hover:bg-red-700' : 'sig-btn-primary'}`}
                                >
                                    {executionAction.type === 'start' && <Play size={16} />}
                                    {executionAction.type === 'complete' && <CheckCircle2 size={16} />}
                                    {executionAction.type === 'cancel' && <Ban size={16} />}
                                    {executionAction.type === 'start' ? 'Iniciar execução' : executionAction.type === 'complete' ? 'Concluir OS' : 'Confirmar cancelamento'}
                                </button>
                            </footer>
                        </form>
                    </div>
                )}

                <section className="sig-card p-5">
                    <div className="grid gap-4 md:grid-cols-3 lg:grid-cols-[1fr_190px_220px_220px] lg:items-end">
                        <Field label="Contrato">
                            <select
                                value={selectedContractId || ''}
                                onChange={(event) => changeContract(event.target.value)}
                                className="sig-input"
                            >
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>
                                        {contract.code} - {contract.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <MetricCard icon={ClipboardList} label="OS cadastradas" value={ordens.length} />
                        <MetricCard
                            icon={ClipboardCheck}
                            label="Custo previsto"
                            value={formatCurrency(ordens.reduce((sum, ordem) => sum + Number(ordem.custo_previsto || 0), 0))}
                        />
                        <MetricCard
                            icon={CircleDollarSign}
                            label="Custo real"
                            value={formatCurrency(ordens.reduce((sum, ordem) => sum + Number(ordem.custo_real || 0), 0))}
                        />
                    </div>
                </section>

                {showForm && (
                    <div
                        className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/55 p-3 sm:p-6"
                        role="presentation"
                        onMouseDown={(event) => {
                            if (event.target === event.currentTarget && !form.processing) {
                                closeForm();
                            }
                        }}
                    >
                    <form
                        onSubmit={submit}
                        onMouseDown={(event) => event.stopPropagation()}
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="create-service-order-title"
                        className="flex max-h-[calc(100dvh-1.5rem)] w-full max-w-7xl flex-col overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-2xl sm:max-h-[calc(100dvh-3rem)]"
                    >
                        <header className="flex shrink-0 items-start justify-between gap-4 border-b border-[var(--border)] bg-white px-5 py-4">
                            <div className="flex min-w-0 items-center gap-3">
                                <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                                    <HardHat size={20} />
                                </span>
                                <div className="min-w-0">
                                    <h2 id="create-service-order-title" className="text-lg font-bold text-[var(--ink-900)]">
                                        {editingOrder ? `Editar ${editingOrder.codigo}` : 'Criar ordem de serviço'}
                                    </h2>
                                    <p className="text-sm text-[var(--ink-500)]">
                                        {editingOrder
                                            ? 'A edição está disponível enquanto a OS permanecer em rascunho.'
                                            : 'O usuário logado será registrado automaticamente como solicitante.'}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={closeForm}
                                disabled={form.processing}
                                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-[var(--ink-500)] transition hover:bg-[var(--surface-muted)] hover:text-[var(--ink-900)] disabled:cursor-not-allowed disabled:opacity-50"
                                aria-label="Fechar formulário da ordem de serviço"
                            >
                                <X size={20} />
                            </button>
                        </header>

                        <div className="grid flex-1 gap-5 overflow-y-auto p-5">
                            {Object.values(form.errors).length > 0 && (
                                <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                                    Revise os campos destacados antes de salvar a OS.
                                </div>
                            )}

                            <div className="grid gap-4 lg:grid-cols-3">
                                <Field label="Contrato" error={form.errors.contract_id}>
                                    <select
                                        value={form.data.contract_id}
                                        onChange={(event) => changeContract(event.target.value, true)}
                                        className="sig-input"
                                    >
                                        {contracts.map((contract) => (
                                            <option key={contract.id} value={contract.id}>
                                                {contract.code} - {contract.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>

                                <Field label="Obra" error={form.errors.obra_id}>
                                    <select
                                        value={form.data.obra_id}
                                        onChange={(event) => {
                                            form.setData({
                                                ...form.data,
                                                obra_id: event.target.value,
                                                project_document_ids: [],
                                            });
                                        }}
                                        className="sig-input"
                                    >
                                        <option value="">Selecione a obra</option>
                                        {obras.map((obra) => (
                                            <option key={obra.id} value={obra.id}>{obra.label}</option>
                                        ))}
                                    </select>
                                </Field>

                                <Field label="Projetos vinculados" error={form.errors.project_document_ids}>
                                    <div className="max-h-40 overflow-auto rounded-lg border border-[var(--border)] bg-white">
                                        {form.data.obra_id === '' ? (
                                            <p className="p-3 text-xs font-semibold text-[var(--ink-500)]">Selecione uma obra para listar os projetos.</p>
                                        ) : filteredProjects.length === 0 ? (
                                            <p className="p-3 text-xs font-semibold text-[var(--ink-500)]">Nenhum projeto aprovado encontrado para esta obra.</p>
                                        ) : filteredProjects.map((project) => {
                                            const checked = form.data.project_document_ids.includes(project.id);

                                            return (
                                                <button
                                                    key={project.id}
                                                    type="button"
                                                    onClick={() => toggleId('project_document_ids', project.id)}
                                                    className={`flex w-full items-start gap-2 border-b border-[var(--border)] p-2 text-left text-xs last:border-b-0 hover:bg-[var(--primary-50)] ${checked ? 'bg-emerald-50' : 'bg-white'}`}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => toggleId('project_document_ids', project.id)}
                                                        onClick={(event) => event.stopPropagation()}
                                                        className="mt-0.5"
                                                    />
                                                    <span className="min-w-0 font-semibold text-[var(--ink-800)]">{project.label}</span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                    <span className="text-xs text-[var(--ink-500)]">
                                        {selectedProjects.length} projeto(s) selecionado(s)
                                    </span>
                                </Field>
                            </div>

                            <div className="grid gap-4 lg:grid-cols-3">
                                <Field label="Gerenciadora da obra" error={form.errors.gerenciadora_empresa_id}>
                                    <select
                                        value={form.data.gerenciadora_empresa_id}
                                        onChange={(event) => form.setData('gerenciadora_empresa_id', event.target.value)}
                                        className="sig-input"
                                    >
                                        <option value="">Selecione a gerenciadora</option>
                                        {gerenciadoraOptions.map((empresa) => (
                                            <option key={empresa.id} value={empresa.id}>{empresa.label}</option>
                                        ))}
                                    </select>
                                </Field>

                                <Field label="Construtora solicitante" error={form.errors.construtora_empresa_id}>
                                    <select
                                        value={form.data.construtora_empresa_id}
                                        onChange={(event) => form.setData('construtora_empresa_id', event.target.value)}
                                        className="sig-input"
                                    >
                                        <option value="">Selecione a construtora</option>
                                        {construtoraOptions.map((empresa) => (
                                            <option key={empresa.id} value={empresa.id}>{empresa.label}</option>
                                        ))}
                                    </select>
                                </Field>

                                <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
                                    <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                        Solicitante
                                    </span>
                                    <div className="mt-2 flex items-center gap-3">
                                        <Avatar user={currentUser} />
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-bold text-[var(--ink-900)]">{currentUser?.name || 'Usuário logado'}</p>
                                            <p className="truncate text-xs text-[var(--ink-500)]">{currentUser?.email || 'Registrado automaticamente'}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <Field label="Título" error={form.errors.titulo}>
                                <input
                                    value={form.data.titulo}
                                    onChange={(event) => form.setData('titulo', event.target.value)}
                                    className="sig-input"
                                    placeholder="Ex: Execução da drenagem do trecho 01"
                                />
                            </Field>

                            <div className="grid gap-4 md:grid-cols-3">
                                <Field label="Prazo para início" error={form.errors.prazo_inicio}>
                                    <input
                                        type="date"
                                        value={form.data.prazo_inicio}
                                        onChange={(event) => form.setData('prazo_inicio', event.target.value)}
                                        className="sig-input"
                                    />
                                </Field>

                                <Field label="Prazo para finalização" error={form.errors.prazo_finalizacao}>
                                    <input
                                        type="date"
                                        min={form.data.prazo_inicio || undefined}
                                        value={form.data.prazo_finalizacao}
                                        onChange={(event) => form.setData('prazo_finalizacao', event.target.value)}
                                        className="sig-input"
                                    />
                                </Field>

                                <Field label="Custo previsto pelos itens">
                                    <input
                                        value={formatCurrency(estimatedTotalP0)}
                                        className="sig-input bg-[var(--surface-muted)] font-semibold"
                                        readOnly
                                    />
                                </Field>
                            </div>

                            <Field label="Descrição" error={form.errors.descricao}>
                                <textarea
                                    value={form.data.descricao}
                                    onChange={(event) => form.setData('descricao', event.target.value)}
                                    className="sig-input min-h-28"
                                    placeholder="Descreva o escopo, restrições e premissas da execução."
                                />
                            </Field>

                            <section className="rounded-lg border border-[var(--border)] p-4">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                                        <Users size={18} />
                                    </span>
                                    <div>
                                        <h3 className="text-sm font-bold text-[var(--ink-900)]">Responsáveis pela execução</h3>
                                        <p className="text-xs text-[var(--ink-500)]">Selecione quem acompanhará a execução e receberá as atualizações desta OS.</p>
                                    </div>
                                </div>
                                <div className="mt-3 grid max-h-40 gap-2 overflow-auto sm:grid-cols-2 lg:grid-cols-3">
                                    {users.length === 0 ? (
                                        <p className="text-sm text-[var(--ink-500)]">Nenhum usuário disponível neste contrato.</p>
                                    ) : users.map((user) => {
                                        const checked = form.data.responsavel_ids.includes(user.id);

                                        return (
                                            <label
                                                key={user.id}
                                                className={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition ${checked ? 'border-blue-300 bg-blue-50' : 'border-[var(--border)] bg-white hover:bg-[var(--surface-muted)]'}`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={checked}
                                                    onChange={() => toggleId('responsavel_ids', user.id)}
                                                />
                                                <Avatar user={user} />
                                                <span className="min-w-0">
                                                    <strong className="block truncate text-sm text-[var(--ink-900)]">{user.name}</strong>
                                                    <span className="block truncate text-xs text-[var(--ink-500)]">{user.email}</span>
                                                </span>
                                            </label>
                                        );
                                    })}
                                </div>
                                {form.errors.responsavel_ids && <p className="mt-2 text-xs font-semibold text-red-600">{form.errors.responsavel_ids}</p>}
                            </section>

                            <SelectionPanel
                                title="Itens de contrato vinculados"
                                icon={FolderKanban}
                                search={itemSearch}
                                setSearch={(value) => setItemSearch(value)}
                                placeholder="Buscar por item, código ou descrição"
                                count={selectedItems.length}
                                extraControls={(
                                    <select
                                        value={planilhaFilter}
                                        onChange={(event) => {
                                            setPlanilhaFilter(event.target.value);
                                            setItemPage(1);
                                        }}
                                        className="sig-input w-full sm:w-48"
                                    >
                                        <option value="todas">Todas as planilhas</option>
                                        {itemPlanilhas.map((planilha) => (
                                            <option key={planilha} value={planilha}>Planilha {planilha}</option>
                                        ))}
                                    </select>
                                )}
                            >
                                {selectedItems.length > 0 && (
                                    <div className="flex max-h-24 flex-wrap gap-2 overflow-y-auto rounded-lg border border-emerald-200 bg-emerald-50 p-2">
                                        {selectedItems.map((item) => (
                                            <button
                                                key={item.id}
                                                type="button"
                                                onClick={() => toggleItem(item)}
                                                className="flex max-w-full items-center gap-1 rounded-md border border-emerald-200 bg-white px-2 py-1 text-xs font-semibold text-emerald-800 transition hover:border-emerald-400"
                                                title="Remover item selecionado"
                                            >
                                                <span className="truncate">{item.item} - {item.codigo || '-'}</span>
                                                <X size={13} className="shrink-0" />
                                            </button>
                                        ))}
                                    </div>
                                )}

                                <div className="max-h-80 divide-y divide-[var(--border)] overflow-auto rounded-lg border border-[var(--border)]">
                                    {itemsLoading ? (
                                        <p className="p-6 text-center text-sm font-semibold text-[var(--ink-500)]">
                                            Carregando itens...
                                        </p>
                                    ) : itemsLoadError ? (
                                        <p className="p-6 text-center text-sm font-semibold text-red-600">{itemsLoadError}</p>
                                    ) : itemResults.length === 0 ? (
                                        <p className="p-4 text-sm text-[var(--ink-500)]">Nenhum item encontrado.</p>
                                    ) : itemResults.map((item) => {
                                        const checked = form.data.item_ids.includes(item.id);

                                        return (
                                            <button
                                                key={item.id}
                                                type="button"
                                                onClick={() => toggleItem(item)}
                                                className={`grid w-full gap-2 p-3 text-left transition hover:bg-[var(--primary-50)] ${
                                                    checked ? 'bg-emerald-50' : 'bg-white'
                                                }`}
                                            >
                                                <div className="flex items-start gap-3">
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => toggleItem(item)}
                                                        onClick={(event) => event.stopPropagation()}
                                                        className="mt-1"
                                                    />
                                                    <div className="min-w-0 flex-1">
                                                        <p className="text-sm font-bold text-[var(--ink-900)]">
                                                            {item.item} - {item.codigo || '-'}
                                                        </p>
                                                        <p className="mt-1 line-clamp-2 text-xs text-[var(--ink-600)]">
                                                            {item.descricao}
                                                        </p>
                                                    </div>
                                                    <div className="grid shrink-0 gap-1 text-right">
                                                        <span className="whitespace-nowrap text-[10px] font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                                            P0
                                                        </span>
                                                        <strong className="whitespace-nowrap text-xs text-[var(--ink-900)]">
                                                            {formatCurrency(item.valor_total_p0 ?? item.valor_total)}
                                                        </strong>
                                                        <span className="mt-1 whitespace-nowrap text-[10px] font-bold uppercase tracking-wide text-emerald-700">
                                                            Reajustado
                                                        </span>
                                                        <strong className="whitespace-nowrap text-xs text-emerald-700">
                                                            {formatCurrency(item.valor_total_reajustado ?? item.valor_total)}
                                                        </strong>
                                                    </div>
                                                </div>
                                            </button>
                                        );
                                    })}
                                </div>
                                <div className="flex flex-col gap-2 text-xs text-[var(--ink-500)] sm:flex-row sm:items-center sm:justify-between">
                                    <span>
                                        {itemMeta.total > 0
                                            ? `Exibindo ${itemMeta.from}-${itemMeta.to} de ${itemMeta.total} itens`
                                            : 'Nenhum item disponível'}
                                    </span>
                                    <div className="flex items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={() => setItemPage((current) => Math.max(1, current - 1))}
                                            disabled={itemsLoading || itemMeta.current_page <= 1}
                                            className="sig-btn sig-btn-secondary min-h-9 px-3 py-1.5 text-xs"
                                        >
                                            Anterior
                                        </button>
                                        <span className="min-w-20 text-center font-semibold text-[var(--ink-700)]">
                                            {itemMeta.current_page || 1} de {itemMeta.last_page || 1}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => setItemPage((current) => Math.min(itemMeta.last_page || 1, current + 1))}
                                            disabled={itemsLoading || itemMeta.current_page >= itemMeta.last_page}
                                            className="sig-btn sig-btn-secondary min-h-9 px-3 py-1.5 text-xs"
                                        >
                                            Próxima
                                        </button>
                                    </div>
                                </div>
                                <div className="grid gap-2 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3 text-xs sm:grid-cols-2">
                                    <p className="font-semibold text-[var(--ink-500)]">
                                        Total inicial P0:
                                        <strong className="ml-1 text-[var(--ink-900)]">{formatCurrency(estimatedTotalP0)}</strong>
                                    </p>
                                    <p className="font-semibold text-[var(--ink-500)] sm:text-right">
                                        Total com reajuste:
                                        <strong className="ml-1 text-emerald-700">{formatCurrency(estimatedTotalAdjusted)}</strong>
                                    </p>
                                </div>
                            </SelectionPanel>

                            <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
                                <Field label={editingOrder ? 'Adicionar documentos' : 'Documentos para execução'} error={form.errors.documentos}>
                                    <input
                                        type="file"
                                        multiple
                                        onChange={(event) => form.setData('documentos', Array.from(event.target.files || []))}
                                        className="sig-input file:mr-4 file:rounded-md file:border-0 file:bg-[var(--primary-50)] file:px-3 file:py-2 file:text-sm file:font-bold file:text-[var(--primary)]"
                                    />
                                    <span className="text-xs text-[var(--ink-500)]">
                                        {editingOrder
                                            ? `${editingOrder.documentos_count || 0} documento(s) existente(s) serão preservados. Você pode anexar novos arquivos.`
                                            : 'Anexe memoriais, projetos, permissões ou documentos complementares.'}
                                    </span>
                                </Field>

                                <Field label="Observação de custos" error={form.errors.custo_observacao}>
                                    <textarea
                                        value={form.data.custo_observacao}
                                        onChange={(event) => form.setData('custo_observacao', event.target.value)}
                                        className="sig-input min-h-24"
                                        placeholder="Detalhe premissas, limites ou custos indiretos."
                                    />
                                </Field>
                            </div>
                        </div>

                        <footer className="flex shrink-0 flex-wrap items-center justify-end gap-3 border-t border-[var(--border)] bg-white px-5 py-4">
                            <button type="button" onClick={closeForm} disabled={form.processing} className="sig-btn sig-btn-secondary">
                                Cancelar
                            </button>
                            <button type="submit" disabled={form.processing} className="sig-btn sig-btn-primary">
                                {editingOrder ? <Pencil size={16} /> : <Plus size={16} />}
                                {form.processing
                                    ? (editingOrder ? 'Salvando...' : 'Criando...')
                                    : (editingOrder ? 'Salvar alterações' : 'Criar OS')}
                            </button>
                        </footer>
                    </form>
                    </div>
                )}

                <section className="sig-card overflow-hidden">
                    <header className="border-b border-[var(--border)] px-5 py-4">
                        <h2 className="text-lg font-bold text-[var(--ink-900)]">Ordens de serviço</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            {selectedContract
                                ? `${selectedContract.code} - ${selectedContract.name}`
                                : 'Selecione um contrato para listar as ordens.'}
                        </p>
                    </header>

                    {ordens.length === 0 ? (
                        <div className="p-10 text-center">
                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                                <ClipboardList size={22} />
                            </div>
                            <p className="mt-3 text-sm font-bold text-[var(--ink-900)]">Nenhuma OS cadastrada</p>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                Crie a primeira ordem de serviço para este contrato.
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y divide-[var(--border)]">
                            {ordens.map((ordem) => (
                                <article key={ordem.id}>
                                    <div className="grid w-full items-center gap-3 p-4 text-left transition hover:bg-[var(--surface-muted)] md:grid-cols-[110px_minmax(0,1fr)_130px] xl:grid-cols-[110px_minmax(180px,1fr)_130px_400px_auto]">
                                        <p className="mono font-bold text-[var(--primary)]">{ordem.codigo}</p>
                                        <div className="min-w-0">
                                            <h3 className="truncate text-sm font-bold text-[var(--ink-900)]">{ordem.titulo}</h3>
                                            <p className="truncate text-xs text-[var(--ink-500)]">
                                                {ordem.obra?.nome || 'Sem obra'} · {ordem.solicitante?.name || 'Sem solicitante'}
                                            </p>
                                        </div>
                                        <span className={`w-fit rounded-full px-3 py-1 text-xs font-bold ${statusClasses[ordem.status] || statusClasses.rascunho}`}>
                                            {statusLabels[ordem.status] || ordem.status}
                                        </span>
                                        <div className="grid min-w-0 grid-cols-[minmax(125px,1fr)_minmax(125px,1fr)_minmax(72px,0.7fr)] gap-4 md:col-span-2 md:col-start-2 xl:col-span-1 xl:col-start-auto">
                                            <div>
                                                <span className="block text-[10px] font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                                    Previsto
                                                </span>
                                                <strong className="mt-1 block whitespace-nowrap text-sm text-[var(--ink-900)]">
                                                    {formatCurrency(ordem.custo_previsto)}
                                                </strong>
                                            </div>
                                            <div>
                                                <span className="block text-[10px] font-bold uppercase tracking-wide text-emerald-700">
                                                    Real
                                                </span>
                                                <strong className="mt-1 block whitespace-nowrap text-sm text-emerald-700">
                                                    {formatCurrency(ordem.custo_real)}
                                                </strong>
                                            </div>
                                            <div>
                                                <span className="block text-[10px] font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                                    Medido
                                                </span>
                                                <strong className="mt-1 block whitespace-nowrap text-sm text-[var(--primary)]">
                                                    {formatPercentage(ordem.percentual_medido)}%
                                                </strong>
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap justify-end gap-2 md:col-start-3 xl:col-start-auto">
                                            {ordem.status === 'rascunho' && can.manage_drafts && (
                                                <button type="button" onClick={() => requestEditOrder(ordem)} className="sig-btn sig-btn-secondary">
                                                    <Pencil size={15} />
                                                    Editar
                                                </button>
                                            )}
                                            <Link href={route('tenant.ordem-servico.os.show', [tenant.slug, ordem.id])} className="sig-btn sig-btn-secondary">
                                                Abrir
                                                <ChevronRight size={15} />
                                            </Link>
                                        </div>
                                    </div>

                                    <div className="hidden">
                                    <div>
                                        <p className="mono text-lg font-bold text-[var(--primary)]">{ordem.codigo}</p>
                                        <span className={`mt-3 inline-flex rounded-full px-3 py-1 text-xs font-bold ${statusClasses[ordem.status] || statusClasses.rascunho}`}>
                                            {statusLabels[ordem.status] || ordem.status}
                                        </span>
                                        <p className="mt-3 text-xs text-[var(--ink-500)]">Criada em {ordem.created_at}</p>
                                    </div>

                                    <div className="min-w-0">
                                        <h3 className="text-lg font-bold text-[var(--ink-900)]">{ordem.titulo}</h3>
                                        {ordem.descricao && (
                                            <p className="mt-2 line-clamp-2 text-sm leading-6 text-[var(--ink-500)]">{ordem.descricao}</p>
                                        )}

                                        <div className="mt-4 grid gap-3 md:grid-cols-3">
                                            <InfoLine icon={HardHat} label="Obra" value={ordem.obra?.nome || 'Sem obra'} />
                                            <InfoLine
                                                icon={FileText}
                                                label="Projetos"
                                                value={ordem.projects?.length
                                                    ? `${ordem.projects.length} projeto(s)`
                                                    : 'Sem projeto'}
                                            />
                                            <InfoLine icon={CalendarDays} label="Prazo para início" value={ordem.prazo_inicio_label || 'Sem prazo'} />
                                            <InfoLine icon={CalendarDays} label="Prazo para finalização" value={ordem.prazo_finalizacao_label || 'Sem prazo'} />
                                        </div>

                                        {ordem.projects?.length > 0 && (
                                            <div className="mt-4 rounded-lg border border-[var(--border)] bg-white p-3">
                                                <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                                    Projetos vinculados
                                                </span>
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {ordem.projects.map((project) => (
                                                        <span
                                                            key={project.id}
                                                            className="rounded-full bg-[var(--primary-50)] px-3 py-1 text-xs font-bold text-[var(--primary)]"
                                                            title={project.title}
                                                        >
                                                            {project.code || project.title}
                                                        </span>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        <div className="mt-4 max-h-80 space-y-2 overflow-auto rounded-lg border border-[var(--border)] p-2">
                                            <div className="sticky top-0 z-10 flex items-center justify-between rounded-md bg-white px-3 py-2 shadow-sm">
                                                <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                                    Itens vinculados
                                                </span>
                                                <span className="rounded-full bg-[var(--surface-muted)] px-2.5 py-1 text-xs font-bold text-[var(--ink-600)]">
                                                    {ordem.itens.length} {ordem.itens.length === 1 ? 'item' : 'itens'}
                                                </span>
                                            </div>
                                            {ordem.itens.map((item) => (
                                                <span
                                                    key={item.id}
                                                    className="grid gap-2 rounded-md bg-slate-100 px-3 py-2 text-xs text-[var(--ink-600)] sm:grid-cols-[minmax(0,1fr)_112px_112px_120px]"
                                                    title={item.descricao}
                                                >
                                                    <div className="min-w-0">
                                                        <p className="font-bold text-[var(--ink-900)]">
                                                            {item.item} - {item.codigo || 'sem código'}
                                                        </p>
                                                        <p className="mt-1 whitespace-normal leading-5">{item.descricao}</p>
                                                    </div>
                                                    <div>
                                                        <span className="block text-[10px] font-bold uppercase tracking-wide text-[var(--ink-500)]">Valor P0</span>
                                                        <strong className="mt-1 block whitespace-nowrap text-[var(--ink-900)]">
                                                            {formatCurrency(item.valor_previsto)}
                                                        </strong>
                                                    </div>
                                                    <div>
                                                        <span className="block text-[10px] font-bold uppercase tracking-wide text-emerald-700">Reajustado</span>
                                                        <strong className="mt-1 block whitespace-nowrap text-emerald-700">
                                                            {formatCurrency(item.valor_reajustado)}
                                                        </strong>
                                                    </div>
                                                    <div>
                                                        <span className="block text-[10px] font-bold uppercase tracking-wide text-[var(--ink-500)]">Medido</span>
                                                        <div className="mt-1 flex items-center gap-2">
                                                            <span className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-slate-200">
                                                                <span
                                                                    className="block h-full rounded-full bg-[var(--primary)]"
                                                                    style={{
                                                                        width: `${Math.max(0, Math.min(100, Number(item.percentual_medido || 0)))}%`,
                                                                    }}
                                                                />
                                                            </span>
                                                            <strong className="whitespace-nowrap text-[var(--primary)]">
                                                                {formatPercentage(item.percentual_medido)}%
                                                            </strong>
                                                        </div>
                                                        <span className="mt-1 block whitespace-nowrap text-[10px] font-semibold text-emerald-700">
                                                            Custo real: {formatCurrency(item.custo_real)}
                                                        </span>
                                                    </div>
                                                </span>
                                            ))}
                                        </div>

                                        <div className="mt-4 grid gap-3 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4 sm:grid-cols-2">
                                            <div>
                                                <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">Responsáveis pela execução</span>
                                                <p className="mt-1 text-sm font-semibold text-[var(--ink-800)]">
                                                    {ordem.responsaveis?.length
                                                        ? ordem.responsaveis.map((responsavel) => responsavel.name).join(', ')
                                                        : 'Nenhum responsável vinculado'}
                                                </p>
                                            </div>
                                            <div>
                                                <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">Registro da execução</span>
                                                <p className="mt-1 text-sm text-[var(--ink-700)]">
                                                    {ordem.completed_at
                                                        ? `Concluída por ${ordem.completed_by?.name || 'usuário'} em ${ordem.completed_at}`
                                                        : ordem.execution_started_at
                                                            ? `Iniciada por ${ordem.execution_started_by?.name || 'usuário'} em ${ordem.execution_started_at}`
                                                            : ordem.cancelled_at
                                                                ? `Cancelada por ${ordem.cancelled_by?.name || 'usuário'} em ${ordem.cancelled_at}`
                                                                : 'Execução ainda não iniciada'}
                                                </p>
                                            </div>
                                            {ordem.completion_summary && (
                                                <p className="text-sm leading-6 text-[var(--ink-700)] sm:col-span-2"><strong>Conclusão:</strong> {ordem.completion_summary}</p>
                                            )}
                                            {ordem.cancellation_reason && (
                                                <p className="text-sm leading-6 text-red-700 sm:col-span-2"><strong>Cancelamento:</strong> {ordem.cancellation_reason}</p>
                                            )}
                                        </div>

                                        <OrderConversation ordem={ordem} tenant={tenant} users={users} />
                                    </div>

                                    <div className="grid content-between gap-4">
                                        <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
                                            <div className="rounded-lg bg-[var(--surface-muted)] p-4">
                                                <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">Custo previsto</span>
                                                <strong className="mt-2 block text-xl text-[var(--ink-900)]">{formatCurrency(ordem.custo_previsto)}</strong>
                                            </div>
                                            <div className="rounded-lg bg-emerald-50 p-4">
                                                <span className="text-xs font-bold uppercase tracking-wide text-emerald-700">Custo real</span>
                                                <strong className="mt-2 block text-xl text-emerald-800">{formatCurrency(ordem.custo_real)}</strong>
                                            </div>
                                        </div>

                                        <div className="grid gap-2 text-sm">
                                            <InfoLine icon={Building2} label="Gerenciadora" value={ordem.gerenciadora_empresa?.nome || 'Não definida'} />
                                            <InfoLine icon={Building2} label="Construtora" value={ordem.construtora_empresa?.nome || 'Não definida'} />
                                            <InfoLine icon={UserRound} label="Solicitante" value={ordem.solicitante?.name || 'Não identificado'} />
                                        </div>

                                        <div className="flex items-center gap-2 text-sm font-semibold text-[var(--ink-500)]">
                                            <Paperclip size={16} />
                                            {ordem.documentos_count} documento(s)
                                        </div>

                                        {ordem.status === 'rascunho' && can.manage_drafts && (
                                            <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
                                                <button
                                                    type="button"
                                                    onClick={() => openEditForm(ordem)}
                                                    className="sig-btn sig-btn-secondary justify-center"
                                                >
                                                    <Pencil size={16} />
                                                    Editar
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => submitForAnalysis(ordem)}
                                                    className="sig-btn sig-btn-primary justify-center"
                                                >
                                                    <Send size={16} />
                                                    Enviar para análise
                                                </button>
                                            </div>
                                        )}

                                        {ordem.status === 'aprovada' && can.execute && (
                                            <button
                                                type="button"
                                                onClick={() => setExecutionAction({ type: 'start', ordem })}
                                                className="sig-btn sig-btn-primary justify-center"
                                            >
                                                <Play size={16} />
                                                Iniciar execução
                                            </button>
                                        )}

                                        {ordem.status === 'em_execucao' && can.complete && (
                                            <button
                                                type="button"
                                                onClick={() => setExecutionAction({ type: 'complete', ordem })}
                                                className="sig-btn bg-emerald-600 text-white hover:bg-emerald-700 justify-center"
                                            >
                                                <CheckCircle2 size={16} />
                                                Concluir OS
                                            </button>
                                        )}

                                        {['rascunho', 'aprovada', 'em_execucao'].includes(ordem.status) && can.complete && (
                                            <button
                                                type="button"
                                                onClick={() => setExecutionAction({ type: 'cancel', ordem })}
                                                className="sig-btn sig-btn-secondary justify-center text-red-700"
                                            >
                                                <Ban size={16} />
                                                Cancelar OS
                                            </button>
                                        )}

                                        {ordem.status === 'concluida' && (
                                            <a
                                                href={route('tenant.ordem-servico.os.pdf', [tenant.slug, ordem.id])}
                                                className="sig-btn sig-btn-secondary justify-center"
                                            >
                                                <Download size={16} />
                                                Baixar PDF final
                                            </a>
                                        )}
                                    </div>
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
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

    const cancelReply = () => {
        setReplyTo(null);
        form.setData({ ...form.data, parent_id: '', body: '', mention_user_ids: [], anexos: [] });
    };

    const toggleResolved = (comment) => {
        router.patch(route('tenant.ordem-servico.os.comments.resolve', [tenant.slug, ordem.id, comment.id]), {}, {
            preserveScroll: true,
        });
    };

    return (
        <section className="mt-4 overflow-hidden rounded-lg border border-[var(--border)] bg-white">
            <header className="flex items-center justify-between gap-3 border-b border-[var(--border)] px-4 py-3">
                <div className="flex items-center gap-2">
                    <MessageSquare size={17} className="text-[var(--primary)]" />
                    <h4 className="text-sm font-bold text-[var(--ink-900)]">Acompanhamento da OS</h4>
                </div>
                <span className="text-xs font-semibold text-[var(--ink-500)]">{ordem.comentarios?.length || 0} registro(s)</span>
            </header>

            <div className="max-h-96 divide-y divide-[var(--border)] overflow-auto">
                {(ordem.comentarios || []).length === 0 ? (
                    <p className="p-5 text-center text-sm text-[var(--ink-500)]">Nenhum comentário ou pendência registrado.</p>
                ) : ordem.comentarios.map((comment) => (
                    <article key={comment.id} className={`p-4 ${comment.tipo === 'pendencia' ? 'bg-amber-50/60' : ''}`}>
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p className="text-sm font-bold text-[var(--ink-900)]">{comment.user?.name || 'Usuário removido'}</p>
                                <p className="text-xs text-[var(--ink-500)]">{comment.created_at}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                {comment.tipo === 'pendencia' && (
                                    <button
                                        type="button"
                                        onClick={() => toggleResolved(comment)}
                                        className={`rounded-full px-2.5 py-1 text-xs font-bold ${comment.status === 'resolvida' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}`}
                                    >
                                        {comment.status === 'resolvida' ? 'Resolvida' : 'Pendente'}
                                    </button>
                                )}
                                <button type="button" onClick={() => beginReply(comment)} className="text-xs font-bold text-[var(--primary)] hover:underline">Responder</button>
                            </div>
                        </div>
                        <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-[var(--ink-700)]">{comment.body}</p>
                        <AttachmentLinks attachments={comment.attachments} ordem={ordem} tenant={tenant} />
                        {(comment.replies || []).map((reply) => (
                            <div key={reply.id} className="ml-5 mt-3 border-l-2 border-blue-200 pl-3">
                                <p className="text-xs font-bold text-[var(--ink-900)]">{reply.user?.name || 'Usuário removido'} <span className="font-normal text-[var(--ink-500)]">· {reply.created_at}</span></p>
                                <p className="mt-1 whitespace-pre-wrap text-sm text-[var(--ink-700)]">{reply.body}</p>
                                <AttachmentLinks attachments={reply.attachments} ordem={ordem} tenant={tenant} />
                            </div>
                        ))}
                    </article>
                ))}
            </div>

            <form onSubmit={submit} className="grid gap-3 border-t border-[var(--border)] bg-[var(--surface-muted)] p-4">
                {replyTo && (
                    <div className="flex items-center justify-between rounded-md bg-blue-50 px-3 py-2 text-xs text-blue-800">
                        <span>Respondendo a {replyTo.user?.name || 'usuário'}</span>
                        <button type="button" onClick={cancelReply} className="font-bold">Cancelar resposta</button>
                    </div>
                )}
                {!replyTo && (
                    <div className="flex gap-2">
                        {['comentario', 'pendencia'].map((type) => (
                            <button
                                key={type}
                                type="button"
                                onClick={() => form.setData('tipo', type)}
                                className={`rounded-md px-3 py-1.5 text-xs font-bold ${form.data.tipo === type ? 'bg-[var(--primary)] text-white' : 'border border-[var(--border)] bg-white text-[var(--ink-700)]'}`}
                            >
                                {type === 'comentario' ? 'Comentário' : 'Pendência'}
                            </button>
                        ))}
                    </div>
                )}
                <textarea
                    value={form.data.body}
                    onChange={(event) => form.setData('body', event.target.value)}
                    className="sig-input min-h-20 bg-white"
                    placeholder={replyTo ? 'Escreva a resposta...' : 'Registre uma atualização, orientação ou pendência...'}
                />
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="grid gap-1 text-xs font-bold uppercase text-[var(--ink-500)]">
                        Mencionar usuários
                        <select
                            multiple
                            value={form.data.mention_user_ids.map(String)}
                            onChange={(event) => form.setData('mention_user_ids', Array.from(event.target.selectedOptions).map((option) => Number(option.value)))}
                            className="sig-input min-h-20 bg-white normal-case"
                        >
                            {users.map((user) => <option key={user.id} value={user.id}>{user.name} - {user.email}</option>)}
                        </select>
                    </label>
                    <label className="grid content-start gap-1 text-xs font-bold uppercase text-[var(--ink-500)]">
                        Anexos
                        <input
                            type="file"
                            multiple
                            onChange={(event) => form.setData('anexos', Array.from(event.target.files || []))}
                            className="sig-input bg-white normal-case"
                        />
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

function AttachmentLinks({ attachments = [], ordem, tenant }) {
    if (!attachments.length) {
        return null;
    }

    return (
        <div className="mt-2 flex flex-wrap gap-2">
            {attachments.map((attachment) => (
                <a
                    key={attachment.id}
                    href={route('tenant.ordem-servico.os.documents.download', [tenant.slug, ordem.id, attachment.id])}
                    className="inline-flex max-w-full items-center gap-1 rounded-md border border-[var(--border)] bg-white px-2 py-1 text-xs font-semibold text-[var(--primary)]"
                >
                    <Paperclip size={13} />
                    <span className="truncate">{attachment.nome_original}</span>
                </a>
            ))}
        </div>
    );
}

function MetricCard({ icon: Icon, label, value }) {
    return (
        <div className="rounded-lg bg-[var(--surface-muted)] p-4">
            <div className="flex items-center gap-3">
                <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-white text-[var(--primary)]">
                    <Icon size={18} />
                </span>
                <div>
                    <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">{label}</span>
                    <strong className="mt-1 block text-lg text-[var(--ink-900)]">{value}</strong>
                </div>
            </div>
        </div>
    );
}

function SelectionPanel({ title, icon: Icon, search, setSearch, placeholder, count, extraControls, children }) {
    return (
        <section className="grid gap-3 rounded-lg border border-[var(--border)] p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                        <Icon size={18} />
                    </span>
                    <div>
                        <h3 className="text-sm font-bold text-[var(--ink-900)]">{title}</h3>
                        <p className="text-xs text-[var(--ink-500)]">{count} selecionado(s)</p>
                    </div>
                </div>
                <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    {extraControls}
                    <div className="relative w-full sm:w-80">
                        <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--ink-400)]" size={16} />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="sig-input"
                            style={{ paddingLeft: '2.5rem' }}
                            placeholder={placeholder}
                        />
                    </div>
                </div>
            </div>
            {children}
        </section>
    );
}

function InfoLine({ icon: Icon, label, value }) {
    return (
        <div className="flex min-w-0 items-start gap-2">
            <Icon className="mt-0.5 shrink-0 text-[var(--ink-400)]" size={16} />
            <div className="min-w-0">
                <span className="text-[11px] font-bold uppercase tracking-wide text-[var(--ink-400)]">{label}</span>
                <p className="truncate text-sm font-semibold text-[var(--ink-800)]">{value}</p>
            </div>
        </div>
    );
}

function Avatar({ user }) {
    return user?.avatar_url ? (
        <img src={user.avatar_url} alt={user.name} className="h-9 w-9 rounded-full object-cover" />
    ) : (
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--primary-100)] text-xs font-bold text-[var(--primary)]">
            {initials(user?.name)}
        </span>
    );
}
