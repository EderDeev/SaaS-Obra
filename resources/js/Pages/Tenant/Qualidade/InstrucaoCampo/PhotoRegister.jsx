import { ArrowDown, ArrowUp, ImagePlus, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const MAX_PHOTOS = 12;
const MAX_BYTES = 5 * 1024 * 1024;
const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

export default function PhotoRegister({ photos, onChange, errors = {}, disabled = false }) {
    const currentPhotos = useRef(photos);
    const [warning, setWarning] = useState('');
    currentPhotos.current = photos;

    useEffect(() => () => {
        currentPhotos.current.forEach((photo) => {
            if (photo.file) URL.revokeObjectURL(photo.previewUrl);
        });
    }, []);

    const addPhotos = (event) => {
        const files = Array.from(event.target.files || []);
        event.target.value = '';
        const available = MAX_PHOTOS - photos.length;
        const valid = files.filter((file) => TYPES.includes(file.type) && file.size <= MAX_BYTES);
        const messages = [];
        if (valid.length !== files.length) messages.push('Envie imagens JPG, PNG ou WebP de até 5 MB cada.');
        if (valid.length > available) messages.push('O registro permite até 12 fotos.');
        setWarning(messages.join(' '));
        onChange([...photos, ...valid.slice(0, available).map((file) => ({
            id: globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`,
            file,
            previewUrl: URL.createObjectURL(file),
            originalName: file.name,
            comment: '',
        }))]);
    };

    const movePhoto = (id, direction) => {
        const index = photos.findIndex((photo) => photo.id === id);
        const nextIndex = index + direction;
        if (index < 0 || nextIndex < 0 || nextIndex >= photos.length) return;
        const next = [...photos];
        [next[index], next[nextIndex]] = [next[nextIndex], next[index]];
        onChange(next);
    };

    const removePhoto = (id) => {
        const photo = photos.find((item) => item.id === id);
        if (photo?.file) URL.revokeObjectURL(photo.previewUrl);
        setWarning('');
        onChange(photos.filter((item) => item.id !== id));
    };

    return <section className="min-w-0 rounded-lg border border-[var(--border)] bg-white p-3" aria-label="Registro fotográfico">
        <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 className="eyebrow">Registro fotográfico</h2>
                <p className="mt-1 text-[12px] text-[var(--ink-500)]">Adicione até 12 imagens, organize a posição e comente cada foto.</p>
            </div>
            <label className={`sig-btn sig-btn-secondary sig-btn-sm ${disabled || photos.length >= MAX_PHOTOS ? 'cursor-not-allowed opacity-50' : ''}`}>
                <ImagePlus size={14} />Adicionar fotos
                <input className="sr-only" type="file" accept="image/png,image/jpeg,image/webp" multiple onChange={addPhotos} disabled={disabled || photos.length >= MAX_PHOTOS} />
            </label>
        </div>
        {(warning || errors.photos) && <p role="alert" className="mt-2 text-xs text-[var(--red)]">{warning || errors.photos}</p>}
        {photos.length > 0 ? <div className="mt-3 grid gap-3">
            {photos.map((photo, index) => <div key={photo.id} className="grid min-w-0 gap-3 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-2 sm:grid-cols-[88px_minmax(0,1fr)]">
                <img src={photo.previewUrl} alt={photo.originalName} className="h-20 w-20 rounded-md object-cover" />
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="sig-pill">Posição {index + 1}</span>
                        <button className="sig-btn sig-btn-ghost !min-h-8 !px-2" type="button" onClick={() => movePhoto(photo.id, -1)} disabled={disabled || index === 0} title="Mover para cima" aria-label={`Mover foto ${index + 1} para cima`}><ArrowUp size={14} /></button>
                        <button className="sig-btn sig-btn-ghost !min-h-8 !px-2" type="button" onClick={() => movePhoto(photo.id, 1)} disabled={disabled || index === photos.length - 1} title="Mover para baixo" aria-label={`Mover foto ${index + 1} para baixo`}><ArrowDown size={14} /></button>
                        <button className="sig-btn sig-btn-ghost !min-h-8 !px-2 text-[var(--red)]" type="button" onClick={() => removePhoto(photo.id)} disabled={disabled} title="Remover foto" aria-label={`Remover foto ${index + 1}`}><Trash2 size={14} /></button>
                    </div>
                    <textarea className="mt-2 w-full rounded-md border border-[var(--border)] bg-white px-3 py-2 text-[12.5px] outline-none focus:border-[var(--primary)]" value={photo.comment} onChange={(event) => onChange(photos.map((item) => item.id === photo.id ? { ...item, comment: event.target.value } : item))} disabled={disabled} maxLength={1000} placeholder="Comentário da imagem" aria-label={`Comentário da foto ${index + 1}`} rows={2} />
                </div>
            </div>)}
        </div> : <div className="mt-3 rounded-lg border border-dashed border-[var(--border-strong)] px-3 py-6 text-center text-[12.5px] text-[var(--ink-500)]">Nenhuma imagem adicionada.</div>}
    </section>;
}
