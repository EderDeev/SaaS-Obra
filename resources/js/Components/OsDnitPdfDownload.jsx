import { ChevronDown, Download, FileText } from 'lucide-react';
import { useEffect, useRef } from 'react';

const options = [
    ['analyst', 'Somente solicitação do analista', 'Abertura do pedido e produtos solicitados'],
    ['concremat', 'Solicitação + Análise Supervisão', 'Pedido original e análise técnica'],
    ['inspector', 'Solicitação + análises Concremat e fiscal', 'Inclui a decisão do fiscal do contrato'],
    ['complete', 'Documento completo com o fluxo', 'Todas as etapas e o histórico de movimentações'],
];

export default function OsDnitPdfDownload({ order, tenantSlug, className = '' }) {
    const details = useRef(null);
    useEffect(() => {
        const closeOutside = (event) => {
            if (details.current?.open && !details.current.contains(event.target)) details.current.open = false;
        };
        const escape = (event) => {
            if (event.key === 'Escape' && details.current?.open) {
                details.current.open = false;
                details.current.querySelector('summary')?.focus();
                event.stopPropagation();
            }
        };
        document.addEventListener('pointerdown', closeOutside);
        document.addEventListener('keydown', escape);
        return () => {
            document.removeEventListener('pointerdown', closeOutside);
            document.removeEventListener('keydown', escape);
        };
    }, []);

    return <details ref={details} className={`relative block w-full min-w-0 sm:inline-block sm:w-auto ${className}`}>
        <summary className="sig-btn sig-btn-secondary w-full cursor-pointer list-none sm:w-auto [&::-webkit-details-marker]:hidden" aria-label={`Opções para baixar PDF da OS ${order.request_number}`}><Download size={16} />Baixar PDF<ChevronDown size={14} /></summary>
        <div className="static z-50 mt-2 w-full min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white p-1.5 text-left shadow-xl sm:absolute sm:right-0 sm:w-80 sm:max-w-[calc(100vw-2rem)]" aria-label="Escolher conteúdo do PDF">
            {options.map(([scope, title, description]) => <a key={scope} href={route('tenant.os-dnit.orders.pdf', { tenant: tenantSlug, order: order.id, scope })} onClick={() => { details.current.open = false; }} className="flex items-start gap-3 rounded-lg px-3 py-3 text-slate-800 hover:bg-blue-50 focus:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500">
                <FileText size={17} className="mt-0.5 shrink-0 text-blue-600" /><span className="min-w-0 break-words"><span className="block text-sm font-semibold">{title}</span><span className="mt-1 block text-xs leading-relaxed text-slate-500">{description}</span></span>
            </a>)}
        </div>
    </details>;
}
