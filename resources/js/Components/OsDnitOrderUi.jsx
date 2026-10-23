import { useId } from 'react';
import { Link, router } from '@inertiajs/react';

export function Field({ name, label, error, children }) {
    return <div className="grid content-start gap-1.5">
        <label htmlFor={`os-${name}`} className="text-sm font-semibold text-[var(--ink-700)]">{label}</label>
        {children}
        {error && <p id={`os-${name}-error`} role="alert" className="text-sm text-red-700">{error}</p>}
    </div>;
}

export function Pagination({ data, label = 'ordens de serviço', disabled = false, pageName = 'page', preserveState = false }) {
    const pageId = useId();
    const previous = data.links[0]?.url;
    const next = data.links.at(-1)?.url;
    return <nav aria-label={`Paginação de ${label}`} className="sig-card grid gap-3 p-4 sm:flex sm:flex-wrap sm:items-center sm:justify-between">
        <div className="flex items-center justify-between gap-3 sm:contents">
            <p className="min-w-0 text-xs leading-5 text-[var(--ink-500)] sm:text-sm">
                <span className="block sm:inline">{data.total > 0 ? `${data.from}–${data.to} de ${data.total}` : '0 resultados'}</span>
                <span className="hidden sm:inline"> · </span>
                <span className="block sm:inline">{data.per_page} por página</span>
            </p>
            <div className="flex shrink-0 items-center gap-2 text-xs sm:text-sm">
                <label htmlFor={pageId}>Página</label>
                <select id={pageId} value={data.current_page} disabled={disabled || data.last_page <= 1} onChange={(event) => {
                    const url = new URL(data.first_page_url);
                    url.searchParams.set(pageName, event.target.value);
                    router.get(url.toString(), {}, { preserveScroll: true, preserveState });
                }} className="min-w-14 rounded-lg border-slate-300 py-1.5 pl-2 pr-7 text-sm disabled:bg-slate-50 disabled:text-slate-500">
                    {Array.from({ length: data.last_page }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}
                </select>
                <span>de {data.last_page}</span>
            </div>
        </div>
        <div className="grid grid-cols-2 gap-2 sm:hidden">
            {[[previous, 'Anterior'], [next, 'Próxima']].map(([url, text]) => url && !disabled
                ? <Link key={text} href={url} preserveScroll preserveState={preserveState} className="sig-btn sig-btn-secondary">{text}</Link>
                : <button key={text} type="button" disabled className="sig-btn sig-btn-secondary disabled:opacity-50">{text}</button>)}
        </div>
        <div className="hidden flex-wrap gap-1 sm:flex">{data.links.map((link, index) => {
            const text = index === 0 ? 'Anterior' : index === data.links.length - 1 ? 'Próxima' : link.label;
            const className = `rounded-lg border px-3 py-2 text-sm ${link.active ? 'border-blue-600 bg-blue-50 font-semibold text-blue-700' : 'border-slate-200 bg-white'}`;
            return link.url && !disabled && !link.active ? <Link key={index} href={link.url} preserveScroll preserveState={preserveState} className={className}>{text}</Link>
                : <span key={index} className={link.active ? className : 'rounded-lg border border-slate-100 px-3 py-2 text-sm text-slate-400'} aria-current={link.active ? 'page' : undefined} aria-disabled={!link.active || disabled ? 'true' : undefined}>{text}</span>;
        })}</div>
    </nav>;
}

export function dateLabel(value) {
    return value ? value.slice(0, 10).split('-').reverse().join('/') : '—';
}

export function numberLabel(value) {
    return Number(value).toLocaleString('pt-BR', { maximumFractionDigits: 3 });
}
