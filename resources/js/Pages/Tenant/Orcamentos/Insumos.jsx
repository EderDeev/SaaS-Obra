import { router, useForm, usePage } from '@inertiajs/react';
import ConfirmActionButton from '@/Components/ConfirmActionButton';
import { Building2, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, Clock3, Filter, FolderTree, Globe2, Pencil, Plus, Search, Trash2, UploadCloud, X } from 'lucide-react';
import { cloneElement, useEffect, useMemo, useState } from 'react';
import OrcamentoShell from './Partials/OrcamentoShell';

const states = [
    { value: 'AC', label: 'Acre' },
    { value: 'AL', label: 'Alagoas' },
    { value: 'AP', label: 'Amapa' },
    { value: 'AM', label: 'Amazonas' },
    { value: 'BA', label: 'Bahia' },
    { value: 'CE', label: 'Ceara' },
    { value: 'DF', label: 'Distrito Federal' },
    { value: 'ES', label: 'Espirito Santo' },
    { value: 'GO', label: 'Goias' },
    { value: 'MA', label: 'Maranhao' },
    { value: 'MT', label: 'Mato Grosso' },
    { value: 'MS', label: 'Mato Grosso do Sul' },
    { value: 'MG', label: 'Minas Gerais' },
    { value: 'PA', label: 'Para' },
    { value: 'PB', label: 'Paraiba' },
    { value: 'PR', label: 'Parana' },
    { value: 'PE', label: 'Pernambuco' },
    { value: 'PI', label: 'Piaui' },
    { value: 'RJ', label: 'Rio de Janeiro' },
    { value: 'RN', label: 'Rio Grande do Norte' },
    { value: 'RS', label: 'Rio Grande do Sul' },
    { value: 'RO', label: 'Rondonia' },
    { value: 'RR', label: 'Roraima' },
    { value: 'SC', label: 'Santa Catarina' },
    { value: 'SP', label: 'Sao Paulo' },
    { value: 'SE', label: 'Sergipe' },
    { value: 'TO', label: 'Tocantins' },
];

const banks = [
    { value: 'SINAPI', label: 'SINAPI' },
    { value: 'SICRO3', label: 'SICRO3' },
    { value: 'PROPRIA', label: 'Base propria' },
];

const types = [
    { value: '', label: 'Nao classificado' },
    { value: 'material', label: 'Material' },
    { value: 'labor', label: 'Mao de obra' },
    { value: 'equipment', label: 'Equipamento' },
    { value: 'service', label: 'Servico' },
];

const typeLabels = {
    material: 'Material',
    labor: 'Mao de obra',
    equipment: 'Equipamento',
    service: 'Servico',
};

export default function OrcamentosInsumos({
    tenant,
    filters: initialFilters = {},
    hasSearched = false,
    insumos = [],
    totalInsumos = 0,
    insumoSummary = { total: 0, types: [] },
    typeOptions = [],
    typeOptionsByBank = {},
    dateOptions = [],
    grupoOptions = [],
    grupos = [],
    canManageTenantInsumos = false,
    canManageGlobalInsumos = false,
    canImportTenantInsumos = false,
}) {
    const page = usePage();
    const insumoRows = insumos?.data ?? insumos;
    const pagination = insumos?.data ? insumos : null;
    const [activePanel, setActivePanel] = useState(null);
    const [createModalOpen, setCreateModalOpen] = useState(false);
    const [filters, setFilters] = useState({
        search: initialFilters.search ?? '',
        bank: initialFilters.bank ?? 'SINAPI',
        orderBy: initialFilters.orderBy ?? 'description',
        state: initialFilters.state ?? 'PA',
        type: initialFilters.type ?? 'all',
        date: initialFilters.date ?? '',
        perPage: initialFilters.perPage ?? 50,
    });

    const createForm = useForm({
        scope: 'tenant',
        tipo: '',
        grupo_id: '',
        codigo_insumo: '',
        descricao: '',
        unidade: '',
        uf: 'PA',
        preco_nao_desonerado: '',
        preco_desonerado: '',
        custo_improdutivo_nao_desonerado: '',
        custo_improdutivo_desonerado: '',
        data: '04/2026',
        observacao: '',
    });

    const tenantImportForm = useForm({
        scope: 'tenant',
        file: null,
        first_item_row: '2',
        last_item_row: '',
        data: currentMonthReference(),
        tipo_column: '',
        codigo_column: '',
        grupo_column: '',
        descricao_column: '',
        unidade_column: '',
        preco_desonerado_column: '',
        preco_nao_desonerado_column: '',
    });

    const globalImportForm = useForm({
        scope: 'global',
        banco: 'SINAPI',
        file: null,
        first_item_row: '2',
        last_item_row: '',
        codigo_insumo_column: '',
        classificacao_column: '',
        descricao_column: '',
        unidade_column: '',
        uf_column: '',
        origem_preco_column: '',
        preco_nao_desonerado_column: '',
        preco_desonerado_column: '',
        custo_improdutivo_nao_desonerado_column: '',
        custo_improdutivo_desonerado_column: '',
        data_column: '',
    });

    const visiblePanels = useMemo(() => ({
        create: canManageTenantInsumos,
        groups: canManageTenantInsumos,
        importTenant: canImportTenantInsumos,
        importGlobal: canManageGlobalInsumos,
    }), [canImportTenantInsumos, canManageGlobalInsumos, canManageTenantInsumos]);
    const [currentImportLabel, setCurrentImportLabel] = useState(null);
    const activeImportForm = tenantImportForm.processing
        ? tenantImportForm
        : globalImportForm.processing
            ? globalImportForm
            : null;

    const updateFilter = (field, value) => {
        setFilters((current) => ({ ...current, [field]: value }));
    };

    const submitSearch = (event) => {
        event.preventDefault();

        router.get(route('tenant.orcamentos.insumos.index', tenant.slug), { ...filters, searched: 1 }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const clearFilters = () => {
        const defaults = {
            search: '',
            bank: 'SINAPI',
            orderBy: 'description',
            state: 'PA',
            type: 'all',
            date: '',
            perPage: 50,
        };

        setFilters(defaults);
        router.get(route('tenant.orcamentos.insumos.index', tenant.slug), {}, {
            preserveScroll: true,
            replace: true,
        });
    };

    const submitCreate = (event) => {
        event.preventDefault();

        createForm.post(route('tenant.orcamentos.insumos.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset();
                setCreateModalOpen(false);
            },
        });
    };

    const submitImport = (event, form, label) => {
        event.preventDefault();
        setCurrentImportLabel(label);

        form.post(route('tenant.orcamentos.insumos.import', tenant.slug), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setActivePanel(null);
            },
            onFinish: () => setCurrentImportLabel(null),
        });
    };

    const togglePanel = (panel) => {
        setCreateModalOpen(false);
        setActivePanel((current) => (current === panel ? null : panel));
    };

    const openCreateModal = () => {
        setActivePanel(null);
        setCreateModalOpen(true);
    };

    const closeCreateModal = () => {
        if (createForm.processing) {
            return;
        }

        createForm.reset();
        createForm.clearErrors();
        setCreateModalOpen(false);
    };

    return (
        <OrcamentoShell
            tenant={tenant}
            active="insumos"
            title="Insumos"
            subtitle="Consulte insumos das bases de referência por estado, tipo e data."
            showNav={false}
            eyebrow="Orçamentos · Bases de preço"
            actions={(
                <>
                    {visiblePanels.groups && (
                        <ActionButton active={activePanel === 'groups'} icon={FolderTree} onClick={() => togglePanel('groups')}>
                            Grupos
                        </ActionButton>
                    )}
                    {visiblePanels.importTenant && (
                        <ActionButton active={activePanel === 'importTenant'} icon={Building2} onClick={() => togglePanel('importTenant')}>
                            Importar base própria
                        </ActionButton>
                    )}
                    {visiblePanels.importGlobal && (
                        <ActionButton active={activePanel === 'importGlobal'} icon={Globe2} onClick={() => togglePanel('importGlobal')}>
                            Importar CSV global
                        </ActionButton>
                    )}
                    {visiblePanels.create && (
                        <ActionButton active={createModalOpen} icon={Plus} primary onClick={openCreateModal}>
                            Novo insumo
                        </ActionButton>
                    )}
                </>
            )}
        >
            {page.props.flash?.success && (
                <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                    {page.props.flash.success}
                </div>
            )}

            {activeImportForm && (
                <ImportProgressOverlay form={activeImportForm} title={currentImportLabel ?? 'Importando arquivo'} />
            )}

            {page.props.flash?.import_result && (
                <ImportResultFeedback result={page.props.flash.import_result} />
            )}

            {createModalOpen && (
                <CreateInsumoModal
                    form={createForm}
                    grupoOptions={grupoOptions}
                    onClose={closeCreateModal}
                    onSubmit={submitCreate}
                />
            )}

            {activePanel === 'groups' && (
                <InsumoGroupsPanel
                    grupos={grupos}
                    onClose={() => setActivePanel(null)}
                    tenant={tenant}
                />
            )}

            {activePanel === 'importTenant' && (
                <ImportOwnInsumoPanel
                    form={tenantImportForm}
                    onClose={() => setActivePanel(null)}
                    onSubmit={(event) => submitImport(event, tenantImportForm, 'Importacao de base propria')}
                />
            )}

            {activePanel === 'importGlobal' && (
                <ImportInsumoPanel
                    description="Os itens importados ficarao disponiveis para toda a plataforma. Use apenas para bases oficiais ou corporativas."
                    form={globalImportForm}
                    icon={Globe2}
                    onClose={() => setActivePanel(null)}
                    onSubmit={(event) => submitImport(event, globalImportForm, 'Importacao global de insumos')}
                    title="Importar insumos globais"
                />
            )}

            <SearchPanel
                dateOptions={dateOptions}
                filters={filters}
                onChange={updateFilter}
                onClear={clearFilters}
                onSubmit={submitSearch}
                summary={insumoSummary}
                typeOptions={typeOptions}
                typeOptionsByBank={typeOptionsByBank}
            />

            <InsumosList
                filters={filters}
                hasSearched={hasSearched}
                insumos={insumoRows}
                pagination={pagination}
                setFilters={setFilters}
                tenant={tenant}
                totalInsumos={insumoSummary?.total ?? totalInsumos}
            />
        </OrcamentoShell>
    );
}

