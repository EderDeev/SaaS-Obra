import ContractAccessCard from '@/Components/ContractAccessCard';
import ContractTour, { startContractTour } from '@/Components/ContractTour';
import brazilCities from '@/Data/brazilCities';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, FilePlus2, FileText, LayoutGrid, List, Plane, Plus, Search, Settings, Upload, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ContractAdditiveHistoryModal, ContractAdditiveModal, ContractParametrizacaoModal } from './Show';

const statusMeta = {
    planning: { label: 'Planejamento', pill: 'sig-pill-blue' },
    active: { label: 'Em andamento', pill: 'sig-pill-green' },
    paused: { label: 'Paralisado', pill: 'sig-pill-amber' },
    completed: { label: 'Concluído', pill: 'sig-pill-blue' },
    cancelled: { label: 'Cancelado', pill: 'sig-pill-red' },
};

const brazilianStates = [
    { value: 'AC', label: 'Acre' },
    { value: 'AL', label: 'Alagoas' },
    { value: 'AP', label: 'Amapá' },
    { value: 'AM', label: 'Amazonas' },
    { value: 'BA', label: 'Bahia' },
    { value: 'CE', label: 'Ceará' },
    { value: 'DF', label: 'Distrito Federal' },
    { value: 'ES', label: 'Espírito Santo' },
    { value: 'GO', label: 'Goiás' },
    { value: 'MA', label: 'Maranhão' },
    { value: 'MT', label: 'Mato Grosso' },
    { value: 'MS', label: 'Mato Grosso do Sul' },
    { value: 'MG', label: 'Minas Gerais' },
    { value: 'PA', label: 'Pará' },
    { value: 'PB', label: 'Paraíba' },
    { value: 'PR', label: 'Paraná' },
    { value: 'PE', label: 'Pernambuco' },
    { value: 'PI', label: 'Piauí' },
    { value: 'RJ', label: 'Rio de Janeiro' },
    { value: 'RN', label: 'Rio Grande do Norte' },
    { value: 'RS', label: 'Rio Grande do Sul' },
    { value: 'RO', label: 'Rondônia' },
    { value: 'RR', label: 'Roraima' },
    { value: 'SC', label: 'Santa Catarina' },
    { value: 'SP', label: 'São Paulo' },
    { value: 'SE', label: 'Sergipe' },
    { value: 'TO', label: 'Tocantins' },
];

const stateNameByUf = Object.fromEntries(brazilianStates.map((state) => [state.value, state.label]));
const stateOptions = brazilianStates.filter((state) => Boolean(brazilCities[state.value]));

const currencyOptions = [
    { value: 'BRL', label: 'Real', locale: 'pt-BR', fractionDigits: 2 },
    { value: 'USD', label: 'Dólar', locale: 'en-US', fractionDigits: 2 },
    { value: 'JPY', label: 'Yen', locale: 'ja-JP', fractionDigits: 0 },
    { value: 'CNY', label: 'Yuan', locale: 'zh-CN', fractionDigits: 2 },
    { value: 'EUR', label: 'Euro', locale: 'de-DE', fractionDigits: 2 },
];

const additiveTypeLabel = {
    cost: 'Custo',
    deadline: 'Prazo',
    cost_deadline: 'Custo e prazo',
};

function currencyConfig(currency) {
    return currencyOptions.find((option) => option.value === currency) || currencyOptions[0];
}

function amountFromDigits(value, currency) {
    const digits = value.replace(/\D/g, '');

    if (!digits) {
        return '';
    }

    const config = currencyConfig(currency);
    const amount = Number(digits) / (10 ** config.fractionDigits);

    return amount.toFixed(config.fractionDigits);
}

function formatCurrency(value, currency) {
    if (value === '' || value === null || value === undefined) {
        return '';
    }

    const config = currencyConfig(currency);

    return Number(value).toLocaleString(config.locale, {
        style: 'currency',
        currency: config.value,
        minimumFractionDigits: config.fractionDigits,
        maximumFractionDigits: config.fractionDigits,
    });
}

