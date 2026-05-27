import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Activity, ChevronRight, ClipboardList, FileWarning, FolderOpen, KeyRound, Save, Search, ShieldCheck, SlidersHorizontal, UserCog, Users } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

function initials(name = 'U') {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function Avatar({ user, className = '' }) {
    if (user?.avatar_url) {
        return <img src={user.avatar_url} alt={user.name} className={`sig-avatar object-cover ${className}`} />;
    }

    return <span className={`sig-avatar ${className}`}>{initials(user?.name)}</span>;
}

function contractLabel(contract) {
    return `${contract.code} - ${contract.name}`;
}

const groupMeta = {
    activities: { icon: Activity, dataKey: 'activity_permissions' },
    rnc: { icon: FileWarning, dataKey: 'rnc_permissions' },
    projects: { icon: FolderOpen, dataKey: 'project_permissions' },
    users: { icon: UserCog, dataKey: 'user_permissions' },
    parametrizacao: { icon: SlidersHorizontal, dataKey: 'parametrizacao_permissions' },
};

export default function PermissionsIndex({
    tenant,
    users,
    contracts,
    contractIdsByUser,
    activityPermissionsByUserContract,
    projectPermissionsByUserContract,
    rncPermissionsByUserContract,
    userPermissionsByUser,
    parametrizacaoPermissionsByUser,
    permissionGroups,
}) {
    const page = usePage();
    const permissionGroupEntries = Object.entries(permissionGroups || {});
    const [openGroup, setOpenGroup] = useState(permissionGroupEntries[0]?.[0] ?? 'activities');
    const [query, setQuery] = useState('');
    const [selectedUserId, setSelectedUserId] = useState(users[0]?.id ?? '');
    const selectedUser = users.find((user) => String(user.id) === String(selectedUserId));
    const availableContracts = useMemo(() => {
        const ids = contractIdsByUser?.[selectedUserId] || [];

        return contracts.filter((contract) => ids.includes(contract.id));
    }, [contracts, contractIdsByUser, selectedUserId]);
    const [selectedContractId, setSelectedContractId] = useState(availableContracts[0]?.id ?? contracts[0]?.id ?? '');
    const selectedContract = contracts.find((contract) => String(contract.id) === String(selectedContractId));
    const lockedOwner = selectedUser?.role === 'tenant_owner';
    const form = useForm({
        user_id: selectedUserId,
        contract_id: selectedContractId,
        activity_permissions: [],
        project_permissions: [],
        rnc_permissions: [],
        user_permissions: [],
        parametrizacao_permissions: [],
    });

    const filteredUsers = useMemo(() => {
        const term = query.trim().toLowerCase();

        if (!term) {
            return users;
        }

        return users.filter((user) => `${user.name} ${user.email} ${user.role}`.toLowerCase().includes(term));
    }, [query, users]);

    useEffect(() => {
        const nextContractId = availableContracts[0]?.id ?? '';
        setSelectedContractId(nextContractId);
    }, [selectedUserId]);

    useEffect(() => {
        const key = `${selectedUserId}:${selectedContractId}`;

        form.setData({
            user_id: selectedUserId,
            contract_id: selectedContractId,
            activity_permissions: activityPermissionsByUserContract?.[key] || [],
            project_permissions: projectPermissionsByUserContract?.[key] || [],
            rnc_permissions: rncPermissionsByUserContract?.[key] || [],
            user_permissions: userPermissionsByUser?.[selectedUserId] || [],
            parametrizacao_permissions: parametrizacaoPermissionsByUser?.[selectedUserId] || [],
        });
        form.clearErrors();
    }, [selectedUserId, selectedContractId]);

    const togglePermission = (dataKey, permission) => {
        if (lockedOwner) {
            return;
        }

        const selected = form.data[dataKey] || [];

        form.setData(
            dataKey,
            selected.includes(permission)
                ? selected.filter((item) => item !== permission)
                : [...selected, permission],
        );
    };

    const submit = (event) => {
        event.preventDefault();

        form.patch(route('tenant.permissions.update', tenant.slug), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Permissoes" />

            <section className="sig-content grid gap-6 xl:grid-cols-[380px_minmax(0,1fr)]">
                <aside className="grid content-start gap-4">
                    <section className="sig-card p-5">
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <KeyRound size={14} />
                            <span className="eyebrow">Permissoes</span>
                        </div>
                        <h1 className="mt-2 text-xl font-semibold text-[var(--ink-900)]">Usuarios</h1>

                        <div className="mt-4">
                            <div className="sig-input flex items-center gap-2">
                                <Search size={15} className="text-[var(--ink-500)]" />
                                <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Pesquisar usuario" />
                            </div>
                        </div>

                        <div className="mt-3 max-h-[470px] overflow-y-auto rounded-lg border border-[var(--border)] bg-white p-1">
                            {filteredUsers.map((user) => {
                                const selected = String(user.id) === String(selectedUserId);

                                return (
                                    <button
                                        key={user.id}
                                        type="button"
                                        className={`flex w-full items-center gap-3 rounded-md px-3 py-2 text-left transition ${selected ? 'bg-[var(--primary-50)] text-[var(--primary)]' : 'hover:bg-[var(--surface-muted)]'}`}
                                        onClick={() => setSelectedUserId(user.id)}
                                    >
                                        <Avatar user={user} />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-[13px] font-semibold">{user.name}</span>
                                            <span className="block truncate text-[12px] text-[var(--ink-500)]">{user.email}</span>
                                            <span className="mono mt-0.5 block text-[11px] text-[var(--ink-500)]">{user.role}</span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </section>

                    <section className="sig-card p-5">
                        <div className="mb-3 flex items-center gap-2 text-[var(--ink-500)]">
                            <ClipboardList size={14} />
                            <span className="eyebrow">Contrato</span>
                        </div>
                        <label className="sig-input">
                            <select
                                value={selectedContractId}
                                onChange={(event) => setSelectedContractId(event.target.value)}
                                disabled={availableContracts.length === 0}
                            >
                                {availableContracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>
                                        {contractLabel(contract)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {availableContracts.length === 0 && (
                            <div className="mt-3 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-3 py-2 text-[12.5px] text-[var(--ink-500)]">
                                Usuario sem contrato vinculado.
                            </div>
                        )}
                    </section>
                </aside>

                <form className="grid content-start gap-4" onSubmit={submit}>
                    {page.props.flash.success && (
                        <div className="rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}
                    {(form.errors.user_id || form.errors.contract_id) && (
                        <div className="rounded-lg bg-[var(--red-50)] px-3 py-2 text-sm text-[var(--red)]">
                            {form.errors.user_id || form.errors.contract_id}
                        </div>
                    )}

                    <header className="sig-card flex flex-wrap items-center justify-between gap-4 p-5">
                        <div className="flex min-w-0 items-center gap-3">
                            <Avatar user={selectedUser} className="!h-11 !w-11" />
                            <div className="min-w-0">
                                <div className="truncate text-lg font-semibold text-[var(--ink-900)]">{selectedUser?.name}</div>
                                <div className="truncate text-sm text-[var(--ink-500)]">{selectedUser?.email}</div>
                                {selectedContract && (
                                    <div className="mono mt-1 text-[11.5px] text-[var(--ink-500)]">{contractLabel(selectedContract)}</div>
                                )}
                            </div>
                        </div>
                        <button className="sig-btn sig-btn-primary" disabled={form.processing || lockedOwner || !selectedContractId}>
                            <Save size={15} />
                            Salvar permissoes
                        </button>
                    </header>

                    {lockedOwner && (
                        <div className="rounded-lg border border-[var(--border)] bg-white px-4 py-3 text-sm font-semibold text-[var(--ink-700)]">
                            Owner possui acesso total automaticamente.
                        </div>
                    )}

                    <section className="sig-card overflow-hidden">
                        <header className="border-b border-[var(--border)] px-5 py-4">
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <ShieldCheck size={14} />
                                <span className="eyebrow">Módulos de permissões</span>
                            </div>
                            <h2 className="mt-1 text-[15px] font-semibold text-[var(--ink-900)]">
                                Selecione um módulo para editar
                            </h2>
                        </header>

                        <div className="divide-y divide-[var(--border)]">
                        {permissionGroupEntries.map(([groupKey, group]) => {
                            const meta = groupMeta[groupKey] || { icon: ShieldCheck, dataKey: `${groupKey}_permissions` };
                            const Icon = meta.icon;
                            const selected = form.data[meta.dataKey] || [];
                            const permissions = Object.entries(group.permissions || {});
                            const expanded = openGroup === groupKey;

                            return (
                                <div key={groupKey}>
                                    <button
                                        type="button"
                                        className={`flex w-full items-center gap-3 px-5 py-4 text-left transition ${expanded ? 'bg-[var(--primary-50)]' : 'hover:bg-[var(--surface-muted)]'}`}
                                        onClick={() => setOpenGroup((current) => current === groupKey ? '' : groupKey)}
                                    >
                                        <span className={`flex h-9 w-9 items-center justify-center rounded-lg ${expanded ? 'bg-white text-[var(--primary)]' : 'bg-[var(--surface-muted)] text-[var(--ink-600)]'}`}>
                                            <Icon size={16} />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-[14px] font-semibold text-[var(--ink-900)]">{group.label}</span>
                                            <span className="block text-[11.5px] text-[var(--ink-500)]">
                                                {selected.length} de {permissions.length} permissões selecionadas
                                            </span>
                                        </span>
                                        <span className="sig-pill sig-pill-blue">{selected.length}</span>
                                        <ChevronRight size={16} className={`shrink-0 transition-transform ${expanded ? 'rotate-90' : ''}`} />
                                    </button>

                                    {expanded && (
                                        <div className="grid gap-2 bg-white px-5 pb-5">
                                            {permissions.map(([permission, label]) => {
                                                const checked = selected.includes(permission);

                                                return (
                                                    <label
                                                        key={permission}
                                                        className={`flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 text-sm font-semibold transition sm:ml-12 ${checked ? 'border-[var(--primary)] bg-[var(--primary-50)] text-[var(--primary)]' : 'border-[var(--border)] bg-white hover:bg-[var(--surface-muted)]'} ${lockedOwner ? 'cursor-not-allowed opacity-75' : ''}`}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={checked}
                                                            disabled={lockedOwner}
                                                            onChange={() => togglePermission(meta.dataKey, permission)}
                                                        />
                                                        <span>{label}</span>
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                        </div>
                    </section>

                    <section className="sig-card p-4">
                        <div className="mb-2 flex items-center gap-2 text-[var(--ink-500)]">
                            <Users size={14} />
                            <span className="eyebrow">Resumo</span>
                        </div>
                        <div className="grid gap-2 text-sm text-[var(--ink-600)] md:grid-cols-5">
                            <Summary label="Atividades" value={form.data.activity_permissions.length} />
                            <Summary label="Projetos" value={form.data.project_permissions.length} />
                            <Summary label="RNC" value={form.data.rnc_permissions.length} />
                            <Summary label="Usuarios" value={form.data.user_permissions.length} />
                            <Summary label="Parametrizacao" value={form.data.parametrizacao_permissions.length} />
                        </div>
                    </section>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}

function Summary({ label, value }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-white px-3 py-2">
            <div className="eyebrow">{label}</div>
            <div className="mt-1 text-lg font-semibold text-[var(--ink-900)]">{value}</div>
        </div>
    );
}
