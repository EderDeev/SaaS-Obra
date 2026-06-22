import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CalendarClock, Calculator, Link2, Plus, Save, Trash2, TrendingUp, Upload } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const parseDecimal = (value) => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    let normalized = String(value).trim();

    if (normalized.includes(',') && normalized.includes('.')) {
        normalized = normalized.lastIndexOf(',') > normalized.lastIndexOf('.')
            ? normalized.replace(/\./g, '').replace(',', '.')
            : normalized.replace(/,/g, '');
    } else if (normalized.includes(',')) {
        normalized = normalized.replace(',', '.');
    }

    const number = Number(normalized);

    return Number.isFinite(number) ? number : null;
};

const formatDecimal = (value, digits = 6) =>
    new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: digits,
    }).format(Number(value || 0));

const formatCurrency = (value) =>
    new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value || 0));

const formatPercent = (value) =>
    new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    }).format(Number(value || 0));

function Field({ label, error, children, className = '' }) {
    return (
        <label className={`grid gap-1.5 text-sm ${className}`}>
            <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">{label}</span>
            {children}
            {error ? <span className="text-xs font-semibold text-red-600">{error}</span> : null}
        </label>
    );
}
export default function IndicesReajuste({ tenant, contracts, selectedContractId, indices, itensReajuste = [] }) {
    const { flash = {} } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const [showImport, setShowImport] = useState(false);
    const [showLinks, setShowLinks] = useState(false);
    const [activeCompetenciaId, setActiveCompetenciaId] = useState(null);
    const [linkValues, setLinkValues] = useState({});
    const [itemFilter, setItemFilter] = useState('');
    const [sheetItemFilter, setSheetItemFilter] = useState('');
    const [indiceFilter, setIndiceFilter] = useState('all');
    const [bulkIndiceId, setBulkIndiceId] = useState('');
    const [itemPage, setItemPage] = useState(1);
    const [savingLinks, setSavingLinks] = useState(false);
    const itemsPerPage = 20;

    const form = useForm({
        contract_id: selectedContractId || '',
        nome: '',
        codigo: '',
        indice_base: '',
        data_base: '',
        indice_atual: '',
        data_atual: '',
        observacao: '',
    });

    const competenciaForm = useForm({
        competencia: '',
        valor_indice: '',
        data_publicacao: '',
        observacao: '',
    });

    const importForm = useForm({
        contract_id: selectedContractId || '',
        file: null,
        first_item_row: '2',
        last_item_row: '',
        item_column: 'A',
        indice_codigo_column: 'B',
    });

    useEffect(() => {
        setLinkValues(
            Object.fromEntries(
                itensReajuste.map((item) => [String(item.id), item.indice_id ? String(item.indice_id) : '']),
            ),
        );
    }, [itensReajuste]);

    useEffect(() => {
        importForm.setData('contract_id', selectedContractId || '');
    }, [selectedContractId]);

    useEffect(() => {
        setItemPage(1);
    }, [itemFilter, sheetItemFilter, indiceFilter, selectedContractId]);

    const preview = useMemo(() => {
        const base = parseDecimal(form.data.indice_base);
        const current = parseDecimal(form.data.indice_atual);

        if (!base || current === null) {
            return { factor: 0, percent: 0 };
        }

        const factor = (current - base) / base;

        return { factor, percent: factor * 100 };
    }, [form.data.indice_base, form.data.indice_atual]);

    const selectedContract = contracts.find((contract) => Number(contract.id) === Number(selectedContractId));

    const filteredItems = useMemo(() => {
        const term = itemFilter.trim().toLowerCase();
        const sheetTerm = sheetItemFilter.trim().replace(/\.+$/, '');

        return itensReajuste.filter((item) => {
            const selectedIndice = linkValues[String(item.id)] || '';
            const itemNumber = String(item.item || '');
            const matchesText = !term || [item.item, item.codigo, item.descricao, item.indice_codigo, item.indice_nome]
                .filter(Boolean)
                .some((value) => String(value).toLowerCase().includes(term));
            const matchesSheet = !sheetTerm || itemNumber === sheetTerm || itemNumber.startsWith(`${sheetTerm}.`);
            const matchesIndice =
                indiceFilter === 'all'
                || (indiceFilter === 'linked' && selectedIndice)
                || (indiceFilter === 'unlinked' && !selectedIndice)
                || selectedIndice === indiceFilter;

            return matchesText && matchesSheet && matchesIndice;
        });
    }, [itensReajuste, itemFilter, sheetItemFilter, indiceFilter, linkValues]);

    const totalItemPages = Math.max(1, Math.ceil(filteredItems.length / itemsPerPage));
    const currentItemPage = Math.min(itemPage, totalItemPages);
    const paginatedItems = filteredItems.slice((currentItemPage - 1) * itemsPerPage, currentItemPage * itemsPerPage);

    const linkedCount = itensReajuste.filter((item) => linkValues[String(item.id)]).length;

    const applyBulkIndice = () => {
        if (!bulkIndiceId || filteredItems.length === 0) {
            return;
        }

        if (!window.confirm(`Deseja aplicar este índice aos ${filteredItems.length} item(ns) filtrados?`)) {
            return;
        }

        setLinkValues((current) => ({
            ...current,
            ...Object.fromEntries(filteredItems.map((item) => [String(item.id), bulkIndiceId])),
        }));
    };

    const handleContractChange = (event) => {
        router.get(
            route('tenant.medicao.indice-reajuste.index', tenant.slug),
            { contract_id: event.target.value },
            { preserveScroll: true, preserveState: false },
        );
    };

    const submit = (event) => {
        event.preventDefault();

        form.post(route('tenant.medicao.indice-reajuste.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('nome', 'codigo', 'indice_base', 'data_base', 'indice_atual', 'data_atual', 'observacao');
                setShowCreate(false);
            },
        });
    };

    const destroy = (indice) => {
        if (!window.confirm(`Deseja realmente excluir o índice "${indice.nome}"?`)) {
            return;
        }

        router.delete(route('tenant.medicao.indice-reajuste.destroy', [tenant.slug, indice.id]), {
            preserveScroll: true,
        });
    };

    const openCompetenciaForm = (indice) => {
        setActiveCompetenciaId((current) => (current === indice.id ? null : indice.id));
        competenciaForm.reset('competencia', 'valor_indice', 'data_publicacao', 'observacao');
        competenciaForm.clearErrors();
    };

    const submitCompetencia = (event, indice) => {
        event.preventDefault();

        competenciaForm.post(route('tenant.medicao.indice-reajuste.competencias.store', [tenant.slug, indice.id]), {
            preserveScroll: true,
            onSuccess: () => {
                competenciaForm.reset('competencia', 'valor_indice', 'data_publicacao', 'observacao');
                setActiveCompetenciaId(null);
            },
        });
    };

    const destroyCompetencia = (indice, competencia) => {
        if (!window.confirm(`Deseja remover a competência ${competencia.competencia_label}?`)) {
            return;
        }

        router.delete(route('tenant.medicao.indice-reajuste.competencias.destroy', [tenant.slug, indice.id, competencia.id]), {
            preserveScroll: true,
        });
    };

    const saveItemLinks = () => {
        if (!window.confirm('Deseja realmente salvar os vínculos de índices destes itens?')) {
            return;
        }

        setSavingLinks(true);

        router.post(
            route('tenant.medicao.indice-reajuste.vinculos.store', tenant.slug),
            {
                contract_id: selectedContractId,
                links: itensReajuste.map((item) => ({
                    item_id: item.id,
                    indice_id: linkValues[String(item.id)] || null,
                })),
            },
            {
                preserveScroll: true,
                onFinish: () => setSavingLinks(false),
            },
        );
    };

    const submitImport = (event) => {
        event.preventDefault();

        importForm.post(route('tenant.medicao.indice-reajuste.vinculos.import', tenant.slug), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                importForm.reset('file', 'last_item_row');
                setShowImport(false);
            },
        });
    };

    return (
        <AuthenticatedLayout tenant={tenant}>
            <Head title="Medição - Índice de Reajuste" />

            <div className="space-y-6 px-4 py-5 sm:px-6 lg:px-8">
                <section className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <span className="eyebrow">Medição</span>
                        <h1 className="mt-2 text-3xl font-black text-[var(--ink-900)]">Índice de Reajuste</h1>
                        <p className="mt-2 max-w-3xl text-base text-[var(--ink-500)]">
                            Cadastre índices, atualize competências e vincule cada item ao índice que será usado nas medições.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={() => setShowLinks((current) => !current)}
                        className="inline-flex items-center justify-center gap-2 rounded-lg border border-blue-100 bg-blue-50 px-5 py-3 text-sm font-bold text-blue-700 shadow-sm hover:bg-blue-100"
                    >
                        <Link2 size={18} />
                        {'Vincular \u00cdndices'}
                    </button>
                    <button
                        type="button"
                        onClick={() => setShowCreate((current) => !current)}
                        className="inline-flex items-center justify-center gap-2 rounded-lg bg-[var(--primary)] px-5 py-3 text-sm font-bold text-white shadow-sm hover:bg-blue-700"
                    >
                        <Plus size={18} />
                        Criar índice
                    </button>
                    </div>
                </section>

                {flash.success ? (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                        {flash.success}
                    </div>
                ) : null}

                <section className="rounded-lg border border-[var(--border)] bg-white p-4 shadow-sm">
                    <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                        <Field label="Contrato">
                            <select
                                value={selectedContractId || ''}
                                onChange={handleContractChange}
                                className="h-12 rounded-lg border border-[var(--border)] bg-white px-4 text-sm font-semibold text-[var(--ink-800)] outline-none focus:border-[var(--primary)]"
                            >
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>
                                        {contract.code} - {contract.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <div className="rounded-lg bg-slate-50 px-4 py-3 text-sm text-[var(--ink-500)]">
                            <strong className="block text-[var(--ink-900)]">
                                {indices.length} índice(s) · {linkedCount}/{itensReajuste.length} item(ns) vinculados
                            </strong>
                            {selectedContract ? `${selectedContract.code} - ${selectedContract.name}` : 'Selecione um contrato'}
                        </div>
                    </div>
                </section>

                {showCreate ? (
                    <form onSubmit={submit} className="rounded-lg border border-[var(--border)] bg-white shadow-sm">
                        <div className="flex items-center gap-3 border-b border-[var(--border)] px-5 py-4">
                            <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-[var(--primary)]">
                                <Calculator size={20} />
                            </span>
                            <div>
                                <h2 className="text-lg font-black text-[var(--ink-900)]">Novo índice de reajuste</h2>
                                <p className="text-sm text-[var(--ink-500)]">R = V x (Ia - I0) / I0</p>
                            </div>
                        </div>

                        <div className="grid gap-4 p-5 lg:grid-cols-4">
                            <Field label="Nome" error={form.errors.nome}>
                                <input
                                    value={form.data.nome}
                                    onChange={(event) => form.setData('nome', event.target.value)}
                                    className="h-12 rounded-lg border border-[var(--border)] px-4 outline-none focus:border-[var(--primary)]"
                                    placeholder="Ex: INCC - base contratual"
                                />
                            </Field>
                            <Field label="Código" error={form.errors.codigo}>
                                <input
                                    value={form.data.codigo}
                                    onChange={(event) => form.setData('codigo', event.target.value)}
                                    className="h-12 rounded-lg border border-[var(--border)] px-4 outline-none focus:border-[var(--primary)]"
                                    placeholder="Ex: INCC"
                                />
                            </Field>
                            <Field label="Data base (I0)" error={form.errors.data_base}>
                                <input
                                    type="date"
                                    value={form.data.data_base}
                                    onChange={(event) => form.setData('data_base', event.target.value)}
                                    className="h-12 rounded-lg border border-[var(--border)] px-4 outline-none focus:border-[var(--primary)]"
                                />
                            </Field>
                            <Field label="Índice base (I0)" error={form.errors.indice_base}>
                                <input
                                    value={form.data.indice_base}
                                    onChange={(event) => form.setData('indice_base', event.target.value)}
                                    inputMode="decimal"
                                    className="h-12 rounded-lg border border-[var(--border)] px-4 outline-none focus:border-[var(--primary)]"
                                    placeholder="Ex: 100,000000"
                                />
                            </Field>
                            <Field label="Competência inicial (Ia)" error={form.errors.data_atual}>
                                <input
                                    type="date"
                                    value={form.data.data_atual}
                                    onChange={(event) => form.setData('data_atual', event.target.value)}
                                    className="h-12 rounded-lg border border-[var(--border)] px-4 outline-none focus:border-[var(--primary)]"
                                />
                            </Field>
                            <Field label="Índice inicial (Ia)" error={form.errors.indice_atual}>
                                <input
                                    value={form.data.indice_atual}
                                    onChange={(event) => form.setData('indice_atual', event.target.value)}
                                    inputMode="decimal"
                                    className="h-12 rounded-lg border border-[var(--border)] px-4 outline-none focus:border-[var(--primary)]"
                                    placeholder="Ex: 106,250000"
                                />
                            </Field>
                            <div className="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3">
                                <span className="text-xs font-bold uppercase tracking-wide text-blue-700">Fator inicial</span>
                                <strong className="mt-1 block text-xl text-[var(--ink-900)]">{formatDecimal(preview.factor, 6)}</strong>
                            </div>
                            <div className="rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3">
                                <span className="text-xs font-bold uppercase tracking-wide text-emerald-700">Reajuste inicial</span>
                                <strong className="mt-1 block text-xl text-[var(--ink-900)]">{formatPercent(preview.percent)}%</strong>
                            </div>
                            <Field label="Observação" error={form.errors.observacao}>
                                <textarea
                                    value={form.data.observacao}
                                    onChange={(event) => form.setData('observacao', event.target.value)}
                                    rows={3}
                                    className="rounded-lg border border-[var(--border)] px-4 py-3 outline-none focus:border-[var(--primary)] lg:col-span-4"
                                    placeholder="Observações sobre a fonte do índice ou período de referência"
                                />
                            </Field>
                        </div>

                        <div className="flex flex-wrap justify-end gap-3 border-t border-[var(--border)] px-5 py-4">
                            <button
                                type="button"
                                onClick={() => setShowCreate(false)}
                                className="rounded-lg border border-[var(--border)] px-4 py-2 text-sm font-bold text-[var(--ink-700)]"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded-lg bg-[var(--primary)] px-5 py-2 text-sm font-bold text-white disabled:opacity-60"
                            >
                                Salvar índice
                            </button>
                        </div>
                    </form>
                ) : null}

                <section className="overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-sm">
                    <div className="border-b border-[var(--border)] px-5 py-4">
                        <h2 className="text-lg font-black text-[var(--ink-900)]">Índices cadastrados</h2>
                        <p className="text-sm text-[var(--ink-500)]">Atualize as competências sem sobrescrever períodos já utilizados.</p>
                    </div>

                    {indices.length > 0 ? (
                        <div className="grid gap-3 p-4">
                            {indices.map((indice) => (
                                <article key={indice.id} className="rounded-lg border border-[var(--border)] bg-white p-4">
                                    <div className="grid gap-4 md:grid-cols-[minmax(0,1.4fr)_repeat(4,minmax(0,.8fr))_auto] md:items-center">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <TrendingUp size={18} className="text-[var(--primary)]" />
                                                <h3 className="font-black text-[var(--ink-900)]">{indice.nome}</h3>
                                            </div>
                                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                                {indice.codigo || 'Sem código'} {indice.created_by ? `- Criado por ${indice.created_by}` : ''}
                                            </p>
                                        </div>
                                        <div>
                                            <span className="text-xs font-bold uppercase text-[var(--ink-500)]">I0</span>
                                            <strong className="block text-[var(--ink-900)]">{formatDecimal(indice.indice_base)}</strong>
                                            <span className="text-xs text-[var(--ink-500)]">{indice.data_base_label}</span>
                                        </div>
                                        <div>
                                            <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Ia inicial</span>
                                            <strong className="block text-[var(--ink-900)]">{formatDecimal(indice.indice_atual)}</strong>
                                            <span className="text-xs text-[var(--ink-500)]">{indice.data_atual_label}</span>
                                        </div>
                                        <div>
                                            <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Fator inicial</span>
                                            <strong className="block text-[var(--ink-900)]">{formatDecimal(indice.fator_reajuste)}</strong>
                                        </div>
                                        <div>
                                            <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Reajuste inicial</span>
                                            <strong className="block text-emerald-700">{formatPercent(indice.percentual_reajuste)}%</strong>
                                        </div>
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                onClick={() => openCompetenciaForm(indice)}
                                                className="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-blue-100 bg-blue-50 px-3 text-sm font-bold text-blue-700 hover:bg-blue-100"
                                            >
                                                <CalendarClock size={16} />
                                                Competência
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => destroy(indice)}
                                                className="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-red-100 bg-red-50 text-red-600 hover:bg-red-100"
                                                title="Excluir índice"
                                            >
                                                <Trash2 size={17} />
                                            </button>
                                        </div>
                                    </div>

                                    {activeCompetenciaId === indice.id ? (
                                        <form onSubmit={(event) => submitCompetencia(event, indice)} className="mt-4 rounded-lg border border-blue-100 bg-blue-50/60 p-4">
                                            <div className="grid gap-3 lg:grid-cols-5">
                                                <Field label="Competência" error={competenciaForm.errors.competencia}>
                                                    <input
                                                        type="month"
                                                        value={competenciaForm.data.competencia}
                                                        onChange={(event) => competenciaForm.setData('competencia', event.target.value)}
                                                        className="h-11 rounded-lg border border-[var(--border)] bg-white px-3 outline-none focus:border-[var(--primary)]"
                                                    />
                                                </Field>
                                                <Field label="Valor do índice" error={competenciaForm.errors.valor_indice}>
                                                    <input
                                                        value={competenciaForm.data.valor_indice}
                                                        onChange={(event) => competenciaForm.setData('valor_indice', event.target.value)}
                                                        inputMode="decimal"
                                                        className="h-11 rounded-lg border border-[var(--border)] bg-white px-3 outline-none focus:border-[var(--primary)]"
                                                        placeholder="Ex: 112,450000"
                                                    />
                                                </Field>
                                                <Field label="Publicação" error={competenciaForm.errors.data_publicacao}>
                                                    <input
                                                        type="date"
                                                        value={competenciaForm.data.data_publicacao}
                                                        onChange={(event) => competenciaForm.setData('data_publicacao', event.target.value)}
                                                        className="h-11 rounded-lg border border-[var(--border)] bg-white px-3 outline-none focus:border-[var(--primary)]"
                                                    />
                                                </Field>
                                                <Field label="Observação" error={competenciaForm.errors.observacao}>
                                                    <input
                                                        value={competenciaForm.data.observacao}
                                                        onChange={(event) => competenciaForm.setData('observacao', event.target.value)}
                                                        className="h-11 rounded-lg border border-[var(--border)] bg-white px-3 outline-none focus:border-[var(--primary)]"
                                                        placeholder="Fonte ou comentário"
                                                    />
                                                </Field>
                                                <div className="flex items-end gap-2">
                                                    <button type="submit" disabled={competenciaForm.processing} className="h-11 rounded-lg bg-[var(--primary)] px-4 text-sm font-bold text-white disabled:opacity-60">
                                                        Salvar
                                                    </button>
                                                    <button type="button" onClick={() => setActiveCompetenciaId(null)} className="h-11 rounded-lg border border-[var(--border)] bg-white px-4 text-sm font-bold text-[var(--ink-700)]">
                                                        Cancelar
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    ) : null}

                                    <div className="mt-4 rounded-lg border border-[var(--border)] bg-slate-50">
                                        <div className="hidden grid-cols-[1fr_1fr_1fr_1fr_auto] gap-3 border-b border-[var(--border)] px-4 py-3 text-xs font-black uppercase tracking-wide text-[var(--ink-500)] md:grid">
                                            <span>Competência</span>
                                            <span>Valor Ia</span>
                                            <span>Reajuste</span>
                                            <span>Publicação</span>
                                            <span>Ações</span>
                                        </div>
                                        {(indice.competencias || []).length > 0 ? (
                                            indice.competencias.map((competencia) => (
                                                <div key={competencia.id} className="grid gap-2 border-b border-[var(--border)] bg-white px-4 py-3 text-sm last:border-b-0 md:grid-cols-[1fr_1fr_1fr_1fr_auto]">
                                                    <strong className="text-[var(--ink-900)]">{competencia.competencia_label}</strong>
                                                    <span>{formatDecimal(competencia.valor_indice)}</span>
                                                    <span className="font-bold text-emerald-700">{formatPercent(competencia.percentual_reajuste)}%</span>
                                                    <span className="text-[var(--ink-500)]">{competencia.data_publicacao_label || '-'}</span>
                                                    <button type="button" onClick={() => destroyCompetencia(indice, competencia)} className="inline-flex h-8 w-8 items-center justify-center rounded-md text-red-600 hover:bg-red-50" title="Remover competência">
                                                        <Trash2 size={15} />
                                                    </button>
                                                </div>
                                            ))
                                        ) : (
                                            <div className="px-4 py-4 text-sm text-[var(--ink-500)]">
                                                Nenhuma competência atualizada para este índice.
                                            </div>
                                        )}
                                    </div>
                                </article>
                            ))}
                        </div>
                    ) : (
                        <div className="p-8 text-center text-sm text-[var(--ink-500)]">
                            Nenhum índice cadastrado para este contrato.
                        </div>
                    )}
                </section>

                {showLinks ? (
                <section className="overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-sm">
                    <div className="flex flex-col gap-3 border-b border-[var(--border)] px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 className="flex items-center gap-2 text-lg font-black text-[var(--ink-900)]">
                                <Link2 size={19} className="text-[var(--primary)]" />
                                Vínculo dos itens com índices
                            </h2>
                            <p className="text-sm text-[var(--ink-500)]">
                                Escolha manualmente o índice de cada item ou importe uma planilha com item e código do índice.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => setShowImport((current) => !current)}
                                className="inline-flex items-center gap-2 rounded-lg border border-blue-100 bg-blue-50 px-4 py-2 text-sm font-bold text-blue-700 hover:bg-blue-100"
                            >
                                <Upload size={17} />
                                Importar vínculos
                            </button>
                            <button
                                type="button"
                                onClick={saveItemLinks}
                                disabled={savingLinks || itensReajuste.length === 0}
                                className="inline-flex items-center gap-2 rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-bold text-white disabled:opacity-60"
                            >
                                <Save size={17} />
                                Salvar vínculos
                            </button>
                        </div>
                    </div>

                    {showImport ? (
                        <form onSubmit={submitImport} className="border-b border-[var(--border)] bg-slate-50 p-4">
                            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(320px,1.35fr)_repeat(4,minmax(150px,1fr))_auto]">
                                <Field label="Arquivo CSV" error={importForm.errors.file} className="min-w-0">
                                    <input
                                        type="file"
                                        accept=".csv,.txt,.tsv"
                                        onChange={(event) => importForm.setData('file', event.target.files?.[0] || null)}
                                        className="h-11 w-full min-w-0 rounded-lg border border-[var(--border)] bg-white px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-[var(--ink-700)]"
                                    />
                                </Field>
                                <Field label="Primeira linha" error={importForm.errors.first_item_row}>
                                    <input
                                        value={importForm.data.first_item_row}
                                        onChange={(event) => importForm.setData('first_item_row', event.target.value)}
                                        className="h-11 rounded-lg border border-[var(--border)] bg-white px-3 outline-none focus:border-[var(--primary)]"
                                    />
                                </Field>
                                <Field label="Última linha" error={importForm.errors.last_item_row}>
                                    <input
                                        value={importForm.data.last_item_row}
                                        onChange={(event) => importForm.setData('last_item_row', event.target.value)}
                                        className="h-11 rounded-lg border border-[var(--border)] bg-white px-3 outline-none focus:border-[var(--primary)]"
                                        placeholder="Opcional"
                                    />
                                </Field>
                                <Field label="Coluna do item" error={importForm.errors.item_column}>
                                    <input
                                        value={importForm.data.item_column}
                                        onChange={(event) => importForm.setData('item_column', event.target.value)}
                                        className="h-11 rounded-lg border border-[var(--border)] bg-white px-3 uppercase outline-none focus:border-[var(--primary)]"
                                        placeholder="A"
                                    />
                                </Field>
                                <Field label="Coluna do índice" error={importForm.errors.indice_codigo_column}>
                                    <input
                                        value={importForm.data.indice_codigo_column}
                                        onChange={(event) => importForm.setData('indice_codigo_column', event.target.value)}
                                        className="h-11 rounded-lg border border-[var(--border)] bg-white px-3 uppercase outline-none focus:border-[var(--primary)]"
                                        placeholder="B"
                                    />
                                </Field>
                                <div className="flex items-end">
                                    <button
                                        type="submit"
                                        disabled={importForm.processing}
                                        className="h-11 w-full rounded-lg bg-[var(--primary)] px-5 text-sm font-bold text-white disabled:opacity-60 xl:w-auto"
                                    >
                                        Importar
                                    </button>
                                </div>
                            </div>
                            <p className="mt-3 text-sm text-[var(--ink-500)]">
                                Exemplo de CSV: coluna do item com <strong>1.1.1.2</strong> e coluna do índice com <strong>INCC</strong>.
                            </p>
                        </form>
                    ) : null}

                    <div className="grid gap-3 border-b border-[var(--border)] p-4 xl:grid-cols-[minmax(0,1.1fr)_200px_240px_minmax(220px,0.8fr)_auto]">
                        <input
                            value={itemFilter}
                            onChange={(event) => setItemFilter(event.target.value)}
                            className="h-11 w-full rounded-lg border border-[var(--border)] px-4 outline-none focus:border-[var(--primary)]"
                            placeholder="Filtrar por item, código, descrição ou índice"
                        />
                        <input
                            value={sheetItemFilter}
                            onChange={(event) => setSheetItemFilter(event.target.value)}
                            className="h-11 w-full rounded-lg border border-[var(--border)] px-4 outline-none focus:border-[var(--primary)]"
                            placeholder="Item da planilha. Ex: 1"
                        />
                        <select
                            value={indiceFilter}
                            onChange={(event) => setIndiceFilter(event.target.value)}
                            className="h-11 w-full min-w-0 rounded-lg border border-[var(--border)] bg-white px-3 text-sm font-semibold outline-none focus:border-[var(--primary)]"
                        >
                            <option value="all">Todos os índices</option>
                            <option value="linked">Com índice vinculado</option>
                            <option value="unlinked">Sem índice vinculado</option>
                            {indices.map((indice) => (
                                <option key={indice.id} value={String(indice.id)}>
                                    {indice.codigo ? `${indice.codigo} - ${indice.nome}` : indice.nome}
                                </option>
                            ))}
                        </select>
                        <select
                            value={bulkIndiceId}
                            onChange={(event) => setBulkIndiceId(event.target.value)}
                            className="h-11 w-full min-w-0 rounded-lg border border-[var(--border)] bg-white px-3 text-sm font-semibold outline-none focus:border-[var(--primary)]"
                        >
                            <option value="">Índice para aplicar em massa</option>
                            {indices.map((indice) => (
                                <option key={indice.id} value={String(indice.id)}>
                                    {indice.codigo ? `${indice.codigo} - ${indice.nome}` : indice.nome}
                                </option>
                            ))}
                        </select>
                        <button
                            type="button"
                            onClick={applyBulkIndice}
                            disabled={!bulkIndiceId || filteredItems.length === 0}
                            className="inline-flex h-11 items-center justify-center rounded-lg bg-emerald-600 px-4 text-sm font-bold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-40"
                        >
                            Aplicar aos filtrados
                        </button>
                    </div>

                    {filteredItems.length > 0 ? (
                        <div className="grid gap-3 p-4">
                            <div className="flex flex-col gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-[var(--ink-500)] sm:flex-row sm:items-center sm:justify-between">
                                <span>
                                    Exibindo <strong className="text-[var(--ink-900)]">{paginatedItems.length}</strong> de{' '}
                                    <strong className="text-[var(--ink-900)]">{filteredItems.length}</strong> item(ns)
                                </span>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        disabled={currentItemPage <= 1}
                                        onClick={() => setItemPage((page) => Math.max(1, page - 1))}
                                        className="rounded-lg border border-[var(--border)] bg-white px-3 py-2 text-xs font-bold text-[var(--ink-700)] disabled:opacity-40"
                                    >
                                        Anterior
                                    </button>
                                    <span className="text-xs font-bold text-[var(--ink-500)]">
                                        Página {currentItemPage} de {totalItemPages}
                                    </span>
                                    <button
                                        type="button"
                                        disabled={currentItemPage >= totalItemPages}
                                        onClick={() => setItemPage((page) => Math.min(totalItemPages, page + 1))}
                                        className="rounded-lg border border-[var(--border)] bg-white px-3 py-2 text-xs font-bold text-[var(--ink-700)] disabled:opacity-40"
                                    >
                                        Próxima
                                    </button>
                                </div>
                            </div>
                            {paginatedItems.map((item) => (
                                <div
                                    key={item.id}
                                    className="grid gap-3 rounded-lg border border-[var(--border)] bg-white p-3 lg:grid-cols-[80px_110px_minmax(0,1.3fr)_minmax(0,0.8fr)_minmax(0,0.9fr)]"
                                >
                                    <div className="min-w-0">
                                        <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Item</span>
                                        <strong className="block text-[var(--ink-900)]">{item.item || '-'}</strong>
                                    </div>
                                    <div className="min-w-0">
                                        <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Código</span>
                                        <strong className="block truncate text-[var(--ink-900)]">{item.codigo || '-'}</strong>
                                    </div>
                                    <div className="min-w-0">
                                        <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Descrição</span>
                                        <p className="mt-1 text-sm font-semibold text-[var(--ink-900)]">{item.descricao}</p>
                                        <p className="text-xs text-[var(--ink-500)]">
                                            {item.unidade || '-'} · {formatCurrency(item.valor_com_bdi)}
                                        </p>
                                    </div>
                                    <div className="min-w-0">
                                        <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Atual</span>
                                        <p className="mt-1 truncate text-sm font-bold text-[var(--ink-900)]">
                                            {item.indice_codigo ? `${item.indice_codigo} - ${item.indice_nome}` : 'Sem vínculo'}
                                        </p>
                                        {item.source_type ? <p className="text-xs text-[var(--ink-500)]">{item.source_type}</p> : null}
                                    </div>
                                    <Field label="Índice" className="min-w-0">
                                        <select
                                            value={linkValues[String(item.id)] || ''}
                                            onChange={(event) => setLinkValues((current) => ({ ...current, [String(item.id)]: event.target.value }))}
                                            className="h-11 w-full min-w-0 max-w-full rounded-lg border border-[var(--border)] bg-white px-3 text-sm font-semibold outline-none focus:border-[var(--primary)]"
                                        >
                                            <option value="">Sem índice</option>
                                            {indices.map((indice) => (
                                                <option key={indice.id} value={indice.id}>
                                                    {indice.codigo ? `${indice.codigo} - ${indice.nome}` : indice.nome}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="p-8 text-center text-sm text-[var(--ink-500)]">
                            Nenhum item de medição disponível para vínculo neste contrato.
                        </div>
                    )}
                </section>
                ) : null}
            </div>
        </AuthenticatedLayout>
    );
}