function fileSize(bytes = 0) {
    const size = Number(bytes || 0);

    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;

    return `${(size / 1024 / 1024).toFixed(1)} MB`;
}

function enrichContract(contract) {
    return {
        ...contract,
        meta: statusMeta[contract.status] || statusMeta.planning,
        state_label: stateNameByUf[contract.state] || contract.state,
    };
}

const shortDate = (date) => {
    if (!date) return 'sem prazo';

    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date));
};

const companyKey = (company, fallback = '') => company?.id ? String(company.id) : String(fallback || '');
const companyLabel = (company, fallback = '') => company?.nome || fallback || 'Não informado';
const hasAttention = (contract) => Number(contract.overdue_activities_count || 0) > 0
    || Number(contract.open_rncs_count || 0) > 0
    || Number(contract.pending_projects_count || 0) > 0;

const tourDemoContract = {
    id: 'tour-demo',
    code: 'CT-001',
    name: 'Contrato CT-001',
    status: 'planning',
    city: 'Sao Paulo',
    state: 'SP',
    total_value: 12500000,
    currency: 'BRL',
    starts_at: '2026-01-01',
    ends_at: '2027-12-31',
    obra: { nome: 'Obra Jardim Central' },
    cliente_empresa: { nome: 'Cliente Alpha' },
    construtora_empresa: { nome: 'Construtora Horizonte' },
    gerenciadora_empresa: { nome: 'Gerenciadora Tecnica' },
    open_activities_count: 8,
    overdue_activities_count: 1,
    open_rncs_count: 2,
    pending_projects_count: 3,
    contract_additives_count: 1,
    latest_additive: { sequence_number: 1, type: 'cost_deadline', title: 'Reequilibrio e prorrogacao contratual' },
};

function companyOptions(contracts, resolve) {
    return contracts.reduce((options, contract) => {
        const [company, fallback] = resolve(contract);
        const value = companyKey(company, fallback);

        if (value && !options.some((option) => option.value === value)) {
            options.push({ value, label: companyLabel(company, fallback) });
        }

        return options;
    }, []).sort((a, b) => a.label.localeCompare(b.label, 'pt-BR'));
}

