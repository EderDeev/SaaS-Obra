import SigLogo from '@/Components/SigLogo';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Building2, Check, Search } from 'lucide-react';
import { useState } from 'react';

export default function SelectTenant({ tenants, selectedTenantId }) {
    const { auth } = usePage().props;
    const [query, setQuery] = useState('');
    const form = useForm({ tenant_id: '' });
    const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const filtered = tenants.filter((tenant) => normalize(`${tenant.name} ${tenant.slug}`).includes(normalize(query.trim())));

    const select = (id) => {
        if (form.processing) return;
        form.transform(() => ({ tenant_id: id }));
        form.post(route('tenant-selection.store'));
    };

    return (
        <main className="min-h-screen bg-[var(--bg)] px-4 py-6 sm:py-12">
            <Head title="Escolher ambiente" />
            <div className="mx-auto w-full max-w-3xl">
                <header className="flex items-center justify-between gap-4">
                    <SigLogo size={24} />
                    <Link href={route('logout')} method="post" as="button" className="sig-btn sig-btn-ghost">Sair</Link>
                </header>
                <section className="sig-card mt-8 p-5 sm:mt-12 sm:p-8">
                    <p className="text-sm text-[var(--ink-500)]">Olá, {auth.user.name}.</p>
                    <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)] sm:text-3xl">Escolha seu ambiente</h1>
                    <p className="mt-3 text-sm leading-6 text-[var(--ink-600)]">Em qual empresa você quer trabalhar agora? Você pode trocar de ambiente pelo menu do usuário.</p>
                    <label className="sig-input mt-6">
                        <Search size={18} className="shrink-0 text-[var(--ink-500)]" />
                        <input type="search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar empresa..." aria-label="Buscar empresa" />
                    </label>
                    {form.errors.tenant_id && <p role="alert" className="mt-3 text-sm text-[var(--red)]">{form.errors.tenant_id}</p>}
                    <div className="mt-5 grid gap-3 sm:grid-cols-2" aria-busy={form.processing}>
                        {filtered.map((tenant) => {
                            const active = String(tenant.id) === String(selectedTenantId);
                            return (
                                <button key={tenant.id} type="button" onClick={() => select(tenant.id)} disabled={form.processing}
                                    className="flex min-h-24 min-w-0 items-center gap-3 rounded-xl border border-[var(--border)] bg-white p-4 text-left transition hover:border-[var(--primary)] hover:bg-[var(--primary-50)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--primary)] disabled:cursor-wait disabled:opacity-60">
                                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]"><Building2 size={22} /></span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block break-words text-sm font-semibold text-[var(--ink-900)]">{tenant.name}</span>
                                        {active && <span className="mt-1 flex items-center gap-1 text-xs text-[var(--primary)]"><Check size={13} />Ambiente atual</span>}
                                    </span>
                                    <ArrowRight size={18} className="shrink-0 text-[var(--ink-500)]" />
                                </button>
                            );
                        })}
                    </div>
                    {filtered.length === 0 && <p role="status" className="py-8 text-center text-sm text-[var(--ink-500)]">Nenhuma empresa encontrada.</p>}
                    {form.processing && <p role="status" className="mt-4 text-sm text-[var(--ink-500)]">Abrindo ambiente...</p>}
                </section>
            </div>
        </main>
    );
}
