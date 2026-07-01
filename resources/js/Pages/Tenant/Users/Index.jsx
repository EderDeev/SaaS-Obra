import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Activity, Check, ChevronRight, Copy, FileWarning, FolderOpen, KeyRound, Pencil, Plus, ShieldCheck, SlidersHorizontal, UserCog, UserX, Users, X } from 'lucide-react';
import { useEffect, useState } from 'react';

const contractRoleLabels = {
    manager: 'Gerenciadora',
    team_member: 'Equipe gerenciadora',
    client_approver: 'Aprovador cliente',
    client_viewer: 'Visualizador cliente',
    contractor_lead: 'Responsável construtora',
    contractor_member: 'Equipe construtora',
};

const sideLabels = {
    manager: 'Gerenciadora',
    client: 'Cliente',
    contractor: 'Construtora',
};

const permissionGroupMeta = {
    activity_permissions: { icon: Activity },
    rnc_permissions: { icon: FileWarning },
    project_permissions: { icon: FolderOpen },
    user_permissions: { icon: UserCog },
    parametrizacao_permissions: { icon: SlidersHorizontal },
};

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

function normalizeText(value = '') {
    return String(value)
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
}

function sideFromEmpresa(empresa) {
    const text = normalizeText(`${empresa?.tipo_empresa?.nome || ''} ${empresa?.nome || ''}`);

    if (text.includes('construt') || text.includes('contratada')) {
        return 'contractor';
    }

    if (text.includes('cliente') || text.includes('contratante')) {
        return 'client';
    }

    return 'manager';
}

function emptyFormData(defaultEmpresaId, defaultRole) {
    return {
        name: '',
        email: '',
        empresa_id: defaultEmpresaId,
        role: defaultRole,
        user_permissions: [],
        parametrizacao_permissions: [],
        contract_accesses: [],
    };
}

