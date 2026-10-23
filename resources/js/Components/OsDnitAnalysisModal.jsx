import { dateLabel, numberLabel } from '@/Components/OsDnitOrderUi';
import { SupervisionAnalysis, StatusBadge } from '@/Components/OsDnitWorkflow';
import OsDnitProductPicker from '@/Components/OsDnitProductPicker';
import OsDnitConfirmDialog from '@/Components/OsDnitConfirmDialog';
import { submitOsDnitAnalysis } from '@/Support/osDnitAnalysisForm';
import { useForm } from '@inertiajs/react';
import axios from 'axios';
import { AlertCircle, ArrowRight, Check, CheckCircle2, ChevronDown, FileLock2, Loader2, Paperclip, Plus, RotateCcw, Save, Send, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

function OriginalRequest({ order, attachmentLinks, frequencies }) {
    return <details className="group rounded-xl border border-slate-200 bg-slate-50">
        <summary className="flex cursor-pointer list-none items-center gap-3 px-5 py-4 text-sm font-semibold"><FileLock2 size={18} className="text-slate-500" /><span className="flex-1">Consultar pedido do analista e anexos</span><ChevronDown size={17} className="transition-transform group-open:rotate-180" /></summary>
        <div className="space-y-4 border-t border-slate-200 p-5 text-sm">
            <p className="text-xs text-slate-500">Demanda do analista preservada. Os produtos são definidos pela Supervisão.</p>
            <dl className="grid gap-4 sm:grid-cols-2">{[
                ['Solicitante', order.creator.name], ['Prazo solicitado', dateLabel(order.desired_deadline)],
                ['Localização', `${order.highway_name} · km ${numberLabel(order.km_start)} a ${numberLabel(order.km_end)}`],
                ['Lote de supervisão', order.supervision_lot], ['Referência SEI / processo', order.sei_reference || '—'],
            ].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 break-words">{value}</dd></div>)}</dl>
            <div><h3 className="font-semibold">Necessidade técnica</h3><p className="mt-1 whitespace-pre-wrap break-words text-slate-600">{order.technical_need}</p></div>
            <div><h3 className="font-semibold">Justificativa / resultado esperado</h3><p className="mt-1 whitespace-pre-wrap break-words text-slate-600">{order.justification}</p></div>

            <div className="space-y-2"><h3 className="font-semibold">Anexos</h3>{order.attachments.map((file) => <a key={file.id} href={attachmentLinks[file.id]} className="flex items-center gap-2 break-all text-blue-700 underline"><Paperclip size={15} className="shrink-0" />{file.original_name}</a>)}{order.attachments.length === 0 && <p className="text-slate-500">Nenhum anexo.</p>}</div>
        </div>
    </details>;
}

function AnalysisContent({ data, tenantSlug, onClose, onRequestClose, onBusy, onDirty, refresh }) {
    const { order, capabilities, products, categories, frequencies, attachmentLinks } = data;
    const review = capabilities.review;
    const form = useForm({ version: order.workflow_version, review_comment: order.review_comment || '',
        estimated_deadline: (order.estimated_deadline || order.desired_deadline).slice(0, 10),
        items: (order.review_items ?? []).map((i) => ({ product_id: i.product_id, quantity: i.quantity })),
        decision: '', return_to: '', comment: '' });
    const [pickerOpen, setPickerOpen] = useState(false);
    const [confirmAction, setConfirmAction] = useState(false);
    const errorSummary = useRef(null);
    useEffect(() => { onBusy(form.processing); return () => onBusy(false); }, [form.processing, onBusy]);
    useEffect(() => { onDirty(form.isDirty); return () => onDirty(false); }, [form.isDirty, onDirty]);
    useEffect(() => {
        if (Object.keys(form.errors).length) errorSummary.current?.scrollIntoView({ block: 'nearest' });
    }, [form.errors]);
    const latestReturn = [...order.events].reverse().find((event) => event.action === 'returned');
    const recommended = form.data.items.map((item) => {
        const old = (order.review_items ?? []).find((i) => i.product_id === item.product_id);
        const product = products.find((p) => p.id === item.product_id);
        return { ...item, product_code: old?.product_code || product?.code, product_name: old?.product_name || product?.name,
            unit: old?.unit || product?.unit, frequency: old?.frequency || product?.frequency };
    });
    const compared = review ? recommended : order.review_items || [];
    const deadline = review ? form.data.estimated_deadline : order.estimated_deadline?.slice(0, 10);
    const dateChanged = deadline !== order.desired_deadline.slice(0, 10);
    const active = review || capabilities.decide;
    const submit = (mode) => {
        if (form.processing) return;
        submitOsDnitAnalysis(form, { review, mode,
            url: route(review ? 'tenant.os-dnit.orders.review' : 'tenant.os-dnit.orders.decide', [tenantSlug, order.id]),
            onSaved: () => refresh(true), onCompleted: onClose });
    };

    return <><form className="os-dnit-analysis-form flex min-h-0 flex-1 flex-col" aria-busy={form.processing} onSubmit={(event) => { event.preventDefault(); if (!form.processing && active && (review || form.data.decision)) setConfirmAction(true); }}>
        <div className="os-dnit-analysis-body min-h-0 flex-1 space-y-6 overflow-y-auto px-5 py-5 sm:px-7">
            {!review && !capabilities.decide && <div className="flex items-start gap-3 rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-900"><CheckCircle2 size={18} className="mt-0.5 shrink-0" /><div><p className="font-semibold">Esta OS não aguarda sua ação nesta etapa</p><p className="mt-1 text-xs leading-relaxed">Consulte a demanda e a análise registradas abaixo.</p></div></div>}
            {order.status.startsWith('returned') && latestReturn && <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p className="font-semibold">Por que esta OS foi devolvida?</p><p className="mt-2 whitespace-pre-wrap break-words">{latestReturn.comment}</p></div>}
            {Object.keys(form.errors).length > 0 && <div ref={errorSummary} role="alert" className="space-y-2 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p className="flex items-center gap-2 font-semibold"><AlertCircle size={17} />Confira antes de continuar</p>{[...new Set(Object.values(form.errors))].map((error) => <p key={error}>{error}</p>)}{form.errors.version && <button type="button" onClick={() => refresh(false)} className="underline">Atualizar dados da OS</button>}</div>}
            <OriginalRequest order={order} attachmentLinks={attachmentLinks} frequencies={frequencies} />
            {review ? <>
                <section aria-labelledby="analysis-products-title"><div className="mb-3 flex flex-wrap items-center justify-between gap-3"><div><h3 id="analysis-products-title" className="font-semibold">Produtos solicitados</h3><p className="mt-1 text-xs text-slate-500">Defina os produtos e as quantidades necessários para a demanda.</p></div><button type="button" disabled={form.processing} onClick={() => setPickerOpen(!pickerOpen)} className="sig-btn sig-btn-secondary"><Plus size={15} />Selecionar produtos</button></div>
                    {pickerOpen && <div className="mb-4"><OsDnitProductPicker initialOpen products={products} categories={categories} frequencies={frequencies} items={form.data.items} disabled={form.processing} onChange={(items) => form.setData('items', items)} /></div>}
                    <div className="divide-y divide-slate-100 rounded-xl border border-slate-200">{recommended.map((item, index) => {
                        return <div key={item.product_id} className="flex flex-wrap items-center gap-4 p-4"><div className="min-w-0 flex-1 basis-48"><h4 className="break-words text-sm font-semibold"><span className="mr-2 font-mono text-blue-700">{item.product_code}</span>{item.product_name}</h4></div><label className="block w-36 text-xs font-semibold" htmlFor={`analysis-quantity-${item.product_id}`}>Sua quantidade ({item.unit})<input id={`analysis-quantity-${item.product_id}`} type="number" required min="0.001" max="999999999999.999" step="0.001" value={item.quantity} disabled={form.processing} onChange={(event) => form.setData('items', form.data.items.map((i, n) => n === index ? { ...i, quantity: event.target.value } : i))} className="mt-1.5 w-full rounded-lg border-slate-300 text-sm" /></label><button type="button" disabled={form.processing} onClick={() => form.setData('items', form.data.items.filter((_, n) => n !== index))} aria-label={`Retirar produto ${item.product_code}`} className="rounded-lg p-2 text-slate-500 hover:bg-red-50 hover:text-red-700"><X size={17} /></button></div>;
                    })}{recommended.length === 0 && <p className="p-5 text-sm text-slate-500">Selecione pelo menos um produto para encaminhar a análise.</p>}</div>

                </section>
                <section className="grid gap-4 sm:grid-cols-2"><label htmlFor="analysis-deadline" className="block text-sm font-semibold">Prazo estimado pela Supervisão<input id="analysis-deadline" required type="date" value={form.data.estimated_deadline} disabled={form.processing} onChange={(event) => form.setData('estimated_deadline', event.target.value)} className="mt-2 w-full rounded-lg border-slate-300 text-sm" /></label><div className="rounded-xl bg-slate-50 px-4 py-3"><p className="text-xs text-slate-500">Prazo solicitado pelo analista</p><p className="mt-1 text-sm font-medium">{dateLabel(order.desired_deadline)}</p>{dateChanged && <p className="mt-1 text-xs font-medium text-amber-800">Você está recomendando outro prazo.</p>}</div></section>
                <label htmlFor="analysis-comment" className="block text-sm font-semibold">Comentário da análise <span className="font-normal text-slate-500">(obrigatório)</span><textarea id="analysis-comment" required value={form.data.review_comment} disabled={form.processing} onChange={(event) => form.setData('review_comment', event.target.value)} rows={3} maxLength={20000} placeholder="Explique os ajustes ou registre sua avaliação técnica." className="mt-2 w-full rounded-lg border-slate-300 text-sm font-normal" /></label>
            </> : null}
            {!review && order.review_items && <section className="rounded-xl border border-slate-200"><h3 className="border-b border-slate-200 px-5 py-4 font-semibold">Análise Supervisão</h3><SupervisionAnalysis items={order.review_items} deadline={order.estimated_deadline} comment={order.review_comment} /></section>}
            {capabilities.decide && <section className="space-y-4" aria-labelledby="decision-modal-title"><h3 id="decision-modal-title" className="font-semibold">Qual é sua decisão?</h3><div className="grid gap-3 sm:grid-cols-2">{[['approve', 'Aprovar serviço', CheckCircle2], ['return', 'Reprovar e devolver', RotateCcw]].map(([value, label, Icon]) => <button key={value} type="button" disabled={form.processing} aria-pressed={form.data.decision === value} onClick={() => form.setData('decision', value)} className={`flex items-center gap-3 rounded-xl border p-4 text-left text-sm font-semibold ${form.data.decision === value ? 'border-blue-500 bg-blue-50 text-blue-800 ring-1 ring-blue-500' : 'border-slate-200 hover:bg-slate-50'}`}><Icon size={19} />{label}{form.data.decision === value && <Check size={16} className="ml-auto" />}</button>)}</div>
                {form.data.decision === 'return' && <label htmlFor="analysis-return-to" className="block text-sm font-semibold">Quem deve corrigir?<select id="analysis-return-to" required disabled={form.processing} value={form.data.return_to} onChange={(event) => form.setData('return_to', event.target.value)} className="mt-2 w-full rounded-lg border-slate-300 text-sm"><option value="">Selecione o responsável</option><option value="concremat">Supervisão — produtos, quantidade ou prazo</option><option value="analyst">Analista DNIT — pedido de serviço</option></select></label>}
                <label htmlFor="analysis-decision-comment" className="block text-sm font-semibold">{form.data.decision === 'return' ? 'Motivo da reprovação' : 'Comentário da decisão'}<textarea id="analysis-decision-comment" required disabled={form.processing} value={form.data.comment} onChange={(event) => form.setData('comment', event.target.value)} rows={3} maxLength={20000} placeholder={form.data.decision === 'return' ? 'Descreva o que precisa ser revisto pelo responsável.' : 'Registre o comentário da sua decisão.'} className="mt-2 w-full rounded-lg border-slate-300 text-sm font-normal" /></label>
            </section>}
        </div>
        <footer className="os-dnit-analysis-footer flex shrink-0 flex-wrap items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-7"><div className="flex flex-wrap gap-2"><button type="button" onClick={onRequestClose} disabled={form.processing} className="sig-btn sig-btn-secondary">Fechar</button>{review && <button type="button" disabled={form.processing || !form.data.items.length} onClick={(event) => { if (event.currentTarget.form.reportValidity()) submit('save'); }} className="sig-btn sig-btn-secondary"><Save size={15} />Salvar para depois</button>}{active && <button disabled={form.processing || (review ? !form.data.items.length : !form.data.decision)} className="sig-btn sig-btn-primary">{form.processing ? <Loader2 size={16} className="animate-spin" /> : review ? <Send size={16} /> : <CheckCircle2 size={16} />}{form.processing ? 'Salvando…' : review ? 'Encaminhar ao fiscal' : form.data.decision === 'return' ? 'Devolver com justificativa' : 'Confirmar aprovação'}</button>}</div></footer>
    </form><OsDnitConfirmDialog open={confirmAction}
        title={review ? 'Encaminhar análise?' : form.data.decision === 'return' ? 'Reprovar e devolver OS?' : 'Aprovar serviço?'}
        message={review
            ? `Deseja encaminhar a análise da OS ${order.request_number} ao fiscal do contrato? Os produtos, as quantidades, o prazo e o comentário serão enviados para decisão.`
            : form.data.decision === 'return'
                ? `Deseja reprovar a OS ${order.request_number} e devolvê-la ao ${form.data.return_to === 'concremat' ? 'Supervisão' : 'analista DNIT'}? O motivo informado será registrado na decisão.`
                : `Deseja aprovar o serviço da OS ${order.request_number}? A aprovação e seu comentário serão registrados na decisão.`}
        confirmLabel={review ? 'Confirmar envio' : form.data.decision === 'return' ? 'Confirmar devolução' : 'Confirmar aprovação'}
        disabled={form.processing} onCancel={() => setConfirmAction(false)} onConfirm={() => { setConfirmAction(false); submit('forward'); }} /></>;
}

export default function OsDnitAnalysisModal({ orderId, tenantSlug, onClose }) {
    const dialog = useRef(null);
    const heading = useRef(null);
    const busy = useRef(false);
    const dirty = useRef(false);
    const [processing, setProcessing] = useState(false);
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [discard, setDiscard] = useState(false);
    const [savedNotice, setSavedNotice] = useState(false);
    const [reload, setReload] = useState(0);
    const [revision, setRevision] = useState(0);
    const onBusy = useCallback((value) => { busy.current = value; setProcessing(value); }, []);
    const onDirty = useCallback((value) => { dirty.current = value; }, []);
    const requestClose = () => { if (busy.current) return; if (dirty.current) setDiscard(true); else onClose(); };
    useEffect(() => {
        const previousFocus = document.activeElement;
        const previousOverflow = document.body.style.overflow;
        const element = dialog.current;
        element.showModal();
        document.body.style.overflow = 'hidden';
        heading.current?.focus();
        return () => { element.close(); document.body.style.overflow = previousOverflow; if (previousFocus?.isConnected) previousFocus.focus(); };
    }, []);
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true); setError('');
        axios.get(route('tenant.os-dnit.analysis.show', [tenantSlug, orderId]), { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then(({ data: response }) => { if (!controller.signal.aborted) { setData(response); setRevision((n) => n + 1); dirty.current = false; } })
            .catch((exception) => { if (!controller.signal.aborted) setError([403, 404].includes(exception.response?.status) ? 'Seu acesso a esta análise não está disponível. Confira seu vínculo e papel no contrato.' : 'Não foi possível carregar a OS. Tente novamente.'); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [orderId, tenantSlug, reload]);
    return <dialog ref={dialog} aria-modal="true" aria-labelledby="analysis-modal-title" className="os-dnit-dialog os-dnit-analysis fixed inset-0 !m-auto max-h-[94dvh] w-[97vw] max-w-6xl overflow-hidden rounded-2xl border-0 bg-white p-0 text-[var(--ink-900)] shadow-2xl backdrop:bg-slate-900/40 backdrop:backdrop-blur-sm open:flex open:flex-col" onCancel={(event) => { event.preventDefault(); requestClose(); }}>
        <header className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-7"><div className="min-w-0"><div className="mb-1 flex flex-wrap items-center gap-2"><span className="font-mono text-xs font-semibold text-blue-700">{data ? `OS ${data.order.request_number}` : 'Ordem de Serviço'}</span>{data && <StatusBadge status={data.order.status} statuses={data.statuses} />}</div><h2 ref={heading} tabIndex={-1} id="analysis-modal-title" className="text-xl font-semibold focus:outline-none">{data?.capabilities.decide ? 'Decidir sobre o serviço' : 'Analisar solicitação'}</h2>{data && <p className="mt-1 break-words text-sm text-slate-500">{data.order.subject} · {data.order.contract.code}</p>}</div><button type="button" onClick={requestClose} disabled={processing} aria-label="Fechar análise" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"><X size={20} /></button></header>
        {discard && <div role="alert" className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-900"><p>Há alterações não salvas. Deseja fechar sem salvar?</p><div className="flex gap-2"><button type="button" disabled={processing} onClick={() => setDiscard(false)} className="sig-btn sig-btn-secondary">Continuar análise</button><button type="button" disabled={processing} onClick={onClose} className="sig-btn sig-btn-secondary">Descartar e fechar</button></div></div>}
        {savedNotice && !loading && !error && <p role="status" className="shrink-0 border-b border-emerald-200 bg-emerald-50 px-5 py-3 text-sm text-emerald-800">Demanda da análise salvo com sucesso.</p>}
        {loading ? <div role="status" className="flex items-center justify-center gap-3 p-12 text-sm text-slate-500"><Loader2 size={20} className="animate-spin" />Carregando solicitação…</div> : error ? <div role="alert" className="space-y-5 p-6"><p className="text-sm text-red-800">{error}</p><div className="flex gap-2"><button type="button" onClick={() => setReload((n) => n + 1)} className="sig-btn sig-btn-primary">Tentar novamente<ArrowRight size={15} /></button><button type="button" onClick={onClose} className="sig-btn sig-btn-secondary">Fechar</button></div></div> : data && <AnalysisContent key={`${orderId}-${revision}`} data={data} tenantSlug={tenantSlug} onClose={onClose} onRequestClose={requestClose} onBusy={onBusy} onDirty={onDirty} refresh={(wasSaved = false) => { setSavedNotice(wasSaved); setReload((n) => n + 1); }} />}
    </dialog>;
}
