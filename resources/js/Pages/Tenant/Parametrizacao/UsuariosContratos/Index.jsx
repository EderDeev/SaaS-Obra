import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ClipboardList, Link2, Plus, SlidersHorizontal, Trash2, UserRoundCheck, X } from 'lucide-react';
import { useMemo, useState } from 'react';

const sideLabels = {
    manager: 'Gerenciadora',
    client: 'Cliente',
    contractor: 'Construtora',
};

const roleLabels = {
    manager: 'Gestor do contrato',
    team_member: 'Equipe operacional',
    client_approver: 'Cliente aprovador',
    client_viewer: 'Cliente visualizador',
    contractor_lead: 'Construtora lider',
    contractor_member: 'Construtora membro',
};

function contractLabel(contract) {
    return `${contract.code} - ${contract.obra?.nome || contract.name}`;
}

export default function ParametrizacaoUsuariosContratosIndex({ tenant, users, contracts, links, rolesBySide }) {
    const page = usePage();
    const defaultSide = 'manager';
    const [formOpen, setFormOpen] = useState(false);
    const form = useForm({
        user_id: users[0]?.id ?? '',
        contract_id: contracts[0]?.id ?? '',
        side: defaultSide,
        role: rolesBySide[defaultSide]?.[1] ?? rolesBySide[defaultSide]?.[0] ?? '',
    });

    const rolesForSide = useMemo(
        () => rolesBySide[form.data.side] || [],
        [rolesBySide, form.data.side],
    );

    const submit = (event) => {
        event.preventDefault();

        form.post(route('tenant.parametrizacao.usuarios-contratos.store', page.props.currentTenant.slug), {
            preserveScroll: true,
            onSuccess: () => setFormOpen(false),
        });
    };

    const removeLink = (link) => {
        router.delete(route('tenant.parametrizacao.usuarios-contratos.destroy', [page.props.currentTenant.slug, link.id]), {
            preserveScroll: true,
        });
    };

    const updateSide = (side) => {
        const roles = rolesBySide[side] || [];

        form.setData((data) => ({
            ...data,
            side,
            role: roles[side === 'manager' && roles[1] ? 1 : 0] || '',
        }));
    };

    return (
        <AuthenticatedLayout>
            <Head title="Parametrizacao - Usuarios x Contratos" />

            <section className={`sig-content grid gap-6 ${formOpen ? 'xl:grid-cols-[420px_minmax(0,1fr)]' : ''}`}>
                {formOpen && (
                <form className="sig-card p-5" onSubmit={submit}>
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <SlidersHorizontal size={14} />
                        <span className="eyebrow">Parametrizacao</span>
                    </div>
                    <h1 className="mt-2 text-xl font-semibold">Vincular usuario ao contrato</h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        Libere acesso somente aos contratos em que o usuario deve atuar.
                    </p>

                    {page.props.flash.success && (
                        <div className="mt-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}

                    <div className="mt-5 grid gap-3">
                        <Field label="Usuario" error={form.errors.user_id}>
                            <select
                                value={form.data.user_id}
                                onChange={(event) => form.setData('user_id', event.target.value)}
                                required
                            >
                                <option value="">Selecione o usuario</option>
                                {users.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name} - {user.email}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Contrato" error={form.errors.contract_id}>
                            <select
                                value={form.data.contract_id}
                                onChange={(event) => form.setData('contract_id', event.target.value)}
                                required
                            >
                                <option value="">Selecione o contrato</option>
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>
                                        {contractLabel(contract)}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Lado" error={form.errors.side}>
                                <select value={form.data.side} onChange={(event) => updateSide(event.target.value)} required>
                                    {Object.keys(rolesBySide).map((side) => (
                                        <option key={side} value={side}>
                                            {sideLabels[side] || side}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field label="Papel" error={form.errors.role}>
                                <select value={form.data.role} onChange={(event) => form.setData('role', event.target.value)} required>
                                    {rolesForSide.map((role) => (
                                        <option key={role} value={role}>
                                            {roleLabels[role] || role}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </div>
                    </div>

                    <div className="mt-5 flex flex-wrap gap-2">
                        <button className="sig-btn sig-btn-primary" disabled={form.processing || users.length === 0 || contracts.length === 0}>
                            <Plus size={15} />
                            Vincular acesso
                        </button>
                        <button type="button" className="sig-btn sig-btn-secondary" onClick={() => setFormOpen(false)}>
                            <X size={15} />
                            Fechar
                        </button>
                    </div>
                </form>
                )}

                <section className="param-list-card sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <UserRoundCheck size={14} />
                                <span className="eyebrow">Acessos por contrato</span>
                            </div>
                            <h2 className="mt-1 text-[15px] font-semibold">{links.length} vinculos ativos</h2>
                        </div>
                        <button type="button" className="sig-btn sig-btn-primary sig-btn-sm" onClick={() => setFormOpen(true)}>
                            <Plus size={13} />
                            Vincular acesso
                        </button>
                    </header>

                    {!formOpen && page.props.flash.success && (
                        <div className="border-b border-[var(--border)] bg-[var(--green-50)] px-5 py-3 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}

                    {links.length > 0 ? (
                        <>
                        <div className="param-desktop-table overflow-x-auto">
                        <table className="sig-table min-w-[860px]">
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Contrato</th>
                                    <th>Lado</th>
                                    <th>Papel</th>
                                    <th className="text-right">Acoes</th>
                                </tr>
                            </thead>
                            <tbody>
                                {links.map((link) => (
                                    <tr key={link.id}>
                                        <td>
                                            <div className="font-semibold">{link.user?.name}</div>
                                            <div className="text-xs text-[var(--ink-500)]">{link.user?.email}</div>
                                        </td>
                                        <td>
                                            <span className="inline-flex items-center gap-2 text-sm text-[var(--ink-700)]">
                                                <ClipboardList size={14} />
                                                <span>
                                                    <span className="mono text-xs">{link.contract?.code}</span>
                                                    <span className="block font-semibold">{link.contract?.name}</span>
                                                </span>
                                            </span>
                                        </td>
                                        <td>{sideLabels[link.side] || link.side}</td>
                                        <td>
                                            <span className="inline-flex items-center gap-2 text-sm text-[var(--ink-700)]">
                                                <Link2 size={14} />
                                                {roleLabels[link.role] || link.role}
                                            </span>
                                        </td>
                                        <td>
                                            <div className="flex justify-end">
                                                <ConfirmActionButton
                                                    title="Remover usuario do contrato"
                                                    message={`Deseja mesmo remover ${link.user?.name || 'este usuario'} do contrato ${link.contract?.code || ''}? O vinculo ficara salvo no historico.`}
                                                    confirmLabel="Remover acesso"
                                                    onConfirm={() => removeLink(link)}
                                                >
                                                    <Trash2 size={13} />
                                                    Remover
                                                </ConfirmActionButton>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        </div>

                        <div className="param-responsive-list divide-y divide-[var(--border)]">
                            {links.map((link) => (
                                <article key={link.id} className="p-5">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h3 className="text-sm font-semibold text-[var(--ink-900)]">{link.user?.name}</h3>
                                            <div className="mt-1 break-all text-xs text-[var(--ink-500)]">{link.user?.email}</div>
                                        </div>
                                        <span className="sig-pill sig-pill-blue">{sideLabels[link.side] || link.side}</span>
                                    </div>

                                    <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                        <CompactInfo label="Contrato" value={`${link.contract?.code || '-'} - ${link.contract?.name || 'Sem contrato'}`} />
                                        <CompactInfo label="Papel" value={roleLabels[link.role] || link.role} />
                                    </div>

                                    <div className="mt-4 flex flex-wrap gap-2 border-t border-[var(--border)] pt-4">
                                        <ConfirmActionButton
                                            title="Remover usuario do contrato"
                                            message={`Deseja mesmo remover ${link.user?.name || 'este usuario'} do contrato ${link.contract?.code || ''}? O vinculo ficara salvo no historico.`}
                                            confirmLabel="Remover acesso"
                                            onConfirm={() => removeLink(link)}
                                        >
                                            <Trash2 size={13} />
                                            Remover
                                        </ConfirmActionButton>
                                    </div>
                                </article>
                            ))}
                        </div>
                        </>
                    ) : (
                        <div className="p-12 text-center text-sm text-[var(--ink-500)]">
                            Nenhum usuario vinculado a contratos ainda.
                        </div>
                    )}
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function CompactInfo({ label, value }) {
    return (
        <div>
            <div className="eyebrow">{label}</div>
            <div className="mt-1 break-words text-[13px] font-semibold text-[var(--ink-800)]">{value}</div>
        </div>
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
