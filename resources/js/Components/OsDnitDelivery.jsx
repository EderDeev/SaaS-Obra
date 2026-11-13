import { useEffect, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { CheckCircle2, Download, FileText, Send, Upload, X } from 'lucide-react';
import OsDnitConfirmDialog from '@/Components/OsDnitConfirmDialog';
import axios from 'axios';

export const deliveryLabel = (delivery) => delivery.revision ? `Revisão ${delivery.revision}` : null;
const time = (value) => new Date(value).toLocaleString('pt-BR');

function DeliveryFile({ delivery, links }) {
    return <div className="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
        <a href={links[delivery.id]} className="inline-flex max-w-full items-center gap-2 break-all text-sm font-semibold text-blue-700 underline"><FileText size={18} className="shrink-0" />{delivery.original_name}<Download size={16} className="shrink-0" /></a>
        <p className="whitespace-pre-wrap break-words text-sm">{delivery.comment}</p>
    </div>;
}

export function OsDnitDeliveryAction({ order, tenantSlug, review = false }) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const request = useRef(null);
    useEffect(() => () => request.current?.abort(), []);
    const open = async () => {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        setLoading(true);
        setError('');
        try {
            const response = await axios.get(route('tenant.os-dnit.orders.delivery-form', [tenantSlug, order.id]), { signal: controller.signal });
            if (!controller.signal.aborted) setData(response.data);
        } catch (exception) {
            if (!controller.signal.aborted) setError([403, 404].includes(exception.response?.status)
                ? 'A entrega não está disponível nesta etapa ou para seu usuário. Atualize a listagem.'
                : 'Não foi possível abrir a entrega. Tente novamente.');
        } finally {
            if (!controller.signal.aborted) setLoading(false);
        }
    };
    return <div><button type="button" onClick={open} disabled={loading} className="sig-btn sig-btn-primary"><Upload size={16} />{loading ? 'Carregando…' : review ? 'Avaliar entrega' : 'Entregar demanda'}</button>
        {error && <p role="alert" className="mt-2 max-w-sm text-sm text-red-700">{error}</p>}
        {data && <DeliveryModal order={data.order} tenantSlug={tenantSlug} review={review} links={data.deliveryLinks} onClose={() => setData(null)} />}
    </div>;
}

function DeliveryModal({ order, tenantSlug, review, links, onClose }) {
    const dialog = useRef(null);
    const latest = order.deliveries.at(-1);
    const revision = latest ? latest.revision + 1 : 0;
    const [confirm, setConfirm] = useState(false);
    const form = useForm({ version: order.workflow_version, file: null, comment: '', decision: '' });
    useEffect(() => {
        const previous = document.activeElement;
        const previousOverflow = document.body.style.overflow;
        const element = dialog.current;
        element.showModal();
        document.body.style.overflow = 'hidden';
        return () => { element.close(); document.body.style.overflow = previousOverflow; if (previous?.isConnected) previous.focus(); };
    }, []);
    const close = () => { if (!form.processing) onClose(); };
    const submit = () => {
        setConfirm(false);
        form.transform(({ file, decision, ...data }) => review ? { ...data, decision } : { ...data, file });
        form.post(route(review ? 'tenant.os-dnit.orders.review-delivery' : 'tenant.os-dnit.orders.deliver', [tenantSlug, order.id]), {
            preserveScroll: true, forceFormData: !review, onSuccess: onClose,
        });
    };
    return <><dialog ref={dialog} aria-labelledby="os-delivery-title" onCancel={(event) => { event.preventDefault(); close(); }}
        className="os-dnit-dialog fixed inset-0 !m-auto max-h-[90dvh] w-[94vw] max-w-2xl overflow-y-auto rounded-2xl border-0 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-900/40 backdrop:backdrop-blur-sm">
        <header className="flex items-start justify-between gap-4 border-b border-slate-200 p-5"><div><p className="text-xs font-semibold text-blue-700">OS {order.request_number}{(review ? latest.revision : revision) > 0 && <> · Revisão {review ? latest.revision : revision}</>}</p><h2 id="os-delivery-title" className="mt-1 text-lg font-semibold">{review ? 'Avaliar entrega do produto' : 'Entregar produto'}</h2></div><button type="button" disabled={form.processing} onClick={close} aria-label="Fechar" className="rounded-lg p-2 hover:bg-slate-100"><X size={20} /></button></header>
        <form onSubmit={(event) => { event.preventDefault(); setConfirm(true); }} className="space-y-5 p-5">
            {Object.keys(form.errors).length > 0 && <div role="alert" className="rounded-lg bg-red-50 p-4 text-sm text-red-800">{Object.entries(form.errors).map(([key, error]) => <p key={key}>{error}</p>)}</div>}
            {review ? <><DeliveryFile delivery={latest} links={links} /><fieldset><legend className="mb-2 text-sm font-semibold">A entrega atendeu à demanda?</legend><div className="flex flex-wrap gap-3">{[['approve', 'Aprovar entrega'], ['reject', 'Não aprovar']].map(([value, label]) => <button key={value} type="button" disabled={form.processing} aria-pressed={form.data.decision === value} onClick={() => form.setData('decision', value)} className={`rounded-lg border px-4 py-2 text-sm font-semibold ${value === 'approve' ? form.data.decision === value ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-emerald-300 bg-emerald-50 text-emerald-800' : form.data.decision === value ? 'border-red-600 bg-red-600 text-white' : 'border-red-300 bg-red-50 text-red-800'}`}>{label}</button>)}</div></fieldset></> : <>
                {latest?.decision === 'reject' && <div className="rounded-xl bg-red-50 p-4 text-sm text-red-900"><h3 className="font-semibold">Motivo da revisão</h3><p className="mt-2 whitespace-pre-wrap break-words">{latest.review_comment}</p></div>}
                <label className="block text-sm font-semibold">Arquivo da entrega<input type="file" required disabled={form.processing} accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip" onChange={(event) => form.setData('file', event.target.files[0] || null)} className="mt-2 block w-full rounded-lg border border-slate-300 p-3 text-sm font-normal" /><span className="mt-2 block text-xs font-normal text-slate-500">PDF, Word, Excel, imagem ou ZIP. Até 30 MB.</span></label>
            </>}
            <label className="block text-sm font-semibold">{review ? form.data.decision === 'reject' ? 'Por que a entrega não atendeu à demanda?' : 'Considerações sobre a entrega' : 'Comentário sobre o que foi entregue'}<textarea required rows={4} maxLength={20000} disabled={form.processing} value={form.data.comment} onChange={(event) => form.setData('comment', event.target.value)} className="mt-2 w-full rounded-lg border-slate-300 text-sm font-normal" /></label>
            {form.progress && <p role="status" className="text-sm text-blue-700">Enviando arquivo: {form.progress.percentage}%</p>}
            <footer className="flex flex-wrap justify-end gap-3"><button type="button" onClick={close} disabled={form.processing} className="sig-btn sig-btn-secondary">Cancelar</button><button disabled={form.processing || (review && !form.data.decision)} className={`sig-btn ${review ? form.data.decision === 'reject' ? '!bg-red-600 !text-white' : '!bg-emerald-600 !text-white' : 'sig-btn-primary'}`}><Send size={16} />{form.processing ? 'Salvando…' : review ? form.data.decision === 'reject' ? 'Solicitar revisão' : 'Aprovar entrega' : 'Enviar entrega'}</button></footer>
        </form>
    </dialog><OsDnitConfirmDialog open={confirm} title={review ? form.data.decision === 'reject' ? 'Solicitar revisão da entrega?' : 'Aprovar entrega?' : 'Enviar entrega do produto?'} message={review ? form.data.decision === 'reject' ? 'A justificativa será enviada à Supervisão para uma nova revisão do produto.' : 'Ao confirmar o aceite, a OS será finalizada automaticamente e ficará bloqueada para novas alterações.' : 'O arquivo e seu comentário serão enviados ao analista DNIT solicitante para avaliação.'} confirmLabel={review ? form.data.decision === 'reject' ? 'Confirmar revisão' : 'Confirmar aprovação' : 'Confirmar entrega'} disabled={form.processing} onCancel={() => setConfirm(false)} onConfirm={submit} /></>;
}

