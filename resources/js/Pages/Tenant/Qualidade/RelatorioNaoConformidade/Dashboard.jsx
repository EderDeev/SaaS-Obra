import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, BarChart3, CheckCircle2, ClipboardX, Clock3, Gauge, SearchCheck } from 'lucide-react';

const shortDate = (date) => {
    if (!date) return '-';

    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date));
};

const statusClass = {
    aberta: 'sig-pill-blue',
    finalizada: 'sig-pill-green',
    excluida: 'sig-pill-red',
};

export default function RelatorioNaoConformidadeDashboard({
    tenant,
    metrics,
    statusCounts,
    gravidadeCounts,
    naturezaCounts,
    monthlyCounts,
    recentRncs = [],
    responseOverdueRncs = [],
    executionOverdueRncs = [],
}) {
    return (
        <AuthenticatedLayout>
            <Head title="Dashboard RNC" />

            <section className="sig-content">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <Gauge size={14} />
                            <span className="eyebrow">Relatorio Nao Conformidade</span>
                        </div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">Dashboard RNC</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Visao consolidada das RNCs em {tenant.name}
                        </p>
                    </div>
                    <Link href={route('tenant.qualidade.rnc.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                        Ver RNCs
                        <ArrowRight size={15} />
                    </Link>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    <MetricCard icon={ClipboardX} label="Total de RNCs" value={metrics.total} tone="blue" />
                    <MetricCard icon={AlertTriangle} label="Atraso resposta" value={metrics.atrasoResposta} tone="red" />
                    <MetricCard icon={Clock3} label="Atraso execucao" value={metrics.atrasoExecucao} tone="red" />
                    <MetricCard icon={SearchCheck} label="Em analise" value={metrics.emAnalise} tone="amber" />
                    <MetricCard icon={CheckCircle2} label="Finalizadas" value={metrics.finalizadas} tone="green" />
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
                    <DonutPanel title="Status das RNCs" counts={statusCounts} />
                    <ColumnPanel title="Aberturas nos ultimos 6 meses" counts={monthlyCounts} />
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-2">
                    <BarPanel title="Gravidade" counts={gravidadeCounts} />
                    <BarPanel title="Natureza" counts={naturezaCounts} />
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,.8fr)]">
                    <section className="sig-card overflow-hidden">
                        <header className="border-b border-[var(--border)] px-5 py-4">
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <Clock3 size={14} />
                                <span className="eyebrow">Recentes</span>
                            </div>
                            <h2 className="mt-1 text-[15px] font-semibold">Ultimas RNCs registradas</h2>
                        </header>
                        {recentRncs.length > 0 ? (
                            <table className="sig-table">
                                <thead>
                                    <tr>
                                        <th>RNC</th>
                                        <th>Obra</th>
                                        <th>Natureza</th>
                                        <th>Status</th>
                                        <th>Abertura</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recentRncs.map((rnc) => (
                                        <tr key={rnc.id}>
                                            <td>
                                                <Link href={route('tenant.qualidade.rnc.show', [tenant.slug, rnc.id])} className="mono font-semibold text-[var(--primary)]">
                                                    {rnc.formatted_number}
                                                </Link>
                                            </td>
                                            <td>
                                                <div className="font-semibold text-[var(--ink-900)]">{rnc.obra?.codigo || '-'}</div>
                                                <div className="text-xs text-[var(--ink-500)]">{rnc.obra?.nome || '-'}</div>
                                            </td>
                                            <td>{rnc.natureza}</td>
                                            <td>
                                                <span className={`sig-pill ${statusClass[rnc.status] || ''}`}>{rnc.status}</span>
                                            </td>
                                            <td>{shortDate(rnc.opened_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        ) : (
                            <EmptyState text="Nenhuma RNC cadastrada ainda." />
                        )}
                    </section>

                    <div className="grid gap-6">
                        <DelayPanel
                            tenant={tenant}
                            title="Resposta de acao corretiva vencida"
                            eyebrow="Atraso de resposta"
                            items={responseOverdueRncs}
                            type="response"
                        />
                        <DelayPanel
                            tenant={tenant}
                            title="Execucao da acao fora do prazo"
                            eyebrow="Atraso de execucao"
                            items={executionOverdueRncs}
                            type="execution"
                        />
                    </div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function DelayPanel({ tenant, title, eyebrow, items, type }) {
    const isExecution = type === 'execution';
    const emptyText = isExecution
        ? 'Nenhuma execucao atrasada no momento.'
        : 'Nenhuma resposta atrasada no momento.';

    return (
        <section className="sig-card overflow-hidden">
            <header className="border-b border-[var(--border)] px-5 py-4">
                <div className="flex items-center gap-2 text-[var(--ink-500)]">
                    <AlertTriangle size={14} />
                    <span className="eyebrow">{eyebrow}</span>
                </div>
                <h2 className="mt-1 text-[15px] font-semibold">{title}</h2>
            </header>
            {items.length > 0 ? (
                <div className="grid gap-3 p-4">
                    {items.map((rnc) => {
                        const latestAction = rnc.acoes_corretivas?.[0];
                        const approvedAction = latestApprovedAction(rnc);
                        const evidence = evidenceForAction(rnc, approvedAction);
                        const deadline = isExecution ? approvedAction?.prazo_execucao_proposto : rnc.prazo_resposta_acao_corretiva;
                        const completedLate = isExecution ? evidence?.submitted_at : latestAction?.submitted_at;
                        const badge = completedLate ? 'Fora do prazo' : 'Vencida';

                        return (
                            <Link
                                key={rnc.id}
                                href={route('tenant.qualidade.rnc.show', [tenant.slug, rnc.id])}
                                className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3 hover:border-[var(--primary)]"
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <span className="mono font-semibold text-[var(--ink-900)]">{rnc.formatted_number}</span>
                                    <span className="sig-pill sig-pill-red">{badge}</span>
                                </div>
                                <div className="mt-2 text-[13px] font-semibold text-[var(--ink-900)]">
                                    {rnc.obra?.codigo || '-'} - {rnc.obra?.nome || '-'}
                                </div>
                                <div className="mt-1 text-[12px] text-[var(--ink-500)]">
                                    {isExecution ? 'Prazo execucao' : 'Prazo resposta'}: {shortDate(deadline)}
                                </div>
                                {isExecution ? (
                                    <div className="mt-1 text-[12px] text-[var(--ink-500)]">
                                        Evidencia: {evidence?.submitted_at ? shortDate(evidence.submitted_at) : 'nao enviada'}
                                    </div>
                                ) : latestAction?.submitted_at ? (
                                    <div className="mt-1 text-[12px] text-[var(--ink-500)]">
                                        Resposta: {shortDate(latestAction.submitted_at)}
                                    </div>
                                ) : null}
                            </Link>
                        );
                    })}
                </div>
            ) : (
                <EmptyState text={emptyText} />
            )}
        </section>
    );
}

function latestApprovedAction(rnc) {
    return rnc.acoes_corretivas?.find((acao) => acao.status === 'approved');
}

function evidenceForAction(rnc, action) {
    return rnc.evidencias?.find((evidencia) => evidencia.relatorio_nao_conformidade_acao_corretiva_id === action?.id)
        || rnc.evidencias?.[0];
}

function MetricCard({ icon: Icon, label, value, tone }) {
    const tones = {
        blue: 'text-[var(--primary)] bg-[var(--blue-50)]',
        red: 'text-[var(--red)] bg-[var(--red-50)]',
        amber: 'text-[var(--amber)] bg-[var(--amber-50)]',
        green: 'text-[var(--green)] bg-[var(--green-50)]',
    };

    return (
        <section className="sig-card p-4">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <div className="eyebrow">{label}</div>
                    <div className="mt-2 text-3xl font-semibold text-[var(--ink-900)]">{value}</div>
                </div>
                <div className={`flex h-11 w-11 items-center justify-center rounded-lg ${tones[tone] || tones.blue}`}>
                    <Icon size={21} />
                </div>
            </div>
        </section>
    );
}

function BarPanel({ title, counts }) {
    const entries = Object.entries(counts || {});
    const max = Math.max(1, ...entries.map(([, value]) => value));

    return (
        <section className="sig-card p-5">
            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                <BarChart3 size={14} />
                <span className="eyebrow">{title}</span>
            </div>
            <div className="mt-4 grid gap-3">
                {entries.length > 0 ? entries.map(([label, value]) => (
                    <div key={label}>
                        <div className="flex items-center justify-between gap-3 text-[12.5px]">
                            <span className="font-semibold text-[var(--ink-800)]">{label}</span>
                            <span className="mono text-[var(--ink-500)]">{value}</span>
                        </div>
                        <div className="mt-1 h-2 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                            <div
                                className="h-full rounded-full bg-[var(--primary)]"
                                style={{ width: `${Math.max(8, (value / max) * 100)}%` }}
                            />
                        </div>
                    </div>
                )) : (
                    <div className="rounded-lg border border-dashed border-[var(--border-strong)] p-6 text-center text-sm text-[var(--ink-500)]">
                        Sem dados.
                    </div>
                )}
            </div>
        </section>
    );
}

const chartColors = ['#0b5fff', '#11805a', '#b58105', '#c8364a', '#64748b', '#7c3aed', '#0891b2'];

function DonutPanel({ title, counts }) {
    const entries = Object.entries(counts || {});
    const total = entries.reduce((sum, [, value]) => sum + value, 0);

    return (
        <section className="sig-card p-5">
            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                <BarChart3 size={14} />
                <span className="eyebrow">{title}</span>
            </div>
            {total > 0 ? (
                <div className="mt-4 grid gap-5 md:grid-cols-[180px_minmax(0,1fr)] md:items-center">
                    <DonutChart entries={entries} total={total} />
                    <div className="grid gap-2">
                        {entries.map(([label, value], index) => (
                            <div key={label} className="flex items-center justify-between gap-3 rounded-lg bg-[var(--surface-muted)] px-3 py-2 text-[12.5px]">
                                <span className="flex min-w-0 items-center gap-2">
                                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: chartColors[index % chartColors.length] }} />
                                    <span className="truncate font-semibold text-[var(--ink-800)]">{label}</span>
                                </span>
                                <span className="mono text-[var(--ink-500)]">{value}</span>
                            </div>
                        ))}
                    </div>
                </div>
            ) : (
                <EmptyState text="Sem dados para montar o grafico." />
            )}
        </section>
    );
}

function DonutChart({ entries, total }) {
    const radius = 70;
    const circumference = 2 * Math.PI * radius;
    let offset = 0;

    return (
        <div className="relative mx-auto h-[180px] w-[180px]">
            <svg viewBox="0 0 180 180" className="h-full w-full -rotate-90">
                <circle cx="90" cy="90" r={radius} fill="none" stroke="var(--surface-muted)" strokeWidth="22" />
                {entries.map(([label, value], index) => {
                    const length = (value / total) * circumference;
                    const segment = (
                        <circle
                            key={label}
                            cx="90"
                            cy="90"
                            r={radius}
                            fill="none"
                            stroke={chartColors[index % chartColors.length]}
                            strokeDasharray={`${length} ${circumference - length}`}
                            strokeDashoffset={-offset}
                            strokeLinecap="round"
                            strokeWidth="22"
                        />
                    );

                    offset += length;

                    return segment;
                })}
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <span className="text-3xl font-semibold text-[var(--ink-900)]">{total}</span>
                <span className="eyebrow mt-1 text-[var(--ink-500)]">RNCs</span>
            </div>
        </div>
    );
}

function ColumnPanel({ title, counts }) {
    const entries = Object.entries(counts || {});
    const max = Math.max(1, ...entries.map(([, value]) => value));

    return (
        <section className="sig-card p-5">
            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                <BarChart3 size={14} />
                <span className="eyebrow">{title}</span>
            </div>
            <div className="mt-5 flex h-56 items-end gap-3 border-b border-[var(--border)] px-1 pb-2">
                {entries.length > 0 ? entries.map(([label, value], index) => (
                    <div key={label} className="flex min-w-0 flex-1 flex-col items-center gap-2">
                        <span className="mono text-[11px] font-semibold text-[var(--ink-600)]">{value}</span>
                        <div className="flex h-40 w-full items-end rounded-t-lg bg-[var(--surface-muted)]">
                            <div
                                className="w-full rounded-t-lg"
                                style={{
                                    height: `${Math.max(value > 0 ? 10 : 0, (value / max) * 100)}%`,
                                    background: chartColors[index % chartColors.length],
                                }}
                            />
                        </div>
                        <span className="max-w-full truncate text-[11px] font-semibold text-[var(--ink-500)]">{label}</span>
                    </div>
                )) : (
                    <div className="flex h-full w-full items-center justify-center text-sm text-[var(--ink-500)]">
                        Sem dados para montar o grafico.
                    </div>
                )}
            </div>
        </section>
    );
}

function EmptyState({ text }) {
    return (
        <div className="p-10 text-center text-sm text-[var(--ink-500)]">
            {text}
        </div>
    );
}
