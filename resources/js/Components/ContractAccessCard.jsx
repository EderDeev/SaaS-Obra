import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Building2, Calendar, FilePlus2, FileWarning, FolderOpen, ListTodo, MapPin, Settings } from 'lucide-react';

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

const additiveTypeLabel = {
    cost: 'Custo',
    deadline: 'Prazo',
    cost_deadline: 'Custo e prazo',
};

function remainingDaysLabel(date) {
    if (!date) return 'Prazo não informado';

    const rawDate = String(date);
    const dateOnly = /^\d{4}-\d{2}-\d{2}/.test(rawDate) ? rawDate.slice(0, 10) : new Date(date).toISOString().slice(0, 10);
    const [year, month, day] = dateOnly.split('-').map(Number);
    const today = new Date();
    const todayUtc = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
    const endUtc = Date.UTC(year, month - 1, day);
    const diff = Math.round((endUtc - todayUtc) / 86400000);

    if (diff > 1) return `Faltam ${diff} dias`;
    if (diff === 1) return 'Falta 1 dia';
    if (diff === 0) return 'Encerra hoje';
    if (diff === -1) return 'Vencido há 1 dia';

    return `Vencido há ${Math.abs(diff)} dias`;
}

function remainingDaysPillClass(date) {
    if (!date) return 'sig-pill-red';

    const rawDate = String(date);
    const dateOnly = /^\d{4}-\d{2}-\d{2}/.test(rawDate) ? rawDate.slice(0, 10) : new Date(date).toISOString().slice(0, 10);
    const [year, month, day] = dateOnly.split('-').map(Number);
    const today = new Date();
    const todayUtc = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
    const endUtc = Date.UTC(year, month - 1, day);
    const diff = Math.round((endUtc - todayUtc) / 86400000);

    if (diff > 180) return 'sig-pill-green';
    if (diff >= 90) return 'sig-pill-amber';

    return 'sig-pill-red';
}

export default function ContractAccessCard({ tenant, contract, shortDate, canManageContracts = false, onParametrize, onAdditive, onHistory }) {
    const title = contract.obra?.nome || contract.name;
    const cliente = contract.cliente_empresa?.nome || contract.client_company_name || 'Cliente não informado';
    const construtora = contract.construtora_empresa?.nome || contract.contractor_company_name || 'Construtora não informada';
    const state = contract.state_label || contract.state;
    const location = contract.city && state ? `${contract.city} - ${state}` : contract.city || state || 'Local não informado';
    const additiveCount = Number(contract.contract_additives_count || 0);
    const latestAdditive = contract.latest_additive;
    const hasAttention = Number(contract.overdue_activities_count || 0) > 0
        || Number(contract.open_rncs_count || 0) > 0
        || Number(contract.pending_projects_count || 0) > 0;

    return (
        <article
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
                <span className={`sig-pill ${remainingDaysPillClass(contract.ends_at)} shrink-0 text-[12px] font-semibold`}>
                    <Calendar size={12} />
                    {remainingDaysLabel(contract.ends_at)}
                </span>
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

            <footer className="mt-auto grid gap-3 px-[18px] py-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <span className="min-w-0 flex-1">
                        <span className="eyebrow block">Valor contratado</span>
                        <span className="mono block truncate text-xs font-semibold text-[var(--ink-700)]">{money(contract.total_value, contract.currency)}</span>
                    </span>
                    {additiveCount > 0 && (
                        <button className="sig-pill sig-pill-amber cursor-pointer" type="button" onClick={onHistory} title={latestAdditive?.title || 'Aditivo vigente'}>
                            <FilePlus2 size={12} />
                            Aditivo {latestAdditive?.sequence_number || additiveCount}
                            <span className="hidden sm:inline"> · {additiveTypeLabel[latestAdditive?.type] || 'Registrado'}</span>
                        </button>
                    )}
                </div>
                <div className="flex items-center justify-between gap-2">
                    <div>
                        {hasAttention && (
                            <span title="Contrato com pontos de atenção" className="text-[var(--amber)]">
                                <AlertTriangle size={15} />
                            </span>
                        )}
                    </div>
                    <div className="flex flex-wrap justify-end gap-2">
                        {canManageContracts && (
                            <>
                                <button className="sig-btn sig-btn-secondary sig-btn-sm" type="button" onClick={onAdditive}>
                                    <FilePlus2 size={13} />
                                    Aditivo
                                </button>
                                <button className="sig-btn sig-btn-primary sig-btn-sm" type="button" onClick={onParametrize}>
                                    <Settings size={13} />
                                    Parametrizar
                                </button>
                            </>
                        )}
                        <Link className="sig-btn sig-btn-secondary sig-btn-sm" href={route('tenant.contracts.show', [tenant.slug, contract.id])}>
                            Abrir
                            <ArrowRight size={13} className="transition-transform group-hover:translate-x-0.5" />
                        </Link>
                    </div>
                </div>
            </footer>
        </article>
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
