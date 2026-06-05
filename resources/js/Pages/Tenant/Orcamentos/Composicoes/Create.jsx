import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, ChevronDown, Save } from 'lucide-react';
import { useMemo, useState } from 'react';
import OrcamentoShell from '../Partials/OrcamentoShell';

export default function CreateComposicao({ tenant, options = {} }) {
    const [step, setStep] = useState(1);
    const [activeRegion, setActiveRegion] = useState('Todas as Bases');
    const [selectedReferences, setSelectedReferences] = useState(['SINAPI-PA-04/2026']);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const states = options.states ?? [];
    const types = options.types ?? [];
    const calculationMethods = options.calculationMethods ?? [];
    const referenceGroups = options.baseReferences ?? [];
    const allReferences = useMemo(
        () => referenceGroups.flatMap((group) => group.items ?? []),
        [referenceGroups],
    );
    const filteredGroups = activeRegion === 'Todas as Bases'
        ? referenceGroups
        : referenceGroups.filter((group) => group.region === activeRegion);
    const selectedReferenceItems = allReferences.filter((reference) => selectedReferences.includes(reference.codigo));

    const form = useForm({
        codigo: '',
        descricao: '',
        tipo_composicao: types[0]?.value ?? '',
        unidade: '',
        uf: 'PA',
        modelo: 'SINAPI',
        metodo_calculo: 'truncate_2',
        observacao: '',
        base_references: [],
    });

    const updateField = (field, value) => {
        form.clearErrors(field);
        form.setData(field, value);
    };

    const validateGeneralFields = () => {
        const errors = {};

        if (!form.data.codigo.trim()) {
            errors.codigo = 'Informe o codigo da composicao.';
        }

        if (!form.data.descricao.trim()) {
            errors.descricao = 'Informe a descricao da composicao.';
        }

        if (!form.data.tipo_composicao) {
            errors.tipo_composicao = 'Selecione o tipo de composicao.';
        }

        if (!form.data.unidade.trim()) {
            errors.unidade = 'Informe a unidade.';
        }

        if (!form.data.uf) {
            errors.uf = 'Selecione o estado.';
        }

        if (!form.data.metodo_calculo) {
            errors.metodo_calculo = 'Selecione o metodo de calculo.';
        }

        return errors;
    };

    const goToReferences = () => {
        const errors = validateGeneralFields();

        if (Object.keys(errors).length > 0) {
            form.setError(errors);
            setStep(1);
            return;
        }

        form.clearErrors('codigo', 'descricao', 'tipo_composicao', 'unidade', 'uf', 'metodo_calculo');
        setStep(2);
    };

    const toggleReference = (codigo) => {
        setSelectedReferences((current) => (
            current.includes(codigo)
                ? current.filter((item) => item !== codigo)
                : [...current, codigo]
        ));
    };

    const submit = (event) => {
        event.preventDefault();

        const generalErrors = validateGeneralFields();

        if (Object.keys(generalErrors).length > 0) {
            form.setError(generalErrors);
            setStep(1);
            return;
        }

        if (selectedReferenceItems.length === 0) {
            form.setError('base_references', 'Selecione pelo menos uma base de referencia.');
            setStep(2);
            return;
        }

        form.clearErrors();
        setIsSubmitting(true);

        router.post(
            route('tenant.orcamentos.composicoes.store', tenant.slug),
            {
                ...form.data,
                base_references: selectedReferenceItems,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    form.setError(errors);

                    const firstStepFields = ['codigo', 'descricao', 'tipo_composicao', 'unidade', 'uf', 'modelo', 'metodo_calculo', 'observacao'];
                    const hasFirstStepError = firstStepFields.some((field) => Boolean(errors[field]));

                    setStep(hasFirstStepError ? 1 : 2);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    return (
        <OrcamentoShell
            tenant={tenant}
            active="composicoes"
            title="Criar Nova Composicao"
            subtitle="Formulario de cadastro de composicao para base propria."
            showNav={false}
        >
            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <Link className="sig-btn sig-btn-secondary" href={route('tenant.orcamentos.composicoes.index', tenant.slug)}>
                    <ArrowLeft size={15} />
                    Voltar
                </Link>
            </div>

            <form className="sig-card overflow-hidden" onSubmit={submit}>
                <StepTabs step={step} onStepChange={(nextStep) => (nextStep === 2 ? goToReferences() : setStep(nextStep))} />

                {step === 1 ? (
                    <GeneralStep
                        calculationMethods={calculationMethods}
                        form={form}
                        onChange={updateField}
                        onNext={goToReferences}
                        states={states}
                        types={types}
                    />
                ) : (
                    <ReferenceStep
                        activeRegion={activeRegion}
                        allReferences={allReferences}
                        form={form}
                        filteredGroups={filteredGroups}
                        isSubmitting={isSubmitting}
                        onBack={() => setStep(1)}
                        onRegionChange={setActiveRegion}
                        onSelectAll={() => setSelectedReferences(allReferences.map((reference) => reference.codigo))}
                        onSelectLatest={() => setSelectedReferences(['SINAPI-PA-04/2026'])}
                        onSetSelectedReferences={setSelectedReferences}
                        onUnselectAll={() => setSelectedReferences([])}
                        selectedReferenceItems={selectedReferenceItems}
                        selectedReferences={selectedReferences}
                        toggleReference={toggleReference}
                    />
                )}
            </form>
        </OrcamentoShell>
    );
}

function StepTabs({ step, onStepChange }) {
    const tabs = [
        { step: 1, title: 'Passo 1', subtitle: 'Informacoes Gerais' },
        { step: 2, title: 'Passo 2', subtitle: 'Bases de referencia' },
    ];

    return (
        <div className="flex flex-wrap gap-2 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 pt-4">
            {tabs.map((tab) => {
                const active = step === tab.step;

                return (
                    <button
                        key={tab.step}
                        className={`min-w-[180px] border border-b-0 px-4 py-3 text-left transition ${
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

function GeneralStep({ calculationMethods, form, onChange, onNext, states, types }) {
    return (
        <section className="p-5">
            <div className="grid gap-4 lg:grid-cols-2">
                <Field label="Codigo" error={form.errors.codigo}>
                    <input
                        className="sig-input"
                        placeholder="00000003"
                        type="text"
                        value={form.data.codigo}
                        onChange={(event) => onChange('codigo', event.target.value)}
                    />
                </Field>

                <Field label="Unidade" error={form.errors.unidade}>
                    <input
                        className="sig-input"
                        placeholder="m, m2, m3, un, kg ou h"
                        type="text"
                        value={form.data.unidade}
                        onChange={(event) => onChange('unidade', event.target.value)}
                    />
                </Field>

                <Field className="lg:col-span-2" label="Descricao" error={form.errors.descricao}>
                    <input
                        className="sig-input"
                        placeholder="Nome da composicao"
                        type="text"
                        value={form.data.descricao}
                        onChange={(event) => onChange('descricao', event.target.value)}
                    />
                </Field>

                <Field label="Tipo de Composicao" error={form.errors.tipo_composicao}>
                    <select
                        className="sig-input"
                        value={form.data.tipo_composicao}
                        onChange={(event) => onChange('tipo_composicao', event.target.value)}
                    >
                        {types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field label="Estado" error={form.errors.uf}>
                    <select
                        className="sig-input"
                        value={form.data.uf}
                        onChange={(event) => onChange('uf', event.target.value)}
                    >
                        {states.map((state) => (
                            <option key={state.value} value={state.value}>
                                {state.label}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>

            <div className="mt-5 grid gap-5 lg:grid-cols-2">
                <fieldset>
                    <legend className="mb-2 text-xs font-bold text-[var(--ink-500)]">Modelo Composicao</legend>
                    <label className="inline-flex items-center gap-2 text-sm font-medium text-[var(--ink-700)]">
                        <input checked readOnly className="h-4 w-4 accent-[var(--primary)]" type="radio" />
                        Sinapi
                    </label>
                </fieldset>

                <fieldset>
                    <legend className="mb-2 text-xs font-bold text-[var(--ink-500)]">Metodo de Calculo</legend>
                    <div className="grid gap-2">
                        {calculationMethods.map((method) => (
                            <label key={method.value} className="flex items-center gap-2 text-sm text-[var(--ink-700)]">
                                <input
                                    checked={form.data.metodo_calculo === method.value}
                                    className="h-4 w-4 accent-[var(--primary)]"
                                    name="metodo_calculo"
                                    type="radio"
                                    value={method.value}
                                    onChange={(event) => onChange('metodo_calculo', event.target.value)}
                                />
                                {method.label}
                                {method.badge && (
                                    <span className="rounded bg-emerald-500 px-1.5 py-0.5 text-[10px] font-bold text-white">
                                        {method.badge}
                                    </span>
                                )}
                            </label>
                        ))}
                    </div>
                    {form.errors.metodo_calculo && <ErrorMessage message={form.errors.metodo_calculo} />}
                </fieldset>
            </div>

            <Field className="mt-5" label="Observacao" error={form.errors.observacao}>
                <textarea
                    className="sig-input min-h-[110px] resize-y py-3"
                    placeholder="Observacoes tecnicas da composicao"
                    value={form.data.observacao}
                    onChange={(event) => onChange('observacao', event.target.value)}
                />
            </Field>

            <div className="mt-5 flex justify-end">
                <button className="sig-btn sig-btn-primary" type="button" onClick={onNext}>
                    Proximo
                </button>
            </div>
        </section>
    );
}

function ReferenceStep({
    activeRegion,
    allReferences,
    filteredGroups,
    form,
    onBack,
    isSubmitting,
    onRegionChange,
    onSelectAll,
    onSelectLatest,
    onSetSelectedReferences,
    onUnselectAll,
    selectedReferenceItems,
    selectedReferences,
    toggleReference,
}) {
    const regions = ['Todas as Bases', 'Nacional', 'Sudeste', 'Nordeste', 'Centro-Oeste', 'Norte', 'Sul'];

    return (
        <section className="p-4">
            <div className="mb-3 flex flex-wrap gap-2">
                {selectedReferenceItems.length === 0 ? (
                    <span className="rounded-md bg-[var(--surface-muted)] px-3 py-1 text-xs font-bold text-[var(--ink-500)]">
                        Nenhuma base selecionada
                    </span>
                ) : selectedReferenceItems.map((reference) => (
                    <span key={reference.codigo} className="inline-flex items-center gap-1 rounded-md bg-[var(--primary)] px-2 py-1 text-xs font-bold text-white">
                        {reference.codigo}
                        <button type="button" onClick={() => toggleReference(reference.codigo)}>
                            x
                        </button>
                    </span>
                ))}
            </div>

            <div className="overflow-hidden rounded-lg border border-[var(--border)]">
                <div className="flex flex-wrap gap-1 border-b border-[var(--border)] bg-[var(--surface-muted)] p-2">
                    {regions.map((region) => (
                        <button
                            key={region}
                            className={`rounded-md px-2.5 py-1.5 text-xs font-bold transition ${
                                activeRegion === region
                                    ? 'bg-white text-[var(--primary)] shadow-[var(--shadow-sm)]'
                                    : 'text-[var(--ink-500)] hover:bg-white'
                            }`}
                            type="button"
                            onClick={() => onRegionChange(region)}
                        >
                            {region}
                        </button>
                    ))}
                </div>

                <div className="max-h-[280px] overflow-y-auto p-3">
                    {filteredGroups.map((group) => (
                        <div key={group.region} className="mb-3 last:mb-0">
                            <label className="mb-1.5 flex items-center gap-2 text-sm font-bold text-[var(--ink-500)]">
                                <input
                                    checked={(group.items ?? []).every((item) => selectedReferences.includes(item.codigo))}
                                    className="h-4 w-4 accent-[var(--primary)]"
                                    type="checkbox"
                                    onChange={(event) => {
                                        const itemCodes = (group.items ?? []).map((item) => item.codigo);

                                        if (event.target.checked) {
                                            onSetSelectedReferences(Array.from(new Set([...selectedReferences, ...itemCodes])));
                                            return;
                                        }

                                        onSetSelectedReferences(selectedReferences.filter((codigo) => !itemCodes.includes(codigo)));
                                    }}
                                />
                                {group.region}
                            </label>

                            <div className="divide-y divide-[var(--border)] border-t border-[var(--border)]">
                                {(group.items ?? []).map((reference) => (
                                    <label key={reference.codigo} className="grid cursor-pointer items-center gap-2 py-2 text-sm text-[var(--ink-700)] md:grid-cols-[minmax(140px,0.8fr)_minmax(160px,1fr)_minmax(80px,0.5fr)_auto]">
                                        <span className="inline-flex items-center gap-2 font-semibold">
                                            <input
                                                checked={selectedReferences.includes(reference.codigo)}
                                                className="h-4 w-4 accent-[var(--primary)]"
                                                type="checkbox"
                                                onChange={() => toggleReference(reference.codigo)}
                                            />
                                            {reference.nome}
                                        </span>
                                        <span>{reference.localidade}</span>
                                        <span>{reference.data}</span>
                                        <ChevronDown className="hidden text-[var(--ink-400)] md:block" size={16} />
                                    </label>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="sticky bottom-0 grid gap-2 border-t border-[var(--border)] bg-white p-3 shadow-[0_-8px_18px_rgba(15,23,42,0.04)] md:grid-cols-4">
                    <button className="sig-btn sig-btn-secondary justify-center" type="button" onClick={onSelectAll}>
                        Selecionar Todos
                    </button>
                    <button className="sig-btn sig-btn-secondary justify-center" type="button" onClick={onUnselectAll}>
                        Desmarcar Todos
                    </button>
                    <button className="sig-btn sig-btn-secondary justify-center" type="button" onClick={onSelectLatest}>
                        Ultima Data
                    </button>
                    <button className="sig-btn sig-btn-primary justify-center" disabled={isSubmitting || selectedReferenceItems.length === 0} type="submit">
                        <Save size={15} />
                        {isSubmitting ? 'Salvando...' : 'Salvar'}
                    </button>
                </div>
            </div>

            {form.errors.base_references && <ErrorMessage message={form.errors.base_references} />}

            <div className="mt-4">
                <button className="sig-btn sig-btn-secondary" type="button" onClick={onBack}>
                    Voltar para informacoes gerais
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

function ErrorMessage({ message }) {
    return <p className="mt-1 text-xs font-semibold text-rose-600">{message}</p>;
}
