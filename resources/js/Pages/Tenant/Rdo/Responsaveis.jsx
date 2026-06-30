import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { BellRing, Building2, ChevronDown, Plus, Trash2, UserCheck } from 'lucide-react';
import { useMemo, useState } from 'react';

const stageColors = {
    campo: 'bg-blue-50 text-blue-700',
    construtora: 'bg-blue-50 text-blue-700',
    gerenciadora: 'bg-amber-50 text-amber-700',
    cliente: 'bg-indigo-50 text-indigo-700',
    assinatura: 'bg-emerald-50 text-emerald-700',
};

export default function Responsaveis({
    module = 'rdo',
    moduleLabel = 'RDO',
    pageDescription = 'Defina quem preenche pela construtora e quem aprova pela gerenciadora e pelo cliente em cada obra ou frente de serviço.',
    routeNames = {
        index: 'tenant.diario-obra.rdo.responsaveis.index',
        store: 'tenant.diario-obra.rdo.responsaveis.store',
        destroy: 'tenant.diario-obra.rdo.responsaveis.destroy',
    },
    contracts = [],
    selectedContractId,
    contractCompanies,
    obras = [],
    users = [],
    stages = [],
    responsaveis = [],
}) {
    const { currentTenant, flash = {}, errors = {} } = usePage().props;
    const [openUsers, setOpenUsers] = useState(() => new Set());
    const form = useForm({
        contract_id: selectedContractId || '',
        obra_id: '',
        user_id: '',
        etapa: stages?.[0]?.value || 'construtora',
    });
    const companyId = {
        campo: contractCompanies?.construtora?.id,
        construtora: contractCompanies?.construtora?.id,
        gerenciadora: contractCompanies?.gerenciadora?.id,
        cliente: contractCompanies?.cliente?.id,
    }[form.data.etapa];
    const eligibleUsers = useMemo(
        () => form.data.etapa === 'assinatura'
            ? users
            : users.filter((user) => Number(user.empresa_id) === Number(companyId)),
        [users, companyId, form.data.etapa]
    );

    const changeContract = (contractId) => {
        router.get(
            route(routeNames.index, currentTenant.slug),
            { contract_id: contractId },
            { preserveScroll: true, preserveState: false }
        );
    };

    const changeStage = (stage) => {
        form.setData({ ...form.data, etapa: stage, user_id: '' });
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route(routeNames.store, currentTenant.slug), {
            preserveScroll: true,
            onSuccess: () => form.reset('obra_id', 'user_id'),
        });
    };

    const remove = (user, vinculo) => {
        if (!window.confirm(`Remover ${user?.name} de ${vinculo.obra?.codigo} - ${vinculo.obra?.nome}?`)) return;
        router.delete(route(routeNames.destroy, [currentTenant.slug, vinculo.id]), {
            preserveScroll: true,
        });
    };
    const toggleUser = (userId) => {
        setOpenUsers((current) => {
            const next = new Set(current);
            next.has(userId) ? next.delete(userId) : next.add(userId);

            return next;
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`${moduleLabel} - Responsáveis`} />
            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                <header>
                    <span className="eyebrow">Diário de Obra · {moduleLabel}</span>
                    <h1 className="mt-2 text-3xl font-bold">Responsáveis</h1>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-[var(--ink-500)]">
                        {pageDescription}
                    </p>
                </header>

                {flash.success && <Alert tone="success">{flash.success}</Alert>}
                {Object.values(errors).length > 0 && <Alert tone="danger">{Object.values(errors)[0]}</Alert>}

                <section className="sig-card p-5">
                    <Field label="Contrato">
                        <select value={selectedContractId || ''} onChange={(event) => changeContract(event.target.value)} className="sig-input">
                            {contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} - {contract.name}</option>)}
                        </select>
                    </Field>
                    <div className="mt-4 grid gap-3 md:grid-cols-3">
                        <Company label="Construtora" company={contractCompanies?.construtora} />
                        <Company label="Gerenciadora" company={contractCompanies?.gerenciadora} />
                        <Company label="Cliente" company={contractCompanies?.cliente} />
                    </div>
                </section>

                <form onSubmit={submit} className="sig-card overflow-hidden">
                    <header className="flex items-center gap-3 border-b border-[var(--border)] px-5 py-4">
                        <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]"><UserCheck size={20} /></span>
                        <div>
                            <h2 className="text-lg font-bold">Cadastrar responsável</h2>
                            <p className="text-sm text-[var(--ink-500)]">
                                {module === 'rda'
                                    ? 'A lista de usuários é filtrada pela construtora vinculada ao contrato.'
                                    : 'A lista de usuários é filtrada pela empresa da etapa; na assinatura, qualquer usuário ativo do tenant pode ser escolhido.'}
                            </p>
                        </div>
                    </header>
                    <div className="grid gap-4 p-5 lg:grid-cols-[1fr_1fr_1fr_auto] lg:items-end">
                        <Field label="Frente de serviço" error={form.errors.obra_id}>
                            <select value={form.data.obra_id} onChange={(event) => form.setData('obra_id', event.target.value)} className="sig-input">
                                <option value="">Selecione</option>
                                {obras.map((obra) => <option key={obra.id} value={obra.id}>{obra.codigo} - {obra.nome}</option>)}
                            </select>
                        </Field>
                        <Field label="Responsabilidade" error={form.errors.etapa}>
                            <select value={form.data.etapa} onChange={(event) => changeStage(event.target.value)} className="sig-input">
                                {stages.map((stage) => <option key={stage.value} value={stage.value}>{stage.label}</option>)}
                            </select>
                        </Field>
                        <Field label="Usuário" error={form.errors.user_id}>
                            <select value={form.data.user_id} onChange={(event) => form.setData('user_id', event.target.value)} className="sig-input">
                                <option value="">Selecione</option>
                                {eligibleUsers.map((user) => <option key={user.id} value={user.id}>{user.name} · {user.email}</option>)}
                            </select>
                            {eligibleUsers.length === 0 && <span className="text-xs text-amber-700">Nenhum usuário ativo está vinculado a esta empresa.</span>}
                        </Field>
                        <button type="submit" disabled={form.processing || !eligibleUsers.length} className="sig-btn sig-btn-primary"><Plus size={16} /> Cadastrar</button>
                    </div>
                </form>

                <section className="sig-card overflow-hidden">
                    <header className="flex items-center justify-between border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <h2 className="text-lg font-bold">Responsáveis por frente</h2>
                            <p className="text-sm text-[var(--ink-500)]">{responsaveis.length} usuário(s) com vínculo ativo</p>
                        </div>
                        <BellRing size={21} className="text-[var(--ink-400)]" />
                    </header>
                    {responsaveis.length ? (
                        <div className="divide-y divide-[var(--border)]">
                            {responsaveis.map((responsavel) => (
                                <ResponsibilityUserRow
                                    key={responsavel.user?.id}
                                    responsavel={responsavel}
                                    open={openUsers.has(responsavel.user?.id)}
                                    onToggle={() => toggleUser(responsavel.user?.id)}
                                    onRemove={remove}
                                />
                            ))}
                        </div>
                    ) : <p className="p-8 text-center text-sm text-[var(--ink-500)]">Nenhum responsável cadastrado para este contrato.</p>}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function ResponsibilityUserRow({ responsavel, open = false, onToggle, onRemove }) {
    const vinculos = responsavel.vinculos || [];
    const stageSummary = vinculos.reduce((acc, vinculo) => {
        acc[vinculo.etapa] = {
            label: vinculo.etapa_label,
            count: (acc[vinculo.etapa]?.count || 0) + 1,
        };

        return acc;
    }, {});

    return (
        <article className="bg-white">
            <button
                type="button"
                onClick={onToggle}
                className="grid w-full gap-3 px-5 py-4 text-left transition hover:bg-slate-50 md:grid-cols-[minmax(220px,1fr)_auto_auto] md:items-center"
            >
                <div className="min-w-0">
                    <p className="truncate font-bold">{responsavel.user?.name}</p>
                    <p className="truncate text-xs text-[var(--ink-500)]">{responsavel.user?.email}</p>
                </div>
                <div className="flex flex-wrap gap-1.5">
                    {Object.entries(stageSummary).map(([stage, summary]) => (
                        <span key={stage} className={`rounded-full px-2.5 py-1 text-[11px] font-bold ${stageColors[stage] || 'bg-slate-100 text-slate-700'}`}>
                            {summary.label} · {summary.count}
                        </span>
                    ))}
                    <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-600">
                        {vinculos.length} vínculo(s)
                    </span>
                </div>
                <span className="inline-flex items-center justify-end gap-2 text-xs font-bold text-[var(--primary)]">
                    {open ? 'Ocultar permissões' : 'Ver permissões'}
                    <ChevronDown size={16} className={`transition-transform ${open ? 'rotate-180' : ''}`} />
                </span>
            </button>

            {open && (
                <div className="grid gap-2 border-t border-[var(--border)] bg-slate-50/70 px-5 py-4">
                    {vinculos.map((vinculo) => (
                        <div key={vinculo.id} className="flex flex-col gap-3 rounded-lg border border-[var(--border)] bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="min-w-0">
                                <p className="text-sm font-bold">{vinculo.obra?.codigo} - {vinculo.obra?.nome}</p>
                                <span className={`mt-1 inline-flex w-fit rounded-full px-2.5 py-1 text-[11px] font-bold ${stageColors[vinculo.etapa] || 'bg-slate-100 text-slate-700'}`}>
                                    {vinculo.etapa_label}
                                </span>
                            </div>
                            <button type="button" onClick={() => onRemove(responsavel.user, vinculo)} className="sig-btn shrink-0 border-red-200 bg-red-50 text-red-700">
                                <Trash2 size={15} /> Remover
                            </button>
                        </div>
                    ))}
                </div>
            )}
        </article>
    );
}

function Company({ label, company }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-slate-50 px-4 py-3">
            <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-[var(--ink-500)]"><Building2 size={15} /> {label}</div>
            <p className="mt-1 text-sm font-bold">{company?.nome || 'Não vinculada ao contrato'}</p>
        </div>
    );
}

function Field({ label, error, children }) {
    return <label className="grid gap-1.5 text-sm"><span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">{label}</span>{children}{error && <span className="text-xs font-semibold text-red-600">{error}</span>}</label>;
}

function Alert({ tone, children }) {
    return <div className={`rounded-lg border px-4 py-3 text-sm font-semibold ${tone === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'}`}>{children}</div>;
}