function SearchPanel({
    dateOptions = [],
    filters,
    onChange,
    onClear,
    onSubmit,
    summary = { total: 0, types: [] },
    typeOptions = [],
    typeOptionsByBank = {},
}) {
    const bankTypeOptions = typeOptionsByBank?.[filters.bank] ?? typeOptions;
    const normalizedTypeOptions = ensureSelectedOption(bankTypeOptions, filters.type, 'Tipo selecionado');
    const normalizedDateOptions = ensureSelectedOption(dateOptions, filters.date, filters.date);
    const summaryTypes = summary?.types?.length
        ? summary.types
        : normalizedTypeOptions.map((type) => ({ ...type, count: null }));

    return (
        <section className="mb-5">
            <div
                className="mb-4 flex gap-2 overflow-x-auto pb-1 [&::-webkit-scrollbar]:hidden sm:flex-wrap sm:overflow-visible"
                style={{ scrollbarWidth: 'none' }}
            >
                <TypeChip
                    active={filters.type === 'all'}
                    count={summary?.total}
                    label="Todos"
                    onClick={() => onChange('type', 'all')}
                />
                {summaryTypes.map((type) => (
                    <TypeChip
                        key={type.value}
                        active={String(filters.type) === String(type.value)}
                        count={type.count}
                        label={type.label}
                        onClick={() => onChange('type', type.value)}
                    />
                ))}
            </div>

            <form className="grid gap-3 lg:grid-cols-12" onSubmit={onSubmit}>
                <label className="relative block lg:col-span-4">
                    <span className="sr-only">Buscar por descrição ou código</span>
                    <Search className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--ink-400)]" size={17} />
                    <input
                        className="sig-input h-[52px] w-full pl-11"
                        style={{ paddingLeft: 42 }}
                        value={filters.search}
                        onChange={(event) => onChange('search', event.target.value)}
                        placeholder="Buscar por descrição ou código"
                    />
                </label>

                <CompactSelect
                    className="lg:col-span-2"
                    label="Banco"
                    value={filters.bank}
                    onChange={(value) => {
                        onChange('bank', value);
                        onChange('type', 'all');
                    }}
                >
                    <option value="TODOS">Todos</option>
                    {banks.map((bank) => <option key={bank.value} value={bank.value}>{bank.label}</option>)}
                </CompactSelect>

                <CompactSelect
                    className="lg:col-span-2"
                    label="Estado"
                    value={filters.state}
                    onChange={(value) => onChange('state', value)}
                >
                    {states.map((state) => <option key={state.value} value={state.value}>{state.label}</option>)}
                </CompactSelect>

                <CompactSelect
                    className="lg:col-span-2"
                    label="Data de referência"
                    value={filters.date}
                    onChange={(value) => onChange('date', value)}
                >
                    <option value="">Todas</option>
                    {normalizedDateOptions.map((date) => <option key={date.value} value={date.value}>{date.label}</option>)}
                </CompactSelect>

                <CompactSelect
                    className="lg:col-span-2"
                    label="Ordenar por"
                    value={filters.orderBy}
                    onChange={(value) => onChange('orderBy', value)}
                >
                    <option value="description">Descrição (A-Z)</option>
                    <option value="code">Código</option>
                    <option value="unit">Unidade</option>
                    <option value="price">Maior preço</option>
                </CompactSelect>

                <div className="flex flex-wrap items-center justify-end gap-2 lg:col-span-12">
                    <button className="sig-btn sig-btn-ghost" type="button" onClick={onClear}>
                        Limpar filtros
                    </button>
                    <button className="sig-btn sig-btn-primary min-w-32 justify-center" type="submit">
                        <Filter size={15} />
                        Filtrar
                    </button>
                </div>
            </form>
        </section>
    );
}

