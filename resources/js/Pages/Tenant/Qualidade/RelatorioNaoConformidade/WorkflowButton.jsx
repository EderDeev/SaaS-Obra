import axios from 'axios';
import { ClipboardCheck, ImagePlus, Loader2, SearchCheck, X } from 'lucide-react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import WorkflowForm, { workflowStages } from './WorkflowForm';

function WorkflowModal({ tenant, rncId, stage, onClose }) {
    const config = workflowStages[stage];
    const dialog = useRef(null);
    const heading = useRef(null);
    const busy = useRef(false);
    const dirty = useRef(false);
    const [processing, setProcessing] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [reload, setReload] = useState(0);
    const titleId = useId();
    const onBusy = useCallback((value) => { busy.current = value; setProcessing(value); }, []);
    const onDirty = useCallback((value) => { dirty.current = value; }, []);
    const requestClose = () => { if (busy.current) return; if (dirty.current) setDiscard(true); else onClose(); };
    useEffect(() => {
        const previousFocus = document.activeElement;
        const previousOverflow = document.body.style.overflow;
        const element = dialog.current;
        element.showModal(); document.body.style.overflow = 'hidden'; heading.current?.focus();
        return () => { element.close(); document.body.style.overflow = previousOverflow; if (previousFocus?.isConnected) previousFocus.focus(); };
    }, []);
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true); setError('');
        axios.get(route(`tenant.qualidade.rnc.${config.route}.create`, [tenant.slug, rncId]), { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then(({ data: response }) => { if (!controller.signal.aborted) setData(response); })
            .catch((exception) => { if (!controller.signal.aborted) setError([403, 404, 409].includes(exception.response?.status)
                ? 'Esta etapa não está disponível. A RNC pode ter avançado no fluxo ou seu acesso foi alterado. Feche o modal e atualize a página.'
                : 'Não foi possível carregar a RNC. Tente novamente.'); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [tenant.slug, rncId, config.route, reload]);
    if (typeof document === 'undefined') return null;
    return createPortal(<dialog ref={dialog} aria-modal="true" aria-labelledby={titleId}
        className="os-dnit-dialog os-dnit-analysis fixed inset-0 !m-auto max-h-[94dvh] w-[97vw] max-w-6xl overflow-hidden rounded-2xl border-0 bg-white p-0 text-[var(--ink-900)] shadow-2xl backdrop:bg-slate-900/40 backdrop:backdrop-blur-sm open:flex open:flex-col"
        onCancel={(event) => { event.preventDefault(); requestClose(); }}>
        <header className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-7"><div className="min-w-0"><span className="font-mono text-xs font-semibold text-blue-700">{data ? `RNC ${data.rnc.formatted_number}` : 'Relatório de Não Conformidade'}</span><h2 id={titleId} ref={heading} tabIndex={-1} className="mt-1 text-xl font-semibold focus:outline-none">{config.title}</h2>{data?.rnc.assunto && <p className="mt-1 break-words text-sm text-slate-500">{data.rnc.assunto}</p>}</div><button type="button" onClick={requestClose} disabled={processing} aria-label="Fechar etapa da RNC" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"><X size={20} /></button></header>
        {discard && <div role="alert" className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-900"><p>Há alterações não enviadas. Deseja fechar sem salvar?</p><div className="flex flex-wrap gap-2"><button type="button" onClick={() => setDiscard(false)} disabled={processing} className="sig-btn sig-btn-secondary">Continuar preenchimento</button><button type="button" onClick={onClose} disabled={processing} className="sig-btn sig-btn-secondary">Descartar e fechar</button></div></div>}
        {loading ? <div role="status" className="flex items-center justify-center gap-3 p-12 text-sm text-slate-500"><Loader2 size={20} className="animate-spin" />Carregando RNC...</div>
            : error ? <div role="alert" className="space-y-5 p-6"><p className="text-sm text-red-800">{error}</p><div className="flex flex-wrap gap-3"><button type="button" onClick={() => setReload((value) => value + 1)} className="sig-btn sig-btn-primary">Tentar novamente</button><button type="button" onClick={onClose} className="sig-btn sig-btn-secondary">Fechar</button></div></div>
                : data && <WorkflowForm {...data} stage={stage} modal onCancel={requestClose} onCompleted={onClose} onBusy={onBusy} onDirty={onDirty} />}
    </dialog>, document.body);
}

export default function WorkflowButton({ tenant, rncId, stage, children }) {
    const [open, setOpen] = useState(false);
    const close = useCallback(() => setOpen(false), []);
    const Icon = stage === 'proposal' ? ClipboardCheck : stage === 'review' ? SearchCheck : ImagePlus;
    return <><button type="button" className="sig-btn sig-btn-primary" onClick={() => setOpen(true)}><Icon size={16} />{children || workflowStages[stage].title}</button>{open && <WorkflowModal tenant={tenant} rncId={rncId} stage={stage} onClose={close} />}</>;
}
