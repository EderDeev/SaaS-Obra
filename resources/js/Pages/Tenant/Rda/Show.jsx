import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowLeft, ArrowUp, CheckCircle2, CloudSun, ImagePlus, Plus, Save, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';

function ActivitySection({ items = [], onChange, disabled }) {
    const [draft, setDraft] = useState({ titulo: '', ocorrencia: '' });

    const saveActivity = () => {
        const next = {
            titulo: draft.titulo.trim(),
            ocorrencia: draft.ocorrencia.trim(),
        };

        if (!next.titulo && !next.ocorrencia) return;

        onChange([...items, next]);
        setDraft({ titulo: '', ocorrencia: '' });
    };

    const remove = (index) => onChange(items.filter((_, itemIndex) => itemIndex !== index));

    return (
        <section className="rounded-2xl border border-[var(--border)] bg-white p-5 shadow-sm">
            <h2 className="text-lg font-black text-[var(--ink-900)]">Atividades e ocorrências</h2>

            {!disabled && (
                <div className="mt-4 rounded-xl border border-[var(--border)] bg-slate-50 p-4">
                    <div className="grid gap-3 md:grid-cols-2">
                        <label>
                            <span className="eyebrow mb-1.5 block">Título da atividade</span>
                            <input
                                className="sig-input w-full"
                                value={draft.titulo}
                                onChange={(event) => setDraft((current) => ({ ...current, titulo: event.target.value }))}
                                placeholder="Ex.: Concretagem da frente 100"
                            />
                        </label>
                        <label className="md:col-span-2">
                            <span className="eyebrow mb-1.5 block">Ocorrência</span>
                            <textarea
                                className="sig-input min-h-[90px] w-full"
                                value={draft.ocorrencia}
                                onChange={(event) => setDraft((current) => ({ ...current, ocorrencia: event.target.value }))}
                                placeholder="Descreva o que foi executado ou observado em campo."
                            />
                        </label>
                    </div>
                    <button type="button" onClick={saveActivity} className="mt-3 inline-flex items-center gap-2 rounded-lg bg-[var(--primary)] px-4 py-2.5 text-sm font-bold text-white">
                        <Save size={16} /> Salvar atividade
                    </button>
                </div>
            )}

            <div className="mt-4 space-y-3">
                {items.length === 0 && (
                    <div className="rounded-xl border border-dashed border-[var(--border)] bg-[var(--surface-muted)] px-4 py-5 text-sm font-semibold text-[var(--ink-500)]">
                        Nenhuma atividade salva.
                    </div>
                )}
                {items.map((item, index) => (
                    <div key={`${item.titulo}-${index}`} className="rounded-xl border border-[var(--border)] bg-white p-4">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h3 className="font-black text-[var(--ink-900)]">{item.titulo || 'Atividade sem título'}</h3>
                                {item.ocorrencia && <p className="mt-1 whitespace-pre-wrap text-sm text-[var(--ink-600)]">{item.ocorrencia}</p>}
                            </div>
                            {!disabled && (
                                <button type="button" onClick={() => remove(index)} className="rounded-lg bg-red-50 p-2 text-red-700">
                                    <Trash2 size={16} />
                                </button>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

function ResourceSection({ title, catalog = [], items = [], onChange, disabled }) {
    const [draft, setDraft] = useState({ cadastro_id: '', quantidade: '' });

    const add = () => {
        const selected = catalog.find((item) => String(item.id) === String(draft.cadastro_id));
        if (!selected && !draft.quantidade) return;

        onChange([
            ...items,
            {
                cadastro_id: selected?.id || null,
                descricao: selected?.label || '',
                meta: selected?.meta || '',
                quantidade: draft.quantidade || 0,
            },
        ]);
        setDraft({ cadastro_id: '', quantidade: '' });
    };

    const remove = (index) => onChange(items.filter((_, itemIndex) => itemIndex !== index));

    return (
        <section className="rounded-2xl border border-[var(--border)] bg-white p-5 shadow-sm">
            <h2 className="text-lg font-black text-[var(--ink-900)]">{title}</h2>

            {!disabled && (
                <div className="mt-4 grid gap-3 rounded-xl border border-[var(--border)] bg-slate-50 p-4 md:grid-cols-[1fr_160px_auto]">
                    <label>
                        <span className="eyebrow mb-1.5 block">Cadastro</span>
                        <select className="sig-input w-full" value={draft.cadastro_id} onChange={(event) => setDraft((current) => ({ ...current, cadastro_id: event.target.value }))}>
                            <option value="">Selecione...</option>
                            {catalog.map((item) => (
                                <option key={item.id} value={item.id}>{item.label}{item.meta ? ` · ${item.meta}` : ''}</option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="eyebrow mb-1.5 block">Quantidade</span>
                        <input
                            className="sig-input w-full"
                            type="number"
                            min="0"
                            step="0.01"
                            value={draft.quantidade}
                            onChange={(event) => setDraft((current) => ({ ...current, quantidade: event.target.value }))}
                        />
                    </label>
                    <div className="flex items-end">
                        <button type="button" onClick={add} className="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-50 px-4 py-2.5 text-sm font-bold text-blue-700">
                            <Plus size={16} /> Adicionar
                        </button>
                    </div>
                </div>
            )}

            <div className="mt-4 divide-y divide-[var(--border)] rounded-xl border border-[var(--border)]">
                {items.length === 0 && (
                    <div className="px-4 py-5 text-sm font-semibold text-[var(--ink-500)]">Nenhum item adicionado.</div>
                )}
                {items.map((item, index) => (
                    <div key={`${item.cadastro_id}-${index}`} className="flex items-center justify-between gap-4 px-4 py-3">
                        <div>
                            <p className="font-bold text-[var(--ink-900)]">{item.descricao || 'Item sem descrição'}</p>
                            {item.meta && <p className="text-xs text-[var(--ink-500)]">{item.meta}</p>}
                        </div>
                        <div className="flex items-center gap-3">
                            <span className="rounded-lg bg-slate-100 px-3 py-1 text-sm font-black text-[var(--ink-700)]">{item.quantidade}</span>
                            {!disabled && (
                                <button type="button" onClick={() => remove(index)} className="rounded-lg bg-red-50 p-2 text-red-700">
                                    <Trash2 size={16} />
                                </button>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

function WeatherSection({ value = {}, onChange, disabled }) {
    const set = (key, nextValue) => onChange({ ...value, [key]: nextValue });
    const weatherOptions = [
        ['ensolarado', 'Ensolarado'],
        ['nublado', 'Nublado'],
        ['chuvoso', 'Chuvoso'],
        ['nao_aplicavel', 'Não aplicável'],
    ];
    const periods = [
        ['manha', 'Manhã'],
        ['tarde', 'Tarde'],
        ['noite', 'Noite'],
    ];
    const selectedFor = (key) => Array.isArray(value[key]) ? (value[key][0] || '') : (value[key] || '');
    const toggleSituation = (key, option) => {
        set(key, selectedFor(key) === option ? '' : option);
    };

    return (
        <section className="rounded-2xl border border-[var(--border)] bg-white p-5 shadow-sm">
            <div className="mb-4 flex items-center gap-2">
                <CloudSun size={18} className="text-[var(--primary)]" />
                <h2 className="text-lg font-black text-[var(--ink-900)]">Condições de tempo</h2>
            </div>
            <div className="grid gap-4 md:grid-cols-3">
                {periods.map(([key, label]) => (
                    <div key={key} className="rounded-xl border border-[var(--border)] bg-slate-50 p-4">
                        <h3 className="mb-3 font-bold">{label}</h3>
                        <div>
                            <span className="eyebrow mb-2 block">Situação</span>
                            <div className="grid gap-2">
                                {weatherOptions.map(([option, optionLabel]) => (
                                    <label key={option} className="flex items-center gap-2 rounded-lg border border-[var(--border)] bg-white px-3 py-2 text-sm font-semibold text-[var(--ink-700)]">
                                        <input
                                            type="checkbox"
                                            className="h-4 w-4 rounded text-[var(--primary)]"
                                            checked={selectedFor(key) === option}
                                            onChange={() => toggleSituation(key, option)}
                                            disabled={disabled}
                                        />
                                        {optionLabel}
                                    </label>
                                ))}
                            </div>
                        </div>
                        <label className="mt-3 block">
                            <span className="eyebrow mb-1.5 block">Pluviosidade (mm)</span>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                className="sig-input w-full"
                                value={value[`precipitacao_${key}_mm`] || ''}
                                onChange={(event) => set(`precipitacao_${key}_mm`, event.target.value)}
                                disabled={disabled}
                            />
                        </label>
                    </div>
                ))}
            </div>
            <label className="mt-4 flex items-center gap-3 rounded-xl border border-[var(--border)] px-4 py-3">
                <input type="checkbox" className="h-5 w-5 rounded text-[var(--primary)]" checked={Boolean(value.dia_impraticavel)} onChange={(event) => set('dia_impraticavel', event.target.checked)} disabled={disabled} />
                <span className="font-semibold">Dia impraticável</span>
            </label>
        </section>
    );
}

function PhotoSection({ form, disabled }) {
    const data = form.data.dados.fotos || { arquivos: [], novas_fotos: [], ordem_fotos: [] };
    const existing = data.arquivos || [];
    const newPhotos = data.novas_fotos || [];
    const defaultOrder = [
        ...existing.map((photo) => `existing:${photo.path}`),
        ...newPhotos.map((photo) => `new:${photo.client_id}`),
    ];
    const order = (data.ordem_fotos || []).filter((key) => defaultOrder.includes(key));
    const orderedKeys = [...order, ...defaultOrder.filter((key) => !order.includes(key))];

    const setPhotoData = (next) => form.setData('dados', { ...form.data.dados, fotos: next });

    const addPhotos = (event) => {
        const files = Array.from(event.target.files || []);
        if (files.length === 0) return;
        const metadata = files.map((file, index) => ({
            client_id: `${Date.now()}-${index}-${Math.random().toString(36).slice(2)}`,
            nome: file.name,
            comment: '',
            preview_url: URL.createObjectURL(file),
        }));
        const keys = metadata.map((photo) => `new:${photo.client_id}`);
        form.setData({
            ...form.data,
            fotos: [...(form.data.fotos || []), ...files],
            dados: {
                ...form.data.dados,
                fotos: {
                    ...data,
                    novas_fotos: [...newPhotos, ...metadata],
                    ordem_fotos: [...orderedKeys, ...keys],
                },
            },
        });
        event.target.value = '';
    };

    const updateComment = (key, comment) => {
        if (key.startsWith('existing:')) {
            const path = key.slice('existing:'.length);
            setPhotoData({
                ...data,
                arquivos: existing.map((photo) => photo.path === path ? { ...photo, comment, legenda: comment } : photo),
                ordem_fotos: orderedKeys,
            });
            return;
        }
        const clientId = key.slice('new:'.length);
        setPhotoData({
            ...data,
            novas_fotos: newPhotos.map((photo) => photo.client_id === clientId ? { ...photo, comment } : photo),
            ordem_fotos: orderedKeys,
        });
    };

    const removePhoto = (key) => {
        if (key.startsWith('existing:')) {
            const path = key.slice('existing:'.length);
            setPhotoData({
                ...data,
                arquivos: existing.filter((photo) => photo.path !== path),
                ordem_fotos: orderedKeys.filter((item) => item !== key),
            });
            return;
        }
        const clientId = key.slice('new:'.length);
        const index = newPhotos.findIndex((photo) => photo.client_id === clientId);
        if (index < 0) return;
        URL.revokeObjectURL(newPhotos[index].preview_url);
        form.setData({
            ...form.data,
            fotos: (form.data.fotos || []).filter((_, fileIndex) => fileIndex !== index),
            dados: {
                ...form.data.dados,
                fotos: {
                    ...data,
                    novas_fotos: newPhotos.filter((photo) => photo.client_id !== clientId),
                    ordem_fotos: orderedKeys.filter((item) => item !== key),
                },
            },
        });
    };

    const movePhoto = (key, direction) => {
        const index = orderedKeys.indexOf(key);
        const nextIndex = index + direction;
        if (index < 0 || nextIndex < 0 || nextIndex >= orderedKeys.length) return;
        const next = [...orderedKeys];
        [next[index], next[nextIndex]] = [next[nextIndex], next[index]];
        setPhotoData({ ...data, ordem_fotos: next });
    };

    const photoFromKey = (key) => {
        if (key.startsWith('existing:')) {
            const photo = existing.find((item) => item.path === key.slice('existing:'.length));
            return photo ? { key, previewUrl: `/storage/${photo.path}`, name: photo.nome, comment: photo.comment ?? photo.legenda ?? '' } : null;
        }
        const photo = newPhotos.find((item) => item.client_id === key.slice('new:'.length));
        return photo ? { key, previewUrl: photo.preview_url, name: photo.nome, comment: photo.comment || '' } : null;
    };

    const photos = orderedKeys.map(photoFromKey).filter(Boolean);

    return (
        <section className="rounded-2xl border border-[var(--border)] bg-white p-5 shadow-sm">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-lg font-black text-[var(--ink-900)]">Registros fotográficos</h2>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">Organize a posição, comente ou exclua cada imagem.</p>
                </div>
                {!disabled && (
                    <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-blue-50 px-3 py-2 text-sm font-bold text-blue-700">
                        <ImagePlus size={16} /> Adicionar fotos
                        <input className="sr-only" type="file" multiple accept="image/jpeg,image/png,image/webp" onChange={addPhotos} />
                    </label>
                )}
            </div>
            <div className="grid gap-3">
                {photos.length === 0 && (
                    <div className="rounded-xl border border-dashed border-[var(--border)] bg-[var(--surface-muted)] px-4 py-8 text-center text-sm font-semibold text-[var(--ink-500)]">
                        Nenhuma imagem adicionada.
                    </div>
                )}
                {photos.map((photo, index) => (
                    <div key={photo.key} className="grid gap-3 rounded-xl border border-[var(--border)] bg-slate-50 p-3 sm:grid-cols-[100px_1fr]">
                        <a href={photo.previewUrl} target="_blank" rel="noreferrer">
                            <img src={photo.previewUrl} alt={photo.name} className="h-24 w-24 rounded-lg object-cover" />
                        </a>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="rounded-full bg-white px-3 py-1 text-xs font-bold">Posição {index + 1}</span>
                                {!disabled && (
                                    <>
                                        <button type="button" onClick={() => movePhoto(photo.key, -1)} disabled={index === 0} className="rounded-lg bg-white p-2 text-[var(--ink-600)]"><ArrowUp size={14} /></button>
                                        <button type="button" onClick={() => movePhoto(photo.key, 1)} disabled={index === photos.length - 1} className="rounded-lg bg-white p-2 text-[var(--ink-600)]"><ArrowDown size={14} /></button>
                                        <button type="button" onClick={() => removePhoto(photo.key)} className="rounded-lg bg-red-50 p-2 text-red-700"><Trash2 size={14} /></button>
                                    </>
                                )}
                                <span className="min-w-0 truncate text-xs text-[var(--ink-500)]">{photo.name}</span>
                            </div>
                            <textarea
                                className="mt-2 w-full rounded-md border border-[var(--border)] bg-white px-3 py-2 text-sm outline-none focus:border-[var(--primary)]"
                                value={photo.comment}
                                onChange={(event) => updateComment(photo.key, event.target.value)}
                                placeholder="Comentário da imagem"
                                disabled={disabled}
                                rows={2}
                            />
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

export default function RdaShow({ rda, catalogs = {} }) {
    const published = rda.status === 'publicado';
    const form = useForm({
        dados: {
            clima: rda.dados?.clima || {},
            atividades: rda.dados?.atividades || [],
            mao_obra: rda.dados?.mao_obra || [],
            equipamentos: rda.dados?.equipamentos || [],
            subcontratadas: rda.dados?.subcontratadas || [],
            fotos: rda.dados?.fotos || { arquivos: [], novas_fotos: [], ordem_fotos: [] },
        },
        fotos: [],
    });

    const updateDados = (key, value) => form.setData('dados', { ...form.data.dados, [key]: value });
    const save = () => form.patch(rda.update_url, { preserveScroll: true, forceFormData: true });
    const publish = () => form.post(rda.publish_url, { preserveScroll: true, forceFormData: true });
    const changeObra = (obraId) => {
        if (!obraId || Number(obraId) === Number(rda.obra?.id)) return;
        router.post(rda.store_url, {
            contract_id: rda.contract?.id,
            obra_id: obraId,
            reference_date: rda.reference_date,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`${rda.code} - RDA`} />

            <div className="mx-auto max-w-[1400px] px-4 py-6 sm:px-6">
                <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link href={rda.calendar_url} className="mb-3 inline-flex items-center gap-2 text-sm font-bold text-[var(--primary)]">
                            <ArrowLeft size={16} /> Voltar para o calendário
                        </Link>
                        <span className="eyebrow">Diário de Obra · RDA</span>
                        <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">{rda.code}</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            {rda.reference_date_formatted} · {rda.contract?.label}
                        </p>
                        <label className="mt-3 block max-w-xl">
                            <span className="eyebrow mb-1.5 block">Obra / frente preenchida</span>
                            <select className="sig-input w-full" value={rda.obra?.id || ''} onChange={(event) => changeObra(event.target.value)}>
                                {(rda.available_obras || []).map((obra) => <option key={obra.id} value={obra.id}>{obra.label}</option>)}
                            </select>
                        </label>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <span className={`rounded-full px-3 py-1 text-xs font-black ${published ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                            {rda.status_label}
                        </span>
                        {rda.rdo && (
                            <Link href={rda.rdo.url} className="rounded-lg bg-blue-50 px-3 py-2 text-sm font-bold text-blue-700">
                                Ver {rda.rdo.code}
                            </Link>
                        )}
                    </div>
                </div>

                {published && (
                    <div className="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">
                        <CheckCircle2 className="mr-2 inline" size={18} />
                        Este RDA foi publicado{rda.published_at ? ` em ${rda.published_at}` : ''}. Ele fica disponível para consolidação no RDO.
                    </div>
                )}

                <div className="space-y-5">
                    <WeatherSection
                        disabled={published}
                        value={form.data.dados.clima || {}}
                        onChange={(value) => updateDados('clima', value)}
                    />

                    <ActivitySection
                        disabled={published}
                        items={form.data.dados.atividades}
                        onChange={(value) => updateDados('atividades', value)}
                    />

                    <ResourceSection
                        title="Mão de obra"
                        disabled={published}
                        catalog={catalogs.mao_obra || []}
                        items={form.data.dados.mao_obra}
                        onChange={(value) => updateDados('mao_obra', value)}
                    />

                    <ResourceSection
                        title="Equipamentos"
                        disabled={published}
                        catalog={catalogs.equipamentos || []}
                        items={form.data.dados.equipamentos}
                        onChange={(value) => updateDados('equipamentos', value)}
                    />

                    <ResourceSection
                        title="Subcontratadas"
                        disabled={published}
                        catalog={catalogs.subcontratadas || []}
                        items={form.data.dados.subcontratadas}
                        onChange={(value) => updateDados('subcontratadas', value)}
                    />

                    <PhotoSection form={form} disabled={published} />
                </div>

                {!published && (
                    <div className="sticky bottom-0 mt-6 flex flex-wrap justify-end gap-3 border-t border-[var(--border)] bg-[var(--surface)] py-4">
                        <button type="button" onClick={save} disabled={form.processing} className="inline-flex items-center gap-2 rounded-lg border border-[var(--border)] bg-white px-4 py-2.5 font-bold text-[var(--ink-800)]">
                            <Save size={17} /> Salvar rascunho
                        </button>
                        <button type="button" onClick={publish} className="inline-flex items-center gap-2 rounded-lg bg-[var(--primary)] px-4 py-2.5 font-bold text-white">
                            <Send size={17} /> Publicar RDA
                        </button>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
