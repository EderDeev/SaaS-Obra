import { Link } from '@inertiajs/react';
import { Activity, AlertCircle, ArrowUpRight, Calculator, CalendarDays, ClipboardList, FileCheck2, FileText, FolderKanban, HardHat, ShieldCheck } from 'lucide-react';

const modules = {
    rdos: { icon: HardHat, color: 'blue' },
    os: { icon: ClipboardList, color: 'blue' },
    rncs: { icon: AlertCircle, color: 'orange' },
    ics: { icon: ShieldCheck, color: 'amber' },
    projects: { icon: FolderKanban, color: 'purple' },
    activities: { icon: Activity, color: 'green' },
    documents: { icon: FileText, color: 'blue' },
    measurements: { icon: FileCheck2, color: 'green' },
    budgets: { icon: Calculator, color: 'amber' },
    serviceOrders: { icon: ClipboardList, color: 'purple' },
};
const tones = {
    blue: 'bg-blue-50 text-blue-700 border-blue-100',
    orange: 'bg-orange-50 text-orange-700 border-orange-100',
    green: 'bg-emerald-50 text-emerald-700 border-emerald-100',
    purple: 'bg-violet-50 text-violet-700 border-violet-100',
    amber: 'bg-amber-50 text-amber-800 border-amber-100',
    red: 'bg-red-50 text-red-700 border-red-100',
    neutral: 'bg-slate-50 text-slate-700 border-slate-100',
};

export default function ContractModuleCard({ moduleKey, module, href }) {
    const { icon: Icon, color } = modules[moduleKey] || modules.documents;
    const latestDate = module.latest_date
        ? new Date(`${String(module.latest_date).slice(0, 10)}T12:00:00`).toLocaleDateString('pt-BR') : null;

    return (
        <article className="sig-card overflow-hidden">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--line)] px-5 py-4">
                <div className="flex items-center gap-3">
                    <span className={`flex h-10 w-10 items-center justify-center rounded-xl ${tones[color]}`}><Icon size={21} /></span>
                    <h3 className="text-base font-semibold text-[var(--ink-900)]">{module.label}</h3>
                </div>
                <Link href={href} className="sig-btn sig-btn-secondary gap-2 text-xs">Acessar módulo <ArrowUpRight size={15} /></Link>
            </header>
            <div className="px-5 py-5">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span className="text-4xl font-semibold tracking-tight text-[var(--ink-900)]">{module.pending}</span>
                    <span className="text-sm text-[var(--ink-500)]">{module.headline}</span>
                </div>
                <dl className="mt-5 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {(module.metrics || []).map((metric) => (
                        <div key={metric.label} className={`flex items-center justify-between gap-3 rounded-lg border px-3 py-3 ${tones[metric.tone] || tones.neutral}`}>
                            <dt className="text-xs font-medium leading-relaxed">{metric.label}</dt>
                            <dd className="text-lg font-semibold tabular-nums">{metric.value}</dd>
                        </div>
                    ))}
                </dl>
                {module.attention && <p className="mt-4 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-xs leading-relaxed text-amber-900"><AlertCircle size={15} className="mt-0.5 shrink-0" />{module.attention}</p>}
            </div>
            <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--line)] px-5 py-3 text-xs text-[var(--ink-500)]">
                <span>{module.count} {module.count === 1 ? 'registro no contrato' : 'registros no contrato'}</span>
                {latestDate && <span className="flex items-center gap-1.5"><CalendarDays size={13} />Último RDO: {latestDate}</span>}
            </footer>
        </article>
    );
}
