import { Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertCircle, ArrowLeft, Boxes, Check, ChevronDown, ChevronRight, Layers3, PackagePlus, Pencil, Plus, Search, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import OrcamentoShell from '../Partials/OrcamentoShell';

const SICRO3_COMPOSITION_SECTIONS = [
    { value: 'atividades_auxiliares', code: 'D', label: 'Atividades Auxiliares' },
    { value: 'tempo_fixo', code: 'E', label: 'Tempo Fixo' },
    { value: 'momento_transporte', code: 'F', label: 'Momento de Transporte' },
];

const SICRO3_TRANSPORT_TYPES = [
    { value: 'ln', code: 'LN', label: 'Composicao LN' },
    { value: 'rp', code: 'RP', label: 'Composicao RP' },
    { value: 'p', code: 'P', label: 'Composicao P' },
    { value: 'fe', code: 'FE', label: 'Composicao FE' },
];

export default function ShowComposicao({
    tenant,
    composicao,
    detail = null,
    items = [],
    insumoOptions = [],
    composicaoOptions = [],
    insumoFormOptions = {},
}) {
    const page = usePage();
    const [activeBuilder, setActiveBuilder] = useState(null);
    const [createInsumoOpen, setCreateInsumoOpen] = useState(false);

    return (
        <OrcamentoShell
            tenant={tenant}
            active="composicoes"
            title={compositionTitle(composicao)}
            subtitle="Detalhamento da composicao, bases por UF e itens analiticos vinculados."
            showNav={false}
        >
            {page.props.flash?.success && (
                <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                    {page.props.flash.success}
                </div>
            )}

            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <Link className="sig-btn sig-btn-secondary" href={route('tenant.orcamentos.composicoes.index', tenant.slug)}>
                    <ArrowLeft size={15} />
                    Voltar para composicoes
                </Link>
            </div>

            <AnaliticoDetail composicao={composicao} detail={detail} tenant={tenant} />

            {composicao.scope === 'tenant' && (
                <section className="mt-6 overflow-hidden rounded-lg border border-[var(--primary)] bg-white shadow-[var(--shadow-sm)]">
                    <ComposicaoHeader composicao={composicao} />

                    <div className="px-6 py-8">
                        <div className="mb-6 text-center">
                            <h3 className="text-2xl font-bold text-[var(--primary)]">Editar Composicao Propria</h3>
                            <p className="mt-2 text-sm font-semibold text-[var(--ink-500)]">
                                Adicione composicoes auxiliares, insumos existentes ou crie um insumo proprio para esta composicao.
                            </p>
                        </div>

                        <div className="mx-auto mb-6 grid max-w-5xl gap-3 md:grid-cols-3">
                            <button
                                className={`sig-btn justify-center ${activeBuilder === 'composicao' ? 'sig-btn-primary' : 'sig-btn-secondary'}`}
                                type="button"
                                onClick={() => setActiveBuilder((current) => (current === 'composicao' ? null : 'composicao'))}
                            >
                                <Layers3 size={15} />
                                Adicionar Composicao
                            </button>
                            <button
                                className={`sig-btn justify-center ${activeBuilder === 'insumo' ? 'sig-btn-primary' : 'sig-btn-secondary'}`}
                                type="button"
                                onClick={() => setActiveBuilder((current) => (current === 'insumo' ? null : 'insumo'))}
                            >
                                <Boxes size={15} />
                                Adicionar Insumo
                            </button>
                            <button className="sig-btn sig-btn-secondary justify-center" type="button" onClick={() => setCreateInsumoOpen(true)}>
                                <PackagePlus size={15} />
                                Criar Insumo
                            </button>
                        </div>

                        {activeBuilder === 'insumo' && (
                            <ItemBuilder
                                composicao={composicao}
                                existingItems={items}
                                itemType="insumo"
                                options={insumoOptions}
                                tenant={tenant}
                                title="Adicionar insumo da base de referencia"
                                onCancel={() => setActiveBuilder(null)}
                            />
                        )}

                        {activeBuilder === 'composicao' && (
                            <ItemBuilder
                                composicao={composicao}
                                existingItems={items}
                                itemType="composicao"
                                options={composicaoOptions}
                                tenant={tenant}
                                title={String(composicao.modelo).toUpperCase() === 'SICRO3' ? 'Adicionar composicao ao analitico SICRO3' : 'Adicionar composicao da base propria'}
                                onCancel={() => setActiveBuilder(null)}
                            />
                        )}

                        <CompositionItemsTable composicao={composicao} items={items} tenant={tenant} />
                    </div>
                </section>
            )}

            {createInsumoOpen && composicao.scope === 'tenant' && (
                <CreateInsumoModal
                    composicao={composicao}
                    options={insumoFormOptions}
                    tenant={tenant}
                    onClose={() => setCreateInsumoOpen(false)}
                />
            )}
        </OrcamentoShell>
    );
}

function AnaliticoDetail({ composicao, detail, tenant }) {
    const states = detail?.states ?? [];
    const initialState = states.find((state) => state.uf === composicao.uf)?.uf ?? states[0]?.uf ?? null;
    const [openState, setOpenState] = useState(initialState);
    const isSicro3 = String(detail?.modelo ?? composicao.modelo ?? '').toUpperCase() === 'SICRO3';
    const itemPriceDecimals = isSicro3 ? 4 : 2;

    if (isSicro3) {
        return (
            <Sicro3AnaliticoDetail
                composicao={composicao}
                detail={detail}
                openState={openState}
                setOpenState={setOpenState}
                states={states}
                tenant={tenant}
            />
        );
    }

    return (
        <section className="overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-[var(--shadow-sm)]">
            <header className="border-b border-[var(--border)] bg-white px-5 py-5">
                <p className="text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--ink-400)]">
                    Detalhamento de composicoes com precos por UF
                </p>
                <div className="mt-2 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-3xl font-semibold text-[var(--primary)]">
                            {compositionTitle({
                                codigo: detail?.codigo ?? composicao.codigo,
                                descricao: detail?.descricao ?? composicao.descricao,
                            })}
                        </h1>
                    </div>
                    <div className="grid gap-2 text-xs sm:grid-cols-3 lg:min-w-[520px]">
                        <InfoTile label="Data" value={detail?.data ?? firstReferenceLabel(composicao)} />
                        <InfoTile label="Tipo" value={detail?.tipo ?? composicao.tipo_composicao} />
                        <InfoTile label="Unidade" value={detail?.unidade ?? composicao.unidade} />
                    </div>
                </div>
            </header>

            {states.length === 0 ? (
                <div className="p-8 text-center text-sm text-[var(--ink-500)]">
                    Nenhum detalhamento analitico encontrado para esta composicao na data selecionada.
                </div>
            ) : (
                <div className="divide-y divide-[var(--border)]">
                    {states.map((state) => {
                        const isOpen = openState === state.uf;

                        return (
                            <article key={state.uf} className="bg-white">
                                <button
                                    className={`grid w-full gap-3 px-5 py-4 text-left transition hover:bg-[var(--primary-50)]/60 lg:grid-cols-[170px_1fr_1fr_auto] lg:items-center ${
                                        isOpen ? 'bg-[var(--primary-50)]/50' : ''
                                    }`}
                                    type="button"
                                    onClick={() => setOpenState(isOpen ? null : state.uf)}
                                >
                                    <div className="flex items-center gap-3">
                                        <span className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-white text-[var(--primary)] shadow-[var(--shadow-sm)]">
                                            {isOpen ? <ChevronDown size={17} /> : <ChevronRight size={17} />}
                                        </span>
                                        <div>
                                            <p className="text-sm font-bold text-[var(--ink-900)]">{state.estado_label}</p>
                                            <p className="text-xs font-semibold text-[var(--ink-400)]">{state.uf}</p>
                                        </div>
                                    </div>
                                    <StateValue label="Valor Nao Desonerado" value={state.effective_preco_onerado} />
                                    <StateValue label="Valor Desonerado" value={state.effective_preco_desonerado} />
                                    <div className="flex flex-col items-start gap-1 lg:items-end">
                                        <span className="inline-flex min-h-8 items-center justify-center rounded-full bg-white px-3 text-xs font-bold text-[var(--ink-500)] shadow-[var(--shadow-sm)]">
                                            {state.items_count} itens
                                        </span>
                                        <StateQualityNote state={state} />
                                    </div>
                                </button>

                                {isOpen && (
                                    <AnaliticoItems state={state} tenant={tenant} priceDecimals={itemPriceDecimals} />
                                )}
                            </article>
                        );
                    })}
                </div>
            )}
        </section>
    );
}

function Sicro3AnaliticoDetail({ composicao, detail, openState, setOpenState, states, tenant }) {
    if (states.length === 0) {
        return (
            <section className="overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-[var(--shadow-sm)]">
                <div className="p-8 text-center text-sm text-[var(--ink-500)]">
                    Nenhum detalhamento analitico encontrado para esta composicao na data selecionada.
                </div>
            </section>
        );
    }

    return (
        <div className="space-y-4">
            {states.map((state) => {
                const isOpen = states.length === 1 || openState === state.uf;
                const stateComposicao = {
                    ...composicao,
                    codigo: detail?.codigo ?? composicao.codigo,
                    descricao: detail?.descricao ?? composicao.descricao,
                    tipo_composicao: detail?.tipo ?? composicao.tipo_composicao,
                    unidade: detail?.unidade ?? composicao.unidade,
                    uf: state.uf,
                    estado_label: state.estado_label,
                    base_references: [{ data: state.data ?? detail?.data ?? firstReferenceLabel(composicao) }],
                    producao_equipe: state.producao_equipe ?? composicao.producao_equipe,
                    fator_influencia_chuvas: state.fator_influencia_chuvas ?? composicao.fator_influencia_chuvas,
                    preco_onerado: state.effective_preco_onerado,
                    preco_desonerado: state.effective_preco_desonerado,
                    sicro3_summary: state.sicro3_summary,
                };

                return (
                    <article key={state.uf} className="overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-[var(--shadow-sm)]">
                        {states.length > 1 && (
                            <button
                                className={`flex w-full items-center justify-between gap-4 px-5 py-4 text-left transition hover:bg-[var(--primary-50)]/60 ${
                                    isOpen ? 'bg-[var(--primary-50)]/50' : ''
                                }`}
                                type="button"
                                onClick={() => setOpenState(isOpen ? null : state.uf)}
                            >
                                <span className="flex items-center gap-3">
                                    <span className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-white text-[var(--primary)] shadow-[var(--shadow-sm)]">
                                        {isOpen ? <ChevronDown size={17} /> : <ChevronRight size={17} />}
                                    </span>
                                    <span>
                                        <span className="block text-sm font-bold text-[var(--ink-900)]">{state.estado_label}</span>
                                        <span className="block text-xs font-semibold text-[var(--ink-400)]">{state.uf}</span>
                                    </span>
                                </span>
                                <span className="text-xs font-bold text-[var(--ink-500)]">{state.items_count} itens</span>
                            </button>
                        )}

                        {isOpen && (
                            <Sicro3OwnCompositionItemsTable
                                composicao={stateComposicao}
                                items={state.items ?? []}
                                readOnly
                                tenant={tenant}
                            />
                        )}
                    </article>
                );
            })}
        </div>
    );
}

function InfoTile({ label, value }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-3 py-2">
            <span className="text-[10px] font-bold uppercase tracking-[0.06em] text-[var(--ink-400)]">{label}</span>
            <p className="mt-1 break-words text-sm font-bold text-[var(--ink-900)]">{value || '-'}</p>
        </div>
    );
}

