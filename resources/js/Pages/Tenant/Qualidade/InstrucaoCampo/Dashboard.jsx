import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import ReactECharts from 'echarts-for-react';
import { AlertTriangle, BarChart3, CheckCircle2, ClipboardList, Clock3, SearchCheck, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Card, Errors } from './Ui';

const metrics = [
    ['all', 'Total de ICs', ClipboardList, 'text-blue-600 bg-blue-50'],
    ['resolved_yes', 'Resolvidas', CheckCircle2, 'text-green-700 bg-green-50'],
    ['notified', 'Aguardando evidências', Clock3, 'text-amber-700 bg-amber-50'],
    ['resolved_partial', 'Parcialmente resolvidas', SearchCheck, 'text-amber-700 bg-amber-50'],
    ['closed', 'Finalizadas', CheckCircle2, 'text-green-700 bg-green-50'],
    ['resolved_no', 'Não resolvidas', AlertTriangle, 'text-red-700 bg-red-50'],
];

function Bars({ rows, color = 'bg-blue-600' }) {
    const max = Math.max(1, ...rows.map((row) => row.total));
    return rows.length ? <div className="grid gap-4">{rows.map((row) => <div key={row.label} className="grid gap-2">
        <div className="flex items-center justify-between gap-3 text-sm"><span className="min-w-0 break-words">{row.label}</span><span className="shrink-0 font-semibold tabular-nums">{row.total}</span></div>
        <div className="h-2 overflow-hidden rounded-full bg-slate-100"><div className={`h-full rounded-full ${color}`} style={{ width: `${row.total / max * 100}%` }} /></div>
    </div>)}</div> : <p className="py-4 text-sm text-[var(--ink-500)]">Nenhuma IC registrada.</p>;
}

function ResolutionPie({ counts }) {
    const rows = [
        { name: 'Resolvidas', value: counts.resolved_yes || 0, color: '#15803d' },
        { name: 'Parcialmente resolvidas', value: counts.resolved_partial || 0, color: '#b45309' },
        { name: 'Não resolvidas', value: counts.resolved_no || 0, color: '#b91c1c' },
    ];
    const total = rows.reduce((sum, row) => sum + row.value, 0);
    return <div className="grid gap-4">
        {total ? <ReactECharts option={{
            color: rows.map((row) => row.color),
            aria: { enabled: true },
            tooltip: { trigger: 'item', renderMode: 'richText', formatter: '{b}: {c} ({d}%)' },
            series: [{ type: 'pie', radius: '82%', center: ['50%', '50%'],
                data: rows.filter((row) => row.value > 0).map((row) => ({ name: row.name, value: row.value, itemStyle: { color: row.color } })),
                label: { position: 'inside', formatter: '{d}%', color: '#fff', fontWeight: 600 },
                minShowLabelAngle: 12, itemStyle: { borderColor: '#fff', borderWidth: 2 },
                emphasis: { scaleSize: 6 },
            }],
        }} style={{ height: 260, width: '100%' }} notMerge />
            : <p className="flex min-h-48 items-center justify-center text-center text-sm text-[var(--ink-500)]">Nenhuma IC finalizada com resultado para estes filtros.</p>}
        <ul className="grid gap-3">{rows.map((row) => <li key={row.name} className="flex items-center justify-between gap-3 text-sm"><span className="flex min-w-0 items-center gap-2"><span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: row.color }} /><span>{row.name}</span></span><span className="shrink-0 tabular-nums"><strong>{row.value}</strong><span className="ml-2 text-[var(--ink-500)]">{(total ? row.value / total * 100 : 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%</span></span></li>)}</ul>
        <p className="text-xs text-[var(--ink-500)]">{total} IC(s) finalizada(s) com resultado informado.</p>
    </div>;
}