function TypeChip({ active, count, label, onClick }) {
    return (
        <button
            className={`inline-flex min-h-9 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border px-4 text-sm transition ${
                active
                    ? 'border-[var(--border-strong)] bg-[var(--primary-50)] font-semibold text-[var(--ink-900)]'
                    : 'border-[var(--border)] bg-white text-[var(--ink-700)] hover:border-[var(--border-strong)]'
            }`}
            type="button"
            onClick={onClick}
        >
            <span>{label}</span>
            {count !== null && count !== undefined && (
                <span className="font-mono text-xs text-[var(--ink-400)]">{formatCount(count)}</span>
            )}
        </button>
    );
}

function CompactSelect({ children, className = '', label, onChange, value }) {
    return (
        <label
            className={`sig-input relative h-[52px] min-w-0 flex-col justify-center gap-0.5 py-1.5 pl-3.5 pr-9 ${className}`}
            style={{ alignItems: 'stretch' }}
        >
            <span className="pointer-events-none block text-[9.5px] font-bold uppercase leading-[1.2] tracking-[0.05em] text-[var(--ink-400)]">
                {label}
            </span>
            <select
                className="m-0 h-5 min-h-5 w-full appearance-none border-0 bg-transparent p-0 text-sm font-semibold leading-5 text-[var(--ink-900)] outline-none focus:border-0 focus:ring-0"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {children}
            </select>
            <ChevronDown
                aria-hidden="true"
                className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[var(--ink-400)]"
                size={15}
            />
        </label>
    );
}

function ensureSelectedOption(options, selectedValue, fallbackLabel) {
    if (!selectedValue || selectedValue === 'all') {
        return options;
    }

    if (options.some((option) => String(option.value) === String(selectedValue))) {
        return options;
    }

    return [
        { value: selectedValue, label: fallbackLabel || selectedValue },
        ...options,
    ];
}

function ImportProgressOverlay({ form, title }) {
    const progress = Math.max(0, Math.min(100, Math.round(form.progress?.percentage ?? 100)));
    const isUploading = progress < 100;

    return (
        <section className="mb-5 overflow-hidden rounded-lg border border-blue-200 bg-white shadow-[var(--shadow-sm)]">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-blue-100 bg-blue-50 px-4 py-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-white text-blue-700 shadow-sm">
                        {isUploading ? <UploadCloud size={17} /> : <Clock3 size={17} />}
                    </span>
                    <div>
                        <h2 className="text-sm font-bold text-[var(--ink-900)]">{title}</h2>
                        <p className="mt-0.5 text-xs text-[var(--ink-500)]">
                            {isUploading
                                ? 'Enviando arquivo para o servidor.'
                                : 'Upload concluido. Processando e gravando os lotes no PostgreSQL.'}
                        </p>
                    </div>
                </div>
                <span className="rounded-full bg-white px-3 py-1 text-xs font-bold text-blue-700">
                    {isUploading ? `${progress}%` : 'Processando'}
                </span>
            </header>

            <div className="p-4">
                <div className="h-3 overflow-hidden rounded-full bg-blue-100">
                    <div
                        className="h-full rounded-full bg-blue-600 transition-all duration-300"
                        style={{ width: `${progress}%` }}
                    />
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-[var(--ink-500)]">
                    <CheckCircle2 size={14} className={progress >= 100 ? 'text-emerald-600' : 'text-blue-600'} />
                    <span>
                        Mantenha esta tela aberta ate o resumo final aparecer.
                    </span>
                </div>
            </div>
        </section>
    );
}

function ImportResultFeedback({ result }) {
    const duplicated = Number(result.duplicated ?? result.duplicates ?? 0);
    const total = Number(result.read ?? 0) || Number(result.created ?? 0) + Number(result.updated ?? 0) + duplicated + Number(result.skipped ?? 0);
    const metrics = [
        { label: 'Criados', value: result.created ?? 0, tone: 'text-emerald-700 bg-emerald-50 border-emerald-200' },
        { label: 'Atualizados', value: result.updated ?? 0, tone: 'text-blue-700 bg-blue-50 border-blue-200' },
        { label: 'Duplicados ignorados', value: duplicated, tone: 'text-violet-700 bg-violet-50 border-violet-200' },
        { label: 'Invalidos ignorados', value: result.skipped ?? 0, tone: 'text-amber-700 bg-amber-50 border-amber-200' },
        { label: 'Total lido', value: total, tone: 'text-[var(--ink-700)] bg-[var(--surface-muted)] border-[var(--border)]' },
    ];

    return (
        <section className="mb-5 rounded-lg border border-emerald-200 bg-white p-4 shadow-[var(--shadow-sm)]">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-bold text-[var(--ink-900)]">{result.title ?? 'Resumo da importacao'}</h2>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                        Escopo: <strong>{result.scope_label ?? '-'}</strong>
                        {' '}| Base: <strong>{result.base ?? '-'}</strong>
                    </p>
                </div>
                <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
                    Concluido
                </span>
            </div>

            <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                {metrics.map((metric) => (
                    <div key={metric.label} className={`rounded-lg border px-4 py-3 ${metric.tone}`}>
                        <span className="block text-[11px] font-bold uppercase tracking-[0.08em]">{metric.label}</span>
                        <strong className="mt-1 block text-2xl">{metric.value}</strong>
                    </div>
                ))}
            </div>

            {duplicated > 0 && result.duplicate_note && (
                <div className="mt-3 rounded-lg border border-violet-200 bg-violet-50 px-4 py-3 text-xs leading-5 text-violet-800">
                    {result.duplicate_note}
                </div>
            )}
        </section>
    );
}

