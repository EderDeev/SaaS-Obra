import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import { useMemo, useState } from 'react';
import OrcamentoShell from '../Partials/OrcamentoShell';

export default function CreateComposicao({ tenant, options = {} }) {
    const states = options.states ?? [];
    const types = options.types ?? [];
    const calculationMethods = options.calculationMethods ?? [];
    const referenceGroups = options.baseReferences ?? [];
    const initialReferences = referenceGroups.flatMap((group) => group.items ?? []);
    const initialReference = initialReferences.find((reference) => reference.nome === 'SINAPI' && reference.uf === 'PA')
        ?? initialReferences.find((reference) => reference.nome === 'SINAPI')
        ?? initialReferences[0];
    const [step, setStep] = useState(1);
    const [selectedReferences, setSelectedReferences] = useState(initialReference ? [initialReference.codigo] : []);
    const [referenceDatesByChoice, setReferenceDatesByChoice] = useState({});
    const [isSubmitting, setIsSubmitting] = useState(false);
    const allReferences = useMemo(
        () => referenceGroups.flatMap((group) => group.items ?? []),
        [referenceGroups],
    );
    const selectedReferenceItems = allReferences.filter((reference) => selectedReferences.includes(reference.codigo));

    const form = useForm({
        codigo: '',
        descricao: '',
        tipo_composicao: types[0]?.value ?? '',
        unidade: '',
        uf: 'PA',
        modelo: 'SINAPI',
        metodo_calculo: 'truncate_2',
        producao_equipe: '1,0000',
        adicional_mao_obra: '',
        fator_influencia_chuvas: '',
        observacao: '',
        base_references: [],
    });
    const referenceChoices = useMemo(() => {
        const groups = new Map();
        const stateReferences = allReferences.filter((reference) => reference.uf === form.data.uf);

        stateReferences.forEach((reference) => {
            const key = `${reference.nome}|${reference.uf}`;

            if (!groups.has(key)) {
                groups.set(key, {
                    key,
                    nome: reference.nome,
                    uf: reference.uf,
                    localidade: reference.localidade,
                    references: [],
                });
            }

            groups.get(key).references.push(reference);
        });

        return Array.from(groups.values())
            .map((choice) => ({
                ...choice,
                references: choice.references.sort((a, b) => String(b.data).localeCompare(String(a.data))),
            }))
            .sort((a, b) => `${a.nome}-${a.uf}`.localeCompare(`${b.nome}-${b.uf}`));
    }, [allReferences, form.data.uf]);

    const updateField = (field, value) => {
        form.clearErrors(field);
        form.setData(field, value);
    };

    const selectDefaultReference = (model, state) => {
        const stateReferences = allReferences.filter((item) => item.uf === state);
        const reference = stateReferences.find((item) => item.nome === model)
            ?? stateReferences[0];

        setSelectedReferences(reference ? [reference.codigo] : []);
    };

    const updateModelo = (value) => {
        form.clearErrors('modelo', 'producao_equipe', 'adicional_mao_obra', 'fator_influencia_chuvas');
        selectDefaultReference(value, form.data.uf);

        form.setData({
            ...form.data,
            modelo: value,
            producao_equipe: value === 'SICRO3' && !form.data.producao_equipe ? '1,0000' : form.data.producao_equipe,
            adicional_mao_obra: value === 'SICRO3' ? form.data.adicional_mao_obra : '',
            fator_influencia_chuvas: value === 'SICRO3' ? form.data.fator_influencia_chuvas : '',
        });
    };

    const updateEstado = (value) => {
        form.clearErrors('uf');
        selectDefaultReference(form.data.modelo, value);
        form.setData('uf', value);
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

        if (form.data.modelo !== 'SICRO3' && !form.data.metodo_calculo) {
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

                    const firstStepFields = ['codigo', 'descricao', 'tipo_composicao', 'unidade', 'uf', 'modelo', 'metodo_calculo', 'producao_equipe', 'adicional_mao_obra', 'fator_influencia_chuvas', 'observacao'];
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
                        onModeloChange={updateModelo}
                        onStateChange={updateEstado}
                        onNext={goToReferences}
                        states={states}
                        types={types}
                    />
                ) : (
                    <ReferenceStep
                        form={form}
                        isSubmitting={isSubmitting}
                        onBack={() => setStep(1)}
                        onSelectAll={() => setSelectedReferences(referenceChoices.map((choice) => referenceDatesByChoice[choice.key] ?? choice.references[0]?.codigo).filter(Boolean))}
                        onUnselectAll={() => setSelectedReferences([])}
                        referenceDatesByChoice={referenceDatesByChoice}
                        referenceChoices={referenceChoices}
                        setReferenceDatesByChoice={setReferenceDatesByChoice}
                        setSelectedReferences={setSelectedReferences}
                        selectedReferenceItems={selectedReferenceItems}
                        selectedReferences={selectedReferences}
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

function GeneralStep({ calculationMethods, form, onChange, onModeloChange, onNext, onStateChange, states, types }) {
    const isSicro3 = form.data.modelo === 'SICRO3';

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
                        onChange={(event) => onStateChange(event.target.value)}
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
                    <div className="flex flex-wrap gap-4">
                        <label className="inline-flex items-center gap-2 text-sm font-medium text-[var(--ink-700)]">
                            <input
                                checked={form.data.modelo === 'SINAPI'}
                                className="h-4 w-4 accent-[var(--primary)]"
                                name="modelo"
                                type="radio"
                                value="SINAPI"
                                onChange={(event) => onModeloChange(event.target.value)}
                            />
                            Sinapi
                        </label>
                        <label className="inline-flex items-center gap-2 text-sm font-medium text-[var(--ink-700)]">
                            <input
                                checked={form.data.modelo === 'SICRO3'}
                                className="h-4 w-4 accent-[var(--primary)]"
                                name="modelo"
                                type="radio"
                                value="SICRO3"
                                onChange={(event) => onModeloChange(event.target.value)}
                            />
                            Sicro3
                        </label>
                    </div>
                    {form.errors.modelo && <ErrorMessage message={form.errors.modelo} />}
                </fieldset>

                {!isSicro3 && (
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
                )}
            </div>

            {isSicro3 && (
                <div className="mt-5 grid gap-4 lg:grid-cols-3">
                    <Field label="Producao de Equipe" error={form.errors.producao_equipe}>
                        <input
                            className="sig-input"
                            placeholder="1,0000"
                            type="text"
                            value={form.data.producao_equipe}
                            onChange={(event) => onChange('producao_equipe', event.target.value)}
                        />
                    </Field>

                    <Field label="Adicional de Mao de Obra" error={form.errors.adicional_mao_obra}>
                        <input
                            className="sig-input"
                            placeholder="Opcional"
                            type="text"
                            value={form.data.adicional_mao_obra}
                            onChange={(event) => onChange('adicional_mao_obra', event.target.value)}
                        />
                    </Field>

                    <Field label="Fator de Influencia de Chuvas - FIC" error={form.errors.fator_influencia_chuvas}>
                        <input
                            className="sig-input"
                            placeholder="Opcional"
                            type="text"
                            value={form.data.fator_influencia_chuvas}
                            onChange={(event) => onChange('fator_influencia_chuvas', event.target.value)}
                        />
                    </Field>
                </div>
            )}

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
    form,
    onBack,
    isSubmitting,
    onSelectAll,
    onUnselectAll,
    referenceDatesByChoice,
    referenceChoices,
    setReferenceDatesByChoice,
    setSelectedReferences,
    selectedReferenceItems,
    selectedReferences,
}) {
    const toggleChoice = (choice, checked) => {
        const codes = choice.references.map((reference) => reference.codigo);
        const selectedDateCode = referenceDatesByChoice[choice.key] ?? choice.references[0].codigo;

        setSelectedReferences((current) => {
            const withoutChoice = current.filter((code) => !codes.includes(code));

            return checked ? [...withoutChoice, selectedDateCode] : withoutChoice;
        });
    };

    const updateChoiceDate = (choice, code) => {
        const codes = choice.references.map((reference) => reference.codigo);

        setReferenceDatesByChoice((current) => ({
            ...current,
            [choice.key]: code,
        }));

        setSelectedReferences((current) => {
            const hasChoiceSelected = current.some((selected) => codes.includes(selected));
            const withoutChoice = current.filter((selected) => !codes.includes(selected));

            return hasChoiceSelected ? [...withoutChoice, code] : current;
        });
    };

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
                        <button type="button" onClick={() => setSelectedReferences((current) => current.filter((code) => code !== reference.codigo))}>
                            x
                        </button>
                    </span>
                ))}
            </div>

            <div className="overflow-hidden rounded-lg border border-[var(--border)]">
                <div className="border-b border-[var(--border)] bg-[var(--surface-muted)] px-4 py-3">
                    <h2 className="text-sm font-bold text-[var(--ink-900)]">Bases de referencia</h2>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                        Selecione a base oficial e a data que serao usadas como referencia desta composicao.
                    </p>
                </div>

                <div className="divide-y divide-[var(--border)] bg-white">
                    {referenceChoices.length === 0 ? (
                        <div className="m-3 rounded-lg border border-dashed border-[var(--border)] bg-white p-4 text-sm text-[var(--ink-500)]">
                            Nenhuma base global encontrada. Importe uma base oficial para selecionar as referencias.
                        </div>
                    ) : referenceChoices.map((choice) => {
                        const codes = choice.references.map((reference) => reference.codigo);
                        const selectedCode = selectedReferences.find((code) => codes.includes(code))
                            ?? referenceDatesByChoice[choice.key]
                            ?? choice.references[0]?.codigo;
                        const selectedReference = choice.references.find((reference) => reference.codigo === selectedCode) ?? choice.references[0];
                        const checked = codes.some((code) => selectedReferences.includes(code));

                        return (
                            <div
                                key={choice.key}
                                className={`grid gap-3 px-4 py-3 transition md:grid-cols-[32px_minmax(120px,1fr)_minmax(160px,1.2fr)_minmax(220px,1.4fr)] md:items-center ${
                                    checked
                                        ? 'bg-[var(--primary-50)]/70'
                                        : 'bg-white hover:bg-[var(--surface-muted)]/70'
                                }`}
                            >
                                <label className="flex items-center gap-2">
                                    <input
                                        checked={checked}
                                        className="h-4 w-4 accent-[var(--primary)]"
                                        type="checkbox"
                                        onChange={(event) => toggleChoice(choice, event.target.checked)}
                                    />
                                    <span className="text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-400)] md:hidden">
                                        Selecionar
                                    </span>
                                </label>
                                <div className="min-w-0">
                                    <p className="text-sm font-bold text-[var(--ink-900)]">{choice.nome}</p>
                                    <p className="mt-0.5 text-[11px] font-semibold text-[var(--ink-400)] md:hidden">Base</p>
                                </div>
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold text-[var(--ink-600)]">{choice.localidade}</p>
                                    <p className="mt-0.5 text-[11px] font-semibold text-[var(--ink-400)] md:hidden">Localidade</p>
                                </div>
                                <label className="grid gap-1">
                                    <span className="text-[11px] font-bold uppercase tracking-[0.06em] text-[var(--ink-400)] md:hidden">
                                        Data base
                                    </span>
                                    <select
                                        className="sig-input !h-10 !min-h-10 text-sm"
                                        value={selectedReference?.codigo ?? ''}
                                        onChange={(event) => updateChoiceDate(choice, event.target.value)}
                                    >
                                        {choice.references.map((reference) => (
                                            <option key={reference.codigo} value={reference.codigo}>
                                                {reference.data} {reference.total ? `- ${reference.total} registros` : ''}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                        );
                    })}
                </div>

                <div className="sticky bottom-0 grid gap-2 border-t border-[var(--border)] bg-white p-3 shadow-[0_-8px_18px_rgba(15,23,42,0.04)] md:grid-cols-[1fr_1fr_1.4fr]">
                    <button className="sig-btn sig-btn-secondary justify-center" type="button" onClick={onSelectAll}>
                        Selecionar bases exibidas
                    </button>
                    <button className="sig-btn sig-btn-secondary justify-center" type="button" onClick={onUnselectAll}>
                        Limpar selecao
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
