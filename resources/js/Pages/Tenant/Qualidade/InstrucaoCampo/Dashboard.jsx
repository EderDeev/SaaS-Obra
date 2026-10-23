import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, BarChart3, CheckCircle2, ClipboardList, Clock3, FilePenLine, SearchCheck } from 'lucide-react';
import { Card, Status, shortDate } from './Ui';

const metrics = [
    ['all', 'Total de ICs', ClipboardList, 'text-blue-600 bg-blue-50'],
    ['draft', 'Rascunhos', FilePenLine, 'text-slate-600 bg-slate-100'],
    ['notified', 'Aguardando correção', Clock3, 'text-amber-700 bg-amber-50'],
    ['correction_submitted', 'Aguardando verificação', SearchCheck, 'text-blue-600 bg-blue-50'],
    ['closed', 'Resolvidas', CheckCircle2, 'text-green-700 bg-green-50'],
    ['rnc_required', 'Requerem RNC', AlertTriangle, 'text-red-700 bg-red-50'],
];

function Bars({ rows, color = 'bg-blue-600' }) {
    const max = Math.max(1, ...rows.map((row) => row.total));
    return rows.length ? <div className="grid gap-4">{rows.map((row) => <div key={row.label} className="grid gap-2">
        <div className="flex items-center justify-between gap-3 text-sm"><span className="min-w-0 break-words">{row.label}</span><span className="shrink-0 font-semibold tabular-nums">{row.total}</span></div>
        <div className="h-2 overflow-hidden rounded-full bg-slate-100"><div className={`h-full rounded-full ${color}`} style={{ width: `${row.total / max * 100}%` }} /></div>
    </div>)}</div> : <p className="py-4 text-sm text-[var(--ink-500)]">Nenhuma IC registrada.</p>;
}

export default function Dashboard({ tenant, contracts, filters, counts, statuses, months, byContract, recent }) {
    return <AuthenticatedLayout><Head title="Dashboard IC" /><main className="sig-content quality-mobile-page grid gap-6">
        <header className="flex flex-wrap items-start justify-between gap-4">
            <div className="min-w-0"><div className="eyebrow mb-2 flex items-center gap-2"><BarChart3 size={15} />Instruções de Campo</div><h1 className="text-2xl font-semibold">Dashboard IC</h1><p className="mt-1 text-sm text-[var(--ink-500)]">Acompanhe as correções e os resultados das instruções de campo.</p></div>
            <label className="grid w-full gap-2 text-sm font-medium sm:w-80">Contrato<select className="sig-input min-w-0" value={filters.contract_id || ''} onChange={(event) => router.get(route('tenant.qualidade.ic.dashboard', tenant.slug), event.target.value ? { contract_id: event.target.value } : {}, { preserveScroll: true, replace: true })}><option value="">Todos os contratos</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} · {contract.name}</option>)}</select></label>
        </header>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">{metrics.map(([key, label, Icon, tone]) => <section key={key} className="sig-card flex min-w-0 items-center gap-3 p-4"><span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${tone}`}><Icon size={20} /></span><div className="min-w-0"><p className="text-xs text-[var(--ink-500)]">{label}</p><p className="mt-1 text-2xl font-semibold tabular-nums">{counts[key] || 0}</p></div></section>)}</div>
        <div className="grid items-start gap-5 lg:grid-cols-2 xl:grid-cols-3">
            <Card title="Situação das ICs"><Bars rows={Object.entries(statuses).map(([key, label]) => ({ label, total: counts[key] || 0 }))} /></Card>
            <Card title="Aberturas nos últimos 6 meses"><Bars rows={months} /></Card>
            <Card title="ICs por contrato"><Bars rows={byContract} color="bg-indigo-500" /><p className="mt-4 text-xs text-[var(--ink-500)]">Até 10 contratos com mais registros.</p></Card>
        </div>
        <Card title="Últimas ICs registradas"><div className="grid gap-3">{recent.map((ic) => <article key={ic.id} className="flex min-w-0 flex-wrap items-center justify-between gap-4 rounded-xl border border-[var(--border)] p-4">
            <div className="min-w-0"><span className="font-mono text-xs font-bold text-blue-600">IC {ic.formatted_number}</span><p className="mt-1 break-words text-sm font-medium">{ic.contract?.code} · {ic.obra?.nome}</p><p className="mt-1 text-xs text-[var(--ink-500)]">Abertura em {shortDate(ic.opened_at)}</p></div>
            <div className="flex flex-wrap items-center gap-3"><Status value={ic.status} statuses={statuses} />{ic.can_view && <Link href={route('tenant.qualidade.ic.show', [tenant.slug, ic.id])} className="sig-btn sig-btn-secondary sig-btn-sm">Acessar<ArrowRight size={14} /></Link>}</div>
        </article>)}{!recent.length && <p className="py-4 text-sm text-[var(--ink-500)]">Nenhuma IC encontrada.</p>}</div></Card>
    </main></AuthenticatedLayout>;
}
