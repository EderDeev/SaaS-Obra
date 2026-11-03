import { Search } from 'lucide-react';
import { useId, useState } from 'react';

const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

export default function RdoCatalogPicker({ title, items, records, onChange, equipment = false, disabled = false }) {
    const [query, setQuery] = useState('');
    const id = useId();
    const terms = normalize(query).trim().split(/\s+/).filter(Boolean);
    const matches = items.filter(item => terms.every(term => normalize(`${item.code || ''} ${item.name} ${item.description || ''}`).includes(term)));
    const selected = Object.keys(records);
    const setRecord = (key, value) => onChange({ ...records, [key]: value });
    const toggle = item => {
        if (Object.hasOwn(records, item.id)) {
            const next = { ...records };
            delete next[item.id];
            onChange(next);
        } else setRecord(item.id, equipment ? { quantidade: '1', situacao: 'operando' } : '1');
    };
    return <section aria-label={title} className="overflow-hidden rounded-xl border border-blue-200 bg-white">
        <div className="border-b border-slate-100 p-3">
            <label htmlFor={`${id}-search`} className="mb-2 block text-xs font-semibold">Pesquisar {title.toLocaleLowerCase('pt-BR')}</label>
            <div className="relative"><Search size={16} className="pointer-events-none absolute left-3 top-3 text-slate-400" /><input id={`${id}-search`} type="search" className="sig-input !pl-9" placeholder="Código, nome, classificação ou unidade" value={query} onChange={event => setQuery(event.target.value)} onKeyDown={event => { if (event.key === 'Enter') event.preventDefault(); }} /></div>
            <p className="mt-2 text-xs text-[var(--ink-500)]">Marque os itens utilizados nesta frente e informe as quantidades.</p>
        </div>
        <fieldset disabled={disabled} className="max-h-80 divide-y divide-slate-100 overflow-y-auto"><legend className="sr-only">{title} disponíveis neste contrato</legend>
            {matches.map(item => {
                const checked = Object.hasOwn(records, item.id);
                const record = records[item.id];
                return <div key={item.id} className={`flex flex-wrap items-center gap-3 px-4 py-3 ${checked ? 'bg-blue-50' : 'hover:bg-slate-50'}`}>
                    <label className="flex min-w-0 flex-1 basis-48 cursor-pointer items-center gap-3">
                        <input type="checkbox" className="shrink-0 rounded border-slate-300 text-blue-600" checked={checked} onChange={() => toggle(item)} />
                        <span className="min-w-0"><span className="block break-words text-sm font-semibold">{item.code && <span className="mr-2 font-mono text-[var(--primary)]">{item.code}</span>}{item.name}</span><span className="mt-1 block text-xs text-[var(--ink-500)]">{item.description}</span></span>
                    </label>
                    {checked && <div className="flex flex-wrap items-center gap-2">
                        <label className="text-xs text-[var(--ink-500)]">Quantidade<input aria-label={`Quantidade de ${item.name}`} type="number" min="0" step={equipment ? '0.01' : '1'} className="sig-input mt-1 !w-24" value={(equipment ? record?.quantidade : record) ?? ''} onChange={event => setRecord(item.id, equipment ? { ...record, quantidade: event.target.value } : event.target.value)} /></label>
                        {equipment && <label className="text-xs text-[var(--ink-500)]">Situação<select aria-label={`Situação de ${item.name}`} className="sig-input mt-1 !w-36" value={record?.situacao || ''} onChange={event => setRecord(item.id, { ...record, situacao: event.target.value })}><option value="">Selecionar</option><option value="operando">Operando</option><option value="parado">Parado</option><option value="manutencao">Manutenção</option></select></label>}
                    </div>}
                </div>;
            })}
            {!matches.length && <p className="px-4 py-6 text-center text-sm text-[var(--ink-500)]">Nenhum item encontrado.</p>}
        </fieldset>
        <p role="status" className="border-t border-slate-100 px-4 py-2 text-xs text-[var(--ink-500)]">{matches.length} encontrado(s) · {selected.length} selecionado(s) · Total: {selected.reduce((sum, key) => sum + (Number(equipment ? records[key]?.quantidade : records[key]) || 0), 0).toLocaleString('pt-BR')}</p>
    </section>;
}
