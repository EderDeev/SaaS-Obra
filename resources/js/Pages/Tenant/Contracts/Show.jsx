import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Calendar,
    ClipboardCheck,
    FileText,
    FolderKanban,
    MapPin,
    Plus,
    Upload,
    Users,
    Wallet,
} from 'lucide-react';

const contractStatusMeta = {
    planning: { label: 'Planejamento', pill: 'sig-pill-blue' },
    active: { label: 'Em andamento', pill: 'sig-pill-green' },
    paused: { label: 'Paralisado', pill: 'sig-pill-amber' },
    completed: { label: 'Concluído', pill: 'sig-pill-blue' },
    cancelled: { label: 'Cancelado', pill: 'sig-pill-red' },
};

const activityStatusLabels = {
    todo: 'A fazer',
    in_progress: 'Em andamento',
    review: 'Em revisão',
    done: 'Concluída',
};

const projectStatusLabels = {
    em_analise: 'Em análise',
    em_aprovacao: 'Em aprovação',
    ativo: 'Aprovado',
    inativo: 'Inativo',
    reprovado: 'Reprovado',
};

const rncStatusLabels = {
    aberta: 'Aberta',
    aguardando_acao_corretiva: 'Aguardando ação corretiva',
    aguardando_analise: 'Aguardando análise',
    aguardando_evidencia: 'Aguardando evidência',
    finalizada: 'Finalizada',
};

const shortDate = (date) => {
    if (!date) return 'Não informado';

    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date));
};

const currencyLocaleMap = {
    BRL: 'pt-BR',
    USD: 'en-US',
    JPY: 'ja-JP',
    CNY: 'zh-CN',
    EUR: 'de-DE',
};

const money = (value, currency = 'BRL') => Number(value || 0).toLocaleString(currencyLocaleMap[currency] || 'pt-BR', {
    style: 'currency',
    currency,
    maximumFractionDigits: currency === 'JPY' ? 0 : 2,
});

const initials = (name = '?') => name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase();

