import ContractAccessCard from '@/Components/ContractAccessCard';
import brazilCities from '@/Data/brazilCities';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Download, Plus, Search, SortDesc, Star } from 'lucide-react';
import { useMemo, useState } from 'react';

const statusMeta = {
    planning: { label: 'Planejamento', pill: 'sig-pill-blue' },
    active: { label: 'Em andamento', pill: 'sig-pill-green' },
    paused: { label: 'Paralisado', pill: 'sig-pill-amber' },
    completed: { label: 'Concluído', pill: 'sig-pill-blue' },
    cancelled: { label: 'Cancelado', pill: 'sig-pill-red' },
};

const colors = ['#0b5fff', '#0e7c66', '#b58105', '#6a52d8', '#5b6479', '#c8364a'];

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

function enrichContract(contract, index) {
    const physical = contract.status === 'completed'
        ? 100
        : contract.status === 'paused'
            ? 42
            : contract.status === 'planning'
                ? 12
                : 58 + ((contract.id * 7) % 25);

    return {
        ...contract,
        meta: statusMeta[contract.status] || statusMeta.planning,
        physical,
        financial: contract.status === 'completed' ? 100 : Math.max(8, physical - 7),
        pinned: index < 2,
        color: colors[index % colors.length],
        badge: (contract.code || contract.name || '?').replace(/[^A-Za-z0-9]/g, '').slice(0, 2).toUpperCase(),
        state_label: stateNameByUf[contract.state] || contract.state,
    };
}

const shortDate = (date) => {
    if (!date) return 'sem prazo';

    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date));
};

export default function ContractsIndex({ tenant, contracts, statuses, canCreateContracts }) {
    const page = usePage();
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState('todos');
    const [showCreate, setShowCreate] = useState(false);
    const [totalValueDisplay, setTotalValueDisplay] = useState('');
    const form = useForm({
        code: '',
        total_value: '',
        currency: 'BRL',
        city: '',
        state: '',
        starts_at: '',
        ends_at: '',
    });

    const enrichedContracts = useMemo(() => contracts.map(enrichContract), [contracts]);
    const filteredContracts = useMemo(() => {
        const q = query.trim().toLowerCase();

        return enrichedContracts.filter((contract) => {
            if (filter !== 'todos' && contract.status !== filter) {
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
    }, [enrichedContracts, query, filter]);

    const pinnedContracts = filteredContracts.filter((contract) => contract.pinned);
    const otherContracts = filteredContracts.filter((contract) => !contract.pinned);
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

    return (
        <AuthenticatedLayout>
            <Head title="Acessar contrato" />

            <section className="sig-content fade-in">
                <div className="flex flex-wrap items-end gap-6">
                    <div className="min-w-0 flex-1">
                        <div className="eyebrow">Workspace · Contratos</div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">Acessar contrato</h1>
                        <p className="mt-1 max-w-2xl text-sm text-[var(--ink-500)]">
                            Selecione um contrato para continuar no ambiente de trabalho.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button className="sig-btn sig-btn-secondary" type="button">
                            <Download size={15} />
                            Exportar lista
                        </button>
                        {canCreateContracts && (
                            <button className="sig-btn sig-btn-primary" type="button" onClick={() => setShowCreate((value) => !value)}>
                                <Plus size={15} />
                                Novo contrato
                            </button>
                        )}
                    </div>
                </div>

                <div className="mt-6 flex flex-wrap items-center gap-3">
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
                    <button className="sig-btn sig-btn-secondary" type="button">
                        <SortDesc size={14} />
                        Recentes
                    </button>
                </div>

                {showCreate && canCreateContracts && (
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

                {pinnedContracts.length > 0 && (
                    <section className="mt-7">
                        <div className="mb-3 flex items-center gap-2">
                            <Star size={14} fill="currentColor" className="text-[var(--amber)]" />
                            <span className="eyebrow">Fixados</span>
                            <span className="text-xs text-[var(--ink-400)]">{pinnedContracts.length}</span>
                        </div>
                        <div className="grid gap-4 xl:grid-cols-3 lg:grid-cols-2">
                            {pinnedContracts.map((contract) => (
                                <ContractAccessCard key={contract.id} tenant={tenant} contract={contract} shortDate={shortDate} />
                            ))}
                        </div>
                    </section>
                )}

                <section className="mt-7">
                    {pinnedContracts.length > 0 && (
                        <div className="mb-3 flex items-center gap-2">
                            <span className="eyebrow">Todos os contratos</span>
                            <span className="text-xs text-[var(--ink-400)]">{otherContracts.length}</span>
                        </div>
                    )}

                    <div className="grid gap-4 xl:grid-cols-3 lg:grid-cols-2">
                        {(pinnedContracts.length ? otherContracts : filteredContracts).map((contract) => (
                            <ContractAccessCard key={contract.id} tenant={tenant} contract={contract} shortDate={shortDate} />
                        ))}
                    </div>

                    {filteredContracts.length === 0 && (
                        <div className="sig-card p-12 text-center text-[var(--ink-500)]">Nenhum contrato encontrado.</div>
                    )}
                </section>
            </section>
        </AuthenticatedLayout>
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

function Field({ label, error, children }) {
    return (
        <label>
            <span className="eyebrow mb-1 block">{label}</span>
            <span className="sig-input">{children}</span>
            {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
        </label>
    );
}
