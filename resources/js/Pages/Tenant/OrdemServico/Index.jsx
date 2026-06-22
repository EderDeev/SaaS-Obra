import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarDays,
    ChevronDown,
    ChevronRight,
    ClipboardCheck,
    ClipboardList,
    FileText,
    FolderKanban,
    HardHat,
    Paperclip,
    Plus,
    Search,
    Send,
    UserRound,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

const formatCurrency = (value) =>
    new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value || 0));

const formatMoneyInput = (value) => {
    const digits = String(value ?? '').replace(/\D/g, '');

    if (!digits) {
        return '';
    }

    return formatCurrency(Number(digits) / 100);
};

const statusLabels = {
    rascunho: 'Rascunho',
    solicitada: 'Solicitada',
    em_analise: 'Em análise',
    em_aprovacao: 'Em aprovação',
    aprovada: 'Aprovada',
    recusada: 'Recusada',
    em_execucao: 'Em execução',
    concluida: 'Concluída',
    cancelada: 'Cancelada',
};

const statusClasses = {
    rascunho: 'bg-slate-100 text-slate-700',
    solicitada: 'bg-blue-50 text-blue-700',
    em_analise: 'bg-amber-50 text-amber-700',
    em_aprovacao: 'bg-indigo-50 text-indigo-700',
    aprovada: 'bg-emerald-50 text-emerald-700',
    recusada: 'bg-red-50 text-red-700',
    em_execucao: 'bg-amber-50 text-amber-700',
    concluida: 'bg-emerald-100 text-emerald-800',
    cancelada: 'bg-slate-200 text-slate-700',
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

function initials(name = '') {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase() || '?';
}

export default function OrdemServicoIndex({
    selectedContractId,
    contracts = [],
    ordens = [],
    options = {},
}) {
    const page = usePage();
    const tenant = page.props.currentTenant;
    const currentUser = page.props.auth?.user;
    const [showForm, setShowForm] = useState(false);
    const [itemSearch, setItemSearch] = useState('');
    const [planilhaFilter, setPlanilhaFilter] = useState('todas');
    const [expandedOrderId, setExpandedOrderId] = useState(null);

    const form = useForm({
        contract_id: selectedContractId || '',
        obra_id: '',
        project_document_ids: [],
        gerenciadora_empresa_id: '',
        construtora_empresa_id: '',
        titulo: '',
        descricao: '',
        prazo_execucao: '',
        custo_previsto: '',
        custo_observacao: '',
        item_ids: [],
        documentos: [],
    });

    const selectedContract = contracts.find((contract) => Number(contract.id) === Number(form.data.contract_id));
    const obras = options.obras || [];
    const projects = options.projects || [];
    const items = options.items || [];
    const empresas = options.empresas || [];

    const planilhaOptions = useMemo(() => {
        return [...new Set(items.map((item) => item.planilha).filter(Boolean))]
            .sort((a, b) => Number(a) - Number(b));
    }, [items]);

    const empresaMatchesTipo = (empresa, tipo) => {
        const haystack = `${empresa.tipo_slug || ''} ${empresa.tipo_nome || ''}`.toLowerCase();

        return haystack.includes(tipo);
    };

    const gerenciadoras = empresas.filter((empresa) => empresaMatchesTipo(empresa, 'gerenciadora'));
    const construtoras = empresas.filter((empresa) => empresaMatchesTipo(empresa, 'construtora'));
    const gerenciadoraOptions = gerenciadoras.length ? gerenciadoras : empresas;
    const construtoraOptions = construtoras.length ? construtoras : empresas;

    const filteredProjects = projects.filter((project) => {
        if (form.data.obra_id && Number(project.obra_id) !== Number(form.data.obra_id)) {
            return false;
        }

        return true;
    });
    const selectedProjects = projects.filter((project) => form.data.project_document_ids.includes(project.id));

    const filteredItems = useMemo(() => {
        const search = itemSearch.trim().toLowerCase();

        return items.filter((item) => {
                if (planilhaFilter !== 'todas' && String(item.planilha) !== String(planilhaFilter)) {
                    return false;
                }

                if (!search) {
                    return true;
                }

                return [item.item, item.codigo, item.descricao]
                    .filter(Boolean)
                    .some((value) => String(value).toLowerCase().includes(search));
            });
    }, [items, itemSearch, planilhaFilter]);

    const selectedItems = items.filter((item) => form.data.item_ids.includes(item.id));
    const estimatedTotalP0 = selectedItems.reduce(
        (total, item) => total + Number(item.valor_total_p0 ?? item.valor_total ?? 0),
        0
    );
    const estimatedTotalAdjusted = selectedItems.reduce(
        (total, item) => total + Number(item.valor_total_reajustado ?? item.valor_total ?? 0),
        0
    );

    const changeContract = (contractId) => {
        router.get(
            route('tenant.ordem-servico.os.index', tenant.slug),
            { contract_id: contractId },
            { preserveScroll: true, preserveState: false }
        );
    };

    const toggleId = (field, id) => {
        const current = form.data[field] || [];
        const next = current.includes(id)
            ? current.filter((value) => value !== id)
            : [...current, id];

        form.setData(field, next);
    };

    const submit = (event) => {
        event.preventDefault();

        form.post(route('tenant.ordem-servico.os.store', tenant.slug), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setShowForm(false);
                setItemSearch('');
                setPlanilhaFilter('todas');
                form.reset();
                form.setData('contract_id', selectedContractId || '');
            },
        });
    };

    const submitForAnalysis = (ordem) => {
        if (!window.confirm(`Enviar a OS ${ordem.codigo} para análise dos fiscais da obra?`)) {
            return;
        }

        router.patch(route('tenant.ordem-servico.os.submit-analysis', [tenant.slug, ordem.id]), {}, {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Ordem de Serviço" />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                <section className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <span className="eyebrow">Execução</span>
                        <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">Ordem de Serviço</h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-[var(--ink-500)]">
                            Solicite a execução de serviços vinculando contrato, obra, projeto, itens, empresas responsáveis,
                            documentos e custos previstos.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={() => setShowForm((value) => !value)}
                        className="sig-btn sig-btn-primary"
                    >
                        {showForm ? <X size={16} /> : <Plus size={16} />}
                        {showForm ? 'Fechar cadastro' : 'Nova OS'}
                    </button>
                </section>

                {page.props.flash?.success && (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                        {page.props.flash.success}
                    </div>
                )}

                {Object.values(page.props.errors || {}).length > 0 && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                        {Object.values(page.props.errors)[0]}
                    </div>
                )}

                <section className="sig-card p-5">
                    <div className="grid gap-4 lg:grid-cols-[1fr_220px_220px] lg:items-end">
                        <Field label="Contrato">
                            <select
                                value={selectedContractId || ''}
                                onChange={(event) => changeContract(event.target.value)}
                                className="sig-input"
                            >
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>
                                        {contract.code} - {contract.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <MetricCard icon={ClipboardList} label="OS cadastradas" value={ordens.length} />
                        <MetricCard
                            icon={ClipboardCheck}
                            label="Custo previsto"
                            value={formatCurrency(ordens.reduce((sum, ordem) => sum + Number(ordem.custo_previsto || 0), 0))}
                        />
                    </div>
                </section>

                {showForm && (
                    <form onSubmit={submit} className="sig-card overflow-hidden">
                        <header className="border-b border-[var(--border)] px-5 py-4">
                            <div className="flex items-center gap-3">
                                <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                                    <HardHat size={20} />
                                </span>
                                <div>
                                    <h2 className="text-lg font-bold text-[var(--ink-900)]">Criar ordem de serviço</h2>
                                    <p className="text-sm text-[var(--ink-500)]">
                                        O usuário logado será registrado automaticamente como solicitante.
                                    </p>
                                </div>
                            </div>
                        </header>

                        <div className="grid gap-5 p-5">
                            <div className="grid gap-4 lg:grid-cols-3">
                                <Field label="Contrato" error={form.errors.contract_id}>
                                    <select
                                        value={form.data.contract_id}
                                        onChange={(event) => changeContract(event.target.value)}
                                        className="sig-input"
                                    >
                                        {contracts.map((contract) => (
                                            <option key={contract.id} value={contract.id}>
                                                {contract.code} - {contract.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>

                                <Field label="Obra" error={form.errors.obra_id}>
                                    <select
                                        value={form.data.obra_id}
                                        onChange={(event) => {
                                            form.setData({
                                                ...form.data,
                                                obra_id: event.target.value,
                                                project_document_ids: [],
                                            });
                                        }}
                                        className="sig-input"
                                    >
                                        <option value="">Selecione a obra</option>
                                        {obras.map((obra) => (
                                            <option key={obra.id} value={obra.id}>{obra.label}</option>
                                        ))}
                                    </select>
                                </Field>

                                <Field label="Projetos vinculados" error={form.errors.project_document_ids}>
                                    <div className="max-h-40 overflow-auto rounded-lg border border-[var(--border)] bg-white">
                                        {form.data.obra_id === '' ? (
                                            <p className="p-3 text-xs font-semibold text-[var(--ink-500)]">Selecione uma obra para listar os projetos.</p>
                                        ) : filteredProjects.length === 0 ? (
                                            <p className="p-3 text-xs font-semibold text-[var(--ink-500)]">Nenhum projeto aprovado encontrado para esta obra.</p>
                                        ) : filteredProjects.map((project) => {
                                            const checked = form.data.project_document_ids.includes(project.id);

                                            return (
                                                <button
                                                    key={project.id}
                                                    type="button"
                                                    onClick={() => toggleId('project_document_ids', project.id)}
                                                    className={`flex w-full items-start gap-2 border-b border-[var(--border)] p-2 text-left text-xs last:border-b-0 hover:bg-[var(--primary-50)] ${checked ? 'bg-emerald-50' : 'bg-white'}`}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => toggleId('project_document_ids', project.id)}
                                                        onClick={(event) => event.stopPropagation()}
                                                        className="mt-0.5"
                                                    />
                                                    <span className="min-w-0 font-semibold text-[var(--ink-800)]">{project.label}</span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                    <span className="text-xs text-[var(--ink-500)]">
                                        {selectedProjects.length} projeto(s) selecionado(s)
                                    </span>
                                </Field>
                            </div>

                            <div className="grid gap-4 lg:grid-cols-3">
                                <Field label="Gerenciadora da obra" error={form.errors.gerenciadora_empresa_id}>
                                    <select
                                        value={form.data.gerenciadora_empresa_id}
                                        onChange={(event) => form.setData('gerenciadora_empresa_id', event.target.value)}
                                        className="sig-input"
                                    >
                                        <option value="">Selecione a gerenciadora</option>
                                        {gerenciadoraOptions.map((empresa) => (
                                            <option key={empresa.id} value={empresa.id}>{empresa.label}</option>
                                        ))}
                                    </select>
                                </Field>

                                <Field label="Construtora solicitante" error={form.errors.construtora_empresa_id}>
                                    <select
                                        value={form.data.construtora_empresa_id}
                                        onChange={(event) => form.setData('construtora_empresa_id', event.target.value)}
                                        className="sig-input"
                                    >
                                        <option value="">Selecione a construtora</option>
                                        {construtoraOptions.map((empresa) => (
                                            <option key={empresa.id} value={empresa.id}>{empresa.label}</option>
                                        ))}
                                    </select>
                                </Field>

                                <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
                                    <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                        Solicitante
                                    </span>
                                    <div className="mt-2 flex items-center gap-3">
                                        <Avatar user={currentUser} />
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-bold text-[var(--ink-900)]">{currentUser?.name || 'Usuário logado'}</p>
                                            <p className="truncate text-xs text-[var(--ink-500)]">{currentUser?.email || 'Registrado automaticamente'}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="grid gap-4 lg:grid-cols-[1fr_180px_220px]">
                                <Field label="Título" error={form.errors.titulo}>
                                    <input
                                        value={form.data.titulo}
                                        onChange={(event) => form.setData('titulo', event.target.value)}
                                        className="sig-input"
                                        placeholder="Ex: Execução da drenagem do trecho 01"
                                    />
                                </Field>

                                <Field label="Prazo para execução" error={form.errors.prazo_execucao}>
                                    <input
                                        type="date"
                                        value={form.data.prazo_execucao}
                                        onChange={(event) => form.setData('prazo_execucao', event.target.value)}
                                        className="sig-input"
                                    />
                                </Field>

                                <Field label="Custo previsto" error={form.errors.custo_previsto}>
                                    <input
                                        value={form.data.custo_previsto}
                                        onChange={(event) => form.setData('custo_previsto', formatMoneyInput(event.target.value))}
                                        className="sig-input"
                                        placeholder={formatCurrency(estimatedTotalP0)}
                                        inputMode="numeric"
                                    />
                                </Field>
                            </div>

                            <Field label="Descrição" error={form.errors.descricao}>
                                <textarea
                                    value={form.data.descricao}
                                    onChange={(event) => form.setData('descricao', event.target.value)}
                                    className="sig-input min-h-28"
                                    placeholder="Descreva o escopo, restrições e premissas da execução."
                                />
                            </Field>

                            <SelectionPanel
                                title="Itens utilizados"
                                icon={FolderKanban}
                                search={itemSearch}
                                setSearch={setItemSearch}
                                placeholder="Buscar por item, código ou descrição"
                                count={selectedItems.length}
                                extraControls={(
                                    <select
                                        value={planilhaFilter}
                                        onChange={(event) => setPlanilhaFilter(event.target.value)}
                                        className="sig-input w-full sm:w-48"
                                    >
                                        <option value="todas">Todas as planilhas</option>
                                        {planilhaOptions.map((planilha) => (
                                            <option key={planilha} value={planilha}>Planilha {planilha}</option>
                                        ))}
                                    </select>
                                )}
                            >
                                <div className="max-h-80 divide-y divide-[var(--border)] overflow-auto rounded-lg border border-[var(--border)]">
                                    {filteredItems.length === 0 ? (
                                        <p className="p-4 text-sm text-[var(--ink-500)]">Nenhum item encontrado.</p>
                                    ) : filteredItems.map((item) => {
                                        const checked = form.data.item_ids.includes(item.id);

                                        return (
                                            <button
                                                key={item.id}
                                                type="button"
                                                onClick={() => toggleId('item_ids', item.id)}
                                                className={`grid w-full gap-2 p-3 text-left transition hover:bg-[var(--primary-50)] ${
                                                    checked ? 'bg-emerald-50' : 'bg-white'
                                                }`}
                                            >
                                                <div className="flex items-start gap-3">
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => toggleId('item_ids', item.id)}
                                                        onClick={(event) => event.stopPropagation()}
                                                        className="mt-1"
                                                    />
                                                    <div className="min-w-0 flex-1">
                                                        <p className="text-sm font-bold text-[var(--ink-900)]">
                                                            {item.item} - {item.codigo || '-'}
                                                        </p>
                                                        <p className="mt-1 line-clamp-2 text-xs text-[var(--ink-600)]">
                                                            {item.descricao}
                                                        </p>
                                                    </div>
                                                    <div className="grid shrink-0 gap-1 text-right">
                                                        <span className="whitespace-nowrap text-[10px] font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                                            P0
                                                        </span>
                                                        <strong className="whitespace-nowrap text-xs text-[var(--ink-900)]">
                                                            {formatCurrency(item.valor_total_p0 ?? item.valor_total)}
                                                        </strong>
                                                        <span className="mt-1 whitespace-nowrap text-[10px] font-bold uppercase tracking-wide text-emerald-700">
                                                            Reajustado
                                                        </span>
                                                        <strong className="whitespace-nowrap text-xs text-emerald-700">
                                                            {formatCurrency(item.valor_total_reajustado ?? item.valor_total)}
                                                        </strong>
                                                    </div>
                                                </div>
                                            </button>
                                        );
                                    })}
                                </div>
                                <div className="grid gap-2 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3 text-xs sm:grid-cols-2">
                                    <p className="font-semibold text-[var(--ink-500)]">
                                        Total inicial P0:
                                        <strong className="ml-1 text-[var(--ink-900)]">{formatCurrency(estimatedTotalP0)}</strong>
                                    </p>
                                    <p className="font-semibold text-[var(--ink-500)] sm:text-right">
                                        Total com reajuste:
                                        <strong className="ml-1 text-emerald-700">{formatCurrency(estimatedTotalAdjusted)}</strong>
                                    </p>
                                </div>
                            </SelectionPanel>

                            <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
                                <Field label="Documentos para execução" error={form.errors.documentos}>
                                    <input
                                        type="file"
                                        multiple
                                        onChange={(event) => form.setData('documentos', Array.from(event.target.files || []))}
                                        className="sig-input file:mr-4 file:rounded-md file:border-0 file:bg-[var(--primary-50)] file:px-3 file:py-2 file:text-sm file:font-bold file:text-[var(--primary)]"
                                    />
                                    <span className="text-xs text-[var(--ink-500)]">
                                        Anexe memoriais, projetos, permissões ou documentos complementares.
                                    </span>
                                </Field>

                                <Field label="Observação de custos" error={form.errors.custo_observacao}>
                                    <textarea
                                        value={form.data.custo_observacao}
                                        onChange={(event) => form.setData('custo_observacao', event.target.value)}
                                        className="sig-input min-h-24"
                                        placeholder="Detalhe premissas, limites ou custos indiretos."
                                    />
                                </Field>
                            </div>
                        </div>

                        <footer className="flex flex-wrap items-center justify-end gap-3 border-t border-[var(--border)] px-5 py-4">
                            <button type="button" onClick={() => setShowForm(false)} className="sig-btn sig-btn-secondary">
                                Cancelar
                            </button>
                            <button type="submit" disabled={form.processing} className="sig-btn sig-btn-primary">
                                <Plus size={16} />
                                {form.processing ? 'Criando...' : 'Criar OS'}
                            </button>
                        </footer>
                    </form>
                )}

                <section className="sig-card overflow-hidden">
                    <header className="border-b border-[var(--border)] px-5 py-4">
                        <h2 className="text-lg font-bold text-[var(--ink-900)]">Ordens de serviço</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            {selectedContract
                                ? `${selectedContract.code} - ${selectedContract.name}`
                                : 'Selecione um contrato para listar as ordens.'}
                        </p>
                    </header>

                    {ordens.length === 0 ? (
                        <div className="p-10 text-center">
                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                                <ClipboardList size={22} />
                            </div>
                            <p className="mt-3 text-sm font-bold text-[var(--ink-900)]">Nenhuma OS cadastrada</p>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                Crie a primeira ordem de serviço para este contrato.
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y divide-[var(--border)]">
                            {ordens.map((ordem) => (
                                <article key={ordem.id}>
                                    <button
                                        type="button"
                                        onClick={() => setExpandedOrderId((current) => current === ordem.id ? null : ordem.id)}
                                        aria-expanded={expandedOrderId === ordem.id}
                                        className="grid w-full items-center gap-3 p-4 text-left transition hover:bg-[var(--surface-muted)] md:grid-cols-[110px_minmax(0,1fr)_170px_150px_28px]"
                                    >
                                        <p className="mono font-bold text-[var(--primary)]">{ordem.codigo}</p>
                                        <div className="min-w-0">
                                            <h3 className="truncate text-sm font-bold text-[var(--ink-900)]">{ordem.titulo}</h3>
                                            <p className="truncate text-xs text-[var(--ink-500)]">
                                                {ordem.obra?.nome || 'Sem obra'} · {ordem.solicitante?.name || 'Sem solicitante'}
                                            </p>
                                        </div>
                                        <span className={`w-fit rounded-full px-3 py-1 text-xs font-bold ${statusClasses[ordem.status] || statusClasses.rascunho}`}>
                                            {statusLabels[ordem.status] || ordem.status}
                                        </span>
                                        <strong className="text-sm text-[var(--ink-900)]">{formatCurrency(ordem.custo_previsto)}</strong>
                                        {expandedOrderId === ordem.id
                                            ? <ChevronDown size={18} className="text-[var(--ink-500)]" />
                                            : <ChevronRight size={18} className="text-[var(--ink-500)]" />}
                                    </button>

                                    <div className={`${expandedOrderId === ordem.id ? 'grid' : 'hidden'} gap-4 border-t border-[var(--border)] p-5 xl:grid-cols-[180px_1fr_260px]`}>
                                    <div>
                                        <p className="mono text-lg font-bold text-[var(--primary)]">{ordem.codigo}</p>
                                        <span className={`mt-3 inline-flex rounded-full px-3 py-1 text-xs font-bold ${statusClasses[ordem.status] || statusClasses.rascunho}`}>
                                            {statusLabels[ordem.status] || ordem.status}
                                        </span>
                                        <p className="mt-3 text-xs text-[var(--ink-500)]">Criada em {ordem.created_at}</p>
                                    </div>

                                    <div className="min-w-0">
                                        <h3 className="text-lg font-bold text-[var(--ink-900)]">{ordem.titulo}</h3>
                                        {ordem.descricao && (
                                            <p className="mt-2 line-clamp-2 text-sm leading-6 text-[var(--ink-500)]">{ordem.descricao}</p>
                                        )}

                                        <div className="mt-4 grid gap-3 md:grid-cols-3">
                                            <InfoLine icon={HardHat} label="Obra" value={ordem.obra?.nome || 'Sem obra'} />
                                            <InfoLine
                                                icon={FileText}
                                                label="Projetos"
                                                value={ordem.projects?.length
                                                    ? `${ordem.projects.length} projeto(s)`
                                                    : 'Sem projeto'}
                                            />
                                            <InfoLine icon={CalendarDays} label="Prazo" value={ordem.prazo_execucao_label || 'Sem prazo'} />
                                        </div>

                                        {ordem.projects?.length > 0 && (
                                            <div className="mt-4 rounded-lg border border-[var(--border)] bg-white p-3">
                                                <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                                    Projetos vinculados
                                                </span>
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {ordem.projects.map((project) => (
                                                        <span
                                                            key={project.id}
                                                            className="rounded-full bg-[var(--primary-50)] px-3 py-1 text-xs font-bold text-[var(--primary)]"
                                                            title={project.title}
                                                        >
                                                            {project.code || project.title}
                                                        </span>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        <div className="mt-4 max-h-80 space-y-2 overflow-auto rounded-lg border border-[var(--border)] p-2">
                                            <div className="sticky top-0 z-10 flex items-center justify-between rounded-md bg-white px-3 py-2 shadow-sm">
                                                <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">
                                                    Itens vinculados
                                                </span>
                                                <span className="rounded-full bg-[var(--surface-muted)] px-2.5 py-1 text-xs font-bold text-[var(--ink-600)]">
                                                    {ordem.itens.length} {ordem.itens.length === 1 ? 'item' : 'itens'}
                                                </span>
                                            </div>
                                            {ordem.itens.map((item) => (
                                                <span
                                                    key={item.id}
                                                    className="grid gap-2 rounded-md bg-slate-100 px-3 py-2 text-xs text-[var(--ink-600)] sm:grid-cols-[minmax(0,1fr)_120px_120px]"
                                                    title={item.descricao}
                                                >
                                                    <div className="min-w-0">
                                                        <p className="font-bold text-[var(--ink-900)]">
                                                            {item.item} - {item.codigo || 'sem código'}
                                                        </p>
                                                        <p className="mt-1 whitespace-normal leading-5">{item.descricao}</p>
                                                    </div>
                                                    <div>
                                                        <span className="block text-[10px] font-bold uppercase tracking-wide text-[var(--ink-500)]">Valor P0</span>
                                                        <strong className="mt-1 block whitespace-nowrap text-[var(--ink-900)]">
                                                            {formatCurrency(item.valor_previsto)}
                                                        </strong>
                                                    </div>
                                                    <div>
                                                        <span className="block text-[10px] font-bold uppercase tracking-wide text-emerald-700">Reajustado</span>
                                                        <strong className="mt-1 block whitespace-nowrap text-emerald-700">
                                                            {formatCurrency(item.valor_reajustado)}
                                                        </strong>
                                                    </div>
                                                </span>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="grid content-between gap-4">
                                        <div className="rounded-lg bg-[var(--surface-muted)] p-4">
                                            <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">Custo previsto</span>
                                            <strong className="mt-2 block text-xl text-[var(--ink-900)]">{formatCurrency(ordem.custo_previsto)}</strong>
                                        </div>

                                        <div className="grid gap-2 text-sm">
                                            <InfoLine icon={Building2} label="Gerenciadora" value={ordem.gerenciadora_empresa?.nome || 'Não definida'} />
                                            <InfoLine icon={Building2} label="Construtora" value={ordem.construtora_empresa?.nome || 'Não definida'} />
                                            <InfoLine icon={UserRound} label="Solicitante" value={ordem.solicitante?.name || 'Não identificado'} />
                                        </div>

                                        <div className="flex items-center gap-2 text-sm font-semibold text-[var(--ink-500)]">
                                            <Paperclip size={16} />
                                            {ordem.documentos_count} documento(s)
                                        </div>

                                        {ordem.status === 'rascunho' && (
                                            <button
                                                type="button"
                                                onClick={() => submitForAnalysis(ordem)}
                                                className="sig-btn sig-btn-primary justify-center"
                                            >
                                                <Send size={16} />
                                                Enviar para análise
                                            </button>
                                        )}
                                    </div>
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function MetricCard({ icon: Icon, label, value }) {
    return (
        <div className="rounded-lg bg-[var(--surface-muted)] p-4">
            <div className="flex items-center gap-3">
                <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-white text-[var(--primary)]">
                    <Icon size={18} />
                </span>
                <div>
                    <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">{label}</span>
                    <strong className="mt-1 block text-lg text-[var(--ink-900)]">{value}</strong>
                </div>
            </div>
        </div>
    );
}

function SelectionPanel({ title, icon: Icon, search, setSearch, placeholder, count, extraControls, children }) {
    return (
        <section className="grid gap-3 rounded-lg border border-[var(--border)] p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                        <Icon size={18} />
                    </span>
                    <div>
                        <h3 className="text-sm font-bold text-[var(--ink-900)]">{title}</h3>
                        <p className="text-xs text-[var(--ink-500)]">{count} selecionado(s)</p>
                    </div>
                </div>
                <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    {extraControls}
                    <div className="relative w-full sm:w-80">
                        <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--ink-400)]" size={16} />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="sig-input"
                            style={{ paddingLeft: '2.5rem' }}
                            placeholder={placeholder}
                        />
                    </div>
                </div>
            </div>
            {children}
        </section>
    );
}

function InfoLine({ icon: Icon, label, value }) {
    return (
        <div className="flex min-w-0 items-start gap-2">
            <Icon className="mt-0.5 shrink-0 text-[var(--ink-400)]" size={16} />
            <div className="min-w-0">
                <span className="text-[11px] font-bold uppercase tracking-wide text-[var(--ink-400)]">{label}</span>
                <p className="truncate text-sm font-semibold text-[var(--ink-800)]">{value}</p>
            </div>
        </div>
    );
}

function Avatar({ user }) {
    return user?.avatar_url ? (
        <img src={user.avatar_url} alt={user.name} className="h-9 w-9 rounded-full object-cover" />
    ) : (
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--primary-100)] text-xs font-bold text-[var(--primary)]">
            {initials(user?.name)}
        </span>
    );
}