export default function ContractShow({
    tenant,
    contract,
    canManageParticipants,
    participantRoles,
    recentActivities = [],
    recentRncs = [],
    recentProjects = [],
    capabilities = {},
}) {
    const page = usePage();
    const form = useForm({
        name: '',
        email: '',
        side: 'client',
        role: 'client_viewer',
    });

    const rolesForSide = participantRoles[form.data.side] || [];
    const statusMeta = contractStatusMeta[contract.status] || { label: contract.status, pill: '' };
    const badge = (contract.code || contract.name || '?').replace(/[^A-Za-z0-9]/g, '').slice(0, 2).toUpperCase();
    const contractTitle = contract.obra?.nome || contract.name;
    const cliente = contract.cliente_empresa?.nome || contract.client_company_name || 'Cliente não informado';
    const construtora = contract.construtora_empresa?.nome || contract.contractor_company_name || 'Construtora não informada';
    const location = [contract.city, contract.state].filter(Boolean).join(' - ') || 'Local não informado';

    const submit = (event) => {
        event.preventDefault();

        form.post(route('tenant.contracts.participants.store', [page.props.currentTenant.slug, contract.id]), {
            preserveScroll: true,
            onSuccess: () => form.setData({
                name: '',
                email: '',
                side: 'client',
                role: 'client_viewer',
            }),
        });
    };

    const updateSide = (side) => {
        form.setData((data) => ({
            ...data,
            side,
            role: participantRoles[side]?.[0] || '',
        }));
    };

    return (
        <AuthenticatedLayout>
            <Head title={contractTitle} />

            <section className="sig-content fade-in">
                <header className="flex flex-wrap items-start gap-5">
                    <div className="min-w-0 flex-1">
                        <div className="mb-2 flex flex-wrap items-center gap-2">
                            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[15px] font-bold text-[var(--primary)]">
                                {badge}
                            </span>
                            <span className="mono text-[13px] text-[var(--ink-500)]">{contract.code}</span>
                            <span className={`sig-pill ${statusMeta.pill}`}><span className="sig-pill-dot" />{statusMeta.label}</span>
                        </div>
                        <h1 className="text-[26px] font-semibold leading-tight text-[var(--ink-900)]">{contractTitle}</h1>
                        <div className="mt-2 flex flex-wrap gap-x-5 gap-y-2 text-[13.5px] text-[var(--ink-500)]">
                            <span className="flex items-center gap-1.5"><Users size={14} /> {cliente}</span>
                            <span className="flex items-center gap-1.5"><FolderKanban size={14} /> {construtora}</span>
                            <span className="flex items-center gap-1.5"><MapPin size={14} /> {location}</span>
                            <span className="flex items-center gap-1.5"><Calendar size={14} /> até {shortDate(contract.ends_at)}</span>
                        </div>
                    </div>
                    <QuickActions tenant={tenant} capabilities={capabilities} />
                </header>

                <section className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <Metric icon={Activity} label="Atividades abertas" value={contract.open_activities_count} />
                    <Metric icon={AlertTriangle} label="Atividades atrasadas" value={contract.overdue_activities_count} attention={contract.overdue_activities_count > 0} />
                    <Metric icon={ClipboardCheck} label="RNCs abertas" value={contract.open_rncs_count} attention={contract.open_rncs_count > 0} />
                    <Metric icon={FileText} label="Projetos pendentes" value={contract.pending_projects_count} />
                    <Metric icon={FolderKanban} label="Projetos aprovados" value={contract.approved_projects_count} />
                </section>

                <section className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,1fr)]">
                    <div className="grid content-start gap-5">
                        <ModulePanel
                            title="Atividades"
                            subtitle="Acompanhamento das tarefas recentes deste contrato"
                            href={capabilities.viewActivities ? route('tenant.activities.index', tenant.slug) : null}
                        >
                            {recentActivities.map((item) => (
                                <ListRow
                                    key={item.id}
                                    title={item.title}
                                    meta={`${item.category === 'quality' ? 'Qualidade' : 'Projeto'} · ${shortDate(item.due_date)}`}
                                    status={activityStatusLabels[item.status] || item.status}
                                    attention={item.status !== 'done' && item.due_date && new Date(item.due_date) < new Date()}
                                />
                            ))}
                            <EmptyState show={recentActivities.length === 0}>Nenhuma atividade cadastrada neste contrato.</EmptyState>
                        </ModulePanel>

                        <div className="grid gap-5 lg:grid-cols-2">
                            <ModulePanel
                                title="Projetos"
                                subtitle="Documentos submetidos recentemente"
                                href={capabilities.viewProjects ? route('tenant.projects.index', tenant.slug) : null}
                            >
                                {recentProjects.map((item) => (
                                    <ListRow
                                        key={item.id}
                                        title={item.title}
                                        meta={`${item.disciplina?.sigla || 'Sem disciplina'} · ${item.latest_version?.revision || 'R00'}`}
                                        status={projectStatusLabels[item.status] || item.status}
                                    />
                                ))}
                                <EmptyState show={recentProjects.length === 0}>Nenhum projeto submetido neste contrato.</EmptyState>
                            </ModulePanel>

                            <ModulePanel
                                title="RNCs"
                                subtitle="Não conformidades mais recentes"
                                href={capabilities.viewRncs ? route('tenant.qualidade.rnc.index', tenant.slug) : null}
                            >
                                {recentRncs.map((item) => (
                                    <ListRow
                                        key={item.id}
                                        href={capabilities.viewRncs ? route('tenant.qualidade.rnc.show', [tenant.slug, item.id]) : null}
                                        title={`${String(item.sequence_number).padStart(3, '0')}-${item.sequence_year}`}
                                        meta={`${item.disciplina?.nome || 'Sem disciplina'} · ${item.gravidade}`}
                                        status={rncStatusLabels[item.status] || item.status}
                                        attention={item.status !== 'finalizada'}
                                    />
                                ))}
                                <EmptyState show={recentRncs.length === 0}>Nenhuma RNC cadastrada neste contrato.</EmptyState>
                            </ModulePanel>
                        </div>
                    </div>

                    <aside className="grid content-start gap-5">
                        <ContractDetails contract={contract} cliente={cliente} construtora={construtora} location={location} />
                        {canManageParticipants && (
                            <ParticipantForm
                                form={form}
                                rolesForSide={rolesForSide}
                                updateSide={updateSide}
                                submit={submit}
                                success={page.props.flash.success}
                            />
                        )}
                        <TeamCard participants={contract.participants || []} />
                    </aside>
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function Metric({ icon: Icon, label, value, attention = false }) {
    return (
        <div className="sig-card p-4">
            <div className="flex items-center gap-2 text-[var(--ink-500)]"><Icon size={14} /><span className="eyebrow">{label}</span></div>
            <strong className={`mono mt-3 block text-3xl ${attention ? 'text-[var(--red)]' : 'text-[var(--ink-900)]'}`}>{Number(value || 0)}</strong>
        </div>
    );
}

function QuickActions({ tenant, capabilities }) {
    const actions = [
        capabilities.createActivity && { label: 'Nova atividade', icon: Plus, href: route('tenant.activities.index', tenant.slug) },
        capabilities.uploadProject && { label: 'Submeter projeto', icon: Upload, href: route('tenant.projects.index', tenant.slug) },
        capabilities.createRnc && { label: 'Nova RNC', icon: ClipboardCheck, href: route('tenant.qualidade.rnc.create', tenant.slug) },
    ].filter(Boolean);

    if (actions.length === 0) return null;

    return (
        <div className="flex flex-wrap gap-2">
            {actions.map(({ label, icon: Icon, href }) => (
                <Link key={label} className="sig-btn sig-btn-secondary" href={href}>
                    <Icon size={14} />
                    {label}
                </Link>
            ))}
        </div>
    );
}

