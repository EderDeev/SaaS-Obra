import { Link } from '@inertiajs/react';
import { ArrowRight, ClipboardList } from 'lucide-react';

const stages = [
    ['analyst', 'Demandas', 'bg-[var(--surface-muted)] text-[var(--ink-700)]'],
    ['concremat', 'Análise Supervisão', 'bg-[var(--primary-50)] text-[var(--primary)]'],
    ['contract_inspector', 'Fiscal do contrato', 'bg-[var(--amber-50)] text-[var(--amber)]'],
    ['approved', 'Aprovadas', 'bg-[var(--green-50)] text-[var(--green)]'],
    ['completed', 'Finalizadas', 'bg-[var(--surface-muted)] text-[var(--ink-700)]'],
];

function ordersUrl(tenant, contractId, stage = 'all') {
    return route('tenant.os-dnit.orders.index', {
        tenant: tenant.slug,
        ...(contractId ? { contract_id: contractId } : {}),
        stage,
    });
}

export default function OsDnitManagementCard({ tenant, summary, contractId }) {
    if (!summary) return null;

    return (
        <section className="sig-card min-w-0 overflow-hidden" aria-label="Resumo das Ordens de Serviço">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] p-4 sm:p-5">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="rounded-lg bg-[var(--primary-50)] p-2 text-[var(--primary)]"><ClipboardList size={20} /></span>
                    <div>
                        <h2 className="text-base font-semibold text-[var(--ink-900)]">Ordens de Serviço</h2>
                        <p className="text-xs text-[var(--ink-500)]">{summary.total} no total · {contractId ? 'Neste contrato' : 'Nos contratos com acesso'}</p>
                    </div>
                </div>
                <Link className="sig-btn sig-btn-secondary sig-btn-sm" href={ordersUrl(tenant, contractId)}>Ver OSs <ArrowRight size={14} /></Link>
            </header>
            <div className="p-4 sm:p-5">
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                    {stages.map(([stage, label, color]) => (
                        <Link key={stage} href={ordersUrl(tenant, contractId, stage)} className={`min-w-0 rounded-lg p-3 transition hover:ring-2 hover:ring-[var(--border-strong)] ${color}`}>
                            <span className="block text-2xl font-semibold tabular-nums">{summary.stages[stage]}</span>
                            <span className="mt-1 block text-xs font-medium">{label}</span>
                        </Link>
                    ))}
                </div>
                <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-[var(--ink-500)]">
                    <span><strong className="text-[var(--ink-700)]">{summary.pending}</strong> em análise</span>
                    <span><strong className="text-[var(--ink-700)]">{summary.returned}</strong> devolvida(s) para ajuste</span>
                    <span title="OSs enviadas e ainda não finalizadas, considerando o prazo da Supervisão ou, quando não definido, o prazo solicitado."><strong className={summary.overdue > 0 ? 'text-[var(--red)]' : 'text-[var(--ink-700)]'}>{summary.overdue}</strong> com prazo vencido</span>
                </div>
                <p className="mt-2 text-xs text-[var(--ink-500)]">Demandas e análises incluem devoluções. As aprovadas aguardam a conclusão do serviço.</p>
            </div>
            {summary.recent.length > 0 ? (
                <div className="border-t border-[var(--border)] px-4 pb-2 pt-3 sm:px-5">
                    <h3 className="mb-1 text-xs font-semibold text-[var(--ink-500)]">Atualizadas recentemente</h3>
                    {summary.recent.map((order) => (
                        <Link key={order.id} href={route('tenant.os-dnit.orders.show', [tenant.slug, order.id])} className="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-lg py-3 transition hover:bg-[var(--surface-muted)]">
                            <div className="min-w-0 flex-1 basis-40">
                                <span className="block text-xs font-semibold text-[var(--primary)]">OS {order.number}{!contractId && ` · ${order.contract}`}</span>
                                <span className="block truncate text-sm text-[var(--ink-900)]">{order.subject}</span>
                            </div>
                            <span className="max-w-full rounded-full bg-[var(--surface-muted)] px-2 py-1 text-xs text-[var(--ink-700)]">{order.status_label}</span>
                        </Link>
                    ))}
                </div>
            ) : <p className="border-t border-[var(--border)] p-4 text-sm text-[var(--ink-500)]">Nenhuma Ordem de Serviço cadastrada.</p>}
        </section>
    );
}

export function OsDnitContractSnapshot({ tenant, contract }) {
    const summary = contract.os_summary;
    if (!summary) return null;

    return (
        <Link href={ordersUrl(tenant, contract.id)} aria-label={`Ver Ordens de Serviço do contrato ${contract.code}`} className="block rounded-lg border border-[var(--border)] bg-[var(--primary-50)]/40 p-3 transition hover:border-[var(--primary)]">
            <span className="flex flex-wrap items-center justify-between gap-2 text-xs font-semibold text-[var(--primary)]"><span className="flex items-center gap-1.5"><ClipboardList size={14} /> Ordens de Serviço</span><span>{summary.total} <ArrowRight size={12} className="ml-1 inline" /></span></span>
            <span className="mt-1 block text-xs text-[var(--ink-500)]">{summary.pending} em análise · {summary.completed} finalizada(s)</span>
        </Link>
    );
}
