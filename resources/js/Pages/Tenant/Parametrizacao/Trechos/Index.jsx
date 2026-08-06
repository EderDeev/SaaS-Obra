import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Filter, MapPinned, Pencil, Plus, Save, Search, Trash2, X } from 'lucide-react';
import { useMemo, useState } from 'react';

export default function ParametrizacaoTrechosIndex({ trechos, contracts, obras }) {
    const page = usePage();
    const canManage = Boolean(page.props.parametrizacaoPermissions?.can?.manage_parametrizacao_trechos);
    const defaultObraId = obras[0]?.id ?? '';
    const [editingTrecho, setEditingTrecho] = useState(null);
    const [contractFilter, setContractFilter] = useState('todos');
    const [obraFilter, setObraFilter] = useState('todos');
    const [query, setQuery] = useState('');
    const [formOpen, setFormOpen] = useState(false);
    const form = useForm({ obra_id: defaultObraId, codigo: '', nome: '' });

    const obrasForFilter = useMemo(
        () => contractFilter === 'todos'
            ? obras
            : obras.filter((obra) => String(obra.contract_id) === String(contractFilter)),
        [obras, contractFilter],
    );

    const filteredTrechos = useMemo(() => {
        const term = query.trim().toLowerCase();

        return trechos.filter((trecho) => {
            if (contractFilter !== 'todos' && String(trecho.obra?.contract_id) !== String(contractFilter)) return false;
            if (obraFilter !== 'todos' && String(trecho.obra_id) !== String(obraFilter)) return false;
            if (!term) return true;

            return `${trecho.codigo} ${trecho.nome} ${trecho.obra?.codigo || ''} ${trecho.obra?.nome || ''}`
                .toLowerCase()
                .includes(term);
        });
    }, [trechos, contractFilter, obraFilter, query]);

    const resetForm = () => {
        setEditingTrecho(null);
        form.clearErrors();
        form.setData({ obra_id: defaultObraId, codigo: '', nome: '' });
    };

    const closeForm = () => {
        resetForm();
        setFormOpen(false);
    };

    const startEditing = (trecho) => {
        setEditingTrecho(trecho);
        setFormOpen(true);
        form.clearErrors();
        form.setData({ obra_id: trecho.obra_id, codigo: trecho.codigo || '', nome: trecho.nome || '' });
    };

    const submit = (event) => {
        event.preventDefault();
        const target = editingTrecho
            ? route('tenant.parametrizacao.trechos.update', [page.props.currentTenant.slug, editingTrecho.id])
            : route('tenant.parametrizacao.trechos.store', page.props.currentTenant.slug);

        form.transform((data) => editingTrecho ? { ...data, _method: 'patch' } : data);
        form.post(target, {
            preserveScroll: true,
            onSuccess: closeForm,
        });
    };

    const remove = (trecho) => router.delete(
        route('tenant.parametrizacao.trechos.destroy', [page.props.currentTenant.slug, trecho.id]),
        { preserveScroll: true },
    );

    return (
        <AuthenticatedLayout>
            <Head title="Parametrização - Trechos" />

            <section className={`sig-content grid gap-6 ${formOpen ? 'xl:grid-cols-[380px_minmax(0,1fr)]' : ''}`}>
                {formOpen && canManage && (
                    <form className="sig-card p-5" onSubmit={submit}>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <MapPinned size={14} />
                            <span className="eyebrow">Parametrização</span>
                        </div>
                        <h1 className="mt-2 text-xl font-semibold">{editingTrecho ? 'Editar trecho' : 'Cadastrar trecho'}</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Vincule localizadores à obra. O código fará parte da EAP dos novos projetos.
                        </p>

                        <div className="mt-5 grid gap-3">
                            <Field label="Obra" error={form.errors.obra_id}>
                                <select value={form.data.obra_id} onChange={(event) => form.setData('obra_id', event.target.value)} required>
                                    <option value="">Selecione a obra</option>
                                    {obras.map((obra) => (
                                        <option key={obra.id} value={obra.id}>
                                            {obra.contract?.code} · {obra.codigo} - {obra.nome}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Código" error={form.errors.codigo}>
                                <input
                                    value={form.data.codigo}
                                    onChange={(event) => form.setData('codigo', event.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 3))}
                                    placeholder="T01"
                                    maxLength={3}
                                    pattern="[A-Za-z0-9]{3}"
                                    required
                                />
                            </Field>
                            <Field label="Nome" error={form.errors.nome}>
                                <input value={form.data.nome} onChange={(event) => form.setData('nome', event.target.value)} placeholder="Ex: km 0+000 ao km 12+500" required />
                            </Field>
                        </div>

                        <div className="mt-5 flex flex-wrap gap-2">
                            <button className="sig-btn sig-btn-primary" disabled={form.processing || obras.length === 0}>
                                {editingTrecho ? <Save size={15} /> : <Plus size={15} />}
                                {editingTrecho ? 'Salvar alterações' : 'Criar trecho'}
                            </button>
                            <button type="button" className="sig-btn sig-btn-secondary" onClick={closeForm}><X size={15} />Cancelar</button>
                        </div>
                    </form>
                )}

                <section className="param-list-card sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]"><MapPinned size={14} /><span className="eyebrow">Trechos cadastrados</span></div>
                            <h2 className="mt-1 text-[15px] font-semibold">{filteredTrechos.length} de {trechos.length} trechos</h2>
                        </div>
                        {canManage && <button type="button" className="sig-btn sig-btn-primary sig-btn-sm" onClick={() => { resetForm(); setFormOpen(true); }}><Plus size={13} />Criar trecho</button>}
                    </header>

                    {page.props.flash.success && <div className="border-b border-[var(--border)] bg-[var(--green-50)] px-5 py-3 text-sm text-[var(--green)]">{page.props.flash.success}</div>}
                    {page.props.flash.error && <div className="border-b border-[var(--border)] bg-[var(--red-50)] px-5 py-3 text-sm text-[var(--red)]">{page.props.flash.error}</div>}

                    <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 lg:grid-cols-3">
                        <FilterField label="Contrato">
                            <select value={contractFilter} onChange={(event) => { setContractFilter(event.target.value); setObraFilter('todos'); }}>
                                <option value="todos">Todos os contratos</option>
                                {contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} - {contract.name}</option>)}
                            </select>
                        </FilterField>
                        <FilterField label="Obra">
                            <select value={obraFilter} onChange={(event) => setObraFilter(event.target.value)}>
                                <option value="todos">Todas as obras</option>
                                {obrasForFilter.map((obra) => <option key={obra.id} value={obra.id}>{obra.codigo} - {obra.nome}</option>)}
                            </select>
                        </FilterField>
                        <FilterField label="Busca" icon={Search}>
                            <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Código, trecho ou obra" />
                        </FilterField>
                    </div>

                    {filteredTrechos.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="sig-table min-w-[760px]">
                                <thead><tr><th>Trecho</th><th>Obra</th><th>Contrato</th><th>Tipo</th><th>Ações</th></tr></thead>
                                <tbody>
                                    {filteredTrechos.map((trecho) => (
                                        <tr key={trecho.id}>
                                            <td><div className="mono font-semibold text-[var(--primary)]">{trecho.codigo}</div><div className="text-xs text-[var(--ink-500)]">{trecho.nome}</div></td>
                                            <td><div className="font-semibold">{trecho.obra?.codigo} - {trecho.obra?.nome}</div></td>
                                            <td><div className="mono text-xs">{trecho.obra?.contract?.code}</div><div className="text-xs text-[var(--ink-500)]">{trecho.obra?.contract?.name}</div></td>
                                            <td>{trecho.is_default ? <span className="sig-pill sig-pill-blue">Padrão</span> : <span className="text-sm">Trecho</span>}</td>
                                            <td>
                                                {canManage && !trecho.is_default && <div className="flex flex-wrap justify-end gap-2">
                                                    <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" onClick={() => startEditing(trecho)}><Pencil size={14} />Editar</button>
                                                    <ConfirmActionButton title="Excluir trecho" message={`Deseja excluir o trecho ${trecho.codigo} - ${trecho.nome}?`} confirmLabel="Excluir trecho" onConfirm={() => remove(trecho)}><Trash2 size={14} />Excluir</ConfirmActionButton>
                                                </div>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : <div className="p-12 text-center text-sm text-[var(--ink-500)]">Nenhum trecho encontrado.</div>}
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function Field({ label, error, children }) {
    return <label><span className="eyebrow mb-1 block">{label}</span><span className="sig-input">{children}</span>{error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}</label>;
}

function FilterField({ label, icon: Icon = Filter, children }) {
    return <label><span className="eyebrow mb-1 flex items-center gap-1"><Icon size={12} />{label}</span><span className="sig-input bg-white">{children}</span></label>;
}
