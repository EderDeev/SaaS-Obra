import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { BellRing, Plus, Trash2, UserCheck, X } from 'lucide-react';
import { useState } from 'react';

function initials(name = '') {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase() || '?';
}

export default function AnalisarPleitoResponsaveis({
    users = [],
    etapas = [],
    responsaveis = [],
}) {
    const page = usePage();
    const tenant = page.props.currentTenant;
    const [showForm, setShowForm] = useState(false);
    const form = useForm({
        user_id: '',
        etapa: etapas[0]?.value || 'fiscal',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tenant.medicao.analisar-pleito.responsaveis.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('user_id');
                setShowForm(false);
            },
        });
    };

    const remove = (user, etapa) => {
        if (!window.confirm(`Remover ${user?.name} da etapa ${etapa.etapa_label}?`)) {
            return;
        }

        router.delete(route('tenant.medicao.analisar-pleito.responsaveis.destroy', [tenant.slug, etapa.id]), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Responsáveis análise" />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                <section className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <span className="eyebrow">Medição</span>
                        <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">Responsáveis análise</h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-[var(--ink-500)]">
                            Cadastre quem recebe alerta interno e e-mail quando uma Folha de Rosto entra em análise.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={() => setShowForm((current) => !current)}
                        className="sig-btn sig-btn-primary"
                    >
                        {showForm ? <X size={16} /> : <Plus size={16} />}
                        {showForm ? 'Fechar cadastro' : 'Cadastrar responsável'}
                    </button>
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

                {showForm && (
                    <form onSubmit={submit} className="sig-card overflow-hidden">
                        <header className="border-b border-[var(--border)] px-5 py-4">
                            <div className="flex items-center gap-3">
                                <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                                    <UserCheck size={20} />
                                </span>
                                <div>
                                    <h2 className="text-lg font-bold text-[var(--ink-900)]">Cadastrar responsável</h2>
                                    <p className="text-sm text-[var(--ink-500)]">O usuário precisa ter vínculo ativo com este tenant.</p>
                                </div>
                            </div>
                        </header>

                        <div className="grid gap-4 p-5 lg:grid-cols-[1fr_180px_auto] lg:items-end">
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

                            <Field label="Etapa" error={form.errors.etapa}>
                                <select
                                    value={form.data.etapa}
                                    onChange={(event) => form.setData('etapa', event.target.value)}
                                    className="sig-input"
                                >
                                    {etapas.map((etapa) => (
                                        <option key={etapa.value} value={etapa.value}>{etapa.label}</option>
                                    ))}
                                </select>
                            </Field>

                            <button type="submit" disabled={form.processing} className="sig-btn sig-btn-primary">
                                <Plus size={16} />
                                Cadastrar
                            </button>
                        </div>
                    </form>
                )}

                <section className="sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <h2 className="text-lg font-bold text-[var(--ink-900)]">Responsáveis por etapa</h2>
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
                                <article key={responsavel.user.id} className="grid gap-4 p-5 lg:grid-cols-[minmax(240px,1fr)_minmax(320px,auto)] lg:items-center">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <Avatar user={responsavel.user} />
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-bold text-[var(--ink-900)]">{responsavel.user?.name}</p>
                                            <p className="truncate text-xs text-[var(--ink-500)]">{responsavel.user?.email}</p>
                                        </div>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                                        {responsavel.etapas.map((etapa) => (
                                            <span
                                                key={etapa.id}
                                                className="inline-flex items-center gap-2 rounded-full bg-blue-50 py-1 pl-3 pr-1.5 text-xs font-bold text-blue-700"
                                            >
                                                {etapa.etapa_label}
                                                <button
                                                    type="button"
                                                    onClick={() => remove(responsavel.user, etapa)}
                                                    className="flex h-6 w-6 items-center justify-center rounded-full text-red-600 transition hover:bg-red-100"
                                                    title={`Remover etapa ${etapa.etapa_label}`}
                                                >
                                                    <Trash2 size={13} />
                                                </button>
                                            </span>
                                        ))}
                                    </div>
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
