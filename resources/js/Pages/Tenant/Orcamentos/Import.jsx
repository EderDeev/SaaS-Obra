import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, FileSpreadsheet, Plus, Trash2, Upload } from 'lucide-react';
import OrcamentoShell from './Partials/OrcamentoShell';

const emptyReference = () => ({ nome: '', uf: 'PA', data: '' });

export default function ImportOrcamento({ tenant, options = {} }) {
    const form = useForm({
        codigo: options.nextCode ?? '',
        descricao: '',
        cliente_empresa_id: '',
        categoria: options.categories?.find((item) => item.value === 'Outros')?.value
            ?? options.categories?.[0]?.value
            ?? '',
        permitir_insumos_preco_zerado: false,
        arredondamento: 'truncate_all_2',
        encargos_sociais: 'desonerado',
        encargos_horista: '',
        encargos_mensalista: '',
        bdi_tipo: 'unit_price',
        bdi_percentual: '',
        base_references: [emptyReference()],
        file: null,
    });

    const updateReference = (index, field, value) => {
        form.setData('base_references', form.data.base_references.map((reference, currentIndex) => (
            currentIndex === index ? { ...reference, [field]: value } : reference
        )));
    };

    const addReference = () => form.setData('base_references', [...form.data.base_references, emptyReference()]);

    const removeReference = (index) => {
        if (form.data.base_references.length > 1) {
            form.setData('base_references', form.data.base_references.filter((_, currentIndex) => currentIndex !== index));
        }
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tenant.orcamentos.import.store', tenant.slug), {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <OrcamentoShell
            tenant={tenant}
            active="orcamentos"
            title="Importar Orçamento"
            subtitle="Cadastre os parâmetros do orçamento e importe a estrutura de etapas e itens pelo CSV sintético."
            showNav={false}
        >
            <div className="mb-5">
                <Link className="sig-btn sig-btn-secondary" href={route('tenant.orcamentos.index', tenant.slug)}>
                    <ArrowLeft size={15} />
                    Voltar
                </Link>
            </div>

            <form className="grid gap-5" onSubmit={submit}>
                <section className="sig-card overflow-hidden">
                    <SectionHeader
                        icon={FileSpreadsheet}
                        title="Informações gerais"
                        description="Use os dados apresentados no cabeçalho do arquivo Sintético com fórmulas."
                    />
                    <div className="grid gap-4 p-5 lg:grid-cols-3">
                        <Field label="Código*" error={form.errors.codigo}>
                            <input className="sig-input" value={form.data.codigo} onChange={(event) => form.setData('codigo', event.target.value)} />
                        </Field>
                        <Field className="lg:col-span-2" label="Descrição da obra/orçamento*" error={form.errors.descricao}>
                            <input
                                className="sig-input"
                                placeholder="Ex.: Reforma das estações e corredor de ônibus"
                                value={form.data.descricao}
                                onChange={(event) => form.setData('descricao', event.target.value)}
                            />
                        </Field>
                        <Field label="Cliente" error={form.errors.cliente_empresa_id}>
                            <select className="sig-input" value={form.data.cliente_empresa_id} onChange={(event) => form.setData('cliente_empresa_id', event.target.value)}>
                                <option value="">Selecione o cliente</option>
                                {(options.clients ?? []).map((client) => (
                                    <option key={client.id} value={client.id}>{client.nome}{client.sigla ? ` - ${client.sigla}` : ''}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Categoria*" error={form.errors.categoria}>
                            <select className="sig-input" value={form.data.categoria} onChange={(event) => form.setData('categoria', event.target.value)}>
                                {(options.categories ?? []).map((category) => (
                                    <option key={category.value} value={category.value}>{category.label}</option>
                                ))}
                            </select>
                        </Field>
                        <label className="flex items-center gap-2 self-end pb-3 text-sm font-medium text-[var(--ink-700)]">
                            <input
                                checked={form.data.permitir_insumos_preco_zerado}
                                className="h-4 w-4 accent-[var(--primary)]"
                                type="checkbox"
                                onChange={(event) => form.setData('permitir_insumos_preco_zerado', event.target.checked)}
                            />
                            Permitir itens com preço zerado
                        </label>
                    </div>
                </section>

                <section className="sig-card overflow-hidden">
                    <SectionHeader
                        icon={FileSpreadsheet}
                        title="BDI e encargos sociais"
                        description="No arquivo de referência: BDI 29,78%, encargos desonerados, horista 95,00% e mensalista 53,45%."
                    />
                    <div className="grid gap-4 p-5 md:grid-cols-2 lg:grid-cols-3">
                        <Field label="Percentual de BDI (%)*" error={form.errors.bdi_percentual}>
                            <input className="sig-input" placeholder="29,78" value={form.data.bdi_percentual} onChange={(event) => form.setData('bdi_percentual', event.target.value)} />
                        </Field>
                        <Field label="Incidência do BDI*" error={form.errors.bdi_tipo}>
                            <select className="sig-input" value={form.data.bdi_tipo} onChange={(event) => form.setData('bdi_tipo', event.target.value)}>
                                {(options.bdiTypes ?? []).map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
                            </select>
                        </Field>
                        <Field label="Regime dos encargos*" error={form.errors.encargos_sociais}>
                            <select className="sig-input" value={form.data.encargos_sociais} onChange={(event) => form.setData('encargos_sociais', event.target.value)}>
                                {(options.encargosOptions ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                            </select>
                        </Field>
                        <Field label="Encargos horista (%)" error={form.errors.encargos_horista}>
                            <input className="sig-input" placeholder="95,00" value={form.data.encargos_horista} onChange={(event) => form.setData('encargos_horista', event.target.value)} />
                        </Field>
                        <Field label="Encargos mensalista (%)" error={form.errors.encargos_mensalista}>
                            <input className="sig-input" placeholder="53,45" value={form.data.encargos_mensalista} onChange={(event) => form.setData('encargos_mensalista', event.target.value)} />
                        </Field>
                    </div>

                    <fieldset className="border-t border-[var(--border)] p-5">
                        <legend className="px-1 text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">
                            Arredondamento do orçamento
                        </legend>
                        <div className="mt-2 grid gap-2">
                            {(options.roundingMethods ?? []).map((method) => (
                                <RadioOption
                                    key={method.value}
                                    checked={form.data.arredondamento === method.value}
                                    name="arredondamento"
                                    onChange={() => form.setData('arredondamento', method.value)}
                                >
                                    {method.label}
                                    {method.badge && <Badge>{method.badge}</Badge>}
                                </RadioOption>
                            ))}
                        </div>
                        {form.errors.arredondamento && (
                            <p className="mt-2 text-xs font-semibold text-rose-600">{form.errors.arredondamento}</p>
                        )}
                    </fieldset>
                </section>

                <section className="sig-card overflow-hidden">
                    <SectionHeader
                        icon={FileSpreadsheet}
                        title="Bases de referência"
                        description="Cadastre todas as bases informadas no XLSX, incluindo banco, competência e UF."
                        action={(
                            <button className="sig-btn sig-btn-secondary !min-h-9" type="button" onClick={addReference}>
                                <Plus size={15} />
                                Adicionar base
                            </button>
                        )}
                    />
                    <div className="divide-y divide-[var(--border)]">
                        {form.data.base_references.map((reference, index) => (
                            <div key={index} className="grid gap-3 p-4 md:grid-cols-[1fr_140px_180px_42px] md:items-end">
                                <Field label="Banco*" error={form.errors[`base_references.${index}.nome`]}>
                                    <input className="sig-input" placeholder="SINAPI, SICRO3, SBC, ORSE..." value={reference.nome} onChange={(event) => updateReference(index, 'nome', event.target.value)} />
                                </Field>
                                <Field label="UF" error={form.errors[`base_references.${index}.uf`]}>
                                    <select className="sig-input" value={reference.uf} onChange={(event) => updateReference(index, 'uf', event.target.value)}>
                                        <option value="">Nacional</option>
                                        {brazilianStates.map((state) => <option key={state} value={state}>{state}</option>)}
                                    </select>
                                </Field>
                                <Field label="Competência*" error={form.errors[`base_references.${index}.data`]}>
                                    <input className="sig-input" placeholder="02/2025" value={reference.data} onChange={(event) => updateReference(index, 'data', event.target.value)} />
                                </Field>
                                <button
                                    className="flex h-10 w-10 items-center justify-center rounded-lg border border-rose-100 bg-rose-50 text-rose-600 disabled:opacity-40"
                                    disabled={form.data.base_references.length === 1}
                                    title="Remover base"
                                    type="button"
                                    onClick={() => removeReference(index)}
                                >
                                    <Trash2 size={16} />
                                </button>
                            </div>
                        ))}
                    </div>
                    {form.errors.base_references && <p className="px-5 pb-4 text-xs font-semibold text-rose-600">{form.errors.base_references}</p>}
                </section>

                <section className="sig-card overflow-hidden">
                    <SectionHeader
                        icon={Upload}
                        title="Arquivo dos itens"
                        description="O CSV deve seguir o sintético: Item, Código, Banco, Descrição, Und, Quant., Valor Unit, Valor Unit com BDI e Total."
                    />
                    <div className="p-5">
                        <label className="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-[var(--border)] bg-[var(--surface-muted)] px-5 py-8 text-center transition hover:border-[var(--primary)]">
                            <Upload size={26} className="text-[var(--primary)]" />
                            <strong className="mt-3 text-sm text-[var(--ink-900)]">{form.data.file?.name ?? 'Selecionar CSV do orçamento'}</strong>
                            <span className="mt-1 text-xs text-[var(--ink-500)]">CSV, TXT ou TSV de até 100 MB</span>
                            <input accept=".csv,.txt,.tsv" className="hidden" type="file" onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)} />
                        </label>
                        {form.errors.file && <p className="mt-2 text-xs font-semibold text-rose-600">{form.errors.file}</p>}
                    </div>
                </section>

                <div className="flex flex-wrap justify-end gap-3">
                    <Link className="sig-btn sig-btn-secondary" href={route('tenant.orcamentos.index', tenant.slug)}>Cancelar</Link>
                    <button className="sig-btn sig-btn-primary" disabled={form.processing} type="submit">
                        <Upload size={16} />
                        {form.processing ? 'Importando...' : 'Importar Orçamento'}
                    </button>
                </div>
            </form>
        </OrcamentoShell>
    );
}

function SectionHeader({ action = null, description, icon: Icon, title }) {
    return (
        <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
            <div className="flex items-center gap-3">
                <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                    <Icon size={19} />
                </span>
                <div>
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">{title}</h2>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">{description}</p>
                </div>
            </div>
            {action}
        </header>
    );
}

function Field({ children, className = '', error, label }) {
    return (
        <label className={`block ${className}`}>
            <span className="mb-1 block text-xs font-bold text-[var(--ink-500)]">{label}</span>
            {children}
            {error && <p className="mt-1 text-xs font-semibold text-rose-600">{error}</p>}
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

const brazilianStates = [
    'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG',
    'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
];
