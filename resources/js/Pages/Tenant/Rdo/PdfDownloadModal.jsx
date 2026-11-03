import { Download, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { partyLabels } from './WorkflowEditor';

export default function PdfDownloadModal({ rdo, pdfUrl, onClose }) {
    const workflow = rdo.workflow || { fill_parties: ['construtora'], review_parties: ['gerenciadora', 'cliente'] };
    const parties = [...new Set([...(workflow.fill_parties || [workflow.fill_party || 'construtora']), ...(workflow.review_parties || [])])];
    const fronts = (rdo.obras?.length ? rdo.obras : [rdo.obra]).filter(Boolean);
    const [stage, setStage] = useState(parties.at(-1));
    const [selected, setSelected] = useState(fronts.map(front => Number(front.id)));
    const dialog = useRef(null);
    const title = useId();
    const signed = stage === 'signed';
    useEffect(() => {
        const node = dialog.current;
        const overflow = document.body.style.overflow;
        node.showModal();
        document.body.style.overflow = 'hidden';
        return () => { node.close(); document.body.style.overflow = overflow; };
    }, []);
    const query = new URLSearchParams({ stage, viewer: '1' });
    selected.forEach(id => query.append('obra_ids[]', id));
    const url = signed ? rdo.signature?.signed_download_url : `${pdfUrl}${pdfUrl.includes('?') ? '&' : '?'}${query}`;
    const canDownload = Boolean(url) && (signed || selected.length > 0);
    const toggle = id => setSelected(current => current.includes(id) ? current.filter(value => value !== id) : [...current, id]);

    return <dialog ref={dialog} onCancel={onClose} aria-labelledby={title} className="m-auto w-[calc(100%_-_2rem)] max-w-xl overflow-hidden rounded-2xl border border-[var(--border)] bg-white p-0 shadow-2xl backdrop:bg-slate-950/50">
        <header className="flex items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4"><div><h2 id={title} className="text-lg font-bold">Baixar PDF</h2><p className="mt-1 text-xs text-slate-500">{rdo.code} · {rdo.reference_date_formatted}</p></div><button type="button" onClick={onClose} className="sig-btn sig-btn-sm" aria-label="Fechar opções do PDF"><X size={18} /></button></header>
        <div className="max-h-[65dvh] space-y-5 overflow-y-auto p-5">
            <fieldset className="space-y-2"><legend className="mb-2 text-sm font-bold">Etapas do documento</legend>
                {parties.map((party, index) => <label key={party} className={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm ${stage === party ? 'border-blue-500 bg-blue-50' : 'border-slate-200'}`}><input type="radio" name={`${title}-stage`} value={party} checked={stage === party} onChange={() => setStage(party)} /><span>{parties.slice(0, index + 1).map(role => partyLabels[role]).join(' + ')}</span></label>)}
                <label className={`flex items-center gap-3 rounded-lg border p-3 text-sm ${!rdo.signature?.signed_download_url ? 'cursor-not-allowed text-slate-400' : 'cursor-pointer'} ${signed ? 'border-blue-500 bg-blue-50' : 'border-slate-200'}`}><input type="radio" name={`${title}-stage`} value="signed" disabled={!rdo.signature?.signed_download_url} checked={signed} onChange={() => setStage('signed')} /><span>Completo assinado{!rdo.signature?.signed_download_url && <span className="mt-1 block text-xs">Disponível após a conclusão das assinaturas.</span>}</span></label>
            </fieldset>
            <fieldset disabled={signed} className="space-y-2"><legend className="mb-2 text-sm font-bold">Frentes de serviço</legend>
                {signed ? <p className="rounded-lg bg-violet-50 p-3 text-sm text-violet-800">O PDF assinado contém todas as frentes do documento original. Ele é baixado sem alterações para preservar as assinaturas.</p> : <button type="button" onClick={() => setSelected(selected.length === fronts.length ? [] : fronts.map(front => Number(front.id)))} className="text-sm font-semibold text-blue-600">{selected.length === fronts.length ? 'Desmarcar todas' : 'Selecionar todas'}</button>}
                {fronts.map(front => <label key={front.id} className={`flex items-center gap-3 rounded-lg border p-3 text-sm ${signed ? 'opacity-60' : 'cursor-pointer'} ${selected.includes(Number(front.id)) || signed ? 'border-blue-200 bg-blue-50/50' : 'border-slate-200'}`}><input type="checkbox" checked={signed || selected.includes(Number(front.id))} onChange={() => toggle(Number(front.id))} /><span>{front.codigo} · {front.nome}</span></label>)}
                {!signed && !selected.length && <p role="alert" className="text-sm text-amber-700">Selecione ao menos uma frente.</p>}
            </fieldset>
        </div>
        <footer className="flex justify-end gap-2 border-t border-[var(--border)] px-5 py-4"><button type="button" onClick={onClose} className="sig-btn">Cancelar</button>{canDownload ? <a href={url} target="_blank" rel="noreferrer" className="sig-btn sig-btn-primary"><Download size={16} /> Baixar PDF</a> : <button type="button" disabled className="sig-btn sig-btn-primary opacity-50"><Download size={16} /> Baixar PDF</button>}</footer>
    </dialog>;
}
