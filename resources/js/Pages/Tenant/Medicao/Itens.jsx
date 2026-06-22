import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardList,
    FileSpreadsheet,
    GitBranch,
    Layers3,
    Plus,
    RotateCcw,
    Ruler,
    Search,
    Upload,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const formatCurrency = (value) =>
    new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value || 0));

const formatCurrencyOrDash = (value) => {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return formatCurrency(value);
};

const formatDecimal = (value) =>
    new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 6,
    }).format(Number(value || 0));

const formatDecimalOrDash = (value) => {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return formatDecimal(value);
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

function PanelButton({ active, icon: Icon, title, description, colorClass, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-lg border p-4 text-left transition ${
                active
                    ? 'border-[var(--primary)] bg-[var(--primary-50)] shadow-sm'
                    : 'border-[var(--border)] bg-white hover:border-[var(--primary-200)] hover:bg-slate-50'
            }`}
        >
            <span className={`flex h-10 w-10 items-center justify-center rounded-lg ${colorClass}`}>
                <Icon size={19} />
            </span>
            <h3 className="mt-3 text-sm font-bold text-[var(--ink-900)]">{title}</h3>
            <p className="mt-1 text-sm leading-6 text-[var(--ink-500)]">{description}</p>
        </button>
    );
}

function AdditiveMetaFields({ form }) {
    const [localData, setLocalData] = useState({
        additive_title: form.data.additive_title || '',
        additive_reason: form.data.additive_reason || '',
        effective_at: form.data.effective_at || '',
    });

    useEffect(() => {
        setLocalData({
            additive_title: form.data.additive_title || '',
            additive_reason: form.data.additive_reason || '',
            effective_at: form.data.effective_at || '',
        });
    }, [form.data.additive_title, form.data.additive_reason, form.data.effective_at]);

    const updateLocal = (field, value) => {
        setLocalData((current) => ({ ...current, [field]: value }));
    };

    const syncField = (field) => {
        form.setData(field, localData[field]);
    };

    return (
        <div className="grid gap-4 rounded-lg border border-violet-100 bg-violet-50/60 p-4 lg:grid-cols-[1fr_1fr_180px]">
            <Field label="Título do aditivo" error={form.errors.additive_title}>
                <input
                    name="additive_title"
                    value={localData.additive_title}
                    onChange={(event) => updateLocal('additive_title', event.target.value)}
                    onBlur={() => syncField('additive_title')}
                    className="sig-input"
                    placeholder="Ex: Aditivo 1 - Revisão de quantitativos"
                />
            </Field>
            <Field label="Motivo" error={form.errors.additive_reason}>
                <input
                    name="additive_reason"
                    value={localData.additive_reason}
                    onChange={(event) => updateLocal('additive_reason', event.target.value)}
                    onBlur={() => syncField('additive_reason')}
                    className="sig-input"
                    placeholder="Motivo da alteração"
                />
            </Field>
            <Field label="Vigência" error={form.errors.effective_at}>
                <input
                    name="effective_at"
                    type="date"
                    value={localData.effective_at}
                    onChange={(event) => updateLocal('effective_at', event.target.value)}
                    onBlur={() => syncField('effective_at')}
                    className="sig-input"
                />
            </Field>
        </div>
    );
}

function ItemAdditiveHistoryModal({ item, onClose }) {
    if (!item) {
        return null;
    }

    const history = item.additive_history || [];
    const base = item.base_history || {};
    const statusClass = (status) =>
        ({
            novo: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
            alterado: 'bg-amber-50 text-amber-700 ring-amber-100',
            sem_alteracao: 'bg-slate-50 text-slate-700 ring-slate-200',
        })[status] || 'bg-violet-50 text-violet-700 ring-violet-100';

    return (
        <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 p-4" onMouseDown={onClose}>
            <div
                className="max-h-[90vh] w-full max-w-6xl overflow-hidden rounded-xl bg-white shadow-2xl"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                    <div>
                        <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">
                            Histórico de aditivos
                        </span>
                        <h2 className="mt-1 text-lg font-semibold text-[var(--ink-900)]">
                            Item {item.item || '-'}
                        </h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">{item.descricao}</p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--border)] text-[var(--ink-500)] transition hover:border-red-200 hover:text-red-600"
                        aria-label="Fechar histórico de aditivos"
                    >
                        <X size={18} />
                    </button>
                </header>

                <div className="max-h-[70vh] overflow-auto p-5">
                    <div className="flex gap-4 overflow-x-auto pb-2">
                        <article className="min-w-[280px] flex-1 rounded-lg border border-blue-100 bg-blue-50/50 p-4 shadow-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <strong className="text-sm text-[var(--ink-900)]">Base</strong>
                                <span className="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-blue-700 ring-1 ring-blue-100">
                                    {base.status_label || 'Primeira importação'}
                                </span>
                            </div>

                            <div className="mt-4 grid gap-3">
                                <div className="rounded-lg bg-white p-3">
                                    <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Quantidade</span>
                                    <strong className="mt-1 block text-sm text-[var(--ink-900)]">
                                        {formatDecimalOrDash(base.quantidade)}
                                    </strong>
                                </div>
                                <div className="rounded-lg bg-white p-3">
                                    <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Valor BDI</span>
                                    <strong className="mt-1 block text-sm text-[var(--ink-900)]">
                                        {formatCurrencyOrDash(base.valor_com_bdi)}
                                    </strong>
                                </div>
                                <div className="rounded-lg bg-white p-3">
                                    <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Total</span>
                                    <strong className="mt-1 block text-sm text-[var(--ink-900)]">
                                        {formatCurrencyOrDash(base.valor_total)}
                                    </strong>
                                </div>
                            </div>

                            <div className="mt-4 border-t border-blue-100 pt-3 text-xs text-[var(--ink-500)]">
                                <p>Cadastro: {base.created_at || item.created_at || '-'}</p>
                            </div>
                        </article>

                        {history.map((entry) => (
                            <article
                                key={entry.id}
                                className="min-w-[300px] flex-1 rounded-lg border border-[var(--border)] bg-white p-4 shadow-sm"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <strong className="text-sm text-[var(--ink-900)]">Aditivo {entry.number || '-'}</strong>
                                    <span
                                        className={`rounded-full px-2.5 py-1 text-xs font-bold ring-1 ${statusClass(
                                            entry.status,
                                        )}`}
                                    >
                                        {entry.status_label}
                                    </span>
                                </div>

                                <div className="mt-4 grid gap-3">
                                    <div className="rounded-lg bg-slate-50 p-3">
                                        <span className="text-xs font-bold uppercase text-[var(--ink-500)]">
                                            Quantidade anterior
                                        </span>
                                        <strong className="mt-1 block text-sm text-[var(--ink-900)]">
                                            {formatDecimalOrDash(entry.quantidade_anterior)}
                                        </strong>
                                    </div>
                                    <div className="rounded-lg bg-slate-50 p-3">
                                        <span className="text-xs font-bold uppercase text-[var(--ink-500)]">
                                            Quantidade nova
                                        </span>
                                        <strong className="mt-1 block text-sm text-[var(--ink-900)]">
                                            {formatDecimalOrDash(entry.quantidade_nova)}
                                        </strong>
                                    </div>
                                    <div className="rounded-lg bg-slate-50 p-3">
                                        <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Total novo</span>
                                        <strong className="mt-1 block text-sm text-[var(--ink-900)]">
                                            {formatCurrencyOrDash(entry.valor_total_novo)}
                                        </strong>
                                    </div>
                                </div>

                                <div className="mt-4 border-t border-[var(--border)] pt-3">
                                    <p className="text-sm font-semibold text-[var(--ink-900)]">
                                        {entry.title || 'Aditivo sem título'}
                                    </p>
                                    {entry.reason ? (
                                        <p className="mt-1 text-sm text-[var(--ink-500)]">{entry.reason}</p>
                                    ) : null}
                                    <div className="mt-2 text-xs text-[var(--ink-500)]">
                                        <p>Vigência: {entry.effective_at || '-'}</p>
                                        <p>Aplicado: {entry.applied_at || entry.created_at || '-'}</p>
                                    </div>
                                </div>
                            </article>
                        ))}
                    </div>

                    {history.length === 0 ? (
                        <p className="mt-4 rounded-lg border border-dashed border-[var(--border)] bg-slate-50 p-4 text-center text-sm text-[var(--ink-500)]">
                            Este item ainda não teve participação registrada em aditivos.
                        </p>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

export default function MedicaoItens({
    tenant,
    contracts = [],
    orcamentos = [],
    selectedContractId = null,
    items = [],
    additives = [],
    filters = {},
    stats = {},
}) {
    const { props } = usePage();
    const flash = props?.flash || {};
    const [importOptionsOpen, setImportOptionsOpen] = useState(false);
    const [activePanel, setActivePanel] = useState('orcamento');
    const [additiveOptionsOpen, setAdditiveOptionsOpen] = useState(false);
    const [activeAdditivePanel, setActiveAdditivePanel] = useState('orcamento');
    const [selectedHistoryItem, setSelectedHistoryItem] = useState(null);
    const [itemFilters, setItemFilters] = useState({
        item_code: filters.item_code || '',
        sheet_item: filters.sheet_item || '',
        additive: filters.additive || '',
        price_order: filters.price_order || '',
    });
    const selectedContract = useMemo(
        () => contracts.find((contract) => Number(contract.id) === Number(selectedContractId)),
        [contracts, selectedContractId],
    );

    const fromBudgetForm = useForm({
        contract_id: selectedContractId || '',
        orcamento_id: '',
    });

    const importForm = useForm({
        contract_id: selectedContractId || '',
        file: null,
        first_item_row: 2,
        last_item_row: '',
        item_column: 'A',
        codigo_column: 'B',
        banco_column: 'C',
        descricao_column: 'D',
        unidade_column: 'E',
        quantidade_column: 'F',
        valor_unitario_column: 'G',
        valor_com_bdi_column: 'H',
        valor_total_column: 'I',
    });

    const manualForm = useForm({
        contract_id: selectedContractId || '',
        item: '',
        codigo: '',
        banco: '',
        descricao: '',
        unidade: '',
        quantidade_prevista: '',
        valor_unitario: '',
        valor_com_bdi: '',
        valor_total: '',
    });

    const additiveBudgetForm = useForm({
        contract_id: selectedContractId || '',
        orcamento_id: '',
        additive_title: '',
        additive_reason: '',
        effective_at: '',
    });

    const additiveImportForm = useForm({
        contract_id: selectedContractId || '',
        file: null,
        first_item_row: 2,
        last_item_row: '',
        item_column: 'A',
        codigo_column: 'B',
        banco_column: 'C',
        descricao_column: 'D',
        unidade_column: 'E',
        quantidade_column: 'F',
        valor_unitario_column: 'G',
        valor_com_bdi_column: 'H',
        valor_total_column: 'I',
        additive_title: '',
        additive_reason: '',
        effective_at: '',
    });

    const additiveManualForm = useForm({
        contract_id: selectedContractId || '',
        item: '',
        codigo: '',
        banco: '',
        descricao: '',
        unidade: '',
        quantidade_prevista: '',
        valor_unitario: '',
        valor_com_bdi: '',
        valor_total: '',
        additive_title: '',
        additive_reason: '',
        effective_at: '',
    });

    useEffect(() => {
        fromBudgetForm.setData('contract_id', selectedContractId || '');
        importForm.setData('contract_id', selectedContractId || '');
        manualForm.setData('contract_id', selectedContractId || '');
        additiveBudgetForm.setData('contract_id', selectedContractId || '');
        additiveImportForm.setData('contract_id', selectedContractId || '');
        additiveManualForm.setData('contract_id', selectedContractId || '');
    }, [selectedContractId]);

    const changeContract = (event) => {
        router.get(
            route('tenant.medicao.item.index', tenant.slug),
            {
                contract_id: event.target.value,
                ...itemFilters,
            },
            { preserveScroll: true, preserveState: false },
        );
    };

    const submitItemFilters = (event) => {
        event.preventDefault();

        router.get(
            route('tenant.medicao.item.index', tenant.slug),
            {
                contract_id: selectedContractId || '',
                ...itemFilters,
            },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const clearItemFilters = () => {
        const cleanFilters = {
            item_code: '',
            sheet_item: '',
            additive: '',
            price_order: '',
        };

        setItemFilters(cleanFilters);

        router.get(
            route('tenant.medicao.item.index', tenant.slug),
            { contract_id: selectedContractId || '' },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const submitFromBudget = (event) => {
        event.preventDefault();

        if (!window.confirm('Confirmar importação dos itens deste orçamento finalizado para o contrato selecionado?')) {
            return;
        }

        fromBudgetForm.post(route('tenant.medicao.item.orcamento.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => fromBudgetForm.reset('orcamento_id'),
        });
    };

    const submitImport = (event) => {
        event.preventDefault();

        if (!window.confirm('Confirmar importação da base de itens deste arquivo para o contrato selecionado?')) {
            return;
        }

        importForm.post(route('tenant.medicao.item.import', tenant.slug), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => importForm.reset('file'),
        });
    };

    const submitManual = (event) => {
        event.preventDefault();
        manualForm.post(route('tenant.medicao.item.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () =>
                manualForm.reset(
                    'item',
                    'codigo',
                    'banco',
                    'descricao',
                    'unidade',
                    'quantidade_prevista',
                    'valor_unitario',
                    'valor_com_bdi',
                    'valor_total',
                ),
        });
    };

    const additiveMetaFromForm = (event) => {
        const submittedForm = new FormData(event.currentTarget);

        return {
            additive_title: submittedForm.get('additive_title') || '',
            additive_reason: submittedForm.get('additive_reason') || '',
            effective_at: submittedForm.get('effective_at') || '',
        };
    };

    const submitAdditiveFromBudget = (event) => {
        event.preventDefault();
        const additiveMeta = additiveMetaFromForm(event);

        if (!window.confirm('Confirmar aplicação deste aditivo a partir do orçamento finalizado?')) {
            return;
        }

        additiveBudgetForm.transform((data) => ({ ...data, ...additiveMeta })).post(route('tenant.medicao.item.additive.orcamento.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => additiveBudgetForm.reset('orcamento_id', 'additive_title', 'additive_reason', 'effective_at'),
        });
    };

    const submitAdditiveImport = (event) => {
        event.preventDefault();
        const additiveMeta = additiveMetaFromForm(event);

        if (!window.confirm('Confirmar aplicação deste aditivo a partir do CSV?')) {
            return;
        }

        additiveImportForm.transform((data) => ({ ...data, ...additiveMeta })).post(route('tenant.medicao.item.additive.import', tenant.slug), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => additiveImportForm.reset('file', 'additive_title', 'additive_reason', 'effective_at'),
        });
    };

    const submitAdditiveManual = (event) => {
        event.preventDefault();
        const additiveMeta = additiveMetaFromForm(event);

        if (!window.confirm('Confirmar criação deste aditivo manual?')) {
            return;
        }

        additiveManualForm.transform((data) => ({ ...data, ...additiveMeta })).post(route('tenant.medicao.item.additive.manual', tenant.slug), {
            preserveScroll: true,
            onSuccess: () =>
                additiveManualForm.reset(
                    'item',
                    'codigo',
                    'banco',
                    'descricao',
                    'unidade',
                    'quantidade_prevista',
                    'valor_unitario',
                    'valor_com_bdi',
                    'valor_total',
                    'additive_title',
                    'additive_reason',
                    'effective_at',
                ),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Medição - Item" />

            <section className="sig-content grid gap-5">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <Ruler size={15} />
                            <span className="eyebrow">Medição</span>
                        </div>
                        <h1 className="mt-1 text-xl font-semibold text-[var(--ink-900)]">Itens por contrato</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Cadastre a base que será medida dentro de cada contrato.
                        </p>
                    </div>

                    <div className="min-w-full lg:min-w-80">
                        <Field label="Contrato">
                            <select
                                value={selectedContractId || ''}
                                onChange={changeContract}
                                className="sig-input"
                                disabled={contracts.length === 0}
                            >
                                {contracts.length === 0 ? (
                                    <option value="">Nenhum contrato disponível</option>
                                ) : null}
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>
                                        {contract.code} - {contract.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </div>
                </header>

                {flash.success ? (
                    <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                        <CheckCircle2 size={18} />
                        {flash.success}
                    </div>
                ) : null}

                <div className="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        onClick={() => {
                            setImportOptionsOpen((open) => !open);
                            setAdditiveOptionsOpen(false);
                        }}
                        className="inline-flex items-center gap-2 rounded-lg bg-[var(--primary)] px-4 py-2.5 text-sm font-bold text-white shadow-md shadow-blue-900/10 transition hover:-translate-y-0.5 hover:bg-blue-700 hover:shadow-lg disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span className="flex h-6 w-6 items-center justify-center rounded-md bg-white/15">
                            <Upload size={15} />
                        </span>
                        {importOptionsOpen ? 'Ocultar importação' : 'Importar Item'}
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            setAdditiveOptionsOpen((open) => !open);
                            setImportOptionsOpen(false);
                        }}
                        className="inline-flex items-center gap-2 rounded-lg bg-violet-600 px-4 py-2.5 text-sm font-bold text-white shadow-md shadow-violet-900/10 transition hover:-translate-y-0.5 hover:bg-violet-700 hover:shadow-lg disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span className="flex h-6 w-6 items-center justify-center rounded-md bg-white/15">
                            <GitBranch size={15} />
                        </span>
                        {additiveOptionsOpen ? 'Ocultar aditivo' : 'Aditivo Item'}
                    </button>
                </div>

                {importOptionsOpen ? (
                    <>
                <section className="grid gap-4 md:grid-cols-3">
                    <PanelButton
                        active={activePanel === 'orcamento'}
                        icon={ClipboardList}
                        title="Usar orçamento criado"
                        description="Puxa etapas e itens de um orçamento finalizado, mas grava tudo no contrato escolhido."
                        colorClass="bg-blue-50 text-blue-700"
                        onClick={() => setActivePanel('orcamento')}
                    />
                    <PanelButton
                        active={activePanel === 'importar'}
                        icon={Upload}
                        title="Importar base de itens"
                        description="Importa uma planilha CSV no padrão do relatório sintético."
                        colorClass="bg-amber-50 text-amber-700"
                        onClick={() => setActivePanel('importar')}
                    />
                    <PanelButton
                        active={activePanel === 'manual'}
                        icon={Plus}
                        title="Criar manualmente"
                        description="Cria um item avulso para o contrato quando ele não vier de orçamento ou planilha."
                        colorClass="bg-emerald-50 text-emerald-700"
                        onClick={() => setActivePanel('manual')}
                    />
                </section>

                <section className="sig-card overflow-hidden">
                    {activePanel === 'orcamento' ? (
                        <form onSubmit={submitFromBudget} className="grid gap-4 p-5">
                            <div>
                                <h2 className="text-base font-semibold text-[var(--ink-900)]">Importar de orçamento finalizado</h2>
                                <p className="mt-1 text-sm text-[var(--ink-500)]">
                                    Somente orçamentos finalizados aparecem aqui. O orçamento continua no tenant, mas os itens de medição serão vinculados ao contrato{' '}
                                    {selectedContract ? <strong>{selectedContract.code}</strong> : 'selecionado'}.
                                </p>
                            </div>

                            <div className="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                                <Field label="Orçamento" error={fromBudgetForm.errors.orcamento_id}>
                                    <select
                                        value={fromBudgetForm.data.orcamento_id}
                                        onChange={(event) => fromBudgetForm.setData('orcamento_id', event.target.value)}
                                        className="sig-input"
                                        disabled={!selectedContractId}
                                    >
                                        <option value="">Selecione um orçamento</option>
                                        {orcamentos.map((orcamento) => (
                                            <option key={orcamento.id} value={orcamento.id}>
                                                {orcamento.codigo} - {orcamento.descricao} ({orcamento.itens_count} itens, finalizado em {orcamento.closed_at ?? '-'})
                                            </option>
                                        ))}
                                    </select>
                                    {orcamentos.length === 0 ? (
                                        <span className="text-xs font-semibold text-amber-700">
                                            Nenhum orçamento finalizado disponível para importação.
                                        </span>
                                    ) : null}
                                </Field>

                                <button
                                    type="submit"
                                    disabled={fromBudgetForm.processing || !selectedContractId || orcamentos.length === 0}
                                    className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <Layers3 size={17} />
                                    Confirmar importação
                                </button>
                            </div>
                        </form>
                    ) : null}

                    {activePanel === 'importar' ? (
                        <form onSubmit={submitImport} className="grid gap-5 p-5">
                            <div>
                                <h2 className="text-base font-semibold text-[var(--ink-900)]">Importar base de itens</h2>
                                <p className="mt-1 text-sm text-[var(--ink-500)]">
                                    Use CSV com as colunas do sintético: item, código, banco, descrição, unidade, quantidade,
                                    valor unitário, valor com BDI e total.
                                </p>
                            </div>

                            <div className="grid gap-4 lg:grid-cols-3">
                                <Field label="Arquivo CSV" error={importForm.errors.file}>
                                    <input
                                        type="file"
                                        accept=".csv,.txt,.tsv"
                                        onChange={(event) => importForm.setData('file', event.target.files?.[0] || null)}
                                        className="sig-input"
                                    />
                                </Field>
                                <Field label="Linha do primeiro item" error={importForm.errors.first_item_row}>
                                    <input
                                        type="number"
                                        min="1"
                                        value={importForm.data.first_item_row}
                                        onChange={(event) => importForm.setData('first_item_row', event.target.value)}
                                        className="sig-input"
                                    />
                                </Field>
                                <Field label="Linha do último item" error={importForm.errors.last_item_row}>
                                    <input
                                        type="number"
                                        min="1"
                                        value={importForm.data.last_item_row}
                                        onChange={(event) => importForm.setData('last_item_row', event.target.value)}
                                        className="sig-input"
                                        placeholder="Ex: 250"
                                    />
                                </Field>
                            </div>

                            <div className="rounded-lg border border-[var(--border)] bg-slate-50 p-4">
                                <h3 className="text-sm font-bold text-[var(--ink-900)]">Mapeamento das colunas</h3>
                                <p className="mt-1 text-xs text-[var(--ink-500)]">
                                    Informe apenas a letra da coluna da planilha. O padrão já segue o relatório sintético.
                                </p>
                                <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                    {[
                                        ['item_column', 'Item'],
                                        ['codigo_column', 'Código'],
                                        ['banco_column', 'Banco'],
                                        ['descricao_column', 'Descrição'],
                                        ['unidade_column', 'Unidade'],
                                        ['quantidade_column', 'Quantidade'],
                                        ['valor_unitario_column', 'Valor unitário'],
                                        ['valor_com_bdi_column', 'Valor com BDI'],
                                        ['valor_total_column', 'Total'],
                                    ].map(([field, label]) => (
                                        <Field key={field} label={label} error={importForm.errors[field]}>
                                            <input
                                                type="text"
                                                value={importForm.data[field]}
                                                onChange={(event) =>
                                                    importForm.setData(field, event.target.value.toUpperCase())
                                                }
                                                className="sig-input uppercase"
                                                maxLength="6"
                                            />
                                        </Field>
                                    ))}
                                </div>
                            </div>

                            <div>
                                <button
                                    type="submit"
                                    disabled={importForm.processing || !selectedContractId}
                                    className="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <FileSpreadsheet size={17} />
                                    Confirmar importação do CSV
                                </button>
                            </div>
                        </form>
                    ) : null}

                    {activePanel === 'manual' ? (
                        <form onSubmit={submitManual} className="grid gap-5 p-5">
                            <div>
                                <h2 className="text-base font-semibold text-[var(--ink-900)]">Criar item manual</h2>
                                <p className="mt-1 text-sm text-[var(--ink-500)]">
                                    Crie um item diretamente no contrato, sem depender de orçamento ou arquivo.
                                </p>
                            </div>

                            <div className="grid gap-4 lg:grid-cols-4">
                                <Field label="Item" error={manualForm.errors.item}>
                                    <input
                                        value={manualForm.data.item}
                                        onChange={(event) => manualForm.setData('item', event.target.value)}
                                        className="sig-input"
                                        placeholder="Ex: 1.1"
                                    />
                                </Field>
                                <Field label="Código" error={manualForm.errors.codigo}>
                                    <input
                                        value={manualForm.data.codigo}
                                        onChange={(event) => manualForm.setData('codigo', event.target.value)}
                                        className="sig-input"
                                    />
                                </Field>
                                <Field label="Banco" error={manualForm.errors.banco}>
                                    <input
                                        value={manualForm.data.banco}
                                        onChange={(event) => manualForm.setData('banco', event.target.value.toUpperCase())}
                                        className="sig-input uppercase"
                                        placeholder="SINAPI"
                                    />
                                </Field>
                                <Field label="Unidade" error={manualForm.errors.unidade}>
                                    <input
                                        value={manualForm.data.unidade}
                                        onChange={(event) => manualForm.setData('unidade', event.target.value.toUpperCase())}
                                        className="sig-input uppercase"
                                        placeholder="UN"
                                    />
                                </Field>
                            </div>

                            <Field label="Descrição" error={manualForm.errors.descricao}>
                                <input
                                    value={manualForm.data.descricao}
                                    onChange={(event) => manualForm.setData('descricao', event.target.value)}
                                    className="sig-input"
                                    placeholder="Descrição do item"
                                />
                            </Field>

                            <div className="grid gap-4 lg:grid-cols-4">
                                <Field label="Quantidade prevista" error={manualForm.errors.quantidade_prevista}>
                                    <input
                                        value={manualForm.data.quantidade_prevista}
                                        onChange={(event) =>
                                            manualForm.setData('quantidade_prevista', event.target.value)
                                        }
                                        className="sig-input"
                                        placeholder="0,00"
                                    />
                                </Field>
                                <Field label="Valor unitário" error={manualForm.errors.valor_unitario}>
                                    <input
                                        value={manualForm.data.valor_unitario}
                                        onChange={(event) => manualForm.setData('valor_unitario', event.target.value)}
                                        className="sig-input"
                                        placeholder="0,00"
                                    />
                                </Field>
                                <Field label="Valor com BDI" error={manualForm.errors.valor_com_bdi}>
                                    <input
                                        value={manualForm.data.valor_com_bdi}
                                        onChange={(event) => manualForm.setData('valor_com_bdi', event.target.value)}
                                        className="sig-input"
                                        placeholder="0,00"
                                    />
                                </Field>
                                <Field label="Total" error={manualForm.errors.valor_total}>
                                    <input
                                        value={manualForm.data.valor_total}
                                        onChange={(event) => manualForm.setData('valor_total', event.target.value)}
                                        className="sig-input"
                                        placeholder="Calcula se vazio"
                                    />
                                </Field>
                            </div>

                            <div>
                                <button
                                    type="submit"
                                    disabled={manualForm.processing || !selectedContractId}
                                    className="btn-primary inline-flex items-center gap-2"
                                >
                                    <Plus size={17} />
                                    Criar item
                                </button>
                            </div>
                        </form>
                    ) : null}
                </section>
                    </>
                ) : null}

                {additiveOptionsOpen ? (
                    <>
                        <section className="grid gap-4 md:grid-cols-3">
                            <PanelButton
                                active={activeAdditivePanel === 'orcamento'}
                                icon={ClipboardList}
                                title="Aditivo por orçamento"
                                description="Compara um orçamento finalizado contra os itens atuais do contrato."
                                colorClass="bg-violet-50 text-violet-700"
                                onClick={() => setActiveAdditivePanel('orcamento')}
                            />
                            <PanelButton
                                active={activeAdditivePanel === 'importar'}
                                icon={Upload}
                                title="Aditivo por CSV"
                                description="Importa uma nova planilha de itens e registra apenas as diferenças como versão."
                                colorClass="bg-amber-50 text-amber-700"
                                onClick={() => setActiveAdditivePanel('importar')}
                            />
                            <PanelButton
                                active={activeAdditivePanel === 'manual'}
                                icon={Plus}
                                title="Aditivo manual"
                                description="Cria ou altera um item específico sem precisar importar a base inteira."
                                colorClass="bg-emerald-50 text-emerald-700"
                                onClick={() => setActiveAdditivePanel('manual')}
                            />
                        </section>

                        <section className="sig-card overflow-hidden border-violet-100">
                            {activeAdditivePanel === 'orcamento' ? (
                                <form onSubmit={submitAdditiveFromBudget} className="grid gap-4 p-5">
                                    <div>
                                        <h2 className="text-base font-semibold text-[var(--ink-900)]">Aplicar aditivo por orçamento</h2>
                                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                                            O sistema procura itens pelo número do item. Se houver mudança, grava uma nova versão; se não houver, registra como sem alteração.
                                        </p>
                                    </div>

                                    <AdditiveMetaFields form={additiveBudgetForm} />

                                    <div className="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                                        <Field label="Orçamento fechado" error={additiveBudgetForm.errors.orcamento_id}>
                                            <select
                                                value={additiveBudgetForm.data.orcamento_id}
                                                onChange={(event) => additiveBudgetForm.setData('orcamento_id', event.target.value)}
                                                className="sig-input"
                                                disabled={!selectedContractId}
                                            >
                                                <option value="">Selecione um orçamento</option>
                                                {orcamentos.map((orcamento) => (
                                                    <option key={orcamento.id} value={orcamento.id}>
                                                        {orcamento.codigo} - {orcamento.descricao} ({orcamento.itens_count} itens)
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>

                                        <button
                                            type="submit"
                                            disabled={additiveBudgetForm.processing || !selectedContractId || orcamentos.length === 0}
                                            className="inline-flex items-center justify-center gap-2 rounded-lg bg-violet-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            <GitBranch size={17} />
                                            Aplicar aditivo
                                        </button>
                                    </div>
                                </form>
                            ) : null}

                            {activeAdditivePanel === 'importar' ? (
                                <form onSubmit={submitAdditiveImport} className="grid gap-5 p-5">
                                    <div>
                                        <h2 className="text-base font-semibold text-[var(--ink-900)]">Aplicar aditivo por CSV</h2>
                                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                                            Use a mesma estrutura do sintético. Itens iguais ficam vinculados ao aditivo sem criar nova versão.
                                        </p>
                                    </div>

                                    <AdditiveMetaFields form={additiveImportForm} />

                                    <div className="grid gap-4 lg:grid-cols-3">
                                        <Field label="Arquivo CSV" error={additiveImportForm.errors.file}>
                                            <input
                                                type="file"
                                                accept=".csv,.txt,.tsv"
                                                onChange={(event) => additiveImportForm.setData('file', event.target.files?.[0] || null)}
                                                className="sig-input"
                                            />
                                        </Field>
                                        <Field label="Linha do primeiro item" error={additiveImportForm.errors.first_item_row}>
                                            <input
                                                type="number"
                                                min="1"
                                                value={additiveImportForm.data.first_item_row}
                                                onChange={(event) => additiveImportForm.setData('first_item_row', event.target.value)}
                                                className="sig-input"
                                            />
                                        </Field>
                                        <Field label="Linha do último item" error={additiveImportForm.errors.last_item_row}>
                                            <input
                                                type="number"
                                                min="1"
                                                value={additiveImportForm.data.last_item_row}
                                                onChange={(event) => additiveImportForm.setData('last_item_row', event.target.value)}
                                                className="sig-input"
                                                placeholder="Ex: 250"
                                            />
                                        </Field>
                                    </div>

                                    <div className="rounded-lg border border-[var(--border)] bg-slate-50 p-4">
                                        <h3 className="text-sm font-bold text-[var(--ink-900)]">Mapeamento das colunas</h3>
                                        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                            {[
                                                ['item_column', 'Item'],
                                                ['codigo_column', 'Código'],
                                                ['banco_column', 'Banco'],
                                                ['descricao_column', 'Descrição'],
                                                ['unidade_column', 'Unidade'],
                                                ['quantidade_column', 'Quantidade'],
                                                ['valor_unitario_column', 'Valor unitário'],
                                                ['valor_com_bdi_column', 'Valor com BDI'],
                                                ['valor_total_column', 'Total'],
                                            ].map(([field, label]) => (
                                                <Field key={field} label={label} error={additiveImportForm.errors[field]}>
                                                    <input
                                                        type="text"
                                                        value={additiveImportForm.data[field]}
                                                        onChange={(event) =>
                                                            additiveImportForm.setData(field, event.target.value.toUpperCase())
                                                        }
                                                        className="sig-input uppercase"
                                                        maxLength="6"
                                                    />
                                                </Field>
                                            ))}
                                        </div>
                                    </div>

                                    <div>
                                        <button
                                            type="submit"
                                            disabled={additiveImportForm.processing || !selectedContractId}
                                            className="inline-flex items-center gap-2 rounded-lg bg-violet-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            <FileSpreadsheet size={17} />
                                            Aplicar aditivo do CSV
                                        </button>
                                    </div>
                                </form>
                            ) : null}

                            {activeAdditivePanel === 'manual' ? (
                                <form onSubmit={submitAdditiveManual} className="grid gap-5 p-5">
                                    <div>
                                        <h2 className="text-base font-semibold text-[var(--ink-900)]">Aplicar aditivo manual</h2>
                                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                                            Informe o número do item para alterar um item existente ou crie um novo item vinculado ao aditivo.
                                        </p>
                                    </div>

                                    <AdditiveMetaFields form={additiveManualForm} />

                                    <div className="grid gap-4 lg:grid-cols-4">
                                        <Field label="Item" error={additiveManualForm.errors.item}>
                                            <input
                                                value={additiveManualForm.data.item}
                                                onChange={(event) => additiveManualForm.setData('item', event.target.value)}
                                                className="sig-input"
                                                placeholder="Ex: 1.1"
                                            />
                                        </Field>
                                        <Field label="Código" error={additiveManualForm.errors.codigo}>
                                            <input
                                                value={additiveManualForm.data.codigo}
                                                onChange={(event) => additiveManualForm.setData('codigo', event.target.value)}
                                                className="sig-input"
                                            />
                                        </Field>
                                        <Field label="Banco" error={additiveManualForm.errors.banco}>
                                            <input
                                                value={additiveManualForm.data.banco}
                                                onChange={(event) => additiveManualForm.setData('banco', event.target.value.toUpperCase())}
                                                className="sig-input uppercase"
                                                placeholder="SINAPI"
                                            />
                                        </Field>
                                        <Field label="Unidade" error={additiveManualForm.errors.unidade}>
                                            <input
                                                value={additiveManualForm.data.unidade}
                                                onChange={(event) => additiveManualForm.setData('unidade', event.target.value.toUpperCase())}
                                                className="sig-input uppercase"
                                                placeholder="UN"
                                            />
                                        </Field>
                                    </div>

                                    <Field label="Descrição" error={additiveManualForm.errors.descricao}>
                                        <input
                                            value={additiveManualForm.data.descricao}
                                            onChange={(event) => additiveManualForm.setData('descricao', event.target.value)}
                                            className="sig-input"
                                            placeholder="Descrição do item"
                                        />
                                    </Field>

                                    <div className="grid gap-4 lg:grid-cols-4">
                                        <Field label="Quantidade prevista" error={additiveManualForm.errors.quantidade_prevista}>
                                            <input
                                                value={additiveManualForm.data.quantidade_prevista}
                                                onChange={(event) => additiveManualForm.setData('quantidade_prevista', event.target.value)}
                                                className="sig-input"
                                                placeholder="0,00"
                                            />
                                        </Field>
                                        <Field label="Valor unitário" error={additiveManualForm.errors.valor_unitario}>
                                            <input
                                                value={additiveManualForm.data.valor_unitario}
                                                onChange={(event) => additiveManualForm.setData('valor_unitario', event.target.value)}
                                                className="sig-input"
                                                placeholder="0,00"
                                            />
                                        </Field>
                                        <Field label="Valor com BDI" error={additiveManualForm.errors.valor_com_bdi}>
                                            <input
                                                value={additiveManualForm.data.valor_com_bdi}
                                                onChange={(event) => additiveManualForm.setData('valor_com_bdi', event.target.value)}
                                                className="sig-input"
                                                placeholder="0,00"
                                            />
                                        </Field>
                                        <Field label="Total" error={additiveManualForm.errors.valor_total}>
                                            <input
                                                value={additiveManualForm.data.valor_total}
                                                onChange={(event) => additiveManualForm.setData('valor_total', event.target.value)}
                                                className="sig-input"
                                                placeholder="Calcula se vazio"
                                            />
                                        </Field>
                                    </div>

                                    <div>
                                        <button
                                            type="submit"
                                            disabled={additiveManualForm.processing || !selectedContractId}
                                            className="inline-flex items-center gap-2 rounded-lg bg-violet-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            <GitBranch size={17} />
                                            Aplicar aditivo manual
                                        </button>
                                    </div>
                                </form>
                            ) : null}
                        </section>
                    </>
                ) : null}

                {additives.length > 0 ? (
                    <section className="grid gap-3 rounded-lg border border-[var(--border)] bg-white p-4">
                        <h2 className="text-sm font-bold uppercase tracking-wide text-[var(--ink-500)]">Últimos aditivos</h2>
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            {additives.map((additive) => (
                                <article key={additive.id} className="rounded-lg border border-violet-100 bg-violet-50/50 p-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <strong className="text-sm text-[var(--ink-900)]">Aditivo {additive.number}</strong>
                                        <span className="rounded-full bg-white px-2 py-1 text-xs font-bold text-violet-700">
                                            {additive.items_count} itens
                                        </span>
                                    </div>
                                    <p className="mt-1 text-sm font-semibold text-[var(--ink-900)]">{additive.title}</p>
                                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                                        Vigência: {additive.effective_at || '-'} · Criado em {additive.created_at || '-'}
                                    </p>
                                </article>
                            ))}
                        </div>
                    </section>
                ) : null}

                <section className="sig-card overflow-hidden">
                    <div className="flex flex-col gap-3 border-b border-[var(--border)] px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 className="text-base font-semibold text-[var(--ink-900)]">Itens cadastrados</h2>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                {selectedContract
                                    ? `${selectedContract.code} - ${selectedContract.name}`
                                    : 'Selecione um contrato para listar os itens.'}
                            </p>
                        </div>
                        <div className="grid grid-cols-2 gap-3 text-sm lg:min-w-80">
                            <div className="rounded-lg bg-slate-50 px-3 py-2">
                                <span className="block text-xs font-bold uppercase text-[var(--ink-500)]">Itens</span>
                                <strong className="text-[var(--ink-900)]">{stats.total_items || 0}</strong>
                            </div>
                            <div className="rounded-lg bg-slate-50 px-3 py-2">
                                <span className="block text-xs font-bold uppercase text-[var(--ink-500)]">Total</span>
                                <strong className="text-[var(--ink-900)]">{formatCurrency(stats.total_value)}</strong>
                            </div>
                        </div>
                    </div>

                    <form
                        onSubmit={submitItemFilters}
                        className="grid gap-4 border-b border-[var(--border)] bg-slate-50/70 px-5 py-4 xl:grid-cols-[1fr_170px_190px_220px_auto]"
                    >
                        <Field label="Código / item">
                            <input
                                value={itemFilters.item_code}
                                onChange={(event) =>
                                    setItemFilters((current) => ({ ...current, item_code: event.target.value }))
                                }
                                className="sig-input bg-white"
                                placeholder="Ex: 1, 1.1, 100342"
                            />
                        </Field>

                        <Field label="Planilha">
                            <input
                                value={itemFilters.sheet_item}
                                onChange={(event) =>
                                    setItemFilters((current) => ({ ...current, sheet_item: event.target.value }))
                                }
                                className="sig-input bg-white"
                                placeholder="Ex: 1, 2, 10"
                            />
                        </Field>

                        <Field label="Aditivo">
                            <select
                                value={itemFilters.additive}
                                onChange={(event) =>
                                    setItemFilters((current) => ({ ...current, additive: event.target.value }))
                                }
                                className="sig-input bg-white"
                            >
                                <option value="">Todos</option>
                                <option value="base">Base</option>
                                <option value="aditivo">Aditivo</option>
                            </select>
                        </Field>

                        <Field label="Ordenar por preço">
                            <select
                                value={itemFilters.price_order}
                                onChange={(event) =>
                                    setItemFilters((current) => ({ ...current, price_order: event.target.value }))
                                }
                                className="sig-input bg-white"
                            >
                                <option value="">Ordem de cadastro</option>
                                <option value="desc">Maior preço</option>
                                <option value="asc">Menor preço</option>
                            </select>
                        </Field>

                        <div className="flex flex-wrap items-end gap-2">
                            <button
                                type="submit"
                                disabled={!selectedContractId}
                                className="inline-flex items-center gap-2 rounded-lg bg-[var(--primary)] px-4 py-3 text-sm font-bold text-white transition hover:bg-[var(--primary-700)] disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <Search size={17} />
                                Filtrar
                            </button>
                            <button
                                type="button"
                                onClick={clearItemFilters}
                                disabled={!selectedContractId}
                                className="inline-flex items-center gap-2 rounded-lg border border-[var(--border)] bg-white px-4 py-3 text-sm font-bold text-[var(--ink-700)] transition hover:border-[var(--primary-200)] hover:text-[var(--primary)] disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <RotateCcw size={17} />
                                Limpar
                            </button>
                        </div>
                    </form>

                    {items.length === 0 ? (
                        <div className="grid place-items-center px-5 py-12 text-center">
                            <ClipboardList className="text-[var(--ink-400)]" size={34} />
                            <h3 className="mt-3 text-base font-semibold text-[var(--ink-900)]">
                                Nenhum item cadastrado neste contrato
                            </h3>
                            <p className="mt-1 max-w-xl text-sm text-[var(--ink-500)]">
                                Use uma das três opções acima para montar a base de medição do contrato.
                            </p>
                        </div>
                    ) : (
                        <>
                            <div className="hidden lg:block">
                                <div className="grid grid-cols-[80px_110px_90px_1fr_80px_120px_130px_130px_120px] bg-slate-950 px-5 py-3 text-xs font-bold uppercase tracking-wide text-white">
                                    <span>Item</span>
                                    <span>Código</span>
                                    <span>Banco</span>
                                    <span>Descrição</span>
                                    <span>Und.</span>
                                    <span className="text-right">Quant.</span>
                                    <span className="text-right">Valor BDI</span>
                                    <span className="text-right">Total</span>
                                    <span className="text-right">Origem</span>
                                </div>
                                {items.map((item) => {
                                    const isHeader = item.is_header || item.item_type === 'etapa';

                                    return (
                                        <div
                                            key={item.id}
                                            className={`grid grid-cols-[80px_110px_90px_1fr_80px_120px_130px_130px_120px] items-center border-b border-[var(--border)] px-5 py-3 text-sm ${
                                                isHeader ? 'bg-sky-50 font-semibold' : 'bg-white'
                                            }`}
                                        >
                                            <span>
                                                <button
                                                    type="button"
                                                    onClick={() => setSelectedHistoryItem(item)}
                                                    className="font-bold text-[var(--primary)] underline-offset-4 transition hover:underline"
                                                >
                                                    {item.item || '-'}
                                                </button>
                                            </span>
                                            <span>{item.codigo || '-'}</span>
                                            <span>{item.banco || '-'}</span>
                                            <span>{item.descricao}</span>
                                            <span>{item.unidade || '-'}</span>
                                            <span className="text-right">{formatDecimal(item.quantidade_prevista)}</span>
                                            <span className="text-right">{formatCurrencyOrDash(item.valor_com_bdi)}</span>
                                            <span className="text-right font-semibold">{formatCurrency(item.valor_total)}</span>
                                            <span className="text-right text-xs font-bold uppercase text-[var(--ink-500)]">
                                                {item.source_label}
                                                <small className="mt-1 block normal-case text-violet-700">
                                                    {item.version_label || 'Base'}
                                                </small>
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>

                            <div className="grid gap-3 p-4 lg:hidden">
                                {items.map((item) => (
                                    <article
                                        key={item.id}
                                        className={`rounded-lg border border-[var(--border)] p-4 ${
                                            item.nivel === 1 ? 'bg-sky-50' : 'bg-white'
                                        }`}
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <span className="text-xs font-bold uppercase text-[var(--ink-500)]">
                                                    <button
                                                        type="button"
                                                        onClick={() => setSelectedHistoryItem(item)}
                                                        className="font-bold text-[var(--primary)] underline-offset-4 hover:underline"
                                                    >
                                                        {item.item || 'Sem item'}
                                                    </button>{' '}
                                                    · {item.source_label} · {item.version_label || 'Base'}
                                                </span>
                                                <h3 className="mt-1 text-sm font-bold text-[var(--ink-900)]">
                                                    {item.descricao}
                                                </h3>
                                            </div>
                                            <strong className="text-sm text-[var(--ink-900)]">
                                                {formatCurrency(item.valor_total)}
                                            </strong>
                                        </div>
                                        <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-[var(--ink-500)]">
                                            <span>Código: {item.codigo || '-'}</span>
                                            <span>Banco: {item.banco || '-'}</span>
                                            <span>Und.: {item.unidade || '-'}</span>
                                            <span>Qtd.: {formatDecimal(item.quantidade_prevista)}</span>
                                        </div>
                                    </article>
                                ))}
                            </div>
                        </>
                    )}
                </section>
            </section>

            <ItemAdditiveHistoryModal item={selectedHistoryItem} onClose={() => setSelectedHistoryItem(null)} />
        </AuthenticatedLayout>
    );
}
