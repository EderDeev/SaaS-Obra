import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, KeyRound, Pencil, Plus, UserX, Users, X } from 'lucide-react';
import { useEffect, useState } from 'react';

function empresaLabel(empresa) {
    const tipo = empresa.tipo_empresa?.nome ? ` - ${empresa.tipo_empresa.nome}` : '';
    const contract = empresa.contract?.code ? ` - ${empresa.contract.code}` : '';

    return `${empresa.nome}${tipo}${contract}`;
}

function roleExistsInGroups(roleGroups, role) {
    return Object.values(roleGroups || {}).some((roles) => Object.prototype.hasOwnProperty.call(roles, role));
}

function roleLabel(roleLabels, role) {
    return roleLabels?.[role] || role || 'Participante';
}

export default function TenantUsersIndex({ tenant, memberships, empresas, roleGroups = {}, roleLabels = {}, defaultRole = 'engenheiro_planejamento', userPermissionCan }) {
    const page = usePage();
    const currentUser = page.props.auth.user;
    const defaultEmpresaId = empresas[0]?.id ?? '';
    const [formOpen, setFormOpen] = useState(false);
    const [editingMembership, setEditingMembership] = useState(null);
    const [resetPasswordModal, setResetPasswordModal] = useState(null);
    const [passwordCopied, setPasswordCopied] = useState(false);
    const form = useForm({
        name: '',
        email: '',
        empresa_id: defaultEmpresaId,
        role: defaultRole,
    });

    useEffect(() => {
        if (page.props.flash?.reset_password) {
            setResetPasswordModal(page.props.flash.reset_password);
            setPasswordCopied(false);
        }
    }, [page.props.flash?.reset_password]);

    const clearForm = () => {
        setEditingMembership(null);
        form.clearErrors();
        form.setData({ name: '', email: '', empresa_id: defaultEmpresaId, role: defaultRole });
        setFormOpen(false);
    };

    const openCreateForm = () => {
        setEditingMembership(null);
        form.clearErrors();
        form.setData({ name: '', email: '', empresa_id: defaultEmpresaId, role: defaultRole });
        setFormOpen(true);
    };

    const editMembership = (membership) => {
        setEditingMembership(membership);
        setFormOpen(true);
        form.clearErrors();
        form.setData({
            name: membership.user?.name ?? '',
            email: membership.user?.email ?? '',
            empresa_id: membership.empresa_id ?? '',
            role: membership.role ?? defaultRole,
        });
    };

    const copyTemporaryPassword = async () => {
        if (!resetPasswordModal?.temporary_password) {
            return;
        }

        await navigator.clipboard.writeText(resetPasswordModal.temporary_password);
        setPasswordCopied(true);
    };

    const needsCurrentRoleOption = form.data.role && !roleExistsInGroups(roleGroups, form.data.role);

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

    const resetPasswordForMembership = (membership) => {
        router.patch(route('tenant.users.reset-password', [page.props.currentTenant.slug, membership.id]), {}, {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Usuarios" />

            <section className="sig-content space-y-6">
                {formOpen && (
                    <form className="sig-card p-5" onSubmit={submit}>
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                    <Users size={14} />
                                    <span className="eyebrow">Equipe interna</span>
                                </div>
                                <h1 className="mt-2 text-xl font-semibold">{editingMembership ? 'Editar usuario' : 'Adicionar usuario'}</h1>
                                <p className="mt-1 text-sm text-[var(--ink-500)]">
                                    Vincula usuario ao tenant {tenant.name}. Novas contas recebem senha provisoria por email.
                                </p>
                            </div>
                            <button type="button" className="sig-btn sig-btn-ghost sig-btn-sm" onClick={clearForm}>
                                <X size={15} />
                                Cancelar
                            </button>
                        </div>

                        {page.props.flash.success && (
                            <div className="mt-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                                {page.props.flash.success}
                            </div>
                        )}

                        <div className="mt-5 grid gap-3 lg:grid-cols-2">
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
                                    {needsCurrentRoleOption && (
                                        <optgroup label="Papel atual">
                                            <option value={form.data.role}>{roleLabel(roleLabels, form.data.role)}</option>
                                        </optgroup>
                                    )}
                                    {Object.entries(roleGroups).map(([group, roles]) => (
                                        <optgroup key={group} label={group}>
                                            {Object.entries(roles).map(([role, label]) => (
                                                <option key={role} value={role}>{label}</option>
                                            ))}
                                        </optgroup>
                                    ))}
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
                        </div>
                    </form>
                )}

                <section className="sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <Users size={14} />
                                <span className="eyebrow">Equipe interna</span>
                            </div>
                            <h2 className="mt-1 text-[15px] font-semibold">Usuarios de {tenant.name}</h2>
                        </div>
                        {userPermissionCan?.create_user && (
                            <button type="button" className="sig-btn sig-btn-primary sig-btn-sm" onClick={openCreateForm}>
                                <Plus size={13} />
                                Criar usuario
                            </button>
                        )}
                    </header>
                    {!formOpen && page.props.flash.success && (
                        <div className="border-b border-[var(--border)] bg-[var(--green-50)] px-5 py-3 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}
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
                                        <td>{roleLabel(roleLabels, membership.role)}</td>
                                        <td>
                                            <span className={`sig-pill ${membership.status === 'active' ? 'sig-pill-green' : 'sig-pill-red'}`}>
                                                <span className="sig-pill-dot" />
                                                {membership.status === 'active' ? 'Ativo' : 'Inativo'}
                                            </span>
                                            {membership.user.must_change_password && (
                                                <span className="sig-pill sig-pill-blue ml-2">Senha provisoria</span>
                                            )}
                                        </td>
                                        <td>
                                            <div className="flex flex-wrap justify-end gap-2">
                                                {userPermissionCan?.edit_user && (
                                                    <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" onClick={() => editMembership(membership)}>
                                                        <Pencil size={14} />
                                                        Editar
                                                    </button>
                                                )}
                                                {userPermissionCan?.edit_user && membership.user_id !== currentUser.id && (
                                                    <ConfirmActionButton
                                                        title="Resetar senha"
                                                        message={`Deseja gerar uma nova senha provisoria para ${membership.user?.name ?? 'este usuario'}? Nenhum email sera enviado.`}
                                                        confirmLabel="Resetar senha"
                                                        className="sig-btn sig-btn-secondary sig-btn-sm"
                                                        onConfirm={() => resetPasswordForMembership(membership)}
                                                    >
                                                        <KeyRound size={14} />
                                                        Resetar senha
                                                    </ConfirmActionButton>
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

                {resetPasswordModal && (
                    <div
                        className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.48)] px-4 py-6"
                        role="presentation"
                        onMouseDown={() => setResetPasswordModal(null)}
                    >
                        <section
                            className="w-full max-w-lg overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="reset-password-title"
                            onMouseDown={(event) => event.stopPropagation()}
                        >
                            <header className="flex items-start gap-4 border-b border-[var(--border)] px-5 py-4">
                                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[var(--blue-50)] text-[var(--primary)]">
                                    <KeyRound size={21} />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <h2 id="reset-password-title" className="text-[16px] font-semibold text-[var(--ink-900)]">
                                        Senha provisoria gerada
                                    </h2>
                                    <p className="mt-1 text-[13px] leading-5 text-[var(--ink-500)]">
                                        Envie esta senha para {resetPasswordModal.user_name} por um canal seguro. O usuario precisara troca-la no proximo login.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    className="sig-btn sig-btn-ghost !min-h-9 !px-2"
                                    title="Fechar"
                                    onClick={() => setResetPasswordModal(null)}
                                >
                                    <X size={17} />
                                </button>
                            </header>

                            <div className="space-y-4 px-5 py-5">
                                <div>
                                    <span className="eyebrow mb-1 block">Usuario</span>
                                    <p className="text-sm font-semibold text-[var(--ink-900)]">{resetPasswordModal.user_name}</p>
                                    <p className="text-xs text-[var(--ink-500)]">{resetPasswordModal.user_email}</p>
                                </div>

                                <div>
                                    <span className="eyebrow mb-1 block">Senha provisoria</span>
                                    <div className="flex flex-wrap items-center gap-2 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-3 py-3">
                                        <code className="mono flex-1 break-all text-lg font-semibold text-[var(--ink-900)]">
                                            {resetPasswordModal.temporary_password}
                                        </code>
                                        <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" onClick={copyTemporaryPassword}>
                                            {passwordCopied ? <Check size={14} /> : <Copy size={14} />}
                                            {passwordCopied ? 'Copiado' : 'Copiar'}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <footer className="flex justify-end bg-[var(--surface-muted)] px-5 py-4">
                                <button type="button" className="sig-btn sig-btn-primary" onClick={() => setResetPasswordModal(null)}>
                                    Entendi
                                </button>
                            </footer>
                        </section>
                    </div>
                )}
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
