import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, ChevronDown, ChevronRight, ClipboardList, ExternalLink, Send, TriangleAlert, X, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

const statusLabels = {
    em_analise: 'Em análise',
    em_aprovacao: 'Em aprovação',
    aprovada: 'Aprovada',
    recusada: 'Recusada',
};

const statusClasses = {
    em_analise: 'bg-amber-50 text-amber-700',
    em_aprovacao: 'bg-indigo-50 text-indigo-700',
    aprovada: 'bg-emerald-50 text-emerald-700',
    recusada: 'bg-red-50 text-red-700',
};

const formatCurrency = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));

export default function OrdemServicoAnalise({ selectedContractId, contracts = [], ordens = [] }) {
    const page = usePage();
    const tenant = page.props.currentTenant;
    const [observations, setObservations] = useState({});
    const [expandedOrderId, setExpandedOrderId] = useState(null);
    const [orderDetails, setOrderDetails] = useState({});
    const [loadingOrderId, setLoadingOrderId] = useState(null);
    const [detailsError, setDetailsError] = useState('');
    const [rejectionOrder, setRejectionOrder] = useState(null);
    const [rejectionReason, setRejectionReason] = useState('');
    const [rejectionError, setRejectionError] = useState('');
    const [rejectionProcessing, setRejectionProcessing] = useState(false);
    const [confirmationAction, setConfirmationAction] = useState(null);
    const [confirmationError, setConfirmationError] = useState('');
    const [confirmationProcessing, setConfirmationProcessing] = useState(false);

    useEffect(() => {
        if (!rejectionOrder) {
            return undefined;
        }

        const previousOverflow = document.body.style.overflow;
        const handleKeyDown = (event) => {
            if (event.key === 'Escape' && !rejectionProcessing) {
                setRejectionOrder(null);
                setRejectionReason('');
                setRejectionError('');
            }
        };

        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', handleKeyDown);

        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [rejectionOrder, rejectionProcessing]);

    useEffect(() => {
        if (!confirmationAction) {
            return undefined;
        }

        const previousOverflow = document.body.style.overflow;
        const handleKeyDown = (event) => {
            if (event.key === 'Escape' && !confirmationProcessing) {
                setConfirmationAction(null);
                setConfirmationError('');
            }
        };

        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', handleKeyDown);

        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [confirmationAction, confirmationProcessing]);

    const changeContract = (contractId) => {
        router.get(
            route('tenant.ordem-servico.analise.index', tenant.slug),
            { contract_id: contractId },
            { preserveScroll: true, preserveState: false }
        );
    };

    const setObservation = (id, value) => {
        setObservations((current) => ({ ...current, [id]: value }));
    };

    const toggleOrder = async (ordem) => {
        if (expandedOrderId === ordem.id) {
            setExpandedOrderId(null);
            setDetailsError('');
            return;
        }

        setExpandedOrderId(ordem.id);
        setDetailsError('');

        if (orderDetails[ordem.id]) {
            return;
        }

        setLoadingOrderId(ordem.id);

        try {
            const response = await fetch(route('tenant.ordem-servico.analise.detalhes', [tenant.slug, ordem.id]), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error('Não foi possível carregar os detalhes da OS.');
            }

            const data = await response.json();
            setOrderDetails((current) => ({ ...current, [ordem.id]: data.ordem }));
        } catch (error) {
            setDetailsError(error.message || 'Não foi possível carregar os detalhes da OS.');
        } finally {
            setLoadingOrderId(null);
        }
    };

    const openConfirmationModal = (ordem, action) => {
        setConfirmationAction({ ordem, action });
        setConfirmationError('');
    };

    const closeConfirmationModal = () => {
        if (confirmationProcessing) {
            return;
        }

        setConfirmationAction(null);
        setConfirmationError('');
    };

    const submitConfirmation = () => {
        if (!confirmationAction) {
            return;
        }

        const { ordem, action } = confirmationAction;
        const isAnalysis = action === 'analysis';
        const observation = observations[ordem.id] || '';

        setConfirmationProcessing(true);
        setConfirmationError('');

        router.patch(
            route(
                isAnalysis
                    ? 'tenant.ordem-servico.os.analyze'
                    : 'tenant.ordem-servico.os.approve',
                [tenant.slug, ordem.id]
            ),
            isAnalysis
                ? { observacao: observation }
                : { decisao: 'aprovar', observacao: observation },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setObservations((current) => {
                        const next = { ...current };
                        delete next[ordem.id];
                        return next;
                    });
                    setOrderDetails((current) => {
                        const next = { ...current };
                        delete next[ordem.id];
                        return next;
                    });
                    setExpandedOrderId(null);
                    setConfirmationAction(null);

                    if (isAnalysis) {
                        router.reload({ preserveScroll: true, preserveState: false });
                    }
                },
                onError: (errors) => {
                    setConfirmationError(
                        errors.status
                        || errors.observacao
                        || errors.decisao
                        || Object.values(errors)[0]
                        || 'Não foi possível concluir esta ação.'
                    );
                },
                onFinish: () => setConfirmationProcessing(false),
            }
        );
    };

    const openRejectionModal = (ordem) => {
        setRejectionOrder(ordem);
        setRejectionReason(observations[ordem.id] || '');
        setRejectionError('');
    };

    const closeRejectionModal = () => {
        if (rejectionProcessing) {
            return;
        }

        setRejectionOrder(null);
        setRejectionReason('');
        setRejectionError('');
    };

    const submitRejection = () => {
        const reason = rejectionReason.trim();

        if (!reason) {
            setRejectionError('Informe o motivo da reprovação.');
            return;
        }

        setRejectionProcessing(true);
        setRejectionError('');

        router.patch(
            route('tenant.ordem-servico.os.reject', [tenant.slug, rejectionOrder.id]),
            { motivo: reason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setObservations((current) => {
                        const next = { ...current };
                        delete next[rejectionOrder.id];
                        return next;
                    });
                    setOrderDetails((current) => {
                        const next = { ...current };
                        delete next[rejectionOrder.id];
                        return next;
                    });
                    setExpandedOrderId(null);
                    setRejectionOrder(null);
                    setRejectionReason('');
                },
                onError: (errors) => {
                    setRejectionError(
                        errors.motivo
                        || errors.status
                        || Object.values(errors)[0]
                        || 'Não foi possível reprovar a OS.'
                    );
                },
                onFinish: () => setRejectionProcessing(false),
            }
        );
    };

    return (
        <AuthenticatedLayout>
            <Head title="Análise OS" />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                <section className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <span className="eyebrow">Ordem de Serviço</span>
                        <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">Análise OS</h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-[var(--ink-500)]">
                            Consolide a análise dos fiscais e a aprovação final das ordens de serviço por obra.
                        </p>
                    </div>
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

                {confirmationAction && (() => {
                    const isAnalysis = confirmationAction.action === 'analysis';
                    const ordem = confirmationAction.ordem;
                    const observation = observations[ordem.id]?.trim();

                    return (
                        <div
                            className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.52)] p-4 backdrop-blur-[1px]"
                            role="presentation"
                            onMouseDown={(event) => {
                                if (event.target === event.currentTarget) {
                                    closeConfirmationModal();
                                }
                            }}
                        >
                            <section
                                className="w-full max-w-lg overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                                role="dialog"
                                aria-modal="true"
                                aria-labelledby="confirm-service-order-title"
                                onMouseDown={(event) => event.stopPropagation()}
                            >
                                <header className="flex items-start gap-4 border-b border-[var(--border)] px-5 py-5">
                                    <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full ${
                                        isAnalysis
                                            ? 'bg-blue-50 text-blue-700'
                                            : 'bg-emerald-50 text-emerald-700'
                                    }`}>
                                        {isAnalysis ? <Send size={21} /> : <CheckCircle2 size={21} />}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <span className="eyebrow">
                                            {isAnalysis ? 'Análise da OS' : 'Aprovação da OS'}
                                        </span>
                                        <h2 id="confirm-service-order-title" className="mt-1 text-lg font-bold text-[var(--ink-900)]">
                                            {isAnalysis
                                                ? `Enviar ${ordem.codigo} para aprovação?`
                                                : `Aprovar ${ordem.codigo}?`}
                                        </h2>
                                        <p className="mt-2 text-sm leading-6 text-[var(--ink-500)]">
                                            {isAnalysis
                                                ? 'O parecer será registrado e a ordem de serviço seguirá para a etapa de aprovação.'
                                                : 'A aprovação encerrará este fluxo e autorizará a execução da ordem de serviço.'}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-[var(--ink-500)] transition hover:bg-[var(--surface-muted)] hover:text-[var(--ink-900)] disabled:cursor-not-allowed disabled:opacity-50"
                                        aria-label="Fechar confirmação da OS"
                                        disabled={confirmationProcessing}
                                        onClick={closeConfirmationModal}
                                    >
                                        <X size={18} />
                                    </button>
                                </header>

                                <div className="grid gap-4 px-5 py-5">
                                    <div className={`rounded-lg border px-4 py-3 text-sm leading-5 ${
                                        isAnalysis
                                            ? 'border-blue-200 bg-blue-50 text-blue-800'
                                            : 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                    }`}>
                                        {isAnalysis
                                            ? 'Os responsáveis pela aprovação receberão uma notificação e um e-mail para continuar o fluxo.'
                                            : 'Os responsáveis pela OS receberão uma notificação e um e-mail informando que a execução foi autorizada.'}
                                    </div>

                                    {observation && (
                                        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-4 py-3">
                                            <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                                Observação registrada
                                            </span>
                                            <p className="mt-1 whitespace-pre-wrap break-words text-sm leading-5 text-[var(--ink-700)]">
                                                {observation}
                                            </p>
                                        </div>
                                    )}

                                    {confirmationError && (
                                        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                                            {confirmationError}
                                        </div>
                                    )}
                                </div>

                                <footer className="flex flex-wrap justify-end gap-2 bg-[var(--surface-muted)] px-5 py-4">
                                    <button
                                        type="button"
                                        className="sig-btn sig-btn-secondary"
                                        disabled={confirmationProcessing}
                                        onClick={closeConfirmationModal}
                                    >
                                        Cancelar
                                    </button>
                                    <button
                                        type="button"
                                        className={`sig-btn justify-center text-white disabled:opacity-50 ${
                                            isAnalysis
                                                ? 'border-blue-600 bg-blue-600 hover:bg-blue-700'
                                                : 'border-emerald-600 bg-emerald-600 hover:bg-emerald-700'
                                        }`}
                                        disabled={confirmationProcessing}
                                        onClick={submitConfirmation}
                                    >
                                        {isAnalysis ? <Send size={16} /> : <CheckCircle2 size={16} />}
                                        {confirmationProcessing
                                            ? (isAnalysis ? 'Enviando...' : 'Aprovando...')
                                            : (isAnalysis ? 'Enviar para aprovação' : 'Aprovar OS')}
                                    </button>
                                </footer>
                            </section>
                        </div>
                    );
                })()}

                {rejectionOrder && (
                    <div
                        className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.52)] p-4 backdrop-blur-[1px]"
                        role="presentation"
                        onMouseDown={(event) => {
                            if (event.target === event.currentTarget) {
                                closeRejectionModal();
                            }
                        }}
                    >
                        <section
                            className="w-full max-w-lg overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="reject-service-order-title"
                            onMouseDown={(event) => event.stopPropagation()}
                        >
                            <header className="flex items-start gap-4 border-b border-[var(--border)] px-5 py-5">
                                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-700">
                                    <XCircle size={21} />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <span className="eyebrow">
                                        {rejectionOrder.status === 'em_analise' ? 'Análise da OS' : 'Aprovação da OS'}
                                    </span>
                                    <h2 id="reject-service-order-title" className="mt-1 text-lg font-bold text-[var(--ink-900)]">
                                        Reprovar {rejectionOrder.codigo}?
                                    </h2>
                                    <p className="mt-2 text-sm leading-6 text-[var(--ink-500)]">
                                        A OS voltará para rascunho, poderá ser corrigida e enviada novamente.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-[var(--ink-500)] transition hover:bg-[var(--surface-muted)] hover:text-[var(--ink-900)] disabled:cursor-not-allowed disabled:opacity-50"
                                    aria-label="Fechar reprovação da OS"
                                    disabled={rejectionProcessing}
                                    onClick={closeRejectionModal}
                                >
                                    <X size={18} />
                                </button>
                            </header>

                            <div className="grid gap-4 px-5 py-5">
                                <label className="grid gap-1.5 text-sm">
                                    <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                        Motivo da reprovação
                                    </span>
                                    <textarea
                                        value={rejectionReason}
                                        onChange={(event) => {
                                            setRejectionReason(event.target.value);
                                            setRejectionError('');
                                        }}
                                        className="sig-input min-h-32"
                                        placeholder="Descreva claramente o que precisa ser corrigido."
                                        maxLength={5000}
                                        autoFocus
                                    />
                                    {rejectionError && (
                                        <span className="text-xs font-semibold text-red-600">{rejectionError}</span>
                                    )}
                                </label>

                                <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-5 text-amber-800">
                                    Os responsáveis pela criação, submissão, análise e aprovação receberão uma notificação e um e-mail com o motivo informado.
                                </div>
                            </div>

                            <footer className="flex flex-wrap justify-end gap-2 bg-[var(--surface-muted)] px-5 py-4">
                                <button
                                    type="button"
                                    className="sig-btn sig-btn-secondary"
                                    disabled={rejectionProcessing}
                                    onClick={closeRejectionModal}
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="button"
                                    className="sig-btn justify-center border-red-200 bg-red-600 text-white hover:bg-red-700 disabled:opacity-50"
                                    disabled={rejectionProcessing}
                                    onClick={submitRejection}
                                >
                                    <XCircle size={16} />
                                    {rejectionProcessing ? 'Reprovando...' : 'Reprovar e devolver'}
                                </button>
                            </footer>
                        </section>
                    </div>
                )}

                <section className="sig-card p-5">
                    <label className="grid gap-1.5 text-sm">
                        <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Contrato</span>
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
                    </label>
                </section>

                <section className="grid gap-4">
                    {ordens.length === 0 ? (
                        <div className="sig-card p-10 text-center">
                            <ClipboardList className="mx-auto text-[var(--ink-400)]" size={32} />
                            <p className="mt-3 text-sm font-bold text-[var(--ink-900)]">Nenhuma OS aguardando fluxo</p>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                Envie uma OS em rascunho para análise para ela aparecer aqui.
                            </p>
                        </div>
                    ) : ordens.map((ordemResumo) => {
                        const ordem = orderDetails[ordemResumo.id] || ordemResumo;

                        return (
                        <article key={ordem.id} className="sig-card overflow-hidden">
                            <button
                                type="button"
                                onClick={() => toggleOrder(ordemResumo)}
                                aria-expanded={expandedOrderId === ordem.id}
                                className="grid w-full items-center gap-3 p-4 text-left transition hover:bg-[var(--surface-muted)] md:grid-cols-[110px_minmax(0,1fr)_170px_150px_28px]"
                            >
                                <p className="mono font-bold text-[var(--primary)]">{ordem.codigo}</p>
                                <div className="min-w-0">
                                    <h2 className="truncate text-sm font-bold text-[var(--ink-900)]">{ordem.titulo}</h2>
                                    <p className="truncate text-xs text-[var(--ink-500)]">
                                        {ordem.obra?.nome || 'Sem obra'} · {ordem.solicitante?.name || 'Sem solicitante'}
                                    </p>
                                    <SubmissionAgeBadge days={ordem.dias_desde_submissao} />
                                </div>
                                <span className={`w-fit rounded-full px-3 py-1 text-xs font-bold ${statusClasses[ordem.status] || 'bg-slate-100 text-slate-700'}`}>
                                    {statusLabels[ordem.status] || ordem.status}
                                </span>
                                <strong className="text-sm text-[var(--ink-900)]">{formatCurrency(ordem.custo_previsto)}</strong>
                                {expandedOrderId === ordem.id
                                    ? <ChevronDown size={18} className="text-[var(--ink-500)]" />
                                    : <ChevronRight size={18} className="text-[var(--ink-500)]" />}
                            </button>

                            {expandedOrderId === ordem.id && loadingOrderId === ordem.id && (
                                <p className="border-t border-[var(--border)] p-8 text-center text-sm font-semibold text-[var(--ink-500)]">
                                    Carregando detalhes da OS...
                                </p>
                            )}

                            {expandedOrderId === ordem.id && detailsError && (
                                <p className="border-t border-red-100 bg-red-50 p-4 text-sm font-semibold text-red-700">
                                    {detailsError}
                                </p>
                            )}

                            {orderDetails[ordem.id] && (
                            <>
                            <div className={`${expandedOrderId === ordem.id ? 'grid' : 'hidden'} gap-4 border-t border-[var(--border)] p-5 xl:grid-cols-[1fr_340px]`}>
                                <div className="grid gap-3">
                                    {ordem.descricao && (
                                        <div className="rounded-lg border border-[var(--border)] px-4 py-3">
                                            <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                                Escopo da OS
                                            </span>
                                            <p className="mt-1 whitespace-pre-wrap break-words text-sm leading-5 text-[var(--ink-700)]">
                                                {ordem.descricao}
                                            </p>
                                        </div>
                                    )}

                                    <AnalysisProjects projects={ordem.projects || []} />
                                    <div className="grid gap-2 md:grid-cols-3">
                                        <Info label="Enviada para análise" value={ordem.submitted_for_review_at || 'Não enviado'} detail={ordem.submitted_by?.name} />
                                        <Info label="Análise fiscal" value={ordem.analyzed_at || 'Pendente'} detail={ordem.analysis_observation} />
                                        <Info label="Decisão final" value={ordem.approval_decided_at || 'Pendente'} detail={ordem.approval_observation} />
                                    </div>

                                    <div className="overflow-hidden rounded-lg border border-[var(--border)]">
                                        <div className="flex items-center justify-between border-b border-[var(--border)] bg-[var(--surface-muted)] px-4 py-3">
                                            <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">Itens vinculados</span>
                                            <span className="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-[var(--ink-600)]">
                                                {ordem.itens.length} {ordem.itens.length === 1 ? 'item' : 'itens'}
                                            </span>
                                        </div>
                                        <div className="max-h-[520px] overflow-auto">
                                        <div className="min-w-[720px]">
                                        <div className="sticky top-0 z-10 grid grid-cols-[100px_minmax(260px,1fr)_130px_130px] gap-3 border-b border-[var(--border)] bg-white px-4 py-3 text-xs font-bold uppercase tracking-wide text-[var(--ink-500)] shadow-sm">
                                            <span>Item</span>
                                            <span>Descrição</span>
                                            <span>Valor P0</span>
                                            <span>Reajustado</span>
                                        </div>
                                        {ordem.itens.map((item) => (
                                            <div key={item.id} className="grid grid-cols-[100px_minmax(260px,1fr)_130px_130px] gap-3 border-b border-[var(--border)] px-4 py-3 text-sm last:border-b-0 hover:bg-[var(--surface-muted)]">
                                                <strong>{item.item}</strong>
                                                <div>
                                                    <p className="font-semibold text-[var(--ink-700)]">{item.codigo || 'Sem código'}</p>
                                                    <p className="mt-1 whitespace-normal leading-5 text-[var(--ink-600)]">{item.descricao}</p>
                                                </div>
                                                <strong className="whitespace-nowrap">{formatCurrency(item.valor_previsto)}</strong>
                                                <strong className="whitespace-nowrap text-emerald-700">{formatCurrency(item.valor_reajustado)}</strong>
                                            </div>
                                        ))}
                                        </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="grid content-start gap-3">
                                    {ordem.status === 'em_analise' && (
                                        <>
                                            <label className="grid gap-1.5 text-sm">
                                                <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Observação da análise</span>
                                                <textarea
                                                    value={observations[ordem.id] || ''}
                                                    onChange={(event) => setObservation(ordem.id, event.target.value)}
                                                    className="sig-input min-h-28"
                                                    placeholder="Registre o parecer do fiscal."
                                                />
                                            </label>
                                            <div className="grid grid-cols-2 gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => openRejectionModal(ordem)}
                                                    disabled={!ordem.can_analyze}
                                                    className="sig-btn justify-center border-red-200 bg-red-50 text-red-700 hover:bg-red-100 disabled:opacity-50"
                                                >
                                                    <XCircle size={16} />
                                                    Reprovar
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => openConfirmationModal(ordem, 'analysis')}
                                                    disabled={!ordem.can_analyze}
                                                    className="sig-btn sig-btn-primary justify-center disabled:opacity-50"
                                                >
                                                    <Send size={16} />
                                                    Enviar para aprovação
                                                </button>
                                            </div>
                                        </>
                                    )}

                                    {ordem.status === 'em_aprovacao' && (
                                        <>
                                            <label className="grid gap-1.5 text-sm">
                                                <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Observação da aprovação</span>
                                                <textarea
                                                    value={observations[ordem.id] || ''}
                                                    onChange={(event) => setObservation(ordem.id, event.target.value)}
                                                    className="sig-input min-h-28"
                                                    placeholder="Registre observações da decisão."
                                                />
                                            </label>
                                            <div className="grid grid-cols-2 gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => openRejectionModal(ordem)}
                                                    disabled={!ordem.can_approve}
                                                    className="sig-btn justify-center border-red-200 bg-red-50 text-red-700 hover:bg-red-100 disabled:opacity-50"
                                                >
                                                    <XCircle size={16} />
                                                    Reprovar
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => openConfirmationModal(ordem, 'approval')}
                                                    disabled={!ordem.can_approve}
                                                    className="sig-btn justify-center border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 disabled:opacity-50"
                                                >
                                                    <CheckCircle2 size={16} />
                                                    Aprovar
                                                </button>
                                            </div>
                                        </>
                                    )}

                                    {['aprovada', 'recusada'].includes(ordem.status) && (
                                        <div className="rounded-lg bg-[var(--surface-muted)] p-4 text-sm text-[var(--ink-600)]">
                                            Fluxo encerrado por {ordem.approval_decided_by?.name || 'usuário não identificado'}.
                                        </div>
                                    )}
                                </div>
                            </div>
                            </>
                            )}
                        </article>
                        );
                    })}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function Info({ label, value, detail }) {
    return (
        <div className="min-w-0 rounded-lg border border-[var(--border)] px-3 py-2.5">
            <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">{label}</span>
            <p className="mt-1 break-words text-sm font-bold text-[var(--ink-900)]">{value}</p>
            {detail ? <p className="mt-1 break-words text-xs leading-5 text-[var(--ink-500)]">{detail}</p> : null}
        </div>
    );
}

function SubmissionAgeBadge({ days = 0 }) {
    const totalDays = Number(days || 0);
    const className = totalDays <= 5
        ? 'bg-emerald-50 text-emerald-700'
        : totalDays <= 10
            ? 'bg-amber-50 text-amber-700'
            : 'bg-red-50 text-red-700';

    return (
        <span className={`mt-2 inline-flex w-fit rounded-full px-2.5 py-1 text-[11px] font-bold ${className}`}>
            {totalDays} {totalDays === 1 ? 'dia' : 'dias'} desde a submissão
        </span>
    );
}

function AnalysisProjects({ projects = [] }) {
    return (
        <section className="rounded-lg border border-indigo-100 bg-indigo-50 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-xs font-bold uppercase tracking-wide text-indigo-700">Projetos vinculados</span>
                <span className="sig-pill sig-pill-blue">{projects.length} projeto(s)</span>
            </div>

            {projects.length === 0 ? (
                <p className="mt-3 text-sm font-semibold text-indigo-900">Nenhum projeto vinculado à OS.</p>
            ) : (
                <div className="mt-3 grid gap-2">
                    {projects.map((project) => {
                        const openRncsCount = Number(project.open_rncs_count || 0);

                        return (
                            <div key={project.id} className="flex flex-col gap-3 rounded-lg bg-white p-3 lg:flex-row lg:items-center lg:justify-between">
                                <div className="min-w-0">
                                    <p className="font-semibold text-[var(--ink-900)]">
                                        {project.code || 'Sem código'} - {project.title || 'Projeto vinculado'}
                                    </p>
                                    <div className="mt-2">
                                        {openRncsCount > 0 ? (
                                            project.first_open_rnc?.url ? (
                                                <a
                                                    href={project.first_open_rnc.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="sig-pill sig-pill-red inline-flex items-center gap-1 hover:underline"
                                                >
                                                    <TriangleAlert size={13} />
                                                    Acessar RNC · {openRncsCount} aberta{openRncsCount === 1 ? '' : 's'}
                                                </a>
                                            ) : (
                                                <span className="sig-pill sig-pill-red inline-flex items-center gap-1">
                                                    <TriangleAlert size={13} />
                                                    {openRncsCount} RNC{openRncsCount === 1 ? '' : 's'} aberta{openRncsCount === 1 ? '' : 's'}
                                                </span>
                                            )
                                        ) : (
                                            <span className="sig-pill sig-pill-green">Sem RNC aberta</span>
                                        )}
                                    </div>
                                </div>

                                <a href={project.url} target="_blank" rel="noreferrer" className="sig-btn sig-btn-primary w-fit">
                                    <ExternalLink size={16} />
                                    Visualizar projeto
                                </a>
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}
