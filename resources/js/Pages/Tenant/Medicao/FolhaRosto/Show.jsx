import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ChevronDown, ChevronRight, ClipboardList, Download, FileArchive, Pencil, Plus, Send, X } from 'lucide-react';
import { useMemo, useState } from 'react';

const formatCurrency = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));

const formatDecimal = (value) =>
    new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(Number(value || 0));

const hasMoreThanFourDecimals = (value) => {
    const normalized = String(value ?? '').replace(',', '.');
    const decimals = normalized.includes('.') ? normalized.split('.')[1] : '';

    return decimals.length > 4;
};

const statusLabel = (status) => ({
    aberta: 'Aberta',
    rascunho: 'Rascunho',
    retornada: 'Retornada',
    analise_fiscal: 'Análise fiscal',
    analise_qualidade: 'Análise qualidade',
    analise_medicao: 'Análise medição',
    analisada: 'Analisada',
}[status] || status);

const statusClass = (status) => ({
    rascunho: 'bg-amber-50 text-amber-700',
    retornada: 'bg-red-50 text-red-700',
    analise_fiscal: 'bg-blue-50 text-blue-700',
    analise_qualidade: 'bg-purple-50 text-purple-700',
    analise_medicao: 'bg-indigo-50 text-indigo-700',
    analisada: 'bg-emerald-50 text-emerald-700',
}[status] || 'bg-slate-100 text-slate-700');

function ReturnedAgeBadge({ status, days }) {
    if (status !== 'retornada') {
        return null;
    }

    const totalDays = Number(days || 0);
    const className = totalDays <= 5
        ? 'bg-emerald-50 text-emerald-700'
        : totalDays <= 10
            ? 'bg-amber-50 text-amber-700'
            : 'bg-red-50 text-red-700';

    return (
        <span className={`inline-flex w-fit rounded-full px-2.5 py-1 text-[11px] font-bold ${className}`}>
            {totalDays} {totalDays === 1 ? 'dia' : 'dias'} retornada
        </span>
    );
}

function AnalysisRequirementBadges({ item }) {
    const requirements = [
        item.precisa_analise_topografica ? 'Topografia' : null,
        item.precisa_analise_qualidade ? 'Qualidade' : null,
    ].filter(Boolean);

    if (requirements.length === 0) {
        return null;
    }

    return (
        <div className="mt-1 flex flex-wrap gap-1.5">
            {requirements.map((requirement) => (
                <span key={requirement} className="rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-bold text-blue-700">
                    {requirement}
                </span>
            ))}
        </div>
    );
}

