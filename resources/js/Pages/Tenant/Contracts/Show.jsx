import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Calendar,
    Check,
    ClipboardList,
    Download,
    FileText,
    Filter,
    MapPin,
    MoreHorizontal,
    Plus,
    Upload,
    Users,
    Wallet,
} from 'lucide-react';
import { useMemo } from 'react';

const statusMetaMap = {
    planning: { label: 'Planejamento', pill: 'sig-pill-blue' },
    active: { label: 'Em andamento', pill: 'sig-pill-green' },
    paused: { label: 'Paralisado', pill: 'sig-pill-amber' },
    completed: { label: 'Concluído', pill: 'sig-pill-blue' },
    cancelled: { label: 'Cancelado', pill: 'sig-pill-red' },
};

const shortDate = (date) => {
    if (!date) return 'sem prazo';

    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date));
};

const currencyLocaleMap = {
    BRL: 'pt-BR',
    USD: 'en-US',
    JPY: 'ja-JP',
    CNY: 'zh-CN',
    EUR: 'de-DE',
};

const money = (value, currency = 'BRL') => {
    const locale = currencyLocaleMap[currency] || 'pt-BR';
    const maximumFractionDigits = currency === 'JPY' ? 0 : 2;

    if (!value) {
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency,
            maximumFractionDigits,
        }).format(0);
    }

    return Number(value).toLocaleString(locale, {
        style: 'currency',
        currency,
        maximumFractionDigits,
    });
};

const initials = (name = '?') => name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase();

