import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import 'mapbox-gl/dist/mapbox-gl.css';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    ClipboardX,
    ImagePlus,
    LocateFixed,
    MapPin,
    Plus,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const today = () => new Date().toISOString().slice(0, 10);
const dateInput = (date) => (date ? String(date).slice(0, 10) : '');
const mapboxToken = import.meta.env.VITE_MAPBOX_ACCESS_TOKEN;

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

export default function RelatorioNaoConformidadeCreate({
    tenant,
    obras,
    empresas,
    naturezas,
    gravidades,
    mode = 'create',
    rnc = null,
}) {
    const page = usePage();
    const isEditing = mode === 'edit' && rnc;
    const defaultObraId = rnc?.obra_id ?? obras[0]?.id ?? '';
    const [selectedPhotos, setSelectedPhotos] = useState(() => (rnc?.photos || []).map((photo) => ({
        id: `existing-${photo.id}`,
        existingId: photo.id,
        file: null,
        previewUrl: photo.url,
        comment: photo.comment || '',
        originalName: photo.original_name || `Foto ${photo.position}`,
        isExisting: true,
    })));
    const selectedPhotosRef = useRef([]);
    const form = useForm({
        obra_id: defaultObraId,
        contratante_empresa_id: rnc?.contratante_empresa_id ?? '',
        contratada_empresa_id: rnc?.contratada_empresa_id ?? '',
        opened_at: dateInput(rnc?.opened_at) || today(),
        latitude: rnc?.latitude ?? '',
        longitude: rnc?.longitude ?? '',
        natureza: rnc?.natureza ?? naturezas[0] ?? '',
        gravidade: rnc?.gravidade ?? gravidades[0] ?? '',
        descricao_problema: rnc?.descricao_problema ?? '',
        observacao: rnc?.observacao ?? '',
        acoes_corretivas_recomendadas: rnc?.acoes_corretivas_recomendadas ?? '',
        prazo_resposta_acao_corretiva: dateInput(rnc?.prazo_resposta_acao_corretiva),
    });

    const selectedObra = useMemo(
        () => obras.find((obra) => String(obra.id) === String(form.data.obra_id)),
        [obras, form.data.obra_id],
    );
    const errorMessages = Object.values(form.errors || {}).filter(Boolean);
    const empresasForSelectedContract = useMemo(
        () => empresas.filter((empresa) => String(empresa.contract_id) === String(selectedObra?.contract_id)),
        [empresas, selectedObra?.contract_id],
    );
    const canCreate = obras.length > 0 && empresasForSelectedContract.length >= 2;
    const selectedContratanteEmpresa = useMemo(
        () => empresasForSelectedContract.find((empresa) => String(empresa.id) === String(form.data.contratante_empresa_id)),
        [empresasForSelectedContract, form.data.contratante_empresa_id],
    );
    const selectedContratadaEmpresa = useMemo(
        () => empresasForSelectedContract.find((empresa) => String(empresa.id) === String(form.data.contratada_empresa_id)),
        [empresasForSelectedContract, form.data.contratada_empresa_id],
    );

    useEffect(() => {
        selectedPhotosRef.current = selectedPhotos;
    }, [selectedPhotos]);

    useEffect(() => () => {
        selectedPhotosRef.current.forEach((photo) => {
            if (!photo.isExisting) {
                URL.revokeObjectURL(photo.previewUrl);
            }
        });
    }, []);

    const setObra = (obraId) => {
        const obra = obras.find((item) => String(item.id) === String(obraId));
        const validCompanyIds = empresas
            .filter((empresa) => String(empresa.contract_id) === String(obra?.contract_id))
            .map((empresa) => empresa.id);

        form.setData({
            ...form.data,
            obra_id: obraId,
            contratante_empresa_id: validCompanyIds.includes(Number(form.data.contratante_empresa_id)) ? form.data.contratante_empresa_id : '',
            contratada_empresa_id: validCompanyIds.includes(Number(form.data.contratada_empresa_id)) ? form.data.contratada_empresa_id : '',
        });
    };

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
                originalName: file.name,
                isExisting: false,
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

            if (photo && !photo.isExisting) {
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

        const existingPhotos = selectedPhotos.filter((photo) => photo.isExisting);
        const newPhotos = selectedPhotos.filter((photo) => !photo.isExisting);

        form.transform((data) => ({
            ...data,
            ...(isEditing ? {
                _method: 'patch',
                sync_existing_photos: true,
                existing_photo_ids: existingPhotos.map((photo) => photo.existingId),
                existing_photo_comments: existingPhotos.map((photo) => photo.comment),
                existing_photo_positions: existingPhotos.map((photo) => selectedPhotos.findIndex((item) => item.id === photo.id) + 1),
            } : {}),
            photos: newPhotos.map((photo) => photo.file),
            photo_comments: newPhotos.map((photo) => photo.comment),
            photo_positions: newPhotos.map((photo) => selectedPhotos.findIndex((item) => item.id === photo.id) + 1),
        }));

        form.post(
            isEditing
                ? route('tenant.qualidade.rnc.update', [page.props.currentTenant.slug, rnc.id])
                : route('tenant.qualidade.rnc.store', page.props.currentTenant.slug),
            {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                selectedPhotos.forEach((photo) => {
                    if (!photo.isExisting) {
                        URL.revokeObjectURL(photo.previewUrl);
                    }
                });

                if (!isEditing) {
                    setSelectedPhotos([]);
                }
            },
            },
        );
    };

    return (
        <AuthenticatedLayout>
            <Head title={isEditing ? `Editar RNC ${rnc.formatted_number}` : 'Nova RNC'} />

            <section className="sig-content">
                <form className="sig-card p-5" onSubmit={submit}>
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <ClipboardX size={14} />
                                <span className="eyebrow">Qualidade</span>
                            </div>
                            <h1 className="mt-2 text-xl font-semibold">
                                {isEditing ? `Editar RNC ${rnc.formatted_number}` : 'Nova RNC'}
                            </h1>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                {isEditing
                                    ? 'Atualize os dados, coordenadas e registro fotografico da RNC.'
                                    : 'Registre nao conformidades vinculadas a obra e as empresas do contrato.'}
                            </p>
                        </div>
                        <Link href={route('tenant.qualidade.rnc.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                            <ArrowLeft size={15} />
                            Voltar
                        </Link>
                    </div>

                    {page.props.flash.success && (
                        <div className="mt-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}

                    {errorMessages.length > 0 && (
                        <div className="mt-4 rounded-lg bg-[var(--red-50)] px-3 py-2 text-sm text-[var(--red)]">
                            Revise os campos destacados antes de salvar a RNC.
                        </div>
                    )}

                    <div className="mt-5 grid gap-3">
                        <Field label="Obra" error={form.errors.obra_id}>
                            <select value={form.data.obra_id} onChange={(event) => setObra(event.target.value)} required>
                                <option value="">Selecione a obra</option>
                                {obras.map((obra) => (
                                    <option key={obra.id} value={obra.id}>
                                        {obra.codigo} - {obra.nome}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        {selectedObra?.contract && (
                            <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-3 py-2">
                                <div className="mono text-[12px] font-semibold text-[var(--ink-700)]">{selectedObra.contract.code}</div>
                                <div className="text-[12px] text-[var(--ink-500)]">{selectedObra.contract.name}</div>
                            </div>
                        )}

                        <div className="grid gap-3 md:grid-cols-2">
                            <Field label="Contratante" error={form.errors.contratante_empresa_id}>
                                <select
                                    value={form.data.contratante_empresa_id}
                                    onChange={(event) => form.setData('contratante_empresa_id', event.target.value)}
                                    required
                                >
                                    <option value="">Selecione</option>
                                    {empresasForSelectedContract.map((empresa) => (
                                        <option key={empresa.id} value={empresa.id}>
                                            {empresa.sigla} - {empresa.nome}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field label="Contratada" error={form.errors.contratada_empresa_id}>
                                <select
                                    value={form.data.contratada_empresa_id}
                                    onChange={(event) => form.setData('contratada_empresa_id', event.target.value)}
                                    required
                                >
                                    <option value="">Selecione</option>
                                    {empresasForSelectedContract.map((empresa) => (
                                        <option key={empresa.id} value={empresa.id}>
                                            {empresa.sigla} - {empresa.nome}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </div>

                        {(selectedContratanteEmpresa || selectedContratadaEmpresa) && (
                            <div className="grid gap-3 md:grid-cols-2">
                                <CompanyLogoPreview label="Logo da Contratante" empresa={selectedContratanteEmpresa} />
                                <CompanyLogoPreview label="Logo da Contratada" empresa={selectedContratadaEmpresa} />
                            </div>
                        )}

                        <div className="grid gap-3 md:grid-cols-2">
                            <Field label="Data abertura" error={form.errors.opened_at}>
                                <input
                                    type="date"
                                    value={form.data.opened_at}
                                    onChange={(event) => form.setData('opened_at', event.target.value)}
                                    required
                                />
                            </Field>

                            <Field label="Prazo para resposta de acao corretiva" error={form.errors.prazo_resposta_acao_corretiva}>
                                <input
                                    type="date"
                                    value={form.data.prazo_resposta_acao_corretiva}
                                    onChange={(event) => form.setData('prazo_resposta_acao_corretiva', event.target.value)}
                                    required
                                />
                            </Field>
                        </div>

                        <MapPicker form={form} />

                        <div className="grid gap-3 md:grid-cols-2">
                            <Field label="Natureza" error={form.errors.natureza}>
                                <select value={form.data.natureza} onChange={(event) => form.setData('natureza', event.target.value)} required>
                                    {naturezas.map((natureza) => (
                                        <option key={natureza} value={natureza}>{natureza}</option>
                                    ))}
                                </select>
                            </Field>

                            <Field label="Gravidade" error={form.errors.gravidade}>
                                <select value={form.data.gravidade} onChange={(event) => form.setData('gravidade', event.target.value)} required>
                                    {gravidades.map((gravidade) => (
                                        <option key={gravidade} value={gravidade}>{gravidade}</option>
                                    ))}
                                </select>
                            </Field>
                        </div>

                        <Field label="Descricao do problema" error={form.errors.descricao_problema}>
                            <textarea
                                value={form.data.descricao_problema}
                                onChange={(event) => form.setData('descricao_problema', event.target.value)}
                                placeholder="Descreva a nao conformidade encontrada"
                                rows={4}
                                required
                            />
                        </Field>

                        <Field label="Observacao" error={form.errors.observacao}>
                            <textarea
                                value={form.data.observacao}
                                onChange={(event) => form.setData('observacao', event.target.value)}
                                placeholder="Informacoes complementares"
                                rows={3}
                            />
                        </Field>

                        <Field label="Acoes corretivas recomendadas" error={form.errors.acoes_corretivas_recomendadas}>
                            <textarea
                                value={form.data.acoes_corretivas_recomendadas}
                                onChange={(event) => form.setData('acoes_corretivas_recomendadas', event.target.value)}
                                placeholder="Oriente as acoes corretivas esperadas"
                                rows={4}
                                required
                            />
                        </Field>

                        <section className="rounded-lg border border-[var(--border)] bg-white p-3">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <span className="eyebrow">Registro fotografico</span>
                                    <p className="mt-1 text-[12px] text-[var(--ink-500)]">Adicione ate 12 imagens, organize a posicao e comente cada foto.</p>
                                </div>
                                <label className="sig-btn sig-btn-secondary sig-btn-sm">
                                    <ImagePlus size={14} />
                                    Adicionar fotos
                                    <input className="sr-only" type="file" accept="image/png,image/jpeg,image/webp" multiple onChange={addPhotos} />
                                </label>
                            </div>
                            {form.errors.photos && <span className="mt-2 block text-xs text-[var(--red)]">{form.errors.photos}</span>}

                            {selectedPhotos.length > 0 ? (
                                <div className="mt-3 grid gap-3">
                                    {selectedPhotos.map((photo, index) => (
                                        <div key={photo.id} className="grid gap-3 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-2 sm:grid-cols-[88px_minmax(0,1fr)]">
                                            <img src={photo.previewUrl} alt={photo.file?.name || photo.originalName} className="h-20 w-20 rounded-md object-cover" />
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="sig-pill">Posicao {index + 1}</span>
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
                    </div>

                    <button type="submit" className="sig-btn sig-btn-primary mt-5" disabled={form.processing || !canCreate}>
                        <Plus size={15} />
                        {form.processing ? 'Salvando...' : isEditing ? 'Salvar RNC' : 'Criar RNC'}
                    </button>

                    {!canCreate && (
                        <p className="mt-2 text-xs text-[var(--ink-500)]">
                            Cadastre uma obra e pelo menos duas empresas no mesmo contrato para criar RNC.
                        </p>
                    )}
                </form>
            </section>
        </AuthenticatedLayout>
    );
}

function MapPicker({ form }) {
    const containerRef = useRef(null);
    const mapRef = useRef(null);
    const markerRef = useRef(null);
    const [locationStatus, setLocationStatus] = useState('');
    const [mapError, setMapError] = useState('');

    const hasMapboxToken = Boolean(mapboxToken);
    const currentLatitude = parseCoordinate(form.data.latitude);
    const currentLongitude = parseCoordinate(form.data.longitude);
    const hasValidCoordinates = isValidLatitude(currentLatitude) && isValidLongitude(currentLongitude);
    const defaultCenter = [-46.633308, -23.55052];

    const setCoordinates = (latitude, longitude) => {
        form.setData('latitude', latitude.toFixed(7));
        form.setData('longitude', longitude.toFixed(7));
    };

    useEffect(() => {
        if (!hasMapboxToken || !containerRef.current || mapRef.current) {
            return;
        }

        let cancelled = false;

        const loadMap = async () => {
            const { default: mapboxgl } = await import('mapbox-gl');

            if (cancelled || !containerRef.current) {
                return;
            }

            mapboxgl.accessToken = mapboxToken;

            const initialCenter = hasValidCoordinates ? [currentLongitude, currentLatitude] : defaultCenter;
            const map = new mapboxgl.Map({
                container: containerRef.current,
                style: 'mapbox://styles/mapbox/streets-v12',
                center: initialCenter,
                zoom: hasValidCoordinates ? 15 : 12,
                projection: 'mercator',
                attributionControl: false,
            });

            const marker = new mapboxgl.Marker({ color: '#d92d20', draggable: true })
                .setLngLat(initialCenter)
                .addTo(map);

            map.addControl(new mapboxgl.NavigationControl({ showCompass: false }), 'top-right');
            map.addControl(new mapboxgl.AttributionControl({ compact: true }), 'bottom-right');

            map.on('load', () => {
                map.resize();
                setMapError('');
            });

            map.on('error', (event) => {
                const message = event?.error?.message || 'Nao foi possivel carregar o mapa.';

                setMapError(message);
            });

            map.on('click', (event) => {
                marker.setLngLat(event.lngLat);
                setCoordinates(event.lngLat.lat, event.lngLat.lng);
            });

            marker.on('dragend', () => {
                const point = marker.getLngLat();
                setCoordinates(point.lat, point.lng);
            });

            mapRef.current = map;
            markerRef.current = marker;

            requestAnimationFrame(() => map.resize());
            window.setTimeout(() => map.resize(), 250);
        };

        loadMap();

        return () => {
            cancelled = true;
            markerRef.current?.remove();
            mapRef.current?.remove();
            markerRef.current = null;
            mapRef.current = null;
        };
    }, []);

    useEffect(() => {
        if (!mapRef.current || !markerRef.current || !hasValidCoordinates) {
            return;
        }

        const nextCenter = [currentLongitude, currentLatitude];

        markerRef.current.setLngLat(nextCenter);
        mapRef.current.easeTo({ center: nextCenter, zoom: Math.max(mapRef.current.getZoom(), 14), duration: 500 });
    }, [form.data.latitude, form.data.longitude]);

    const locateUser = () => {
        if (!navigator.geolocation) {
            setLocationStatus('Geolocalizacao nao disponivel neste navegador.');

            return;
        }

        setLocationStatus('Buscando localizacao...');
        navigator.geolocation.getCurrentPosition(
            (position) => {
                setCoordinates(position.coords.latitude, position.coords.longitude);
                mapRef.current?.easeTo({
                    center: [position.coords.longitude, position.coords.latitude],
                    zoom: 16,
                    duration: 700,
                });
                setLocationStatus('Localizacao capturada.');
            },
            () => setLocationStatus('Nao foi possivel capturar a localizacao.'),
            { enableHighAccuracy: true, timeout: 10000 },
        );
    };

    return (
        <section className="rounded-lg border border-[var(--border)] bg-white p-3">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <span className="eyebrow">Latitude e longitude</span>
                    <p className="mt-1 text-[12px] text-[var(--ink-500)]">Clique no mapa, arraste o marcador ou use a localizacao do navegador.</p>
                </div>
                <button className="sig-btn sig-btn-secondary sig-btn-sm" type="button" onClick={locateUser}>
                    <LocateFixed size={14} />
                    Usar localizacao
                </button>
            </div>

            <div className="relative h-64 overflow-hidden rounded-lg border border-[var(--border)] bg-[var(--surface-muted)]">
                {hasMapboxToken ? (
                    <div ref={containerRef} className="h-full w-full" />
                ) : (
                    <div className="flex h-full items-center justify-center p-6 text-center">
                        <div className="max-w-md">
                            <MapPin size={28} className="mx-auto text-[var(--ink-500)]" />
                            <h3 className="mt-3 text-sm font-semibold text-[var(--ink-900)]">Mapbox ainda nao configurado</h3>
                            <p className="mt-1 text-[12.5px] leading-5 text-[var(--ink-500)]">
                                Adicione a variavel <span className="mono font-semibold">VITE_MAPBOX_ACCESS_TOKEN</span> no arquivo .env e reinicie o Vite para carregar o mapa.
                            </p>
                        </div>
                    </div>
                )}
                {mapError && (
                    <div className="absolute inset-x-3 bottom-3 rounded-lg border border-[var(--red)] bg-white/95 px-3 py-2 text-[12px] leading-5 text-[var(--red)] shadow-sm">
                        Nao foi possivel carregar as ruas do Mapbox. Verifique se o token permite uso em localhost/127.0.0.1. Detalhe: {mapError}
                    </div>
                )}
                <div className="pointer-events-none absolute left-3 top-3 rounded-full bg-white/90 px-3 py-1 text-[11px] font-semibold text-[var(--ink-600)] shadow-sm">
                    Mapbox
                </div>
            </div>

            <div className="mt-3 grid gap-3 md:grid-cols-2">
                <Field label="Latitude" error={form.errors.latitude}>
                    <input
                        value={form.data.latitude}
                        onChange={(event) => form.setData('latitude', event.target.value)}
                        placeholder="-23.5505200"
                        inputMode="decimal"
                    />
                </Field>
                <Field label="Longitude" error={form.errors.longitude}>
                    <input
                        value={form.data.longitude}
                        onChange={(event) => form.setData('longitude', event.target.value)}
                        placeholder="-46.6333080"
                        inputMode="decimal"
                    />
                </Field>
            </div>
            {locationStatus && <p className="mt-2 text-xs text-[var(--ink-500)]">{locationStatus}</p>}
        </section>
    );
}

function parseCoordinate(value) {
    if (value === null || value === undefined) {
        return null;
    }

    const normalized = String(value).trim().replace(',', '.');

    if (normalized === '') {
        return null;
    }

    const coordinate = Number(normalized);

    return Number.isFinite(coordinate) ? coordinate : null;
}

function isValidLatitude(value) {
    return value !== null && value >= -90 && value <= 90;
}

function isValidLongitude(value) {
    return value !== null && value >= -180 && value <= 180;
}

function CompanyLogoPreview({ label, empresa }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
            <div className="eyebrow">{label}</div>
            <div className="mt-2 flex items-center gap-3">
                <div className="flex h-14 w-24 shrink-0 items-center justify-center overflow-hidden rounded-md border border-[var(--border)] bg-white px-2">
                    {empresa?.logo_url ? (
                        <img src={empresa.logo_url} alt={label} className="max-h-full max-w-full object-contain" />
                    ) : (
                        <span className="line-clamp-3 text-center text-[10px] font-bold leading-tight text-[var(--ink-600)]">
                            {empresa?.nome || 'Empresa sem logo'}
                        </span>
                    )}
                </div>
                <div className="min-w-0">
                    <div className="truncate text-[13px] font-semibold text-[var(--ink-900)]">{empresa?.sigla || empresa?.nome || '-'}</div>
                    <div className="truncate text-[12px] text-[var(--ink-500)]">{empresa?.nome || 'Selecione uma empresa'}</div>
                </div>
            </div>
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
