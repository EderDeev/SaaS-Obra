import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Building2, Calendar, FileWarning, FolderOpen, ListTodo, MapPin } from 'lucide-react';

const statusMeta = {
    planning: { label: 'Planejamento', pill: 'sig-pill-blue' },
    active: { label: 'Em andamento', pill: 'sig-pill-green' },
    paused: { label: 'Paralisado', pill: 'sig-pill-amber' },
    completed: { label: 'Concluído', pill: 'sig-pill-blue' },
    cancelled: { label: 'Cancelado', pill: 'sig-pill-red' },
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

export default function ContractAccessCard({ tenant, contract, shortDate }) {
    const title = contract.obra?.nome || contract.name;
    const cliente = contract.cliente_empresa?.nome || contract.client_company_name || 'Cliente não informado';
    const construtora = contract.construtora_empresa?.nome || contract.contractor_company_name || 'Construtora não informada';
    const state = contract.state_label || contract.state;
    const location = contract.city && state ? `${contract.city} - ${state}` : contract.city || state || 'Local não informado';
    const meta = statusMeta[contract.status] || { label: contract.status, pill: '' };
    const hasAttention = Number(contract.overdue_activities_count || 0) > 0
        || Number(contract.open_rncs_count || 0) > 0
        || Number(contract.pending_projects_count || 0) > 0;

    return (
        <Link
            href={route('tenant.contracts.show', [tenant.slug, contract.id])}
            className="sig-card group flex min-h-[270px] flex-col overflow-hidden transition hover:-translate-y-0.5 hover:border-[var(--border-strong)] hover:shadow-[var(--shadow-md)]"
        >
            <header className="flex items-start gap-3 border-b border-[var(--border)] px-[18px] py-4">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                    <Building2 size={16} />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="mono block text-xs text-[var(--ink-500)]">{contract.code}</span>
                    <span className="block truncate text-[14.5px] font-semibold text-[var(--ink-900)]">{title}</span>
                </span>
                <span className={`sig-pill ${meta.pill}`}>{meta.label}</span>
            </header>

            <div className="grid gap-2 px-[18px] py-4 text-[12.5px] text-[var(--ink-500)]">
                <Info icon={Building2} text={cliente} />
                <Info icon={Building2} text={construtora} />
                <Info icon={MapPin} text={location} />
                <Info icon={Calendar} text={`${shortDate(contract.starts_at)} até ${shortDate(contract.ends_at)}`} />
            </div>

            <div className="grid grid-cols-3 border-y border-[var(--border)] bg-[var(--surface-muted)]">
                <Count icon={ListTodo} label="Atividades" value={contract.open_activities_count} alert={contract.overdue_activities_count > 0} />
                <Count icon={FileWarning} label="RNCs" value={contract.open_rncs_count} alert={contract.open_rncs_count > 0} />
                <Count icon={FolderOpen} label="Projetos" value={contract.pending_projects_count} alert={contract.pending_projects_count > 0} />
            </div>

            <footer className="mt-auto flex items-center gap-3 px-[18px] py-3">
                <span className="min-w-0 flex-1">
                    <span className="eyebrow block">Valor contratado</span>
                    <span className="mono block truncate text-xs font-semibold text-[var(--ink-700)]">{money(contract.total_value, contract.currency)}</span>
                </span>
                {hasAttention && (
                    <span title="Contrato com pontos de atenção" className="text-[var(--amber)]">
                        <AlertTriangle size={15} />
                    </span>
                )}
                <ArrowRight size={15} className="text-[var(--ink-400)] transition-transform group-hover:translate-x-0.5" />
            </footer>
        </Link>
    );
}

function Info({ icon: Icon, text }) {
    return (
        <span className="flex min-w-0 items-center gap-2">
            <Icon size={13} className="shrink-0" />
            <span className="truncate">{text}</span>
        </span>
    );
}

function Count({ icon: Icon, label, value, alert }) {
    return (
        <span className="border-r border-[var(--border)] px-3 py-3 text-center last:border-r-0">
            <span className={`mx-auto flex w-fit items-center gap-1 text-sm font-semibold ${alert ? 'text-[var(--amber)]' : 'text-[var(--ink-700)]'}`}>
                <Icon size={13} />
                {value || 0}
            </span>
            <span className="mt-0.5 block text-[11px] text-[var(--ink-500)]">{label}</span>
        </span>
    );
}
