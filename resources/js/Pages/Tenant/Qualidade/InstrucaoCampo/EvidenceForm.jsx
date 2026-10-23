import OsDnitConfirmDialog from '@/Components/OsDnitConfirmDialog';
import { Link, useForm } from '@inertiajs/react';
import { FileText, Send, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Card, Errors, Photos } from './Ui';
import PhotoRegister from './PhotoRegister';

const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

export default function EvidenceForm({ tenant, ic, resolutionOptions, modal = false, onCancel, onCompleted, onBusy, onDirty }) {
    const form = useForm({ comment: '', resolution_result: '', attachments: [], from_modal: modal });
    const [confirm, setConfirm] = useState(false);
    const [selectedPhotos, setSelectedPhotos] = useState([]);
    const errorSummary = useRef(null);
    useEffect(() => { onBusy?.(form.processing); return () => onBusy?.(false); }, [form.processing, onBusy]);
    useEffect(() => { onDirty?.(form.isDirty || selectedPhotos.length > 0); return () => onDirty?.(false); }, [form.isDirty, selectedPhotos.length, onDirty]);
    useEffect(() => {
        if (Object.keys(form.errors).length) {
            errorSummary.current?.focus();
            errorSummary.current?.scrollIntoView({ block: 'nearest' });
        }
    }, [form.errors]);
    const validTotal = (files) => {
        if (files.length > 12 || files.reduce((sum, file) => sum + file.size, 0) > 50 * 1024 * 1024) {
            form.setError('attachments', 'Envie até 12 arquivos, com 10 MB por arquivo e 50 MB no total.');
            return false;
        }
        return true;
    };
    const updatePhotos = (photos) => {
        if (!validTotal([...photos.map((photo) => photo.file), ...form.data.attachments])) {
            photos.filter((photo) => !selectedPhotos.some((current) => current.id === photo.id))
                .forEach((photo) => URL.revokeObjectURL(photo.previewUrl));
            return;
        }
        form.clearErrors('attachments');
        setSelectedPhotos(photos);
    };
    const chooseFiles = (event) => {
        const added = Array.from(event.target.files || []);
        event.target.value = '';
        const files = [...selectedPhotos.map((photo) => photo.file), ...form.data.attachments, ...added];
        if (!validTotal(files)) return;
        if (added.some((file) => file.size > (IMAGE_TYPES.includes(file.type) ? 5 : 10) * 1024 * 1024)) {
            form.setError('attachments', 'Envie imagens de até 5 MB e outros arquivos de até 10 MB cada.');
            return;
        }
        setSelectedPhotos([...selectedPhotos, ...added.filter((file) => IMAGE_TYPES.includes(file.type)).map((file) => ({
            id: globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`,
            file, previewUrl: URL.createObjectURL(file), originalName: file.name, comment: '',
        }))]);
        form.clearErrors('attachments');
        form.setData('attachments', [...form.data.attachments, ...added.filter((file) => !IMAGE_TYPES.includes(file.type))]);
    };
    const submit = () => {
        if (form.processing) return;
        setConfirm(false);
        form.transform((data) => ({ ...data,
            photos: selectedPhotos.map((photo) => photo.file),
            photo_comments: selectedPhotos.map((photo) => photo.comment),
            photo_positions: selectedPhotos.map((_, index) => index + 1),
        }));
        form.post(route('tenant.qualidade.ic.finalize', [tenant.slug, ic.id]), {
            forceFormData: true, preserveScroll: true, onSuccess: () => onCompleted?.(),
        });
    };
    return <>
        <div className={modal ? 'os-dnit-analysis-form flex min-h-0 flex-1 flex-col' : 'grid gap-5'} aria-busy={form.processing}>
            <div className={modal ? 'os-dnit-analysis-body min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 sm:px-7' : 'grid gap-5'}>
                <Card title="Instrução recebida"><p className="whitespace-pre-wrap break-words text-sm leading-7">{ic.instrucao}</p></Card>
                <Card title="Registro fotográfico da abertura"><Photos tenant={tenant} ic={ic} photos={(ic.photos || []).filter((photo) => !photo.event_id)} /></Card>
                <div ref={errorSummary} tabIndex={-1} className="focus:outline-none"><Errors errors={form.errors} /></div>
                <Card title="Evidências e finalização"><div className="grid gap-5">
                    <label className="grid gap-2 text-sm font-medium">Descrição das evidências<textarea className="sig-input min-h-32 w-full resize-y" value={form.data.comment} onChange={(event) => form.setData('comment', event.target.value)} maxLength={10000} disabled={form.processing} placeholder="Descreva o que foi constatado em campo." /></label>
                    <label className="grid gap-2 text-sm font-medium">Evidências<input type="file" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.csv,.txt,.zip" onChange={chooseFiles} disabled={form.processing} className="block w-full min-w-0 text-sm" /></label>
                    <p className="text-xs leading-5 text-[var(--ink-500)]">Imagens JPG, PNG ou WebP, PDF, Word, planilhas, apresentações, texto ou ZIP. Até 12 arquivos no total: imagens de até 5 MB, outros arquivos de até 10 MB e 50 MB somados.</p>
                    <PhotoRegister photos={selectedPhotos} onChange={updatePhotos} errors={form.errors} disabled={form.processing} />
                    <ul className="grid gap-2">{form.data.attachments.map((file, index) => <li key={`${file.name}-${index}`} className="flex min-w-0 items-center gap-3 rounded-lg border border-[var(--border)] p-3"><FileText size={18} className="shrink-0 text-[var(--ink-500)]" /><div className="min-w-0 flex-1"><span className="block break-words text-sm">{file.name}</span><span className="text-xs text-[var(--ink-500)]">{(file.size / 1024 / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 2 })} MB</span></div><button type="button" disabled={form.processing} aria-label={`Remover ${file.name}`} className="sig-btn sig-btn-ghost shrink-0" onClick={() => form.setData('attachments', form.data.attachments.filter((_, i) => i !== index))}><Trash2 size={16} /></button></li>)}</ul>
                    {form.progress && <p role="status" className="text-sm text-[var(--ink-500)]">Enviando anexos: {form.progress.percentage}%</p>}
                    <fieldset className="grid gap-3"><legend className="mb-3 text-sm font-semibold">O problema foi resolvido?</legend><div className="flex flex-wrap gap-3">{Object.entries(resolutionOptions).map(([value, label]) => <label key={value} className="flex items-center gap-2 rounded-lg border border-[var(--border)] px-4 py-3 text-sm"><input type="radio" name="resolution_result" value={value} checked={form.data.resolution_result === value} onChange={() => form.setData('resolution_result', value)} disabled={form.processing} />{label}</label>)}</div></fieldset>
                </div></Card>
            </div>
            <footer className={modal ? 'os-dnit-analysis-footer flex shrink-0 justify-end border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-7' : 'sig-card flex justify-end p-4'}><div className="flex flex-wrap justify-end gap-3">
                {modal ? <button type="button" className="sig-btn sig-btn-secondary" disabled={form.processing} onClick={onCancel}>Cancelar</button> : <Link className="sig-btn sig-btn-secondary" href={route('tenant.qualidade.ic.show', [tenant.slug, ic.id])}>Cancelar</Link>}
                <button type="button" className="sig-btn sig-btn-primary" disabled={form.processing || !form.data.resolution_result || (!form.data.attachments.length && !selectedPhotos.length)} onClick={() => setConfirm(true)}><Send size={16} />{form.processing ? 'Finalizando...' : 'Finalizar IC'}</button>
            </div></footer>
        </div>
        <OsDnitConfirmDialog open={confirm} title="Finalizar IC" message="Finalizar esta IC com as evidências e a resposta informada?" confirmLabel="Finalizar IC" disabled={form.processing} onCancel={() => setConfirm(false)} onConfirm={submit} />
    </>;
}
