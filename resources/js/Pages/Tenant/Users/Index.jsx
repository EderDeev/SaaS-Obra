import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, UserX, Users, X } from 'lucide-react';
import { useState } from 'react';

function empresaLabel(empresa) {
    const tipo = empresa.tipo_empresa?.nome ? ` - ${empresa.tipo_empresa.nome}` : '';
    const contract = empresa.contract?.code ? ` - ${empresa.contract.code}` : '';

    return `${empresa.nome}${tipo}${contract}`;
}

export default function TenantUsersIndex({ tenant, memberships, empresas, roles, userPermissionCan }) {
    const page = usePage();
    const currentUser = page.props.auth.user;
    const defaultEmpresaId = empresas[0]?.id ?? '';
    const [editingMembership, setEditingMembership] = useState(null);
    const form = useForm({
        name: '',
        email: '',
        empresa_id: defaultEmpresaId,
        role: 'engineer',
    });

    const clearForm = () => {
        setEditingMembership(null);
        form.clearErrors();
        form.setData({ name: '', email: '', empresa_id: defaultEmpresaId, role: 'engineer' });
    };

    const editMembership = (membership) => {
        setEditingMembership(membership);
        form.clearErrors();
        form.setData({
            name: membership.user?.name ?? '',
            email: membership.user?.email ?? '',
            empresa_id: membership.empresa_id ?? '',
            role: membership.role ?? 'engineer',
        });
    };

    const submit = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: clearForm,
        };

        if (editingMembership) {
            form.patch(route('tenant.users.update', [page.props.currentTenant.slug, editingMembership.id]), options);
            return;
        }

        form.post(route('tenant.users.store', page.props.currentTenant.slug), options);
    };

    const deactivateMembership = (membership) => {
        router.patch(route('tenant.users.deactivate', [page.props.currentTenant.slug, membership.id]), {}, {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Equipes" />

            <section className="sig-content grid gap-6 lg:grid-cols-[360px_minmax(0,1fr)]">
                <form className="sig-card p-5" onSubmit={submit}>
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <Users size={14} />
                        <span className="eyebrow">Equipe interna</span>
                    </div>
                    <h1 className="mt-2 text-xl font-semibold">{editingMembership ? 'Editar usuario' : 'Adicionar usuario'}</h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">Vincula usuario global ao tenant {tenant.name}.</p>

                    {page.props.flash.success && (
                        <div className="mt-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}

                    <div className="mt-5 grid gap-3">
                        <Field label="Nome" error={form.errors.name}>
                            <input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required />
                        </Field>
                        <Field label="Email" error={form.errors.email}>
                            <input type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} required />
                        </Field>
                        <Field label="Empresa" error={form.errors.empresa_id}>
                            <select value={form.data.empresa_id} onChange={(event) => form.setData('empresa_id', event.target.value)} required>
                                <option value="">Selecione a empresa</option>
                                {empresas.map((empresa) => (
                                    <option key={empresa.id} value={empresa.id}>
                                        {empresaLabel(empresa)}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Papel" error={form.errors.role}>
                            <select value={form.data.role} onChange={(event) => form.setData('role', event.target.value)}>
                                {roles.map((role) => <option key={role} value={role}>{role}</option>)}
                            </select>
                        </Field>
                    </div>

                    <div className="mt-5 flex flex-wrap gap-2">
                        <button
                            className="sig-btn sig-btn-primary"
                            disabled={form.processing || empresas.length === 0 || (!editingMembership && !userPermissionCan?.create_user) || (editingMembership && !userPermissionCan?.edit_user)}
                        >
                            <Plus size={15} />
                            {editingMembership ? 'Salvar alteracoes' : 'Adicionar'}
                        </button>
                        {editingMembership && (
                            <button type="button" className="sig-btn sig-btn-secondary" onClick={clearForm}>
                                <X size={15} />
                                Cancelar
                            </button>
                        )}
                    </div>
                </form>

                <section className="sig-card overflow-hidden">
                    <header className="border-b border-[var(--border)] px-5 py-4">
                        <h2 className="text-[15px] font-semibold">Usuarios de {tenant.name}</h2>
                    </header>
                    <div className="overflow-x-auto">
                        <table className="sig-table min-w-[760px]">
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Empresa</th>
                                    <th>Papel</th>
                                    <th>Status</th>
                                    <th>Acoes</th>
                                </tr>
                            </thead>
                            <tbody>
                                {memberships.map((membership) => (
                                    <tr key={membership.id}>
                                        <td>
                                            <div className="font-semibold">{membership.user.name}</div>
                                            <div className="text-xs text-[var(--ink-500)]">{membership.user.email}</div>
                                        </td>
                                        <td>
                                            {membership.empresa ? (
                                                <>
                                                    <div className="font-semibold">{membership.empresa.nome}</div>
                                                    <div className="text-xs text-[var(--ink-500)]">
                                                        {membership.empresa.tipo_empresa?.nome}
                                                        {membership.empresa.contract?.code ? ` - ${membership.empresa.contract.code}` : ''}
                                                    </div>
                                                </>
                                            ) : (
                                                <span className="text-sm text-[var(--ink-400)]">Sem empresa</span>
                                            )}
                                        </td>
                                        <td>{membership.role}</td>
                                        <td>
                                            <span className={`sig-pill ${membership.status === 'active' ? 'sig-pill-green' : 'sig-pill-red'}`}>
                                                <span className="sig-pill-dot" />
                                                {membership.status}
                                            </span>
                                        </td>
                                        <td>
                                            <div className="flex flex-wrap justify-end gap-2">
                                                {userPermissionCan?.edit_user && (
                                                    <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" onClick={() => editMembership(membership)}>
                                                        <Pencil size={14} />
                                                        Editar
                                                    </button>
                                                )}
                                                {userPermissionCan?.deactivate_user && membership.status === 'active' && membership.user_id !== currentUser.id && (
                                                    <ConfirmActionButton
                                                        title="Desativar usuario"
                                                        message={`Deseja mesmo desativar o acesso de ${membership.user?.name ?? 'este usuario'}?`}
                                                        confirmLabel="Desativar usuario"
                                                        onConfirm={() => deactivateMembership(membership)}
                                                    >
                                                        <UserX size={14} />
                                                        Desativar
                                                    </ConfirmActionButton>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
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