export default function ContractsIndex({ tenant, contracts, statuses, canCreateContracts, canManageContracts = false, parametrizacao = {} }) {
    const page = usePage();
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState('todos');
    const [stateFilter, setStateFilter] = useState('todos');
    const [clientFilter, setClientFilter] = useState('todos');
    const [contractorFilter, setContractorFilter] = useState('todos');
    const [managerFilter, setManagerFilter] = useState('todos');
    const [attentionOnly, setAttentionOnly] = useState(false);
    const [viewMode, setViewMode] = useState('cards');
    const [showCreate, setShowCreate] = useState(false);
    const [parametrizacaoContract, setParametrizacaoContract] = useState(null);
    const [additiveContract, setAdditiveContract] = useState(null);
    const [additiveHistoryContract, setAdditiveHistoryContract] = useState(null);
    const [totalValueDisplay, setTotalValueDisplay] = useState('');
    const [showTourDemo, setShowTourDemo] = useState(() => typeof window !== 'undefined'
        && new URLSearchParams(window.location.search).get('tour') === 'contracts');
    const form = useForm({
        code: '',
        total_value: '',
        currency: 'BRL',
        city: '',
        state: '',
        starts_at: '',
        ends_at: '',
        base_document: null,
    });

    const enrichedContracts = useMemo(() => contracts.map(enrichContract), [contracts]);
    const filteredContracts = useMemo(() => {
        const q = query.trim().toLowerCase();

        return enrichedContracts.filter((contract) => {
            if (filter !== 'todos' && contract.status !== filter) {
                return false;
            }

            if (stateFilter !== 'todos' && contract.state !== stateFilter) {
                return false;
            }

            if (clientFilter !== 'todos' && companyKey(contract.cliente_empresa, contract.client_company_name) !== clientFilter) {
                return false;
            }

            if (contractorFilter !== 'todos' && companyKey(contract.construtora_empresa, contract.contractor_company_name) !== contractorFilter) {
                return false;
            }

            if (managerFilter !== 'todos' && companyKey(contract.gerenciadora_empresa) !== managerFilter) {
                return false;
            }

            if (attentionOnly && !hasAttention(contract)) {
                return false;
            }

            if (!q) {
                return true;
            }

            return [
                contract.code,
                contract.obra?.nome,
                contract.name,
                contract.cliente_empresa?.nome,
                contract.client_company_name,
                contract.construtora_empresa?.nome,
                contract.contractor_company_name,
                contract.gerenciadora_empresa?.nome,
                contract.city,
                contract.state,
                stateNameByUf[contract.state],
                contract.currency,
            ].filter(Boolean).join(' ').toLowerCase().includes(q);
        });
    }, [attentionOnly, clientFilter, contractorFilter, enrichedContracts, filter, managerFilter, query, stateFilter]);

    const clientOptions = useMemo(() => companyOptions(enrichedContracts, (contract) => [contract.cliente_empresa, contract.client_company_name]), [enrichedContracts]);
    const contractorOptions = useMemo(() => companyOptions(enrichedContracts, (contract) => [contract.construtora_empresa, contract.contractor_company_name]), [enrichedContracts]);
    const managerOptions = useMemo(() => companyOptions(enrichedContracts, (contract) => [contract.gerenciadora_empresa]), [enrichedContracts]);
    const counts = {
        todos: contracts.length,
        active: contracts.filter((contract) => contract.status === 'active').length,
        paused: contracts.filter((contract) => contract.status === 'paused').length,
        completed: contracts.filter((contract) => contract.status === 'completed').length,
    };
    const citiesForSelectedState = useMemo(
        () => brazilCities[form.data.state] || [],
        [form.data.state],
    );

    const submit = (event) => {
        event.preventDefault();

        form.post(route('tenant.contracts.store', page.props.currentTenant.slug), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setTotalValueDisplay('');
                setShowCreate(false);
            },
        });
    };

    const updateCurrency = (currency) => {
        const config = currencyConfig(currency);
        const amount = form.data.total_value === ''
            ? ''
            : Number(form.data.total_value).toFixed(config.fractionDigits);

        form.setData((data) => ({
            ...data,
            currency,
            total_value: amount,
        }));
        setTotalValueDisplay(formatCurrency(amount, currency));
    };

    const updateTotalValue = (value) => {
        const amount = amountFromDigits(value, form.data.currency);

        form.setData('total_value', amount);
        setTotalValueDisplay(formatCurrency(amount, form.data.currency));
    };

    const updateState = (state) => {
        form.setData((data) => ({
            ...data,
            state,
            city: '',
        }));
    };
    const parametrizacaoForContract = (contract) => ({
        empresas: Object.values(parametrizacao.empresas?.[contract.id] || {}),
        obras: Object.values(parametrizacao.obras?.[contract.id] || {}),
        disciplinas: Object.values(parametrizacao.disciplinas?.[contract.id] || {}),
        tiposEmpresa: parametrizacao.tiposEmpresa || [],
    });
    const displayedContracts = showTourDemo ? [enrichContract(tourDemoContract)] : filteredContracts;
    const tourDetailUrl = showTourDemo
        ? route('tenant.contracts.tour-preview', tenant.slug)
        : (filteredContracts[0] ? route('tenant.contracts.show', [tenant.slug, filteredContracts[0].id]) : null);

    return (
        <AuthenticatedLayout>
            <Head title="Acessar contrato" />

            <section className="sig-content fade-in">
                <div data-tour="contracts-overview" className="flex flex-wrap items-end gap-6">
                    <div className="min-w-0 flex-1">
                        <div className="eyebrow">Workspace · Contratos</div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">Acessar contrato</h1>
                        <p className="mt-1 max-w-2xl text-sm text-[var(--ink-500)]">
                            Selecione um contrato para continuar no ambiente de trabalho.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button className="sig-btn sig-btn-secondary" type="button" onClick={() => startContractTour(tenant.slug)}>
                            <Plane size={15} />
                            Iniciar tour
                        </button>
                        {canCreateContracts && (
                            <button className="sig-btn sig-btn-primary" type="button" onClick={() => setShowCreate(true)}>
                                <Plus size={15} />
                                Novo contrato
                            </button>
                        )}
                    </div>
                </div>

                <div data-tour="contracts-filters" className="mt-6 space-y-3">
                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex flex-wrap gap-2">
                        <FilterButton active={filter === 'todos'} onClick={() => setFilter('todos')} label="Todos" count={counts.todos} />
                        <FilterButton active={filter === 'active'} onClick={() => setFilter('active')} label="Em andamento" count={counts.active} />
                        <FilterButton active={filter === 'paused'} onClick={() => setFilter('paused')} label="Paralisados" count={counts.paused} />
                        <FilterButton active={filter === 'completed'} onClick={() => setFilter('completed')} label="Concluídos" count={counts.completed} />
                    </div>

                    <div className="min-w-[260px] flex-1" />

                    <label className="sig-input max-w-[320px]">
                        <Search size={15} />
                        <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Filtrar por número, obra, cliente..." />
                    </label>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <FilterSelect value={stateFilter} onChange={setStateFilter} firstLabel="Todos os estados" options={stateOptions} />
                    <FilterSelect value={clientFilter} onChange={setClientFilter} firstLabel="Todos os clientes" options={clientOptions} />
                    <FilterSelect value={contractorFilter} onChange={setContractorFilter} firstLabel="Todas as construtoras" options={contractorOptions} />
                    <FilterSelect value={managerFilter} onChange={setManagerFilter} firstLabel="Todas as gerenciadoras" options={managerOptions} />
                    <button
                        className={`sig-btn ${attentionOnly ? 'sig-btn-primary' : 'sig-btn-secondary'}`}
                        type="button"
                        onClick={() => setAttentionOnly((value) => !value)}
                    >
                        <AlertTriangle size={14} />
                        Com pendências
                    </button>
                    <div className="ml-auto flex rounded-md border border-[var(--border)] bg-white p-1">
                        <button
                            className={`rounded p-1.5 ${viewMode === 'cards' ? 'bg-[var(--ink-900)] text-white' : 'text-[var(--ink-500)]'}`}
                            type="button"
                            title="Visualizar em cards"
                            onClick={() => setViewMode('cards')}
                        >
                            <LayoutGrid size={15} />
                        </button>
                        <button
                            className={`rounded p-1.5 ${viewMode === 'table' ? 'bg-[var(--ink-900)] text-white' : 'text-[var(--ink-500)]'}`}
                            type="button"
                            title="Visualizar em tabela"
                            onClick={() => setViewMode('table')}
                        >
                            <List size={15} />
                        </button>
                    </div>
                </div>
                </div>

                {false && showCreate && canCreateContracts && (
                    <form className="sig-card mt-5 grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-[140px_140px_220px_minmax(190px,220px)_minmax(180px,1fr)_160px_160px]" onSubmit={submit}>
                        <Field label="Código" error={form.errors.code}>
                            <input value={form.data.code} onChange={(event) => form.setData('code', event.target.value.toUpperCase())} required placeholder="CT-001" />
                        </Field>
                        <Field label="Moeda" error={form.errors.currency}>
                            <select value={form.data.currency} onChange={(event) => updateCurrency(event.target.value)} required>
                                {currencyOptions.map((currency) => (
                                    <option key={currency.value} value={currency.value}>
                                        {currency.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Valor" error={form.errors.total_value}>
                            <input
                                value={totalValueDisplay}
                                onChange={(event) => updateTotalValue(event.target.value)}
                                placeholder={formatCurrency(0, form.data.currency)}
                                inputMode="numeric"
                                required
                            />
                        </Field>
                        <Field label="Estado" error={form.errors.state}>
                            <select value={form.data.state} onChange={(event) => updateState(event.target.value)} required>
                                <option value="">Selecione o estado</option>
                                {stateOptions.map((state) => (
                                    <option key={state.value} value={state.value}>
                                        {state.label} ({state.value})
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Cidade" error={form.errors.city}>
                            <select
                                value={form.data.city}
                                onChange={(event) => form.setData('city', event.target.value)}
                                disabled={!form.data.state || citiesForSelectedState.length === 0}
                                required
                            >
                                <option value="">Selecione a cidade</option>
                                {citiesForSelectedState.map((city) => (
                                    <option key={city} value={city}>
                                        {city}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Vigência inicial" error={form.errors.starts_at}>
                            <input value={form.data.starts_at} onChange={(event) => form.setData('starts_at', event.target.value)} type="date" required />
                        </Field>
                        <Field label="Vigência final" error={form.errors.ends_at}>
                            <input value={form.data.ends_at} onChange={(event) => form.setData('ends_at', event.target.value)} type="date" required />
                        </Field>
                        <div className="flex items-end sm:col-span-2 lg:col-span-3 xl:col-span-4 2xl:col-span-7">
                            <button className="sig-btn sig-btn-primary" disabled={form.processing}>
                                <Plus size={15} />
                                Criar contrato
                            </button>
                            {page.props.flash.success && <div className="ml-4 text-sm text-[var(--green)]">{page.props.flash.success}</div>}
                        </div>
                    </form>
                )}

                <section className="mt-7">
                    <div className="mb-3">
                        <span className="eyebrow">Portfólio de contratos</span>
                        <p className="mt-1 text-xs text-[var(--ink-400)]">{displayedContracts.length} contrato(s) encontrado(s)</p>
                    </div>

                    {viewMode === 'cards' ? (
                        <div data-tour="contracts-list" className="grid gap-4 xl:grid-cols-3 lg:grid-cols-2">
                            {displayedContracts.map((contract, index) => (
                                <ContractAccessCard
                                    key={contract.id}
                                    tenant={tenant}
                                    contract={contract}
                                    shortDate={shortDate}
                                    canManageContracts={canManageContracts || showTourDemo}
                                    onParametrize={() => setParametrizacaoContract(contract)}
                                    onAdditive={() => setAdditiveContract(contract)}
                                    onHistory={() => setAdditiveHistoryContract(contract)}
                                    tour={index === 0}
                                    detailUrl={showTourDemo ? tourDetailUrl : null}
                                />
                            ))}
                        </div>
                    ) : (
                        <ContractsTable
                            tenant={tenant}
                            contracts={displayedContracts}
                            canManageContracts={canManageContracts || showTourDemo}
                            onParametrize={setParametrizacaoContract}
                            onAdditive={setAdditiveContract}
                            onHistory={setAdditiveHistoryContract}
                        />
                    )}

                    {displayedContracts.length === 0 && (
                        <div className="sig-card p-12 text-center text-[var(--ink-500)]">Nenhum contrato encontrado.</div>
                    )}
                </section>
            </section>

            {showCreate && canCreateContracts && (
                <ContractCreateModal
                    form={form}
                    totalValueDisplay={totalValueDisplay}
                    citiesForSelectedState={citiesForSelectedState}
                    onClose={() => setShowCreate(false)}
                    onSubmit={submit}
                    onUpdateCurrency={updateCurrency}
                    onUpdateState={updateState}
                    onUpdateTotalValue={updateTotalValue}
                />
            )}

            {parametrizacaoContract && (
                <ContractParametrizacaoModal
                    tenant={tenant}
                    contract={parametrizacaoContract}
                    parametrizacao={parametrizacaoForContract(parametrizacaoContract)}
                    onClose={() => setParametrizacaoContract(null)}
                />
            )}

            {additiveContract && (
                <ContractAdditiveModal
                    tenant={tenant}
                    contract={additiveContract}
                    onClose={() => setAdditiveContract(null)}
                    onHistory={() => {
                        setAdditiveHistoryContract(additiveContract);
                        setAdditiveContract(null);
                    }}
                />
            )}

            {additiveHistoryContract && (
                <ContractAdditiveHistoryModal
                    tenant={tenant}
                    contract={additiveHistoryContract}
                    additives={additiveHistoryContract.contract_additives || []}
                    onClose={() => setAdditiveHistoryContract(null)}
                />
            )}
            <ContractTour
                section="contracts"
                detailUrl={tourDetailUrl}
                onExit={() => setShowTourDemo(false)}
            />
        </AuthenticatedLayout>
    );
}

function ContractCreateModal({
    form,
    totalValueDisplay,
    citiesForSelectedState,
    onClose,
    onSubmit,
    onUpdateCurrency,
    onUpdateState,
    onUpdateTotalValue,
}) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4">
            <form className="sig-card flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden" onSubmit={onSubmit}>
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-6 py-5">
                    <div>
                        <span className="eyebrow">Contrato</span>
                        <h2 className="mt-1 text-xl font-semibold text-[var(--ink-900)]">Novo contrato</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">Informe os dados base para abrir o ambiente do contrato.</p>
                    </div>
                    <button className="sig-btn sig-btn-ghost" type="button" onClick={onClose} aria-label="Fechar">
                        <X size={18} />
                    </button>
                </header>

                <div className="grid gap-4 overflow-y-auto px-6 py-5 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label="Código" error={form.errors.code}>
                        <input value={form.data.code} onChange={(event) => form.setData('code', event.target.value.toUpperCase())} required placeholder="CT-001" />
                    </Field>
                    <Field label="Moeda" error={form.errors.currency}>
                        <select value={form.data.currency} onChange={(event) => onUpdateCurrency(event.target.value)} required>
                            {currencyOptions.map((currency) => (
                                <option key={currency.value} value={currency.value}>
                                    {currency.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Valor" error={form.errors.total_value}>
                        <input
                            value={totalValueDisplay}
                            onChange={(event) => onUpdateTotalValue(event.target.value)}
                            placeholder={formatCurrency(0, form.data.currency)}
                            inputMode="numeric"
                            required
                        />
                    </Field>
                    <Field label="Estado" error={form.errors.state}>
                        <select value={form.data.state} onChange={(event) => onUpdateState(event.target.value)} required>
                            <option value="">Selecione o estado</option>
                            {stateOptions.map((state) => (
                                <option key={state.value} value={state.value}>
                                    {state.label} ({state.value})
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Cidade" error={form.errors.city}>
                        <select
                            value={form.data.city}
                            onChange={(event) => form.setData('city', event.target.value)}
                            disabled={!form.data.state || citiesForSelectedState.length === 0}
                            required
                        >
                            <option value="">Selecione a cidade</option>
                            {citiesForSelectedState.map((city) => (
                                <option key={city} value={city}>
                                    {city}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Vigência inicial" error={form.errors.starts_at}>
                        <input value={form.data.starts_at} onChange={(event) => form.setData('starts_at', event.target.value)} type="date" required />
                    </Field>
                    <Field label="Vigência final" error={form.errors.ends_at}>
                        <input value={form.data.ends_at} onChange={(event) => form.setData('ends_at', event.target.value)} type="date" required />
                    </Field>
                    <div className="sm:col-span-2 lg:col-span-3">
                        <span className="eyebrow mb-1 block">Documento do contrato</span>
                        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
                            <label className="sig-btn sig-btn-secondary sig-btn-sm w-fit">
                                <Upload size={14} />
                                Selecionar documento
                                <input
                                    className="sr-only"
                                    type="file"
                                    accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip"
                                    onChange={(event) => form.setData('base_document', event.target.files?.[0] || null)}
                                />
                            </label>
                            {form.data.base_document && (
                                <div className="mt-3 flex items-center gap-2 rounded-md bg-white px-3 py-2 text-sm text-[var(--ink-700)]">
                                    <FileText size={14} />
                                    <span className="min-w-0 flex-1 truncate">{form.data.base_document.name}</span>
                                    <span className="text-xs text-[var(--ink-500)]">{fileSize(form.data.base_document.size)}</span>
                                </div>
                            )}
                        </div>
                        {form.errors.base_document && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.base_document}</span>}
                    </div>
                </div>

                <footer className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] bg-[var(--surface-muted)] px-6 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onClose}>
                        <X size={15} />
                        Cancelar
                    </button>
                    <button className="sig-btn sig-btn-primary" disabled={form.processing}>
                        <Plus size={15} />
                        Criar contrato
                    </button>
                </footer>
            </form>
        </div>
    );
}

function FilterButton({ active, onClick, label, count }) {
    return (
        <button
            type="button"
            className={`sig-pill border border-[var(--border)] ${active ? 'bg-[var(--ink-900)] text-white' : ''}`}
            onClick={onClick}
        >
            {label} <span className="opacity-70">{count}</span>
        </button>
    );
}

function FilterSelect({ value, onChange, firstLabel, options }) {
    return (
        <label className="sig-input max-w-[240px]">
            <select value={value} onChange={(event) => onChange(event.target.value)}>
                <option value="todos">{firstLabel}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>{option.label}</option>
                ))}
            </select>
        </label>
    );
}

function ContractsTable({ tenant, contracts, canManageContracts, onParametrize, onAdditive, onHistory }) {
    return (
        <div className="sig-card overflow-x-auto">
            <table className="sig-table min-w-[1080px]">
                <thead>
                    <tr>
                        <th>Contrato</th>
                        <th>Empresas</th>
                        <th>Local</th>
                        <th>Vigência</th>
                        <th>Pendências</th>
                        <th>Aditivo</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    {contracts.map((contract) => (
                        <tr key={contract.id}>
                            <td>
                                <strong className="block text-[var(--ink-900)]">{contract.code}</strong>
                                <span className="text-xs text-[var(--ink-500)]">{contract.obra?.nome || contract.name || 'Sem obra vinculada'}</span>
                            </td>
                            <td>
                                <span className="block text-xs">Cliente: {companyLabel(contract.cliente_empresa, contract.client_company_name)}</span>
                                <span className="block text-xs text-[var(--ink-500)]">Construtora: {companyLabel(contract.construtora_empresa, contract.contractor_company_name)}</span>
                            </td>
                            <td>{[contract.city, contract.state_label].filter(Boolean).join(' - ') || 'Não informado'}</td>
                            <td>{shortDate(contract.starts_at)} até {shortDate(contract.ends_at)}</td>
                            <td>
                                <div className="flex flex-wrap gap-1">
                                    <CountPill label="Atividades" count={contract.open_activities_count} />
                                    <CountPill label="RNCs" count={contract.open_rncs_count} />
                                    <CountPill label="Projetos" count={contract.pending_projects_count} />
                                </div>
                            </td>
                            <td>
                                {Number(contract.contract_additives_count || 0) > 0 ? (
                                    <button className="sig-pill sig-pill-amber cursor-pointer" type="button" onClick={() => onHistory(contract)}>
                                        Aditivo {contract.latest_additive?.sequence_number || contract.contract_additives_count}
                                        {' '}· {additiveTypeLabel[contract.latest_additive?.type] || 'Registrado'}
                                    </button>
                                ) : (
                                    <span className="text-xs text-[var(--ink-400)]">Sem aditivo</span>
                                )}
                            </td>
                            <td>{formatCurrency(contract.total_value, contract.currency) || 'Não informado'}</td>
                            <td><span className={`sig-pill ${contract.meta.pill}`}>{contract.meta.label}</span></td>
                            <td>
                                <div className="flex justify-end gap-2">
                                    {canManageContracts && (
                                        <>
                                            <button className="sig-btn sig-btn-secondary" type="button" onClick={() => onAdditive(contract)}>
                                                <FilePlus2 size={14} />
                                                Aditivo
                                            </button>
                                            <button className="sig-btn sig-btn-primary" type="button" onClick={() => onParametrize(contract)}>
                                                <Settings size={14} />
                                                Parametrizar
                                            </button>
                                        </>
                                    )}
                                    <Link className="sig-btn sig-btn-secondary" href={route('tenant.contracts.show', [tenant.slug, contract.id])}>
                                        Abrir
                                    </Link>
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function CountPill({ label, count }) {
    return <span className="sig-pill border border-[var(--border)]">{Number(count || 0)} {label}</span>;
}

function Field({ label, error, children }) {
    return (
        <label>
            <span className="eyebrow mb-1 block">{label}</span>
            <span className="sig-input">{children}</span>
            {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
        </label>
    );
}
