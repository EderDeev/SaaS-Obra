import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';

const types = [['text', 'Texto curto'], ['textarea', 'Texto longo'], ['number', 'Número'], ['date', 'Data'], ['select', 'Seleção'], ['boolean', 'Sim/Não']];

export default function ModelFieldsEditor({ fields, onChange, errors = {} }) {
    const update = (index, changes) => onChange(fields.map((field, position) => position === index ? { ...field, ...changes } : field));
    function move(index, direction) {
        const next = [...fields];
        [next[index], next[index + direction]] = [next[index + direction], next[index]];
        onChange(next);
    }
    return <section className="sig-card grid gap-4 p-5">
        <div><h2 className="text-base font-semibold">Campos preenchidos em cada RNC</h2><p className="mt-2 text-sm text-[var(--ink-500)]">Os campos do modelo padrão continuam disponíveis. Cadastre aqui apenas as informações adicionais deste modelo.</p></div>
        {!fields.length && <p className="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">Este modelo usa somente os campos padrão.</p>}
        {fields.map((field, index) => {
            const builtin = ['work_type', 'pavement_type'].includes(field.key);
            const fieldError = Object.entries(errors).filter(([key]) => key.startsWith(`field_definitions.${index}.`));
            return <article key={field.key} className="grid gap-3 rounded-xl border border-[var(--border)] p-4">
                <div className="flex items-center justify-between gap-3"><span className="text-xs font-medium text-slate-500">Campo {index + 1} · Cabeçalho do documento</span>
                    <div className="flex gap-1"><button type="button" className="rounded p-2 hover:bg-slate-100 disabled:opacity-30" disabled={index === 0} aria-label={`Mover ${field.label} para cima`} onClick={() => move(index, -1)}><ArrowUp size={15} /></button><button type="button" className="rounded p-2 hover:bg-slate-100 disabled:opacity-30" disabled={index === fields.length - 1} aria-label={`Mover ${field.label} para baixo`} onClick={() => move(index, 1)}><ArrowDown size={15} /></button>{!builtin && <button type="button" className="rounded p-2 text-red-600 hover:bg-red-50" aria-label={`Remover ${field.label}`} onClick={() => onChange(fields.filter((_, position) => position !== index))}><Trash2 size={15} /></button>}</div>
                </div>
                <div className="grid gap-3 sm:grid-cols-2"><label className="grid gap-2 text-sm">Nome do campo<input className="sig-input w-full" disabled={builtin} maxLength={120} value={field.label} onChange={(event) => update(index, { label: event.target.value })} required /></label><label className="grid gap-2 text-sm">Tipo<select className="sig-input w-full" disabled={builtin} value={field.type} onChange={(event) => update(index, { type: event.target.value, options: event.target.value === 'select' ? ['Opção 1'] : [] })}>{types.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label></div>
                {field.type === 'select' && <label className="grid gap-2 text-sm">Opções (uma por linha)<textarea className="sig-input w-full" rows={Math.min(5, Math.max(2, field.options.length))} disabled={builtin} value={field.options.join('\n')} onChange={(event) => update(index, { options: event.target.value.split('\n') })} /></label>}
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={field.required} onChange={(event) => update(index, { required: event.target.checked })} />Preenchimento obrigatório</label>
                {fieldError.map(([key, error]) => <p key={key} role="alert" className="text-xs text-red-600">{error}</p>)}
            </article>;
        })}
        {errors.field_definitions && <p role="alert" className="text-sm text-red-600">{errors.field_definitions}</p>}
        <button type="button" className="sig-btn sig-btn-secondary justify-self-start" disabled={fields.length >= 30} onClick={() => onChange([...fields, { key: `campo_${crypto.randomUUID().replaceAll('-', '').slice(0, 20)}`, label: '', type: 'text', required: false, options: [] }])}><Plus size={15} />Adicionar campo</button>
    </section>;
}
