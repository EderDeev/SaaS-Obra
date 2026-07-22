import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Building2, Filter, GitBranch, Pencil, Plus, Save, SlidersHorizontal, Trash2, X } from 'lucide-react';
import { useMemo, useState } from 'react';

export default function ParametrizacaoObrasIndex({ tenant, obras, contracts, obrasPai }) {
    const page = usePage();
    const defaultContractId = contracts[0]?.id ?? '';
    const [editingObra, setEditingObra] = useState(null);
    const [contractFilter, setContractFilter] = useState('todos');
    const [tipoFilter, setTipoFilter] = useState('todos');
    const [formOpen, setFormOpen] = useState(false);
    const form = useForm({
        nome: '',
        contract_id: defaultContractId,
        codigo: '',
        tipo: 'pai',
        obra_pai_id: '',
    });
    const filteredObrasPai = useMemo(
        () => obrasPai.filter((obra) => (
            String(obra.contract_id) === String(form.data.contract_id)
            && String(obra.id) !== String(editingObra?.id ?? '')
        )),
        [obrasPai, form.data.contract_id, editingObra?.id],
    );
    const filteredObras = useMemo(() => obras.filter((obra) => {
        if (contractFilter !== 'todos' && String(obra.contract_id) !== String(contractFilter)) {
            return false;
        }

        if (tipoFilter !== 'todos' && obra.tipo !== tipoFilter) {
            return false;
        }

        return true;
    }), [obras, contractFilter, tipoFilter]);

    const resetForm = () => {
        setEditingObra(null);
        form.clearErrors();
        form.setData({
            nome: '',
            contract_id: defaultContractId,
            codigo: '',
            tipo: 'pai',
            obra_pai_id: '',
        });
    };

    const openCreateForm = () => {
        resetForm();
        setFormOpen(true);
    };

    const closeForm = () => {
        resetForm();
        setFormOpen(false);
    };

    const startEditing = (obra) => {
        setEditingObra(obra);
        setFormOpen(true);
        form.clearErrors();
        form.setData({
            nome: obra.nome || '',
            contract_id: obra.contract_id || defaultContractId,
            codigo: obra.codigo || '',
            tipo: obra.tipo || 'pai',
            obra_pai_id: obra.obra_pai_id || '',
        });
    };

    const submit = (event) => {
        event.preventDefault();

        const targetRoute = editingObra
            ? route('tenant.parametrizacao.obras.update', [page.props.currentTenant.slug, editingObra.id])
            : route('tenant.parametrizacao.obras.store', page.props.currentTenant.slug);

        form.transform((data) => (editingObra ? { ...data, _method: 'patch' } : data));

        form.post(targetRoute, {
            preserveScroll: true,
            onSuccess: () => {
                resetForm();
                setFormOpen(false);
            },
        });
    };

    const setContract = (contractId) => {
        form.setData({
            ...form.data,
            contract_id: contractId,
            obra_pai_id: '',
        });
    };

    const setTipo = (tipo) => {
        form.setData({
            ...form.data,
            tipo,
            obra_pai_id: tipo === 'pai' ? '' : form.data.obra_pai_id,
        });
    };

    const deleteObra = (obra) => {
        if (editingObra?.id === obra.id) {
            resetForm();
        }

        router.delete(route('tenant.parametrizacao.obras.destroy', [page.props.currentTenant.slug, obra.id]), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Parametrizacao - Obras" />

            <section className={`sig-content grid gap-6 ${formOpen ? 'xl:grid-cols-[380px_minmax(0,1fr)]' : ''}`}>
                {formOpen && (
                <form className="sig-card p-5" onSubmit={submit}>
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <SlidersHorizontal size={14} />
                        <span className="eyebrow">Parametrizacao</span>
                    </div>
                    <h1 className="mt-2 text-xl font-semibold">{editingObra ? 'Editar obra' : 'Cadastrar obra'}</h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        {editingObra
                            ? 'Atualize os dados da obra selecionada.'
                            : 'Crie obras principais ou cadastre obras filhas vinculadas a uma obra pai.'}
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
                                onChange={(event) => setContract(event.target.value)}
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
                                placeholder="Ex: Residencial Jardim Central"
                                required
                            />
                        </Field>

                        <Field label="Codigo" error={form.errors.codigo}>
                            <input
                                value={form.data.codigo}
                                onChange={(event) => form.setData('codigo', event.target.value.replace(/\D/g, '').slice(0, 3))}
                                placeholder="001"
                                inputMode="numeric"
                                pattern="[0-9]{3}"
                                maxLength={3}
                                required
                            />
                        </Field>

                        <div>
                            <span className="eyebrow mb-1 block">Tipo de obra</span>
                            <div className="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    className={`sig-btn ${form.data.tipo === 'pai' ? 'sig-btn-primary' : 'sig-btn-secondary'}`}
                                    onClick={() => setTipo('pai')}
                                >
                                    Obra pai
                                </button>
                                <button
                                    type="button"
                                    className={`sig-btn ${form.data.tipo === 'filha' ? 'sig-btn-primary' : 'sig-btn-secondary'}`}
                                    onClick={() => setTipo('filha')}
                                >
                                    Obra filha
                                </button>
                            </div>
                            {form.errors.tipo && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.tipo}</span>}
                        </div>

                        {form.data.tipo === 'filha' && (
                            <Field label="Obra pai" error={form.errors.obra_pai_id}>
                                <select
                                    value={form.data.obra_pai_id}
                                    onChange={(event) => form.setData('obra_pai_id', event.target.value)}
                                    required
                                >
                                    <option value="">Selecione a obra pai</option>
                                    {filteredObrasPai.map((obra) => (
                                        <option key={obra.id} value={obra.id}>
                                            {obra.codigo} - {obra.nome}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        )}
                    </div>

                    <div className="mt-5 flex flex-wrap gap-2">
                        <button className="sig-btn sig-btn-primary" disabled={form.processing || contracts.length === 0 || (form.data.tipo === 'filha' && filteredObrasPai.length === 0)}>
                            {editingObra ? <Save size={15} /> : <Plus size={15} />}
                            {editingObra ? 'Salvar alteracoes' : 'Criar obra'}
                        </button>
                        <button type="button" className="sig-btn sig-btn-secondary" onClick={closeForm}>
                            <X size={15} />
                            {editingObra ? 'Cancelar' : 'Fechar'}
                        </button>
                        {editingObra && (
                            <button type="button" className="sig-btn sig-btn-ghost" onClick={resetForm}>
                                <X size={15} />
                                Limpar
                            </button>
                        )}
                    </div>
                </form>
                )}

                <section className="param-list-card sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <Building2 size={14} />
                                <span className="eyebrow">Obras cadastradas</span>
                            </div>
                            <h2 className="mt-1 text-[15px] font-semibold">
                                {filteredObras.length} de {obras.length} obras
                            </h2>
                        </div>
                        <button type="button" className="sig-btn sig-btn-primary sig-btn-sm" onClick={openCreateForm}>
                            <Plus size={13} />
                            Criar obra
                        </button>
                    </header>

                    {!formOpen && page.props.flash.success && (
                        <div className="border-b border-[var(--border)] bg-[var(--green-50)] px-5 py-3 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}
                    {!formOpen && page.props.flash.error && (
                        <div className="border-b border-[var(--border)] bg-[var(--red-50)] px-5 py-3 text-sm text-[var(--red)]">
                            {page.props.flash.error}
                        </div>
                    )}

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
                                <Filter size={12} />
                                Tipo
                            </span>
                            <span className="sig-input bg-white">
                                <select value={tipoFilter} onChange={(event) => setTipoFilter(event.target.value)}>
                                    <option value="todos">Todos os tipos</option>
                                    <option value="pai">Obra pai</option>
                                    <option value="filha">Obra filha</option>
                                </select>
                            </span>
                        </label>
                    </div>

                    {filteredObras.length > 0 ? (
                        <>
                        <div className="param-desktop-table overflow-x-auto">
                        <table className="sig-table min-w-[940px]">
                            <thead>
                                <tr>
                                    <th>Obra</th>
                                    <th>Contrato</th>
                                    <th>Codigo</th>
                                    <th>Tipo</th>
                                    <th>Vinculo</th>
                                    <th>Acoes</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredObras.map((obra) => (
                                    <tr key={obra.id}>
                                        <td>
                                            <div className="font-semibold">{obra.nome}</div>
                                        </td>
                                        <td>
                                            <div className="mono text-xs">{obra.contract?.code}</div>
                                            <div className="text-xs text-[var(--ink-500)]">{obra.contract?.name}</div>
                                        </td>
                                        <td className="mono">{obra.codigo}</td>
                                        <td>
                                            <span className={obra.tipo === 'pai' ? 'sig-pill sig-pill-blue' : 'sig-pill sig-pill-amber'}>
                                                {obra.tipo === 'pai' ? 'Obra pai' : 'Obra filha'}
                                            </span>
                                        </td>
                                        <td>
                                            {obra.obra_pai ? (
                                                <span className="inline-flex items-center gap-2 text-sm text-[var(--ink-700)]">
                                                    <GitBranch size={14} />
                                                    {obra.obra_pai.codigo} - {obra.obra_pai.nome}
                                                </span>
                                            ) : (
                                                <span className="text-sm text-[var(--ink-400)]">Sem obra pai</span>
                                            )}
                                        </td>
                                        <td>
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <button
                                                    type="button"
                                                    className="sig-btn sig-btn-secondary sig-btn-sm"
                                                    onClick={() => startEditing(obra)}
                                                >
                                                    <Pencil size={14} />
                                                    Editar
                                                </button>
                                                <ConfirmActionButton
                                                    title="Deletar obra"
                                                    message={`Deseja mesmo excluir a obra ${obra.nome}? Esta acao nao deve ser feita por engano.`}
                                                    confirmLabel="Deletar obra"
                                                    onConfirm={() => deleteObra(obra)}
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

                        <div className="param-responsive-list divide-y divide-[var(--border)]">
                            {filteredObras.map((obra) => (
                                <article key={obra.id} className="p-5">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className="text-sm font-semibold text-[var(--ink-900)]">{obra.nome}</h3>
                                                <span className={obra.tipo === 'pai' ? 'sig-pill sig-pill-blue' : 'sig-pill sig-pill-amber'}>
                                                    {obra.tipo === 'pai' ? 'Obra pai' : 'Obra filha'}
                                                </span>
                                            </div>
                                            <div className="mono mt-1 text-xs text-[var(--ink-500)]">{obra.codigo}</div>
                                        </div>
                                    </div>

                                    <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                        <CompactInfo label="Contrato" value={`${obra.contract?.code || '-'} - ${obra.contract?.name || 'Sem contrato'}`} />
                                        <CompactInfo
                                            label="Vinculo"
                                            value={obra.obra_pai ? `${obra.obra_pai.codigo} - ${obra.obra_pai.nome}` : 'Sem obra pai'}
                                        />
                                    </div>

                                    <div className="mt-4 flex flex-wrap gap-2 border-t border-[var(--border)] pt-4">
                                        <button
                                            type="button"
                                            className="sig-btn sig-btn-secondary sig-btn-sm"
                                            onClick={() => startEditing(obra)}
                                        >
                                            <Pencil size={14} />
                                            Editar
                                        </button>
                                        <ConfirmActionButton
                                            title="Deletar obra"
                                            message={`Deseja mesmo excluir a obra ${obra.nome}? Esta acao nao deve ser feita por engano.`}
                                            confirmLabel="Deletar obra"
                                            onConfirm={() => deleteObra(obra)}
                                        >
                                            <Trash2 size={14} />
                                            Deletar
                                        </ConfirmActionButton>
                                    </div>
                                </article>
                            ))}
                        </div>
                        </>
                    ) : (
                        <div className="p-12 text-center text-sm text-[var(--ink-500)]">
                            {obras.length === 0 ? 'Nenhuma obra cadastrada ainda.' : 'Nenhuma obra encontrada para os filtros selecionados.'}
                        </div>
                    )}
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function CompactInfo({ label, value }) {
    return (
        <div>
            <div className="eyebrow">{label}</div>
            <div className="mt-1 break-words text-[13px] font-semibold text-[var(--ink-800)]">{value}</div>
        </div>
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