export default function TenantUsersIndex({
    tenant,
    memberships,
    empresas,
    contracts = [],
    roleGroups = {},
    roleLabels = {},
    defaultRole = 'engenheiro_planejamento',
    userPermissionOptions = {},
    parametrizacaoPermissionOptions = {},
    contractPermissionGroups = {},
    contractRolesBySide = {},
    userPermissionCan,
}) {
    const page = usePage();
    const currentUser = page.props.auth.user;
    const defaultEmpresaId = empresas[0]?.id ?? '';
    const [formOpen, setFormOpen] = useState(false);
    const [editingMembership, setEditingMembership] = useState(null);
    const [resetPasswordModal, setResetPasswordModal] = useState(null);
    const [passwordCopied, setPasswordCopied] = useState(false);
    const [openGlobalPermissionGroup, setOpenGlobalPermissionGroup] = useState('activity_permissions');
    const [openContractPermissionGroups, setOpenContractPermissionGroups] = useState({});
    const form = useForm({
        name: '',
        email: '',
        empresa_id: defaultEmpresaId,
        role: defaultRole,
        user_permissions: [],
        parametrizacao_permissions: [],
        contract_accesses: [],
    });
    const selectedEmpresa = empresas.find((empresa) => Number(empresa.id) === Number(form.data.empresa_id));

    useEffect(() => {
        if (page.props.flash?.reset_password) {
            setResetPasswordModal(page.props.flash.reset_password);
            setPasswordCopied(false);
        }
    }, [page.props.flash?.reset_password]);

    const clearForm = () => {
        setEditingMembership(null);
        form.clearErrors();
        form.setData(emptyFormData(defaultEmpresaId, defaultRole));
        setFormOpen(false);
    };

    const openCreateForm = () => {
        setEditingMembership(null);
        form.clearErrors();
        form.setData(emptyFormData(defaultEmpresaId, defaultRole));
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
            user_permissions: membership.user_permissions ?? [],
            parametrizacao_permissions: membership.parametrizacao_permissions ?? [],
            contract_accesses: [],
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

    const isContractSelected = (contractId) => form.data.contract_accesses.some((access) => Number(access.contract_id) === Number(contractId));

    const defaultContractAccess = (contractId) => {
        const firstSide = sideFromEmpresa(selectedEmpresa);
        const firstRole = contractRolesBySide[firstSide]?.[0] || 'team_member';
        const currentTemplate = form.data.contract_accesses?.[0] || {};

        return {
            contract_id: contractId,
            side: firstSide,
            role: firstRole,
            activity_permissions: currentTemplate.activity_permissions || [],
            project_permissions: currentTemplate.project_permissions || [],
            rnc_permissions: currentTemplate.rnc_permissions || [],
        };
    };

    const applyEmpresaToContractAccesses = (empresaId) => {
        const empresa = empresas.find((item) => Number(item.id) === Number(empresaId));
        const side = sideFromEmpresa(empresa);
        const role = contractRolesBySide[side]?.[0] || 'team_member';

        form.setData({
            ...form.data,
            empresa_id: empresaId,
            contract_accesses: (form.data.contract_accesses || []).map((access) => ({
                ...access,
                side,
                role,
            })),
        });
    };

    const setContractAccesses = (callback) => {
        form.setData('contract_accesses', callback(form.data.contract_accesses || []));
    };

    const toggleContract = (contractId) => {
        setContractAccesses((accesses) => (
            accesses.some((access) => Number(access.contract_id) === Number(contractId))
                ? accesses.filter((access) => Number(access.contract_id) !== Number(contractId))
                : [...accesses, defaultContractAccess(contractId)]
        ));
    };

    const updateContractAccess = (contractId, field, value) => {
        setContractAccesses((accesses) => accesses.map((access) => {
            if (Number(access.contract_id) !== Number(contractId)) {
                return access;
            }

            if (field === 'side') {
                const roles = contractRolesBySide[value] || [];

                return { ...access, side: value, role: roles[0] || '' };
            }

            return { ...access, [field]: value };
        }));
    };

    const togglePermission = (field, permission) => {
        const current = form.data[field] || [];

        form.setData(
            field,
            current.includes(permission)
                ? current.filter((item) => item !== permission)
                : [...current, permission],
        );
    };

    const toggleContractPermission = (contractId, field, permission) => {
        setContractAccesses((accesses) => accesses.map((access) => {
            if (Number(access.contract_id) !== Number(contractId)) {
                return access;
            }

            const current = access[field] || [];

            return {
                ...access,
                [field]: current.includes(permission)
                    ? current.filter((item) => item !== permission)
                    : [...current, permission],
            };
        }));
    };

    const toggleContractPermissionForSelectedContracts = (field, permission) => {
        setContractAccesses((accesses) => accesses.map((access) => {
            const current = access[field] || [];

            return {
                ...access,
                [field]: current.includes(permission)
                    ? current.filter((item) => item !== permission)
                    : [...current, permission],
            };
        }));
    };

    const toggleUnifiedPermission = (field, permission) => {
        if (field === 'user_permissions' || field === 'parametrizacao_permissions') {
            togglePermission(field, permission);
            return;
        }

        if ((form.data.contract_accesses || []).length === 0) {
            return;
        }

        toggleContractPermissionForSelectedContracts(field, permission);
    };

    const setOpenContractPermissionGroup = (contractId, field) => {
        setOpenContractPermissionGroups((current) => ({
            ...current,
            [contractId]: current[contractId] === field ? '' : field,
        }));
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

    const resetPasswordForMembership = (membership) => {
        router.patch(route('tenant.users.reset-password', [page.props.currentTenant.slug, membership.id]), {}, {
            preserveScroll: true,
        });
    };

    const firstContractAccess = form.data.contract_accesses?.[0] || {};
    const unifiedPermissionGroups = {
        activity_permissions: contractPermissionGroups.activity_permissions || { label: 'Atividades', permissions: {} },
        rnc_permissions: contractPermissionGroups.rnc_permissions || { label: 'RNC', permissions: {} },
        project_permissions: contractPermissionGroups.project_permissions || { label: 'Projetos', permissions: {} },
        user_permissions: { label: 'Usuários', permissions: userPermissionOptions },
        parametrizacao_permissions: { label: 'Parametrização', permissions: parametrizacaoPermissionOptions },
    };
    const unifiedSelectedPermissions = {
        activity_permissions: firstContractAccess.activity_permissions || [],
        rnc_permissions: firstContractAccess.rnc_permissions || [],
        project_permissions: firstContractAccess.project_permissions || [],
        user_permissions: form.data.user_permissions || [],
        parametrizacao_permissions: form.data.parametrizacao_permissions || [],
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
                                <select value={form.data.empresa_id} onChange={(event) => applyEmpresaToContractAccesses(event.target.value)} required>
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

                        {false && (
                        <PermissionModuleList
                            className="mt-5"
                            eyebrow="Permissões gerais"
                            title="Módulos de permissões do usuário"
                            groups={{
                                user_permissions: {
                                    label: 'Usuários',
                                    permissions: userPermissionOptions,
                                    description: 'Permissões gerais para administrar usuários do tenant.',
                                },
                                parametrizacao_permissions: {
                                    label: 'Parametrização',
                                    permissions: parametrizacaoPermissionOptions,
                                    description: 'Permissões gerais para acessar cadastros e parametrizações.',
                                },
                            }}
                            selectedByGroup={{
                                user_permissions: form.data.user_permissions,
                                parametrizacao_permissions: form.data.parametrizacao_permissions,
                            }}
                            openGroup={openGlobalPermissionGroup}
                            onOpenGroup={(field) => setOpenGlobalPermissionGroup((current) => current === field ? '' : field)}
                            onToggle={(field, permission) => togglePermission(field, permission)}
                            errors={form.errors}
                        />
                        )}
                        {false && (
                        <div className="mt-5 grid gap-4 lg:grid-cols-2">
                            <PermissionPanel
                                title="Permissões de usuários"
                                description="Permissões gerais para administrar usuários do tenant."
                                permissions={userPermissionOptions}
                                selected={form.data.user_permissions}
                                onToggle={(permission) => togglePermission('user_permissions', permission)}
                                error={form.errors.user_permissions}
                            />
                            <PermissionPanel
                                title="Permissões de parametrização"
                                description="Permissões gerais para acessar cadastros e parametrizações."
                                permissions={parametrizacaoPermissionOptions}
                                selected={form.data.parametrizacao_permissions}
                                onToggle={(permission) => togglePermission('parametrizacao_permissions', permission)}
                                error={form.errors.parametrizacao_permissions}
                            />
                        </div>
                        )}

                        {!editingMembership && (
                            <section className="mt-5 rounded-xl border border-[var(--border)] bg-[var(--surface-muted)] p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <span className="eyebrow">Acessos por contrato</span>
                                        <h2 className="mt-1 text-[15px] font-semibold text-[var(--ink-900)]">Contratos vinculados</h2>
                                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                                            Selecione os contratos que o usuário poderá acessar. O lado da empresa será definido pela empresa escolhida acima.
                                        </p>
                                    </div>
                                    <span className="sig-pill sig-pill-blue">{form.data.contract_accesses.length} contrato(s)</span>
                                </div>

                                <div className="mt-4 grid gap-3">
                                    {contracts.length === 0 && (
                                        <div className="rounded-lg border border-dashed border-[var(--border)] bg-white p-4 text-sm text-[var(--ink-500)]">
                                            Nenhum contrato cadastrado para este tenant.
                                        </div>
                                    )}
                                    {contracts.map((contract) => {
                                        const selected = isContractSelected(contract.id);
                                        const access = form.data.contract_accesses.find((item) => Number(item.contract_id) === Number(contract.id));

                                        return (
                                            <article key={contract.id} className="rounded-xl border border-[var(--border)] bg-white p-4">
                                                <label className="flex cursor-pointer items-start gap-3">
                                                    <input
                                                        type="checkbox"
                                                        className="mt-1 h-4 w-4"
                                                        checked={selected}
                                                        onChange={() => toggleContract(contract.id)}
                                                    />
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block font-semibold text-[var(--ink-900)]">{contract.code}</span>
                                                        <span className="block text-xs text-[var(--ink-500)]">{contract.name || 'Contrato sem obra vinculada'}</span>
                                                    </span>
                                                </label>

                                                {false && selected && access && (
                                                    <div className="mt-4 space-y-4 border-t border-[var(--border)] pt-4">
                                                        <div className="grid gap-3 md:grid-cols-2">
                                                            <Field label="Lado / empresa">
                                                                <select value={access.side} onChange={(event) => updateContractAccess(contract.id, 'side', event.target.value)}>
                                                                    {Object.entries(contractRolesBySide).map(([side]) => (
                                                                        <option key={side} value={side}>{sideLabels[side] || side}</option>
                                                                    ))}
                                                                </select>
                                                            </Field>
                                                            <Field label="Papel no contrato">
                                                                <select value={access.role} onChange={(event) => updateContractAccess(contract.id, 'role', event.target.value)}>
                                                                    {(contractRolesBySide[access.side] || []).map((role) => (
                                                                        <option key={role} value={role}>{contractRoleLabels[role] || role}</option>
                                                                    ))}
                                                                </select>
                                                            </Field>
                                                        </div>

                                                        {false && (
                                                        <PermissionModuleList
                                                            compact
                                                            eyebrow="Módulos de permissões"
                                                            title="Permissões neste contrato"
                                                            groups={contractPermissionGroups}
                                                            selectedByGroup={access}
                                                            openGroup={openContractPermissionGroups[contract.id] || Object.keys(contractPermissionGroups)[0] || ''}
                                                            onOpenGroup={(field) => setOpenContractPermissionGroup(contract.id, field)}
                                                            onToggle={(field, permission) => toggleContractPermission(contract.id, field, permission)}
                                                            errors={form.errors}
                                                            errorPrefix={`contract_accesses.${form.data.contract_accesses.findIndex((item) => Number(item.contract_id) === Number(contract.id))}`}
                                                        />
                                                        )}
                                                        {false && (
                                                        <div className="grid gap-3 lg:grid-cols-3">
                                                            {Object.entries(contractPermissionGroups).map(([field, group]) => (
                                                                <PermissionPanel
                                                                    key={field}
                                                                    compact
                                                                    title={group.label}
                                                                    permissions={group.permissions}
                                                                    selected={access[field] || []}
                                                                    onToggle={(permission) => toggleContractPermission(contract.id, field, permission)}
                                                                    error={form.errors[`contract_accesses.${contract.id}.${field}`]}
                                                                />
                                                            ))}
                                                        </div>
                                                        )}
                                                    </div>
                                                )}
                                            </article>
                                        );
                                    })}
                                </div>
                            </section>
                        )}

                        {!editingMembership && (
                            <div className="mt-5">
                                <PermissionModuleList
                                    eyebrow="Módulos de permissões"
                                    title="Selecione um módulo para editar"
                                    groups={unifiedPermissionGroups}
                                    selectedByGroup={unifiedSelectedPermissions}
                                    openGroup={openGlobalPermissionGroup}
                                    onOpenGroup={(field) => setOpenGlobalPermissionGroup((current) => current === field ? '' : field)}
                                    onToggle={toggleUnifiedPermission}
                                    errors={form.errors}
                                />
                                {form.data.contract_accesses.length === 0 && (
                                    <p className="mt-2 text-xs text-[var(--ink-500)]">
                                        Selecione ao menos um contrato acima para aplicar permissões de Atividades, RNC e Projetos.
                                    </p>
                                )}
                            </div>
                        )}

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

function PermissionModuleList({
    eyebrow = 'Módulos de permissões',
    title = 'Selecione um módulo para editar',
    groups = {},
    selectedByGroup = {},
    openGroup,
    onOpenGroup,
    onToggle,
    errors = {},
    errorPrefix = null,
    compact = false,
    className = '',
}) {
    const entries = Object.entries(groups || {});

    return (
        <section className={`rounded-xl border border-[var(--border)] bg-white ${className}`}>
            <header className={`border-b border-[var(--border)] ${compact ? 'px-4 py-3' : 'px-5 py-4'}`}>
                <div className="flex items-center gap-2 text-[var(--ink-500)]">
                    <ShieldCheck size={14} />
                    <span className="eyebrow">{eyebrow}</span>
                </div>
                <h2 className="mt-1 text-[15px] font-semibold text-[var(--ink-900)]">{title}</h2>
            </header>

            <div className="divide-y divide-[var(--border)]">
                {entries.length === 0 && (
                    <div className="px-5 py-4 text-sm text-[var(--ink-400)]">Nenhuma permissão disponível.</div>
                )}

                {entries.map(([field, group]) => {
                    const meta = permissionGroupMeta[field] || { icon: ShieldCheck };
                    const Icon = meta.icon;
                    const selected = selectedByGroup[field] || [];
                    const permissions = Object.entries(group.permissions || {});
                    const expanded = openGroup === field;
                    const error = errorPrefix ? errors[`${errorPrefix}.${field}`] : errors[field];

                    return (
                        <div key={field}>
                            <button
                                type="button"
                                className={`flex w-full items-center gap-3 text-left transition ${compact ? 'px-4 py-3' : 'px-5 py-4'} ${expanded ? 'bg-[var(--primary-50)]' : 'hover:bg-[var(--surface-muted)]'}`}
                                onClick={() => onOpenGroup(field)}
                            >
                                <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${expanded ? 'bg-white text-[var(--primary)]' : 'bg-[var(--surface-muted)] text-[var(--ink-600)]'}`}>
                                    <Icon size={16} />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block text-[14px] font-semibold text-[var(--ink-900)]">{group.label}</span>
                                    <span className="block text-[11.5px] text-[var(--ink-500)]">
                                        {selected.length} de {permissions.length} permissões selecionadas
                                    </span>
                                    {group.description && (
                                        <span className="mt-0.5 block text-[11.5px] text-[var(--ink-500)]">{group.description}</span>
                                    )}
                                </span>
                                <span className="sig-pill sig-pill-blue">{selected.length}</span>
                                <ChevronRight size={16} className={`shrink-0 transition-transform ${expanded ? 'rotate-90' : ''}`} />
                            </button>

                            {expanded && (
                                <div className={`grid gap-2 bg-white ${compact ? 'px-4 pb-4' : 'px-5 pb-5'}`}>
                                    {permissions.length === 0 && (
                                        <span className="text-sm text-[var(--ink-400)]">Nenhuma permissão disponível.</span>
                                    )}
                                    {permissions.map(([permission, label]) => {
                                        const checked = selected.includes(permission);

                                        return (
                                            <label
                                                key={permission}
                                                className={`flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 text-sm font-semibold transition ${compact ? '' : 'sm:ml-12'} ${checked ? 'border-[var(--primary)] bg-[var(--primary-50)] text-[var(--primary)]' : 'border-[var(--border)] bg-white hover:bg-[var(--surface-muted)]'}`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={checked}
                                                    onChange={() => onToggle(field, permission)}
                                                />
                                                <span>{label}</span>
                                            </label>
                                        );
                                    })}
                                    {error && <span className="text-xs text-[var(--red)]">{error}</span>}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </section>
    );
}

function PermissionPanel({ title, description, permissions = {}, selected = [], onToggle, error, compact = false }) {
    const entries = Object.entries(permissions || {});

    return (
        <section className={`rounded-xl border border-[var(--border)] bg-white ${compact ? 'p-3' : 'p-4'}`}>
            <div>
                <span className="eyebrow">{title}</span>
                {description && <p className="mt-1 text-xs leading-5 text-[var(--ink-500)]">{description}</p>}
            </div>
            <div className={`mt-3 grid gap-2 ${compact ? '' : 'sm:grid-cols-2'}`}>
                {entries.length === 0 && (
                    <span className="text-sm text-[var(--ink-400)]">Nenhuma permissão disponível.</span>
                )}
                {entries.map(([permission, label]) => (
                    <label key={permission} className="flex cursor-pointer items-start gap-2 rounded-lg border border-[var(--border)] px-3 py-2 text-sm hover:bg-[var(--surface-muted)]">
                        <input
                            type="checkbox"
                            className="mt-1 h-4 w-4 shrink-0"
                            checked={selected.includes(permission)}
                            onChange={() => onToggle(permission)}
                        />
                        <span className="leading-5">{label}</span>
                    </label>
                ))}
            </div>
            {error && <span className="mt-2 block text-xs text-[var(--red)]">{error}</span>}
        </section>
    );
}
