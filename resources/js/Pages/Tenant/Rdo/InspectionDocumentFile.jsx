import { FileArchive, FileSpreadsheet, FileText } from 'lucide-react';

export default function InspectionDocumentFile({ file }) {
    const extension = file.name?.split('.').pop()?.toUpperCase() || 'ARQUIVO';
    const Icon = ['ZIP', 'RAR', '7Z'].includes(extension) ? FileArchive : ['XLS', 'XLSX', 'CSV'].includes(extension) ? FileSpreadsheet : FileText;
    const bytes = Number(file.size || 0);
    const size = bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB` : `${Math.max(1, Math.ceil(bytes / 1024))} KB`;
    return <div className="flex min-w-0 flex-1 items-center gap-3"><span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600"><Icon size={21} aria-hidden="true" /></span><div className="min-w-0"><p className="break-all text-sm font-semibold">{file.name}</p><p className="mt-0.5 text-xs text-[var(--ink-500)]">{extension} · {size}</p></div></div>;
}
