import { Check, ChevronDown, Search, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

export default function ReferencePicker({ title, searchLabel, placeholder, hint, options, selected, onChange, multiple = true, disabled = false, emptyMessage, error }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const root = useRef(null);
    const trigger = useRef(null);
    const input = useRef(null);
    const panelId = useId();
    const terms = normalize(query).trim().split(/\s+/).filter(Boolean);
    const matches = options.filter((option) => terms.every((term) => normalize(`${option.code} ${option.label} ${option.details || ''}`).includes(term)));
    const selectedOptions = options.filter((option) => selected.includes(option.value));

    useEffect(() => {
        if (!open) return;
        input.current?.focus();
        const closeOutside = (event) => {
            if (!root.current?.contains(event.target)) setOpen(false);
        };
        const closeOnEscape = (event) => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            setOpen(false);
            trigger.current?.focus();
        };
        document.addEventListener('pointerdown', closeOutside);
        document.addEventListener('keydown', closeOnEscape);
        return () => {
            document.removeEventListener('pointerdown', closeOutside);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [open]);

    const toggle = (value) => {
        if (selected.includes(value)) onChange(selected.filter((item) => item !== value));
        else onChange(multiple ? [...selected, value] : [value]);
    };

    return <section ref={root} aria-label={title} className="min-w-0 space-y-2">
        <h2 className="eyebrow">{title}</h2>
        <button ref={trigger} type="button" disabled={disabled} onClick={() => setOpen((current) => !current)} aria-expanded={open} aria-controls={panelId} aria-invalid={Boolean(error)} aria-describedby={error ? `${panelId}-error` : undefined}
            className="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50">
            <span>{multiple ? 'Selecionar projetos' : 'Selecionar IC'}{selected.length ? ` (${selected.length} selecionado${selected.length === 1 ? '' : 's'})` : ''}</span>
            <ChevronDown size={17} className={`shrink-0 ${open ? 'rotate-180' : ''}`} />
        </button>
        {open && <div id={panelId} className="overflow-hidden rounded-xl border border-blue-200 bg-white shadow-sm">
            <div className="border-b border-slate-100 p-3">
                <label htmlFor={`${panelId}-search`} className="mb-2 block text-xs font-semibold text-[var(--ink-700)]">{searchLabel}</label>
                <div className="relative"><Search size={16} className="pointer-events-none absolute left-3 top-3.5 text-slate-400" aria-hidden="true" /><input ref={input} id={`${panelId}-search`} type="search" value={query} onChange={(event) => setQuery(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') event.preventDefault(); }} placeholder={placeholder} className="min-h-11 w-full rounded-lg border-slate-300 pl-9 text-base sm:text-sm" /></div>
                <p className="mt-2 text-xs text-[var(--ink-500)]">{hint}</p>
            </div>
            <fieldset className="max-h-72 divide-y divide-slate-100 overflow-y-auto"><legend className="sr-only">{title}</legend>
                {matches.map((option) => {
                    const checked = selected.includes(option.value);
                    return <label key={option.value} className={`flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors ${checked ? 'bg-blue-50' : 'hover:bg-slate-50'}`}>
                        <input type={multiple ? 'checkbox' : 'radio'} name={multiple ? undefined : `${panelId}-choice`} checked={checked} disabled={disabled || (multiple && !checked && selected.length >= 50)} onChange={() => toggle(option.value)} className={`h-4 w-4 shrink-0 border-slate-300 text-blue-600 focus:ring-blue-500 ${multiple ? 'rounded' : ''}`} />
                        <span className="min-w-0 flex-1"><span className="block break-words text-sm font-semibold"><span className="mr-2 font-mono text-[var(--primary)]">{option.code}</span>{option.label}</span>{option.details && <span className="mt-1 block break-words text-xs text-[var(--ink-500)]">{option.details}</span>}</span>
                        {checked && <Check size={16} className="shrink-0 text-blue-600" aria-hidden="true" />}
                    </label>;
                })}
                {!matches.length && <p className="px-4 py-6 text-center text-sm text-[var(--ink-500)]">{query.trim() ? 'Nenhum resultado para esta busca.' : emptyMessage}</p>}
            </fieldset>
            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-4 py-2 text-xs text-[var(--ink-500)]"><p role="status">{matches.length} {multiple ? 'projeto(s)' : 'IC(s)'} encontrado(s) · {selected.length} selecionado(s)</p>{selected.length > 0 && <button type="button" disabled={disabled} onClick={() => onChange([])} className="min-h-8 font-semibold text-[var(--primary)]">Limpar seleção</button>}</div>
        </div>}
        {selectedOptions.length > 0 && <ul className="flex flex-wrap gap-2">{selectedOptions.map((option) => <li key={option.value} className="inline-flex max-w-full items-center gap-2 rounded-lg border border-blue-100 bg-blue-50 px-3 py-1 text-xs text-blue-900"><span className="min-w-0 break-words"><strong>{option.code}</strong> · {option.label}</span><button type="button" disabled={disabled} aria-label={`Remover ${option.code} ${option.label}`} onClick={() => toggle(option.value)} className="flex h-8 w-8 shrink-0 items-center justify-center rounded hover:bg-blue-100"><X size={14} /></button></li>)}</ul>}
        {error && <p id={`${panelId}-error`} role="alert" className="text-xs text-[var(--red)]">{error}</p>}
    </section>;
}
