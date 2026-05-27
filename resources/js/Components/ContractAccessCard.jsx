import { Link } from '@inertiajs/react';
import { Building2, MapPin, Star } from 'lucide-react';

export default function ContractAccessCard({ tenant, contract, shortDate }) {
    const title = contract.obra?.nome || contract.name;
    const cliente = contract.cliente_empresa?.nome || contract.client_company_name || 'Cliente não informado';
    const construtora = contract.construtora_empresa?.nome || contract.contractor_company_name || 'Construtora não informada';
    const state = contract.state_label || contract.state;
    const location = contract.city && state
        ? `${contract.city} - ${state}`
        : contract.city || state || 'Local não informado';

    return (
        <Link
            href={route('tenant.contracts.show', [tenant.slug, contract.id])}
            className="sig-card group flex min-h-[300px] flex-col overflow-hidden transition hover:-translate-y-0.5 hover:border-[var(--border-strong)] hover:shadow-[var(--shadow-md)]"
        >
            <header className="flex items-center gap-3 px-[18px] pb-3 pt-4">
                <span
                    className="flex h-9 w-9 items-center justify-center rounded-lg text-base font-bold"
                    style={{ background: `${contract.color}14`, color: contract.color }}
                >
                    {contract.badge}
                </span>
                <div className="min-w-0 flex-1">
                    <div className="mono text-xs text-[var(--ink-500)]">{contract.code}</div>
                    <div className="truncate text-[14.5px] font-semibold text-[var(--ink-900)]">{title}</div>
                </div>
                <Star
                    size={16}
                    fill={contract.pinned ? 'currentColor' : 'none'}
                    className={contract.pinned ? 'text-[var(--amber)]' : 'text-[var(--ink-300)]'}
                />
            </header>

            <div className="grid gap-1.5 px-[18px] pb-4 text-[12.5px] text-[var(--ink-500)]">
                <div className="flex items-center gap-2">
                    <Building2 size={13} />
                    <span className="truncate">{cliente}</span>
                </div>
                <div className="flex items-center gap-2">
                    <MapPin size={13} />
                    <span className="truncate">{location}</span>
                </div>
                <div className="flex items-center gap-2">
                    <Building2 size={13} />
                    <span className="truncate">{construtora}</span>
                </div>
            </div>

            <div className="px-[18px] pb-4">
                <div className="mb-1.5 flex justify-between text-[11.5px] font-semibold uppercase tracking-[0.06em]">
                    <span className="text-[var(--ink-500)]">Avanço físico</span>
                    <span className="mono text-[var(--ink-900)]">{contract.physical}%</span>
                </div>
                <div className="sig-progress">
                    <i
                        style={{
                            width: `${contract.physical}%`,
                            background: contract.status === 'paused'
                                ? 'var(--amber)'
                                : contract.status === 'completed'
                                    ? 'var(--green)'
                                    : 'var(--primary)',
                        }}
                    />
                </div>

                <div className="mb-1.5 mt-3 flex justify-between text-[11.5px] font-semibold uppercase tracking-[0.06em]">
                    <span className="text-[var(--ink-500)]">Financeiro</span>
                    <span className="mono text-[var(--ink-900)]">{contract.financial}%</span>
                </div>
                <div className="sig-progress">
                    <i style={{ width: `${contract.financial}%`, background: 'var(--ink-700)' }} />
                </div>
            </div>

            <div className="px-[18px] pb-4">
                <span className={`sig-pill ${contract.meta.pill}`}>
                    <span className="sig-pill-dot" />
                    {contract.meta.label}
                </span>
            </div>

            <footer className="mt-auto flex items-center gap-3 border-t border-[var(--border)] bg-[var(--surface-muted)] px-[18px] py-3">
                <div className="min-w-0 flex-1">
                    <div className="eyebrow">Prazo</div>
                    <div className="truncate text-[12.5px] font-semibold text-[var(--ink-700)]">
                        {shortDate(contract.ends_at)}
                    </div>
                </div>
                <span className="sig-btn sig-btn-secondary sig-btn-sm">Acessar</span>
            </footer>
        </Link>
    );
}
