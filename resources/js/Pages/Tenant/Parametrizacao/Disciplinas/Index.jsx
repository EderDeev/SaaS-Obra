import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Filter, Layers3, Pencil, Plus, Save, Search, SlidersHorizontal, Trash2, X } from 'lucide-react';
import { useMemo, useState } from 'react';

export default function ParametrizacaoDisciplinasIndex({ tenant, disciplinas, contracts }) {
    const page = usePage();
    const defaultContractId = contracts[0]?.id ?? '';
    const [editingDisciplina, setEditingDisciplina] = useState(null);
    const [contractFilter, setContractFilter] = useState('todos');
    const [query, setQuery] = useState('');
    const form = useForm({
        contract_id: defaultContractId,
        nome: '',
        sigla: '',
        descricao: '',
        cor: '#2563eb',
    });

    const filteredDisciplinas = useMemo(() => {
        const term = query.trim().toLowerCase();

        return disciplinas.filter((disciplina) => {
            if (contractFilter !== 'todos' && String(disciplina.contract_id) !== String(contractFilter)) {
                return false;
            }

            if (!term) {
                return true;
            }

            return `${disciplina.nome} ${disciplina.sigla} ${disciplina.descricao || ''}`
                .toLowerCase()
                .includes(term);
        });
    }, [disciplinas, contractFilter, query]);

    const resetForm = () => {
        setEditingDisciplina(null);
        form.clearErrors();
        form.setData({
            contract_id: defaultContractId,
            nome: '',
            sigla: '',
            descricao: '',
            cor: '#2563eb',
        });
    };

    const startEditing = (disciplina) => {
        setEditingDisciplina(disciplina);
        form.clearErrors();
        form.setData({
            contract_id: disciplina.contract_id || defaultContractId,
            nome: disciplina.nome || '',
            sigla: disciplina.sigla || '',
            descricao: disciplina.descricao || '',
            cor: disciplina.cor || '#2563eb',
        });
    };

    const submit = (event) => {
        event.preventDefault();

        const targetRoute = editingDisciplina
            ? route('tenant.parametrizacao.disciplinas.update', [page.props.currentTenant.slug, editingDisciplina.id])
            : route('tenant.parametrizacao.disciplinas.store', page.props.currentTenant.slug);

        form.transform((data) => (editingDisciplina ? { ...data, _method: 'patch' } : data));

        form.post(targetRoute, {
            preserveScroll: true,
            onSuccess: resetForm,
        });
    };

    const deleteDisciplina = (disciplina) => {
        if (editingDisciplina?.id === disciplina.id) {
            resetForm();
        }

        router.delete(route('tenant.parametrizacao.disciplinas.destroy', [page.props.currentTenant.slug, disciplina.id]), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Parametrizacao - Disciplinas" />

            <section className="sig-content grid gap-6 xl:grid-cols-[380px_minmax(0,1fr)]">
                <form className="sig-card p-5" onSubmit={submit}>
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <SlidersHorizontal size={14} />
                        <span className="eyebrow">Parametrizacao</span>
                    </div>
                    <h1 className="mt-2 text-xl font-semibold">
                        {editingDisciplina ? 'Editar disciplina' : 'Cadastrar disciplina'}
                    </h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        Cadastre as disciplinas tecnicas usadas no controle de projetos do contrato.
                    </p>

                    {page.props.flash.success && (
                        <div className="mt-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}
                    {page.props.flash.error && (
                        <div className="mt-4 rounded-lg bg-[var(--red-50)] px-3 py-2 text-sm text-[var(--red)]">
                            {page.props.flash.error}
                        </div>
                    )}

                    <div className="mt-5 grid gap-3">
                        <Field label="Contrato" error={form.errors.contract_id}>
                            <select
                                value={form.data.contract_id}
                                onChange={(event) => form.setData('contract_id', event.target.value)}
                                required
                            >
                                <option value="">Selecione o contrato</option>
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>
                                        {contract.code} - {contract.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Nome" error={form.errors.nome}>
                            <input
                                value={form.data.nome}
                                onChange={(event) => form.setData('nome', event.target.value)}
                                placeholder="Ex: Arquitetura"
                                required
                            />
                        </Field>

                        <div className="grid gap-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                            <Field label="Sigla" error={form.errors.sigla}>
                                <input
                                    value={form.data.sigla}
                                    onChange={(event) => form.setData('sigla', event.target.value.toUpperCase())}
                                    placeholder="ARQ"
                                    maxLength={20}
                                    required
                                />
                            </Field>

                            <Field label="Cor" error={form.errors.cor}>
                                <span className="flex items-center gap-3">
                                    <input
                                        className="h-9 w-12 shrink-0 cursor-pointer rounded-md border border-[var(--border)] bg-white p-1"
                                        type="color"
                                        value={/^#[0-9A-Fa-f]{6}$/.test(form.data.cor) ? form.data.cor : '#2563eb'}
                                        onChange={(event) => form.setData('cor', event.target.value)}
                                    />
                                    <input
                                        value={form.data.cor}
                                        onChange={(event) => form.setData('cor', event.target.value)}
                                        placeholder="#2563eb"
                                        maxLength={7}
                                        required
                                    />
                                </span>
                            </Field>
                        </div>

                        <Field label="Descricao" error={form.errors.descricao}>
                            <textarea
                                value={form.data.descricao}
                                onChange={(event) => form.setData('descricao', event.target.value)}
                                placeholder="Detalhes opcionais da disciplina"
                                rows="4"
                            />
                        </Field>
                    </div>

                    <div className="mt-5 flex flex-wrap gap-2">
                        <button className="sig-btn sig-btn-primary" disabled={form.processing || contracts.length === 0}>
                            {editingDisciplina ? <Save size={15} /> : <Plus size={15} />}
                            {editingDisciplina ? 'Salvar alteracoes' : 'Criar disciplina'}
                        </button>
                        {editingDisciplina && (
                            <button type="button" className="sig-btn sig-btn-secondary" onClick={resetForm}>
                                <X size={15} />
                                Cancelar
                            </button>
                        )}
                    </div>
                </form>

                <section className="sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <Layers3 size={14} />
                                <span className="eyebrow">Disciplinas cadastradas</span>
                            </div>
                            <h2 className="mt-1 text-[15px] font-semibold">
                                {filteredDisciplinas.length} de {disciplinas.length} disciplinas
                            </h2>
                        </div>
                    </header>

                    <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 lg:grid-cols-2">
                        <label>
                            <span className="eyebrow mb-1 flex items-center gap-1">
                                <Filter size={12} />
                                Contrato
                            </span>
                            <span className="sig-input bg-white">
                                <select value={contractFilter} onChange={(event) => setContractFilter(event.target.value)}>
                                    <option value="todos">Todos os contratos</option>
                                    {contracts.map((contract) => (
                                        <option key={contract.id} value={contract.id}>
                                            {contract.code} - {contract.name}
                                        </option>
                                    ))}
                                </select>
                            </span>
                        </label>

                        <label>
                            <span className="eyebrow mb-1 flex items-center gap-1">
                                <Search size={12} />
                                Busca
                            </span>
                            <span className="sig-input bg-white">
                                <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar por nome, sigla ou descricao" />
                            </span>
                        </label>
                    </div>

                    {filteredDisciplinas.length > 0 ? (
                        <div className="overflow-x-auto">
                        <table className="sig-table min-w-[920px]">
                            <thead>
                                <tr>
                                    <th>Disciplina</th>
                                    <th>Contrato</th>
                                    <th>Sigla</th>
                                    <th>Cor</th>
                                    <th>Descricao</th>
                                    <th>Acoes</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredDisciplinas.map((disciplina) => (
                                    <tr key={disciplina.id}>
                                        <td>
                                            <div className="font-semibold">{disciplina.nome}</div>
                                        </td>
                                        <td>
                                            <div className="mono text-xs">{disciplina.contract?.code}</div>
                                            <div className="text-xs text-[var(--ink-500)]">{disciplina.contract?.name}</div>
                                        </td>
                                        <td className="font-semibold">{disciplina.sigla}</td>
                                        <td>
                                            <span className="inline-flex items-center gap-2 text-sm font-semibold text-[var(--ink-700)]">
                                                <span className="h-4 w-4 rounded-full border border-[var(--border)]" style={{ backgroundColor: disciplina.cor }} />
                                                <span className="mono text-xs">{disciplina.cor}</span>
                                            </span>
                                        </td>
                                        <td className="max-w-[280px]">
                                            <span className="line-clamp-2 text-sm text-[var(--ink-500)]">
                                                {disciplina.descricao || 'Sem descricao'}
                                            </span>
                                        </td>
                                        <td>
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <button
                                                    type="button"
                                                    className="sig-btn sig-btn-secondary sig-btn-sm"
                                                    onClick={() => startEditing(disciplina)}
                                                >
                                                    <Pencil size={14} />
                                                    Editar
                                                </button>
                                                <ConfirmActionButton
                                                    title="Deletar disciplina"
                                                    message={`Deseja mesmo excluir a disciplina ${disciplina.nome}? O registro sera mantido no historico.`}
                                                    confirmLabel="Deletar disciplina"
                                                    onConfirm={() => deleteDisciplina(disciplina)}
                                                >
                                                    <Trash2 size={14} />
                                                    Deletar
                                                </ConfirmActionButton>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        </div>
                    ) : (
                        <div className="p-12 text-center text-sm text-[var(--ink-500)]">
                            {disciplinas.length === 0 ? 'Nenhuma disciplina cadastrada ainda.' : 'Nenhuma disciplina encontrada para os filtros selecionados.'}
                        </div>
                    )}
                </section>
            </section>
        </AuthenticatedLayout>
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
