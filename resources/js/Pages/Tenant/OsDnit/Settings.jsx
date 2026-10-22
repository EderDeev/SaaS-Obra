import OsDnitDeleteButton from '@/Components/OsDnitDeleteButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { MapPinned, Pencil, Plus, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export default function Settings({ highways, filters, canCreate, contracts }) {
    const { currentTenant, flash = {} } = usePage().props;
    const createContracts = contracts.filter((contract) => contract.can_create);
    const [editing, setEditing] = useState(null);
    const nameInput = useRef(null);
    const form = useForm({ contract_id: createContracts.length === 1 ? String(createContracts[0].id) : '', name: '', active: true });
    const search = useForm({ q: filters.q || '', contract_id: filters.contract_id || '' });
    const indexUrl = route('tenant.os-dnit.settings.index', currentTenant.slug);

    useEffect(() => {
        if (!editing) return;
        nameInput.current?.focus();
        nameInput.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, [editing]);

    const reset = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
    };

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: reset };
        if (editing) {
            form.patch(route('tenant.os-dnit.settings.highways.update', [currentTenant.slug, editing.id]), options);
        } else {
            form.post(route('tenant.os-dnit.settings.highways.store', currentTenant.slug), options);
        }
    };

    const edit = (highway) => {
        form.clearErrors();
        setEditing(highway);
        form.setData({ contract_id: highway.contract_id, name: highway.name, active: highway.active });
    };

    return (
        <AuthenticatedLayout>
            <Head title="OS DNIT · Parametrização" />
            <div className="sig-content os-dnit-page space-y-6">
                <header>
                    <span className="eyebrow">OS DNIT</span>
                    <h1 className="mt-2 text-3xl font-bold">Parametrização</h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">Cadastros reutilizáveis para o preenchimento das ordens de serviço do DNIT.</p>
                </header>

                {contracts.length === 0 && <p role="status" className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Você não possui vínculo ativo com um contrato desta empresa.</p>}
                {flash.success && <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{flash.success}</div>}

                <div className="flex rounded-xl border border-[var(--border)] bg-white p-2 shadow-sm">
                    <h2 className="inline-flex items-center gap-2 rounded-lg bg-[var(--primary)] px-4 py-2.5 font-bold text-white"><MapPinned size={17} /> Rodovias</h2>
                </div>

                {(canCreate || editing) && (
                    <form onSubmit={submit} className="rounded-xl border border-[var(--border)] bg-white shadow-sm">
                        <header className="border-b border-[var(--border)] px-5 py-4">
                            <h2 className="text-lg font-bold">{editing ? 'Editar rodovia' : 'Cadastrar rodovia'}</h2>
                        </header>
                        <div className="space-y-5 p-5">
                            <div className="grid gap-2"><label htmlFor="highway-contract" className="text-sm font-semibold">Contrato</label><select id="highway-contract" required disabled={Boolean(editing)} value={form.data.contract_id} onChange={(event) => form.setData('contract_id', event.target.value)} className="w-full rounded-lg border-slate-300 text-sm"><option value="">Selecione o contrato</option>{(editing ? contracts : createContracts).map((contract) => <option key={contract.id} value={contract.id}>{contract.code} — {contract.name}</option>)}</select>{form.errors.contract_id && <p role="alert" className="text-sm text-red-700">{form.errors.contract_id}</p>}</div>
                            <div className="grid gap-2">
                                <label htmlFor="highway-name" className="text-sm font-semibold">Rodovia</label>
                                <input ref={nameInput} id="highway-name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required maxLength={255} placeholder="Ex.: BR-364/AC" aria-invalid={Boolean(form.errors.name)} aria-describedby={form.errors.name ? 'highway-name-error' : 'highway-name-hint'} className="w-full rounded-lg border-slate-300 text-sm" />
                                <p id="highway-name-hint" className="text-xs text-[var(--ink-500)]">Informe o nome da rodovia em texto livre.</p>
                                {form.errors.name && <p id="highway-name-error" role="alert" className="text-sm text-red-700">{form.errors.name}</p>}
                            </div>
                            {editing && <div>
                                <label className="inline-flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.active} onChange={(event) => form.setData('active', event.target.checked)} className="rounded border-slate-300 text-blue-600" /> Rodovia ativa</label>
                                {form.errors.active && <p role="alert" className="mt-1 text-sm text-red-700">{form.errors.active}</p>}
                            </div>}
                            <div className="flex justify-end gap-2">
                                {editing && <button type="button" onClick={reset} disabled={form.processing} className="sig-btn sig-btn-secondary">Cancelar</button>}
                                <button type="submit" disabled={form.processing} className="sig-btn sig-btn-primary">{editing ? <Pencil size={16} /> : <Plus size={16} />}{form.processing ? 'Salvando…' : editing ? 'Salvar alterações' : 'Cadastrar'}</button>
                            </div>
                        </div>
                    </form>
                )}

                <section className="overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-sm" aria-labelledby="highways-title">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <h2 id="highways-title" className="text-lg font-bold">Rodovias cadastradas <span className="text-sm font-normal text-[var(--ink-500)]">({highways.total})</span></h2>
                        <form className="flex flex-wrap gap-2" onSubmit={(event) => { event.preventDefault(); search.get(indexUrl, { preserveState: true, preserveScroll: true }); }}>
                            <select aria-label="Filtrar rodovias por contrato" value={search.data.contract_id} onChange={(event) => search.setData('contract_id', event.target.value)} className="max-w-full rounded-lg border-slate-300 text-sm"><option value="">Todos os meus contratos</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code}</option>)}</select>
                            <input aria-label="Buscar rodovia" value={search.data.q} onChange={(event) => search.setData('q', event.target.value)} maxLength={120} placeholder="Buscar rodovia" className="min-w-0 rounded-lg border-slate-300 text-sm" />
                            <button type="submit" disabled={search.processing} className="sig-btn sig-btn-secondary"><Search size={16} />Buscar</button>
                            {(filters.q || filters.contract_id) && <Link href={indexUrl} onClick={() => search.setData({ q: '', contract_id: '' })} className="sig-btn sig-btn-secondary">Limpar</Link>}
                        </form>
                    </header>
                    <div className="divide-y divide-[var(--border)]">
                        {highways.data.map((highway) => <div key={highway.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                            <div className="flex min-w-0 items-center gap-3"><MapPinned size={18} className="shrink-0 text-[var(--ink-500)]" /><div><p className="break-all text-sm font-semibold">{highway.name}</p><p className="mt-1 text-xs text-[var(--ink-500)]">{highway.contract.code} — {highway.contract.name}</p></div></div>
                            <div className="flex items-center gap-3">
                                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${highway.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{highway.active ? 'Ativa' : 'Inativa'}</span>
                                {highway.can_edit && <button type="button" onClick={() => edit(highway)} disabled={form.processing} className="sig-btn sig-btn-secondary sm:!min-h-8 !px-2.5" aria-label={`Editar rodovia ${highway.name}`}><Pencil size={15} />Editar</button>}
                                {highway.can_delete && <OsDnitDeleteButton url={route('tenant.os-dnit.settings.highways.destroy', [currentTenant.slug, highway.id])} title="Excluir rodovia?" label={`Excluir rodovia ${highway.name}`} message={`Deseja excluir a rodovia ${highway.name}? Rodovias vinculadas a OSs serão preservadas.`} />}
                            </div>
                        </div>)}
                        {highways.data.length === 0 && <p className="px-5 py-8 text-center text-sm text-[var(--ink-500)]">{filters.q ? 'Nenhuma rodovia encontrada para esta busca.' : 'Nenhuma rodovia cadastrada.'}</p>}
                    </div>
                    {highways.last_page > 1 && <nav aria-label="Paginação de rodovias" className="flex flex-wrap justify-end gap-1 border-t border-[var(--border)] p-4">
                        {highways.links.map((link, index) => {
                            const label = index === 0 ? 'Anterior' : index === highways.links.length - 1 ? 'Próxima' : link.label;
                            return link.url ? <Link key={index} href={link.url} aria-current={link.active ? 'page' : undefined} className={`rounded-lg border px-3 py-2 text-sm ${link.active ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white'}`}>{label}</Link> : <span key={index} aria-disabled="true" className="px-3 py-2 text-sm text-slate-400">{label}</span>;
                        })}
                    </nav>}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