function compositionTitle(composicao) {
    const code = String(composicao?.codigo ?? '').trim();
    const description = String(composicao?.descricao ?? '').trim();

    return [code, description].filter(Boolean).join(' - ') || '-';
}

function StateValue({ label, value }) {
    return (
        <div>
            <span className="text-[10px] font-bold uppercase tracking-[0.06em] text-[var(--ink-400)]">{label}</span>
            <p className="mt-1 font-mono text-sm font-bold text-[var(--ink-900)]">{formatCurrency(value)}</p>
        </div>
    );
}

function StateQualityNote({ state }) {
    const missing = Number(state.missing_price_items_count ?? 0);
    const isCalculated = state.price_source === 'analytic';

    if (!isCalculated && missing <= 0) {
        return null;
    }

    return (
        <div className="mt-1 flex flex-wrap items-center gap-1 text-[10px] font-semibold">
            {isCalculated && (
                <span className="rounded-full bg-blue-50 px-2 py-0.5 text-blue-700">
                    Calculado
                </span>
            )}
            {missing > 0 && (
                <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">
                    <AlertCircle size={11} />
                    {missing} sem preco
                </span>
            )}
        </div>
    );
}

function AnaliticoItems({ state, tenant, priceDecimals = 2 }) {
    if (!state.items?.length) {
        return (
            <div className="border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-6 text-sm text-[var(--ink-500)]">
                Nenhum item analitico encontrado para esta UF.
            </div>
        );
    }

    return (
        <div className="border-t border-[var(--border)] bg-[var(--surface-muted)] p-3 sm:p-5">
            <div className="hidden overflow-hidden rounded-lg border border-[var(--border)] bg-white xl:block">
                <table className="w-full table-fixed border-collapse text-left text-xs">
                    <colgroup>
                        <col className="w-[4%]" />
                        <col className="w-[8%]" />
                        <col className="w-[30%]" />
                        <col className="w-[13%]" />
                        <col className="w-[6%]" />
                        <col className="w-[9%]" />
                        <col className="w-[9%]" />
                        <col className="w-[7%]" />
                        <col className="w-[7%]" />
                        <col className="w-[7%]" />
                    </colgroup>
                    <thead className="bg-[var(--ink-900)] text-white">
                        <tr>
                            <TableHead></TableHead>
                            <TableHead>Codigo</TableHead>
                            <TableHead>Descricao</TableHead>
                            <TableHead>Tipo</TableHead>
                            <TableHead>Unid.</TableHead>
                            <TableHead className="text-right">Unit. Nao Des.</TableHead>
                            <TableHead className="text-right">Unit. Des.</TableHead>
                            <TableHead className="text-right">Coef.</TableHead>
                            <TableHead className="text-right">Total Nao Des.</TableHead>
                            <TableHead className="text-right">Total Des.</TableHead>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-[var(--border)]">
                        {state.items.map((item) => (
                            <tr
                                key={item.id}
                                className={`${item.item_type === 'composicao' ? 'bg-emerald-50/80' : 'bg-amber-50/70'} hover:bg-[var(--primary-50)]`}
                            >
                                <td className="px-3 py-3 font-mono font-bold text-[var(--primary)]">{item.marker}</td>
                                <td className="px-3 py-3 font-mono font-bold text-[var(--primary)]">
                                    <CompositionCodeLink item={item} tenant={tenant} />
                                </td>
                                <td className="px-3 py-3 font-semibold leading-5 text-[var(--ink-800)]">{item.descricao}</td>
                                <td className="px-3 py-3 text-[var(--ink-600)]">
                                    <div className="flex flex-col gap-1">
                                        <span>{item.tipo || item.item_type_label}</span>
                                        <ItemPriceWarning item={item} />
                                    </div>
                                </td>
                                <td className="px-3 py-3 font-semibold text-[var(--ink-700)]">{item.unidade || '-'}</td>
                                <td className="px-3 py-3 text-right font-mono">{formatCurrency(item.preco_unitario_onerado, priceDecimals)}</td>
                                <td className="px-3 py-3 text-right font-mono">{formatCurrency(item.preco_unitario_desonerado, priceDecimals)}</td>
                                <td className="px-3 py-3 text-right font-mono">{formatNumber(item.coeficiente, 6)}</td>
                                <td className="px-3 py-3 text-right font-mono font-semibold">{formatCurrency(item.preco_onerado, priceDecimals)}</td>
                                <td className="px-3 py-3 text-right font-mono font-semibold">{formatCurrency(item.preco_desonerado, priceDecimals)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="grid gap-3 xl:hidden">
                {state.items.map((item) => (
                    <article
                        key={item.id}
                        className={`rounded-lg border border-[var(--border)] p-4 shadow-[var(--shadow-sm)] ${
                            item.item_type === 'composicao' ? 'bg-emerald-50/80' : 'bg-amber-50/70'
                        }`}
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="font-mono text-xs font-bold text-[var(--primary)]">
                                    {item.marker} <CompositionCodeLink item={item} tenant={tenant} />
                                </p>
                                <h3 className="mt-2 break-words text-sm font-bold text-[var(--ink-900)]">{item.descricao}</h3>
                            </div>
                            <span className="rounded-full bg-white px-2 py-1 text-[10px] font-bold uppercase text-[var(--ink-500)] shadow-[var(--shadow-sm)]">
                                {item.unidade || '-'}
                            </span>
                        </div>
                        <div className="mt-4 grid gap-3 text-xs sm:grid-cols-2">
                            <MobileValue label="Tipo" value={item.tipo || item.item_type_label} />
                            <MobileValue label="Coeficiente" value={formatNumber(item.coeficiente, 6)} />
                            <MobileValue label="Unit. nao des." value={formatCurrency(item.preco_unitario_onerado, priceDecimals)} />
                            <MobileValue label="Unit. des." value={formatCurrency(item.preco_unitario_desonerado, priceDecimals)} />
                            <MobileValue label="Total nao des." value={formatCurrency(item.preco_onerado, priceDecimals)} />
                            <MobileValue label="Total des." value={formatCurrency(item.preco_desonerado, priceDecimals)} />
                        </div>
                        <ItemPriceWarning item={item} />
                    </article>
                ))}
            </div>
        </div>
    );
}

function ItemPriceWarning({ item }) {
    const missing = Number(item.missing_price_items_count ?? 0);

    if (missing <= 0) {
        return null;
    }

    return (
        <span className="inline-flex w-fit items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700">
            <AlertCircle size={11} />
            Sem preco vinculado
        </span>
    );
}

function TableHead({ children, className = '' }) {
    return <th className={`px-3 py-3 text-[11px] font-bold uppercase ${className}`}>{children}</th>;
}

function CompositionCodeLink({ item, tenant }) {
    const composicaoId = item.composicao_id ?? item.child_composicao_id;

    if (item.item_type !== 'composicao' || !composicaoId) {
        return <>{item.codigo}</>;
    }

    return (
        <Link
            className="underline decoration-[var(--primary)]/30 underline-offset-4 transition hover:text-[var(--primary)] hover:decoration-[var(--primary)]"
            href={route('tenant.orcamentos.composicoes.show', [tenant.slug, composicaoId])}
        >
            {item.codigo}
        </Link>
    );
}

function MobileValue({ label, value }) {
    return (
        <div>
            <span className="text-[10px] font-bold uppercase tracking-[0.06em] text-[var(--ink-400)]">{label}</span>
            <p className="mt-1 break-words font-semibold text-[var(--ink-800)]">{value || '-'}</p>
        </div>
    );
}

function ComposicaoHeader({ composicao }) {
    return (
        <>
            <header className="border-b border-[var(--border)] px-6 py-5">
                <h2 className="text-[17px] font-bold text-[var(--primary)]">{compositionTitle(composicao)}</h2>
            </header>

            <div className="grid gap-6 border-b border-[var(--border)] px-6 py-5 lg:grid-cols-2">
                <dl className="grid gap-3 text-sm sm:grid-cols-[160px_1fr]">
                    <DetailTerm>Tipo</DetailTerm>
                    <DetailValue>{composicao.tipo_composicao}</DetailValue>
                    <DetailTerm>Unidade</DetailTerm>
                    <DetailValue>{composicao.unidade}</DetailValue>
                    <DetailTerm>Estado</DetailTerm>
                    <DetailValue>{composicao.estado_label}</DetailValue>
                    <DetailTerm>Modelo</DetailTerm>
                    <DetailValue>{composicao.modelo}</DetailValue>
                </dl>

                <dl className="grid gap-3 text-sm sm:grid-cols-[160px_1fr]">
                    <DetailTerm>Metodo de Calculo</DetailTerm>
                    <DetailValue>{composicao.metodo_calculo_label}</DetailValue>
                    <DetailTerm>Observacao</DetailTerm>
                    <DetailValue>{composicao.observacao || 'Sem observacao'}</DetailValue>
                    <DetailTerm>Criado em</DetailTerm>
                    <DetailValue>{composicao.created_at}</DetailValue>
                </dl>
            </div>

            <div className="grid gap-5 border-b border-[var(--border)] px-6 py-5 lg:grid-cols-[1fr_2fr]">
                <div className="grid grid-cols-2 gap-4">
                    <PriceBlock label="Preco Onerado" value={composicao.preco_onerado} />
                    <PriceBlock label="Preco Desonerado" value={composicao.preco_desonerado} />
                </div>

                <div>
                    <h3 className="text-xs font-bold uppercase tracking-[0.06em] text-[var(--primary)]">Bases de Referencia</h3>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {(composicao.base_references ?? []).map((reference) => (
                            <span key={reference.codigo} className="rounded-md bg-[var(--primary)] px-2 py-1 text-xs font-bold text-white">
                                {reference.codigo}
                            </span>
                        ))}
                    </div>
                </div>
            </div>
        </>
    );
}

function ItemBuilder({ composicao, existingItems = [], itemType, options, tenant, title, onCancel }) {
    const referenceBaseOptions = useMemo(() => (
        Array.from(new Set((composicao.base_references ?? [])
            .map((reference) => String(reference.nome ?? '').trim().toUpperCase())
            .filter(Boolean)))
    ), [composicao.base_references]);
    const baseOptions = useMemo(() => {
        const optionBases = Array.from(new Set(options.map((option) => option.base).filter(Boolean)));

        return optionBases.length > 0 ? optionBases : referenceBaseOptions;
    }, [options, referenceBaseOptions]);
    const [base, setBase] = useState(options[0]?.base ?? referenceBaseOptions[0] ?? String(composicao.modelo ?? 'SINAPI').toUpperCase());
    const [codeSearch, setCodeSearch] = useState('');
    const [descriptionSearch, setDescriptionSearch] = useState('');
    const [remoteOptions, setRemoteOptions] = useState([]);
    const [selectedId, setSelectedId] = useState('');
    const [coefficient, setCoefficient] = useState('1');
    const [sicro3Section, setSicro3Section] = useState(SICRO3_COMPOSITION_SECTIONS[0].value);
    const [sicro3OperativeUse, setSicro3OperativeUse] = useState('1');
    const [sicro3IdleUse, setSicro3IdleUse] = useState('0');
    const [sicro3ReferencedItemId, setSicro3ReferencedItemId] = useState('');
    const [sicro3TransportType, setSicro3TransportType] = useState('fe');
    const [processing, setProcessing] = useState(false);
    const [loadingOptions, setLoadingOptions] = useState(false);
    const isSicro3 = String(composicao.modelo).toUpperCase() === 'SICRO3';
    const isSicro3CompositionBuilder = itemType === 'composicao' && isSicro3;
    const isSicro3TransportComposition = isSicro3CompositionBuilder && sicro3Section === 'momento_transporte';
    const hasSearch = Boolean(codeSearch.trim() || descriptionSearch.trim());
    const availableOptions = hasSearch ? remoteOptions : options;
    const selectedTransportType = SICRO3_TRANSPORT_TYPES.find((type) => type.value === sicro3TransportType) ?? SICRO3_TRANSPORT_TYPES[0];
    const changeBase = (value) => {
        setBase(value);
        setSelectedId('');
    };
    const changeSicro3Section = (value) => {
        setSicro3Section(value);
        setSelectedId('');
        setCoefficient('1');
        setSicro3ReferencedItemId('');
    };
    const changeCodeSearch = (value) => {
        setCodeSearch(value);
        setSelectedId('');
    };
    const changeDescriptionSearch = (value) => {
        setDescriptionSearch(value);
        setSelectedId('');
    };
    const filteredOptions = useMemo(() => {
        const code = codeSearch.trim().toLowerCase();
        const description = descriptionSearch.trim().toLowerCase();

        if (!code && !description) {
            return [];
        }

        return availableOptions
            .filter((option) => !base || option.base === base)
            .filter((option) => !code || String(option.codigo).toLowerCase().includes(code))
            .filter((option) => !description || String(option.descricao).toLowerCase().includes(description))
            .slice(0, 30);
    }, [availableOptions, base, codeSearch, descriptionSearch]);
    const selected = availableOptions.find((option) => String(option.id) === String(selectedId));
    const selectedSicro3Section = itemType === 'insumo' ? sicro3SectionFromOption(selected) : sicro3Section;
    const isSicro3Equipment = isSicro3 && itemType === 'insumo' && selectedSicro3Section === 'equipamentos';
    const needsSicro3Reference = isSicro3 && itemType === 'composicao' && ['tempo_fixo', 'momento_transporte'].includes(sicro3Section);
    const referenceOptions = useMemo(() => existingItems
        .filter((item) => ['equipamentos', 'mao_de_obra', 'material'].includes(item.sicro3_section))
        .map((item) => ({
            id: item.id,
            label: `${item.codigo} - ${item.descricao}`,
        })), [existingItems]);
    const requiresSearch = !codeSearch.trim() && !descriptionSearch.trim();
    const itemLabel = itemType === 'insumo' ? 'insumos' : 'composicoes';
    const previewOnerado = isSicro3
        ? calculateSicro3BuilderPrice(selected, coefficient, selectedSicro3Section, sicro3OperativeUse, sicro3IdleUse, 'onerado')
        : calculatePrice(selected?.preco_unitario_onerado, coefficient);
    const previewDesonerado = isSicro3
        ? calculateSicro3BuilderPrice(selected, coefficient, selectedSicro3Section, sicro3OperativeUse, sicro3IdleUse, 'desonerado')
        : calculatePrice(selected?.preco_unitario_desonerado, coefficient);

    useEffect(() => {
        const code = codeSearch.trim();
        const description = descriptionSearch.trim();

        if (!code && !description) {
            setRemoteOptions([]);
            setLoadingOptions(false);
            return undefined;
        }

        const controller = new AbortController();
        const timeout = window.setTimeout(() => {
            const params = new URLSearchParams({
                item_type: itemType,
                base: base || '',
                codigo: code,
                descricao: description,
            });

            setLoadingOptions(true);
            fetch(`${route('tenant.orcamentos.composicoes.items.options', [tenant.slug, composicao.id])}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Falha ao buscar opcoes.');
                    }

                    return response.json();
                })
                .then((payload) => setRemoteOptions(payload.options ?? []))
                .catch((error) => {
                    if (error.name !== 'AbortError') {
                        setRemoteOptions([]);
                    }
                })
                .finally(() => {
                    if (!controller.signal.aborted) {
                        setLoadingOptions(false);
                    }
                });
        }, 250);

        return () => {
            window.clearTimeout(timeout);
            controller.abort();
        };
    }, [base, codeSearch, composicao.id, descriptionSearch, itemType, tenant.slug]);

    const cancelSelection = () => {
        setSelectedId('');
        setCoefficient('1');
        setSicro3Section(SICRO3_COMPOSITION_SECTIONS[0].value);
        setSicro3OperativeUse('1');
        setSicro3IdleUse('0');
        setSicro3ReferencedItemId('');
        setSicro3TransportType('fe');
    };

    const save = () => {
        if (!selectedId || (isSicro3CompositionBuilder && !sicro3Section) || (needsSicro3Reference && !sicro3ReferencedItemId)) {
            return;
        }

        setProcessing(true);
        router.post(
            route('tenant.orcamentos.composicoes.items.store', [tenant.slug, composicao.id]),
            {
                item_type: itemType,
                source_id: selectedId,
                coeficiente: coefficient,
                sicro3_section: isSicro3CompositionBuilder ? sicro3Section : null,
                sicro3_utilizacao_operativa: isSicro3Equipment ? sicro3OperativeUse : null,
                sicro3_utilizacao_improdutiva: isSicro3Equipment ? sicro3IdleUse : null,
                sicro3_referenced_item_id: needsSicro3Reference ? sicro3ReferencedItemId : null,
                sicro3_transport_type: isSicro3TransportComposition ? sicro3TransportType : null,
            },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setSelectedId('');
                    setCodeSearch('');
                    setDescriptionSearch('');
                    setCoefficient('1');
                    setSicro3Section(SICRO3_COMPOSITION_SECTIONS[0].value);
                    setSicro3OperativeUse('1');
                    setSicro3IdleUse('0');
                    setSicro3ReferencedItemId('');
                    setSicro3TransportType('fe');
                },
            },
        );
    };

    return (
        <section className="mb-6 overflow-hidden rounded-lg border border-[var(--border)] bg-white">
            <header className="flex items-center justify-between gap-3 bg-[var(--surface-muted)] px-4 py-3">
                <div className="flex items-center gap-2">
                    <Search size={16} className="text-[var(--primary)]" />
                    <h3 className="text-sm font-bold text-[var(--ink-900)]">{title}</h3>
                </div>
                <button className="text-[var(--ink-400)] hover:text-rose-600" type="button" onClick={onCancel}>
                    <X size={18} />
                </button>
            </header>

            {isSicro3CompositionBuilder && (
                <div className="border-b border-[var(--border)] bg-white px-4 py-3">
                    <div className="grid gap-3 md:grid-cols-2">
                        <label className="grid gap-1 text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">
                            Tipo da composicao no analitico SICRO3
                            <select
                                className="sig-input mt-1 !h-10 !min-h-10 normal-case tracking-normal"
                                value={sicro3Section}
                                onChange={(event) => changeSicro3Section(event.target.value)}
                            >
                                {SICRO3_COMPOSITION_SECTIONS.map((section) => (
                                    <option key={section.value} value={section.value}>
                                        {section.code} - {section.label}
                                    </option>
                                ))}
                            </select>
                        </label>

                        {isSicro3TransportComposition ? (
                            <label className="grid gap-1 text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">
                                Tipo da composicao de transporte
                                <select
                                    className="sig-input mt-1 !h-10 !min-h-10 normal-case tracking-normal"
                                    value={sicro3TransportType}
                                    onChange={(event) => setSicro3TransportType(event.target.value)}
                                >
                                    {SICRO3_TRANSPORT_TYPES.map((type) => (
                                        <option key={type.value} value={type.value}>
                                            {type.code} - {type.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ) : null}
                    </div>
                    <p className="mt-2 text-xs text-[var(--ink-500)]">
                        {isSicro3TransportComposition
                            ? `Busque e selecione a composicao de transporte ${selectedTransportType.code}; depois vincule o item que sera transportado.`
                            : 'Essa classificacao organiza a composicao nas secoes D, E ou F do analitico SICRO3.'}
                    </p>
                </div>
            )}

            <div className="overflow-x-auto">
                <table className="min-w-[1240px] w-full border-collapse text-left text-xs">
                    <thead className="bg-slate-200 text-[var(--ink-700)]">
                        <tr>
                            <th className="w-10 px-2 py-3"><input type="checkbox" disabled /></th>
                            <th className="px-2 py-3">BASE</th>
                            <th className="px-2 py-3">CODIGO</th>
                            <th className="px-2 py-3">DESCRICAO</th>
                            <th className="px-2 py-3">TIPO</th>
                            <th className="px-2 py-3">UNID.</th>
                            <th className="px-2 py-3 text-right">PRECO UNITARIO ONERADO</th>
                            <th className="px-2 py-3 text-right">PRECO UNITARIO DESONERADO</th>
                            <th className="px-2 py-3">COEFICIENTE</th>
                            <th className="px-2 py-3 text-right">PRECO ONERADO</th>
                            <th className="px-2 py-3 text-right">PRECO DESONERADO</th>
                            <th className="px-2 py-3 text-center">ACOES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr className={`border-b border-[var(--border)] ${selected ? 'bg-indigo-50/70' : ''}`}>
                            <td className="px-2 py-2">
                                <input checked={Boolean(selected)} readOnly type="checkbox" />
                            </td>
                            <td className="px-2 py-2">
                                {selected ? (
                                    <span className="font-semibold text-[var(--ink-700)]">{selected.base_label ?? formatBaseLabel(selected.base)}</span>
                                ) : (
                                    <select className="sig-input !h-9 !min-h-9 !w-28 !px-2" value={base} onChange={(event) => changeBase(event.target.value)}>
                                        {baseOptions.map((option) => (
                                            <option key={option} value={option}>{formatBaseLabel(option)}</option>
                                        ))}
                                    </select>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                {selected ? (
                                    <span className="font-mono font-semibold text-[var(--ink-700)]">{selected.codigo}</span>
                                ) : (
                                    <input className="sig-input !h-9 !min-h-9 !w-32 !px-2" placeholder={isSicro3TransportComposition ? `Codigo ${selectedTransportType.code}` : 'Codigo'} value={codeSearch} onChange={(event) => changeCodeSearch(event.target.value)} />
                                )}
                            </td>
                            <td className="px-2 py-2">
                                {selected ? (
                                    <span className="block max-w-[460px] text-[11px] font-semibold leading-5 text-[var(--ink-700)]">
                                        {selected.descricao}
                                    </span>
                                ) : (
                                    <input className="sig-input !h-9 !min-h-9 !w-80 !px-2" placeholder={isSicro3TransportComposition ? `Descricao ${selectedTransportType.code}` : 'Descricao'} value={descriptionSearch} onChange={(event) => changeDescriptionSearch(event.target.value)} />
                                )}
                            </td>
                            <td className="px-2 py-2">{selected?.tipo ?? '-'}</td>
                            <td className="px-2 py-2">{selected?.unidade ?? '-'}</td>
                            <td className="px-2 py-2 text-right">{formatCurrency(selected?.preco_unitario_onerado)}</td>
                            <td className="px-2 py-2 text-right">{formatCurrency(selected?.preco_unitario_desonerado)}</td>
                            <td className="px-2 py-2">
                                <input className="sig-input !h-9 !min-h-9 !w-28 !px-2" value={coefficient} onChange={(event) => setCoefficient(event.target.value)} />
                            </td>
                            <td className="px-2 py-2 text-right">{formatCurrency(previewOnerado)}</td>
                            <td className="px-2 py-2 text-right">{formatCurrency(previewDesonerado)}</td>
                            <td className="px-2 py-2">
                                {selected ? (
                                    <div className="flex items-center justify-center gap-2">
                                        <button
                                            className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-600 text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                            type="button"
                                            disabled={processing || (isSicro3CompositionBuilder && !sicro3Section) || (needsSicro3Reference && !sicro3ReferencedItemId)}
                                            onClick={save}
                                            aria-label="Confirmar item"
                                            title="Confirmar"
                                        >
                                            <Check size={14} strokeWidth={3} />
                                        </button>
                                        <button
                                            className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-rose-600 text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                                            type="button"
                                            disabled={processing}
                                            onClick={cancelSelection}
                                            aria-label="Cancelar selecao"
                                            title="Cancelar"
                                        >
                                            <X size={14} strokeWidth={3} />
                                        </button>
                                    </div>
                                ) : (
                                    <span className="block text-center text-[var(--ink-300)]">-</span>
                                )}
                            </td>
                        </tr>
                        {selected && isSicro3 && (isSicro3Equipment || needsSicro3Reference || sicro3Section === 'momento_transporte') ? (
                            <tr className="border-b border-[var(--border)] bg-white">
                                <td className="px-4 py-4" colSpan={12}>
                                    <div className="grid gap-3 md:grid-cols-3">
                                        {isSicro3Equipment ? (
                                            <>
                                                <label className="grid gap-1 text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">
                                                    Utilizacao operativa
                                                    <input
                                                        className="sig-input mt-1 !h-10 !min-h-10 normal-case tracking-normal"
                                                        value={sicro3OperativeUse}
                                                        onChange={(event) => setSicro3OperativeUse(event.target.value)}
                                                    />
                                                </label>
                                                <label className="grid gap-1 text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">
                                                    Utilizacao improdutiva
                                                    <input
                                                        className="sig-input mt-1 !h-10 !min-h-10 normal-case tracking-normal"
                                                        value={sicro3IdleUse}
                                                        onChange={(event) => setSicro3IdleUse(event.target.value)}
                                                    />
                                                </label>
                                                <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-3 py-2 text-xs text-[var(--ink-600)]">
                                                    <p className="font-bold uppercase tracking-[0.06em]">Custos improdutivos</p>
                                                    <p className="mt-1">Nao des.: {formatCurrency(selected.custo_improdutivo_onerado ?? selected.preco_unitario_onerado, 4)}</p>
                                                    <p>Des.: {formatCurrency(selected.custo_improdutivo_desonerado ?? selected.preco_unitario_desonerado, 4)}</p>
                                                </div>
                                            </>
                                        ) : null}

                                        {needsSicro3Reference ? (
                                            <label className="grid gap-1 text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)] md:col-span-3">
                                                Selecione o item referenciado
                                                <select
                                                    className="sig-input mt-1 !h-10 !min-h-10 normal-case tracking-normal"
                                                    value={sicro3ReferencedItemId}
                                                    onChange={(event) => setSicro3ReferencedItemId(event.target.value)}
                                                >
                                                    <option value="">Selecione um item ja adicionado</option>
                                                    {referenceOptions.map((option) => (
                                                        <option key={option.id} value={option.id}>{option.label}</option>
                                                    ))}
                                                </select>
                                                {referenceOptions.length === 0 && (
                                                    <span className="normal-case tracking-normal text-amber-600">
                                                        Adicione primeiro ao menos um equipamento, mao de obra ou material para referenciar.
                                                    </span>
                                                )}
                                            </label>
                                        ) : null}

                                        {isSicro3TransportComposition ? (
                                            <div className="rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-xs text-blue-800 md:col-span-3">
                                                <p className="font-bold uppercase tracking-[0.06em]">Composicao de transporte vinculada</p>
                                                <p className="mt-1 font-semibold">
                                                    {selectedTransportType.code} {selected.codigo} - {selected.descricao}
                                                </p>
                                                <p className="mt-1 text-blue-700">
                                                    Esse codigo sera gravado como transporte {selectedTransportType.code}. O item referenciado acima e o item transportado.
                                                </p>
                                            </div>
                                        ) : null}
                                    </div>
                                </td>
                            </tr>
                        ) : null}
                    </tbody>
                </table>
            </div>

            <div className="border-t border-[var(--border)] p-4">
                <div className="max-h-44 overflow-y-auto rounded-lg border border-[var(--border)]">
                    {requiresSearch ? (
                        <div className="p-4 text-sm text-[var(--ink-500)]">
                            Pesquise pelo codigo ou pela descricao para listar {itemLabel} da base selecionada.
                        </div>
                    ) : loadingOptions ? (
                        <div className="p-4 text-sm text-[var(--ink-500)]">Buscando registros da base selecionada...</div>
                    ) : filteredOptions.length === 0 ? (
                        <div className="p-4 text-sm text-[var(--ink-500)]">
                            Nenhum registro encontrado nas bases de referencia selecionadas. Verifique se a data da base desta composicao possui {itemLabel} importados.
                        </div>
                    ) : filteredOptions.map((option) => (
                        <button
                            key={option.id}
                            className={`grid w-full gap-2 border-b border-[var(--border)] px-3 py-2 text-left text-xs transition last:border-b-0 md:grid-cols-[120px_1fr_110px_110px] ${
                                String(option.id) === String(selectedId) ? 'bg-[var(--primary-50)] text-[var(--primary)]' : 'hover:bg-[var(--surface-muted)]'
                            }`}
                            type="button"
                            onClick={() => setSelectedId(option.id)}
                        >
                            <span className="font-mono font-bold">{option.codigo}</span>
                            <span className="font-semibold">{option.descricao}</span>
                            <span>{option.unidade}</span>
                            <span>{option.data ?? option.base_label ?? formatBaseLabel(option.base)}</span>
                        </button>
                    ))}
                </div>
            </div>
        </section>
    );
}

function CompositionItemsTable({ composicao, items, tenant }) {
    const [editingId, setEditingId] = useState(null);
    const [editingCoefficient, setEditingCoefficient] = useState('1');
    const [processingId, setProcessingId] = useState(null);
    const isSicro3 = String(composicao.modelo).toUpperCase() === 'SICRO3';

    if (items.length === 0) {
        return (
            <div className="rounded-lg border border-dashed border-[var(--border)] p-8 text-center text-sm text-[var(--ink-500)]">
                Nenhum item adicionado nesta composicao.
            </div>
        );
    }

    const startEdit = (item) => {
        setEditingId(item.id);
        setEditingCoefficient(formatNumber(item.coeficiente, 6));
    };

    const cancelEdit = () => {
        setEditingId(null);
        setEditingCoefficient('1');
    };

    const saveEdit = (item) => {
        setProcessingId(item.id);
        router.patch(
            route('tenant.orcamentos.composicoes.items.update', [tenant.slug, composicao.id, item.id]),
            { coeficiente: editingCoefficient },
            {
                preserveScroll: true,
                onFinish: () => setProcessingId(null),
                onSuccess: cancelEdit,
            },
        );
    };

    if (isSicro3) {
        return (
            <Sicro3OwnCompositionItemsTable
                composicao={composicao}
                editingCoefficient={editingCoefficient}
                editingId={editingId}
                items={items}
                onCancelEdit={cancelEdit}
                onEditCoefficientChange={setEditingCoefficient}
                onSaveEdit={saveEdit}
                onStartEdit={startEdit}
                processingId={processingId}
                tenant={tenant}
            />
        );
    }

    return (
        <section className="overflow-hidden rounded-lg border border-[var(--border)]">
            <table className="w-full min-w-[1080px] border-collapse text-left text-xs">
                <thead className="bg-[var(--ink-900)] text-white">
                    <tr>
                        <th className="px-3 py-3">BASE</th>
                        <th className="px-3 py-3">CODIGO</th>
                        <th className="px-3 py-3">DESCRICAO</th>
                        <th className="px-3 py-3">TIPO</th>
                        <th className="px-3 py-3">UNID.</th>
                        <th className="px-3 py-3 text-right">COEF.</th>
                        <th className="px-3 py-3 text-right">PRECO ONERADO</th>
                        <th className="px-3 py-3 text-right">PRECO DESONERADO</th>
                        <th className="px-3 py-3"></th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-[var(--border)] bg-white">
                    {items.map((item) => {
                        const isEditing = editingId === item.id;
                        const processing = processingId === item.id;
                        const previewOnerado = isEditing
                            ? calculateSicro3BuilderPrice(item, editingCoefficient, item.sicro3_section, item.sicro3_utilizacao_operativa, item.sicro3_utilizacao_improdutiva, 'onerado')
                            : item.preco_onerado;
                        const previewDesonerado = isEditing
                            ? calculateSicro3BuilderPrice(item, editingCoefficient, item.sicro3_section, item.sicro3_utilizacao_operativa, item.sicro3_utilizacao_improdutiva, 'desonerado')
                            : item.preco_desonerado;

                        return (
                            <tr key={item.id} className={`hover:bg-[var(--primary-50)]/50 ${isEditing ? 'bg-indigo-50/60' : ''}`}>
                                <td className="px-3 py-3">{formatBaseLabel(item.base)}</td>
                                <td className="px-3 py-3 font-mono font-bold text-[var(--primary)]">
                                    <CompositionCodeLink item={item} tenant={tenant} />
                                </td>
                                <td className="px-3 py-3 font-semibold text-[var(--ink-900)]">{item.descricao}</td>
                                <td className="px-3 py-3">{item.tipo}</td>
                                <td className="px-3 py-3">{item.unidade}</td>
                                <td className="px-3 py-3 text-right">
                                    {isEditing ? (
                                        <input
                                            className="sig-input !h-8 !min-h-8 !w-24 !px-2 text-right text-xs"
                                            value={editingCoefficient}
                                            onChange={(event) => setEditingCoefficient(event.target.value)}
                                        />
                                    ) : (
                                        formatNumber(item.coeficiente, 6)
                                    )}
                                </td>
                                <td className="px-3 py-3 text-right">{formatCurrency(previewOnerado)}</td>
                                <td className="px-3 py-3 text-right">{formatCurrency(previewDesonerado)}</td>
                                <td className="px-3 py-3 text-right">
                                    {isEditing ? (
                                        <div className="flex items-center justify-end gap-1">
                                            <button
                                                className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-emerald-600 text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                                type="button"
                                                disabled={processing}
                                                onClick={() => saveEdit(item)}
                                                title="Salvar coeficiente"
                                            >
                                                <Check size={14} strokeWidth={3} />
                                            </button>
                                            <button
                                                className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-rose-600 text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                                                type="button"
                                                disabled={processing}
                                                onClick={cancelEdit}
                                                title="Cancelar edicao"
                                            >
                                                <X size={14} strokeWidth={3} />
                                            </button>
                                        </div>
                                    ) : (
                                        <div className="flex items-center justify-end gap-1">
                                            <button
                                                className="inline-flex h-8 w-8 items-center justify-center rounded-full text-[var(--primary)] transition hover:bg-[var(--primary-50)]"
                                                type="button"
                                                onClick={() => startEdit(item)}
                                                title="Editar coeficiente"
                                            >
                                                <Pencil size={14} />
                                            </button>
                                            <button
                                                className="inline-flex h-8 w-8 items-center justify-center rounded-full text-rose-600 transition hover:bg-rose-50"
                                                type="button"
                                                onClick={() => router.delete(route('tenant.orcamentos.composicoes.items.destroy', [tenant.slug, composicao.id, item.id]), { preserveScroll: true })}
                                                title="Remover item"
                                            >
                                                <X size={15} />
                                            </button>
                                        </div>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </section>
    );
}

const SICRO3_ANALYTIC_SECTIONS = [
    { key: 'equipamentos', code: 'A', label: 'EQUIPAMENTOS', columns: 'equipment' },
    { key: 'mao_de_obra', code: 'B', label: 'MAO-DE-OBRA', columns: 'labor' },
    { key: 'material', code: 'C', label: 'MATERIAL', columns: 'material' },
    { key: 'atividades_auxiliares', code: 'D', label: 'ATIVIDADES AUXILIARES', columns: 'activity' },
    { key: 'tempo_fixo', code: 'E', label: 'TEMPO FIXO', columns: 'fixed' },
    { key: 'momento_transporte', code: 'F', label: 'MOMENTO DE TRANSPORTE', columns: 'transport' },
];

function Sicro3OwnCompositionItemsTable({
    composicao,
    editingCoefficient,
    editingId,
    items,
    onCancelEdit = () => {},
    onEditCoefficientChange = () => {},
    onSaveEdit = () => {},
    onStartEdit = () => {},
    processingId,
    readOnly = false,
    tenant,
}) {
    const sicro3Summary = composicao.sicro3_summary ?? {};
    const totalOnerado = Number(sicro3Summary.preco_onerado ?? composicao.preco_onerado ?? items.reduce((sum, item) => sum + Number(item.preco_onerado ?? 0), 0));
    const totalDesonerado = Number(sicro3Summary.preco_desonerado ?? composicao.preco_desonerado ?? items.reduce((sum, item) => sum + Number(item.preco_desonerado ?? 0), 0));

    return (
        <section className="overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-[var(--shadow-sm)]">
            <div className="border-b border-[var(--border)]">
                <div className="bg-[var(--primary)] px-3 py-2 text-xs font-bold text-white">
                    {compositionTitle(composicao)}
                </div>
                <div className="overflow-x-auto">
                    <div className="min-w-[960px]">
                        <div className="grid grid-cols-4 bg-[#2f2f2f] text-[10px] font-bold uppercase text-white">
                            <div className="px-2 py-2">Data</div>
                            <div className="px-2 py-2 text-center">Unidade</div>
                            <div className="px-2 py-2 text-center">Producao da equipe</div>
                            <div className="px-2 py-2 text-right">Fator de influencia da chuva - FIC</div>
                        </div>
                        <div className="grid grid-cols-4 border-b border-[var(--border)] bg-white text-[10px] font-semibold text-[var(--ink-900)]">
                            <div className="px-2 py-2">{firstReferenceLabel(composicao)}</div>
                            <div className="px-2 py-2 text-center">{composicao.unidade || '-'}</div>
                            <div className="px-2 py-2 text-center">{formatOptionalNumber(composicao.producao_equipe, 4)}</div>
                            <div className="px-2 py-2 text-right">{formatOptionalNumber(composicao.fator_influencia_chuvas, 4)}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="overflow-x-auto">
                <div className="min-w-[1280px]">
                    <div className="flex items-center justify-between border-b border-[var(--border)] bg-[var(--surface-muted)] px-3 py-3 text-xs font-bold text-[var(--ink-900)]">
                        <span>{composicao.estado_label ?? composicao.uf} - Nao Desonerado</span>
                        <span>{formatCurrency(totalOnerado)}</span>
                    </div>

                    {SICRO3_ANALYTIC_SECTIONS.map((section) => (
                        <Sicro3AnalyticSection
                            key={section.key}
                            composicao={composicao}
                            editingCoefficient={editingCoefficient}
                            editingId={editingId}
                            items={items.filter((item) => item.sicro3_section === section.key)}
                            onCancelEdit={onCancelEdit}
                            onEditCoefficientChange={onEditCoefficientChange}
                            onSaveEdit={onSaveEdit}
                            onStartEdit={onStartEdit}
                            processingId={processingId}
                            readOnly={readOnly}
                            section={section}
                            summary={sicro3Summary}
                            tenant={tenant}
                        />
                    ))}

                    <div className="flex items-center justify-between border-t border-[var(--border)] bg-[var(--surface-muted)] px-3 py-4 text-xs font-bold text-[var(--ink-900)]">
                        <span>{composicao.estado_label ?? composicao.uf} - Desonerado</span>
                        <span>{formatCurrency(totalDesonerado)}</span>
                    </div>
                </div>
            </div>
        </section>
    );
}

function Sicro3HeaderCell({ label, value }) {
    return (
        <div className="bg-white px-3 py-2">
            <p className="text-[10px] font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">{label}</p>
            <p className="mt-2 font-semibold text-[var(--ink-900)]">{value || '-'}</p>
        </div>
    );
}

function Sicro3AnalyticSection({
    composicao,
    editingCoefficient,
    editingId,
    items,
    onCancelEdit,
    onEditCoefficientChange,
    onSaveEdit,
    onStartEdit,
    processingId,
    readOnly,
    section,
    summary,
    tenant,
}) {
    const sectionSummary = summary?.sections?.[section.key] ?? {};
    const isTransportSection = section.key === 'momento_transporte';
    const subtotalOnerado = isTransportSection
        ? null
        : Number(sectionSummary.onerado ?? items.reduce((sum, item) => sum + sicro3DisplayTotal(item, 'onerado'), 0));
    const columnCount = sicro3ColumnCount(section, readOnly);

    return (
        <div className="border-b border-[var(--border)]">
            <div className="grid grid-cols-[42px_1fr] bg-[#2f2f2f] text-[10px] font-bold uppercase text-white">
                <div className="px-2 py-2">{section.code}</div>
                <div className="px-2 py-2">{section.label}</div>
            </div>
            <table className="w-full table-fixed border-collapse text-left text-[10px]">
                <Sicro3SectionHead readOnly={readOnly} section={section} />
                <tbody className="divide-y divide-[var(--border)] bg-white">
                    {items.length === 0 ? (
                        <tr>
                            <td className="px-2 py-3 text-center text-[var(--ink-400)]" colSpan={columnCount}>
                                Nenhum item nesta categoria.
                            </td>
                        </tr>
                    ) : items.map((item) => {
                        const isEditing = editingId === item.id;
                        const processing = processingId === item.id;
                        const previewOnerado = isEditing
                            ? calculateSicro3BuilderPrice(item, editingCoefficient, item.sicro3_section, item.sicro3_utilizacao_operativa, item.sicro3_utilizacao_improdutiva, 'onerado')
                            : sicro3DisplayTotal(item, 'onerado');

                        return (
                            <Sicro3SectionRow
                                key={item.id}
                                composicao={composicao}
                                editingCoefficient={editingCoefficient}
                                isEditing={isEditing}
                                item={item}
                                onCancelEdit={onCancelEdit}
                                onEditCoefficientChange={onEditCoefficientChange}
                                onSaveEdit={onSaveEdit}
                                onStartEdit={onStartEdit}
                                previewOnerado={previewOnerado}
                                processing={processing}
                                readOnly={readOnly}
                                section={section}
                                tenant={tenant}
                            />
                        );
                    })}
                    <tr className="bg-white font-bold text-[var(--ink-700)]">
                        <td className="px-2 py-2 text-right" colSpan={columnCount - 1}>
                            {isTransportSection ? 'Custo unitario total de transporte' : `Total ${section.label.toLowerCase()}`}
                        </td>
                        <td className="px-2 py-2 text-right">{isTransportSection ? '-' : formatCurrency(subtotalOnerado, 4)}</td>
                    </tr>
                    {section.key === 'mao_de_obra' ? (
                        <>
                            <Sicro3SummaryRow colSpan={columnCount - 1} label="Custo horario total de execucao" value={summary?.custo_horario_execucao_onerado} />
                            <Sicro3SummaryRow colSpan={columnCount - 1} label="Custo unitario de execucao" value={summary?.custo_unitario_execucao_onerado} />
                            <Sicro3SummaryRow colSpan={columnCount - 1} label="Custo do Fator de Influencia da Chuva - FIC" value={summary?.custo_fic_onerado} />
                        </>
                    ) : null}
                </tbody>
            </table>
        </div>
    );
}

function Sicro3SummaryRow({ colSpan, label, value }) {
    return (
        <tr className="bg-white text-[var(--ink-700)]">
            <td className="px-2 py-2 text-right font-semibold" colSpan={colSpan}>
                {label}
            </td>
            <td className="px-2 py-2 text-right font-bold">{formatCurrency(value ?? 0, 4)}</td>
        </tr>
    );
}

function Sicro3SectionHead({ readOnly = false, section }) {
    if (section.columns === 'equipment') {
        return (
            <thead className="bg-[#2f2f2f] text-white">
                <tr>
                    <th className="w-[7%] px-2 py-2">CODIGO</th>
                    <th className="w-[33%] px-2 py-2">DESCRICAO</th>
                    <th className="w-[8%] px-2 py-2 text-right">QUANTIDADE</th>
                    <th className="w-[8%] px-2 py-2 text-right">UTIL. OPERATIVA</th>
                    <th className="w-[8%] px-2 py-2 text-right">UTIL. IMPRODUTIVA</th>
                    <th className="w-[10%] px-2 py-2 text-right">CUSTO OPERATIVO</th>
                    <th className="w-[10%] px-2 py-2 text-right">CUSTO IMPRODUTIVO</th>
                    <th className="w-[12%] px-2 py-2 text-right">CUSTO HORARIO</th>
                    {!readOnly && <th className="w-[8%] px-2 py-2 text-right">ACOES</th>}
                </tr>
            </thead>
        );
    }

    if (section.columns === 'labor') {
        return (
            <thead className="bg-[#2f2f2f] text-white">
                <tr>
                    <th className="w-[7%] px-2 py-2">CODIGO</th>
                    <th className="w-[43%] px-2 py-2">DESCRICAO</th>
                    <th className="w-[10%] px-2 py-2 text-right">QUANTIDADE</th>
                    <th className="w-[8%] px-2 py-2">UNIDADE</th>
                    <th className="w-[16%] px-2 py-2 text-right">CUSTO HORARIO</th>
                    <th className="w-[16%] px-2 py-2 text-right">CUSTO HORARIO TOTAL</th>
                    {!readOnly && <th className="w-[8%] px-2 py-2 text-right">ACOES</th>}
                </tr>
            </thead>
        );
    }

    if (section.columns === 'fixed') {
        return (
            <thead className="bg-[#2f2f2f] text-white">
                <tr>
                    <th className="w-[7%] px-2 py-2">CODIGO</th>
                    <th className="w-[43%] px-2 py-2">DESCRICAO</th>
                    <th className="w-[9%] px-2 py-2">CODIGO</th>
                    <th className="w-[9%] px-2 py-2 text-right">QUANTIDADE</th>
                    <th className="w-[8%] px-2 py-2">UNIDADE</th>
                    <th className="w-[12%] px-2 py-2 text-right">PRECO UNITARIO</th>
                    <th className="w-[12%] px-2 py-2 text-right">CUSTO UNITARIO</th>
                    {!readOnly && <th className="w-[8%] px-2 py-2 text-right">ACOES</th>}
                </tr>
            </thead>
        );
    }

    if (section.columns === 'transport') {
        return (
            <thead className="bg-[#2f2f2f] text-white">
                <tr>
                    <th className="w-[7%] px-2 py-2">CODIGO</th>
                    <th className="w-[38%] px-2 py-2">DESCRICAO</th>
                    <th className="w-[9%] px-2 py-2 text-right">QUANTIDADE</th>
                    <th className="w-[7%] px-2 py-2">UNIDADE</th>
                    <th className="w-[9%] px-2 py-2 text-right">LN</th>
                    <th className="w-[9%] px-2 py-2 text-right">RP</th>
                    <th className="w-[9%] px-2 py-2 text-right">P</th>
                    <th className="w-[9%] px-2 py-2 text-right">FE</th>
                    <th className="w-[12%] px-2 py-2 text-right">CUSTO UNITARIO</th>
                    {!readOnly && <th className="w-[8%] px-2 py-2 text-right">ACOES</th>}
                </tr>
            </thead>
        );
    }

    return (
        <thead className="bg-[#2f2f2f] text-white">
            <tr>
                <th className="w-[7%] px-2 py-2">CODIGO</th>
                <th className="w-[43%] px-2 py-2">DESCRICAO</th>
                <th className="w-[9%] px-2 py-2 text-right">QUANTIDADE</th>
                <th className="w-[8%] px-2 py-2">UNIDADE</th>
                <th className="w-[16%] px-2 py-2 text-right">PRECO UNITARIO</th>
                <th className="w-[16%] px-2 py-2 text-right">{section.columns === 'activity' ? 'CUSTO HORARIO' : 'CUSTO UNITARIO'}</th>
                {!readOnly && <th className="w-[8%] px-2 py-2 text-right">ACOES</th>}
            </tr>
        </thead>
    );
}

function Sicro3SectionRow({
    editingCoefficient,
    isEditing,
    item,
    onCancelEdit,
    onEditCoefficientChange,
    onSaveEdit,
    onStartEdit,
    previewDesonerado,
    previewOnerado,
    processing,
    readOnly = false,
    section,
    tenant,
    composicao,
}) {
    const quantityCell = isEditing ? (
        <input
            className="sig-input !h-8 !min-h-8 !w-24 !px-2 text-right text-xs"
            value={editingCoefficient}
            onChange={(event) => onEditCoefficientChange(event.target.value)}
        />
    ) : (
        formatNumber(item.coeficiente, 5)
    );
    const rowClass = isEditing ? 'bg-indigo-50/60' : 'hover:bg-[var(--primary-50)]/40';
    const actionsCell = !readOnly && (
        <td className="px-2 py-1.5 text-right">
            <Sicro3RowActions
                composicao={composicao}
                isEditing={isEditing}
                item={item}
                onCancelEdit={onCancelEdit}
                onSaveEdit={onSaveEdit}
                onStartEdit={onStartEdit}
                processing={processing}
                tenant={tenant}
            />
        </td>
    );
    const unitOnerado = sicro3DisplayUnit(item, 'onerado');
    const totalOnerado = previewOnerado ?? sicro3DisplayTotal(item, 'onerado');

    if (section.columns === 'equipment') {
        const operationalUse = Number(item.sicro3_utilizacao_operativa ?? 1);
        const idleUse = Number(item.sicro3_utilizacao_improdutiva ?? 0);
        const idleCost = sicro3DisplayIdleCost(item);

        return (
            <tr className={rowClass}>
                <td className="px-2 py-1.5 font-mono font-bold text-[var(--primary)]"><CompositionCodeLink item={item} tenant={tenant} /></td>
                <td className="px-2 py-1.5 font-medium text-[var(--ink-800)]">{item.descricao}</td>
                <td className="px-2 py-1.5 text-right">{quantityCell}</td>
                <td className="px-2 py-1.5 text-right">{formatNumber(operationalUse, 2)}</td>
                <td className="px-2 py-1.5 text-right">{formatNumber(idleUse, 2)}</td>
                <td className="px-2 py-1.5 text-right">{formatCurrency(unitOnerado, 4)}</td>
                <td className="px-2 py-1.5 text-right">{formatCurrency(idleCost, 4)}</td>
                <td className="px-2 py-1.5 text-right font-bold">{formatCurrency(totalOnerado, 4)}</td>
                {actionsCell}
            </tr>
        );
    }

    if (section.columns === 'labor') {
        return (
            <tr className={rowClass}>
                <td className="px-2 py-1.5 font-mono font-bold text-[var(--primary)]"><CompositionCodeLink item={item} tenant={tenant} /></td>
                <td className="px-2 py-1.5 font-medium text-[var(--ink-800)]">{item.descricao}</td>
                <td className="px-2 py-1.5 text-right">{quantityCell}</td>
                <td className="px-2 py-1.5">{item.unidade}</td>
                <td className="px-2 py-1.5 text-right">{formatCurrency(unitOnerado, 4)}</td>
                <td className="px-2 py-1.5 text-right font-bold">{formatCurrency(totalOnerado, 4)}</td>
                {actionsCell}
            </tr>
        );
    }

    if (section.columns === 'fixed') {
        return (
            <tr className={rowClass}>
                <td className="px-2 py-1.5 font-mono font-bold text-[var(--primary)]">{item.sicro3_referenced_item_code || item.codigo}</td>
                <td className="px-2 py-1.5 font-medium text-[var(--ink-800)]">{sicro3ReferenceDescription(item)}</td>
                <td className="px-2 py-1.5 font-mono font-bold text-[var(--primary)]"><CompositionCodeLink item={item} tenant={tenant} /></td>
                <td className="px-2 py-1.5 text-right">{quantityCell}</td>
                <td className="px-2 py-1.5">{item.unidade}</td>
                <td className="px-2 py-1.5 text-right">{formatCurrency(unitOnerado, 4)}</td>
                <td className="px-2 py-1.5 text-right font-bold">{formatCurrency(totalOnerado, 4)}</td>
                {actionsCell}
            </tr>
        );
    }

    if (section.columns === 'transport') {
        return (
            <tr className={rowClass}>
                <td className="px-2 py-1.5 font-mono font-bold text-[var(--primary)]">{item.sicro3_referenced_item_code || item.codigo}</td>
                <td className="px-2 py-1.5 font-medium text-[var(--ink-800)]">{sicro3ReferenceDescription(item)}</td>
                <td className="px-2 py-1.5 text-right">{quantityCell}</td>
                <td className="px-2 py-1.5">{item.unidade}</td>
                <td className="px-2 py-1.5 text-right">{item.sicro3_transport_ln_code || '-'}</td>
                <td className="px-2 py-1.5 text-right">{item.sicro3_transport_rp_code || '-'}</td>
                <td className="px-2 py-1.5 text-right">{item.sicro3_transport_p_code || '-'}</td>
                <td className="px-2 py-1.5 text-right">{item.sicro3_transport_fe_code || '-'}</td>
                <td className="px-2 py-1.5 text-right font-bold">-</td>
                {actionsCell}
            </tr>
        );
    }

    return (
        <tr className={rowClass}>
            <td className="px-2 py-1.5 font-mono font-bold text-[var(--primary)]"><CompositionCodeLink item={item} tenant={tenant} /></td>
            <td className="px-2 py-1.5 font-medium text-[var(--ink-800)]">{item.descricao}</td>
            <td className="px-2 py-1.5 text-right">{quantityCell}</td>
            <td className="px-2 py-1.5">{item.unidade}</td>
            <td className="px-2 py-1.5 text-right">{formatCurrency(unitOnerado, 4)}</td>
            <td className="px-2 py-1.5 text-right font-bold">{formatCurrency(totalOnerado, 4)}</td>
            {actionsCell}
        </tr>
    );
}

function Sicro3ReferenceMeta({ item }) {
    const transportCodes = [
        item.sicro3_transport_ln_code ? `LN ${item.sicro3_transport_ln_code}` : null,
        item.sicro3_transport_rp_code ? `RP ${item.sicro3_transport_rp_code}` : null,
        item.sicro3_transport_p_code ? `P ${item.sicro3_transport_p_code}` : null,
        item.sicro3_transport_fe_code ? `FE ${item.sicro3_transport_fe_code}` : null,
    ].filter(Boolean);

    if (!item.sicro3_referenced_item_code && transportCodes.length === 0) {
        return null;
    }

    return (
        <div className="mt-1 space-y-1 text-[11px] font-medium text-[var(--ink-500)]">
            {item.sicro3_referenced_item_code && (
                <p>Item referenciado: {item.sicro3_referenced_item_code} - {item.sicro3_referenced_item_description}</p>
            )}
            {transportCodes.length > 0 && (
                <p>Transporte: {transportCodes.join(' · ')}</p>
            )}
        </div>
    );
}

function Sicro3RowActions({ composicao, isEditing, item, onCancelEdit, onSaveEdit, onStartEdit, processing, tenant }) {
    if (isEditing) {
        return (
            <div className="flex items-center justify-end gap-1">
                <button
                    className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-emerald-600 text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    disabled={processing}
                    onClick={() => onSaveEdit(item)}
                    title="Salvar coeficiente"
                >
                    <Check size={14} strokeWidth={3} />
                </button>
                <button
                    className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-rose-600 text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    disabled={processing}
                    onClick={onCancelEdit}
                    title="Cancelar edicao"
                >
                    <X size={14} strokeWidth={3} />
                </button>
            </div>
        );
    }

    return (
        <div className="flex items-center justify-end gap-1">
            <button
                className="inline-flex h-8 w-8 items-center justify-center rounded-full text-[var(--primary)] transition hover:bg-[var(--primary-50)]"
                type="button"
                onClick={() => onStartEdit(item)}
                title="Editar coeficiente"
            >
                <Pencil size={14} />
            </button>
            <button
                className="inline-flex h-8 w-8 items-center justify-center rounded-full text-rose-600 transition hover:bg-rose-50"
                type="button"
                onClick={() => router.delete(route('tenant.orcamentos.composicoes.items.destroy', [tenant.slug, composicao.id, item.id]), { preserveScroll: true })}
                title="Remover item"
            >
                <X size={15} />
            </button>
        </div>
    );
}

function Sicro3SectionBadge({ item }) {
    if (!item.sicro3_section_code || !item.sicro3_section_label) {
        return <span className="text-[var(--ink-300)]">-</span>;
    }

    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-1 text-[11px] font-bold text-[var(--ink-700)]">
            <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-[var(--ink-900)] text-[10px] text-white">
                {item.sicro3_section_code}
            </span>
            {item.sicro3_section_label}
        </span>
    );
}

function CreateInsumoModal({ composicao, options, tenant, onClose }) {
    const firstReference = composicao.base_references?.[0] ?? {};
    const form = useForm({
        codigo_insumo: '',
        descricao: '',
        unidade: '',
        tipo: 'equipment',
        grupo_id: '',
        uf: firstReference.uf ?? composicao.uf ?? 'PA',
        preco_nao_desonerado: '',
        preco_desonerado: '',
        custo_improdutivo_nao_desonerado: '',
        custo_improdutivo_desonerado: '',
        data: toMonthInputValue(firstReference.data) ?? '2026-04',
        coeficiente: '1',
        observacao: '',
    });
    const isEquipment = form.data.tipo === 'equipment';
    const updateTipo = (value) => {
        form.setData({
            ...form.data,
            tipo: value,
            custo_improdutivo_nao_desonerado: value === 'equipment' ? form.data.custo_improdutivo_nao_desonerado : '',
            custo_improdutivo_desonerado: value === 'equipment' ? form.data.custo_improdutivo_desonerado : '',
        });
    };

    const submit = (event) => {
        event.preventDefault();

        form.post(route('tenant.orcamentos.composicoes.insumos.store', [tenant.slug, composicao.id]), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/45 px-4 py-8">
            <form className="w-full max-w-xl rounded-lg border-t-4 border-[var(--primary)] bg-white shadow-2xl" onSubmit={submit}>
                <header className="flex items-center justify-between border-b border-[var(--border)] px-5 py-4">
                    <h2 className="text-sm font-bold uppercase text-[var(--ink-900)]">Criar insumo</h2>
                    <button className="text-[var(--ink-400)] hover:text-rose-600" type="button" onClick={onClose}>
                        <X size={18} />
                    </button>
                </header>

                <div className="p-5">
                    <h3 className="mb-5 text-2xl font-bold leading-tight text-[var(--ink-500)]">
                        Formulario para criacao de um novo insumo para esta composicao
                    </h3>
                    <div className="mb-4 inline-flex rounded-md bg-[var(--primary-50)] px-3 py-1 text-xs font-bold uppercase tracking-[0.06em] text-[var(--primary)]">
                        Base propria
                    </div>

                    <ModalField label="Codigo" error={form.errors.codigo_insumo}>
                        <input className="sig-input" value={form.data.codigo_insumo} onChange={(event) => form.setData('codigo_insumo', event.target.value)} />
                    </ModalField>

                    <ModalField label="Descricao" error={form.errors.descricao}>
                        <input className="sig-input" value={form.data.descricao} onChange={(event) => form.setData('descricao', event.target.value)} />
                    </ModalField>

                    <ModalField label="Unidade" error={form.errors.unidade}>
                        <input className="sig-input" value={form.data.unidade} onChange={(event) => form.setData('unidade', event.target.value)} />
                    </ModalField>

                    <ModalField label="Tipo" error={form.errors.tipo}>
                        <select className="sig-input" value={form.data.tipo} onChange={(event) => updateTipo(event.target.value)}>
                            {(options.types ?? []).map((type) => (
                                <option key={type.value} value={type.value}>{type.label}</option>
                            ))}
                        </select>
                    </ModalField>

                    <ModalField label="Grupo" error={form.errors.grupo_id}>
                        <select className="sig-input" value={form.data.grupo_id} onChange={(event) => form.setData('grupo_id', event.target.value)}>
                            <option value="">Sem grupo</option>
                            {(options.grupos ?? []).map((grupo) => (
                                <option key={grupo.value} value={grupo.value}>{grupo.label}</option>
                            ))}
                        </select>
                    </ModalField>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <ModalField label="Estado" error={form.errors.uf}>
                            <input className="sig-input" maxLength="2" value={form.data.uf} onChange={(event) => form.setData('uf', event.target.value.toUpperCase())} />
                        </ModalField>
                        <ModalField label="Data" error={form.errors.data}>
                            <input className="sig-input" type="month" value={form.data.data} onChange={(event) => form.setData('data', event.target.value)} />
                        </ModalField>
                    </div>

                    <ModalField label="Valor nao desonerado" error={form.errors.preco_nao_desonerado}>
                        <input
                            className="sig-input"
                            inputMode="numeric"
                            placeholder="100.000,00"
                            value={form.data.preco_nao_desonerado}
                            onChange={(event) => setMoneyField(form, 'preco_nao_desonerado', event.target.value)}
                        />
                    </ModalField>
                    <ModalField label="Valor desonerado (opcional)" error={form.errors.preco_desonerado}>
                        <input
                            className="sig-input"
                            inputMode="numeric"
                            placeholder="Opcional"
                            value={form.data.preco_desonerado}
                            onChange={(event) => setMoneyField(form, 'preco_desonerado', event.target.value)}
                        />
                    </ModalField>
                    {isEquipment && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ModalField label="Valor nao Desonerado Improdutivo" error={form.errors.custo_improdutivo_nao_desonerado}>
                                <input
                                    className="sig-input"
                                    inputMode="numeric"
                                    placeholder="Opcional"
                                    value={form.data.custo_improdutivo_nao_desonerado}
                                    onChange={(event) => setMoneyField(form, 'custo_improdutivo_nao_desonerado', event.target.value)}
                                />
                            </ModalField>
                            <ModalField label="Valor Desonerado Improdutivo" error={form.errors.custo_improdutivo_desonerado}>
                                <input
                                    className="sig-input"
                                    inputMode="numeric"
                                    placeholder="Opcional"
                                    value={form.data.custo_improdutivo_desonerado}
                                    onChange={(event) => setMoneyField(form, 'custo_improdutivo_desonerado', event.target.value)}
                                />
                            </ModalField>
                        </div>
                    )}
                    <ModalField label="Coeficiente deste insumo para esta composicao" error={form.errors.coeficiente}>
                        <input className="sig-input" value={form.data.coeficiente} onChange={(event) => form.setData('coeficiente', event.target.value)} />
                    </ModalField>
                    <ModalField label="Observacao" error={form.errors.observacao}>
                        <input className="sig-input" placeholder="Opcional" value={form.data.observacao} onChange={(event) => form.setData('observacao', event.target.value)} />
                    </ModalField>
                </div>

                <footer className="flex justify-end gap-3 border-t border-[var(--border)] px-5 py-4">
                    <button className="sig-btn sig-btn-secondary" type="button" onClick={onClose}>Cancelar</button>
                    <button className="sig-btn sig-btn-primary" disabled={form.processing} type="submit">
                        <Plus size={15} />
                        {form.processing ? 'Salvando...' : 'Salvar e Adicionar na Composicao'}
                    </button>
                </footer>
            </form>
        </div>
    );
}

function ModalField({ children, error, label }) {
    return (
        <label className="mb-3 block">
            <span className="mb-1 block text-xs font-bold text-[var(--ink-500)]">{label}</span>
            {children}
            {error && <span className="mt-1 block text-xs font-semibold text-rose-600">{error}</span>}
        </label>
    );
}

function setMoneyField(form, field, value) {
    form.setData(field, formatMoneyInput(value));
}

function formatMoneyInput(value) {
    const digits = String(value ?? '').replace(/\D/g, '');

    if (!digits) {
        return '';
    }

    const padded = digits.padStart(3, '0');
    const integer = (padded.slice(0, -2).replace(/^0+(?=\d)/, '') || '0')
        .replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const cents = padded.slice(-2);

    return `${integer},${cents}`;
}

function DetailTerm({ children }) {
    return <dt className="text-xs font-bold text-[var(--ink-500)]">{children}</dt>;
}

function DetailValue({ children }) {
    return <dd className="font-semibold text-[var(--primary)]">{children}</dd>;
}

function PriceBlock({ label, value }) {
    return (
        <div>
            <span className="text-xs font-bold text-[var(--ink-500)]">{label}</span>
            <p className="mt-1 text-2xl font-bold text-[var(--ink-900)]">{formatCurrency(value)}</p>
        </div>
    );
}

function toMonthInputValue(value) {
    if (!value) {
        return null;
    }

    const parsed = String(value).trim();

    if (/^\d{4}-\d{2}$/.test(parsed)) {
        return parsed;
    }

    const monthYear = parsed.match(/^(\d{1,2})\/(\d{2}|\d{4})$/);

    if (!monthYear) {
        return null;
    }

    const month = monthYear[1].padStart(2, '0');
    const year = monthYear[2].length === 2 ? `20${monthYear[2]}` : monthYear[2];

    return `${year}-${month}`;
}

function calculatePrice(price, coefficient) {
    const parsedPrice = Number(price ?? 0);
    const parsedCoefficient = Number(String(coefficient || '1').replace(',', '.'));

    return (Number.isNaN(parsedPrice) ? 0 : parsedPrice) * (Number.isNaN(parsedCoefficient) ? 1 : parsedCoefficient);
}

function calculateSicro3BuilderPrice(option, coefficient, section, operativeUse = '1', idleUse = '0', field = 'onerado') {
    if (!option) {
        return 0;
    }

    if (section !== 'equipamentos') {
        return calculatePrice(sicro3DisplayUnit(option, field), coefficient);
    }

    const parsedCoefficient = Number(String(coefficient || '1').replace(',', '.'));
    const parsedOperativeUse = Number(String(operativeUse || '1').replace(',', '.'));
    const parsedIdleUse = Number(String(idleUse || '0').replace(',', '.'));
    const operationalCost = sicro3DisplayUnit(option, field);
    const idleCost = sicro3DisplayIdleCost(option, field);

    return (Number.isNaN(parsedCoefficient) ? 1 : parsedCoefficient)
        * ((operationalCost * (Number.isNaN(parsedOperativeUse) ? 1 : parsedOperativeUse))
            + (idleCost * (Number.isNaN(parsedIdleUse) ? 0 : parsedIdleUse)));
}

function sicro3ColumnCount(section, readOnly = false) {
    const columns = {
        equipment: 8,
        labor: 6,
        material: 6,
        activity: 6,
        fixed: 7,
        transport: 9,
    }[section.columns] ?? 6;

    return readOnly ? columns : columns + 1;
}

function sicro3ValueWithFallback(primary, fallback) {
    const parsedPrimary = Number(primary ?? 0);
    const parsedFallback = Number(fallback ?? 0);

    if (!Number.isNaN(parsedPrimary) && parsedPrimary > 0) {
        return parsedPrimary;
    }

    return Number.isNaN(parsedFallback) ? 0 : parsedFallback;
}

function sicro3DisplayUnit(item, field = 'onerado') {
    return field === 'desonerado'
        ? sicro3ValueWithFallback(item.preco_unitario_desonerado, item.preco_unitario_onerado)
        : sicro3ValueWithFallback(item.preco_unitario_onerado, item.preco_unitario_desonerado);
}

function sicro3DisplayIdleCost(item, field = 'onerado') {
    if (field === 'desonerado') {
        return sicro3ValueWithFallback(item.custo_improdutivo_desonerado, item.custo_improdutivo_onerado || sicro3DisplayUnit(item, field));
    }

    return sicro3ValueWithFallback(item.custo_improdutivo_onerado, item.custo_improdutivo_desonerado || sicro3DisplayUnit(item, field));
}

function sicro3DisplayTotal(item, field = 'onerado') {
    const stored = field === 'desonerado'
        ? sicro3ValueWithFallback(item.preco_desonerado, item.preco_onerado)
        : sicro3ValueWithFallback(item.preco_onerado, item.preco_desonerado);

    if (stored > 0) {
        return stored;
    }

    return calculateSicro3BuilderPrice(
        item,
        item.coeficiente,
        item.sicro3_section,
        item.sicro3_utilizacao_operativa,
        item.sicro3_utilizacao_improdutiva,
        field,
    );
}

function sicro3ReferenceDescription(item) {
    const reference = String(item.sicro3_referenced_item_description ?? '').trim();
    const description = String(item.descricao ?? '').trim();

    if (reference && description && reference !== description) {
        return `${reference} - ${description}`;
    }

    return description || reference || '-';
}

function sicro3SectionFromOption(option) {
    const type = String(option?.tipo ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/-/g, ' ');

    if (type.includes('equipamento')) {
        return 'equipamentos';
    }

    if (type.includes('mao de obra')) {
        return 'mao_de_obra';
    }

    if (type.includes('material')) {
        return 'material';
    }

    return null;
}

function formatCurrency(value, decimals = 2) {
    const parsed = Number(value ?? 0);

    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(Number.isNaN(parsed) ? 0 : parsed);
}

function formatNumber(value, decimals = 2) {
    const parsed = Number(value ?? 0);

    return new Intl.NumberFormat('pt-BR', {
        maximumFractionDigits: decimals,
        minimumFractionDigits: 0,
    }).format(Number.isNaN(parsed) ? 0 : parsed);
}

function formatOptionalNumber(value, decimals = 2) {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return formatNumber(value, decimals);
}

function firstReferenceLabel(composicao) {
    const reference = composicao.base_references?.[0];

    return reference?.data ?? '-';
}

function formatBaseLabel(base) {
    return String(base).toUpperCase() === 'PROPRIA' ? 'Base propria' : base;
}