export default function ContractShow({ tenant, contract, canManageParticipants, participantRoles }) {
    const page = usePage();
    const form = useForm({
        name: '',
        email: '',
        side: 'client',
        role: 'client_viewer',
    });

    const rolesForSide = participantRoles[form.data.side] || [];
    const statusMeta = statusMetaMap[contract.status] || { label: contract.status, pill: '' };
    const physical = contract.status === 'completed' ? 100 : contract.status === 'paused' ? 42 : contract.status === 'planning' ? 12 : 68;
    const financial = contract.status === 'completed' ? 100 : Math.max(8, physical - 9);
    const pendingMeasures = contract.status === 'active' ? Math.max(1, contract.id % 4) : 0;
    const badge = (contract.code || contract.name || '?').replace(/[^A-Za-z0-9]/g, '').slice(0, 2).toUpperCase();
    const contractTitle = contract.obra?.nome || contract.name;
    const cliente = contract.cliente_empresa?.nome || contract.client_company_name || 'Cliente não informado';
    const construtora = contract.construtora_empresa?.nome || contract.contractor_company_name || 'Construtora não informada';
    const location = contract.city && contract.state
        ? `${contract.city}/${contract.state}`
        : contract.city || contract.state || 'Local não informado';

    const activities = useMemo(() => [
        { icon: Check, tone: 'var(--green)', who: 'Sistema', what: 'atualizou o progresso físico', when: 'há 12 min' },
        { icon: FileText, tone: 'var(--primary)', who: contract.participants?.[0]?.user?.name || 'Equipe', what: 'anexou documento ao contrato', when: 'há 1h' },
        { icon: AlertTriangle, tone: 'var(--amber)', who: 'Controle', what: 'sinalizou pendência de validação', when: 'hoje, 08:14' },
        { icon: Wallet, tone: 'var(--ink-700)', who: 'Financeiro', what: `registrou medição de ${money(contract.total_value ? contract.total_value * 0.04 : 125000, contract.currency)}`, when: 'ontem' },
    ], [contract]);

    const submit = (event) => {
        event.preventDefault();

        form.post(route('tenant.contracts.participants.store', [page.props.currentTenant.slug, contract.id]), {
            preserveScroll: true,
            onSuccess: () => {
                form.setData({
                    name: '',
                    email: '',
                    side: 'client',
                    role: 'client_viewer',
                });
            },
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

            <section className="fade-in">
                <div className="flex flex-wrap items-start gap-5 px-8 pt-7">
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
                            <span className="flex items-center gap-1.5"><MapPin size={14} /> {construtora}</span>
                            <span className="flex items-center gap-1.5"><MapPin size={14} /> {location}</span>
                            <span className="flex items-center gap-1.5"><Calendar size={14} /> entrega {shortDate(contract.ends_at)}</span>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <button className="sig-btn sig-btn-secondary" type="button"><Download size={14} />Exportar</button>
                        <button className="sig-btn sig-btn-secondary" type="button"><FileText size={14} />Ver contrato</button>
                        {canManageParticipants && <button className="sig-btn sig-btn-primary" type="button"><Plus size={14} />Nova medição</button>}
                    </div>
                </div>

                <section className="grid gap-3 px-8 pt-6 lg:grid-cols-4 sm:grid-cols-2">
                    <Kpi icon={Activity} label="Avanço físico" value={`${physical}%`} trend="↑ 4,2%" sub="Últimos 30 dias" />
                    <Kpi icon={Wallet} label="Avanço financeiro" value={`${financial}%`} trend="↑ 3,8%" sub={`${money(contract.total_value, contract.currency)} contratados`} />
                    <Kpi icon={Calendar} label="Prazo" value={shortDate(contract.ends_at)} sub={`Início ${shortDate(contract.starts_at)}`} />
                    <Kpi icon={ClipboardList} label="Medições pendentes" value={pendingMeasures} sub={pendingMeasures ? 'Requer aprovação' : 'Tudo em dia'} accent={pendingMeasures ? 'var(--primary)' : undefined} />
                </section>

                <section className="grid gap-5 px-8 py-6 lg:grid-cols-[minmax(0,1.7fr)_minmax(320px,1fr)]">
                    <div className="grid gap-5">
                        <Timeline physical={physical} contract={contract} />
                        <Measurements contract={contract} canManageParticipants={canManageParticipants} />
                    </div>

                    <aside className="grid content-start gap-5">
                        <QuickActions />
                        {canManageParticipants && (
                            <ParticipantForm
                                form={form}
                                rolesForSide={rolesForSide}
                                participantRoles={participantRoles}
                                updateSide={updateSide}
                                submit={submit}
                                success={page.props.flash.success}
                            />
                        )}
                        <TeamCard participants={contract.participants || []} />
                        <ActivityFeed activities={activities} />
                    </aside>
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function Kpi({ icon: Icon, label, value, trend, sub, accent }) {
    return (
        <div className="sig-card p-[18px]">
            <div className="flex items-center gap-2 text-[var(--ink-500)]"><Icon size={14} /><span className="eyebrow">{label}</span></div>
            <div className="mt-2 flex items-baseline gap-2">
                <span className="mono text-[28px] font-semibold" style={{ color: accent || 'var(--ink-900)' }}>{value}</span>
                {trend && <span className="text-xs font-semibold text-[var(--green)]">{trend}</span>}
            </div>
            <p className="mt-1 text-[12.5px] text-[var(--ink-500)]">{sub}</p>
        </div>
    );
}

function Timeline({ physical, contract }) {
    const phases = [
        ['Mobilização', 100, 'Concluída'],
        ['Terraplenagem', 100, 'Concluída'],
        ['Pavimentação', physical, `${physical}%`],
        ['Sinalização', contract.status === 'completed' ? 100 : 0, 'A iniciar'],
        ['Entrega', contract.status === 'completed' ? 100 : 0, 'A iniciar'],
    ];

    return (
        <div className="sig-card p-5">
            <header className="mb-4 flex items-center justify-between gap-4">
                <div>
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Cronograma físico</h2>
                    <p className="text-[12.5px] text-[var(--ink-500)]">5 fases · progresso consolidado</p>
                </div>
                <button className="sig-btn sig-btn-secondary sig-btn-sm" type="button">Ver Gantt</button>
            </header>

            <div className="grid grid-cols-5 gap-1">
                {phases.map(([name, pct, label]) => (
                    <div key={name}>
                        <div className="h-2 overflow-hidden rounded bg-[var(--ink-200)]">
                            <div className="h-full rounded" style={{ width: `${pct}%`, background: pct >= 100 ? 'var(--green)' : pct > 0 ? 'var(--primary)' : 'transparent' }} />
                        </div>
                        <div className="mt-2 text-[11.5px] font-semibold text-[var(--ink-700)]">{name}</div>
                        <div className="text-[11px] text-[var(--ink-500)]">{label}</div>
                    </div>
                ))}
            </div>
        </div>
    );
}

function Measurements({ contract, canManageParticipants }) {
    const rows = [
        ['ME #14', '01-15 mai', money(contract.total_value ? contract.total_value * 0.04 : 612480, contract.currency), 'em análise', 'sig-pill-blue'],
        ['ME #13', '16-30 abr', money(contract.total_value ? contract.total_value * 0.03 : 482300, contract.currency), 'pago', 'sig-pill-green'],
        ['ME #12', '01-15 abr', money(contract.total_value ? contract.total_value * 0.035 : 538910, contract.currency), 'pago', 'sig-pill-green'],
    ];

    return (
        <div className="sig-card overflow-hidden">
            <header className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div>
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Medições</h2>
                    <p className="text-[12.5px] text-[var(--ink-500)]">Histórico operacional do contrato</p>
                </div>
                <div className="flex gap-2">
                    <button className="sig-btn sig-btn-secondary sig-btn-sm" type="button"><Filter size={13} /> Filtrar</button>
                    {canManageParticipants && <button className="sig-btn sig-btn-primary sig-btn-sm" type="button"><Plus size={13} /> Nova medição</button>}
                </div>
            </header>
            <table className="sig-table">
                <thead>
                    <tr><th>Identificador</th><th>Período</th><th>Valor</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row[0]}>
                            <td className="mono font-semibold">{row[0]}</td>
                            <td className="text-[var(--ink-500)]">{row[1]}</td>
                            <td className="mono">{row[2]}</td>
                            <td><span className={`sig-pill ${row[4]}`}><span className="sig-pill-dot" />{row[3]}</span></td>
                            <td className="text-right"><button className="sig-btn sig-btn-ghost sig-btn-sm" type="button"><MoreHorizontal size={14} /></button></td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function QuickActions() {
    return (
        <div className="sig-card grid grid-cols-2 gap-2 p-3">
            <button className="sig-btn sig-btn-ghost justify-start bg-[var(--surface-muted)]" type="button"><Plus size={15} /> Nova medição</button>
            <button className="sig-btn sig-btn-ghost justify-start bg-[var(--surface-muted)]" type="button"><Upload size={15} /> Documento</button>
            <button className="sig-btn sig-btn-ghost justify-start bg-[var(--surface-muted)]" type="button"><Calendar size={15} /> Boletim diário</button>
            <button className="sig-btn sig-btn-ghost justify-start bg-[var(--surface-muted)]" type="button"><Activity size={15} /> Diário de obra</button>
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
                <Field label="Email"><input value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} type="email" required /></Field>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Lado">
                        <select value={form.data.side} onChange={(event) => updateSide(event.target.value)}>
                            <option value="client">cliente</option>
                            <option value="contractor">construtora</option>
                            <option value="manager">gerenciadora</option>
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
        <div className="sig-card p-5">
            <header className="mb-4 flex items-center justify-between">
                <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Equipe no contrato</h2>
            </header>
            <ul className="grid gap-3">
                {participants.map((participant) => (
                    <li key={participant.id} className="flex items-center gap-3">
                        <span className="sig-avatar relative">
                            {initials(participant.user.name)}
                            <span className="absolute bottom-0 right-0 h-2.5 w-2.5 rounded-full bg-[var(--green)] ring-2 ring-white" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-[13px] font-semibold text-[var(--ink-900)]">{participant.user.name}</span>
                            <span className="block truncate text-[11.5px] text-[var(--ink-500)]">{participant.side} · {participant.role}</span>
                        </span>
                        <button className="sig-btn sig-btn-ghost sig-btn-sm !px-2" type="button"><MoreHorizontal size={14} /></button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function ActivityFeed({ activities }) {
    return (
        <div className="sig-card p-5">
            <header className="mb-3">
                <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Atividade recente</h2>
                <p className="text-[12.5px] text-[var(--ink-500)]">Eventos do contrato nas últimas 48h</p>
            </header>
            <ul className="grid">
                {activities.map((item, index) => {
                    const Icon = item.icon;

                    return (
                        <li key={item.what} className={`flex gap-3 py-3 ${index < activities.length - 1 ? 'border-b border-[var(--border)]' : ''}`}>
                            <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg" style={{ background: `${item.tone}14`, color: item.tone }}>
                                <Icon size={14} />
                            </span>
                            <span className="min-w-0 flex-1 text-[13px]">
                                <strong className="font-semibold">{item.who}</strong>
                                <span className="text-[var(--ink-500)]"> {item.what}</span>
                                <span className="block text-[11.5px] text-[var(--ink-400)]">{item.when}</span>
                            </span>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
