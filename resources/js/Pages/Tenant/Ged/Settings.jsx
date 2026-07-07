import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, FileSearch, Plus, Tags } from 'lucide-react';

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
    const typeForm = useForm({
        name: '',
        contract_id: '',
    });

    const tagForm = useForm({
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

    return (
        <AuthenticatedLayout>
            <Head title="Parametrização GED" />

            <div className="space-y-6 px-1 pb-8 sm:px-2">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
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
                    <section className="rounded-2xl border border-[var(--border)] bg-white shadow-sm">
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
                                        <div key={type.id} className="flex items-center justify-between gap-3 rounded-xl border border-[var(--border)] bg-slate-50/50 p-3">
                                            <div className="min-w-0">
                                                <div className="truncate font-semibold text-[var(--ink-900)]">{type.name}</div>
                                                <div className="text-xs text-[var(--ink-500)]">
                                                    {type.contract ? `${type.contract.code} - ${type.contract.name}` : 'Sem contrato'} · {type.documents_count || 0} documento(s) · {formatDateTime(type.created_at)}
                                                </div>
                                            </div>
                                            <span className="sig-pill sig-pill-blue">{type.documents_count || 0}</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </section>

                    <section className="rounded-2xl border border-[var(--border)] bg-white shadow-sm">
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
                                <div className="flex flex-wrap gap-2">
                                    {tags.map((tag) => (
                                        <div key={tag.id} className="inline-flex items-center gap-2 rounded-full border border-[var(--border)] bg-white px-3 py-2 text-sm shadow-sm">
                                            <span className="h-3 w-3 rounded-full" style={{ backgroundColor: tag.color }} />
                                            <span className="font-semibold text-[var(--ink-800)]">{tag.name}</span>
                                            <span className="text-xs text-[var(--ink-500)]">{tag.contract?.code || 'Sem contrato'}</span>
                                            <span className="text-xs text-[var(--ink-500)]">{tag.documents_count || 0}</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