export default function Dashboard({ tenant, contracts, obras, disciplinas, filters, counts, statuses, months, byContract }) {
    const [values, setValues] = useState(filters);
    const current = useRef(filters);
    const cancel = useRef(null);
    const errors = usePage().props.errors;
    useEffect(() => { current.current = filters; setValues(filters); }, [filters]);
    useEffect(() => () => cancel.current?.cancel(), []);
    const apply = (changes, reset = false) => {
        cancel.current?.cancel();
        const next = reset ? {} : { ...current.current, ...changes };
        current.current = next;
        setValues(next);
        router.get(route('tenant.qualidade.ic.dashboard', tenant.slug), Object.fromEntries(Object.entries(next).filter(([, value]) => value !== '' && value != null)), {
            preserveScroll: true, preserveState: true, replace: true,
            onCancelToken: (token) => { cancel.current = token; },
        });
    };
    const options = (items) => items.filter((item) => !values.contract_id || String(item.contract_id) === String(values.contract_id));
    return <AuthenticatedLayout><Head title="Dashboard IC" /><main className="sig-content quality-mobile-page grid gap-6">
        <header className="flex flex-wrap items-start justify-between gap-4">
            <div className="min-w-0"><div className="eyebrow mb-2 flex items-center gap-2"><BarChart3 size={15} />Instruções de Campo</div><h1 className="text-2xl font-semibold">Dashboard IC</h1><p className="mt-1 text-sm text-[var(--ink-500)]">Acompanhe a comunicação, as evidências e os resultados das instruções de campo.</p></div>
        </header>
        <section aria-label="Filtros do dashboard IC" className="sig-card grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-3">
            <label className="grid min-w-0 gap-2 text-sm font-medium">Contrato<select className="sig-input w-full min-w-0" value={values.contract_id || ''} onChange={(event) => apply({ contract_id: event.target.value, obra_id: '', disciplina_id: '' })}><option value="">Todos os contratos</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} · {contract.name}</option>)}</select></label>
            <label className="grid min-w-0 gap-2 text-sm font-medium">Obra<select className="sig-input w-full min-w-0" value={values.obra_id || ''} onChange={(event) => apply({ obra_id: event.target.value })}><option value="">Todas as obras</option>{options(obras).map((obra) => <option key={obra.id} value={obra.id}>{obra.codigo} · {obra.nome}</option>)}</select></label>
            <label className="grid min-w-0 gap-2 text-sm font-medium">Disciplina<select className="sig-input w-full min-w-0" value={values.disciplina_id || ''} onChange={(event) => apply({ disciplina_id: event.target.value })}><option value="">Todas as disciplinas</option>{options(disciplinas).map((disciplina) => <option key={disciplina.id} value={disciplina.id}>{disciplina.nome}</option>)}</select></label>
            <label className="grid min-w-0 gap-2 text-sm font-medium">Gravidade<select className="sig-input w-full min-w-0" value={values.gravidade || ''} onChange={(event) => apply({ gravidade: event.target.value })}><option value="">Todas as gravidades</option>{['Leve', 'Média', 'Grave', 'Gravíssima'].map((value) => <option key={value}>{value}</option>)}</select></label>
            <label className="grid min-w-0 gap-2 text-sm font-medium">Abertura: de<input type="date" className="sig-input w-full min-w-0" value={values.date_from || ''} max={values.date_to || undefined} onChange={(event) => apply({ date_from: event.target.value })} /></label>
            <label className="grid min-w-0 gap-2 text-sm font-medium">Abertura: até<input type="date" className="sig-input w-full min-w-0" value={values.date_to || ''} min={values.date_from || undefined} onChange={(event) => apply({ date_to: event.target.value })} /></label>
            {Object.values(values).some((value) => value !== '' && value != null) && <div className="sm:col-span-2 xl:col-span-3"><button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" onClick={() => apply({}, true)}><X size={14} />Limpar filtros</button></div>}
        </section>
        <Errors errors={errors} />
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">{metrics.map(([key, label, Icon, tone]) => <section key={key} className="sig-card flex min-w-0 items-center gap-3 p-4"><span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${tone}`}><Icon size={20} /></span><div className="min-w-0"><p className="text-xs text-[var(--ink-500)]">{label}</p><p className="mt-1 text-2xl font-semibold tabular-nums">{counts[key] || 0}</p></div></section>)}</div>
        <div className="grid items-start gap-5 lg:grid-cols-2">
            <Card title="Resultado das ICs finalizadas"><ResolutionPie counts={counts} /></Card>
            <Card title="Situação das ICs"><Bars rows={Object.entries(statuses).map(([key, label]) => ({ label, total: counts[key] || 0 }))} /></Card>
            <Card title="Aberturas nos últimos 6 meses"><Bars rows={months} /></Card>
            <Card title="ICs por contrato"><Bars rows={byContract} color="bg-indigo-500" /><p className="mt-4 text-xs text-[var(--ink-500)]">Até 10 contratos com mais registros.</p></Card>
        </div>
    </main></AuthenticatedLayout>;
}
