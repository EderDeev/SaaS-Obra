import { useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Send, X } from 'lucide-react';
import { useState } from 'react';

const emptyDivergence = () => ({ topico: 'Atividades e ocorrências', titulo: '', versao_construtora: '', versao_gerenciadora: '' });

export default function ManagerFlowActions({ rdo, obraId, renderApprovalFields }) {
    const { currentTenant } = usePage().props;
    const [action, setAction] = useState(null);
    const form = useForm({ obra_ids: [], comments_by_obra: {}, divergencia: emptyDivergence(), reservation_obra_ids: [], reservations_by_obra: {} });
    const finalApproval = rdo.workflow?.model === 'gerenciadora_construtora';
    const fronts = (rdo.obras || []).filter(obra => rdo.flow_obra_ids?.includes(Number(obra.id)));
    const returning = action === 'return';
    const open = next => {
        const first = fronts.find(obra => Number(obra.id) === Number(obraId)) || fronts[0];
        form.clearErrors();
        form.setData({ obra_ids: next !== 'return' ? fronts.map(obra => Number(obra.id)) : first ? [Number(first.id)] : [], reservation_obra_ids: [], reservations_by_obra: {}, comments_by_obra: Object.fromEntries(fronts.map(obra => {
            const record = (rdo.inspection_records || []).find(record => record.is_current_version && Number(record.obra_id) === Number(obra.id));
            return [obra.id, record?.comment || record?.summary || ''];
        })), divergencia: emptyDivergence() });
        setAction(next);
    };
    const close = () => { if (!form.processing) setAction(null); };
    const submit = event => {
        event.preventDefault();
        const finalAction = finalApproval && !returning ? form.data.reservation_obra_ids.length ? 'approve_with_reservations' : 'approve' : action;
        form.transform(data => ({
            action: finalAction, filling_party: rdo.fill_party, obra_ids: fronts.map(obra => Number(obra.id)),
            comments_by_obra: returning ? Object.fromEntries(fronts.map(obra => [obra.id, data.divergencia.versao_gerenciadora])) : Object.fromEntries(data.obra_ids.map(id => [id, data.comments_by_obra[id] || ''])),
            ...(returning && rdo.can_create_divergence ? { divergencia: { ...data.divergencia, obra_id: data.obra_ids[0] } } : {}),
            ...(finalAction === 'approve_with_reservations' ? { reservation_obra_ids: data.reservation_obra_ids, reservations_by_obra: data.reservations_by_obra } : {}),
        }));
        form.post(route('tenant.diario-obra.rdo.flow', [currentTenant.slug, rdo.id]), {
            preserveScroll: true, preserveState: false, onSuccess: () => setAction(null),
        });
    };
    const setDivergence = (key, value) => form.setData('divergencia', { ...form.data.divergencia, [key]: value });
    return <>
        <div className="border-t border-[var(--border)] p-4">
            {rdo.flow_actions?.approve && rdo.manager_forward_ready === false && <p className="mb-3 text-sm text-amber-800">Conclua os registros da gerenciadora em todas as obras para {finalApproval ? 'aprovar o RDO completo' : 'encaminhar o RDO completo ao cliente'}. A ação exige acesso a todas as obras pendentes.</p>}
            <div className="flex flex-wrap justify-end gap-3">
            {rdo.flow_actions?.return && <button type="button" onClick={() => open('return')} className="sig-btn border-red-200 bg-red-50 text-red-700"><ArrowLeft size={16} /> Devolver para a construtora</button>}
            {finalApproval && rdo.flow_actions?.approve_with_reservations && <button type="button" disabled={rdo.manager_forward_ready === false} onClick={() => open('approve_with_reservations')} className="sig-btn border-amber-200 bg-amber-50 text-amber-800 disabled:opacity-50">Aprovar com ressalvas</button>}
            {rdo.flow_actions?.approve && <button type="button" disabled={rdo.manager_forward_ready === false} onClick={() => open('approve')} className="sig-btn sig-btn-primary disabled:opacity-50"><Send size={16} /> {finalApproval ? 'Aprovar RDO' : 'Encaminhar para aprovação do cliente'}</button>}
            </div>
        </div>
        {action && <div className="fixed inset-0 z-[125] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" onMouseDown={event => event.target === event.currentTarget && close()}>
            <form role="dialog" aria-modal="true" aria-label={returning ? 'Devolver RDO para a construtora' : finalApproval ? 'Aprovação final da gerenciadora' : 'Encaminhar RDO ao cliente'} onSubmit={submit} className="max-h-[94vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white shadow-xl">
                <header className="flex items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4"><h2 className="text-lg font-bold">{returning ? 'Devolver para a construtora' : finalApproval ? 'Aprovação final da gerenciadora' : 'Encaminhar para aprovação do cliente'}</h2><button type="button" disabled={form.processing} onClick={close} className="sig-btn sig-btn-ghost" aria-label="Fechar decisão"><X size={18} /></button></header>
                <div className="space-y-4 p-5">
                    <p className="text-sm text-[var(--ink-500)]">{returning ? 'Todas as obras do RDO serão devolvidas juntas para a construtora. Selecione abaixo apenas a obra relacionada à divergência e registre o que deverá ser esclarecido.' : finalApproval ? 'Todas as obras serão aprovadas juntas. Confira o parecer e indique as ressalvas de cada obra.' : 'Todas as obras serão encaminhadas juntas. Confira o parecer de cada uma antes de confirmar.'}</p>
                    <div className="flex flex-wrap gap-2" aria-label="Obras desta decisão">{fronts.map(obra => {
                        const id = Number(obra.id), selected = form.data.obra_ids.includes(id);
                        if (!returning) return <span key={id} className="sig-pill"><CheckCircle2 size={15} /> {obra.codigo} · {obra.nome}</span>;
                        return <button key={id} type="button" disabled={form.processing} aria-pressed={selected} className={`sig-btn sig-btn-sm !whitespace-normal ${selected ? 'sig-btn-primary' : ''}`} onClick={() => form.setData('obra_ids', returning ? [id] : selected ? form.data.obra_ids.filter(value => value !== id) : [...form.data.obra_ids, id])}>{selected && <CheckCircle2 size={15} />} {obra.codigo} · {obra.nome}</button>;
                    })}</div>
                    {returning ? <div className="grid gap-4 sm:grid-cols-2">
                        <label className="block text-sm font-semibold">Tópico<select className="sig-input mt-1" value={form.data.divergencia.topico} onChange={event => setDivergence('topico', event.target.value)}>{['Atividades e ocorrências','Mão de obra','Equipamentos','Clima','Prazo','Qualidade','Segurança','Outro'].map(topic => <option key={topic}>{topic}</option>)}</select></label>
                        <label className="block text-sm font-semibold">Título da divergência<input required maxLength={180} className="sig-input mt-1" value={form.data.divergencia.titulo} onChange={event => setDivergence('titulo', event.target.value)} /></label>
                        <label className="block text-sm font-semibold">Versão registrada pela construtora<textarea required maxLength={10000} rows={4} className="sig-input mt-1" value={form.data.divergencia.versao_construtora} onChange={event => setDivergence('versao_construtora', event.target.value)} /></label>
                        <label className="block text-sm font-semibold">Constatação da gerenciadora<textarea required maxLength={5000} rows={4} className="sig-input mt-1" value={form.data.divergencia.versao_gerenciadora} onChange={event => setDivergence('versao_gerenciadora', event.target.value)} /></label>
                    </div> : finalApproval ? renderApprovalFields(form) : fronts.filter(obra => form.data.obra_ids.includes(Number(obra.id))).map(obra => <label key={obra.id} className="block text-sm font-semibold">Parecer · {obra.codigo} · {obra.nome}<textarea required maxLength={5000} rows={3} className="sig-input mt-2" value={form.data.comments_by_obra[obra.id] || ''} onChange={event => form.setData('comments_by_obra', { ...form.data.comments_by_obra, [obra.id]: event.target.value })} /></label>)}
                    {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-red-600">{error}</p>)}
                    <p className="text-sm text-[var(--ink-500)]">Ao confirmar, todos os usuários envolvidos receberão e-mail e notificação no sistema.</p>
                </div>
                <footer className="flex justify-end gap-2 border-t border-[var(--border)] px-5 py-4"><button type="button" disabled={form.processing} onClick={close} className="sig-btn">Cancelar</button><button type="submit" disabled={form.processing || !form.data.obra_ids.length} className="sig-btn sig-btn-primary">{form.processing ? 'Enviando…' : returning ? 'Confirmar devolução' : finalApproval ? form.data.reservation_obra_ids.length ? 'Confirmar aprovação com ressalvas' : 'Confirmar aprovação' : 'Confirmar encaminhamento'}</button></footer>
            </form>
        </div>}
    </>;
}