export default function FolhaRostoShow({ ordem, boletim = null, boletinsAbertos = [], construtoras = [] }) {
    const page = usePage();
    const tenant = page.props.currentTenant;
    const [showForm, setShowForm] = useState(false);
    const [editingFolha, setEditingFolha] = useState(null);
    const [expandedFrId, setExpandedFrId] = useState(null);
    const [returnReasonFolha, setReturnReasonFolha] = useState(null);
    const [quantities, setQuantities] = useState({});
    const [analysisFlags, setAnalysisFlags] = useState({});
    const form = useForm({
        comentario: '',
        memoria_calculo: null,
        boletim_medicao_id: boletim?.status === 'aberto_lancamento' ? boletim.id : '',
        construtora_empresa_id: '',
        itens: [],
    });

    const selectedItems = useMemo(
        () => ordem.itens.filter((item) => Number(quantities[item.id] || 0) > 0),
        [ordem.itens, quantities]
    );

    const editingQuantities = useMemo(
        () => Object.fromEntries((editingFolha?.itens || []).map((item) => [
            item.ordem_servico_item_id,
            Number(item.quantidade_pleiteada || 0),
        ])),
        [editingFolha]
    );
    const availableForItem = (item) =>
        Number(item.quantidade_disponivel || 0) + Number(editingQuantities[item.id] || 0);
    const itemsOverBalance = useMemo(
        () => ordem.itens.filter((item) => Number(quantities[item.id] || 0) > availableForItem(item)),
        [ordem.itens, quantities, editingQuantities]
    );

    const hasQuantityOverBalance = itemsOverBalance.length > 0;
    const hasQuantityPrecisionError = Object.values(quantities).some((value) => hasMoreThanFourDecimals(value));
    const folhasPorBoletim = useMemo(
        () => Object.values(ordem.folhas_rosto.reduce((groups, folha) => {
            const key = folha.boletim?.id || 'sem-bm';

            if (!groups[key]) {
                groups[key] = {
                    key,
                    boletim: folha.boletim,
                    folhas: [],
                };
            }

            groups[key].folhas.push(folha);
            return groups;
        }, {})),
        [ordem.folhas_rosto]
    );
    const boletinsDisponiveis = useMemo(() => {
        if (!editingFolha?.boletim || boletinsAbertos.some((item) => item.id === editingFolha.boletim.id)) {
            return boletinsAbertos;
        }

        return [editingFolha.boletim, ...boletinsAbertos];
    }, [boletinsAbertos, editingFolha]);

    const estimatedValue = selectedItems.reduce((total, item) => {
        const baseQuantity = Number(item.quantidade_total || 0);
        const unitValue = baseQuantity > 0 ? Number(item.valor_previsto || 0) / baseQuantity : 0;
        return total + (unitValue * Number(quantities[item.id] || 0));
    }, 0);

    const submit = (event) => {
        event.preventDefault();

        if (hasQuantityOverBalance || hasQuantityPrecisionError) {
            return;
        }

        const itens = selectedItems.map((item) => ({
            ordem_servico_item_id: item.id,
            quantidade_pleiteada: Number(quantities[item.id]),
            precisa_analise_topografica: Boolean(analysisFlags[item.id]?.topografia),
            precisa_analise_qualidade: Boolean(analysisFlags[item.id]?.qualidade),
        }));

        form.transform((data) => ({
            ...data,
            itens,
            ...(editingFolha ? { _method: 'patch' } : {}),
        }));
        const options = {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.setData((data) => ({
                    ...data,
                    boletim_medicao_id: boletim?.status === 'aberto_lancamento' ? boletim.id : '',
                    construtora_empresa_id: '',
                }));
                setQuantities({});
                setAnalysisFlags({});
                setShowForm(false);
                setEditingFolha(null);
            },
        };

        if (editingFolha) {
            form.post(route('tenant.medicao.folha-rosto.update', [tenant.slug, editingFolha.id]), options);
        } else {
            form.post(route('tenant.medicao.folha-rosto.store', [tenant.slug, ordem.id]), options);
        }
    };

    const startEditing = (folha, event) => {
        event.stopPropagation();
        setEditingFolha(folha);
        setQuantities(Object.fromEntries(folha.itens.map((item) => [
            item.ordem_servico_item_id,
            String(item.quantidade_pleiteada),
        ])));
        setAnalysisFlags(Object.fromEntries(folha.itens.map((item) => [
            item.ordem_servico_item_id,
            {
                topografia: Boolean(item.precisa_analise_topografica),
                qualidade: Boolean(item.precisa_analise_qualidade),
            },
        ])));
        form.setData({
            comentario: folha.comentario || '',
            memoria_calculo: null,
            boletim_medicao_id: folha.boletim?.id || '',
            construtora_empresa_id: folha.construtora?.id || '',
            itens: [],
        });
        setShowForm(true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const submitForAnalysis = (folha, event) => {
        event.stopPropagation();

        if (!window.confirm(`Enviar a FR ${folha.codigo} para análise fiscal?`)) {
            return;
        }

        router.patch(
            route('tenant.medicao.folha-rosto.submit-analysis', [tenant.slug, folha.id]),
            {},
            { preserveScroll: true }
        );
    };

    const backUrl = boletim
        ? `${route('tenant.medicao.folha-rosto.index', tenant.slug)}?boletim_id=${boletim.id}`
        : route('tenant.medicao.folha-rosto.index', tenant.slug);

    return (
        <AuthenticatedLayout>
            <Head title={`Folhas de Rosto - ${ordem.codigo}`} />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                <section className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <Link href={backUrl} className="inline-flex items-center gap-2 text-sm font-bold text-[var(--primary)]">
                            <ArrowLeft size={16} />
                            {boletim ? `Voltar para ${boletim.codigo}` : 'Voltar para Folhas de Rosto'}
                        </Link>
                        <p className="mono mt-4 text-sm font-bold text-[var(--primary)]">{ordem.codigo}</p>
                        <h1 className="mt-1 text-3xl font-bold text-[var(--ink-900)]">{ordem.titulo}</h1>
                        <p className="mt-2 text-sm text-[var(--ink-500)]">
                            {ordem.obra?.codigo} - {ordem.obra?.nome} · {ordem.contract?.code} - {ordem.contract?.name}
                        </p>
                    </div>

                    {ordem.can_create && (
                        <button
                            type="button"
                            onClick={() => {
                                if (showForm) {
                                    setShowForm(false);
                                    setEditingFolha(null);
                                    setAnalysisFlags({});
                                } else {
                                    setEditingFolha(null);
                                    setQuantities({});
                                    setAnalysisFlags({});
                                    form.reset();
                                    form.setData('boletim_medicao_id', boletim?.status === 'aberto_lancamento' ? boletim.id : '');
                                    setShowForm(true);
                                }
                            }}
                            className="sig-btn sig-btn-primary"
                        >
                            {showForm ? <X size={16} /> : <Plus size={16} />}
                            {showForm ? 'Fechar criação' : 'Nova Folha de Rosto'}
                        </button>
                    )}
                </section>

                {boletim && (
                    <section className={`rounded-xl border px-5 py-4 ${boletim.status === 'aberto_lancamento' ? 'border-blue-200 bg-blue-50' : 'border-amber-200 bg-amber-50'}`}>
                        <p className="text-sm font-bold text-blue-900">
                            Lançamento vinculado ao {boletim.codigo}
                        </p>
                        <p className="mt-1 text-sm text-blue-700">
                            Período {boletim.periodo_formatado} · {boletim.tipo_label} · {boletim.status_label}
                        </p>
                        {boletim.status !== 'aberto_lancamento' && (
                            <p className="mt-2 text-sm font-semibold text-amber-800">
                                O envio de novas Folhas de Rosto está pausado para este boletim.
                            </p>
                        )}
                    </section>
                )}

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

                {showForm && (
                    <form onSubmit={submit} className="sig-card overflow-hidden">
                        <header className="border-b border-[var(--border)] px-5 py-3">
                            <h2 className="text-lg font-bold text-[var(--ink-900)]">
                                {editingFolha ? `Editar rascunho ${editingFolha.codigo}` : 'Criar Folha de Rosto'}
                            </h2>
                            <p className="text-sm text-[var(--ink-500)]">
                                Selecione o BM aberto, a construtora solicitante e as quantidades pleiteadas.
                            </p>
                        </header>

                        <div className="grid gap-4 p-4">
                            <div className="grid gap-4 lg:grid-cols-2">
                                <label className="grid gap-1.5 text-sm">
                                    <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">BM aberto para lançamento</span>
                                    <select
                                        value={form.data.boletim_medicao_id || ''}
                                        onChange={(event) => form.setData('boletim_medicao_id', event.target.value)}
                                        className="sig-input"
                                    >
                                        <option value="">Selecione o BM</option>
                                        {boletinsDisponiveis.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.codigo} - {item.periodo_formatado} - {item.tipo_label}
                                            </option>
                                        ))}
                                    </select>
                                    {boletinsDisponiveis.length === 0 && (
                                        <span className="text-xs font-semibold text-amber-700">
                                            Não há BM aberto para lançamento neste contrato.
                                        </span>
                                    )}
                                </label>

                                <label className="grid gap-1.5 text-sm">
                                    <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Construtora solicitante</span>
                                    <select
                                        value={form.data.construtora_empresa_id || ''}
                                        onChange={(event) => form.setData('construtora_empresa_id', event.target.value)}
                                        className="sig-input"
                                    >
                                        <option value="">Selecione a construtora</option>
                                        {construtoras.map((empresa) => (
                                            <option key={empresa.id} value={empresa.id}>
                                                {empresa.sigla ? `${empresa.sigla} - ` : ''}{empresa.nome}
                                            </option>
                                        ))}
                                    </select>
                                    {construtoras.length === 0 && (
                                        <span className="text-xs font-semibold text-amber-700">
                                            Cadastre uma construtora vinculada ao contrato da OS.
                                        </span>
                                    )}
                                </label>
                            </div>

                            <label className="grid gap-1.5 text-sm">
                                <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Comentário</span>
                                <textarea
                                    value={form.data.comentario}
                                    onChange={(event) => form.setData('comentario', event.target.value)}
                                    className="sig-input min-h-20"
                                    placeholder="Descreva o período, frente de serviço e justificativa do pleito."
                                />
                            </label>

                            <label className="grid gap-1.5 text-sm">
                                <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                    Memória de cálculo (ZIP)
                                </span>
                                <input
                                    type="file"
                                    accept=".zip,application/zip"
                                    onChange={(event) => form.setData('memoria_calculo', event.target.files?.[0] || null)}
                                    className="sig-input file:mr-4 file:rounded-md file:border-0 file:bg-[var(--primary-50)] file:px-3 file:py-2 file:text-sm file:font-bold file:text-[var(--primary)]"
                                />
                                <span className="text-xs text-[var(--ink-500)]">
                                    {editingFolha?.memoria_calculo
                                        ? `Arquivo atual: ${editingFolha.memoria_calculo.nome}. Selecione outro ZIP somente para substituí-lo.`
                                        : 'Anexe um único arquivo ZIP de até 30 MB para análise posterior.'}
                                </span>
                            </label>

                            <div className="overflow-hidden rounded-lg border border-[var(--border)]">
                                <div className="grid grid-cols-[80px_minmax(240px,1fr)_120px_120px_130px_170px] gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-3 py-2 text-[11px] font-bold uppercase text-[var(--ink-500)]">
                                    <span>Item</span>
                                    <span>Descrição / consumo</span>
                                    <span>Consumido</span>
                                    <span>Saldo</span>
                                    <span>Qtd. pleiteada</span>
                                    <span>Análises</span>
                                </div>
                                <div className="max-h-[420px] min-w-[930px] divide-y divide-[var(--border)] overflow-auto">
                                    {ordem.itens.map((item) => {
                                        const requestedQuantity = Number(quantities[item.id] || 0);
                                        const availableQuantity = availableForItem(item);
                                        const isOverBalance = requestedQuantity > availableQuantity;
                                        const hasPrecisionError = hasMoreThanFourDecimals(quantities[item.id]);

                                        return (
                                            <div key={item.id} className={`grid grid-cols-[80px_minmax(240px,1fr)_120px_120px_130px_170px] gap-3 px-3 py-2.5 text-xs ${isOverBalance ? 'bg-red-50/60' : ''}`}>
                                                <strong>{item.item}</strong>
                                                <div>
                                                    <p className="font-semibold text-[var(--ink-800)]">{item.codigo} - {item.descricao}</p>
                                                    <p className="mt-0.5 text-[11px] text-[var(--ink-500)]">
                                                        Total: {formatDecimal(item.quantidade_total)} {item.unidade || ''}
                                                    </p>
                                                    <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                                        <div
                                                            className={`h-full rounded-full ${item.percentual_consumido >= 100 ? 'bg-red-500' : 'bg-emerald-500'}`}
                                                            style={{ width: `${Math.min(100, item.percentual_consumido)}%` }}
                                                        />
                                                    </div>
                                                    <p className="mt-0.5 text-[11px] font-bold text-[var(--ink-500)]">
                                                        {formatDecimal(item.percentual_consumido)}% consumido
                                                    </p>
                                                </div>
                                                <strong>{formatDecimal(item.quantidade_consumida)} {item.unidade || ''}</strong>
                                                <strong className={isOverBalance ? 'text-red-700' : 'text-emerald-700'}>
                                                    {formatDecimal(availableQuantity)} {item.unidade || ''}
                                                </strong>
                                                <div>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        max={availableQuantity}
                                                        step="0.0001"
                                                        disabled={availableQuantity <= 0}
                                                        value={quantities[item.id] || ''}
                                                        onChange={(event) => setQuantities((current) => ({
                                                            ...current,
                                                            [item.id]: event.target.value,
                                                        }))}
                                                        className={`sig-input h-9 ${isOverBalance || hasPrecisionError ? 'border-red-300 bg-red-50 text-red-800 focus:border-red-500 focus:ring-red-200' : ''}`}
                                                        placeholder="0,00"
                                                    />
                                                    {isOverBalance && (
                                                        <p className="mt-1 text-[11px] font-semibold leading-tight text-red-600">
                                                            Sem saldo para medir essa quantidade.
                                                        </p>
                                                    )}
                                                    {hasPrecisionError && (
                                                        <p className="mt-1 text-[11px] font-semibold leading-tight text-red-600">
                                                            Use no máximo 4 casas decimais.
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="grid content-start gap-1.5">
                                                    <label className={`inline-flex items-center gap-2 rounded-lg border px-2 py-1.5 font-semibold ${requestedQuantity > 0 ? 'border-slate-200 bg-white text-[var(--ink-700)]' : 'border-slate-100 bg-slate-50 text-[var(--ink-400)]'}`}>
                                                        <input
                                                            type="checkbox"
                                                            disabled={requestedQuantity <= 0}
                                                            checked={Boolean(analysisFlags[item.id]?.topografia)}
                                                            onChange={(event) => setAnalysisFlags((current) => ({
                                                                ...current,
                                                                [item.id]: {
                                                                    ...(current[item.id] || {}),
                                                                    topografia: event.target.checked,
                                                                },
                                                            }))}
                                                            className="rounded border-slate-300 text-[var(--primary)] focus:ring-[var(--primary)]"
                                                        />
                                                        Topografia
                                                    </label>
                                                    <label className={`inline-flex items-center gap-2 rounded-lg border px-2 py-1.5 font-semibold ${requestedQuantity > 0 ? 'border-slate-200 bg-white text-[var(--ink-700)]' : 'border-slate-100 bg-slate-50 text-[var(--ink-400)]'}`}>
                                                        <input
                                                            type="checkbox"
                                                            disabled={requestedQuantity <= 0}
                                                            checked={Boolean(analysisFlags[item.id]?.qualidade)}
                                                            onChange={(event) => setAnalysisFlags((current) => ({
                                                                ...current,
                                                                [item.id]: {
                                                                    ...(current[item.id] || {}),
                                                                    qualidade: event.target.checked,
                                                                },
                                                            }))}
                                                            className="rounded border-slate-300 text-[var(--primary)] focus:ring-[var(--primary)]"
                                                        />
                                                        Qualidade
                                                    </label>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>

                        <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border)] px-5 py-4">
                            <div className="text-sm">
                                <span className="text-[var(--ink-500)]">{selectedItems.length} item(ns) selecionado(s)</span>
                                <strong className="ml-3 text-[var(--ink-900)]">Estimado: {formatCurrency(estimatedValue)}</strong>
                                {hasQuantityOverBalance && (
                                    <span className="ml-3 font-semibold text-red-600">
                                        Ajuste os itens sem saldo antes de criar a FR.
                                    </span>
                                )}
                                {hasQuantityPrecisionError && (
                                    <span className="ml-3 font-semibold text-red-600">
                                        Use no máximo 4 casas decimais nos quantitativos.
                                    </span>
                                )}
                            </div>
                            <button
                                type="submit"
                                disabled={form.processing || hasQuantityOverBalance || hasQuantityPrecisionError || selectedItems.length === 0 || !form.data.boletim_medicao_id || !form.data.construtora_empresa_id || !form.data.comentario.trim() || (!editingFolha && !form.data.memoria_calculo)}
                                className="sig-btn sig-btn-primary disabled:opacity-50"
                            >
                                <Send size={16} />
                                {editingFolha ? 'Salvar alterações' : 'Criar Folha de Rosto'}
                            </button>
                        </footer>
                    </form>
                )}

                <section className="grid gap-3">
                    <div>
                        <h2 className="text-xl font-bold text-[var(--ink-900)]">Folhas de Rosto criadas</h2>
                        <p className="text-sm text-[var(--ink-500)]">{ordem.folhas_rosto.length} registro(s) nesta OS.</p>
                    </div>

                    {ordem.folhas_rosto.length === 0 ? (
                        <div className="sig-card p-10 text-center">
                            <ClipboardList className="mx-auto text-[var(--ink-400)]" size={32} />
                            <p className="mt-3 font-bold text-[var(--ink-900)]">Nenhuma Folha de Rosto criada</p>
                        </div>
                    ) : folhasPorBoletim.map((grupo) => (
                        <section key={grupo.key} className="sig-card overflow-hidden">
                            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] bg-slate-100 px-5 py-3">
                                <div>
                                    <p className="font-bold text-[var(--ink-900)]">
                                        {grupo.boletim?.codigo || 'Sem BM vinculado'}
                                    </p>
                                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                                        {grupo.boletim
                                            ? `Referência ${grupo.boletim.periodo_formatado} · ${grupo.boletim.tipo_label} · ${grupo.boletim.status_label}`
                                            : 'Folhas de Rosto sem Boletim de Medição'}
                                    </p>
                                </div>
                                <span className="sig-pill sig-pill-blue">
                                    {grupo.folhas.length} FR{grupo.folhas.length === 1 ? '' : 's'}
                                </span>
                            </header>
                            <div className="divide-y divide-[var(--border)]">
                            {grupo.folhas.map((folha) => (
                        <article key={folha.id}>
                            <div className="hidden grid-cols-[190px_minmax(0,1fr)_140px_150px_300px_40px] gap-4 border-b border-[var(--border)] bg-[var(--surface-muted)] px-4 py-2 text-[11px] font-bold uppercase tracking-wide text-[var(--ink-500)] md:grid">
                                <span>FR</span>
                                <span>Comentário</span>
                                <span>Status</span>
                                <span>Valor pleito</span>
                                <span>Ação</span>
                                <span />
                            </div>
                            <div
                                onClick={() => setExpandedFrId((current) => current === folha.id ? null : folha.id)}
                                className="grid w-full cursor-pointer items-center gap-4 p-4 text-left md:grid-cols-[190px_minmax(0,1fr)_140px_150px_300px_40px]"
                            >
                                <strong className="mono text-[var(--primary)]">{folha.codigo}</strong>
                                <div className="min-w-0">
                                    <p className="truncate font-semibold text-[var(--ink-900)]">{folha.comentario}</p>
                                    <p className="text-xs text-[var(--ink-500)]">
                                        {folha.creator} · {folha.created_at}
                                        {folha.construtora?.nome ? ` · ${folha.construtora.nome}` : ''}
                                    </p>
                                </div>
                                <div className="grid gap-1">
                                    {folha.status === 'retornada' ? (
                                        <button
                                            type="button"
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                setReturnReasonFolha(folha);
                                            }}
                                            className={`w-fit rounded-full px-3 py-1 text-xs font-bold underline-offset-2 hover:underline ${statusClass(folha.status)}`}
                                            title="Ver motivo do retorno"
                                        >
                                            {statusLabel(folha.status)}
                                        </button>
                                    ) : (
                                        <span className={`w-fit rounded-full px-3 py-1 text-xs font-bold ${statusClass(folha.status)}`}>
                                            {statusLabel(folha.status)}
                                        </span>
                                    )}
                                    <ReturnedAgeBadge status={folha.status} days={folha.dias_retornada} />
                                </div>
                                <strong>{formatCurrency(folha.valor_total)}</strong>
                                <div>
                                    {['rascunho', 'retornada'].includes(folha.status) ? (
                                        <div className="flex items-center gap-2">
                                            <button
                                                type="button"
                                                onClick={(event) => startEditing(folha, event)}
                                                className="sig-btn h-9 flex-1 justify-center whitespace-nowrap px-3 text-xs"
                                            >
                                                <Pencil size={14} />
                                                Editar
                                            </button>
                                            <button
                                                type="button"
                                                onClick={(event) => submitForAnalysis(folha, event)}
                                                className="sig-btn sig-btn-primary h-9 flex-1 justify-center whitespace-nowrap px-3 text-xs"
                                            >
                                                <Send size={14} />
                                                Enviar para Análise
                                            </button>
                                        </div>
                                    ) : (
                                        <span className="text-xs font-semibold text-[var(--ink-500)]">Fluxo iniciado</span>
                                    )}
                                </div>
                                {expandedFrId === folha.id ? <ChevronDown size={18} /> : <ChevronRight size={18} />}
                            </div>

                            {expandedFrId === folha.id && (
                                <div className="border-t border-[var(--border)]">
                                    {folha.memoria_calculo && (
                                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] bg-blue-50 px-5 py-3">
                                            <div className="flex items-center gap-3">
                                                <FileArchive size={20} className="text-blue-700" />
                                                <div>
                                                    <p className="text-sm font-bold text-blue-900">Memória de cálculo</p>
                                                    <p className="text-xs text-blue-700">{folha.memoria_calculo.nome}</p>
                                                </div>
                                            </div>
                                            <a href={folha.memoria_calculo.download_url} className="sig-btn bg-white text-blue-700 hover:bg-blue-100">
                                                <Download size={16} />
                                                Baixar ZIP
                                            </a>
                                        </div>
                                    )}
                                    <div className="hidden gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-2 text-[11px] font-bold uppercase tracking-wide text-[var(--ink-500)] md:grid md:grid-cols-[100px_minmax(0,1fr)_150px_150px]">
                                        <span>Item</span>
                                        <span>Descrição</span>
                                        <span>Pleito</span>
                                        <span>Valor pleito</span>
                                    </div>
                                    {folha.itens.map((item) => (
                                        <div key={item.id} className="grid gap-3 border-b border-[var(--border)] px-5 py-3 text-sm last:border-b-0 md:grid-cols-[100px_minmax(0,1fr)_150px_150px]">
                                            <strong>{item.item}</strong>
                                            <span>
                                                {item.codigo} - {item.descricao}
                                                <AnalysisRequirementBadges item={item} />
                                            </span>
                                            <strong>{formatDecimal(item.quantidade_pleiteada)} {item.unidade || ''}</strong>
                                            <strong className="text-emerald-700">{formatCurrency(item.valor_pleiteado)}</strong>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </article>
                            ))}
                            </div>
                        </section>
                    ))}
                </section>

                {returnReasonFolha && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
                        <div className="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
                            <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                                <div>
                                    <span className="eyebrow">Motivo do retorno</span>
                                    <h3 className="mt-1 text-lg font-bold text-[var(--ink-900)]">
                                        {returnReasonFolha.codigo}
                                    </h3>
                                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                                        {Number(returnReasonFolha.dias_retornada || 0)} {Number(returnReasonFolha.dias_retornada || 0) === 1 ? 'dia' : 'dias'} retornada
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    onClick={() => setReturnReasonFolha(null)}
                                    className="rounded-full p-2 text-[var(--ink-500)] hover:bg-slate-100 hover:text-[var(--ink-900)]"
                                >
                                    <X size={18} />
                                </button>
                            </header>

                            <div className="space-y-3 px-5 py-4">
                                <p className="text-sm font-semibold text-[var(--ink-900)]">
                                    {returnReasonFolha.comentario}
                                </p>
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <p className="text-[11px] font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                        Engenheiro responsável pelo retorno
                                    </p>
                                    <p className="mt-1 text-sm font-bold text-[var(--ink-900)]">
                                        {returnReasonFolha.responsavel_retorno?.name || 'Não informado'}
                                    </p>
                                    {returnReasonFolha.responsavel_retorno?.email && (
                                        <p className="mt-1 text-xs text-[var(--ink-500)]">
                                            {returnReasonFolha.responsavel_retorno.email}
                                        </p>
                                    )}
                                </div>
                                <div className="rounded-xl border border-red-100 bg-red-50 p-4 text-sm leading-6 text-red-900">
                                    {returnReasonFolha.motivo_retorno || 'Motivo do retorno não informado.'}
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
