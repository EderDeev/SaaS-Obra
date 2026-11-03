import { ArrowDown, ArrowUp, FileText, ImagePlus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import InspectionDocumentFile from './InspectionDocumentFile';

export default function InspectionEvidenceEditor({ form, existing = [] }) {
    const [previews, setPreviews] = useState([]);
    const [error, setError] = useState('');
    const data = form.data;
    const files = data.evidencias || [];
    const order = data.evidencias_ordem || [];
    useEffect(() => {
        const urls = files.map(file => file.type.startsWith('image/') ? URL.createObjectURL(file) : null);
        setPreviews(urls);
        return () => urls.forEach(url => url && URL.revokeObjectURL(url));
    }, [files]);
    const change = patch => form.setData({ ...data, ...patch });
    const add = event => {
        const incoming = Array.from(event.target.files || []);
        event.target.value = '';
        const totalSize = order.reduce((sum, key) => {
            const [kind, i] = key.split(':');
            return sum + Number(kind === 'existing' ? existing[i]?.size || 0 : files[i]?.size || 0);
        }, 0) + incoming.reduce((sum, file) => sum + file.size, 0);
        if (order.length + incoming.length > 10 || totalSize > 50 * 1024 * 1024 || incoming.some(file => file.size > 15 * 1024 * 1024)) {
            setError('Limite de 10 evidências, 15 MB por arquivo e 50 MB no total.');
            return;
        }
        setError('');
        change({ evidencias: [...files, ...incoming], evidencias_metadados: [...(data.evidencias_metadados || []), ...incoming.map(() => ({ comment: '' }))], evidencias_ordem: [...order, ...incoming.map((_, i) => `new:${files.length + i}`)] });
    };
    const remove = key => {
        const [kind, raw] = key.split(':');
        const index = Number(raw);
        if (kind === 'existing') return change({ evidencias_ordem: order.filter(item => item !== key) });
        change({
            evidencias: files.filter((_, i) => i !== index),
            evidencias_metadados: (data.evidencias_metadados || []).filter((_, i) => i !== index),
            evidencias_ordem: order.filter(item => item !== key).map(item => {
                const [type, n] = item.split(':');
                return type === 'new' && Number(n) > index ? `new:${Number(n) - 1}` : item;
            }),
        });
    };
    const move = (index, direction) => {
        const next = [...order];
        [next[index], next[index + direction]] = [next[index + direction], next[index]];
        change({ evidencias_ordem: next });
    };
    const comment = (key, value) => {
        const [kind, raw] = key.split(':');
        const index = Number(raw);
        if (kind === 'existing') change({ evidencias_comentarios: { ...data.evidencias_comentarios, [index]: value } });
        else change({ evidencias_metadados: (data.evidencias_metadados || []).map((item, i) => i === index ? { ...item, comment: value } : item) });
    };
    return <section className="space-y-3" aria-label="Evidências da gerenciadora">
        <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="text-sm font-bold">Evidências desta frente</h3><label className="sig-btn sig-btn-secondary sig-btn-sm"><ImagePlus size={15} /> Adicionar evidências<input disabled={form.processing} className="sr-only" type="file" multiple accept="image/png,image/jpeg,image/webp,.pdf,.doc,.docx,.xls,.xlsx,.zip,.txt" onChange={add} /></label></div>
        <p className="text-xs text-[var(--ink-500)]">Imagens e documentos. Até 10 anexos por frente e versão, 15 MB por arquivo e 50 MB no total.</p>
        {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
        {order.map((key, position) => {
            const [kind, raw] = key.split(':');
            const index = Number(raw);
            const file = kind === 'existing' ? existing[index] : files[index];
            if (!file) return null;
            const url = kind === 'existing' ? file.url : previews[index];
            const image = (kind === 'existing' ? file.mime_type : file.type)?.startsWith('image/');
            const value = kind === 'existing' ? data.evidencias_comentarios?.[index] ?? file.comment ?? '' : data.evidencias_metadados?.[index]?.comment || '';
            if (!image) return <div key={key} className="rounded-lg border border-[var(--border)] bg-white p-3">
                <div className="flex flex-wrap items-center gap-3"><InspectionDocumentFile file={file} /><div className="flex items-center gap-1">
                    {kind === 'existing' && <a href={file.url} target="_blank" rel="noreferrer" className="sig-btn sig-btn-sm">Abrir</a>}
                    <button type="button" disabled={form.processing || position === 0} onClick={() => move(position, -1)} className="sig-btn sig-btn-ghost !min-h-8 !px-2" aria-label={`Mover evidência ${position + 1} para cima`}><ArrowUp size={14} /></button>
                    <button type="button" disabled={form.processing || position === order.length - 1} onClick={() => move(position, 1)} className="sig-btn sig-btn-ghost !min-h-8 !px-2" aria-label={`Mover evidência ${position + 1} para baixo`}><ArrowDown size={14} /></button>
                    <button type="button" disabled={form.processing} onClick={() => remove(key)} className="sig-btn sig-btn-ghost !min-h-8 !px-2 text-red-600" aria-label={`Excluir ${file.name}`}><Trash2 size={14} /></button>
                </div></div>
                <input aria-label={`Comentário de ${file.name}`} className="sig-input mt-3" placeholder="Comentário do documento (opcional)" value={value} onChange={event => comment(key, event.target.value)} />
            </div>;
            return <div key={key} className="grid gap-3 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-2 sm:grid-cols-[88px_minmax(0,1fr)]">
                {image && url ? <a href={url} target="_blank" rel="noreferrer"><img src={url} alt={file.name} className="h-20 w-20 rounded-md object-cover" /></a> : <div className="flex h-20 w-20 items-center justify-center rounded-md bg-slate-100 text-slate-500"><FileText size={28} /></div>}
                <div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><span className="sig-pill">Posição {position + 1}</span><button type="button" onClick={() => move(position, -1)} disabled={form.processing || position === 0} className="sig-btn sig-btn-ghost !min-h-8 !px-2" aria-label={`Mover evidência ${position + 1} para cima`}><ArrowUp size={14} /></button><button type="button" onClick={() => move(position, 1)} disabled={form.processing || position === order.length - 1} className="sig-btn sig-btn-ghost !min-h-8 !px-2" aria-label={`Mover evidência ${position + 1} para baixo`}><ArrowDown size={14} /></button><button type="button" disabled={form.processing} onClick={() => remove(key)} className="sig-btn sig-btn-ghost !min-h-8 !px-2 text-red-600" aria-label={`Excluir ${file.name}`}><Trash2 size={14} /></button><span className="min-w-0 break-all text-xs text-[var(--ink-500)]">{file.name}</span></div><textarea aria-label={`Comentário de ${file.name}`} className="sig-input mt-2" rows={2} placeholder={image ? 'Comentário da imagem' : 'Comentário do documento'} value={value} onChange={event => comment(key, event.target.value)} /></div>
            </div>;
        })}
        {!order.length && <p className="rounded-lg border border-dashed border-[var(--border)] p-6 text-center text-sm text-[var(--ink-500)]">Nenhuma evidência adicionada.</p>}
    </section>;
}
