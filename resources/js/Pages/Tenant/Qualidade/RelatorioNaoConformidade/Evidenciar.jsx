import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowLeft, ArrowUp, CheckCircle2, ClipboardX, FileArchive, ImagePlus, Send, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const shortDate = (date) => {
    if (!date) return '-';

    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date));
};

const fileSize = (bytes) => {
    if (!bytes) return '0 KB';

    if (bytes >= 1024 * 1024) {
        return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
};

const photoId = () => (globalThis.crypto?.randomUUID ? globalThis.crypto.randomUUID() : `${Date.now()}-${Math.random()}`);
const pdfFriendlyImageTypes = ['image/png', 'image/webp'];
const jpegFileName = (fileName) => fileName.replace(/\.[^.]+$/, '') + '.jpg';

const convertImageToJpeg = (file) => new Promise((resolve) => {
    if (!pdfFriendlyImageTypes.includes(file.type)) {
        resolve(file);

        return;
    }

    const imageUrl = URL.createObjectURL(file);
    const image = new Image();

    image.onload = () => {
        const canvas = document.createElement('canvas');
        canvas.width = image.naturalWidth || image.width;
        canvas.height = image.naturalHeight || image.height;

        const context = canvas.getContext('2d');

        if (!context) {
            URL.revokeObjectURL(imageUrl);
            resolve(file);

            return;
        }

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);

        canvas.toBlob((blob) => {
            URL.revokeObjectURL(imageUrl);

            if (!blob) {
                resolve(file);

                return;
            }

            resolve(new File([blob], jpegFileName(file.name), {
                type: 'image/jpeg',
                lastModified: file.lastModified,
            }));
        }, 'image/jpeg', 0.9);
    };

    image.onerror = () => {
        URL.revokeObjectURL(imageUrl);
        resolve(file);
    };

    image.src = imageUrl;
});