export default function OsDnitDelivery({ order, capabilities, links, tenantSlug }) {
    const [modal, setModal] = useState(false);
    const deliveries = order.deliveries || [];
    if (!deliveries.length && !['approved', 'delivery_revision', 'awaiting_delivery_review', 'delivery_accepted'].includes(order.status)) return null;
    const latest = deliveries.at(-1);
    return <section className="sig-card" aria-labelledby="delivery-heading"><header className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-5"><div><h2 id="delivery-heading" className="text-lg font-semibold">Entrega do produto</h2><p className="mt-1 text-sm text-slate-500">{order.status === 'delivery_revision' ? `Revisão ${(latest?.revision ?? 0) + 1} solicitada` : order.status === 'approved' ? 'Aguardando entrega da Supervisão' : order.status === 'awaiting_delivery_review' ? 'Aguardando avaliação do analista DNIT solicitante' : 'Entrega aprovada pelo analista DNIT'}</p></div>{(capabilities.deliver || capabilities.reviewDelivery) && <button type="button" onClick={() => setModal(true)} className="sig-btn sig-btn-primary">{capabilities.deliver ? <Upload size={16} /> : <CheckCircle2 size={16} />}{capabilities.deliver ? latest ? 'Enviar revisão' : 'Entregar produto' : 'Avaliar entrega'}</button>}</header>
        <div className="divide-y divide-slate-200 px-5">{[...deliveries].reverse().map((delivery, index) => <details key={delivery.id} open={index === 0} className="py-4"><summary className="cursor-pointer text-sm font-semibold">{deliveryLabel(delivery) && <>{deliveryLabel(delivery)} · </>}{delivery.decision === 'approve' ? 'Aprovada' : delivery.decision === 'reject' ? 'Não aprovada' : 'Aguardando avaliação'}</summary><div className="mt-3 space-y-3"><p className="text-xs text-slate-500">{delivery.creator?.name} · {time(delivery.created_at)}</p><DeliveryFile delivery={delivery} links={links} />{delivery.decision && <div className={`rounded-xl p-4 text-sm ${delivery.decision === 'approve' ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-red-900'}`}><p className="font-semibold">{delivery.reviewer?.name} · {time(delivery.reviewed_at)}</p><p className="mt-2 whitespace-pre-wrap break-words">{delivery.review_comment}</p></div>}</div></details>)}</div>
        {modal && <DeliveryModal key={order.workflow_version} order={order} tenantSlug={tenantSlug} review={capabilities.reviewDelivery} links={links} onClose={() => setModal(false)} />}
    </section>;
}
