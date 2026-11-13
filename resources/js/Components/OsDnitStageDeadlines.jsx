import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import ReactECharts from 'echarts-for-react';
import { RefreshCw, X } from 'lucide-react';

const stages = { demand: 'Demanda', supervision: 'Análise Supervisão', inspector: 'Fiscal do contrato', delivery: 'Entrega do produto', evaluation: 'Avaliação do analista DNIT' };
const outcomes = { attended: { label: 'Atendido', color: '#059669' }, missed: { label: 'Não atendido', color: '#dc2626' }, pending: { label: 'Em andamento', color: '#0b5fff' }, unapproved: { label: 'Sem prazo aprovado', color: '#94a3b8' } };
const dateTime = (value) => value ? new Date(value).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', dateStyle: 'short', timeStyle: 'short' }) : 'Em andamento';
export const duration = (minutes) => {
    if (minutes === null || minutes === undefined) return '—';
    return `${Math.floor(minutes / 1440)} dia(s) · ${Math.floor(minutes % 1440 / 60)}h ${minutes % 60}min`;
};
export function stageDeadlineOption(totals) {
    const rows = Object.entries(outcomes).map(([key, outcome]) => ({ name: outcome.label, value: totals[key] || 0, outcome: key, itemStyle: { color: outcome.color } }));
    return {
        aria: { enabled: true }, tooltip: { trigger: 'item', confine: true, formatter: '{b}: {c} OSs ({d}%)' },
        series: [{ type: 'pie', radius: ['0%', '72%'], center: ['50%', '50%'],
            itemStyle: { borderWidth: 3, borderColor: '#fff' }, label: { formatter: '{b}\n{d}%', fontSize: 12, color: '#475569' },
            data: rows.some((row) => row.value) ? rows.filter((row) => row.value) : [{ name: 'Sem registros', value: 1, itemStyle: { color: '#eef2f6' }, label: { show: false }, tooltip: { show: false } }],
        }],
    };
}

