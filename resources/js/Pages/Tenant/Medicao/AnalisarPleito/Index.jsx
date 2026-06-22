import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, ChevronDown, ChevronRight, ClipboardCheck, Download, ExternalLink, Filter, RotateCcw, Save, Send, TriangleAlert, X } from 'lucide-react';
import { useState } from 'react';

const formatCurrency = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));

const formatDecimal = (value) =>
    new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(Number(value || 0));

const hasMoreThanFourDecimals = (value) => {
    const normalized = String(value ?? '').replace(',', '.');
    const decimals = normalized.includes('.') ? normalized.split('.')[1] : '';

    return decimals.length > 4;
};

const sectors = [
    { key: 'fiscal', label: 'Fiscal' },
    { key: 'qualidade', label: 'Qualidade' },
    { key: 'medicao', label: 'Medição' },
];

export default function AnalisarPleitoIndex({ selectedContractId, contracts = [], cards = [] }) {
    const page = usePage();
    const tenant = page.props.currentTenant;
    const [selectedGroup, setSelectedGroup] = useState(null);
    const [analysisFolha, setAnalysisFolha] = useState(null);
    const [loadingGroupKey, setLoadingGroupKey] = useState(null);
    const [groupError, setGroupError] = useState('');
    const [analysisLoadingId, setAnalysisLoadingId] = useState(null);
    const [analysisError, setAnalysisError] = useState('');

    const changeContract = (contractId) => {
        router.get(
            route('tenant.medicao.analisar-pleito.index', tenant.slug),
            { contract_id: contractId },
            { preserveScroll: true, preserveState: false }
        );
    };

    const selectRow = async (card, row) => {
        const key = `${card.key}-${row.obra_id || 'sem-obra'}`;

        if (selectedGroup?.key === key) {
            setSelectedGroup(null);
            setGroupError('');
            return;
        }

        setSelectedGroup({
            key,
            cardTitle: card.title,
            cardKey: card.key,
            row: { ...row, boletins: [] },
        });
        setLoadingGroupKey(key);
        setGroupError('');

        try {
            const params = new URLSearchParams({
                contract_id: selectedContractId || '',
                status: card.status,
                obra_id: row.obra_id || 'sem-obra',
            });
            const response = await fetch(`${route('tenant.medicao.analisar-pleito.grupo', tenant.slug)}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error('Não foi possível carregar as FRs deste grupo.');
            }

            const data = await response.json();

            setSelectedGroup((current) => current?.key === key
                ? { ...current, row: { ...current.row, boletins: data.boletins || [] } }
                : current);
        } catch (error) {
            setGroupError(error.message || 'Não foi possível carregar as FRs deste grupo.');
        } finally {
            setLoadingGroupKey(null);
        }
    };

    const openAnalysis = async (folha) => {
        setAnalysisLoadingId(folha.id);
        setAnalysisError('');

        try {
            const response = await fetch(route('tenant.medicao.analisar-pleito.folha', [tenant.slug, folha.id]), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error('Não foi possível carregar os detalhes da FR.');
            }

            const data = await response.json();
            setAnalysisFolha(data.folha);
        } catch (error) {
            setAnalysisError(error.message || 'Não foi possível carregar os detalhes da FR.');
        } finally {
            setAnalysisLoadingId(null);
        }
    };

    const handleFlowMoved = (folhaId) => {
        setAnalysisFolha((current) => current?.id === folhaId ? null : current);

        setSelectedGroup((current) => {
            if (!current) {
                return current;
            }

            const boletins = (current.row.boletins || [])
                .map((boletim) => {
                    const folhas = (boletim.folhas || []).filter((folha) => folha.id !== folhaId);

                    return {
                        ...boletim,
                        folhas,
                        total: folhas.length,
                    };
                })
                .filter((boletim) => boletim.folhas.length > 0);

            return {
                ...current,
                row: {
                    ...current.row,
                    boletins,
                    total: boletins.reduce((sum, boletim) => sum + Number(boletim.total || 0), 0),
                },
            };
        });

        router.reload({
            only: ['cards'],
            preserveScroll: true,
            preserveState: true,
        });
    };

    const isSelected = (cardKey, row) => selectedGroup?.key === `${cardKey}-${row.obra_id || 'sem-obra'}`;

    return (
        <AuthenticatedLayout>
            <Head title="Analisar pleito" />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                <section className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <span className="eyebrow">Medição</span>
                        <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">Analisar pleito</h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-[var(--ink-500)]">
                            Acompanhe as Folhas de Rosto enviadas para análise, separadas por etapa e frente de serviço.
                        </p>
                    </div>
                </section>

                <section className="sig-card p-5">
                    <label className="grid gap-1.5 text-sm">
                        <span className="flex items-center gap-2 font-bold uppercase tracking-wide text-[var(--ink-500)]">
                            <Filter size={14} />
                            Contrato
                        </span>
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

                <section className="grid gap-6 xl:grid-cols-3">
                    {cards.map((card) => (
                        <article key={card.key} className="sig-card overflow-hidden border-t-4 border-t-[var(--primary)]">
                            <header className="flex items-start gap-3 px-7 py-6">
                                <span className="mt-1 flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                                    <ClipboardCheck size={20} />
                                </span>
                                <h2 className="text-xl font-semibold leading-snug text-[var(--ink-900)]">{card.title}</h2>
                            </header>

                            <div className="px-7 pb-7">
                                <div className="overflow-hidden rounded-sm border border-[var(--border)]">
                                    <div className="grid grid-cols-[110px_minmax(0,1fr)_100px] border-b border-[var(--border)] bg-white text-base font-bold text-[var(--ink-900)]">
                                        <span className="border-r border-[var(--border)] px-4 py-4">Código</span>
                                        <span className="border-r border-[var(--border)] px-4 py-4">Obra</span>
                                        <span className="px-4 py-4">Ação</span>
                                    </div>

                                    {card.rows.length === 0 ? (
                                        <div className="grid grid-cols-[minmax(0,1fr)_100px] bg-[var(--surface-muted)] text-base font-bold">
                                            <span className="border-r border-[var(--border)] px-4 py-4 text-right">Total</span>
                                            <span className="px-4 py-4 text-center">0</span>
                                        </div>
                                    ) : (
                                        <>
                                            {card.rows.map((row) => {
                                                const selected = isSelected(card.key, row);

                                                return (
                                                    <div
                                                        key={`${card.key}-${row.obra_id || 'sem-obra'}`}
                                                        className={`grid grid-cols-[110px_minmax(0,1fr)_100px] border-b border-[var(--border)] text-sm last:border-b-0 ${selected ? 'bg-blue-50' : ''}`}
                                                    >
                                                        <span className="border-r border-[var(--border)] px-4 py-4 font-semibold">{row.codigo}</span>
                                                        <span className="border-r border-[var(--border)] px-4 py-4">{row.obra}</span>
                                                        <span className="px-4 py-3 text-center">
                                                            <button
                                                                type="button"
                                                                onClick={() => selectRow(card, row)}
                                                                className={`inline-flex items-center gap-1 rounded-md px-3 py-2 text-sm font-bold text-white transition ${
                                                                    selected ? 'bg-blue-600 hover:bg-blue-700' : 'bg-emerald-600 hover:bg-emerald-700'
                                                                }`}
                                                                title="Ver FRs por BM"
                                                            >
                                                                {row.total}
                                                                {selected ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                                                            </button>
                                                        </span>
                                                    </div>
                                                );
                                            })}

                                            <div className="grid grid-cols-[minmax(0,1fr)_100px] bg-[var(--surface-muted)] text-base font-bold">
                                                <span className="border-r border-[var(--border)] px-4 py-4 text-right">Total</span>
                                                <span className="px-4 py-4 text-center">{card.total}</span>
                                            </div>
                                        </>
                                    )}
                                </div>
                            </div>
                        </article>
                    ))}
                </section>

                {selectedGroup && (
                    <section className="sig-card overflow-hidden border-t-4 border-t-[var(--primary)]">
                        <header className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--border)] px-6 py-5">
                            <div>
                                <span className="eyebrow">Folhas de Rosto por BM</span>
                                <h2 className="mt-1 text-xl font-bold text-[var(--ink-900)]">
                                    {selectedGroup.row.codigo} - {selectedGroup.row.obra}
                                </h2>
                                <p className="mt-1 text-sm text-[var(--ink-500)]">
                                    {selectedGroup.cardTitle} · {selectedGroup.row.total} FR{selectedGroup.row.total === 1 ? '' : 's'} aberta{selectedGroup.row.total === 1 ? '' : 's'}
                                </p>
                            </div>

                            <button
                                type="button"
                                onClick={() => setSelectedGroup(null)}
                                className="sig-btn"
                            >
                                Fechar detalhes
                            </button>
                        </header>

                        {groupError && (
                            <div className="border-b border-red-100 bg-red-50 px-6 py-3 text-sm font-semibold text-red-700">
                                {groupError}
                            </div>
                        )}

                        {analysisError && (
                            <div className="border-b border-red-100 bg-red-50 px-6 py-3 text-sm font-semibold text-red-700">
                                {analysisError}
                            </div>
                        )}

                        <BmDetails
                            boletins={selectedGroup.row.boletins || []}
                            loading={loadingGroupKey === selectedGroup.key}
                            analysisLoadingId={analysisLoadingId}
                            onAnalyze={openAnalysis}
                            onFlowMoved={handleFlowMoved}
                            tenant={tenant}
                        />
                    </section>
                )}

                {analysisFolha && (
                    <AnalysisModal
                        key={analysisFolha.id}
                        folha={analysisFolha}
                        tenant={tenant}
                        onClose={() => setAnalysisFolha(null)}
                    />
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function BmDetails({ boletins = [], loading = false, analysisLoadingId = null, onAnalyze, onFlowMoved, tenant }) {
    if (loading) {
        return (
            <p className="px-6 py-10 text-center text-sm font-semibold text-[var(--ink-500)]">
                Carregando FRs deste grupo...
            </p>
        );
    }

    if (boletins.length === 0) {
        return (
            <p className="px-6 py-10 text-center text-sm text-[var(--ink-500)]">
                Nenhuma FR encontrada para este agrupamento.
            </p>
        );
    }

    return (
        <div className="space-y-4 bg-slate-50 p-5">
            {boletins.map((boletim) => (
                <section key={boletim.id || 'sem-bm'} className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <header className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-100 px-4 py-3">
                        <div>
                            <p className="text-sm font-bold text-[var(--ink-900)]">{boletim.codigo}</p>
                            <p className="text-xs text-[var(--ink-500)]">
                                {boletim.periodo ? `Referência ${boletim.periodo}` : 'Sem período'}
                                {boletim.tipo_label ? ` · ${boletim.tipo_label}` : ''}
                            </p>
                        </div>

                        <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
                            {boletim.total} FR{boletim.total === 1 ? '' : 's'}
                        </span>
                    </header>

                    <div className="divide-y divide-slate-200">
                        {boletim.folhas.map((folha) => (
                            <article key={folha.id} className="grid gap-3 px-4 py-3 lg:grid-cols-[minmax(180px,1fr)_minmax(240px,1.3fr)_140px_220px] lg:items-center">
                                <div className="min-w-0">
                                    {folha.url ? (
                                        <Link href={folha.url} className="mono font-bold text-[var(--primary)] hover:underline">
                                            {folha.codigo}
                                        </Link>
                                    ) : (
                                        <span className="mono font-bold text-[var(--primary)]">{folha.codigo}</span>
                                    )}
                                    <p className="mt-1 truncate text-xs text-[var(--ink-500)]">
                                        {folha.ordem?.codigo || 'OS não informada'} · {folha.creator || 'Solicitante não informado'}
                                    </p>
                                    <AnalysisAgeBadge days={folha.dias_em_analise} />
                                </div>

                                <div className="min-w-0">
                                    <p className="truncate text-sm font-semibold text-[var(--ink-900)]">{folha.comentario}</p>
                                    <p className="mt-1 truncate text-xs text-[var(--ink-500)]">
                                        {folha.construtora?.sigla ? `${folha.construtora.sigla} - ` : ''}{folha.construtora?.nome || 'Construtora não informada'}
                                        {folha.created_at ? ` · ${folha.created_at}` : ''}
                                    </p>
                                </div>

                                <strong className="text-right text-sm text-emerald-700 lg:text-left">
                                    {formatCurrency(folha.valor_total)}
                                </strong>

                                <div className="grid gap-2">
                                    <button
                                        type="button"
                                        onClick={() => onAnalyze(folha)}
                                        disabled={analysisLoadingId === folha.id}
                                        className="sig-btn sig-btn-primary justify-center px-3 py-2 text-xs"
                                    >
                                        {analysisLoadingId === folha.id ? 'Carregando...' : 'Analisar'}
                                    </button>

                                    <InlineFlowControls folha={folha} tenant={tenant} onMoved={onFlowMoved} />
                                </div>
                            </article>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}


function InlineFlowControls({ folha, tenant, onMoved }) {
    const actions = flowActionsForSector(folha.active_sector);
    const [open, setOpen] = useState(false);
    const [showReturnReason, setShowReturnReason] = useState(false);
    const form = useForm({
        action: '',
        motivo: '',
    });

    if (actions.length === 0) {
        return null;
    }

    const moveFlow = (action) => {
        if (action === 'retornar_construtora' && !showReturnReason) {
            setShowReturnReason(true);
            return;
        }

        if (action === 'retornar_construtora' && !form.data.motivo.trim()) {
            form.setError('motivo', 'Informe o motivo do retorno.');
            return;
        }

        const labels = {
            fiscal: 'submeter esta FR para o fiscal',
            qualidade: 'submeter esta FR para a qualidade',
            medicao: 'submeter esta FR para a medição',
            finalizar: 'finalizar a análise desta FR',
            retornar_construtora: 'retornar esta FR para a construtora',
        };

        if (!window.confirm(`Deseja ${labels[action] || 'movimentar esta FR'}?`)) {
            return;
        }

        form.clearErrors();
        form.transform((data) => ({
            ...data,
            action,
        }));
        form.patch(route('tenant.medicao.analisar-pleito.fluxo', [tenant.slug, folha.id]), {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setShowReturnReason(false);
                form.reset('motivo');
                onMoved?.(folha.id);
            },
        });
    };

    return (
        <div>
            <button
                type="button"
                onClick={() => setOpen((current) => !current)}
                className="sig-btn sig-btn-secondary w-full justify-center px-3 py-2 text-xs"
            >
                Fluxo
                <ChevronDown size={14} className={`transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="mt-2 rounded-lg border border-amber-100 bg-amber-50 p-2 shadow-sm">
                    <div className="grid gap-1.5">
                        {actions.map((action) => {
                            const Icon = action.icon;

                            return (
                                <button
                                    key={action.key}
                                    type="button"
                                    onClick={() => moveFlow(action.key)}
                                    disabled={form.processing}
                                    className={`sig-btn w-full justify-start whitespace-normal px-2.5 py-2 text-left text-[11px] leading-tight ${action.variant === 'danger' ? 'sig-btn-secondary text-red-700' : 'sig-btn-primary'}`}
                                >
                                    <Icon size={13} className="shrink-0" />
                                    <span>{action.label}</span>
                                </button>
                            );
                        })}
                    </div>

                    {showReturnReason && (
                        <label className="mt-2 grid gap-1 text-[11px]">
                            <textarea
                                value={form.data.motivo}
                                onChange={(event) => form.setData('motivo', event.target.value)}
                                className={`sig-input min-h-16 bg-white text-xs ${form.errors.motivo ? 'border-red-300 bg-red-50' : ''}`}
                                placeholder="Motivo obrigatório do retorno"
                            />
                            {form.errors.motivo && (
                                <span className="font-semibold text-red-600">{form.errors.motivo}</span>
                            )}
                        </label>
                    )}
                </div>
            )}
        </div>
    );
}

