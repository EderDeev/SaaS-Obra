import OsDnitDeleteButton from '@/Components/OsDnitDeleteButton';
import OsDnitTourButton from '@/Components/OsDnitTourButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Loader2, Package, Pencil, Plus, Save, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

function Field({ name, label, error, children }) {
    return (
        <div className="grid content-start gap-1.5">
            <label htmlFor={`product-${name}`} className="text-sm font-medium text-[var(--ink-700)]">{label}</label>
            {children}
            {error && <p id={`product-${name}-error`} className="text-sm text-red-700" role="alert">{error}</p>}
        </div>
    );
}

export default function Products({ products, filters, categories, frequencies, canCreate, contracts }) {
    const { currentTenant, flash } = usePage().props;
    const [editingProduct, setEditingProduct] = useState(null);
    const createContracts = contracts.filter((contract) => contract.can_create);
    const [formOpen, setFormOpen] = useState(false);
    const productDialog = useRef(null);
    const productCodeInput = useRef(null);
    const createProductButton = useRef(null);
    const form = useForm({ contract_id: createContracts.length === 1 ? String(createContracts[0].id) : '', code: '', name: '', category: '', unit: '', frequency: 'on_demand' });
    const search = useForm({ q: filters.q || '', category: filters.category || '', contract_id: filters.contract_id || '' });
    const indexUrl = route('tenant.os-dnit.products.index', currentTenant.slug);
    const [filtering, setFiltering] = useState(false);
    const filterTimer = useRef(null);
    const filterRequest = useRef(null);
    const filterVersion = useRef(0);

    useEffect(() => () => {
        clearTimeout(filterTimer.current);
        filterVersion.current++;
        filterRequest.current?.cancel();
    }, []);

    useEffect(() => {
        search.setData({ q: filters.q || '', category: filters.category || '', contract_id: filters.contract_id || '' });
    }, [filters.q, filters.category, filters.contract_id]);

    function filterProducts(data, delay = 0) {
        clearTimeout(filterTimer.current);
        const version = ++filterVersion.current;
        filterRequest.current?.cancel();
        filterRequest.current = null;
        search.setData(data);
        setFiltering(true);
        const visit = () => router.get(indexUrl, data, {
            preserveState: true, preserveScroll: true, replace: true,
            onCancelToken: (token) => { filterRequest.current = token; },
            onFinish: () => {
                if (filterVersion.current === version) {
                    setFiltering(false);
                    filterRequest.current = null;
                }
            },
        });
        if (delay) filterTimer.current = setTimeout(visit, delay);
        else visit();
    }

    useEffect(() => {
        const dialog = productDialog.current;
        if (!formOpen || !dialog) return;

        const previousOverflow = document.body.style.overflow;
        const previousFocus = document.activeElement;
        dialog.showModal();
        productCodeInput.current?.focus();
        document.body.style.overflow = 'hidden';

        return () => {
            dialog.close();
            document.body.style.overflow = previousOverflow;
            if (previousFocus?.isConnected) previousFocus.focus();
            else createProductButton.current?.focus();
        };
    }, [formOpen]);

    const closeForm = () => {
        setFormOpen(false);
        setEditingProduct(null);
        form.reset();
        form.clearErrors();
    };

    const openProduct = (product = null) => {
        setEditingProduct(product);
        form.clearErrors();
        form.setData(product ? { contract_id: String(product.contract_id), code: product.code, name: product.name, category: product.category, unit: product.unit, frequency: product.frequency }
            : { contract_id: createContracts.some((c) => String(c.id) === String(filters.contract_id)) ? String(filters.contract_id) : createContracts.length === 1 ? String(createContracts[0].id) : '', code: '', name: '', category: '', unit: '', frequency: 'on_demand' });
        setFormOpen(true);
    };
    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: closeForm };
        if (editingProduct) form.patch(route('tenant.os-dnit.products.update', [currentTenant.slug, editingProduct.id]), options);
        else form.post(route('tenant.os-dnit.products.store', currentTenant.slug), options);
    };

    const inputProps = (name) => ({
        id: `product-${name}`,
        value: form.data[name],
        onChange: (event) => form.setData(name, event.target.value),
        required: true,
        'aria-invalid': Boolean(form.errors[name]),
        'aria-describedby': form.errors[name] ? `product-${name}-error` : undefined,
        className: 'w-full rounded-lg border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500',
    });

    return (
        <AuthenticatedLayout>
            <Head title="Ordem de Serviço · Produtos" />
            <div className="sig-content os-dnit-page space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="eyebrow">Ordem de Serviço</div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">Produtos</h1>
                        <p className="mt-2 text-sm text-[var(--ink-500)]">Catálogo de produtos para as ordens de serviço do DNIT.</p>
                    </div>
                    <div className="flex flex-wrap gap-2"><OsDnitTourButton tenantSlug={currentTenant.slug} />{canCreate && (
                        <button ref={createProductButton} type="button" onClick={() => openProduct()} className="sig-btn sig-btn-primary">
                            <Plus size={16} /> Novo produto
                        </button>
                    )}</div>
                </header>

                {contracts.length === 0 && <p role="status" className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Você não possui vínculo ativo com um contrato desta empresa.</p>}
                {flash?.success && <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{flash.success}</div>}

                {formOpen && (
                    <dialog
                        ref={productDialog}
                        aria-labelledby="new-product-title"
                        aria-modal="true"
                        className="os-dnit-dialog fixed inset-0 !m-auto max-h-[90dvh] w-[95vw] max-w-3xl overflow-y-auto rounded-2xl border-0 bg-white p-0 text-[var(--ink-900)] shadow-2xl backdrop:bg-slate-900/50"
                        onCancel={(event) => {
                            event.preventDefault();
                            if (!form.processing) closeForm();
                        }}
                        onClick={(event) => {
                            if (event.target === event.currentTarget && !form.processing) closeForm();
                        }}
                    >
                    <form onSubmit={submit} className="space-y-5 p-5 sm:p-6" aria-busy={form.processing}>
                        <div className="flex items-center justify-between gap-3">
                            <h2 id="new-product-title" className="text-lg font-semibold">{editingProduct ? 'Editar produto' : 'Novo produto'}</h2>
                            <button type="button" onClick={closeForm} disabled={form.processing} aria-label="Fechar cadastro" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"><X size={18} /></button>
                        </div>
                        <Field name="contract_id" label="Contrato" error={form.errors.contract_id}>
                            <select {...inputProps('contract_id')} disabled={Boolean(editingProduct)}><option value="">Selecione o contrato</option>{(editingProduct ? contracts : createContracts).map((contract) => <option key={contract.id} value={contract.id}>{contract.code} — {contract.name}</option>)}</select>
                        </Field>
                        <div className="grid gap-4 md:grid-cols-[160px_minmax(0,1fr)]">
                            <Field name="code" label="Código" error={form.errors.code}>
                                <input {...inputProps('code')} ref={productCodeInput} maxLength={40} placeholder="Ex.: 02" />
                            </Field>
                            <Field name="name" label="Nome do produto" error={form.errors.name}>
                                <input {...inputProps('name')} maxLength={255} placeholder="Ex.: Controle de Obras" />
                            </Field>
                        </div>
                        <div className="grid gap-4 md:grid-cols-3">
                            <Field name="category" label="Categoria" error={form.errors.category}>
                                <select {...inputProps('category')}>
                                    <option value="">Selecione a categoria</option>
                                    {Object.entries(categories).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                </select>
                            </Field>
                            <Field name="unit" label="Unidade de medida" error={form.errors.unit}>
                                <input {...inputProps('unit')} list="product-units" maxLength={20} placeholder="Ex.: mês, unid, km ou m²" />
                                <datalist id="product-units">{['mês', 'unid', 'km', 'm', 'm²', 'm³', 'ha', 'h'].map((unit) => <option key={unit} value={unit} />)}</datalist>
                            </Field>
                            <Field name="frequency" label="Frequência" error={form.errors.frequency}>
                                <select {...inputProps('frequency')}>
                                    {Object.entries(frequencies).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                </select>
                            </Field>
                        </div>
                        <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
                            <button type="button" className="sig-btn sig-btn-secondary" onClick={closeForm} disabled={form.processing}>Cancelar</button>
                            <button type="submit" className="sig-btn sig-btn-primary" disabled={form.processing}><Save size={16} />{form.processing ? 'Salvando…' : editingProduct ? 'Salvar alterações' : 'Salvar produto'}</button>
                        </div>
                    </form>
                    </dialog>
                )}

                <section className="space-y-4" aria-label="Catálogo de produtos" aria-busy={filtering}>
                    <form onSubmit={(event) => {
                        event.preventDefault();
                        filterProducts(search.data);
                    }} className="sig-card flex flex-wrap items-end gap-3 p-4">
                        <div className="min-w-48 flex-1">
                            <label htmlFor="product-search" className="mb-1.5 block text-xs font-medium text-[var(--ink-500)]">Buscar produto</label>
                            <input id="product-search" value={search.data.q} onChange={(event) => filterProducts({ ...search.data, q: event.target.value }, 350)} maxLength={120} placeholder="Código ou nome do produto" className="w-full rounded-lg border-slate-300 text-sm" />
                        </div>
                        <div className="min-w-48 flex-1 sm:max-w-72">
                            <label htmlFor="product-category-filter" className="mb-1.5 block text-xs font-medium text-[var(--ink-500)]">Categoria</label>
                            <select id="product-category-filter" value={search.data.category} onChange={(event) => filterProducts({ ...search.data, category: event.target.value })} className="w-full rounded-lg border-slate-300 text-sm">
                                <option value="">Todas as categorias</option>
                                {Object.entries(categories).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                            </select>
                        </div>
                        <div className="min-w-48 flex-1 sm:max-w-72"><label htmlFor="product-contract-filter" className="mb-1.5 block text-xs font-medium text-[var(--ink-500)]">Contrato</label><select id="product-contract-filter" value={search.data.contract_id} onChange={(event) => filterProducts({ ...search.data, contract_id: event.target.value })} className="w-full rounded-lg border-slate-300 text-sm"><option value="">Todos os meus contratos</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} — {contract.name}</option>)}</select></div>
                        {(search.data.q || search.data.category || search.data.contract_id) && <button type="button" onClick={() => filterProducts({ q: '', category: '', contract_id: '' })} className="sig-btn sig-btn-secondary">Limpar</button>}
                    </form>

                    <p role="status" className="flex items-center gap-2 text-sm text-[var(--ink-500)]">{filtering ? <><Loader2 size={15} className="animate-spin" />Atualizando produtos…</> : <>{products.total} {products.total === 1 ? 'produto encontrado' : 'produtos encontrados'}</>}</p>
                    <div className="space-y-2">
                        {products.data.map((product) => (
                            <article key={product.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
                                <div className="min-w-0 flex-1 basis-64">
                                    <h2 className="break-words text-sm font-semibold text-[var(--ink-900)]"><span className="mr-2 font-mono font-bold text-[var(--primary)]">{product.code}</span>{product.name}</h2>
                                    <p className="mt-1 text-xs text-[var(--ink-500)]">{product.contract.code} · {categories[product.category]} · Unidade: {product.unit} · {frequencies[product.frequency]}</p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={`shrink-0 rounded-full px-3 py-1 text-xs font-semibold ${product.frequency === 'monthly' ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600'}`}>{frequencies[product.frequency]}</span>
                                    {product.can_edit && <button type="button" onClick={() => openProduct(product)} className="sig-btn sig-btn-secondary" aria-label={`Editar produto ${product.code}`}><Pencil size={15} />Editar</button>}
                                    {product.can_delete && <OsDnitDeleteButton url={route('tenant.os-dnit.products.destroy', [currentTenant.slug, product.id])} title="Excluir produto?" label={`Excluir produto ${product.code}`} message={`Deseja excluir o produto ${product.code} — ${product.name}? Produtos vinculados a OSs serão preservados.`} />}
                                </div>
                            </article>
                        ))}
                        {products.data.length === 0 && (
                            <div className="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                                <Package size={30} className="mx-auto text-slate-400" />
                                <h2 className="mt-4 font-semibold">{filters.q || filters.category ? 'Nenhum produto encontrado' : 'Nenhum produto cadastrado'}</h2>
                                <p className="mt-2 text-sm text-[var(--ink-500)]">{filters.q || filters.category ? 'Ajuste a busca ou selecione outra categoria.' : 'Cadastre os produtos para começar a organizar o catálogo.'}</p>
                            </div>
                        )}
                    </div>
                    {products.last_page > 1 && (
                        <nav aria-label="Paginação de produtos" className="flex flex-wrap justify-end gap-1">
                            {products.links.map((link, index) => {
                                const label = index === 0 ? 'Anterior' : index === products.links.length - 1 ? 'Próxima' : link.label;
                                return link.url && !filtering ? <Link key={index} href={link.url} preserveScroll aria-current={link.active ? 'page' : undefined} className={`rounded-lg border px-3 py-2 text-sm ${link.active ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white'}`}>{label}</Link>
                                    : <span key={index} aria-disabled="true" aria-current={link.active ? 'page' : undefined} className="rounded-lg border border-slate-100 px-3 py-2 text-sm text-slate-400">{label}</span>;
                            })}
                        </nav>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
