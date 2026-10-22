import { Check, ChevronDown, Search } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

const normalize = (value) => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

export default function OsDnitProductPicker({ products, categories, frequencies, items, onChange, disabled = false, initialOpen = false }) {
    const [open, setOpen] = useState(initialOpen);
    const [query, setQuery] = useState('');
    const root = useRef(null);
    const trigger = useRef(null);
    const input = useRef(null);
    const panelId = useId();
    const terms = normalize(query).trim().split(/\s+/).filter(Boolean);
    const matches = products.filter((product) => {
        const text = normalize(`${product.code} ${product.name} ${categories[product.category]} ${product.unit} ${frequencies[product.frequency]}`);
        return terms.every((term) => text.includes(term));
    });

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

    const toggle = (product) => {
        const selected = items.some((item) => String(item.product_id) === String(product.id));
        if (selected) onChange(items.filter((item) => String(item.product_id) !== String(product.id)));
        else if (items.length < 100) onChange([...items, { product_id: product.id, quantity: '1' }]);
    };

    return <div ref={root} className="space-y-3">
        <button ref={trigger} type="button" disabled={disabled || products.length === 0} onClick={() => setOpen((current) => !current)} aria-expanded={open} aria-controls={panelId} className="flex w-full items-center justify-between gap-3 rounded-lg border border-slate-300 bg-white px-4 py-3 text-left text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50">
            <span>Selecionar produtos{items.length > 0 ? ` (${items.length} selecionado${items.length === 1 ? '' : 's'})` : ''}</span><ChevronDown size={17} className={open ? 'rotate-180' : ''} />
        </button>
        {open && <div id={panelId} className="overflow-hidden rounded-xl border border-blue-200 bg-white shadow-sm">
            <div className="border-b border-slate-100 p-3">
                <label htmlFor={`${panelId}-search`} className="mb-2 block text-xs font-semibold text-[var(--ink-700)]">Pesquisar produtos</label>
                <div className="relative"><Search size={16} className="pointer-events-none absolute left-3 top-3 text-slate-400" /><input ref={input} id={`${panelId}-search`} type="search" value={query} onChange={(event) => setQuery(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') event.preventDefault(); }} placeholder="Código, nome, categoria ou unidade" className="w-full rounded-lg border-slate-300 pl-9 text-sm" /></div>
                <p className="mt-2 text-xs text-[var(--ink-500)]">Marque os produtos para incluí-los imediatamente na solicitação.</p>
            </div>
            <fieldset className="max-h-72 divide-y divide-slate-100 overflow-y-auto"><legend className="sr-only">Produtos disponíveis neste contrato</legend>
                {matches.map((product) => {
                    const selected = items.some((item) => String(item.product_id) === String(product.id));
                    return <label key={product.id} className={`flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors ${selected ? 'bg-blue-50' : 'hover:bg-slate-50'}`}>
                        <input type="checkbox" checked={selected} disabled={disabled || (!selected && items.length >= 100)} onChange={() => toggle(product)} className="shrink-0 rounded border-slate-300 text-blue-600 focus:ring-blue-500" />
                        <span className="min-w-0 flex-1"><span className="block break-words text-sm font-semibold"><span className="mr-2 font-mono text-[var(--primary)]">{product.code}</span>{product.name}</span><span className="mt-1 block text-xs text-[var(--ink-500)]">{categories[product.category]} · Unidade: {product.unit} · {frequencies[product.frequency]}</span></span>
                        {selected && <Check size={16} className="shrink-0 text-blue-600" aria-hidden="true" />}
                    </label>;
                })}
                {matches.length === 0 && <p className="px-4 py-6 text-center text-sm text-[var(--ink-500)]">Nenhum produto encontrado para esta busca.</p>}
            </fieldset>
            <p role="status" className="border-t border-slate-100 px-4 py-2 text-xs text-[var(--ink-500)]">{matches.length} produto(s) encontrado(s) · {items.length} selecionado(s)</p>
        </div>}
    </div>;
}