function InsumoGroupsPanel({ grupos = [], onClose, tenant }) {
    const [editingId, setEditingId] = useState(null);
    const createForm = useForm({
        nome: '',
        descricao: '',
    });
    const editForm = useForm({
        nome: '',
        descricao: '',
    });

    const submitCreate = (event) => {
        event.preventDefault();

        createForm.post(route('tenant.orcamentos.insumos.grupos.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => createForm.reset(),
        });
    };

    const startEdit = (grupo) => {
        setEditingId(grupo.id);
        editForm.setData({
            nome: grupo.nome ?? '',
            descricao: grupo.descricao ?? '',
        });
    };

    const cancelEdit = () => {
        setEditingId(null);
        editForm.reset();
        editForm.clearErrors();
    };

    const submitEdit = (event, grupo) => {
        event.preventDefault();

        editForm.patch(route('tenant.orcamentos.insumos.grupos.update', [tenant.slug, grupo.id]), {
            preserveScroll: true,
            onSuccess: cancelEdit,
        });
    };

    return (
        <section className="sig-card mb-5 overflow-hidden">
            <PanelHeader
                description="Organize os insumos proprios por grupos, como materiais hidraulicos, equipamentos ou familias de servicos."
                icon={FolderTree}
                onClose={onClose}
                title="Grupos de insumos"
            />

            <div className="grid gap-5 p-5 xl:grid-cols-[minmax(280px,360px)_1fr]">
                <form className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4" onSubmit={submitCreate}>
                    <h3 className="text-sm font-semibold text-[var(--ink-900)]">Novo grupo</h3>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">Os grupos criados aqui aparecem no cadastro manual de insumo.</p>

                    <div className="mt-4 grid gap-3">
                        <Field label="Nome" error={createForm.errors.nome}>
                            <input value={createForm.data.nome} onChange={(event) => createForm.setData('nome', event.target.value)} placeholder="Ex: Equipamentos de compactacao" />
                        </Field>
                        <Field label="Descricao" error={createForm.errors.descricao}>
                            <textarea
                                value={createForm.data.descricao}
                                onChange={(event) => createForm.setData('descricao', event.target.value)}
                                placeholder="Opcional"
                                style={{ minHeight: 78, paddingTop: 10, resize: 'vertical' }}
                            />
                        </Field>
                    </div>

                    <div className="mt-4 flex justify-end">
                        <button className="sig-btn sig-btn-primary" disabled={createForm.processing} type="submit">
                            <Plus size={15} />
                            {createForm.processing ? 'Salvando...' : 'Criar grupo'}
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-[var(--border)] bg-white">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-4 py-3">
                        <div>
                            <h3 className="text-sm font-semibold text-[var(--ink-900)]">Grupos cadastrados</h3>
                            <p className="mt-1 text-xs text-[var(--ink-500)]">{grupos.length} grupo(s) ativo(s)</p>
                        </div>
                    </header>

                    {grupos.length === 0 ? (
                        <div className="p-6 text-sm text-[var(--ink-500)]">
                            Nenhum grupo cadastrado ainda.
                        </div>
                    ) : (
                        <div className="divide-y divide-[var(--border)]">
                            {grupos.map((grupo) => {
                                const isEditing = editingId === grupo.id;

                                return (
                                    <article key={grupo.id} className="p-4">
                                        {isEditing ? (
                                            <form className="grid gap-3" onSubmit={(event) => submitEdit(event, grupo)}>
                                                <div className="grid gap-3 lg:grid-cols-2">
                                                    <Field label="Nome" error={editForm.errors.nome}>
                                                        <input value={editForm.data.nome} onChange={(event) => editForm.setData('nome', event.target.value)} />
                                                    </Field>
                                                    <Field label="Descricao" error={editForm.errors.descricao}>
                                                        <input value={editForm.data.descricao} onChange={(event) => editForm.setData('descricao', event.target.value)} placeholder="Opcional" />
                                                    </Field>
                                                </div>
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    <button className="sig-btn sig-btn-secondary" type="button" onClick={cancelEdit}>Cancelar</button>
                                                    <button className="sig-btn sig-btn-primary" disabled={editForm.processing} type="submit">
                                                        {editForm.processing ? 'Salvando...' : 'Salvar grupo'}
                                                    </button>
                                                </div>
                                            </form>
                                        ) : (
                                            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h4 className="text-sm font-semibold text-[var(--ink-900)]">{grupo.nome}</h4>
                                                        <span className="rounded-full bg-[var(--primary-50)] px-2 py-0.5 text-[10px] font-bold text-[var(--primary)]">
                                                            {grupo.insumos_count ?? 0} insumo(s)
                                                        </span>
                                                    </div>
                                                    {grupo.descricao && (
                                                        <p className="mt-1 text-xs leading-5 text-[var(--ink-500)]">{grupo.descricao}</p>
                                                    )}
                                                </div>
                                                <div className="flex flex-wrap gap-2">
                                                    <button className="sig-btn sig-btn-secondary sig-btn-sm" type="button" onClick={() => startEdit(grupo)}>
                                                        <Pencil size={13} />
                                                        Editar
                                                    </button>
                                                    <ConfirmActionButton
                                                        title="Excluir grupo"
                                                        message={`Deseja mesmo excluir o grupo ${grupo.nome}? Os insumos vinculados ficarao sem grupo, mas o historico sera mantido.`}
                                                        confirmLabel="Excluir grupo"
                                                        onConfirm={() => router.delete(route('tenant.orcamentos.insumos.grupos.destroy', [tenant.slug, grupo.id]), { preserveScroll: true })}
                                                    >
                                                        <Trash2 size={13} />
                                                        Excluir
                                                    </ConfirmActionButton>
                                                </div>
                                            </div>
                                        )}
                                    </article>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>
        </section>
    );
}

function CreateInsumoModal({ form, grupoOptions = [], onClose, onSubmit }) {
    const isEquipment = form.data.tipo === 'equipment';
    const updateTipo = (value) => {
        form.setData({
            ...form.data,
            tipo: value,
            custo_improdutivo_nao_desonerado: value === 'equipment' ? form.data.custo_improdutivo_nao_desonerado : '',
            custo_improdutivo_desonerado: value === 'equipment' ? form.data.custo_improdutivo_desonerado : '',
        });
    };

    useEffect(() => {
        const previousOverflow = document.body.style.overflow;
        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [onClose]);

    return (
        <div
            aria-labelledby="create-insumo-title"
            aria-modal="true"
            className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/45 p-4"
            role="dialog"
            onMouseDown={onClose}
        >
            <section
                className="flex max-h-[calc(100vh-2rem)] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex shrink-0 items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                            <Plus size={17} />
                        </span>
                        <div className="min-w-0">
                            <h2 id="create-insumo-title" className="text-[17px] font-semibold text-[var(--ink-900)]">
                                Criar insumo
                            </h2>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                Cadastre um insumo na base própria.
                            </p>
                        </div>
                    </div>
                    <button
                        aria-label="Fechar"
                        className="sig-btn sig-btn-ghost h-9 w-9 shrink-0 p-0"
                        disabled={form.processing}
                        title="Fechar"
                        type="button"
                        onClick={onClose}
                    >
                        <X size={17} />
                    </button>
                </header>

                <form className="flex min-h-0 flex-1 flex-col" onSubmit={onSubmit}>
                    <div className="grid min-h-0 gap-4 overflow-y-auto p-5">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label="Tipo" error={form.errors.tipo}>
                                <select value={form.data.tipo} onChange={(event) => updateTipo(event.target.value)}>
                                    {types.map((type) => <option key={type.value || 'empty'} value={type.value}>{type.label}</option>)}
                                </select>
                            </Field>

                            <Field label="Grupo" error={form.errors.grupo_id}>
                                <select value={form.data.grupo_id} onChange={(event) => form.setData('grupo_id', event.target.value)}>
                                    <option value="">Sem grupo</option>
                                    {grupoOptions.map((grupo) => <option key={grupo.value} value={grupo.value}>{grupo.label}</option>)}
                                </select>
                            </Field>

                            <Field label="Código do insumo" error={form.errors.codigo_insumo}>
                                <input value={form.data.codigo_insumo} onChange={(event) => form.setData('codigo_insumo', event.target.value)} />
                            </Field>

                            <Field label="Unidade" error={form.errors.unidade}>
                                <input value={form.data.unidade} onChange={(event) => form.setData('unidade', event.target.value.toUpperCase())} placeholder="UN, M, KG..." />
                            </Field>
                        </div>

                        <Field label="Descrição" error={form.errors.descricao}>
                            <input
                                value={form.data.descricao}
                                onChange={(event) => form.setData('descricao', event.target.value)}
                                placeholder="Descrição do insumo"
                            />
                        </Field>

                        <Field label="Observação" error={form.errors.observacao}>
                            <textarea
                                value={form.data.observacao}
                                onChange={(event) => form.setData('observacao', event.target.value)}
                                placeholder="Observação opcional sobre o insumo"
                                style={{ minHeight: 72, paddingTop: 10, resize: 'vertical' }}
                            />
                        </Field>

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label="UF" error={form.errors.uf}>
                                <select value={form.data.uf} onChange={(event) => form.setData('uf', event.target.value)}>
                                    {states.map((state) => <option key={state.value} value={state.value}>{state.value} - {state.label}</option>)}
                                </select>
                            </Field>

                            <Field label="Preço não desonerado" error={form.errors.preco_nao_desonerado}>
                                <input
                                    inputMode="numeric"
                                    value={form.data.preco_nao_desonerado}
                                    onChange={(event) => setMoneyField(form, 'preco_nao_desonerado', event.target.value)}
                                    placeholder="100.000,00"
                                />
                            </Field>

                            <Field label="Preço desonerado (opcional)" error={form.errors.preco_desonerado}>
                                <input
                                    inputMode="numeric"
                                    value={form.data.preco_desonerado}
                                    onChange={(event) => setMoneyField(form, 'preco_desonerado', event.target.value)}
                                    placeholder="Opcional"
                                />
                            </Field>

                            <Field label="Data" error={form.errors.data}>
                                <input value={form.data.data} onChange={(event) => form.setData('data', event.target.value)} placeholder="04/2026" />
                            </Field>
                        </div>

                        {isEquipment && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Valor não desonerado improdutivo" error={form.errors.custo_improdutivo_nao_desonerado}>
                                    <input
                                        inputMode="numeric"
                                        value={form.data.custo_improdutivo_nao_desonerado}
                                        onChange={(event) => setMoneyField(form, 'custo_improdutivo_nao_desonerado', event.target.value)}
                                        placeholder="Opcional"
                                    />
                                </Field>

                                <Field label="Valor desonerado improdutivo" error={form.errors.custo_improdutivo_desonerado}>
                                    <input
                                        inputMode="numeric"
                                        value={form.data.custo_improdutivo_desonerado}
                                        onChange={(event) => setMoneyField(form, 'custo_improdutivo_desonerado', event.target.value)}
                                        placeholder="Opcional"
                                    />
                                </Field>
                            </div>
                        )}
                    </div>

                    <footer className="flex shrink-0 flex-wrap justify-end gap-2 border-t border-[var(--border)] bg-white px-5 py-4">
                        <button className="sig-btn sig-btn-secondary" disabled={form.processing} type="button" onClick={onClose}>
                            Cancelar
                        </button>
                        <button className="sig-btn sig-btn-primary" disabled={form.processing} type="submit">
                            <Plus size={15} />
                            {form.processing ? 'Salvando...' : 'Salvar insumo'}
                        </button>
                    </footer>
                </form>
            </section>
        </div>
    );
}

function ImportOwnInsumoPanel({ form, onClose, onSubmit }) {
    return (
        <section className="sig-card mb-5 overflow-hidden">
            <header className="border-b border-[var(--border)]">
                <div className="bg-[var(--primary)] px-5 py-3 text-sm font-bold text-white">
                    Selecione o arquivo e informe os campos relevantes.
                </div>
                <div className="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                    <div className="flex items-start gap-3">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                            <Building2 size={17} />
                        </span>
                        <div>
                            <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Importar base propria</h2>
                            <p className="mt-1 text-xs text-[var(--ink-500)]">
                                Os itens serao gravados como Base propria. Informe as letras das colunas da sua planilha.
                            </p>
                        </div>
                    </div>
                    <button className="sig-btn sig-btn-ghost" type="button" onClick={onClose}>
                        <X size={15} />
                        Fechar
                    </button>
                </div>
            </header>

            <form className="grid gap-5 p-5" onSubmit={onSubmit}>
                <div className="grid gap-4 lg:grid-cols-[minmax(240px,420px)_1fr]">
                    <Field label="Arquivo" error={form.errors.file}>
                        <input
                            accept=".csv,.txt,.tsv"
                            onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                            type="file"
                        />
                    </Field>
                    <div className="rounded-lg border border-dashed border-[var(--border-strong)] bg-[var(--surface-muted)] px-4 py-3 text-xs leading-5 text-[var(--ink-500)]">
                        Suporta arquivos <strong>CSV, TXT e TSV</strong>. Para XLSX/XLS/ODS, exporte a planilha como CSV antes de importar.
                    </div>
                </div>

                <div className="grid max-w-3xl gap-4">
                    <Field label="Numero da linha do primeiro item" error={form.errors.first_item_row}>
                        <input value={form.data.first_item_row} onChange={(event) => form.setData('first_item_row', event.target.value)} inputMode="numeric" placeholder="2" />
                    </Field>
                    <Field label="Numero da linha do ultimo item" error={form.errors.last_item_row}>
                        <input value={form.data.last_item_row} onChange={(event) => form.setData('last_item_row', event.target.value)} inputMode="numeric" placeholder="Ex: 250" />
                    </Field>
                    <Field label="Data de referencia" error={form.errors.data}>
                        <input value={form.data.data} onChange={(event) => form.setData('data', event.target.value)} placeholder="06/2026" />
                    </Field>
                </div>

                <div className="grid max-w-3xl gap-4">
                    <ColumnLetterField form={form} field="tipo_column" label="Letra da coluna de Tipo" />
                    <ColumnLetterField form={form} field="codigo_column" label="Letra da coluna do Codigo" />
                    <ColumnLetterField form={form} field="grupo_column" label="Letra da coluna do Grupo" optional />
                    <ColumnLetterField form={form} field="descricao_column" label="Letra da coluna da Descricao" />
                    <ColumnLetterField form={form} field="unidade_column" label="Letra da coluna da Unidade" />
                    <ColumnLetterField form={form} field="preco_desonerado_column" label="Letra da coluna do Preco Unitario Desonerado" optional />
                    <ColumnLetterField form={form} field="preco_nao_desonerado_column" label="Letra da coluna do Preco Unitario Nao Desonerado" />
                </div>

                <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-4 py-3 text-xs leading-5 text-[var(--ink-500)]">
                    <p>
                        <strong>Tipo:</strong> campo obrigatorio. Informe a coluna que contem o tipo de cada linha, como Material, Equipamento, Mao de obra ou Servico.
                    </p>
                    <p>
                        <strong>Grupo:</strong> campo opcional. Se informado, o nome ou ID precisa existir previamente em Grupos.
                    </p>
                </div>

                <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] pt-4">
                    <button className="sig-btn sig-btn-secondary" type="button" onClick={onClose}>
                        Cancelar
                    </button>
                    <button className="sig-btn sig-btn-primary" disabled={form.processing || !form.data.file} type="submit">
                        <UploadCloud size={15} />
                        {form.processing ? 'Importando...' : 'Salvar as alteracoes'}
                    </button>
                </div>
            </form>
        </section>
    );
}

function ColumnLetterField({ field, form, label, optional = false }) {
    return (
        <Field label={label} error={form.errors[field]}>
            <input
                value={form.data[field]}
                onChange={(event) => form.setData(field, event.target.value.toUpperCase())}
                placeholder={optional ? 'Opcional' : 'Ex: A'}
            />
        </Field>
    );
}

function ImportInsumoPanel({ description, form, icon, onClose, onSubmit, title }) {
    const Icon = icon;
    const isSicro3 = form.data.banco === 'SICRO3';

    return (
        <section className="sig-card mb-5 overflow-hidden">
            <PanelHeader description={description} icon={Icon} onClose={onClose} title={title} />

            <form className="grid gap-5 p-5" onSubmit={onSubmit}>
                <div className="grid gap-4 lg:grid-cols-[220px_minmax(240px,420px)_1fr]">
                    <Field label="Banco" error={form.errors.banco}>
                        <select value={form.data.banco} onChange={(event) => form.setData('banco', event.target.value)}>
                            {banks.map((bank) => <option key={bank.value} value={bank.value}>{bank.label}</option>)}
                        </select>
                    </Field>

                    <Field label="Arquivo CSV" error={form.errors.file}>
                        <input
                            accept=".csv,.txt,.tsv"
                            onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                            type="file"
                        />
                    </Field>

                    <div className="rounded-lg border border-dashed border-[var(--border-strong)] bg-[var(--surface-muted)] px-4 py-3 text-xs leading-5 text-[var(--ink-500)]">
                        Suporta arquivos <strong>CSV, TXT e TSV</strong>. Para XLSX/XLS/ODS, exporte a planilha como CSV antes de importar.
                    </div>
                </div>

                <div className="grid max-w-3xl gap-4">
                    <Field label="Numero da linha do primeiro item" error={form.errors.first_item_row}>
                        <input value={form.data.first_item_row} onChange={(event) => form.setData('first_item_row', event.target.value)} inputMode="numeric" placeholder="2" />
                    </Field>
                    <Field label="Numero da linha do ultimo item" error={form.errors.last_item_row}>
                        <input value={form.data.last_item_row} onChange={(event) => form.setData('last_item_row', event.target.value)} inputMode="numeric" placeholder="Ex: 190000" />
                    </Field>
                </div>

                <div className="grid max-w-3xl gap-4">
                    <ColumnLetterField form={form} field="codigo_insumo_column" label="Letra da coluna do Codigo do insumo" />
                    <ColumnLetterField form={form} field="classificacao_column" label="Letra da coluna da Classificacao" />
                    <ColumnLetterField form={form} field="descricao_column" label="Letra da coluna da Descricao" />
                    <ColumnLetterField form={form} field="unidade_column" label="Letra da coluna da Unidade" />
                    <ColumnLetterField form={form} field="uf_column" label="Letra da coluna da UF" />
                    <ColumnLetterField form={form} field="origem_preco_column" label="Letra da coluna da Origem do preco" optional />
                    <ColumnLetterField form={form} field="preco_nao_desonerado_column" label="Letra da coluna do Preco nao desonerado" />
                    <ColumnLetterField form={form} field="preco_desonerado_column" label="Letra da coluna do Preco desonerado" />
                    {isSicro3 && (
                        <>
                            <ColumnLetterField form={form} field="custo_improdutivo_nao_desonerado_column" label="Letra da coluna do Custo improdutivo nao desonerado" optional />
                            <ColumnLetterField form={form} field="custo_improdutivo_desonerado_column" label="Letra da coluna do Custo improdutivo desonerado" optional />
                        </>
                    )}
                    <ColumnLetterField form={form} field="data_column" label="Letra da coluna da Data" />
                </div>

                <div className="rounded-lg border border-dashed border-[var(--border-strong)] bg-[var(--surface-muted)] px-4 py-3 text-xs leading-5 text-[var(--ink-500)]">
                    <strong className="text-[var(--ink-700)]">Campos obrigatorios:</strong>{' '}
                    codigo, classificacao, descricao, unidade, UF, preco nao desonerado, preco desonerado e data.
                    <br />
                    Origem do preco e opcional. Para <strong>SICRO3</strong>, as colunas de custo improdutivo nao desonerado e desonerado tambem sao opcionais.
                    <br />
                    A data vem da propria planilha e pode estar como <strong>04/26</strong>, <strong>abr/26</strong>, <strong>04/2026</strong> ou <strong>2026-04-01</strong>.
                    <br />
                    Duplicados ja existentes na base global serao ignorados. Limite por importacao: <strong>100 MB</strong>, processado em lotes no PostgreSQL.
                </div>

                <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] pt-4">
                    <button className="sig-btn sig-btn-secondary" type="button" onClick={onClose}>
                        Cancelar
                    </button>
                    <button className="sig-btn sig-btn-primary" disabled={form.processing || !form.data.file} type="submit">
                        <UploadCloud size={15} />
                        {form.processing ? 'Importando...' : 'Importar CSV'}
                    </button>
                </div>
            </form>
        </section>
    );
}

function PanelHeader({ description, icon, onClose, title }) {
    const Icon = icon;

    return (
        <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
            <div className="flex items-start gap-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                    <Icon size={17} />
                </span>
                <div>
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">{title}</h2>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">{description}</p>
                </div>
            </div>
            <button className="sig-btn sig-btn-ghost" type="button" onClick={onClose}>
                <X size={15} />
                Fechar
            </button>
        </header>
    );
}

function ActionButton({ active, children, icon, onClick, primary = false }) {
    const Icon = icon;

    return (
        <button
            className={`sig-btn ${active || primary ? 'sig-btn-primary' : 'sig-btn-secondary'}`}
            type="button"
            onClick={onClick}
        >
            <Icon size={15} />
            {children}
        </button>
    );
}

function InsumosList({ filters, hasSearched, insumos, pagination, setFilters, tenant, totalInsumos }) {
    const from = pagination?.from ?? (insumos.length ? 1 : 0);
    const to = pagination?.to ?? insumos.length;
    const filteredTotal = pagination?.total ?? insumos.length;
    const currentPage = pagination?.current_page ?? 1;
    const lastPage = pagination?.last_page ?? 1;
    const updatePerPage = (perPage) => {
        const nextFilters = { ...filters, perPage };

        setFilters(nextFilters);
        router.get(route('tenant.orcamentos.insumos.index', tenant.slug), { ...nextFilters, searched: 1 }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };
    const goTo = (url) => {
        if (url) {
            router.get(url, {}, { preserveScroll: true, preserveState: true });
        }
    };

    return (
        <section className="sig-card overflow-hidden">
            <header className="flex flex-wrap items-center justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                <div>
                    <p className="text-[10.5px] font-bold uppercase tracking-[0.1em] text-[var(--ink-400)]">
                        {resultContext(filters)}
                    </p>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        {hasSearched
                            ? `Exibindo ${formatCount(from)}–${formatCount(to)} de ${formatCount(filteredTotal)} resultados filtrados · ${formatCount(totalInsumos)} na base`
                            : `${formatCount(totalInsumos)} itens disponíveis. Aplique os filtros para consultar a base.`}
                    </p>
                </div>
                {hasSearched && (
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.04em] text-[var(--ink-500)]">
                            Itens por página
                            <select
                                className="sig-input !h-9 !min-h-9 !w-20 !px-3 !text-sm !normal-case !tracking-normal"
                                value={filters.perPage}
                                onChange={(event) => updatePerPage(event.target.value)}
                            >
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                        <span className="h-7 w-px bg-[var(--border)]" aria-hidden="true" />
                        <div className="flex items-center gap-2">
                            <PageArrow
                                disabled={!pagination?.prev_page_url}
                                label="Página anterior"
                                onClick={() => goTo(pagination?.prev_page_url)}
                            >
                                <ChevronLeft size={16} />
                            </PageArrow>
                            <span className="min-w-16 text-center text-sm font-medium text-[var(--ink-700)]">
                                {currentPage} / {lastPage}
                            </span>
                            <PageArrow
                                disabled={!pagination?.next_page_url}
                                label="Próxima página"
                                onClick={() => goTo(pagination?.next_page_url)}
                            >
                                <ChevronRight size={16} />
                            </PageArrow>
                        </div>
                    </div>
                )}
            </header>

            {!hasSearched ? (
                <div className="p-8 text-center text-sm text-[var(--ink-500)]">
                    Selecione os critérios e clique em Filtrar para carregar a listagem de insumos.
                </div>
            ) : insumos.length === 0 ? (
                <div className="p-8 text-center text-sm text-[var(--ink-500)]">
                    Nenhum insumo encontrado para os filtros informados.
                </div>
            ) : (
                <>
                    <div className="hidden lg:block">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[940px] table-fixed border-collapse text-left">
                                <colgroup>
                                    <col className="w-[10%]" />
                                    <col className="w-[40%]" />
                                    <col className="w-[11%]" />
                                    <col className="w-[7%]" />
                                    <col className="w-[9%]" />
                                    <col className="w-[11.5%]" />
                                    <col className="w-[11.5%]" />
                                </colgroup>
                                <thead>
                                    <tr className="border-b border-[var(--border)] bg-[var(--surface-muted)] text-[var(--ink-400)]">
                                        <TableHeader>Código</TableHeader>
                                        <TableHeader>Descrição</TableHeader>
                                        <TableHeader>Tipo</TableHeader>
                                        <TableHeader>Unidade</TableHeader>
                                        <TableHeader>Data</TableHeader>
                                        <TableHeader className="text-right">Não desonerado</TableHeader>
                                        <TableHeader className="text-right">Desonerado</TableHeader>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[var(--border)] bg-white">
                                    {insumos.map((insumo) => (
                                        <tr key={insumo.id} className="transition-colors hover:bg-slate-50">
                                            <TableCell className="whitespace-nowrap font-mono text-[13px] font-medium text-[var(--primary)]">
                                                {formatInsumoCode(insumo.codigo_insumo)}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex min-w-0 flex-col gap-1">
                                                    <span className="text-[13.5px] leading-5 text-[var(--ink-900)]">
                                                        {insumo.descricao}
                                                    </span>
                                                    <span className="text-[11px] font-medium text-[var(--ink-400)]">
                                                        {[insumo.banco, insumo.uf].filter(Boolean).join(' · ')}
                                                        {insumo.grupo?.nome ? ` · ${insumo.grupo.nome}` : ''}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <TypeBadge insumo={insumo} />
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap font-mono text-[12px] text-[var(--ink-700)]">
                                                {insumo.unidade || '-'}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap font-mono text-[12px] text-[var(--ink-400)]">
                                                {insumo.data || '-'}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-[13px] font-semibold text-[var(--ink-900)]">
                                                {formatDecimalValue(insumo.preco_nao_desonerado)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-[13px] text-[var(--ink-500)]">
                                                {formatDecimalValue(insumo.preco_desonerado)}
                                            </TableCell>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div className="grid gap-2 bg-[var(--surface-muted)] p-3 lg:hidden">
                        {insumos.map((insumo) => (
                            <article key={insumo.id} className="rounded-md border border-[var(--border)] bg-white p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <span className="text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--ink-400)]">Código</span>
                                        <p className="font-mono text-[14px] font-semibold text-[var(--primary)]">
                                            {formatInsumoCode(insumo.codigo_insumo)}
                                        </p>
                                    </div>
                                    <TypeBadge insumo={insumo} />
                                </div>

                                <p className="mt-3 text-[13px] font-medium leading-5 text-[var(--ink-900)]">
                                    {insumo.descricao}
                                </p>

                                <div className="mt-3 grid grid-cols-2 gap-3 border-t border-[var(--border)] pt-3 text-sm sm:grid-cols-4">
                                    <MobileMetric label="Unidade" value={insumo.unidade || '-'} />
                                    <MobileMetric label="Data" value={insumo.data || '-'} />
                                    <MobileMetric label="Nao deson." value={formatDecimalValue(insumo.preco_nao_desonerado)} />
                                    <MobileMetric label="Deson." value={formatDecimalValue(insumo.preco_desonerado)} />
                                </div>

                                <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-medium text-[var(--ink-400)]">
                                    <span>{insumo.banco}</span>
                                    {insumo.uf && <span>{insumo.uf}</span>}
                                    {insumo.grupo?.nome && <span>{insumo.grupo.nome}</span>}
                                </div>
                            </article>
                        ))}
                    </div>
                </>
            )}

            {hasSearched && pagination && (
                <Pagination pagination={pagination} />
            )}
        </section>
    );
}

function PageArrow({ children, disabled, label, onClick }) {
    return (
        <button
            aria-label={label}
            className={`flex h-9 w-9 items-center justify-center rounded-md border border-[var(--border)] bg-white text-[var(--ink-600)] transition hover:border-[var(--border-strong)] hover:text-[var(--ink-900)] ${
                disabled ? 'cursor-not-allowed opacity-40' : ''
            }`}
            disabled={disabled}
            type="button"
            onClick={onClick}
        >
            {children}
        </button>
    );
}

function TypeBadge({ insumo }) {
    const label = displayClassification(insumo);
    const tone = classificationTone(insumo, label);

    return (
        <span className={`inline-flex rounded-md px-2.5 py-1 text-[11px] font-medium ${tone}`}>
            {label}
        </span>
    );
}

function Pagination({ pagination }) {
    const goTo = (url) => {
        if (url) {
            router.get(url, {}, { preserveScroll: true, preserveState: true });
        }
    };

    return (
        <footer className="flex flex-col gap-3 border-t border-[var(--border)] bg-white px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex flex-wrap items-center gap-2">
                <button
                    className={`sig-btn sig-btn-secondary min-h-9 ${!pagination.prev_page_url ? 'cursor-not-allowed opacity-45' : ''}`}
                    disabled={!pagination.prev_page_url}
                    type="button"
                    onClick={() => goTo(pagination.prev_page_url)}
                >
                    Anterior
                </button>
                <button
                    className={`sig-btn sig-btn-primary min-h-9 ${!pagination.next_page_url ? 'cursor-not-allowed opacity-45' : ''}`}
                    disabled={!pagination.next_page_url}
                    type="button"
                    onClick={() => goTo(pagination.next_page_url)}
                >
                    Proxima
                </button>
                <span className="text-xs font-medium text-[var(--ink-500)]">
                    Pagina {pagination.current_page} de {pagination.last_page}
                </span>
            </div>

            <div className="flex flex-wrap items-center gap-1">
                {pagination.links
                    .filter((link) => !String(link.label).includes('Previous') && !String(link.label).includes('Next'))
                    .map((link, index) => {
                        const label = paginationLabel(link.label);

                        return (
                            <button
                                key={`${link.label}-${index}`}
                                className={`min-h-8 rounded-md border px-3 text-xs font-bold transition ${
                                    link.active
                                        ? 'border-[var(--primary)] bg-[var(--primary)] text-white'
                                        : 'border-[var(--border)] bg-white text-[var(--ink-600)] hover:bg-[var(--primary-50)]'
                                } ${!link.url ? 'cursor-not-allowed opacity-45' : ''}`}
                                disabled={!link.url}
                                type="button"
                                onClick={() => goTo(link.url)}
                            >
                                {label}
                            </button>
                        );
                    })}
            </div>
        </footer>
    );
}

function paginationLabel(label) {
    return String(label)
        .replace('&laquo; Previous', 'Anterior')
        .replace('Next &raquo;', 'Proxima')
        .replace('&laquo;', '')
        .replace('&raquo;', '');
}

function TableHeader({ children, className = '' }) {
    return (
        <th className={`px-3 py-3 text-[10.5px] font-bold uppercase tracking-[0.06em] ${className}`}>
            {children}
        </th>
    );
}

function TableCell({ children, className = '' }) {
    return (
        <td className={`break-words px-3 py-3.5 align-middle ${className}`}>
            {children}
        </td>
    );
}

function MobileMetric({ label, value }) {
    return (
        <div>
            <span className="block text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--ink-400)]">
                {label}
            </span>
            <span className="mt-1 block break-words font-semibold text-[var(--ink-900)]">
                {value}
            </span>
        </div>
    );
}

function Field({ label, children, error }) {
    const control = cloneElement(children, {
        className: `${children.props.className || ''} sig-input`.trim(),
        style: {
            width: '100%',
            minHeight: children.type === 'textarea' ? 82 : 38,
            ...children.props.style,
        },
    });

    return (
        <label className="block">
            <span className="mb-1 block text-[11px] font-bold uppercase tracking-[0.04em] text-[var(--ink-500)]">
                {label}
            </span>
            {control}
            {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
        </label>
    );
}

function currentMonthReference() {
    const date = new Date();
    const month = String(date.getMonth() + 1).padStart(2, '0');

    return `${month}/${date.getFullYear()}`;
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

function formatInsumoCode(value) {
    const code = String(value || '').trim();

    return /^\d+$/.test(code) ? code.padStart(8, '0') : code;
}

function formatCount(value) {
    return new Intl.NumberFormat('pt-BR').format(Number(value ?? 0));
}

function resultContext(filters) {
    const bank = filters.bank === 'TODOS' ? 'Todas as bases' : `Base ${filters.bank}`;
    const state = filters.state ? stateLabel(filters.state) : 'Todos os estados';
    const date = filters.date || 'Todas as datas';

    return `${bank} · ${state} · ${date}`;
}

function stateLabel(value) {
    return states.find((state) => state.value === value)?.label ?? value;
}

function classificationTone(insumo, label) {
    const value = String(insumo.tipo || label || '').toLowerCase();

    if (value.includes('serv')) {
        return 'bg-emerald-50 text-emerald-700';
    }

    if (value.includes('material')) {
        return 'bg-blue-50 text-blue-700';
    }

    if (value.includes('equip')) {
        return 'bg-amber-50 text-amber-700';
    }

    if (value.includes('obra') || value.includes('labor')) {
        return 'bg-violet-50 text-violet-700';
    }

    return 'bg-slate-100 text-slate-600';
}

function displayClassification(insumo) {
    return insumo.classificacao || typeLabels[insumo.tipo] || 'Nao classificado';
}

function formatDecimalValue(value) {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    const number = Number(value);

    if (Number.isNaN(number)) {
        return value;
    }

    return new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(truncateDecimal(number));
}

function truncateDecimal(value, decimals = 2) {
    const parsed = Number(value ?? 0);

    if (Number.isNaN(parsed)) {
        return 0;
    }

    const factor = 10 ** decimals;
    const epsilon = 1e-9;

    return parsed < 0
        ? Math.ceil(parsed * factor - epsilon) / factor
        : Math.floor(parsed * factor + epsilon) / factor;
}
