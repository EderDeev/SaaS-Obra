import { ChevronDown, Download, FileText } from 'lucide-react';
import { useEffect, useRef } from 'react';

const options = [['opening', 'Abertura'], ['analysis', 'Abertura + proposta + análise'], ['evidence', 'Abertura + proposta + análise + evidência'], ['signed', 'PDF completo assinado']];

export default function PdfDownload({ tenant, rnc }) {
    const details = useRef(null);
    useEffect(() => {
        const outside = (event) => { if (!details.current?.contains(event.target) && details.current) details.current.open = false; };
        const escape = (event) => { if (event.key === 'Escape' && details.current?.open) { details.current.open = false; details.current.querySelector('summary')?.focus(); } };
        document.addEventListener('pointerdown', outside); document.addEventListener('keydown', escape);
        return () => { document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', escape); };
    }, []);
    return <details ref={details} className="relative w-full min-w-0 sm:w-auto"><summary className="sig-btn sig-btn-secondary w-full cursor-pointer list-none [&::-webkit-details-marker]:hidden" aria-label={`Opções de PDF da RNC ${rnc.formatted_number}`}><Download size={16} />Baixar PDF<ChevronDown size={14} /></summary>
        <div className="static z-50 mt-2 grid w-full min-w-0 gap-1 rounded-xl border border-slate-200 bg-white p-2 text-left shadow-xl sm:absolute sm:right-0 sm:w-80 sm:max-w-[calc(100vw-2rem)]">{options.map(([scope, label]) => scope === 'signed' && !rnc.has_signed_pdf ? <span key={scope} aria-disabled="true" className="flex items-start gap-3 rounded-lg px-3 py-3 text-sm text-slate-400"><FileText size={16} className="mt-0.5 shrink-0" /><span>{label}<small className="mt-1 block">Disponível após todas as assinaturas.</small></span></span> : <a key={scope} className="flex items-start gap-3 rounded-lg px-3 py-3 text-sm font-medium text-slate-800 hover:bg-blue-50" href={route('tenant.qualidade.rnc.pdf', { tenant: tenant.slug, rnc: rnc.id, scope })} onClick={() => { details.current.open = false; }}><FileText size={16} className="mt-0.5 shrink-0 text-blue-600" /><span>{label}</span></a>)}</div>
    </details>;
}
