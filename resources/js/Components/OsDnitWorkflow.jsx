import { dateLabel, numberLabel } from '@/Components/OsDnitOrderUi';
import OsDnitProductPicker from '@/Components/OsDnitProductPicker';
import OsDnitConfirmDialog from '@/Components/OsDnitConfirmDialog';
import { useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { CheckCircle, Pencil, Send, X } from 'lucide-react';

export function StatusBadge({ status, statuses }) {
    const color = ['approved', 'completed'].includes(status) ? 'bg-emerald-50 text-emerald-800' : status.startsWith('returned') ? 'bg-amber-50 text-amber-900' : status === 'draft' ? 'bg-slate-100 text-slate-700' : 'bg-blue-50 text-blue-700';
    return <span className={`h-fit rounded-full px-3 py-1 text-xs font-semibold ${color}`}>{statuses[status] || status}</span>;
}

export function SupervisionAnalysis({ items, deadline, comment }) {
    return <div className="space-y-5 p-4 sm:p-5">
        <div><p className="text-xs text-slate-500">Prazo estimado pela Supervisão</p><p className="mt-1 text-sm font-semibold">{dateLabel(deadline)}</p></div>
        <div><h3 className="text-sm font-semibold">Produtos solicitados</h3><ul className="mt-2 divide-y divide-slate-100">{items.map((item) => <li key={item.product_id} className="flex flex-wrap items-center justify-between gap-3 py-3"><p className="min-w-0 flex-1 break-words text-sm"><strong className="mr-2 font-mono text-blue-700">{item.product_code}</strong>{item.product_name}</p><span className="text-sm font-semibold">{numberLabel(item.quantity)} {item.unit}</span></li>)}</ul>{!items.length && <p className="mt-2 text-sm text-slate-500">Nenhum produto definido na análise.</p>}</div>
        {comment !== undefined && <div className="rounded-xl bg-blue-50 p-4"><h3 className="text-sm font-semibold text-blue-900">Comentário da análise</h3><p className="mt-2 whitespace-pre-wrap break-words text-sm">{comment || 'Análise ainda não registrada.'}</p></div>}
    </div>;
}

export function FinalizeAction({ order, tenantSlug }) {
    const [confirm, setConfirm] = useState(false);
    const form = useForm({ version: order.workflow_version });
    return <div><button type="button" disabled={form.processing} className="sig-btn sig-btn-primary" onClick={() => setConfirm(true)}><CheckCircle size={16} />Finalizar OS</button>
        <Errors errors={form.errors} />
        <OsDnitConfirmDialog open={confirm} title="Finalizar OS?" message={`Confirma que o serviço da OS ${order.request_number} foi concluído? A finalização será registrada no fluxo e comunicada aos responsáveis.`} confirmLabel="Finalizar OS" disabled={form.processing} onCancel={() => setConfirm(false)} onConfirm={() => { setConfirm(false); form.post(route('tenant.os-dnit.orders.finalize', [tenantSlug, order.id]), { preserveScroll: true }); }} />
    </div>;
}

function Errors({ errors }) {
    if (!Object.keys(errors).length) return null;
    return <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{[...new Set(Object.values(errors))].map((e) => <p key={e}>{e}</p>)}</div>;
}

export function DraftActions({ order, editable }) {
    const { currentTenant } = usePage().props;
    const form = useForm({ version: order.workflow_version });
    const [confirmSubmit, setConfirmSubmit] = useState(false);
    return <section className="sig-card space-y-4 p-5">
        <h2 className="text-lg font-semibold">Solicitação do analista DNIT</h2>
        <p className="text-sm text-slate-500">{editable ? 'Confira a demanda antes de enviar para análise.' : 'A solicitação já foi enviada e está bloqueada para edição, inclusive após devolução.'}</p>
        <Errors errors={form.errors} />
        <div className="flex flex-wrap gap-3">
            {editable && <Link href={route('tenant.os-dnit.orders.edit', [currentTenant.slug, order.id])} className="sig-btn sig-btn-secondary"><Pencil size={16} />Editar solicitação</Link>}
            <button type="button" disabled={form.processing} onClick={() => setConfirmSubmit(true)} className="sig-btn sig-btn-primary"><Send size={16} />{order.status === 'returned_analyst' ? 'Reenviar para Supervisão' : 'Enviar para Supervisão'}</button>
        </div>
        <OsDnitConfirmDialog open={confirmSubmit} title="Enviar OS para análise?"
            message={`Deseja enviar a OS ${order.request_number} à Supervisão? Após o envio, a solicitação ficará bloqueada para edição.`}
            disabled={form.processing} onCancel={() => setConfirmSubmit(false)} onConfirm={() => {
                setConfirmSubmit(false);
                if (!form.processing) form.post(route('tenant.os-dnit.orders.submit', [currentTenant.slug, order.id]), { preserveScroll: true });
            }} />
    </section>;
}

export function ReviewForm({ order, products, categories, frequencies }) {
    const { currentTenant } = usePage().props;
    const [confirmForward, setConfirmForward] = useState(false);
    const form = useForm({ version: order.workflow_version, review_comment: order.review_comment || '',
        estimated_deadline: order.estimated_deadline?.slice(0, 10) || order.desired_deadline.slice(0, 10),
        items: (order.review_items ?? []).map((i) => ({ product_id: i.product_id, quantity: i.quantity })) });
    const save = (mode) => {
        form.transform((data) => ({ ...data, mode }));
        form.post(route('tenant.os-dnit.orders.review', [currentTenant.slug, order.id]), { preserveScroll: true });
    };
    return <section className="sig-card" aria-labelledby="review-title"><h2 id="review-title" className="border-b border-slate-200 px-5 py-4 text-lg font-semibold">Análise da Supervisão</h2><form className="space-y-5 p-5" onSubmit={(e) => { e.preventDefault(); setConfirmForward(true); }}>
        <p className="text-sm text-slate-500">Defina os produtos e as quantidades necessários para atender à demanda.</p><Errors errors={form.errors} />
        <div><label htmlFor="review-deadline" className="mb-2 block text-sm font-semibold">Prazo estimado pela Supervisão</label><input id="review-deadline" type="date" required value={form.data.estimated_deadline} onChange={(e) => form.setData('estimated_deadline', e.target.value)} className="rounded-lg border-slate-300 text-sm" /></div>
        <div><label htmlFor="review-comment" className="mb-2 block text-sm font-semibold">Comentário da análise (obrigatório)</label><textarea id="review-comment" required rows={4} maxLength={20000} value={form.data.review_comment} onChange={(e) => form.setData('review_comment', e.target.value)} className="w-full rounded-lg border-slate-300 text-sm" /></div>
        <OsDnitProductPicker products={products} categories={categories} frequencies={frequencies} items={form.data.items} onChange={(items) => form.setData('items', items)} disabled={form.processing} />
        <div className="divide-y divide-slate-100">{form.data.items.map((item, index) => { const product = products.find((p) => p.id === item.product_id); const old = (order.review_items ?? []).find((i) => i.product_id === item.product_id); return <div key={item.product_id} className="flex flex-wrap items-center gap-4 py-3"><p className="min-w-48 flex-1 text-sm"><strong className="mr-2 text-[var(--primary)]">{old?.product_code || product?.code}</strong>{old?.product_name || product?.name}</p><label className="text-xs font-semibold">Quantidade ({old?.unit || product?.unit})<input type="number" min="0.001" max="999999999999.999" step="0.001" required value={item.quantity} onChange={(e) => form.setData('items', form.data.items.map((i, n) => n === index ? { ...i, quantity: e.target.value } : i))} className="mt-1 block w-36 rounded-lg border-slate-300 text-sm" /></label><button type="button" aria-label={`Retirar produto ${old?.product_code || product?.code}`} onClick={() => form.setData('items', form.data.items.filter((_, n) => n !== index))} className="rounded-lg p-2 text-slate-500 hover:bg-red-50"><X size={17} /></button></div>; })}</div>
        <div className="flex flex-wrap justify-end gap-3"><button type="button" onClick={(event) => { if (event.currentTarget.form.reportValidity()) save('save'); }} disabled={form.processing} className="sig-btn sig-btn-secondary">Salvar análise</button><button disabled={form.processing || !form.data.items.length} className="sig-btn sig-btn-primary"><Send size={16} />Encaminhar ao fiscal do contrato</button></div>
    </form><OsDnitConfirmDialog open={confirmForward} title="Encaminhar análise?"
        message={`Deseja encaminhar a análise da OS ${order.request_number} ao fiscal do contrato? Os produtos, as quantidades, o prazo e o comentário serão enviados para decisão.`}
        disabled={form.processing} onCancel={() => setConfirmForward(false)} onConfirm={() => { setConfirmForward(false); if (!form.processing) save('forward'); }} /></section>;
}

export function DecisionForm({ order }) {
    const { currentTenant } = usePage().props;
    const form = useForm({ version: order.workflow_version, decision: '', return_to: '', comment: '' });
    const [confirmDecision, setConfirmDecision] = useState(false);
    return <section className="sig-card p-5"><h2 className="text-lg font-semibold">Decisão do fiscal do contrato</h2><form className="mt-4 space-y-4" onSubmit={(e) => { e.preventDefault(); if (!form.processing && form.data.decision) setConfirmDecision(true); }}><Errors errors={form.errors} />
        <fieldset className="flex flex-wrap gap-5"><legend className="mb-2 text-sm font-semibold">Decisão</legend><label className="flex items-center gap-2 text-sm"><input required type="radio" name="decision" value="approve" checked={form.data.decision === 'approve'} onChange={() => form.setData('decision', 'approve')} />Aprovar serviço</label><label className="flex items-center gap-2 text-sm"><input required type="radio" name="decision" value="return" checked={form.data.decision === 'return'} onChange={() => form.setData('decision', 'return')} />Reprovar e devolver para correção</label></fieldset>
        {form.data.decision === 'return' && <div><label htmlFor="return-to" className="mb-2 block text-sm font-semibold">Devolver para</label><select id="return-to" required value={form.data.return_to} onChange={(e) => form.setData('return_to', e.target.value)} className="w-full rounded-lg border-slate-300 text-sm"><option value="">Selecione o responsável pela correção</option><option value="concremat">Supervisão — prazo, produtos ou quantidades</option><option value="analyst">Analista DNIT — pedido de serviço</option></select></div>}
        <div><label htmlFor="decision-comment" className="mb-2 block text-sm font-semibold">{form.data.decision === 'return' ? 'Motivo da reprovação' : 'Comentário da decisão'}</label><textarea id="decision-comment" required rows={4} maxLength={20000} value={form.data.comment} onChange={(e) => form.setData('comment', e.target.value)} className="w-full rounded-lg border-slate-300 text-sm" /></div>
        <div className="flex justify-end"><button className="sig-btn sig-btn-primary" disabled={!form.data.decision || form.processing}><CheckCircle size={16} />{form.data.decision === 'approve' ? 'Aprovar serviço' : 'Devolver OS'}</button></div>
    </form><OsDnitConfirmDialog open={confirmDecision}
        title={form.data.decision === 'return' ? 'Reprovar e devolver OS?' : 'Aprovar serviço?'}
        message={form.data.decision === 'return'
            ? `Deseja reprovar a OS ${order.request_number} e devolvê-la ao ${form.data.return_to === 'concremat' ? 'Supervisão' : 'analista DNIT'}? O motivo informado será registrado na decisão.`
            : `Deseja aprovar o serviço da OS ${order.request_number}? A aprovação e seu comentário serão registrados na decisão.`}
        confirmLabel={form.data.decision === 'return' ? 'Confirmar devolução' : 'Confirmar aprovação'}
        disabled={form.processing} onCancel={() => setConfirmDecision(false)} onConfirm={() => {
            setConfirmDecision(false);
            if (!form.processing) form.post(route('tenant.os-dnit.orders.decide', [currentTenant.slug, order.id]), { preserveScroll: true });
        }} /></section>;
}

const actions = { created: 'Demanda criada', legacy_draft: 'Demanda anterior preservada', draft_updated: 'Solicitação corrigida', submitted: 'Enviada à Supervisão', analysis_saved: 'Análise salva', forwarded: 'Encaminhada ao fiscal do contrato', returned: 'Reprovada e devolvida', approved: 'Demanda aprovada', completed: 'OS finalizada — serviço concluído' };
const timeLabel = (value) => new Date(value).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' });

export function OrderHistory({ events, statuses }) {
    return <section className="sig-card" aria-labelledby="order-flow-title">
        <h2 id="order-flow-title" className="border-b border-slate-200 px-5 py-4 text-lg font-semibold">Fluxo da OS</h2>
        <ol className="divide-y divide-slate-100 px-5">
            {[...events].reverse().map((event) => <li key={event.id} className="space-y-2 py-4">
                <div className="flex flex-wrap justify-between gap-2">
                    <strong className="text-sm">{actions[event.action] || event.action}</strong>
                    <time dateTime={event.created_at} className="text-xs text-slate-500">{timeLabel(event.created_at)}</time>
                </div>
                <p className="text-xs text-slate-500">{event.actor?.name || 'Usuário indisponível'} · {statuses[event.to_status] || event.to_status}</p>
                {event.comment && <p className="whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 text-sm">{event.comment}</p>}
            </li>)}
            {events.length === 0 && <li className="py-5 text-sm text-slate-500">OS cadastrada antes da implantação deste fluxo. O histórico será registrado a partir da próxima ação.</li>}
        </ol>
    </section>;
}
