import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GedTour from '@/Components/GedTour';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, FileSearch, Pencil, Plus, Tags, Trash2, X } from 'lucide-react';
import { useState } from 'react';

function formatDateTime(value) {
    if (!value) return '—';

    return new Date(value).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function EmptyState({ children }) {
    return (
        <div className="rounded-xl border border-dashed border-[var(--border)] bg-slate-50/70 p-6 text-center text-sm text-[var(--ink-500)]">
            {children}
        </div>
    );
}

function ContractOptions({ contracts }) {
    return (
        <>
            <option value="">Selecione um contrato</option>
            {contracts.map((contract) => (
                <option key={contract.id} value={contract.id}>
                    {contract.code} - {contract.name}
                </option>
            ))}
        </>
    );
}

export default function GedSettings({ tenant, contracts = [], types = [], tags = [] }) {
    const [editingTypeId, setEditingTypeId] = useState(null);
    const [editingTagId, setEditingTagId] = useState(null);
    const typeForm = useForm({
        name: '',
        contract_id: '',
    });

    const tagForm = useForm({
        name: '',
        contract_id: '',
        color: '#2563eb',
    });

    const editTypeForm = useForm({
        name: '',
        contract_id: '',
    });

    const editTagForm = useForm({
        name: '',
        contract_id: '',
        color: '#2563eb',
    });

    function submitType(event) {
        event.preventDefault();

        typeForm.post(route('tenant.ged.types.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => typeForm.reset(),
        });
    }

    function submitTag(event) {
        event.preventDefault();

        tagForm.post(route('tenant.ged.tags.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => tagForm.reset('name'),
        });
    }

    function startEditType(type) {
        setEditingTagId(null);
        setEditingTypeId(type.id);
        editTypeForm.setData({
            name: type.name || '',
            contract_id: type.contract_id || '',
        });
        editTypeForm.clearErrors();
    }

    function submitTypeEdit(event, type) {
        event.preventDefault();

        if (!window.confirm('Deseja salvar as alteracoes deste tipo documental?')) return;

        editTypeForm.patch(route('tenant.ged.types.update', [tenant.slug, type.id]), {
            preserveScroll: true,
            onSuccess: () => {
                setEditingTypeId(null);
                editTypeForm.reset();
            },
        });
    }

    function destroyType(type) {
        if ((type.documents_count || 0) > 0) {
            window.alert('Nao e possivel excluir este tipo documental porque existem documentos cadastrados com ele.');
            return;
        }

        if (!window.confirm('Deseja excluir este tipo documental?')) return;

        router.delete(route('tenant.ged.types.destroy', [tenant.slug, type.id]), {
            preserveScroll: true,
            onError: (errors) => window.alert(errors.type || 'Nao foi possivel excluir este tipo documental.'),
        });
    }

    function startEditTag(tag) {
        setEditingTypeId(null);
        setEditingTagId(tag.id);
        editTagForm.setData({
            name: tag.name || '',
            contract_id: tag.contract_id || '',
            color: tag.color || '#2563eb',
        });
        editTagForm.clearErrors();
    }

    function submitTagEdit(event, tag) {
        event.preventDefault();

        if (!window.confirm('Deseja salvar as alteracoes desta etiqueta?')) return;

        editTagForm.patch(route('tenant.ged.tags.update', [tenant.slug, tag.id]), {
            preserveScroll: true,
            onSuccess: () => {
                setEditingTagId(null);
                editTagForm.reset();
            },
        });
    }

    function destroyTag(tag) {
        if ((tag.documents_count || 0) > 0) {
            window.alert('Nao e possivel excluir esta etiqueta porque existem documentos cadastrados com ela.');
            return;
        }

        if (!window.confirm('Deseja excluir esta etiqueta?')) return;

        router.delete(route('tenant.ged.tags.destroy', [tenant.slug, tag.id]), {
            preserveScroll: true,
            onError: (errors) => window.alert(errors.tag || 'Nao foi possivel excluir esta etiqueta.'),
        });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Parametrização GED" />

            <div className="space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-8 xl:px-10">
                <div data-tour="ged-settings-overview" className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div className="eyebrow">GED</div>
                        <h1 className="mt-2 text-2xl font-bold text-[var(--ink-900)]">Parametrização da documentação</h1>
                        <p className="mt-1 max-w-3xl text-sm text-[var(--ink-600)]">
                            Cadastre a base de classificação do GED por contrato. Os tipos documentais e etiquetas serão usados para organizar e filtrar os documentos.
                        </p>
                    </div>

                    <Link href={route('tenant.ged.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                        <ArrowLeft size={16} />
                        Voltar aos documentos
                    </Link>
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section data-tour="ged-types" className="rounded-2xl border border-[var(--border)] bg-white shadow-sm">
                        <div className="flex items-center gap-3 border-b border-[var(--border)] p-5">
                            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                <FileSearch size={21} />
                            </div>
                            <div>
                                <h2 className="font-semibold text-[var(--ink-900)]">Tipos documentais</h2>
                                <p className="text-sm text-[var(--ink-500)]">Ex.: Contrato, Nota fiscal, ART, Projeto, Relatório.</p>
                            </div>
                        </div>

                        <form onSubmit={submitType} className="grid gap-4 border-b border-[var(--border)] p-5">
                            <div className="grid gap-4 md:grid-cols-2">
                                <label className="ged-field">
                                    <span className="ged-label">Nome</span>
                                    <input
                                        className="ged-control"
                                        value={typeForm.data.name}
                                        onChange={(event) => typeForm.setData('name', event.target.value)}
                                        placeholder="Ex.: Contrato"
                                        required
                                    />
                                    {typeForm.errors.name && <span className="mt-1 block text-xs text-rose-600">{typeForm.errors.name}</span>}
                                </label>

                                <label className="ged-field">
                                    <span className="ged-label">Contrato</span>
                                    <select
                                        className="ged-control"
                                        value={typeForm.data.contract_id}
                                        onChange={(event) => typeForm.setData('contract_id', event.target.value)}
                                        required
                                    >
                                        <ContractOptions contracts={contracts} />
                                    </select>
                                    {typeForm.errors.contract_id && <span className="mt-1 block text-xs text-rose-600">{typeForm.errors.contract_id}</span>}
                                </label>
                            </div>

                            <div className="flex justify-end">
                                <button className="sig-btn sig-btn-primary" disabled={typeForm.processing}>
                                    <Plus size={16} />
                                    Criar tipo documental
                                </button>
                            </div>
                        </form>

                        <div className="p-5">
                            {types.length === 0 ? (
                                <EmptyState>Nenhum tipo documental cadastrado.</EmptyState>
                            ) : (
                                <div className="space-y-2">
                                    {types.map((type) => (
                                        editingTypeId === type.id ? (
                                            <form key={type.id} onSubmit={(event) => submitTypeEdit(event, type)} className="grid gap-3 rounded-xl border border-blue-200 bg-blue-50/40 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                                                <label className="ged-field">
                                                    <span className="ged-label">Nome</span>
                                                    <input className="ged-control" value={editTypeForm.data.name} onChange={(event) => editTypeForm.setData('name', event.target.value)} required />
                                                    {editTypeForm.errors.name && <span className="mt-1 block text-xs text-rose-600">{editTypeForm.errors.name}</span>}
                                                </label>

                                                <label className="ged-field">
                                                    <span className="ged-label">Contrato</span>
                                                    <select className="ged-control" value={editTypeForm.data.contract_id} onChange={(event) => editTypeForm.setData('contract_id', event.target.value)} required>
                                                        <ContractOptions contracts={contracts} />
                                                    </select>
                                                    {editTypeForm.errors.contract_id && <span className="mt-1 block text-xs text-rose-600">{editTypeForm.errors.contract_id}</span>}
                                                </label>

                                                <div className="flex gap-2">
                                                    <button type="submit" className="sig-btn sig-btn-primary !min-h-10 !px-3" disabled={editTypeForm.processing} title="Salvar alteracoes">
                                                        <Check size={16} />
                                                    </button>
                                                    <button type="button" className="sig-btn sig-btn-secondary !min-h-10 !px-3" onClick={() => setEditingTypeId(null)} title="Cancelar edicao">
                                                        <X size={16} />
                                                    </button>
                                                </div>
                                            </form>
                                        ) : (
                                        <div key={type.id} className="flex items-center justify-between gap-3 rounded-xl border border-[var(--border)] bg-slate-50/50 p-3">
                                            <div className="min-w-0">
                                                <div className="truncate font-semibold text-[var(--ink-900)]">{type.name}</div>
                                                <div className="text-xs text-[var(--ink-500)]">
                                                    {type.contract ? `${type.contract.code} - ${type.contract.name}` : 'Sem contrato'} · {type.documents_count || 0} documento(s) · {formatDateTime(type.created_at)}
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-2">
                                                <span className="sig-pill sig-pill-blue">{type.documents_count || 0}</span>
                                                <button type="button" className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-blue-200 hover:text-blue-700" onClick={() => startEditType(type)} title="Editar tipo documental">
                                                    <Pencil size={16} />
                                                </button>
                                                <button type="button" className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-rose-100 bg-white text-rose-600 hover:bg-rose-50" onClick={() => destroyType(type)} title="Excluir tipo documental">
                                                    <Trash2 size={16} />
                                                </button>
                                            </div>
                                        </div>
                                        )
                                    ))}
                                </div>
                            )}
                        </div>
                    </section>

                    <section data-tour="ged-tags" className="rounded-2xl border border-[var(--border)] bg-white shadow-sm">
                        <div className="flex items-center gap-3 border-b border-[var(--border)] p-5">
                            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
                                <Tags size={21} />
                            </div>
                            <div>
                                <h2 className="font-semibold text-[var(--ink-900)]">Etiquetas</h2>
                                <p className="text-sm text-[var(--ink-500)]">Marque documentos por tema, prioridade, status ou etapa.</p>
                            </div>
                        </div>

                        <form onSubmit={submitTag} className="grid gap-4 border-b border-[var(--border)] p-5">
                            <div className="grid gap-4 md:grid-cols-[1fr_1fr_96px]">
                                <label className="ged-field">
                                    <span className="ged-label">Nome</span>
                                    <input
                                        className="ged-control"
                                        value={tagForm.data.name}
                                        onChange={(event) => tagForm.setData('name', event.target.value)}
                                        placeholder="Ex.: Assinado"
                                        required
                                    />
                                    {tagForm.errors.name && <span className="mt-1 block text-xs text-rose-600">{tagForm.errors.name}</span>}
                                </label>

                                <label className="ged-field">
                                    <span className="ged-label">Contrato</span>
                                    <select
                                        className="ged-control"
                                        value={tagForm.data.contract_id}
                                        onChange={(event) => tagForm.setData('contract_id', event.target.value)}
                                        required
                                    >
                                        <ContractOptions contracts={contracts} />
                                    </select>
                                    {tagForm.errors.contract_id && <span className="mt-1 block text-xs text-rose-600">{tagForm.errors.contract_id}</span>}
                                </label>

                                <label className="ged-field">
                                    <span className="ged-label">Cor</span>
                                    <input
                                        type="color"
                                        className="ged-control h-[42px]"
                                        value={tagForm.data.color}
                                        onChange={(event) => tagForm.setData('color', event.target.value)}
                                    />
                                </label>
                            </div>

                            <div className="flex justify-end">
                                <button className="sig-btn sig-btn-primary" disabled={tagForm.processing}>
                                    <Plus size={16} />
                                    Criar etiqueta
                                </button>
                            </div>
                        </form>

                        <div className="p-5">
                            {tags.length === 0 ? (
                                <EmptyState>Nenhuma etiqueta cadastrada.</EmptyState>
                            ) : (
                                <div className="space-y-2">
                                    {tags.map((tag) => (
                                        editingTagId === tag.id ? (
                                            <form key={tag.id} onSubmit={(event) => submitTagEdit(event, tag)} className="grid gap-3 rounded-xl border border-emerald-200 bg-emerald-50/40 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_96px_auto] md:items-end">
                                                <label className="ged-field">
                                                    <span className="ged-label">Nome</span>
                                                    <input className="ged-control" value={editTagForm.data.name} onChange={(event) => editTagForm.setData('name', event.target.value)} required />
                                                    {editTagForm.errors.name && <span className="mt-1 block text-xs text-rose-600">{editTagForm.errors.name}</span>}
                                                </label>

                                                <label className="ged-field">
                                                    <span className="ged-label">Contrato</span>
                                                    <select className="ged-control" value={editTagForm.data.contract_id} onChange={(event) => editTagForm.setData('contract_id', event.target.value)} required>
                                                        <ContractOptions contracts={contracts} />
                                                    </select>
                                                    {editTagForm.errors.contract_id && <span className="mt-1 block text-xs text-rose-600">{editTagForm.errors.contract_id}</span>}
                                                </label>

                                                <label className="ged-field">
                                                    <span className="ged-label">Cor</span>
                                                    <input type="color" className="ged-control h-[42px]" value={editTagForm.data.color} onChange={(event) => editTagForm.setData('color', event.target.value)} />
                                                    {editTagForm.errors.color && <span className="mt-1 block text-xs text-rose-600">{editTagForm.errors.color}</span>}
                                                </label>

                                                <div className="flex gap-2">
                                                    <button type="submit" className="sig-btn sig-btn-primary !min-h-10 !px-3" disabled={editTagForm.processing} title="Salvar alteracoes">
                                                        <Check size={16} />
                                                    </button>
                                                    <button type="button" className="sig-btn sig-btn-secondary !min-h-10 !px-3" onClick={() => setEditingTagId(null)} title="Cancelar edicao">
                                                        <X size={16} />
                                                    </button>
                                                </div>
                                            </form>
                                        ) : (
                                            <div key={tag.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[var(--border)] bg-white px-3 py-2 text-sm shadow-sm">
                                                <div className="flex min-w-0 items-center gap-2">
                                                    <span className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: tag.color }} />
                                                    <span className="truncate font-semibold text-[var(--ink-800)]">{tag.name}</span>
                                                    <span className="text-xs text-[var(--ink-500)]">{tag.contract?.code || 'Sem contrato'}</span>
                                                    <span className="text-xs text-[var(--ink-500)]">{tag.documents_count || 0} documento(s)</span>
                                                </div>
                                                <div className="flex shrink-0 items-center gap-2">
                                                    <button type="button" className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-emerald-200 hover:text-emerald-700" onClick={() => startEditTag(tag)} title="Editar etiqueta">
                                                        <Pencil size={16} />
                                                    </button>
                                                    <button type="button" className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-rose-100 bg-white text-rose-600 hover:bg-rose-50" onClick={() => destroyTag(tag)} title="Excluir etiqueta">
                                                        <Trash2 size={16} />
                                                    </button>
                                                </div>
                                            </div>
                                        )
                                    ))}
                                </div>
                            )}
                        </div>
                    </section>
                </div>
            </div>
            <GedTour tenant={tenant} section="settings" />
        </AuthenticatedLayout>
    );
}
