import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Camera, RotateCcw, Save, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const cropFrameSize = 288;
const outputAvatarSize = 512;

function initials(name = 'Usuário') {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

export default function ProfileEdit() {
    const { props } = usePage();
    const user = props.auth.user;
    const [avatarPreview, setAvatarPreview] = useState(null);
    const [avatarLoadFailed, setAvatarLoadFailed] = useState(false);
    const [cropSource, setCropSource] = useState(null);
    const [cropZoom, setCropZoom] = useState(1);
    const [cropOffset, setCropOffset] = useState({ x: 0, y: 0 });
    const [dragState, setDragState] = useState(null);
    const cropImageRef = useRef(null);
    const avatarPreviewRef = useRef(null);
    const form = useForm({
        _method: 'patch',
        avatar: null,
    });

    useEffect(() => {
        if (!cropSource) {
            return undefined;
        }

        return () => URL.revokeObjectURL(cropSource);
    }, [cropSource]);

    useEffect(() => {
        return () => {
            if (avatarPreviewRef.current) {
                URL.revokeObjectURL(avatarPreviewRef.current);
            }
        };
    }, []);

    useEffect(() => {
        setAvatarLoadFailed(false);
    }, [avatarPreview, user.avatar_url]);

    const clampOffset = (offset, zoom = cropZoom) => {
        const image = cropImageRef.current;

        if (!image?.naturalWidth || !image?.naturalHeight) {
            return offset;
        }

        const baseScale = Math.max(
            cropFrameSize / image.naturalWidth,
            cropFrameSize / image.naturalHeight,
        );
        const displayWidth = image.naturalWidth * baseScale * zoom;
        const displayHeight = image.naturalHeight * baseScale * zoom;
        const maxX = Math.max(0, (displayWidth - cropFrameSize) / 2);
        const maxY = Math.max(0, (displayHeight - cropFrameSize) / 2);

        return {
            x: Math.max(-maxX, Math.min(maxX, offset.x)),
            y: Math.max(-maxY, Math.min(maxY, offset.y)),
        };
    };

    const selectAvatar = (event) => {
        const file = event.target.files?.[0];

        event.target.value = '';

        if (!file) {
            return;
        }

        setCropSource(URL.createObjectURL(file));
        setCropZoom(1);
        setCropOffset({ x: 0, y: 0 });
    };

    const updateZoom = (value) => {
        const zoom = Number(value);

        setCropZoom(zoom);
        setCropOffset((offset) => clampOffset(offset, zoom));
    };

    const startDrag = (event) => {
        event.preventDefault();
        event.currentTarget.setPointerCapture(event.pointerId);
        setDragState({
            pointerX: event.clientX,
            pointerY: event.clientY,
            offsetX: cropOffset.x,
            offsetY: cropOffset.y,
        });
    };

    const moveDrag = (event) => {
        if (!dragState) {
            return;
        }

        const nextOffset = {
            x: dragState.offsetX + event.clientX - dragState.pointerX,
            y: dragState.offsetY + event.clientY - dragState.pointerY,
        };

        setCropOffset(clampOffset(nextOffset));
    };

    const endDrag = () => {
        setDragState(null);
    };

    const resetCrop = () => {
        setCropZoom(1);
        setCropOffset({ x: 0, y: 0 });
    };

    const closeCrop = () => {
        setCropSource(null);
        setDragState(null);
    };

    const applyCrop = async () => {
        const image = cropImageRef.current;

        if (!image?.naturalWidth || !image?.naturalHeight) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = outputAvatarSize;
        canvas.height = outputAvatarSize;

        const context = canvas.getContext('2d');
        const baseScale = Math.max(
            cropFrameSize / image.naturalWidth,
            cropFrameSize / image.naturalHeight,
        );
        const outputScale = outputAvatarSize / cropFrameSize;
        const drawWidth = image.naturalWidth * baseScale * cropZoom * outputScale;
        const drawHeight = image.naturalHeight * baseScale * cropZoom * outputScale;
        const drawX = (outputAvatarSize - drawWidth) / 2 + cropOffset.x * outputScale;
        const drawY = (outputAvatarSize - drawHeight) / 2 + cropOffset.y * outputScale;

        context.clearRect(0, 0, outputAvatarSize, outputAvatarSize);
        context.save();
        context.beginPath();
        context.arc(outputAvatarSize / 2, outputAvatarSize / 2, outputAvatarSize / 2, 0, Math.PI * 2);
        context.clip();
        context.drawImage(image, drawX, drawY, drawWidth, drawHeight);
        context.restore();

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png', 0.92));

        if (!blob) {
            return;
        }

        if (avatarPreviewRef.current) {
            URL.revokeObjectURL(avatarPreviewRef.current);
        }

        const previewUrl = URL.createObjectURL(blob);
        avatarPreviewRef.current = previewUrl;
        setAvatarPreview(previewUrl);
        setAvatarLoadFailed(false);

        form.setData('avatar', new File([blob], `avatar-${Date.now()}.png`, { type: 'image/png' }));
        closeCrop();
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('profile.update'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.setData('avatar', null),
        });
    };

    const avatarUrl = avatarPreview || user.avatar_url;

    return (
        <AuthenticatedLayout>
            <Head title="Perfil" />

            <section className="sig-content max-w-3xl">
                <form className="sig-card p-5" onSubmit={submit}>
                    <div className="eyebrow">Sistema</div>
                    <h1 className="mt-2 text-xl font-semibold">Perfil</h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">Atualize sua foto de perfil para facilitar a identificação no sistema.</p>

                    <div className="mt-5 flex flex-wrap items-center gap-4 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4">
                        {avatarUrl && !avatarLoadFailed ? (
                            <img
                                src={avatarUrl}
                                alt={user.name}
                                className="h-20 w-20 rounded-full object-cover ring-4 ring-white"
                                onError={() => setAvatarLoadFailed(true)}
                            />
                        ) : (
                            <span className="flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-[#c5d2ff] to-[#9ab1ff] text-xl font-bold text-[#1c2c5c] ring-4 ring-white">
                                {initials(user.name)}
                            </span>
                        )}
                        <div className="min-w-[220px] flex-1">
                            <div className="text-[14px] font-semibold text-[var(--ink-900)]">Foto de perfil</div>
                            <p className="mt-1 text-[12.5px] text-[var(--ink-500)]">Use uma imagem JPG, PNG ou WebP com até 2 MB.</p>
                            <label className="sig-btn sig-btn-secondary mt-3 inline-flex">
                                <Camera size={15} />
                                Escolher foto
                                <input
                                    className="sr-only"
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    onChange={selectAvatar}
                                />
                            </label>
                            {form.data.avatar && (
                                <span className="ml-3 text-[12.5px] text-[var(--ink-500)]">Foto editada e pronta para salvar.</span>
                            )}
                            {form.errors.avatar && <span className="mt-2 block text-xs text-[var(--red)]">{form.errors.avatar}</span>}
                        </div>
                    </div>

                    <div className="mt-5 grid gap-3 rounded-lg border border-[var(--border)] bg-white p-4 sm:grid-cols-2">
                        <div>
                            <span className="eyebrow mb-1 block">Nome</span>
                            <div className="text-[14px] font-semibold text-[var(--ink-900)]">{user.name}</div>
                        </div>
                        <div>
                            <span className="eyebrow mb-1 block">Email</span>
                            <div className="break-all text-[14px] text-[var(--ink-600)]">{user.email}</div>
                        </div>
                    </div>

                    <button className="sig-btn sig-btn-primary mt-5" disabled={form.processing}>
                        <Save size={15} />
                        Salvar perfil
                    </button>
                </form>
            </section>

            {cropSource && (
                <div className="fixed inset-0 z-[90] flex items-center justify-center bg-[rgba(11,16,32,0.48)] px-4 py-6">
                    <section className="w-full max-w-xl rounded-xl bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]">
                        <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                            <div>
                                <h2 className="text-[16px] font-semibold text-[var(--ink-900)]">Editar foto de perfil</h2>
                                <p className="mt-1 text-[12.5px] text-[var(--ink-500)]">Arraste a imagem e ajuste o zoom para enquadrar no círculo.</p>
                            </div>
                            <button className="sig-btn sig-btn-ghost !min-h-9 !px-2" type="button" onClick={closeCrop} title="Fechar">
                                <X size={18} />
                            </button>
                        </header>

                        <div className="grid gap-5 p-5">
                            <div className="flex justify-center">
                                <div
                                    className="relative h-72 w-72 touch-none overflow-hidden rounded-full border-4 border-white bg-[var(--ink-200)] shadow-[0_0_0_1px_var(--border),0_14px_40px_rgba(11,16,32,0.18)]"
                                    onPointerDown={startDrag}
                                    onPointerMove={moveDrag}
                                    onPointerUp={endDrag}
                                    onPointerCancel={endDrag}
                                    onPointerLeave={endDrag}
                                >
                                    <img
                                        ref={cropImageRef}
                                        src={cropSource}
                                        alt="Prévia para recorte"
                                        className="absolute left-1/2 top-1/2 h-full w-full select-none object-cover"
                                        draggable={false}
                                        onLoad={() => setCropOffset((offset) => clampOffset(offset))}
                                        style={{
                                            transform: `translate(-50%, -50%) translate(${cropOffset.x}px, ${cropOffset.y}px) scale(${cropZoom})`,
                                            transformOrigin: 'center',
                                        }}
                                    />
                                </div>
                            </div>

                            <label>
                                <span className="eyebrow mb-2 block">Zoom</span>
                                <input
                                    className="w-full accent-[var(--primary)]"
                                    type="range"
                                    min="1"
                                    max="3"
                                    step="0.01"
                                    value={cropZoom}
                                    onChange={(event) => updateZoom(event.target.value)}
                                />
                            </label>

                            <div className="flex flex-wrap justify-between gap-2">
                                <button className="sig-btn sig-btn-secondary" type="button" onClick={resetCrop}>
                                    <RotateCcw size={15} />
                                    Reposicionar
                                </button>
                                <div className="flex flex-wrap gap-2">
                                    <button className="sig-btn sig-btn-secondary" type="button" onClick={closeCrop}>Cancelar</button>
                                    <button className="sig-btn sig-btn-primary" type="button" onClick={applyCrop}>
                                        <Camera size={15} />
                                        Usar esta foto
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