function StageOrdersModal({ items, stage, outcome, calculatedAt, onClose }) {
    const dialog = useRef(null);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    useEffect(() => {
        const previous = document.activeElement;
        const overflow = document.body.style.overflow;
        const element = dialog.current;
        element.showModal(); document.body.style.overflow = 'hidden';
        return () => { element.close(); document.body.style.overflow = overflow; if (previous?.isConnected) previous.focus(); };
    }, []);
    const term = search.trim().toLocaleLowerCase('pt-BR');
    const visible = items.filter((item) => !term || `${item.number} ${item.subject} ${item.contract}`.toLocaleLowerCase('pt-BR').includes(term));
    const pages = Math.max(1, Math.ceil(visible.length / 10));
    return <dialog ref={dialog} aria-labelledby="stage-orders-title" onCancel={(event) => { event.preventDefault(); onClose(); }} className="os-dnit-dialog fixed inset-0 !m-auto max-h-[92dvh] w-[96vw] max-w-5xl overflow-hidden rounded-2xl border-0 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-900/40 backdrop:backdrop-blur-sm open:flex open:flex-col">
        <header className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 p-5"><div><p className="text-xs font-semibold" style={{ color: outcomes[outcome].color }}>{stages[stage]} · Prazo {outcomes[outcome].label.toLocaleLowerCase('pt-BR')}</p><h2 id="stage-orders-title" className="mt-1 text-xl font-semibold">Ordens de Serviço ({items.length})</h2><p className="mt-1 text-xs text-slate-500">Cálculo de {dateTime(calculatedAt)}</p></div><button type="button" aria-label="Fechar detalhes dos prazos" onClick={onClose} className="rounded-lg p-2 hover:bg-slate-100"><X size={20} /></button></header>
        <div className="shrink-0 border-b border-slate-100 p-4"><input aria-label="Pesquisar OS nos detalhes dos prazos" placeholder="Buscar por número, assunto ou contrato" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} className="w-full rounded-lg border-slate-300 text-sm" /></div>
        <div className="min-h-0 space-y-4 overflow-y-auto p-5">{visible.slice((page - 1) * 10, page * 10).map((item) => <article key={item.id} className="rounded-xl border border-slate-200 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3"><div><strong className="font-mono text-sm text-blue-700">OS {item.number}</strong>{item.revision > 0 && <span className="ml-2 text-xs text-slate-500">Revisão {item.revision}</span>}<h3 className="mt-1 font-semibold">{item.subject}</h3><p className="mt-1 text-xs text-slate-500">Contrato {item.contract}{item.responsible ? ` · ${item.responsible}` : ''}</p></div><a href={item.url} target="_blank" rel="noopener noreferrer" className="sig-btn sig-btn-secondary">Acessar OS</a></div>
            <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">{[['Entrada na etapa', dateTime(item.started_at)], ['Prazo aprovado pelo fiscal', item.deadline_at ? dateTime(item.deadline_at) : 'Sem prazo aprovado'], ['Conclusão da etapa', dateTime(item.finished_at)], ['Tempo decorrido na etapa', duration(item.elapsed_minutes)]].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 font-medium">{value}</dd></div>)}</dl>
            {item.delivered_at && <dl className="mt-4 grid gap-4 rounded-lg bg-blue-50 p-4 text-sm sm:grid-cols-3"><div><dt className="text-xs text-slate-500">Produto entregue em</dt><dd className="mt-1 font-medium">{dateTime(item.delivered_at)}</dd></div><div><dt className="text-xs text-slate-500">{item.evaluation_result === 'approve' ? 'Aprovado pelo analista em' : item.evaluation_result === 'reject' ? 'Reprovado pelo analista em' : 'Avaliação do analista'}</dt><dd className="mt-1 font-medium">{item.evaluated_at ? dateTime(item.evaluated_at) : 'Aguardando avaliação'}</dd></div><div><dt className="text-xs text-slate-500">{item.evaluated_at ? 'Tempo até a avaliação' : 'Tempo aguardando avaliação'}</dt><dd className="mt-1 font-medium">{duration(item.evaluation_minutes)}</dd></div></dl>}
        </article>)}{!visible.length && <p className="py-8 text-center text-sm text-slate-500">Nenhuma OS encontrada.</p>}</div>
        <footer className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4"><p className="text-xs text-slate-500">{visible.length} OS(s) · Página {page} de {pages}</p><div className="flex gap-2"><button type="button" disabled={page === 1} onClick={() => setPage(page - 1)} className="sig-btn sig-btn-secondary">Anterior</button><button type="button" disabled={page >= pages} onClick={() => setPage(page + 1)} className="sig-btn sig-btn-secondary">Próxima</button><button type="button" onClick={onClose} className="sig-btn sig-btn-primary">Fechar</button></div></footer>
    </dialog>;
}

