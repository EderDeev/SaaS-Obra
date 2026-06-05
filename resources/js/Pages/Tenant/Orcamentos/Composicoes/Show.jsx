import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Boxes, Check, ChevronDown, ChevronRight, Layers3, PackagePlus, Pencil, Plus, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import OrcamentoShell from '../Partials/OrcamentoShell';

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
            title={composicao.descricao}
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
                                itemType="composicao"
                                options={composicaoOptions}
                                tenant={tenant}
                                title="Adicionar composicao da base propria"
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

    return (
        <section className="overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-[var(--shadow-sm)]">
            <header className="border-b border-[var(--border)] bg-white px-5 py-5">
                <p className="text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--ink-400)]">
                    Detalhamento de composicoes com precos por UF
                </p>
                <div className="mt-2 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="font-mono text-3xl font-semibold text-[var(--primary)]">{detail?.codigo ?? composicao.codigo}</h1>
                        <p className="mt-2 max-w-5xl text-sm font-bold uppercase leading-6 text-[var(--ink-900)]">
                            {detail?.descricao ?? composicao.descricao}
                        </p>
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
                                    <StateValue label="Valor Nao Desonerado" value={state.preco_onerado} />
                                    <StateValue label="Valor Desonerado" value={state.preco_desonerado} />
                                    <span className="inline-flex min-h-8 items-center justify-center rounded-full bg-white px-3 text-xs font-bold text-[var(--ink-500)] shadow-[var(--shadow-sm)]">
                                        {state.items_count} itens
                                    </span>
                                </button>

                                {isOpen && (
                                    <AnaliticoItems state={state} tenant={tenant} />
                                )}
                            </article>
                        );
                    })}
                </div>
            )}
        </section>
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

function StateValue({ label, value }) {
    return (
        <div>
            <span className="text-[10px] font-bold uppercase tracking-[0.06em] text-[var(--ink-400)]">{label}</span>
            <p className="mt-1 font-mono text-sm font-bold text-[var(--ink-900)]">{formatCurrency(value)}</p>
        </div>
    );
}

function AnaliticoItems({ state, tenant }) {
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
                                <td className="px-3 py-3 text-[var(--ink-600)]">{item.tipo || item.item_type_label}</td>
                                <td className="px-3 py-3 font-semibold text-[var(--ink-700)]">{item.unidade || '-'}</td>
                                <td className="px-3 py-3 text-right font-mono">{formatCurrency(item.preco_unitario_onerado)}</td>
                                <td className="px-3 py-3 text-right font-mono">{formatCurrency(item.preco_unitario_desonerado)}</td>
                                <td className="px-3 py-3 text-right font-mono">{formatNumber(item.coeficiente, 6)}</td>
                                <td className="px-3 py-3 text-right font-mono font-semibold">{formatCurrency(item.preco_onerado)}</td>
                                <td className="px-3 py-3 text-right font-mono font-semibold">{formatCurrency(item.preco_desonerado)}</td>
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
                            <MobileValue label="Unit. nao des." value={formatCurrency(item.preco_unitario_onerado)} />
                            <MobileValue label="Unit. des." value={formatCurrency(item.preco_unitario_desonerado)} />
                            <MobileValue label="Total nao des." value={formatCurrency(item.preco_onerado)} />
                            <MobileValue label="Total des." value={formatCurrency(item.preco_desonerado)} />
                        </div>
                    </article>
                ))}
            </div>
        </div>
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
                <h2 className="text-[17px] font-bold text-[var(--primary)]">{composicao.descricao}</h2>
                <p className="mt-1 font-mono text-sm text-[var(--ink-500)]">{composicao.codigo}</p>
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

