import { ImagePlus, RotateCcw, Trash2, Upload } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

export default function LogoUploadField({ label, file, current, removed, error, onChange, onRemove, onUndo }) {
    const id = useId();
    const input = useRef(null);
    const [localUrl, setLocalUrl] = useState(null);
    useEffect(() => {
        if (!file) { setLocalUrl(null); if (input.current) input.current.value = ''; return; }
        const url = URL.createObjectURL(file);
        setLocalUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [file]);
    const url = file ? localUrl : removed ? null : current?.url;
    return <div className="min-w-0 rounded-xl border border-[var(--border)] p-4">
        <h3 className="text-sm font-semibold">{label}</h3>
        <div className="mt-3 flex h-32 items-center justify-center overflow-hidden rounded-lg border border-dashed border-slate-200 bg-white p-3">
            {url ? <img src={url} alt={label} className="max-h-full max-w-full object-contain" /> : <div className="flex flex-col items-center gap-2 text-xs text-slate-400"><ImagePlus size={26} />{removed ? 'Logo removida' : 'Nenhuma imagem enviada'}</div>}
        </div>
        {(file || (!removed && current)) && <p className="mt-2 truncate text-xs text-slate-500" title={file?.name || current?.name}>{file?.name || current?.name}</p>}
        <div className="mt-3 flex flex-wrap items-center gap-2">
            <label htmlFor={id} className="sig-btn sig-btn-secondary !min-h-9 cursor-pointer !px-3 !text-xs"><Upload size={14} />{url ? 'Trocar imagem' : 'Enviar imagem'}</label>
            <input id={id} ref={input} aria-label={label} type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" onChange={(event) => { const selected = event.target.files?.[0]; if (selected) onChange(selected); }} />
            {url && <button type="button" className="sig-btn sig-btn-ghost !min-h-9 !px-3 !text-xs text-red-600" onClick={onRemove}><Trash2 size={14} />Remover</button>}
            {removed && current && <button type="button" className="sig-btn sig-btn-ghost !min-h-9 !px-3 !text-xs" onClick={onUndo}><RotateCcw size={14} />Desfazer remoção</button>}
        </div>
        <p className="mt-2 text-xs text-slate-500">PNG, JPG ou WebP. Até 4 MB e 4096 × 4096 pixels.</p>
        {error && <p role="alert" className="mt-2 text-xs text-red-600">{error}</p>}
    </div>;
}