export default function RncEvidenciar({ tenant, rnc, acaoCorretiva }) {
    const page = usePage();
    const photoInputRef = useRef(null);
    const zipInputRef = useRef(null);
    const [selectedPhotos, setSelectedPhotos] = useState([]);
    const selectedPhotosRef = useRef([]);
    const form = useForm({
        evidence_photos: [],
        evidence_photo_comments: [],
        evidence_photo_positions: [],
        attachment: null,
    });
    const photoError = form.errors.evidence_photos
        || form.errors['evidence_photos.0']
        || form.errors.evidence_photo_comments
        || form.errors['evidence_photo_comments.0'];

    useEffect(() => {
        selectedPhotosRef.current = selectedPhotos;
    }, [selectedPhotos]);

    useEffect(() => () => {
        selectedPhotosRef.current.forEach((photo) => URL.revokeObjectURL(photo.previewUrl));
    }, []);

    const addPhotos = async (event) => {
        const files = Array.from(event.target.files || []);
        const remainingSlots = Math.max(0, 12 - selectedPhotos.length);
        const acceptedFiles = files.slice(0, remainingSlots);
        const preparedFiles = await Promise.all(acceptedFiles.map((file) => convertImageToJpeg(file)));

        setSelectedPhotos((current) => [
            ...current,
            ...preparedFiles.map((file) => ({
                id: photoId(),
                file,
                previewUrl: URL.createObjectURL(file),
                comment: '',
            })),
        ]);

        event.target.value = '';
    };

    const updatePhotoComment = (id, comment) => {
        setSelectedPhotos((current) => current.map((photo) => (
            photo.id === id ? { ...photo, comment } : photo
        )));
    };

    const removePhoto = (id) => {
        setSelectedPhotos((current) => {
            const photo = current.find((item) => item.id === id);

            if (photo) {
                URL.revokeObjectURL(photo.previewUrl);
            }

            return current.filter((item) => item.id !== id);
        });
    };

    const movePhoto = (id, direction) => {
        setSelectedPhotos((current) => {
            const index = current.findIndex((photo) => photo.id === id);
            const nextIndex = index + direction;

            if (index < 0 || nextIndex < 0 || nextIndex >= current.length) {
                return current;
            }

            const next = [...current];
            [next[index], next[nextIndex]] = [next[nextIndex], next[index]];

            return next;
        });
    };

    const submit = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            evidence_photos: selectedPhotos.map((photo) => photo.file),
            evidence_photo_comments: selectedPhotos.map((photo) => photo.comment),
            evidence_photo_positions: selectedPhotos.map((_, index) => index + 1),
        }));

        form.post(route('tenant.qualidade.rnc.evidencias.store', [tenant.slug, rnc.id]), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                selectedPhotos.forEach((photo) => URL.revokeObjectURL(photo.previewUrl));
                setSelectedPhotos([]);
                form.reset();

                if (photoInputRef.current) {
                    photoInputRef.current.value = '';
                }

                if (zipInputRef.current) {
                    zipInputRef.current.value = '';
                }
            },
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Evidenciar correcao - RNC ${rnc.formatted_number}`} />

            <section className="sig-content">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <ImagePlus size={14} />
                            <span className="eyebrow">Evidenciar correcao</span>
                        </div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">
                            RNC {rnc.formatted_number}
                        </h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            {rnc.obra?.codigo} - {rnc.obra?.nome}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('tenant.qualidade.rnc.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                            <ArrowLeft size={15} />
                            Voltar
                        </Link>
                        <Link href={route('tenant.qualidade.rnc.show', [tenant.slug, rnc.id])} className="sig-btn sig-btn-secondary">
                            <ClipboardX size={15} />
                            Abrir RNC
                        </Link>
                    </div>
                </div>

                {page.props.flash.success && (
                    <div className="mb-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                        {page.props.flash.success}
                    </div>
                )}

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                    <article className="sig-card overflow-hidden">
                        <section className="border-b border-[var(--border)] p-5">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="sig-pill sig-pill-green">Proposta aprovada</span>
                                <span className="sig-pill sig-pill-blue">{rnc.natureza}</span>
                                <span className="sig-pill">{rnc.gravidade}</span>
                            </div>
                            <h2 className="mt-4 text-xl font-semibold text-[var(--ink-900)]">Resumo da correcao aprovada</h2>

                            <div className="mt-5 grid gap-3 md:grid-cols-2">
                                <Meta label="Contrato" value={`${rnc.contract?.code || ''} - ${rnc.contract?.name || ''}`} />
                                <Meta label="Obra" value={`${rnc.obra?.codigo || ''} - ${rnc.obra?.nome || ''}`} />
                                <Meta label="Contratante" value={rnc.contratante?.sigla || rnc.contratante?.nome} />
                                <Meta label="Contratada" value={rnc.contratada?.sigla || rnc.contratada?.nome} />
                                <Meta label="Prazo proposto" value={acaoCorretiva.prazo_execucao_proposto_formatted || shortDate(acaoCorretiva.prazo_execucao_proposto)} />
                                <Meta label="Analisada em" value={acaoCorretiva.reviewed_at_formatted || '-'} />
                            </div>
                        </section>

                        <section className="border-b border-[var(--border)] p-5">
                            <div className="eyebrow">Proposta aprovada</div>
                            <TextBlock title="Descricao da proposta" value={acaoCorretiva.descricao_proposta} />
                            <TextBlock title="Observacoes da analise" value={acaoCorretiva.review_observation || 'Sem observacoes.'} />
                        </section>

                        <section className="p-5">
                            <div className="eyebrow">Nao conformidade original</div>
                            <TextBlock title="Descricao do problema" value={rnc.descricao_problema} />
                            <TextBlock title="Acoes corretivas recomendadas" value={rnc.acoes_corretivas_recomendadas} />
                        </section>
                    </article>

                    <aside className="grid gap-6 content-start">
                        <form className="sig-card p-5" onSubmit={submit}>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <CheckCircle2 size={14} />
                                <span className="eyebrow">Finalizar RNC</span>
                            </div>
                            <h2 className="mt-2 text-xl font-semibold text-[var(--ink-900)]">Evidencias da correcao</h2>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                Anexe imagens da correcao executada, organize a ordem, comente cada imagem e envie um .zip de ate 30 MB.
                            </p>

                            <div className="mt-5 grid gap-4">
                                <section className="rounded-lg border border-[var(--border)] bg-white p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <span className="eyebrow">Registro fotografico da correcao</span>
                                            <p className="mt-1 text-[12px] text-[var(--ink-500)]">
                                                Adicione ate 12 imagens, organize a posicao e comente cada foto.
                                            </p>
                                        </div>
                                        <label className="sig-btn sig-btn-secondary sig-btn-sm">
                                            <ImagePlus size={14} />
                                            Adicionar fotos
                                            <input
                                                ref={photoInputRef}
                                                className="sr-only"
                                                type="file"
                                                accept="image/png,image/jpeg,image/webp"
                                                multiple
                                                onChange={addPhotos}
                                            />
                                        </label>
                                    </div>

                                    {photoError && (
                                        <span className="mt-2 block text-xs text-[var(--red)]">
                                            {photoError}
                                        </span>
                                    )}

                                    {selectedPhotos.length > 0 ? (
                                        <div className="mt-3 grid gap-3">
                                            {selectedPhotos.map((photo, index) => (
                                                <div key={photo.id} className="grid gap-3 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-2 sm:grid-cols-[88px_minmax(0,1fr)]">
                                                    <img src={photo.previewUrl} alt={photo.file.name} className="h-20 w-20 rounded-md object-cover" />
                                                    <div className="min-w-0">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="sig-pill">Posicao {index + 1}</span>
                                                            <span className="text-[11px] text-[var(--ink-500)]">{fileSize(photo.file.size)}</span>
                                                            <button className="sig-btn sig-btn-ghost !min-h-8 !px-2" type="button" onClick={() => movePhoto(photo.id, -1)} disabled={index === 0} title="Mover para cima">
                                                                <ArrowUp size={14} />
                                                            </button>
                                                            <button className="sig-btn sig-btn-ghost !min-h-8 !px-2" type="button" onClick={() => movePhoto(photo.id, 1)} disabled={index === selectedPhotos.length - 1} title="Mover para baixo">
                                                                <ArrowDown size={14} />
                                                            </button>
                                                            <button className="sig-btn sig-btn-ghost !min-h-8 !px-2 text-[var(--red)]" type="button" onClick={() => removePhoto(photo.id)} title="Remover foto">
                                                                <Trash2 size={14} />
                                                            </button>
                                                        </div>
                                                        <textarea
                                                            className="mt-2 w-full rounded-md border border-[var(--border)] bg-white px-3 py-2 text-[12.5px] outline-none focus:border-[var(--primary)]"
                                                            value={photo.comment}
                                                            onChange={(event) => updatePhotoComment(photo.id, event.target.value)}
                                                            placeholder="Comentario da imagem"
                                                            rows={2}
                                                        />
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="mt-3 rounded-lg border border-dashed border-[var(--border-strong)] px-3 py-6 text-center text-[12.5px] text-[var(--ink-500)]">
                                            Nenhuma imagem adicionada.
                                        </div>
                                    )}
                                </section>

                                <Field label="Documento zipado de evidencias" error={form.errors.attachment}>
                                    <input
                                        ref={zipInputRef}
                                        type="file"
                                        accept=".zip,application/zip"
                                        onChange={(event) => form.setData('attachment', event.target.files?.[0] || null)}
                                        required
                                    />
                                </Field>

                                {form.data.attachment && (
                                    <div className="flex items-center gap-3 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-3 py-2 text-sm">
                                        <FileArchive size={16} />
                                        <span className="min-w-0 flex-1 truncate">{form.data.attachment.name}</span>
                                        <span className="text-xs text-[var(--ink-500)]">{fileSize(form.data.attachment.size)}</span>
                                    </div>
                                )}
                            </div>

                            <button
                                type="submit"
                                className="sig-btn sig-btn-primary mt-5 w-full"
                                disabled={form.processing || selectedPhotos.length === 0 || !form.data.attachment}
                            >
                                <Send size={15} />
                                {form.processing ? 'Enviando...' : 'Enviar evidencias e finalizar'}
                            </button>
                        </form>
                    </aside>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Meta({ label, value }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-3 py-2">
            <div className="eyebrow">{label}</div>
            <div className="mt-1 text-[13px] font-semibold text-[var(--ink-800)]">{value || '-'}</div>
        </div>
    );
}

function TextBlock({ title, value }) {
    return (
        <div className="mt-4 rounded-lg border border-[var(--border)] bg-white p-4">
            <h3 className="text-[13px] font-semibold text-[var(--ink-900)]">{title}</h3>
            <p className="mt-2 whitespace-pre-line text-sm leading-6 text-[var(--ink-500)]">{value}</p>
        </div>
    );
}

function Field({ label, error, children }) {
    return (
        <label>
            <span className="eyebrow mb-1 block">{label}</span>
            <span className="sig-input">{children}</span>
            {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
        </label>
    );
}
