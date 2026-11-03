import { CheckCircle2, Columns2, MessageSquareText, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { ReadSection, RdoSection } from './DocumentWorkspace';
import { rdoParty } from './partyAppearance';
import { partyLabels } from './WorkflowEditor';

export default function ClientReviewPanel({ rdo, form, processing, onAction, renderFields, catalogs, renderEvidence, obraId }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(false);
    const [decision, setDecision] = useState(null);
    const hasReservations = (form.data.reservation_obra_ids || []).length > 0;
    const editor = useRef(null);
    const [frontId, setFrontId] = useState(null);
    const [tab, setTab] = useState('atividades');
    const dialog = useRef(null);
    const titleId = useId();
    const fronts = (rdo.obras?.length ? rdo.obras : [rdo.obra]).filter(Boolean);
    const currentId = frontId ?? fronts[0]?.id;
    const front = fronts.find(item => Number(item.id) === Number(currentId));
    const fillingParty = (rdo.fill_parties || [rdo.fill_party]).includes('construtora') ? 'construtora' : rdo.fill_party;
    const compare = fillingParty === 'construtora';
    const data = rdo.sections_by_party?.[fillingParty]?.[currentId] || (rdo.fill_party === fillingParty ? rdo.sections?.[currentId] : {}) || {};
    const inspection = (rdo.inspection_records || []).find(record => record.is_current_version && Number(record.obra_id) === Number(currentId));
    const actions = Object.entries(rdo.flow_actions || {});
    useEffect(() => {
        if (!open || !dialog.current) return;
        const node = dialog.current;
        const previous = document.body.style.overflow;
        node.showModal();
        document.body.style.overflow = 'hidden';
        return () => { node.close(); document.body.style.overflow = previous; };
    }, [open]);
    useEffect(() => {
        if (!editing || !editor.current) return;
        const node = editor.current, previous = document.body.style.overflow;
        node.showModal(); document.body.style.overflow = 'hidden';
        return () => { node.close(); document.body.style.overflow = previous; };
    }, [editing]);
    const startDecision = action => {
        form.clearErrors();
        setDecision(action); setEditing(true);
    };
    const finishDecision = event => {
        event.preventDefault();
        const finalDecision = ['approve', 'approve_with_reservations'].includes(decision) ? hasReservations && rdo.flow_actions?.approve_with_reservations ? 'approve_with_reservations' : 'approve' : decision;
        if (finalDecision) onAction(finalDecision, finalDecision === 'return' ? {
            return_to: form.data.return_to, comment: form.data.comment,
            comments_by_obra: Object.fromEntries((rdo.flow_obra_ids || []).map(id => [id, form.data.comment])),
        } : {});
        setEditing(false);
    };
    const show = () => {
        setFrontId(fronts.some(front => Number(front.id) === Number(obraId)) ? obraId : fronts[0]?.id);
        setTab('atividades');
        setOpen(true);
    };
    const read = key => <ReadSection section={key} data={data[key] || {}} catalogs={catalogs} rdo={{ ...rdo, fill_party: fillingParty, participating_parties: [fillingParty] }} obraId={currentId} />;
    return <section aria-label="Manifestação do cliente" data-rdo-party={rdoParty('cliente')} className="rdo-party-panel mt-5 overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-sm">
        <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4"><div className="flex items-center gap-3"><span className="rdo-party-accent rounded-lg bg-emerald-50 p-2 text-emerald-700"><MessageSquareText size={20} /></span><h2 className="rdo-party-label text-base font-semibold">Manifestação do cliente</h2></div><button type="button" className="sig-btn sig-btn-primary rdo-party-selected !px-5 !py-3 shadow-sm" onClick={show}><Columns2 size={20} /> {compare ? 'Comparar registros' : 'Visualizar registros'}</button></header>
        <div className="space-y-4 p-5">
            <button type="button" className="sig-btn sig-btn-primary rdo-party-selected !px-5 !py-3 font-semibold shadow-sm" onClick={() => startDecision(null)}><MessageSquareText size={20} /> Comentários e ressalvas</button>
            {Object.entries(form.errors).filter(([key]) => ['action', 'obra_ids', 'comments_by_obra', 'reservations_by_obra'].includes(key)).map(([key, error]) => <p key={key} role="alert" className="text-sm text-red-600">{error}</p>)}
            <div className="flex flex-wrap justify-end gap-2">{actions.map(([key, action]) => <button key={key} type="button" disabled={processing || form.processing || !rdo.flow_obra_ids?.length} onClick={() => startDecision(key)} className={`sig-btn ${action.tone === 'primary' ? 'sig-btn-primary' : action.tone === 'success' ? 'border-emerald-600 bg-emerald-600 text-white' : action.tone === 'warning' ? 'border-amber-500 bg-amber-50 text-amber-800' : 'border-red-500 bg-red-50 text-red-700'}`}><CheckCircle2 size={16} /> {action.label}</button>)}</div>
        </div>
        {editing && <dialog ref={editor} aria-label="Comentários e decisão do cliente" onCancel={event => { event.preventDefault(); setEditing(false); }} className="fixed inset-0 m-auto max-h-[94dvh] w-[calc(100%_-_1.5rem)] max-w-3xl rounded-xl border border-[var(--border)] bg-white p-0 text-[var(--ink-900)] shadow-xl backdrop:bg-slate-950/60">
            <form onSubmit={finishDecision}><header className="flex items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4"><h2 className="text-lg font-bold">{decision === 'return' ? 'Motivo e destino da devolução' : hasReservations ? 'Comentários e ressalvas' : 'Comentários do cliente'}</h2><button type="button" className="sig-btn sig-btn-sm" aria-label="Fechar comentários" onClick={() => setEditing(false)}><X size={18} /></button></header>
            <div className="max-h-[calc(94dvh_-_10rem)] space-y-4 overflow-y-auto p-5">{decision === 'return' ? <><label className="block text-sm font-semibold">Devolver para<select required aria-label="Destino da devolução" className="sig-input mt-2" value={form.data.return_to} onChange={event => form.setData('return_to', event.target.value)}><option value="">Selecione o destino</option>{(rdo.fill_parties || [rdo.fill_party]).includes('construtora') && <option value="construtora">Construtora</option>}{((rdo.workflow?.review_parties || []).includes('gerenciadora') || rdo.fill_party === 'gerenciadora') && <option value="gerenciadora">Gerenciadora</option>}</select></label><label className="block text-sm font-semibold">Justificativa da devolução<textarea required maxLength={5000} aria-label="Justificativa da devolução" className="sig-input mt-2" rows={4} value={form.data.comment} onChange={event => form.setData('comment', event.target.value)} /></label><p className="text-sm text-[var(--ink-500)]">O RDO inteiro será devolvido, com todas as obras.</p></> : renderFields('approve_with_reservations')}
{Object.values(form.errors).map((error, index) => <p key={index} role="alert" className="text-sm text-red-600">{error}</p>)}</div>
            <footer className="flex justify-end gap-2 border-t border-[var(--border)] px-5 py-3"><button type="button" className="sig-btn" onClick={() => setEditing(false)}>Cancelar</button><button type="submit" className="sig-btn sig-btn-primary">{decision ? 'Continuar para confirmação' : 'Concluir preenchimento'}</button></footer></form>
        </dialog>}
        {open && <dialog ref={dialog} aria-labelledby={titleId} onCancel={event => { event.preventDefault(); setOpen(false); }} className="fixed inset-0 m-auto max-h-[94dvh] w-[calc(100%_-_1.5rem)] max-w-7xl rounded-xl border border-[var(--border)] bg-white p-0 text-[var(--ink-900)] shadow-2xl backdrop:bg-slate-950/60 backdrop:backdrop-blur-sm">
            <header className="flex items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4"><div><h2 id={titleId} className="text-lg font-bold">{compare ? 'Comparar registros do RDO' : 'Registros do RDO'}</h2><p className="mt-1 text-xs text-[var(--ink-500)]">{rdo.code} · {rdo.reference_date_formatted}</p></div><button type="button" className="sig-btn sig-btn-sm" aria-label="Fechar comparação" onClick={() => setOpen(false)}><X size={18} /></button></header>
            <div className="max-h-[calc(94dvh_-_10rem)] space-y-4 overflow-y-auto p-4 sm:p-5">
                <div className="flex flex-wrap gap-2" aria-label="Obra em comparação">{fronts.map(item => <button key={item.id} type="button" className={`sig-btn sig-btn-sm !whitespace-normal ${Number(item.id) === Number(currentId) ? 'sig-btn-primary' : ''}`} aria-pressed={Number(item.id) === Number(currentId)} onClick={() => setFrontId(item.id)}>{item.codigo} · {item.nome}</button>)}</div>
                <div className="flex flex-wrap gap-2" aria-label="Registro em comparação">{[['atividades','Atividades'],['comentarios','Comentários'],['fotos','Evidências'],['clima','Condições do tempo'],['recursos','Mão de obra e equipamentos']].map(([key,label]) => <button type="button" key={key} aria-pressed={tab === key} className={`sig-btn sig-btn-sm ${tab === key ? 'sig-btn-primary' : ''}`} onClick={() => setTab(key)}>{label}</button>)}</div>
                <p className="text-sm font-semibold">{front?.codigo} · {front?.nome}</p>
                <div className={`grid items-start gap-4 ${compare ? 'lg:grid-cols-2' : ''}`}>
                    <section aria-label={`Comparação: ${partyLabels[fillingParty]}`} data-rdo-party={rdoParty(fillingParty)} className="rdo-party-panel overflow-hidden rounded-xl border border-[var(--border)] bg-white"><header className="rdo-party-label border-b border-[var(--border)] px-4 py-3 font-semibold">{partyLabels[fillingParty]}</header><div className="p-4">{tab === 'recursos' ? <div className="space-y-3">{[['mao_obra','Mão de obra'],['equipamentos','Equipamentos']].map(([key,label]) => <RdoSection key={key} section={key} title={label}>{read(key)}</RdoSection>)}</div> : read(tab)}</div></section>
                    {compare && <section aria-label="Comparação: Gerenciadora" data-rdo-party={rdoParty('gerenciadora')} className="rdo-party-panel overflow-hidden rounded-xl border border-[var(--border)] bg-white"><header className="rdo-party-label border-b border-[var(--border)] px-4 py-3 font-semibold">Gerenciadora{inspection?.user?.name && <span className="mt-1 block text-xs font-normal text-[var(--ink-500)]">{inspection.user.name}</span>}</header><div className="p-4">
                        {tab === 'atividades' && (inspection?.activities?.length ? <ReadSection section="atividades" data={{ atividades: inspection.activities }} catalogs={catalogs} rdo={rdo} obraId={currentId} /> : <p className="whitespace-pre-wrap break-words text-sm">{inspection?.summary || 'Nenhuma atividade da gerenciadora registrada nesta obra.'}</p>)}
                        {tab === 'comentarios' && <p className="whitespace-pre-wrap break-words text-sm leading-relaxed">{inspection?.comment || rdo.approval_comments?.gerenciadora?.[currentId]?.comment || 'Nenhum comentário registrado nesta obra.'}</p>}
                        {tab === 'fotos' && (inspection?.evidence?.length ? renderEvidence(inspection.evidence) : <p className="text-sm text-[var(--ink-500)]">Nenhuma evidência da gerenciadora nesta obra.</p>)}
                        {tab === 'clima' && (inspection?.weather ? <ReadSection section="clima" data={inspection.weather} catalogs={catalogs} rdo={rdo} obraId={currentId} /> : <p className="text-sm text-[var(--ink-500)]">Nenhuma condição climática registrada pela gerenciadora nesta obra.</p>)}
                        {tab === 'recursos' && <p className="text-sm text-[var(--ink-500)]">Mão de obra e equipamentos são registrados pela construtora. Consulte as atividades e os comentários da gerenciadora para conferir sua análise.</p>}
                    </div></section>}
                </div>
            </div>
            <footer className="flex justify-end border-t border-[var(--border)] px-5 py-3"><button type="button" className="sig-btn" onClick={() => setOpen(false)}>Voltar à manifestação</button></footer>
        </dialog>}
    </section>;
}