function ItemBuilder({ composicao, itemType, options, tenant, title, onCancel }) {
    const [base, setBase] = useState(options[0]?.base ?? 'SINAPI');
    const [codeSearch, setCodeSearch] = useState('');
    const [descriptionSearch, setDescriptionSearch] = useState('');
    const [selectedId, setSelectedId] = useState('');
    const [coefficient, setCoefficient] = useState('1');
    const [processing, setProcessing] = useState(false);
    const baseOptions = useMemo(() => Array.from(new Set(options.map((option) => option.base).filter(Boolean))), [options]);
    const filteredOptions = useMemo(() => {
        const code = codeSearch.trim().toLowerCase();
        const description = descriptionSearch.trim().toLowerCase();

        if (!code && !description) {
            return [];
        }

        return options
            .filter((option) => !base || option.base === base)
            .filter((option) => !code || String(option.codigo).toLowerCase().includes(code))
            .filter((option) => !description || String(option.descricao).toLowerCase().includes(description))
            .slice(0, 30);
    }, [base, codeSearch, descriptionSearch, itemType, options]);
    const selected = options.find((option) => String(option.id) === String(selectedId));
    const requiresSearch = !codeSearch.trim() && !descriptionSearch.trim();
    const itemLabel = itemType === 'insumo' ? 'insumos' : 'composicoes';

    const cancelSelection = () => {
        setSelectedId('');
        setCoefficient('1');
    };

    const save = () => {
        if (!selectedId) {
            return;
        }

        setProcessing(true);
        router.post(
            route('tenant.orcamentos.composicoes.items.store', [tenant.slug, composicao.id]),
            {
                item_type: itemType,
                source_id: selectedId,
                coeficiente: coefficient,
            },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setSelectedId('');
                    setCodeSearch('');
                    setDescriptionSearch('');
                    setCoefficient('1');
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
                                    <span className="font-semibold text-[var(--ink-700)]">{selected.base}</span>
                                ) : (
                                    <select className="sig-input !h-9 !min-h-9 !w-28 !px-2" value={base} onChange={(event) => setBase(event.target.value)}>
                                        {baseOptions.map((option) => (
                                            <option key={option} value={option}>{option}</option>
                                        ))}
                                    </select>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                {selected ? (
                                    <span className="font-mono font-semibold text-[var(--ink-700)]">{selected.codigo}</span>
                                ) : (
                                    <input className="sig-input !h-9 !min-h-9 !w-32 !px-2" placeholder="Codigo" value={codeSearch} onChange={(event) => setCodeSearch(event.target.value)} />
                                )}
                            </td>
                            <td className="px-2 py-2">
                                {selected ? (
                                    <span className="block max-w-[460px] text-[11px] font-semibold leading-5 text-[var(--ink-700)]">
                                        {selected.descricao}
                                    </span>
                                ) : (
                                    <input className="sig-input !h-9 !min-h-9 !w-80 !px-2" placeholder="Descricao" value={descriptionSearch} onChange={(event) => setDescriptionSearch(event.target.value)} />
                                )}
                            </td>
                            <td className="px-2 py-2">{selected?.tipo ?? '-'}</td>
                            <td className="px-2 py-2">{selected?.unidade ?? '-'}</td>
                            <td className="px-2 py-2 text-right">{formatCurrency(selected?.preco_unitario_onerado)}</td>
                            <td className="px-2 py-2 text-right">{formatCurrency(selected?.preco_unitario_desonerado)}</td>
                            <td className="px-2 py-2">
                                <input className="sig-input !h-9 !min-h-9 !w-28 !px-2" value={coefficient} onChange={(event) => setCoefficient(event.target.value)} />
                            </td>
                            <td className="px-2 py-2 text-right">{formatCurrency(calculatePrice(selected?.preco_unitario_onerado, coefficient))}</td>
                            <td className="px-2 py-2 text-right">{formatCurrency(calculatePrice(selected?.preco_unitario_desonerado, coefficient))}</td>
                            <td className="px-2 py-2">
                                {selected ? (
                                    <div className="flex items-center justify-center gap-2">
                                        <button
                                            className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-600 text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                            type="button"
                                            disabled={processing}
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
                    </tbody>
                </table>
            </div>

            <div className="border-t border-[var(--border)] p-4">
                <div className="max-h-44 overflow-y-auto rounded-lg border border-[var(--border)]">
                    {requiresSearch ? (
                        <div className="p-4 text-sm text-[var(--ink-500)]">
                            Pesquise pelo codigo ou pela descricao para listar {itemLabel} da base selecionada.
                        </div>
                    ) : filteredOptions.length === 0 ? (
                        <div className="p-4 text-sm text-[var(--ink-500)]">Nenhum registro encontrado nas bases de referencia selecionadas.</div>
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
                            <span>{option.data ?? option.base}</span>
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

    return (
        <section className="overflow-hidden rounded-lg border border-[var(--border)]">
            <table className="w-full min-w-[980px] border-collapse text-left text-xs">
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
                            ? calculatePrice(item.preco_unitario_onerado, editingCoefficient)
                            : item.preco_onerado;
                        const previewDesonerado = isEditing
                            ? calculatePrice(item.preco_unitario_desonerado, editingCoefficient)
                            : item.preco_desonerado;

                        return (
                            <tr key={item.id} className={`hover:bg-[var(--primary-50)]/50 ${isEditing ? 'bg-indigo-50/60' : ''}`}>
                                <td className="px-3 py-3">{item.base}</td>
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

function CreateInsumoModal({ composicao, options, tenant, onClose }) {
    const firstReference = composicao.base_references?.[0] ?? {};
    const form = useForm({
        codigo_insumo: '',
        descricao: '',
        unidade: '',
        tipo: 'equipment',
        uf: firstReference.uf ?? composicao.uf ?? 'PA',
        origem_preco: 'CR',
        preco_nao_desonerado: '',
        preco_desonerado: '',
        data: toMonthInputValue(firstReference.data) ?? '2026-04',
        coeficiente: '1',
        observacao: '',
    });

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

                    <div className="grid gap-3 sm:grid-cols-2">
                        <ModalField label="Codigo" error={form.errors.codigo_insumo}>
                            <input className="sig-input" value={form.data.codigo_insumo} onChange={(event) => form.setData('codigo_insumo', event.target.value)} />
                        </ModalField>
                        <ModalField label="Origem preco" error={form.errors.origem_preco}>
                            <input className="sig-input" value={form.data.origem_preco} onChange={(event) => form.setData('origem_preco', event.target.value)} />
                        </ModalField>
                    </div>

                    <ModalField label="Descricao" error={form.errors.descricao}>
                        <input className="sig-input" value={form.data.descricao} onChange={(event) => form.setData('descricao', event.target.value)} />
                    </ModalField>

                    <ModalField label="Unidade" error={form.errors.unidade}>
                        <input className="sig-input" value={form.data.unidade} onChange={(event) => form.setData('unidade', event.target.value)} />
                    </ModalField>

                    <ModalField label="Tipo" error={form.errors.tipo}>
                        <select className="sig-input" value={form.data.tipo} onChange={(event) => form.setData('tipo', event.target.value)}>
                            {(options.types ?? []).map((type) => (
                                <option key={type.value} value={type.value}>{type.label}</option>
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
                        <input className="sig-input" value={form.data.preco_nao_desonerado} onChange={(event) => form.setData('preco_nao_desonerado', event.target.value)} />
                    </ModalField>
                    <ModalField label="Valor desonerado" error={form.errors.preco_desonerado}>
                        <input className="sig-input" placeholder="Opcional" value={form.data.preco_desonerado} onChange={(event) => form.setData('preco_desonerado', event.target.value)} />
                    </ModalField>
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

function formatCurrency(value) {
    const parsed = Number(value ?? 0);

    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(Number.isNaN(parsed) ? 0 : parsed);
}

function formatNumber(value, decimals = 2) {
    const parsed = Number(value ?? 0);

    return new Intl.NumberFormat('pt-BR', {
        maximumFractionDigits: decimals,
        minimumFractionDigits: 0,
    }).format(Number.isNaN(parsed) ? 0 : parsed);
}

function firstReferenceLabel(composicao) {
    const reference = composicao.base_references?.[0];

    return reference?.data ?? '-';
}