function ModulePanel({ title, subtitle, href, children }) {
    return (
        <section className="sig-card overflow-hidden">
            <header className="flex items-start justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                <div>
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">{title}</h2>
                    <p className="mt-0.5 text-xs text-[var(--ink-500)]">{subtitle}</p>
                </div>
                {href && <Link className="sig-btn sig-btn-ghost sig-btn-sm" href={href}>Ver módulo</Link>}
            </header>
            <div className="divide-y divide-[var(--border)]">{children}</div>
        </section>
    );
}

function ListRow({ title, meta, status, href, attention = false }) {
    const content = (
        <>
            <span className="min-w-0 flex-1">
                <strong className="block truncate text-[13px] text-[var(--ink-900)]">{title}</strong>
                <span className="mt-0.5 block truncate text-xs text-[var(--ink-500)]">{meta}</span>
            </span>
            <span className={`sig-pill ${attention ? 'sig-pill-amber' : 'sig-pill-blue'}`}>{status}</span>
        </>
    );

    return href ? (
        <Link className="flex items-center gap-3 px-5 py-3 hover:bg-[var(--surface-muted)]" href={href}>{content}</Link>
    ) : (
        <div className="flex items-center gap-3 px-5 py-3">{content}</div>
    );
}

function EmptyState({ show, children }) {
    return show ? <p className="px-5 py-4 text-sm text-[var(--ink-500)]">{children}</p> : null;
}

function ContractDetails({ contract, cliente, construtora, location }) {
    return (
        <section className="sig-card p-5">
            <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Dados do contrato</h2>
            <dl className="mt-4 grid gap-3 text-[13px]">
                <InfoRow label="Código" value={contract.code} />
                <InfoRow label="Cliente" value={cliente} />
                <InfoRow label="Construtora" value={construtora} />
                <InfoRow label="Local" value={location} />
                <InfoRow label="Vigência" value={`${shortDate(contract.starts_at)} até ${shortDate(contract.ends_at)}`} />
                <InfoRow label="Valor" value={money(contract.total_value, contract.currency)} />
            </dl>
        </section>
    );
}

function InfoRow({ label, value }) {
    return (
        <div className="grid grid-cols-[92px_minmax(0,1fr)] gap-3">
            <dt className="text-[var(--ink-500)]">{label}</dt>
            <dd className="text-right font-medium text-[var(--ink-900)]">{value}</dd>
        </div>
    );
}

function ParticipantForm({ form, rolesForSide, updateSide, submit, success }) {
    return (
        <form className="sig-card p-5" onSubmit={submit}>
            <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Adicionar participante</h2>
            {success && <div className="mt-3 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">{success}</div>}
            <div className="mt-4 grid gap-3">
                <Field label="Nome"><input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /></Field>
                <Field label="E-mail"><input value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} type="email" required /></Field>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Lado">
                        <select value={form.data.side} onChange={(event) => updateSide(event.target.value)}>
                            <option value="client">Cliente</option>
                            <option value="contractor">Construtora</option>
                            <option value="manager">Gerenciadora</option>
                        </select>
                    </Field>
                    <Field label="Papel">
                        <select value={form.data.role} onChange={(event) => form.setData('role', event.target.value)}>
                            {rolesForSide.map((role) => <option key={role} value={role}>{role}</option>)}
                        </select>
                    </Field>
                </div>
            </div>
            <button className="sig-btn sig-btn-primary mt-4" disabled={form.processing}><Plus size={14} /> Adicionar</button>
        </form>
    );
}

function Field({ label, children }) {
    return (
        <label>
            <span className="eyebrow mb-1 block">{label}</span>
            <span className="sig-input">{children}</span>
        </label>
    );
}

function TeamCard({ participants }) {
    return (
        <section className="sig-card p-5">
            <header className="mb-4 flex items-center justify-between">
                <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Equipe no contrato</h2>
                <span className="text-xs text-[var(--ink-400)]">{participants.length}</span>
            </header>
            <ul className="grid gap-3">
                {participants.map((participant) => (
                    <li key={participant.id} className="flex items-center gap-3">
                        <span className="sig-avatar">{initials(participant.user.name)}</span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-[13px] font-semibold text-[var(--ink-900)]">{participant.user.name}</span>
                            <span className="block truncate text-[11.5px] text-[var(--ink-500)]">{participant.side} · {participant.role}</span>
                        </span>
                    </li>
                ))}
            </ul>
            {participants.length === 0 && <p className="text-sm text-[var(--ink-500)]">Nenhum participante vinculado.</p>}
        </section>
    );
}