function AnalysisAgeBadge({ days = 0 }) {
    const totalDays = Number(days || 0);
    const className = totalDays <= 5
        ? 'bg-emerald-50 text-emerald-700'
        : totalDays <= 10
            ? 'bg-amber-50 text-amber-700'
            : 'bg-red-50 text-red-700';

    return (
        <span className={`mt-2 inline-flex rounded-full px-2.5 py-1 text-[11px] font-bold ${className}`}>
            {totalDays} {totalDays === 1 ? 'dia' : 'dias'} em análise
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
                <span key={requirement} className="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-blue-700">
                    {requirement}
                </span>
            ))}
        </div>
    );
}

function AnalysisModal({ folha, tenant, onClose }) {
    const activeSectors = folha.active_sector
        ? sectors.filter((sector) => sector.key === folha.active_sector)
        : [];
    const hasItemAnalysis = (sectorKey) => folha.itens.some((item) => {
        const analysis = item.analises?.[sectorKey];

        return analysis
            && (analysis.quantidade_aprovada !== '' || Boolean(analysis.comentario));
    });
    const previousAnalysisSectors = folha.active_sector === 'qualidade'
        ? sectors.filter((sector) => sector.key === 'fiscal')
        : folha.active_sector === 'medicao'
            ? sectors.filter((sector) => sector.key === 'fiscal'
                || (sector.key === 'qualidade' && hasItemAnalysis('qualidade')))
            : [];
    const analysisGridColumns = [
        '70px',
        'minmax(210px,1fr)',
        '100px',
        '100px',
        '90px',
        '100px',
        ...previousAnalysisSectors.map(() => '200px'),
        'minmax(240px,1fr)',
    ].join(' ');
    const form = useForm({
        setores: buildInitialAnalysisData(folha),
    });

    const hasQuantityOverBalance = activeSectors.some((sector) =>
        folha.itens.some((item) =>
            Number(form.data.setores[sector.key]?.itens?.[item.id]?.quantidade_aprovada || 0) > Number(item.saldo || 0)
        )
    );
    const hasQuantityPrecisionError = activeSectors.some((sector) =>
        folha.itens.some((item) =>
            hasMoreThanFourDecimals(form.data.setores[sector.key]?.itens?.[item.id]?.quantidade_aprovada)
        )
    );

    const updateItem = (sectorKey, itemId, field, value) => {
        form.setData('setores', {
            ...form.data.setores,
            [sectorKey]: {
                ...form.data.setores[sectorKey],
                itens: {
                    ...form.data.setores[sectorKey].itens,
                    [itemId]: {
                        ...form.data.setores[sectorKey].itens[itemId],
                        [field]: value,
                    },
                },
            },
        });
    };

    const updateGeneralComment = (sectorKey, value) => {
        form.setData('setores', {
            ...form.data.setores,
            [sectorKey]: {
                ...form.data.setores[sectorKey],
                comentario_geral: value,
            },
        });
    };

    const submit = (event) => {
        event.preventDefault();

        if (hasQuantityOverBalance || hasQuantityPrecisionError) {
            return;
        }

        form.post(route('tenant.medicao.analisar-pleito.analise.store', [tenant.slug, folha.id]), {
            preserveScroll: true,
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
            <form onSubmit={submit} className="my-6 w-full max-w-7xl overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header className="flex flex-wrap items-start justify-between gap-4 border-b border-[var(--border)] px-6 py-5">
                    <div>
                        <span className="eyebrow">Análise do pleito</span>
                        <h2 className="mt-1 text-2xl font-bold text-[var(--ink-900)]">{folha.codigo}</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            {folha.ordem?.codigo || 'OS não informada'} · Etapa atual: {folha.active_sector_label || 'Não informada'} · {formatCurrency(folha.valor_total)}
                        </p>
                    </div>

                    <button type="button" onClick={onClose} className="sig-btn">
                        <X size={16} />
                        Fechar
                    </button>
                </header>

                <div className="max-h-[72vh] overflow-auto p-5">
                    <section className="mb-5 grid gap-3 rounded-xl border border-[var(--border)] bg-[var(--surface-muted)] p-4 text-sm md:grid-cols-2 xl:grid-cols-5">
                        <InfoItem label="Solicitante" value={folha.creator || 'Não informado'} />
                        <InfoItem
                            label="Gerenciadora"
                            value={folha.gerenciadora?.nome
                                ? `${folha.gerenciadora?.sigla ? `${folha.gerenciadora.sigla} - ` : ''}${folha.gerenciadora.nome}`
                                : 'Não informada'}
                        />
                        <InfoItem
                            label="Construtora"
                            value={folha.construtora?.nome
                                ? `${folha.construtora?.sigla ? `${folha.construtora.sigla} - ` : ''}${folha.construtora.nome}`
                                : 'Não informada'}
                        />
                        <InfoItem label="Data de envio" value={folha.data_envio || folha.created_at || 'Não informada'} />
                        <div>
                            <span className="text-[11px] font-bold uppercase tracking-wide text-[var(--ink-500)]">Memória de cálculo</span>
                            {folha.memoria_calculo?.download_url ? (
                                <a href={folha.memoria_calculo.download_url} className="sig-btn mt-1 w-fit bg-white text-[var(--primary)] hover:bg-blue-50">
                                    <Download size={16} />
                                    Baixar ZIP
                                </a>
                            ) : (
                                <p className="mt-1 font-semibold text-[var(--ink-900)]">Não enviada</p>
                            )}
                        </div>
                    </section>

                    <ProjectLinkCard projetos={folha.ordem?.projetos || (folha.ordem?.projeto ? [folha.ordem.projeto] : [])} />

                    <section className="mb-5 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3">
                        <span className="text-[11px] font-bold uppercase tracking-wide text-blue-700">
                            Comentário da construtora
                        </span>
                        <p className="mt-1 whitespace-pre-wrap text-sm font-semibold text-blue-950">
                            {folha.comentario || 'Sem comentário informado.'}
                        </p>
                    </section>

                    {activeSectors.length === 0 && (
                        <div className="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
                            Esta FR não está em uma etapa editável de análise.
                        </div>
                    )}

                    <section className="mb-5 grid gap-3">
                        {activeSectors.map((sector) => (
                            <label key={sector.key} className="grid gap-1.5 text-sm">
                                <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                    Comentário geral - {sector.label}
                                </span>
                                <textarea
                                    value={form.data.setores[sector.key]?.comentario_geral || ''}
                                    onChange={(event) => updateGeneralComment(sector.key, event.target.value)}
                                    className="sig-input min-h-20"
                                    placeholder={`Comentário geral da análise de ${sector.label.toLowerCase()}`}
                                />
                            </label>
                        ))}
                    </section>

                    <div
                        className="min-w-[960px] overflow-hidden rounded-xl border border-[var(--border)]"
                        style={{ minWidth: `${960 + (previousAnalysisSectors.length * 210)}px` }}
                    >
                        <div
                            className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-[var(--ink-500)]"
                            style={{ gridTemplateColumns: analysisGridColumns }}
                        >
                            <span>Item</span>
                            <span>Descrição</span>
                            <span>Preço P0</span>
                            <span>Preço reaj.</span>
                            <span>Saldo</span>
                            <span>Pleito</span>
                            {previousAnalysisSectors.map((sector) => (
                                <span key={`header-${sector.key}`}>Análise {sector.label}</span>
                            ))}
                            {activeSectors.map((sector) => (
                                <span key={sector.key}>Aprovado {sector.label}</span>
                            ))}
                        </div>

                        <div className="divide-y divide-[var(--border)]">
                            {folha.itens.map((item) => (
                                <div
                                    key={item.id}
                                    className="grid gap-3 px-3 py-3 text-xs"
                                    style={{ gridTemplateColumns: analysisGridColumns }}
                                >
                                    <strong>{item.item}</strong>
                                    <div>
                                        <p className="font-semibold text-[var(--ink-900)]">{item.codigo} - {item.descricao}</p>
                                        <p className="mt-1 text-[11px] text-[var(--ink-500)]">Unidade: {item.unidade || '-'}</p>
                                        <AnalysisRequirementBadges item={item} />
                                    </div>
                                    <strong>{formatCurrency(item.preco_p0)}</strong>
                                    <strong>{formatCurrency(item.preco_reajustado)}</strong>
                                    <strong className="text-emerald-700">{formatDecimal(item.saldo)} {item.unidade || ''}</strong>
                                    <strong className="text-blue-700">{formatDecimal(item.quantidade_pleiteada)} {item.unidade || ''}</strong>

                                    {previousAnalysisSectors.map((sector) => {
                                        const previousAnalysis = item.analises?.[sector.key];

                                        return (
                                            <div key={`previous-${item.id}-${sector.key}`} className="min-w-0 rounded-lg border border-slate-200 bg-slate-50 p-2">
                                                <span className="block text-[10px] font-bold uppercase tracking-wide text-slate-500">
                                                    Quantitativo {sector.label.toLowerCase()}
                                                </span>
                                                <strong className="mt-1 block text-sm text-slate-900">
                                                    {previousAnalysis?.quantidade_aprovada !== ''
                                                        && previousAnalysis?.quantidade_aprovada !== undefined
                                                        ? `${formatDecimal(previousAnalysis.quantidade_aprovada)} ${item.unidade || ''}`
                                                        : 'Não informado'}
                                                </strong>
                                                <span className="mt-2 block text-[10px] font-bold uppercase tracking-wide text-slate-500">
                                                    Comentário {sector.label.toLowerCase()}
                                                </span>
                                                <p className="mt-1 break-words whitespace-pre-wrap text-[11px] leading-4 text-slate-700">
                                                    {previousAnalysis?.comentario || 'Sem comentário.'}
                                                </p>
                                            </div>
                                        );
                                    })}

                                    {activeSectors.map((sector) => {
                                        const approvedQuantity = Number(form.data.setores[sector.key]?.itens?.[item.id]?.quantidade_aprovada || 0);
                                        const isOverBalance = approvedQuantity > Number(item.saldo || 0);
                                        const hasPrecisionError = hasMoreThanFourDecimals(form.data.setores[sector.key]?.itens?.[item.id]?.quantidade_aprovada);

                                        return (
                                            <div key={`${item.id}-${sector.key}`} className="grid min-w-0 gap-2">
                                                <input
                                                    type="number"
                                                    min="0"
                                                    max={item.saldo}
                                                    step="0.0001"
                                                    value={form.data.setores[sector.key]?.itens?.[item.id]?.quantidade_aprovada ?? ''}
                                                    onChange={(event) => updateItem(sector.key, item.id, 'quantidade_aprovada', event.target.value)}
                                                    className={`sig-input h-9 w-full min-w-0 ${isOverBalance || hasPrecisionError ? 'border-red-300 bg-red-50 text-red-800 focus:border-red-500 focus:ring-red-200' : ''}`}
                                                    placeholder="Qtd."
                                                />
                                                {isOverBalance && (
                                                    <p className="text-[11px] font-semibold leading-tight text-red-600">
                                                        Sem saldo para aprovar essa quantidade.
                                                    </p>
                                                )}
                                                {hasPrecisionError && (
                                                    <p className="text-[11px] font-semibold leading-tight text-red-600">
                                                        Use no máximo 4 casas decimais.
                                                    </p>
                                                )}
                                                <textarea
                                                    value={form.data.setores[sector.key]?.itens?.[item.id]?.comentario || ''}
                                                    onChange={(event) => updateItem(sector.key, item.id, 'comentario', event.target.value)}
                                                    className="sig-input min-h-16 w-full min-w-0 resize-y text-xs"
                                                    placeholder={`Comentário ${sector.label.toLowerCase()}`}
                                                />
                                            </div>
                                        );
                                    })}
                                </div>
                            ))}
                        </div>
                    </div>

                </div>

                <footer className="flex flex-wrap items-center justify-end gap-3 border-t border-[var(--border)] px-6 py-4">
                    {Object.values(form.errors || {}).length > 0 && (
                        <span className="mr-auto text-sm font-semibold text-red-600">
                            {Object.values(form.errors)[0]}
                        </span>
                    )}
                    {hasQuantityOverBalance && (
                        <span className="mr-auto text-sm font-semibold text-red-600">
                            Ajuste as quantidades acima do saldo antes de salvar.
                        </span>
                    )}
                    {hasQuantityPrecisionError && (
                        <span className="mr-auto text-sm font-semibold text-red-600">
                            Use no máximo 4 casas decimais nos quantitativos.
                        </span>
                    )}
                    <button type="button" onClick={onClose} className="sig-btn">
                        Cancelar
                    </button>
                    <button type="submit" disabled={form.processing || activeSectors.length === 0 || hasQuantityOverBalance || hasQuantityPrecisionError} className="sig-btn sig-btn-primary">
                        <Save size={16} />
                        Salvar análise
                    </button>
                </footer>
            </form>
        </div>
    );
}

function ProjectLinkCard({ projetos = [] }) {
    if (projetos.length === 0) {
        return (
            <section className="mb-5 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                <span className="text-[11px] font-bold uppercase tracking-wide text-[var(--ink-500)]">
                    Projetos vinculados à OS
                </span>
                <p className="mt-1 font-semibold text-[var(--ink-700)]">Nenhum projeto vinculado a esta OS.</p>
            </section>
        );
    }

    return (
        <section className="mb-5 rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <span className="text-[11px] font-bold uppercase tracking-wide text-indigo-700">
                    Projetos vinculados à OS
                </span>
                <span className="sig-pill sig-pill-blue">{projetos.length} projeto(s)</span>
            </div>

            <div className="grid gap-2">
                {projetos.map((projeto) => {
                    const openRncsCount = Number(projeto.open_rncs_count || 0);

                    return (
                        <div key={projeto.id} className="flex flex-col gap-2 rounded-lg bg-white p-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="min-w-0">
                                <p className="font-semibold text-indigo-950">
                                    {projeto.codigo || 'Sem código'} - {projeto.titulo || 'Projeto vinculado'}
                                </p>
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    {openRncsCount > 0 ? (
                                        projeto.first_open_rnc?.url ? (
                                            <a
                                                href={projeto.first_open_rnc.url}
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
                                                {openRncsCount} {openRncsCount === 1 ? 'RNC aberta' : 'RNCs abertas'}
                                            </span>
                                        )
                                    ) : (
                                        <span className="sig-pill sig-pill-green">Sem RNC aberta</span>
                                    )}
                                </div>
                            </div>

                            <a href={projeto.url} target="_blank" rel="noreferrer" className="sig-btn sig-btn-primary w-fit">
                                <ExternalLink size={16} />
                                Visualizar projeto
                            </a>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}

function InfoItem({ label, value }) {
    return (
        <div>
            <span className="text-[11px] font-bold uppercase tracking-wide text-[var(--ink-500)]">{label}</span>
            <p className="mt-1 font-semibold text-[var(--ink-900)]">{value}</p>
        </div>
    );
}

function buildInitialAnalysisData(folha) {
    return sectors.reduce((acc, sector) => {
        acc[sector.key] = {
            comentario_geral: folha.analises?.[sector.key]?.comentario_geral || '',
            itens: folha.itens.reduce((itemAcc, item) => {
                itemAcc[item.id] = {
                    quantidade_aprovada: item.analises?.[sector.key]?.quantidade_aprovada ?? '',
                    comentario: item.analises?.[sector.key]?.comentario || '',
                };

                return itemAcc;
            }, {}),
        };

        return acc;
    }, {});
}

function flowActionsForSector(sector) {
    const returnAction = {
        key: 'retornar_construtora',
        label: 'Retornar para construtora',
        icon: RotateCcw,
        variant: 'danger',
    };

    if (sector === 'fiscal') {
        return [
            { key: 'qualidade', label: 'Submeter para qualidade', icon: Send },
            { key: 'medicao', label: 'Submeter para medição', icon: Send },
            returnAction,
        ];
    }

    if (sector === 'qualidade') {
        return [
            { key: 'fiscal', label: 'Submeter para fiscal', icon: Send },
            { key: 'medicao', label: 'Submeter para medição', icon: Send },
            returnAction,
        ];
    }

    if (sector === 'medicao') {
        return [
            { key: 'fiscal', label: 'Submeter para fiscal', icon: Send },
            { key: 'qualidade', label: 'Submeter para qualidade', icon: Send },
            returnAction,
            { key: 'finalizar', label: 'Finalizar análise', icon: CheckCircle2 },
        ];
    }

    if (sector === 'analisada') {
        return [
            { key: 'fiscal', label: 'Retornar para fiscal', icon: RotateCcw },
            { key: 'qualidade', label: 'Retornar para qualidade', icon: RotateCcw },
            { key: 'medicao', label: 'Retornar para medição', icon: RotateCcw },
            returnAction,
        ];
    }

    return [];
}
