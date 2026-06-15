import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import { useMemo, useState } from 'react';
import OrcamentoShell from './Partials/OrcamentoShell';

export default function CreateOrcamento({ tenant, options = {} }) {
    const [step, setStep] = useState(1);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const allReferences = useMemo(
        () => (options.baseReferences ?? []).flatMap((group) => group.items ?? []),
        [options.baseReferences],
    );
    const referencesByBase = useMemo(() => groupReferencesByBase(allReferences), [allReferences]);
    const availableBases = useMemo(
        () => Object.keys(referencesByBase).filter((base) => ['SINAPI', 'SICRO3'].includes(base)),
        [referencesByBase],
    );
    const initialBase = availableBases.includes('SINAPI') ? 'SINAPI' : availableBases[0];
    const [selectedBases, setSelectedBases] = useState(() => (initialBase ? { [initialBase]: true } : {}));
    const [stateByBase, setStateByBase] = useState(() => buildInitialStateByBase(referencesByBase));
    const [dateByBase, setDateByBase] = useState(() => buildInitialDateByBase(referencesByBase, stateByBase));

    const selectedBaseReferences = useMemo(
        () => availableBases
            .filter((base) => selectedBases[base])
            .map((base) => {
                const state = stateByBase[base];
                const date = dateByBase[base];

                return (referencesByBase[base] ?? []).find((reference) => reference.uf === state && reference.data === date)
                    ?? (referencesByBase[base] ?? []).find((reference) => reference.uf === state)
                    ?? (referencesByBase[base] ?? [])[0];
            })
            .filter(Boolean),
        [availableBases, dateByBase, referencesByBase, selectedBases, stateByBase],
    );

    const form = useForm({
        codigo: options.nextCode ?? '',
        descricao: '',
        cliente_empresa_id: '',
        categoria: options.categories?.find((category) => category.value === 'Unidades habitacionais - Construcao')?.value
            ?? options.categories?.[0]?.value
            ?? '',
        prazo_entrega_at: '',
        permitir_insumos_preco_zerado: false,
        is_licitacao: false,
        licitacao_tipo: '',
        licitacao_abertura_at: '',
        licitacao_processo: '',
        arredondamento: 'truncate_all_2',
        encargos_sociais: 'desonerado',
        bdi_tipo: 'unit_price',
        bdi_percentual: '0,00',
        base_references: [],
    });

    const updateField = (field, value) => {
        form.clearErrors(field);
        form.setData(field, value);
    };

    const validateStepOne = () => {
        const errors = {};

        if (!form.data.codigo.trim()) {
            errors.codigo = 'Informe o código do orçamento.';
        }

        if (!form.data.descricao.trim()) {
            errors.descricao = 'Informe a descrição do orçamento.';
        }

        if (!form.data.categoria) {
            errors.categoria = 'Selecione a categoria.';
        }

        if (form.data.is_licitacao) {
            if (!form.data.licitacao_tipo) {
                errors.licitacao_tipo = 'Informe o tipo de licitação.';
            }

            if (!form.data.licitacao_abertura_at) {
                errors.licitacao_abertura_at = 'Informe a abertura da licitação.';
            }

            if (!form.data.licitacao_processo.trim()) {
                errors.licitacao_processo = 'Informe o número do processo.';
            }
        }

        return errors;
    };

    const validateStepTwo = () => {
        const errors = {};

        if (!form.data.arredondamento) {
            errors.arredondamento = 'Selecione o arredondamento.';
        }

        if (!form.data.encargos_sociais) {
            errors.encargos_sociais = 'Selecione os encargos sociais.';
        }

        if (!form.data.bdi_tipo) {
            errors.bdi_tipo = 'Selecione a incidência do BDI.';
        }

        if (!String(form.data.bdi_percentual).trim()) {
            errors.bdi_percentual = 'Informe o percentual de BDI.';
        }

        return errors;
    };

    const goToStep = (nextStep) => {
        if (nextStep === 2) {
            const errors = validateStepOne();

            if (Object.keys(errors).length > 0) {
                form.setError(errors);
                setStep(1);
                return;
            }
        }

        if (nextStep === 3) {
            const firstStepErrors = validateStepOne();
            const secondStepErrors = validateStepTwo();
            const errors = { ...firstStepErrors, ...secondStepErrors };

            if (Object.keys(errors).length > 0) {
                form.setError(errors);
                setStep(Object.keys(firstStepErrors).length > 0 ? 1 : 2);
                return;
            }
        }

        form.clearErrors();
        setStep(nextStep);
    };

    const submit = (event) => {
        event.preventDefault();

        const firstStepErrors = validateStepOne();
        const secondStepErrors = validateStepTwo();
        const errors = { ...firstStepErrors, ...secondStepErrors };

        if (selectedBaseReferences.length === 0) {
            errors.base_references = 'Selecione pelo menos uma base de referência.';
        }

        if (Object.keys(errors).length > 0) {
            form.setError(errors);
            setStep(Object.keys(firstStepErrors).length > 0 ? 1 : Object.keys(secondStepErrors).length > 0 ? 2 : 3);
            return;
        }

        form.clearErrors();
        setIsSubmitting(true);

        router.post(
            route('tenant.orcamentos.store', tenant.slug),
            {
                ...form.data,
                base_references: selectedBaseReferences,
            },
            {
                preserveScroll: true,
                onError: (serverErrors) => {
                    form.setError(serverErrors);

                    const stepOneFields = ['codigo', 'descricao', 'cliente_empresa_id', 'categoria', 'prazo_entrega_at', 'licitacao_tipo', 'licitacao_abertura_at', 'licitacao_processo'];
                    const stepTwoFields = ['arredondamento', 'encargos_sociais', 'bdi_tipo', 'bdi_percentual'];
                    const hasStepOneError = stepOneFields.some((field) => Boolean(serverErrors[field]));
                    const hasStepTwoError = stepTwoFields.some((field) => Boolean(serverErrors[field]));

                    setStep(hasStepOneError ? 1 : hasStepTwoError ? 2 : 3);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    return (
        <OrcamentoShell
            tenant={tenant}
            active="orcamentos"
            title="Criar orçamento"
            subtitle="Defina as informações gerais, regras de cálculo e bases oficiais que formarão o orçamento."
            showNav={false}
        >
            <div className="mb-5">
                <Link className="sig-btn sig-btn-secondary" href={route('tenant.orcamentos.index', tenant.slug)}>
                    <ArrowLeft size={15} />
                    Voltar
                </Link>
            </div>

            <form className="sig-card overflow-hidden" onSubmit={submit}>
                <StepTabs step={step} onStepChange={goToStep} />

                {step === 1 && (
                    <GeneralStep
                        categories={options.categories ?? []}
                        clients={options.clients ?? []}
                        form={form}
                        licitacaoTipos={options.licitacaoTipos ?? []}
                        onChange={updateField}
                        onNext={() => goToStep(2)}
                    />
                )}

                {step === 2 && (
                    <CalculationStep
                        bdiTypes={options.bdiTypes ?? []}
                        encargosOptions={options.encargosOptions ?? []}
                        form={form}
                        onBack={() => setStep(1)}
                        onChange={updateField}
                        onNext={() => goToStep(3)}
                        roundingMethods={options.roundingMethods ?? []}
                    />
                )}

                {step === 3 && (
                    <ReferenceStep
                        availableBases={availableBases}
                        dateByBase={dateByBase}
                        form={form}
                        isSubmitting={isSubmitting}
                        onBack={() => setStep(2)}
                        referencesByBase={referencesByBase}
                        selectedBaseReferences={selectedBaseReferences}
                        selectedBases={selectedBases}
                        setDateByBase={setDateByBase}
                        setSelectedBases={setSelectedBases}
                        setStateByBase={setStateByBase}
                        stateByBase={stateByBase}
                    />
                )}
            </form>
        </OrcamentoShell>
    );
}

function StepTabs({ step, onStepChange }) {
    const tabs = [
        { step: 1, title: 'Passo 1', subtitle: 'Informações gerais' },
        { step: 2, title: 'Passo 2', subtitle: 'Arredondamento, encargos e BDI' },
        { step: 3, title: 'Passo 3', subtitle: 'Bases' },
    ];

    return (
        <div className="flex flex-wrap gap-2 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 pt-4">
            {tabs.map((tab) => {
                const active = step === tab.step;

                return (
                    <button
                        key={tab.step}
                        className={`min-w-[210px] border border-b-0 px-4 py-3 text-left transition ${
                            active
                                ? 'border-[var(--border)] bg-white text-[var(--primary)]'
                                : 'border-transparent text-[var(--ink-500)] hover:text-[var(--primary)]'
                        }`}
                        type="button"
                        onClick={() => onStepChange(tab.step)}
                    >
                        <span className={`block border-l-4 pl-3 text-sm font-bold ${active ? 'border-[var(--primary)]' : 'border-[var(--ink-300)]'}`}>
                            {tab.title}
                        </span>
                        <span className="mt-1 block pl-4 text-xs">{tab.subtitle}</span>
                    </button>
                );
            })}
        </div>
    );
}

function GeneralStep({ categories, clients, form, licitacaoTipos, onChange, onNext }) {
    return (
        <section className="p-5">
            <div className="grid gap-4 lg:grid-cols-3">
                <Field label="Código*" error={form.errors.codigo}>
                    <input
                        className="sig-input"
                        type="text"
                        value={form.data.codigo}
                        onChange={(event) => onChange('codigo', event.target.value)}
                    />
                </Field>

                <Field className="lg:col-span-2" label="Descrição*" error={form.errors.descricao}>
                    <input
                        className="sig-input"
                        placeholder="Ex: Orçamento executivo do contrato"
                        type="text"
                        value={form.data.descricao}
                        onChange={(event) => onChange('descricao', event.target.value)}
                    />
                </Field>

                <Field label="Cliente" error={form.errors.cliente_empresa_id}>
                    <select
                        className="sig-input"
                        value={form.data.cliente_empresa_id}
                        onChange={(event) => onChange('cliente_empresa_id', event.target.value)}
                    >
                        <option value="">Selecione o cliente</option>
                        {clients.map((client) => (
                            <option key={client.id} value={client.id}>
                                {client.nome}{client.sigla ? ` - ${client.sigla}` : ''}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field label="Categoria*" error={form.errors.categoria}>
                    <select
                        className="sig-input"
                        value={form.data.categoria}
                        onChange={(event) => onChange('categoria', event.target.value)}
                    >
                        {categories.map((category) => (
                            <option key={category.value} value={category.value}>
                                {category.label}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field label="Prazo de entrega do orçamento" error={form.errors.prazo_entrega_at}>
                    <input
                        className="sig-input"
                        type="datetime-local"
                        value={form.data.prazo_entrega_at}
                        onChange={(event) => onChange('prazo_entrega_at', event.target.value)}
                    />
                </Field>
            </div>

            <div className="mt-5 grid gap-3">
                <label className="inline-flex items-center gap-2 text-sm font-medium text-[var(--ink-700)]">
                    <input
                        checked={form.data.permitir_insumos_preco_zerado}
                        className="h-4 w-4 accent-[var(--primary)]"
                        type="checkbox"
                        onChange={(event) => onChange('permitir_insumos_preco_zerado', event.target.checked)}
                    />
                    Permitir insumos com preço zerado
                </label>

                <label className="inline-flex items-center gap-2 text-sm font-medium text-[var(--ink-700)]">
                    <input
                        checked={form.data.is_licitacao}
                        className="h-4 w-4 accent-[var(--primary)]"
                        type="checkbox"
                        onChange={(event) => onChange('is_licitacao', event.target.checked)}
                    />
                    Licitação
                </label>
            </div>

            {form.data.is_licitacao && (
                <div className="mt-5 grid gap-4 border-t border-[var(--border)] pt-5 lg:grid-cols-3">
                    <Field label="Tipo de licitação" error={form.errors.licitacao_tipo}>
                        <select
                            className="sig-input"
                            value={form.data.licitacao_tipo}
                            onChange={(event) => onChange('licitacao_tipo', event.target.value)}
                        >
                            <option value="">Selecione</option>
                            {licitacaoTipos.map((tipo) => (
                                <option key={tipo.value} value={tipo.value}>
                                    {tipo.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Data e hora de abertura" error={form.errors.licitacao_abertura_at}>
                        <input
                            className="sig-input"
                            type="datetime-local"
                            value={form.data.licitacao_abertura_at}
                            onChange={(event) => onChange('licitacao_abertura_at', event.target.value)}
                        />
                    </Field>

                    <Field label="Número do processo" error={form.errors.licitacao_processo}>
                        <input
                            className="sig-input"
                            placeholder="Ex: 001/2026"
                            type="text"
                            value={form.data.licitacao_processo}
                            onChange={(event) => onChange('licitacao_processo', event.target.value)}
                        />
                    </Field>
                </div>
            )}

            <div className="mt-5 flex justify-end">
                <button className="sig-btn sig-btn-primary" type="button" onClick={onNext}>
                    Próximo
                </button>
            </div>
        </section>
    );
}

function CalculationStep({
    bdiTypes,
    encargosOptions,
    form,
    onBack,
    onChange,
    onNext,
    roundingMethods,
}) {
    return (
        <section className="p-5">
            <div className="grid gap-6 lg:grid-cols-2">
                <fieldset>
                    <legend className="mb-3 text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">
                        Arredondamento do orçamento
                    </legend>
                    <div className="grid gap-2">
                        {roundingMethods.map((method) => (
                            <RadioOption
                                key={method.value}
                                checked={form.data.arredondamento === method.value}
                                name="arredondamento"
                                onChange={() => onChange('arredondamento', method.value)}
                            >
                                {method.label}
                                {method.badge && <Badge>{method.badge}</Badge>}
                            </RadioOption>
                        ))}
                    </div>
                    {form.errors.arredondamento && <ErrorMessage message={form.errors.arredondamento} />}
                </fieldset>

                <fieldset>
                    <legend className="mb-3 text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">
                        Encargos sociais
                    </legend>
                    <div className="grid gap-2">
                        {encargosOptions.map((option) => (
                            <RadioOption
                                key={option.value}
                                checked={form.data.encargos_sociais === option.value}
                                name="encargos_sociais"
                                onChange={() => onChange('encargos_sociais', option.value)}
                            >
                                {option.label}
                            </RadioOption>
                        ))}
                    </div>
                    {form.errors.encargos_sociais && <ErrorMessage message={form.errors.encargos_sociais} />}
                </fieldset>
            </div>

            <div className="mt-6 border-t border-[var(--border)] pt-5">
                <fieldset>
                    <legend className="mb-3 text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">
                        BDI - Benefícios e Despesas Indiretas
                    </legend>
                    <div className="grid gap-2">
                        {bdiTypes.map((type) => (
                            <RadioOption
                                key={type.value}
                                checked={form.data.bdi_tipo === type.value}
                                name="bdi_tipo"
                                onChange={() => onChange('bdi_tipo', type.value)}
                            >
                                {type.label}
                                {type.badge && <Badge>{type.badge}</Badge>}
                            </RadioOption>
                        ))}
                    </div>
                    {form.errors.bdi_tipo && <ErrorMessage message={form.errors.bdi_tipo} />}
                </fieldset>

                <Field className="mt-4 max-w-xl" label="Percentual de BDI (%)" error={form.errors.bdi_percentual}>
                    <input
                        className="sig-input"
                        placeholder="0,00"
                        type="text"
                        value={form.data.bdi_percentual}
                        onChange={(event) => onChange('bdi_percentual', event.target.value)}
                    />
                </Field>
            </div>

            <div className="mt-6 flex flex-wrap justify-between gap-3">
                <button className="sig-btn sig-btn-secondary" type="button" onClick={onBack}>
                    Voltar
                </button>
                <button className="sig-btn sig-btn-primary" type="button" onClick={onNext}>
                    Próximo
                </button>
            </div>
        </section>
    );
}

function ReferenceStep({
    availableBases,
    dateByBase,
    form,
    isSubmitting,
    onBack,
    referencesByBase,
    selectedBaseReferences,
    selectedBases,
    setDateByBase,
    setSelectedBases,
    setStateByBase,
    stateByBase,
}) {
    const toggleBase = (base, checked) => {
        setSelectedBases((current) => ({ ...current, [base]: checked }));
    };

    const updateState = (base, state) => {
        const firstDate = dateOptionsForBase(referencesByBase, base, state)[0] ?? '';

        setStateByBase((current) => ({ ...current, [base]: state }));
        setDateByBase((current) => ({ ...current, [base]: firstDate }));
    };

    return (
        <section className="p-4">
            <div className="mb-3 flex flex-wrap gap-2">
                {selectedBaseReferences.length === 0 ? (
                    <span className="rounded-md bg-[var(--surface-muted)] px-3 py-1 text-xs font-bold text-[var(--ink-500)]">
                        Nenhuma base selecionada
                    </span>
                ) : selectedBaseReferences.map((reference) => (
                    <span key={reference.codigo} className="inline-flex items-center gap-1 rounded-md bg-[var(--primary)] px-2 py-1 text-xs font-bold text-white">
                        {reference.nome} {reference.uf} {reference.data}
                    </span>
                ))}
            </div>

            <div className="overflow-hidden rounded-lg border border-[var(--border)]">
                <div className="border-b border-[var(--border)] bg-[var(--surface-muted)] px-4 py-3 text-center">
                    <h2 className="text-sm font-bold text-[var(--ink-900)]">Bases nacionais</h2>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                        Selecione uma ou mais bases, depois escolha a UF e a versão de referência.
                    </p>
                </div>

                <div className="divide-y divide-[var(--border)] bg-white">
                    {availableBases.length === 0 ? (
                        <div className="m-3 rounded-lg border border-dashed border-[var(--border)] bg-white p-4 text-sm text-[var(--ink-500)]">
                            Nenhuma base encontrada. Importe SINAPI ou SICRO3 antes de criar o orçamento.
                        </div>
                    ) : availableBases.map((base) => {
                        const states = stateOptionsForBase(referencesByBase, base);
                        const currentState = stateByBase[base] ?? states[0]?.value ?? '';
                        const dates = dateOptionsForBase(referencesByBase, base, currentState);
                        const currentDate = dateByBase[base] ?? dates[0] ?? '';
                        const checked = Boolean(selectedBases[base]);

                        return (
                            <div
                                key={base}
                                className={`grid gap-3 px-4 py-3 transition md:grid-cols-[32px_minmax(120px,1fr)_minmax(220px,1.3fr)_minmax(220px,1.3fr)] md:items-center ${
                                    checked ? 'bg-[var(--primary-50)]/70' : 'bg-white hover:bg-[var(--surface-muted)]/70'
                                }`}
                            >
                                <label className="flex items-center gap-2">
                                    <input
                                        checked={checked}
                                        className="h-4 w-4 accent-[var(--primary)]"
                                        type="checkbox"
                                        onChange={(event) => toggleBase(base, event.target.checked)}
                                    />
                                    <span className="text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-400)] md:hidden">
                                        Selecionar
                                    </span>
                                </label>

                                <div>
                                    <p className="text-sm font-bold text-[var(--ink-900)]">{base}</p>
                                    <p className="mt-0.5 text-[11px] font-semibold text-[var(--ink-400)]">Base oficial</p>
                                </div>

                                <label className="grid gap-1">
                                    <span className="text-[11px] font-bold uppercase tracking-[0.06em] text-[var(--ink-400)]">Local</span>
                                    <select
                                        className="sig-input !h-10 !min-h-10 text-sm"
                                        value={currentState}
                                        onChange={(event) => updateState(base, event.target.value)}
                                    >
                                        {states.map((state) => (
                                            <option key={state.value} value={state.value}>
                                                {state.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label className="grid gap-1">
                                    <span className="text-[11px] font-bold uppercase tracking-[0.06em] text-[var(--ink-400)]">Versão</span>
                                    <select
                                        className="sig-input !h-10 !min-h-10 text-sm"
                                        value={currentDate}
                                        onChange={(event) => setDateByBase((current) => ({ ...current, [base]: event.target.value }))}
                                    >
                                        {dates.map((date) => (
                                            <option key={date} value={date}>
                                                {date}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                        );
                    })}
                </div>
            </div>

            {form.errors.base_references && <ErrorMessage message={form.errors.base_references} />}

            <div className="mt-5 flex flex-wrap justify-between gap-3">
                <button className="sig-btn sig-btn-secondary" type="button" onClick={onBack}>
                    Voltar
                </button>
                <button className="sig-btn sig-btn-primary" disabled={isSubmitting || selectedBaseReferences.length === 0} type="submit">
                    <Save size={15} />
                    {isSubmitting ? 'Salvando...' : 'Salvar orçamento'}
                </button>
            </div>
        </section>
    );
}

function Field({ children, className = '', error, label }) {
    return (
        <label className={`block ${className}`}>
            <span className="mb-1 block text-xs font-bold text-[var(--ink-500)]">{label}</span>
            {children}
            {error && <ErrorMessage message={error} />}
        </label>
    );
}

function RadioOption({ checked, children, name, onChange }) {
    return (
        <label className="flex items-center gap-2 text-sm text-[var(--ink-700)]">
            <input
                checked={checked}
                className="h-4 w-4 accent-[var(--primary)]"
                name={name}
                type="radio"
                onChange={onChange}
            />
            <span className="inline-flex flex-wrap items-center gap-1">{children}</span>
        </label>
    );
}

function Badge({ children }) {
    return <span className="rounded bg-emerald-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{children}</span>;
}

function ErrorMessage({ message }) {
    return <p className="mt-1 text-xs font-semibold text-rose-600">{message}</p>;
}

function groupReferencesByBase(references) {
    return references.reduce((groups, reference) => {
        if (!['SINAPI', 'SICRO3'].includes(reference.nome)) {
            return groups;
        }

        const base = reference.nome;
        groups[base] = groups[base] ?? [];
        groups[base].push(reference);

        return groups;
    }, {});
}

function buildInitialStateByBase(referencesByBase) {
    return Object.fromEntries(
        Object.entries(referencesByBase).map(([base, references]) => [
            base,
            references.find((reference) => reference.uf === 'PA')?.uf ?? references[0]?.uf ?? '',
        ]),
    );
}

function buildInitialDateByBase(referencesByBase, stateByBase) {
    return Object.fromEntries(
        Object.entries(referencesByBase).map(([base, references]) => {
            const state = stateByBase[base] ?? references[0]?.uf ?? '';
            const reference = references.find((item) => item.uf === state) ?? references[0];

            return [base, reference?.data ?? ''];
        }),
    );
}

function stateOptionsForBase(referencesByBase, base) {
    const states = new Map();

    (referencesByBase[base] ?? []).forEach((reference) => {
        states.set(reference.uf, reference.localidade ?? reference.uf);
    });

    return Array.from(states.entries())
        .map(([value, label]) => ({ value, label }))
        .sort((a, b) => a.label.localeCompare(b.label));
}

function dateOptionsForBase(referencesByBase, base, state) {
    return Array.from(new Set(
        (referencesByBase[base] ?? [])
            .filter((reference) => reference.uf === state)
            .map((reference) => reference.data)
            .filter(Boolean),
    )).sort((a, b) => referenceDateScore(b) - referenceDateScore(a));
}

function referenceDateScore(value) {
    const [month, year] = String(value).split('/').map((part) => Number.parseInt(part, 10));

    if (!month || !year) {
        return 0;
    }

    return year * 100 + month;
}
