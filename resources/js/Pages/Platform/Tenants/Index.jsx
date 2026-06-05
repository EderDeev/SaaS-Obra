import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

function formatCnpj(value) {
    const digits = value.replace(/\D/g, '').slice(0, 14);

    return digits
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2')
        .replace(/(\d{4})(\d)/, '$1-$2');
}

const statusLabels = {
    active: 'Ativo',
    trial: 'Em teste',
    suspended: 'Suspenso',
};

const statusPills = {
    active: 'sig-pill-green',
    trial: 'sig-pill-blue',
    suspended: 'sig-pill-red',
};

export default function PlatformTenantsIndex({ tenants, plans, statuses }) {
    const page = usePage();
    const [formOpen, setFormOpen] = useState(false);
    const form = useForm({
        name: '',
        slug: '',
        cnpj: '',
        plan: 'starter',
        status: 'active',
        owner_name: '',
        owner_email: '',
    });

    const submit = (event) => {
        event.preventDefault();

        form.post(route('platform.tenants.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setFormOpen(false);
            },
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Tenants" />

            <section className="sig-content space-y-6">
                {formOpen && (
                    <form className="sig-card p-5" onSubmit={submit}>
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="eyebrow">Platform</div>
                                <h1 className="mt-2 text-xl font-semibold">Novo tenant</h1>
                                <p className="mt-1 text-sm text-[var(--ink-500)]">Cria tenant e owner inicial.</p>
                            </div>
                            <button type="button" className="sig-btn sig-btn-ghost sig-btn-sm" onClick={() => setFormOpen(false)}>
                                <X size={14} />
                                Cancelar
                            </button>
                        </div>

                        {page.props.flash.success && (
                            <div className="mt-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">{page.props.flash.success}</div>
                        )}

                        <div className="mt-5 grid gap-3 lg:grid-cols-2">
                            <Field label="Tenant" error={form.errors.name}><input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></Field>
                            <Field label="Slug" error={form.errors.slug}><input value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} required placeholder="tenant-demo" /></Field>
                            <Field label="CNPJ" error={form.errors.cnpj}>
                                <input
                                    value={form.data.cnpj}
                                    onChange={(e) => form.setData('cnpj', formatCnpj(e.target.value))}
                                    inputMode="numeric"
                                    maxLength={18}
                                    placeholder="00.000.000/0000-00"
                                />
                            </Field>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Plano">
                                    <select value={form.data.plan} onChange={(e) => form.setData('plan', e.target.value)}>
                                        {plans.map((plan) => <option key={plan} value={plan}>{plan}</option>)}
                                    </select>
                                </Field>
                                <Field label="Status">
                                    <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                        {statuses.map((status) => (
                                            <option key={status} value={status}>
                                                {statusLabels[status] || status}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            </div>
                            <Field label="Owner" error={form.errors.owner_name}><input value={form.data.owner_name} onChange={(e) => form.setData('owner_name', e.target.value)} required /></Field>
                            <Field label="Email do owner" error={form.errors.owner_email}><input type="email" value={form.data.owner_email} onChange={(e) => form.setData('owner_email', e.target.value)} required /></Field>
                        </div>

                        <button className="sig-btn sig-btn-primary mt-5" disabled={form.processing}><Plus size={15} /> Criar tenant</button>
                    </form>
                )}

                <section className="sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <div className="eyebrow">Platform</div>
                            <h2 className="mt-1 text-[15px] font-semibold">Tenants cadastrados</h2>
                        </div>
                        <button type="button" className="sig-btn sig-btn-primary sig-btn-sm" onClick={() => setFormOpen(true)}>
                            <Plus size={13} />
                            Novo tenant
                        </button>
                    </header>
                    {!formOpen && page.props.flash.success && (
                        <div className="border-b border-[var(--border)] bg-[var(--green-50)] px-5 py-3 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}
                    <table className="sig-table">
                        <thead>
                            <tr><th>Tenant</th><th>Plano</th><th>Status</th><th>Uso</th><th></th></tr>
                        </thead>
                        <tbody>
                            {tenants.map((tenant) => (
                                <tr key={tenant.id}>
                                    <td><div className="font-semibold">{tenant.name}</div><div className="mono text-xs text-[var(--ink-500)]">{tenant.slug}</div></td>
                                    <td>{tenant.plan}</td>
                                    <td>
                                        <span className={`sig-pill ${statusPills[tenant.status] || 'sig-pill-muted'}`}>
                                            <span className="sig-pill-dot" />
                                            {statusLabels[tenant.status] || tenant.status}
                                        </span>
                                    </td>
                                    <td>{tenant.users_count} usuários · {tenant.contracts_count} contratos</td>
                                    <td className="text-right"><Link href={route('tenant.dashboard', tenant.slug)} className="sig-btn sig-btn-secondary sig-btn-sm">Abrir</Link></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
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
