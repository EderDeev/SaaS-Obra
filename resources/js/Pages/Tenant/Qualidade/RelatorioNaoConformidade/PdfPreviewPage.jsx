import { LoaderCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export default function PdfPreviewPage({ pdf, pageNumber = 1, name, lazy = false, scrollRoot }) {
    const container = useRef(null);
    const canvas = useRef(null);
    const [width, setWidth] = useState(0);
    const [status, setStatus] = useState('loading');
    const [visible, setVisible] = useState(!lazy);

    useEffect(() => {
        if (!lazy || visible) return;
        const observer = new IntersectionObserver(([entry]) => {
            if (entry.isIntersecting) { setVisible(true); observer.disconnect(); }
        }, { root: scrollRoot?.current ?? null, rootMargin: '400px 0px' });
        observer.observe(container.current);
        return () => observer.disconnect();
    }, [lazy, visible, scrollRoot]);

    useEffect(() => {
        const observer = new ResizeObserver(([entry]) => setWidth(Math.floor(entry.contentRect.width)));
        observer.observe(container.current);
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        if (!width || !visible) return;
        let active = true;
        let rendering;
        setStatus('loading');
        (async () => {
            try {
                const page = await pdf.getPage(pageNumber);
                if (!active) return;
                const original = page.getViewport({ scale: 1 });
                const viewport = page.getViewport({ scale: width / original.width * Math.min(window.devicePixelRatio || 1, 2) });
                const target = canvas.current;
                target.width = Math.ceil(viewport.width);
                target.height = Math.ceil(viewport.height);
                rendering = page.render({ canvas: target, viewport, background: '#ffffff' });
                await rendering.promise;
                if (active) setStatus('ready');
            } catch (error) {
                if (active && error.name !== 'RenderingCancelledException') setStatus('error');
            }
        })();
        return () => { active = false; rendering?.cancel(); };
    }, [pdf, pageNumber, width, visible]);

    return <div ref={container} className="relative min-h-40 w-full bg-white" style={{ aspectRatio: '595.28 / 841.89' }} aria-busy={status === 'loading'}>
        <canvas ref={canvas} role="img" aria-label={`Página ${pageNumber} de ${name}`} className={`absolute inset-0 block h-full w-full ${status === 'ready' ? '' : 'invisible'}`} />
        {status !== 'ready' && <div className="absolute inset-0 flex min-h-40 items-center justify-center gap-2 p-4 text-center text-xs text-slate-500">
            {status === 'error' ? 'Não foi possível exibir esta página. Use Baixar para abrir o PDF.' : <><LoaderCircle size={18} className="animate-spin" />Exibindo página...</>}
        </div>}
    </div>;
}
