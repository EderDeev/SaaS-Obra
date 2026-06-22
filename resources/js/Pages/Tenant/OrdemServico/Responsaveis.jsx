import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { BellRing, Plus, Trash2, UserCheck } from 'lucide-react';

function initials(name = '') {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase() || '?';
}

export default function OrdemServicoResponsaveis({
    selectedContractId,
    contracts = [],
    obras = [],
    users = [],
    responsaveis = [],
}) {
    const page = usePage();
    const tenant = page.props.currentTenant;
    const form = useForm({
        contract_id: selectedContractId || '',
        obra_id: '',
        user_id: '',
        tipo: 'fiscal',
    });

    const changeContract = (contractId) => {
        router.get(
            route('tenant.ordem-servico.responsaveis.index', tenant.slug),
            { contract_id: contractId },
            { preserveScroll: true, preserveState: false }
        );
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tenant.ordem-servico.responsaveis.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => form.reset('obra_id', 'user_id'),
        });
    };

    const remove = (responsavel) => {
        if (!window.confirm(`Remover ${responsavel.user?.name} como ${responsavel.tipo_label.toLowerCase()} desta obra?`)) {
            return;
        }

        router.delete(route('tenant.ordem-servico.responsaveis.destroy', [tenant.slug, responsavel.id]), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Responsáveis OS" />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                <section className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <span className="eyebrow">Ordem de Serviço</span>
                        <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">Responsáveis</h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-[var(--ink-500)]">
                            Defina os fiscais que analisam e os aprovadores responsáveis por cada obra.
                        </p>
                    </div>
                </section>

                {page.props.flash?.success && (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                        {page.props.flash.success}
                    </div>
                )}

                {Object.values(page.props.errors || {}).length > 0 && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                        {Object.values(page.props.errors)[0]}
                    </div>
                )}

                <section className="sig-card p-5">
                    <label className="grid gap-1.5 text-sm">
                        <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Contrato</span>
                        <select
                            value={selectedContractId || ''}
                            onChange={(event) => changeContract(event.target.value)}
                            className="sig-input"
                        >
                            {contracts.map((contract) => (
                                <option key={contract.id} value={contract.id}>
                                    {contract.code} - {contract.name}
                                </option>
                            ))}
                        </select>
                    </label>
                </section>

                <form onSubmit={submit} className="sig-card overflow-hidden">
                    <header className="border-b border-[var(--border)] px-5 py-4">
                        <div className="flex items-center gap-3">
                            <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                                <UserCheck size={20} />
                            </span>
                            <div>
                                <h2 className="text-lg font-bold text-[var(--ink-900)]">Cadastrar responsável da obra</h2>
                                <p className="text-sm text-[var(--ink-500)]">O usuário precisa ter vínculo ativo com o contrato.</p>
                            </div>
                        </div>
                    </header>

                    <div className="grid gap-4 p-5 lg:grid-cols-[1fr_1fr_180px_auto] lg:items-end">
                        <Field label="Obra" error={form.errors.obra_id}>
                            <select
                                value={form.data.obra_id}
                                onChange={(event) => form.setData('obra_id', event.target.value)}
                                className="sig-input"
                            >
                                <option value="">Selecione a obra</option>
                                {obras.map((obra) => (
                                    <option key={obra.id} value={obra.id}>{obra.label}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Usuário" error={form.errors.user_id}>
                            <select
                                value={form.data.user_id}
                                onChange={(event) => form.setData('user_id', event.target.value)}
                                className="sig-input"
                            >
                                <option value="">Selecione o usuário</option>
                                {users.map((user) => (
                                    <option key={user.id} value={user.id}>{user.label}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Papel" error={form.errors.tipo}>
                            <select
                                value={form.data.tipo}
                                onChange={(event) => form.setData('tipo', event.target.value)}
                                className="sig-input"
                            >
                                <option value="fiscal">Fiscal</option>
                                <option value="aprovador">Aprovador</option>
                            </select>
                        </Field>

                        <button type="submit" disabled={form.processing} className="sig-btn sig-btn-primary">
                            <Plus size={16} />
                            Cadastrar
                        </button>
                    </div>
                </form>

                <section className="sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <h2 className="text-lg font-bold text-[var(--ink-900)]">Alertas e aprovações por obra</h2>
                            <p className="text-sm text-[var(--ink-500)]">{responsaveis.length} responsável(is) ativo(s)</p>
                        </div>
                        <BellRing className="text-[var(--ink-400)]" size={22} />
                    </header>

                    {responsaveis.length === 0 ? (
                        <p className="p-8 text-center text-sm text-[var(--ink-500)]">
                            Nenhum responsável cadastrado para este contrato.
                        </p>
                    ) : (
                        <div className="divide-y divide-[var(--border)]">
                            {responsaveis.map((responsavel) => (
                                <article key={responsavel.id} className="grid gap-4 p-5 lg:grid-cols-[1fr_1fr_160px_auto] lg:items-center">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <Avatar user={responsavel.user} />
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-bold text-[var(--ink-900)]">{responsavel.user?.name}</p>
                                            <p className="truncate text-xs text-[var(--ink-500)]">{responsavel.user?.email}</p>
                                        </div>
                                    </div>

                                    <div>
                                        <span className="text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]">Obra</span>
                                        <p className="text-sm font-bold text-[var(--ink-900)]">
                                            {responsavel.obra?.codigo ? `${responsavel.obra.codigo} - ` : ''}{responsavel.obra?.nome}
                                        </p>
                                    </div>

                                    <span className={`inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold ${
                                        responsavel.tipo === 'aprovador'
                                            ? 'bg-indigo-50 text-indigo-700'
                                            : 'bg-amber-50 text-amber-700'
                                    }`}>
                                        {responsavel.tipo_label}
                                    </span>

                                    <button
                                        type="button"
                                        onClick={() => remove(responsavel)}
                                        className="sig-btn justify-center border-red-200 bg-red-50 text-red-700 hover:bg-red-100"
                                    >
                                        <Trash2 size={16} />
                                        Remover
                                    </button>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function Field({ label, error, children }) {
    return (
        <label className="grid gap-1.5 text-sm">
            <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">{label}</span>
            {children}
            {error ? <span className="text-xs font-semibold text-red-600">{error}</span> : null}
        </label>
    );
}

function Avatar({ user }) {
    return user?.avatar_url ? (
        <img src={user.avatar_url} alt={user.name} className="h-10 w-10 rounded-full object-cover" />
    ) : (
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[var(--primary-100)] text-xs font-bold text-[var(--primary)]">
            {initials(user?.name)}
        </span>
    );
}