export default function OsDnitStageDeadlines({ tenantSlug, contracts, initialFilters }) {
    const [filters, setFilters] = useState({ contract_id: initialFilters.contract_id || '', stage: 'delivery', date_from: initialFilters.date_from, date_to: initialFilters.date_to });
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [reload, setReload] = useState(0);
    const [outcome, setOutcome] = useState(null);
    useEffect(() => {
        const controller = new AbortController();
        setOutcome(null); setError('');
        if (!filters.date_from || !filters.date_to || filters.date_to < filters.date_from) { setError('Informe um período válido.'); setLoading(false); return; }
        setLoading(true);
        const timer = setTimeout(() => axios.get(route('tenant.os-dnit.dashboard.stage-deadlines', tenantSlug), { params: filters, signal: controller.signal })
            .then((response) => { if (!controller.signal.aborted) setData(response.data); })
            .catch((exception) => { if (!controller.signal.aborted) setError(exception.response?.status === 422 ? Object.values(exception.response.data.errors || {}).flat().join(' ') : 'Não foi possível consultar os prazos desta etapa.'); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); }), 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [filters.contract_id, filters.stage, filters.date_from, filters.date_to, reload]);
    const setFilter = (key, value) => setFilters((previous) => ({ ...previous, [key]: value }));
    const total = data ? Object.values(data.totals).reduce((sum, value) => sum + value, 0) : 0;
    return <section className="sig-card min-w-0 p-5 sm:p-6" aria-labelledby="stage-deadline-title">
        <header className="flex items-start justify-between gap-4"><div><h2 id="stage-deadline-title" className="text-lg font-semibold">Prazos por etapa</h2><p className="mt-1 text-xs text-slate-500">Última passagem de cada OS pela etapa no período de entrada selecionado. Clique em uma fatia para ver as OSs.</p></div><button type="button" disabled={loading} onClick={() => setReload(reload + 1)} className="sig-btn sig-btn-secondary" aria-label="Atualizar gráfico de prazos por etapa"><RefreshCw size={16} /></button></header>
        <div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><label className="text-xs font-semibold text-slate-600">Etapa<select value={filters.stage} onChange={(event) => setFilter('stage', event.target.value)} className="mt-2 w-full rounded-lg border-slate-300 text-sm">{Object.entries(stages).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label><label className="text-xs font-semibold text-slate-600">Contrato<select value={filters.contract_id} onChange={(event) => setFilter('contract_id', event.target.value)} className="mt-2 w-full rounded-lg border-slate-300 text-sm"><option value="">Todos os contratos</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} — {contract.name}</option>)}</select></label><label className="text-xs font-semibold text-slate-600">Entrada na etapa: de<input type="date" value={filters.date_from} onChange={(event) => setFilter('date_from', event.target.value)} className="mt-2 w-full rounded-lg border-slate-300 text-sm" /></label><label className="text-xs font-semibold text-slate-600">Até<input type="date" min={filters.date_from} value={filters.date_to} onChange={(event) => setFilter('date_to', event.target.value)} className="mt-2 w-full rounded-lg border-slate-300 text-sm" /></label></div>
        {error && <p role="alert" className="mt-4 rounded-lg bg-red-50 p-4 text-sm text-red-800">{error}</p>}
        <div aria-busy={loading} className={`mt-4 grid items-center gap-6 lg:grid-cols-2 ${loading ? 'opacity-50' : ''}`}><div>{data && <ReactECharts option={stageDeadlineOption(data.totals)} notMerge opts={{ renderer: 'svg' }} style={{ height: 310, width: '100%' }} onEvents={{ click: ({ data: selected }) => { if (!loading && !error && selected.outcome) setOutcome(selected.outcome); } }} />}{loading && <p role="status" className="text-center text-sm text-slate-500">Consultando prazos…</p>}</div><div><p className="text-sm font-semibold">{total} OS(s) em {stages[filters.stage]}</p><div className="mt-4 space-y-3">{Object.entries(outcomes).map(([key, entry]) => <button type="button" key={key} disabled={loading || !data || Boolean(error)} onClick={() => setOutcome(key)} className="flex w-full items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 text-left hover:bg-slate-50"><span className="flex items-center gap-2 text-sm"><span className="h-3 w-3 rounded-full" style={{ backgroundColor: entry.color }} />{entry.label}</span><strong className="tabular-nums">{data?.totals[key] ?? 0}<span className="ml-2 text-xs font-normal text-slate-500">{total ? Math.round(data.totals[key] / total * 100) : 0}%</span></strong></button>)}</div>{data && <><p className="mt-4 text-xs leading-relaxed text-slate-500">{data.deadline_reference}</p><p className="mt-2 text-xs text-slate-500">Atualizado em {dateTime(data.calculated_at)}.</p></>}</div></div>
        {outcome && data && <StageOrdersModal items={data.items.filter((item) => item.outcome === outcome)} stage={filters.stage} outcome={outcome} calculatedAt={data.calculated_at} onClose={() => setOutcome(null)} />}
    </section>;
}
