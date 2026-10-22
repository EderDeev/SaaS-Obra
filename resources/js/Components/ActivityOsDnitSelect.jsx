import axios from 'axios';
import { ChevronDown, Search, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

export default function ActivityOsDnitSelect({ tenantSlug, contractId, value, onChange, initialOrder = null, error, disabled = false }) {
    const id = useId();
    const root = useRef(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [orders, setOrders] = useState([]);
    const [selected, setSelected] = useState(initialOrder);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState('');
    const [hasMore, setHasMore] = useState(false);
    const [active, setActive] = useState(-1);

    useEffect(() => {
        const close = (event) => { if (!root.current?.contains(event.target)) setOpen(false); };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, []);

    useEffect(() => {
        if (!open || !contractId || disabled) return;
        const controller = new AbortController();
        setLoading(true);
        setFailure('');
        setOrders([]);
        setActive(-1);
        setHasMore(false);
        const timer = setTimeout(async () => {
            try {
                const { data } = await axios.get(route('tenant.activities.os-dnit-options', tenantSlug), {
                    params: { contract_id: contractId, q: query }, signal: controller.signal,
                });
                if (controller.signal.aborted) return;
                setOrders(data.orders);
                setHasMore(data.has_more);
            } catch (exception) {
                if (controller.signal.aborted) return;
                setFailure(exception.response?.status === 403 ? 'Você não possui acesso às OSs DNIT deste contrato.' : 'Não foi possível buscar as OSs. Feche o campo e tente novamente.');
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 300);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [open, query, contractId, disabled, tenantSlug]);

    useEffect(() => {
        if (active >= 0) root.current?.querySelector(`[data-option-index="${active}"]`)?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const choose = (order) => {
        setSelected(order);
        onChange(String(order.id));
        setOpen(false);
        setQuery('');
    };
    const label = selected && String(selected.id) === String(value) ? `${selected.request_number} — ${selected.subject}` : '';

    return <div ref={root} className="min-w-0" onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false); }}>
        <label htmlFor={id} className="eyebrow mb-1 block">Ordem de Serviço DNIT</label>
        <div className="sig-input flex min-w-0 items-center gap-2">
            <Search size={15} className="shrink-0 text-[var(--ink-500)]" />
            <input id={id} role="combobox" aria-required="true" aria-expanded={open} aria-controls={`${id}-list`} aria-autocomplete="list"
                aria-activedescendant={open && active >= 0 ? `${id}-option-${active}` : undefined}
                aria-invalid={Boolean(error)} aria-describedby={error ? `${id}-error` : undefined}
                disabled={disabled || !contractId} value={open ? query : label} maxLength={120}
                placeholder={contractId ? 'Pesquisar por número ou assunto da OS' : 'Selecione primeiro o contrato'}
                className="!min-w-0 flex-1 !text-base sm:!text-sm" autoComplete="off"
                onFocus={() => { setQuery(''); setOpen(true); }} onClick={() => setOpen(true)}
                onChange={(event) => { setQuery(event.target.value); setOpen(true); }}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); setOpen(false); }
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault(); setOpen(true);
                        setActive((index) => orders.length ? Math.max(0, Math.min(orders.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1))) : -1);
                    }
                    if (event.key === 'Enter' && open) { event.preventDefault(); if (orders[active]) choose(orders[active]); }
                }} />
            {value && <button type="button" aria-label="Limpar OS selecionada" disabled={disabled} onClick={() => { onChange(''); setSelected(null); setQuery(''); }} className="shrink-0 p-1 text-[var(--ink-500)]"><X size={15} /></button>}
            <ChevronDown size={15} className="shrink-0 text-[var(--ink-500)]" />
        </div>
        {label && <p className="mt-1 break-words text-xs text-[var(--ink-500)]">Selecionada: {label}</p>}
        {open && <div className="mt-1 overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-sm">
            <ul id={`${id}-list`} role="listbox" aria-label="Ordens de serviço DNIT" aria-busy={loading} className="max-h-56 overflow-y-auto">
                {orders.map((order, index) => <li key={order.id} id={`${id}-option-${index}`} data-option-index={index} role="option" aria-selected={String(order.id) === String(value)}
                    className={`cursor-pointer border-b border-[var(--border)] px-3 py-2.5 last:border-0 hover:bg-blue-50 ${active === index || String(order.id) === String(value) ? 'bg-blue-50' : ''}`}
                    onMouseDown={(event) => event.preventDefault()} onClick={() => choose(order)}>
                    <span className="block text-sm font-semibold text-[var(--primary)]">OS {order.request_number}</span>
                    <span className="block break-words text-sm text-[var(--ink-700)]">{order.subject}</span>
                </li>)}
            </ul>
            <div role="status" className="px-3 text-sm text-[var(--ink-500)]">
                {loading && <p className="py-3">Buscando OSs…</p>}
                {!loading && failure && <p className="py-3 text-red-700">{failure}</p>}
                {!loading && !failure && orders.length === 0 && <p className="py-3">Nenhuma OS encontrada neste contrato.</p>}
                {!loading && hasMore && <p className="py-2 text-xs">Mostrando 20 resultados. Refine a pesquisa para encontrar a OS.</p>}
            </div>
        </div>}
        {error && <p id={`${id}-error`} role="alert" className="mt-1 text-xs text-red-700">{error}</p>}
    </div>;
}
