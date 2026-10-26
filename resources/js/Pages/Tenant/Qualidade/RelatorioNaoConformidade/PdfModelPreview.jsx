import axios from 'axios';
import { Download, Eye, LoaderCircle, RotateCcw, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import PdfPreviewPage from './PdfPreviewPage';
import PdfPreviewDocument from './PdfPreviewDocument';

const fileIds = new WeakMap();
let nextFileId = 0;
function fingerprint(_key, value) {
    if (!(value instanceof File)) return value;
    if (!fileIds.has(value)) fileIds.set(value, ++nextFileId);
    return { file: value.name, id: fileIds.get(value) };
}

function previewBody(payload) {
    const body = new FormData();
    for (const [key, value] of Object.entries(payload)) {
        if (value === null || value === undefined) continue;
        if (value instanceof File) body.append(key, value);
        else if (typeof value === 'object') {
            for (const [name, option] of Object.entries(value)) body.append(`${key}[${name}]`, option ?? '');
        } else body.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value);
    }
    return body;
}

export default function PdfModelPreview({ name, endpoint, payload }) {
    const requestKey = JSON.stringify(payload, fingerprint);
    const [preview, setPreview] = useState(null);
    const [retry, setRetry] = useState(0);
    const [hover, setHover] = useState(null);
    const [expanded, setExpanded] = useState(false);
    const firstRequest = useRef(true);
    const closeTimer = useRef(null);
    const eyeButton = useRef(null);
    const hoverPanel = useRef(null);
    const hoverScroller = useRef(null);
    const modalScroller = useRef(null);
    const dialog = useRef(null);
    const current = preview?.key === requestKey ? preview : null;
    const url = current?.url;

    useEffect(() => {
        const controller = new AbortController();
        let objectUrl;
        let loadingPdf;
        const delay = firstRequest.current ? 0 : 600;
        firstRequest.current = false;
        setPreview(null);
        setHover(null);
        const timer = setTimeout(async () => {
            try {
                const response = await axios.post(endpoint, previewBody(payload), {
                    responseType: 'blob', signal: controller.signal, timeout: 60000,
                    headers: { Accept: 'application/json' },
                });
                if (controller.signal.aborted) return;
                if (!response.headers['content-type']?.includes('application/pdf')) throw new Error('Invalid PDF response');
                const { getDocument, GlobalWorkerOptions } = await import('pdfjs-dist');
                if (controller.signal.aborted) return;
                GlobalWorkerOptions.workerSrc = pdfWorkerUrl;
                objectUrl = URL.createObjectURL(response.data);
                loadingPdf = getDocument({ url: objectUrl, isEvalSupported: false });
                const pdf = await loadingPdf.promise;
                if (!controller.signal.aborted) setPreview({ key: requestKey, url: objectUrl, pdf });
            } catch (error) {
                if (!controller.signal.aborted && !axios.isCancel(error)) setPreview({ key: requestKey, error: true });
            }
        }, delay);
        return () => {
            clearTimeout(timer);
            controller.abort();
            loadingPdf?.destroy().catch(() => {});
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        };
    }, [endpoint, requestKey, retry]);

    useEffect(() => () => clearTimeout(closeTimer.current), []);

    useEffect(() => {
        if (!hover) return;
        const close = () => setHover(null);
        const scroll = (event) => {
            if (event.target instanceof Node && hoverPanel.current?.contains(event.target)) return;
            close();
        };
        const wheel = (event) => {
            const scroller = hoverScroller.current;
            if (!scroller) return;
            event.preventDefault();
            event.stopPropagation();
            const unit = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? scroller.clientHeight : 1;
            scroller.scrollTop += event.deltaY * unit;
            scroller.scrollLeft += event.deltaX * unit;
        };
        const button = eyeButton.current;
        const escape = (event) => { if (event.key === 'Escape') close(); };
        button?.addEventListener('wheel', wheel, { passive: false });
        window.addEventListener('scroll', scroll, true);
        window.addEventListener('resize', close);
        window.addEventListener('keydown', escape);
        return () => {
            button?.removeEventListener('wheel', wheel);
            window.removeEventListener('scroll', scroll, true);
            window.removeEventListener('resize', close);
            window.removeEventListener('keydown', escape);
        };
    }, [hover]);

    useEffect(() => {
        if (!expanded) return;
        const element = dialog.current;
        const previousFocus = document.activeElement;
        element.showModal();
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            element.close();
            document.body.style.overflow = previousOverflow;
            if (previousFocus?.isConnected) previousFocus.focus();
        };
    }, [expanded]);

    function openHover() {
        clearTimeout(closeTimer.current);
        if (!url || expanded || !window.matchMedia('(hover: hover) and (min-width: 640px)').matches) return;
        const anchor = eyeButton.current.getBoundingClientRect();
        const width = Math.min(520, window.innerWidth - 32);
        const above = anchor.top - 24;
        const below = window.innerHeight - anchor.bottom - 24;
        const placeAbove = above >= below;
        const height = Math.max(80, Math.min(410, placeAbove ? above : below));
        const top = placeAbove ? anchor.top - height - 8 : anchor.bottom + 8;
        setHover({ width, height, top: Math.max(16, top), left: Math.max(16, Math.min(anchor.left + anchor.width / 2 - width / 2, window.innerWidth - width - 16)) });
    }

    function scheduleClose() {
        clearTimeout(closeTimer.current);
        closeTimer.current = setTimeout(() => setHover(null), 220);
    }

    function openExpanded() {
        clearTimeout(closeTimer.current);
        setHover(null);
        setExpanded(true);
    }

    return <div className="mt-4 min-w-0">
        <div className="relative h-72 overflow-hidden rounded-lg border border-slate-200 bg-white sm:h-80" aria-busy={!current}>
            {url ? <><PdfPreviewPage pdf={current.pdf} name={name} /><button type="button" className="absolute inset-0 cursor-zoom-in bg-transparent focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--primary)]" onClick={openExpanded} aria-label={`Ampliar prévia de ${name}`} /></> : <div className="flex h-full flex-col items-center justify-center gap-3 px-5 text-center text-sm text-slate-500">
                {current?.error ? <><span role="alert">Não foi possível carregar a prévia deste modelo.</span><button type="button" className="sig-btn sig-btn-secondary !min-h-9 !text-xs" onClick={() => setRetry((value) => value + 1)}><RotateCcw size={14} />Tentar novamente</button></> : <><LoaderCircle size={22} className="animate-spin text-[var(--primary)]" /><span role="status">Gerando prévia...</span></>}
            </div>}
        </div>
        <div className="mt-3 flex flex-wrap items-center justify-end gap-2">
            <div className="flex items-center gap-1">
                <button ref={eyeButton} type="button" disabled={!url} className="sig-btn sig-btn-ghost !min-h-9 !px-3 !text-xs disabled:cursor-wait disabled:opacity-50" aria-label={`Ver modelo ${name}`} aria-haspopup="dialog" onClick={openExpanded} onMouseEnter={openHover} onMouseLeave={scheduleClose} onFocus={openHover} onBlur={(event) => { if (!hoverPanel.current?.contains(event.relatedTarget)) scheduleClose(); }}><Eye size={15} />Ver</button>
                {url && <a href={url} download={`rnc-${payload.layout}.pdf`} className="sig-btn sig-btn-ghost !min-h-9 !px-3 !text-xs" aria-label={`Baixar PDF de ${name}`}><Download size={15} />Baixar</a>}
            </div>
        </div>
        {hover && url && createPortal(<aside ref={hoverPanel} aria-label={`Prévia ampliada de ${name}`} className="fixed z-[9999] flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl" style={hover} onMouseEnter={() => clearTimeout(closeTimer.current)} onMouseLeave={scheduleClose}>
            <div className="shrink-0 border-b border-slate-200 px-3 py-2 text-sm font-semibold">{name}</div>
            <div ref={hoverScroller} className="min-h-0 flex-1 overflow-auto overscroll-contain" tabIndex={0} aria-label={`Documento completo de ${name}`}><PdfPreviewDocument pdf={current.pdf} name={name} scrollRoot={hoverScroller} /></div>
        </aside>, document.body)}
        {expanded && createPortal(<dialog ref={dialog} aria-label={`Modelo ${name}`} className="m-auto h-[90dvh] w-[calc(100vw-2rem)] max-w-5xl overflow-hidden rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-slate-950/45 open:flex open:flex-col" onClose={() => setExpanded(false)} onClick={(event) => { if (event.target === event.currentTarget) setExpanded(false); }}>
            <header className="flex shrink-0 items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <h2 className="text-base font-semibold">{name}</h2>
                <div className="flex shrink-0 items-center gap-2">{url && <a href={url} download={`rnc-${payload.layout}.pdf`} className="sig-btn sig-btn-secondary !min-h-9 !px-3" aria-label={`Baixar PDF de ${name}`}><Download size={16} /><span className="hidden sm:inline">Baixar PDF</span></a>}<button type="button" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="Fechar prévia" onClick={() => setExpanded(false)}><X size={20} /></button></div>
            </header>
            <div ref={modalScroller} className="min-h-0 flex-1 overflow-auto overscroll-contain bg-slate-100 p-2 sm:p-4" tabIndex={0} aria-label={`Documento completo de ${name}`}>{url ? <PdfPreviewDocument pdf={current.pdf} name={name} scrollRoot={modalScroller} /> : <div className="flex h-full items-center justify-center text-sm text-slate-500">Atualizando prévia...</div>}</div>
        </dialog>, document.body)}
    </div>;
}
