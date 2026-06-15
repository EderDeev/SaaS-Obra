import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardList,
    FileSpreadsheet,
    Layers3,
    Plus,
    Ruler,
    Upload,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const formatCurrency = (value) =>
    new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value || 0));

const formatDecimal = (value) =>
    new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 6,
    }).format(Number(value || 0));

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

export default function MedicaoItens({
    tenant,
    contracts = [],
    orcamentos = [],
    selectedContractId = null,
    items = [],
    stats = {},
}) {
    const { props } = usePage();
    const flash = props?.flash || {};
    const [activePanel, setActivePanel] = useState('orcamento');
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

    useEffect(() => {
        fromBudgetForm.setData('contract_id', selectedContractId || '');
        importForm.setData('contract_id', selectedContractId || '');
        manualForm.setData('contract_id', selectedContractId || '');
    }, [selectedContractId]);

    const changeContract = (event) => {
        router.get(
            route('tenant.medicao.item.index', tenant.slug),
            { contract_id: event.target.value },
            { preserveScroll: true, preserveState: false },
        );
    };

    const submitFromBudget = (event) => {
        event.preventDefault();
        fromBudgetForm.post(route('tenant.medicao.item.orcamento.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => fromBudgetForm.reset('orcamento_id'),
        });
    };

    const submitImport = (event) => {
        event.preventDefault();
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

                <section className="grid gap-4 md:grid-cols-3">
                    <PanelButton
                        active={activePanel === 'orcamento'}
                        icon={ClipboardList}
                        title="Usar orçamento criado"
                        description="Puxa etapas e itens de um orçamento existente, mas grava tudo no contrato escolhido."
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
                                <h2 className="text-base font-semibold text-[var(--ink-900)]">Importar de orçamento</h2>
                                <p className="mt-1 text-sm text-[var(--ink-500)]">
                                    O orçamento continua no tenant, mas os itens de medição serão vinculados ao contrato{' '}
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
                                                {orcamento.codigo} - {orcamento.descricao} ({orcamento.itens_count} itens)
                                            </option>
                                        ))}
                                    </select>
                                </Field>

                                <button
                                    type="submit"
                                    disabled={fromBudgetForm.processing || !selectedContractId}
                                    className="btn-primary inline-flex items-center justify-center gap-2"
                                >
                                    <Layers3 size={17} />
                                    Usar itens do orçamento
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
                                    className="btn-primary inline-flex items-center gap-2"
                                >
                                    <FileSpreadsheet size={17} />
                                    Importar itens
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
                                {items.map((item) => (
                                    <div
                                        key={item.id}
                                        className={`grid grid-cols-[80px_110px_90px_1fr_80px_120px_130px_130px_120px] items-center border-b border-[var(--border)] px-5 py-3 text-sm ${
                                            item.nivel === 1 ? 'bg-sky-50 font-semibold' : 'bg-white'
                                        }`}
                                    >
                                        <span>{item.item || '-'}</span>
                                        <span>{item.codigo || '-'}</span>
                                        <span>{item.banco || '-'}</span>
                                        <span>{item.descricao}</span>
                                        <span>{item.unidade || '-'}</span>
                                        <span className="text-right">{formatDecimal(item.quantidade_prevista)}</span>
                                        <span className="text-right">{formatCurrency(item.valor_com_bdi)}</span>
                                        <span className="text-right font-semibold">{formatCurrency(item.valor_total)}</span>
                                        <span className="text-right text-xs font-bold uppercase text-[var(--ink-500)]">
                                            {item.source_label}
                                        </span>
                                    </div>
                                ))}
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
                                                    {item.item || 'Sem item'} · {item.source_label}
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
        </AuthenticatedLayout>
    );
}
