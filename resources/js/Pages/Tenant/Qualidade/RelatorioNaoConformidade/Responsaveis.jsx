import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { BellRing, ClipboardList, Pencil, Plus, Search, ShieldCheck, Trash2, UserRoundCheck } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

function contractLabel(contract) {
    return `${contract.code} - ${contract.name}`;
}

function initials(name = 'U') {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function Avatar({ user }) {
    if (user?.avatar_url) {
        return <img src={user.avatar_url} alt={user.name} className="sig-avatar object-cover" />;
    }

    return <span className="sig-avatar">{initials(user?.name)}</span>;
}

function ResponsibleActions({ tenant, responsavel, onEdit }) {
    return (
        <>
            <button
                type="button"
                className="sig-btn sig-btn-secondary sig-btn-sm"
                onClick={() => onEdit(responsavel)}
            >
                <Pencil size={13} />
                Editar
            </button>
            <ConfirmActionButton
                title="Remover alerta"
                message={`Deseja mesmo remover ${responsavel.user?.name || 'este usuario'} dos alertas de RNC?`}
                confirmLabel="Remover alerta"
                className="sig-btn sig-btn-secondary sig-btn-sm text-[var(--red)]"
                onConfirm={() => router.delete(route('tenant.qualidade.rnc.responsaveis.destroy', [tenant.slug, responsavel.id]), { preserveScroll: true })}
            >
                <Trash2 size={13} />
                Remover
            </ConfirmActionButton>
        </>
    );
}

function MobileMeta({ label, children }) {
    return (
        <div>
            <div className="eyebrow mb-1">{label}</div>
            {children}
        </div>
    );
}

export default function RncResponsaveisIndex({ tenant, contracts, usersByContract, responsaveis }) {
    const page = usePage();
    const firstContractId = contracts[0]?.id ?? '';
    const form = useForm({
        contract_id: firstContractId,
        user_id: usersByContract[firstContractId]?.[0]?.id ?? '',
    });
    const [query, setQuery] = useState('');

    const users = usersByContract[form.data.contract_id] || [];
    const filteredUsers = useMemo(() => {
        const term = query.trim().toLowerCase();

        if (!term) {
            return users;
        }

        return users.filter((user) => `${user.name} ${user.email}`.toLowerCase().includes(term));
    }, [query, users]);

    useEffect(() => {
        if (users.length === 0) {
            form.setData('user_id', '');
            return;
        }

        if (!users.some((user) => String(user.id) === String(form.data.user_id))) {
            form.setData('user_id', users[0].id);
        }
    }, [form.data.contract_id]);

    const submit = (event) => {
        event.preventDefault();

        form.post(route('tenant.qualidade.rnc.responsaveis.store', tenant.slug), {
            preserveScroll: true,
        });
    };

    const loadResponsavel = (responsavel) => {
        form.setData({
            contract_id: responsavel.contract?.id || '',
            user_id: responsavel.user?.id || '',
        });
        setQuery(`${responsavel.user?.name || ''} ${responsavel.user?.email || ''}`.trim());
    };

    return (
        <AuthenticatedLayout>
            <Head title="RNC - Alertas" />

            <section className="sig-content grid gap-6 xl:grid-cols-[430px_minmax(0,1fr)]">
                <form className="sig-card p-5" onSubmit={submit}>
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <ShieldCheck size={14} />
                        <span className="eyebrow">Relatorio Nao Conformidade</span>
                    </div>
                    <h1 className="mt-2 text-xl font-semibold text-[var(--ink-900)]">Alertas</h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        Cadastre quem recebe notificacoes e alertas por email das RNCs de cada contrato. Permissoes sao gerenciadas no menu Permissoes.
                    </p>

                    {page.props.flash.success && (
                        <div className="mt-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}

                    <div className="mt-5 grid gap-4">
                        <Field label="Contrato" error={form.errors.contract_id}>
                            <select
                                value={form.data.contract_id}
                                onChange={(event) => {
                                    form.setData('contract_id', event.target.value);
                                    setQuery('');
                                }}
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

                        <div>
                            <span className="eyebrow mb-1 block">Usuario para alerta</span>
                            <div className="sig-input flex items-center gap-2">
                                <Search size={15} className="text-[var(--ink-500)]" />
                                <input
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    placeholder="Pesquisar por nome ou email"
                                />
                            </div>
                            {form.errors.user_id && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.user_id}</span>}

                            <div className="mt-2 max-h-[290px] overflow-y-auto rounded-lg border border-[var(--border)] bg-white p-1">
                                {filteredUsers.length > 0 ? filteredUsers.map((user) => {
                                    const selected = String(form.data.user_id) === String(user.id);

                                    return (
                                        <button
                                            key={user.id}
                                            type="button"
                                            className={`flex w-full items-center gap-3 rounded-md px-3 py-2 text-left transition ${selected ? 'bg-[var(--primary-50)] text-[var(--primary)]' : 'hover:bg-[var(--surface-muted)]'}`}
                                            onClick={() => form.setData('user_id', user.id)}
                                        >
                                            <Avatar user={user} />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[13px] font-semibold">{user.name}</span>
                                                <span className="block truncate text-[12px] text-[var(--ink-500)]">{user.email}</span>
                                            </span>
                                            {selected && <UserRoundCheck size={16} />}
                                        </button>
                                    );
                                }) : (
                                    <div className="px-3 py-6 text-center text-[12.5px] text-[var(--ink-500)]">
                                        Nenhum usuario disponivel para este contrato.
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    <button className="sig-btn sig-btn-primary mt-5" disabled={form.processing || !form.data.contract_id || !form.data.user_id}>
                        <Plus size={15} />
                        Salvar alerta
                    </button>
                </form>

                <section className="sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <BellRing size={14} />
                                <span className="eyebrow">Alertas RNC</span>
                            </div>
                            <h2 className="mt-1 text-[15px] font-semibold text-[var(--ink-900)]">
                                {responsaveis.length} usuario(s) em alerta
                            </h2>
                        </div>
                    </header>

                    {responsaveis.length > 0 ? (
                        <>
                            <div className="grid gap-3 p-4 lg:hidden">
                                {responsaveis.map((responsavel) => (
                                    <article key={responsavel.id} className="rounded-lg border border-[var(--border)] bg-white p-3">
                                        <div className="flex items-start gap-3">
                                            <Avatar user={responsavel.user} />
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate font-semibold text-[var(--ink-900)]">{responsavel.user?.name}</div>
                                                <div className="truncate text-xs text-[var(--ink-500)]">{responsavel.user?.email}</div>
                                            </div>
                                        </div>

                                        <div className="mt-4 grid gap-3">
                                            <MobileMeta label="Contrato">
                                                <span className="inline-flex min-w-0 items-start gap-2 text-sm text-[var(--ink-700)]">
                                                    <ClipboardList size={14} className="mt-0.5 shrink-0" />
                                                    <span className="min-w-0">
                                                        <span className="mono block text-xs">{responsavel.contract?.code}</span>
                                                        <span className="block font-semibold">{responsavel.contract?.name}</span>
                                                    </span>
                                                </span>
                                            </MobileMeta>

                                            <MobileMeta label="Cadastrado em">
                                                <span className="text-sm font-semibold text-[var(--ink-800)]">{responsavel.created_at}</span>
                                            </MobileMeta>
                                        </div>

                                        <div className="mt-4 flex flex-wrap gap-2">
                                            <ResponsibleActions tenant={tenant} responsavel={responsavel} onEdit={loadResponsavel} />
                                        </div>
                                    </article>
                                ))}
                            </div>

                            <div className="hidden overflow-x-auto lg:block">
                                <table className="sig-table min-w-[980px]">
                                    <thead>
                                        <tr>
                                            <th>Usuario</th>
                                            <th>Contrato</th>
                                            <th>Cadastrado em</th>
                                            <th className="text-right">Acoes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {responsaveis.map((responsavel) => (
                                            <tr key={responsavel.id}>
                                                <td>
                                                    <div className="flex items-center gap-3">
                                                        <Avatar user={responsavel.user} />
                                                        <span className="min-w-0">
                                                            <span className="block truncate font-semibold text-[var(--ink-900)]">
                                                                {responsavel.user?.name}
                                                            </span>
                                                            <span className="block truncate text-xs text-[var(--ink-500)]">
                                                                {responsavel.user?.email}
                                                            </span>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span className="inline-flex items-center gap-2 text-sm text-[var(--ink-700)]">
                                                        <ClipboardList size={14} />
                                                        <span>
                                                            <span className="mono text-xs">{responsavel.contract?.code}</span>
                                                            <span className="block font-semibold">{responsavel.contract?.name}</span>
                                                        </span>
                                                    </span>
                                                </td>
                                                <td>{responsavel.created_at}</td>
                                                <td>
                                                    <div className="flex flex-wrap justify-end gap-2">
                                                        <ResponsibleActions tenant={tenant} responsavel={responsavel} onEdit={loadResponsavel} />
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    ) : (
                        <div className="p-12 text-center text-sm text-[var(--ink-500)]">
                            Nenhum usuario cadastrado para receber alertas de RNC.
                        </div>
                    )}
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
