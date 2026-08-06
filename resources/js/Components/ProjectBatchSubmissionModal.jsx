import ProjectIdentity from '@/Components/ProjectIdentity';
import { useForm } from '@inertiajs/react';
import { FileCheck2, Files, FileText, Send, Trash2, TriangleAlert, UploadCloud, X } from 'lucide-react';
import { useEffect, useMemo } from 'react';

const MAX_FILE_SIZE = 50 * 1024 * 1024;

function normalizePart(value) {
    return String(value || '').trim().toUpperCase().replace(/\s+/g, '').replace(/[^A-Z0-9]/g, '');
}

function normalizeSequence(value) {
    const digits = String(value || '').replace(/\D+/g, '').slice(0, 3);
    return digits ? digits.padStart(3, '0') : '';
}

function buildCode(contract, obra, trecho, disciplina, phase, type, typeCodes, sequence) {
    return [contract?.code, obra?.codigo, trecho?.codigo, disciplina?.sigla, phase?.code, typeCodes?.[type] || type, sequence]
        .map(normalizePart).filter(Boolean).join('-');
}

function nextRevision(revision) {
    const match = String(revision || '').match(/^R?(\d+)$/i);
    return `R${String(match ? Number(match[1]) + 1 : 0).padStart(2, '0')}`;
}

function emptyItem(sequence = '001', disciplineId = '') {
    return {
        key: `${Date.now()}-${Math.random()}`,
        disciplina_id: disciplineId,
        document_number: sequence,
        title: '',
        revision_change_summary: '',
        file: null,
    };
}

export default function ProjectBatchSubmissionModal({
    tenant,
    contracts,
    obras,
    trechos,
    disciplinas,
    documents,
    projectPhases,
    documentTypes,
    documentTypeCodes,
    allowedExtensions,
    capImpactLabels,
    onClose,
}) {
    const defaultContract = contracts[0] || null;
    const defaultObra = obras.find((item) => String(item.contract_id) === String(defaultContract?.id)) || null;
    const defaultTrecho = trechos.find((item) => String(item.obra_id) === String(defaultObra?.id)) || null;
    const defaultPhase = projectPhases[0] || null;
    const defaultType = Object.keys(documentTypes)[0] || 'projeto';
    const form = useForm({
        contract_id: defaultContract?.id || '',
        obra_id: defaultObra?.id || '',
        trecho_id: defaultTrecho?.id || '',
        project_phase_id: defaultPhase?.id || '',
        document_type: defaultType,
        title: '',
        cap_reason: '',
        cap_description: '',
        cap_impacts: [],
        items: [],
    });

    useEffect(() => {
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = previousOverflow;
        };
    }, []);

    const selectedContract = contracts.find((item) => String(item.id) === String(form.data.contract_id)) || null;
    const selectedObra = obras.find((item) => String(item.id) === String(form.data.obra_id)) || null;
    const selectedTrecho = trechos.find((item) => String(item.id) === String(form.data.trecho_id)) || null;
    const selectedPhase = projectPhases.find((item) => String(item.id) === String(form.data.project_phase_id)) || null;
    const contractObras = useMemo(() => obras.filter((item) => String(item.contract_id) === String(form.data.contract_id)), [obras, form.data.contract_id]);
    const obraTrechos = useMemo(() => trechos.filter((item) => String(item.obra_id) === String(form.data.obra_id)), [trechos, form.data.obra_id]);
    const contractDisciplines = useMemo(() => disciplinas.filter((item) => String(item.contract_id) === String(form.data.contract_id)), [disciplinas, form.data.contract_id]);
    const acceptedFiles = allowedExtensions.map((extension) => `.${extension}`).join(',');

    const itemMeta = form.data.items.map((item) => {
        const discipline = disciplinas.find((candidate) => String(candidate.id) === String(item.disciplina_id)) || null;
        const sequence = normalizeSequence(item.document_number);
        const code = buildCode(selectedContract, selectedObra, selectedTrecho, discipline, selectedPhase, form.data.document_type, documentTypeCodes, sequence);
        const existing = documents.find((document) => String(document.code || '') === code) || null;
        const revision = existing ? nextRevision(existing.latest_version?.revision) : 'R00';

        return { discipline, sequence, code, existing, revision, requiresCap: Boolean(Number(existing?.has_approved_version || 0)) };
    });
    const requiresCap = itemMeta.some((item) => item.requiresCap);
    const selectedProjectCount = form.data.items.filter((item) => item.file).length;
    const canSubmitPackage = form.data.items.length > 1 && selectedProjectCount === form.data.items.length;
    const disciplineCounts = form.data.items.reduce((counts, item) => {
        const disciplineId = String(item.disciplina_id || '');
        counts[disciplineId] = (counts[disciplineId] || 0) + 1;
        return counts;
    }, {});
    const baseSequence = normalizeSequence(form.data.items[0]?.document_number) || '001';

    const setItems = (items) => form.setData('items', items);
    const updateItem = (index, field, value) => setItems(form.data.items.map((item, itemIndex) => itemIndex === index ? { ...item, [field]: value } : item));

    const suggestItemSequences = (items, context = {}) => {
        if (!items.length) return items;

        const contractId = context.contract_id ?? form.data.contract_id;
        const obraId = context.obra_id ?? form.data.obra_id;
        const trechoId = context.trecho_id ?? form.data.trecho_id;
        const phaseId = context.project_phase_id ?? form.data.project_phase_id;
        const documentType = context.document_type ?? form.data.document_type;
        const disciplineIds = [...new Set(items.map((item) => String(item.disciplina_id || '')).filter(Boolean))];
        const occupiedByDiscipline = new Map(disciplineIds.map((disciplineId) => [disciplineId, new Set()]));

        documents
            .filter((document) => (
                String(document.contract_id) === String(contractId)
                && String(document.obra_id) === String(obraId)
                && String(document.trecho_id || '') === String(trechoId || '')
                && String(document.project_phase_id) === String(phaseId)
                && String(document.document_type) === String(documentType)
                && occupiedByDiscipline.has(String(document.disciplina_id))
            ))
            .forEach((document) => {
                const sequence = normalizeSequence(document.document_number);
                if (sequence) occupiedByDiscipline.get(String(document.disciplina_id)).add(sequence);
            });

        const highestOccupied = Math.max(
            0,
            ...Array.from(occupiedByDiscipline.values()).flatMap((sequences) => Array.from(sequences).map(Number)),
        );
        const sharedSequence = highestOccupied < 999 ? highestOccupied + 1 : null;
        const disciplineCounts = items.reduce((counts, item) => {
            const disciplineId = String(item.disciplina_id || '');
            counts[disciplineId] = (counts[disciplineId] || 0) + 1;
            return counts;
        }, {});
        const nextByDiscipline = new Map(disciplineIds.map((disciplineId) => [disciplineId, sharedSequence]));

        return items.map((item) => {
            const disciplineId = String(item.disciplina_id || '');
            if (!disciplineId || sharedSequence === null) return { ...item, document_number: '' };

            if (disciplineCounts[disciplineId] === 1) {
                return { ...item, document_number: String(sharedSequence).padStart(3, '0') };
            }

            const occupied = occupiedByDiscipline.get(disciplineId) || new Set();
            let sequence = nextByDiscipline.get(disciplineId) || sharedSequence;
            while (sequence <= 999 && occupied.has(String(sequence).padStart(3, '0'))) sequence += 1;
            nextByDiscipline.set(disciplineId, sequence + 1);
            if (sequence <= 999) occupied.add(String(sequence).padStart(3, '0'));

            return { ...item, document_number: sequence <= 999 ? String(sequence).padStart(3, '0') : '' };
        });
    };

    const changeItemDiscipline = (index, disciplineId) => {
        const nextItems = form.data.items.map((item, itemIndex) => itemIndex === index ? { ...item, disciplina_id: disciplineId } : { ...item });
        setItems(suggestItemSequences(nextItems));
    };

    const changeItemSequence = (index, value) => {
        const sequence = String(value || '').replace(/\D+/g, '').slice(0, 3);
        const nextItems = form.data.items.map((item, itemIndex) => itemIndex === index ? { ...item, document_number: sequence } : { ...item });

        if (index === 0) {
            const counts = nextItems.reduce((result, item) => {
                const disciplineId = String(item.disciplina_id || '');
                result[disciplineId] = (result[disciplineId] || 0) + 1;
                return result;
            }, {});
            nextItems.forEach((item, itemIndex) => {
                if (itemIndex > 0 && counts[String(item.disciplina_id || '')] === 1) item.document_number = sequence;
            });
        }

        setItems(nextItems);
    };

    const changeContract = (contractId) => {
        const nextObra = obras.find((item) => String(item.contract_id) === String(contractId)) || null;
        const nextTrecho = trechos.find((item) => String(item.obra_id) === String(nextObra?.id)) || null;
        const nextDiscipline = disciplinas.find((item) => String(item.contract_id) === String(contractId)) || null;
        const nextItems = form.data.items.map((item) => ({ ...item, disciplina_id: nextDiscipline?.id || '' }));
        const nextContext = { contract_id: contractId, obra_id: nextObra?.id || '', trecho_id: nextTrecho?.id || '' };
        form.setData({
            ...form.data,
            ...nextContext,
            items: suggestItemSequences(nextItems, nextContext),
        });
    };

    const changeObra = (obraId) => {
        const nextTrecho = trechos.find((item) => String(item.obra_id) === String(obraId)) || null;
        const nextContext = { obra_id: obraId, trecho_id: nextTrecho?.id || '' };
        form.setData({ ...form.data, ...nextContext, items: suggestItemSequences(form.data.items, nextContext) });
    };

    const changeTrecho = (trechoId) => {
        form.setData({ ...form.data, trecho_id: trechoId, items: suggestItemSequences(form.data.items, { trecho_id: trechoId }) });
    };

    const changePhase = (phaseId) => {
        form.setData({ ...form.data, project_phase_id: phaseId, items: suggestItemSequences(form.data.items, { project_phase_id: phaseId }) });
    };

    const changeDocumentType = (documentType) => {
        form.setData({ ...form.data, document_type: documentType, items: suggestItemSequences(form.data.items, { document_type: documentType }) });
    };

    const addFiles = (files) => {
        const selected = Array.from(files || []).slice(0, 20);
        if (!selected.length) return;
        const existingItems = form.data.items.every((item) => !item.file && !item.title) ? [] : form.data.items;
        const defaultDisciplineId = contractDisciplines[0]?.id || '';
        const nextItems = selected.map((file) => ({
                ...emptyItem('', defaultDisciplineId),
                title: file.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' '),
                file,
            }));
        setItems(suggestItemSequences([...existingItems, ...nextItems].slice(0, 20)));
    };

    const toggleImpact = (impact) => {
        const impacts = form.data.cap_impacts.includes(impact)
            ? form.data.cap_impacts.filter((item) => item !== impact)
            : [...form.data.cap_impacts, impact];
        form.setData('cap_impacts', impacts);
    };

    const submit = (event) => {
        event.preventDefault();
        form.clearErrors();
        const localErrors = {};
        const counts = form.data.items.reduce((result, item) => {
            const disciplineId = String(item.disciplina_id || '');
            result[disciplineId] = (result[disciplineId] || 0) + 1;
            return result;
        }, {});
        const sharedSequence = normalizeSequence(form.data.items[0]?.document_number);
        form.data.items.forEach((item, index) => {
            if (!item.file) localErrors[`items.${index}.file`] = 'Selecione o arquivo.';
            if (counts[String(item.disciplina_id || '')] === 1 && normalizeSequence(item.document_number) !== sharedSequence) {
                localErrors[`items.${index}.document_number`] = 'Disciplinas diferentes devem usar o mesmo sequencial.';
            }
            if (item.file && item.file.size > MAX_FILE_SIZE) localErrors[`items.${index}.file`] = 'Máximo de 50 MB por arquivo.';
        });
        if (Object.keys(localErrors).length) {
            Object.entries(localErrors).forEach(([field, message]) => form.setError(field, message));
            return;
        }
        form.post(route('tenant.projects.batches.store', tenant.slug), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <div className="fixed inset-0 z-[120] grid place-items-center overflow-y-auto bg-[rgba(11,16,32,0.45)] p-3 sm:p-6" onMouseDown={() => !form.processing && onClose()}>
            <form className="my-auto max-h-[calc(100vh-2rem)] w-full max-w-[1120px] overflow-x-hidden overflow-y-auto rounded-lg border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.25)]" onSubmit={submit} onMouseDown={(event) => event.stopPropagation()}>
                <header className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-[var(--border)] bg-white px-5 py-4 sm:px-6">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]"><Files size={15} /><span className="eyebrow">Pacote de projetos</span></div>
                        <h2 className="mt-1 text-xl font-semibold">Submeter em lote</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">Defina a parte comum da EAP e informe apenas disciplina, sequencial e arquivo de cada projeto.</p>
                    </div>
                    <button type="button" className="sig-btn sig-btn-ghost !min-h-9 !px-2" onClick={onClose} aria-label="Fechar"><X size={18} /></button>
                </header>

                <div className="grid gap-5 p-5 sm:p-6">
                    {Object.keys(form.errors).length > 0 && (
                        <div className="rounded-lg bg-[var(--red-50)] px-4 py-3 text-sm text-[var(--red)]">Revise os campos destacados antes de enviar o pacote.</div>
                    )}

                    <section>
                        <h3 className="text-sm font-semibold">Dados comuns</h3>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <BatchField label="Contrato" error={form.errors.contract_id}><select value={form.data.contract_id} onChange={(e) => changeContract(e.target.value)} required><option value="">Selecione</option>{contracts.map((item) => <option key={item.id} value={item.id}>{item.code} - {item.name}</option>)}</select></BatchField>
                            <BatchField label="Obra" error={form.errors.obra_id}><select value={form.data.obra_id} onChange={(e) => changeObra(e.target.value)} required><option value="">Selecione</option>{contractObras.map((item) => <option key={item.id} value={item.id}>{item.codigo} - {item.nome}</option>)}</select></BatchField>
                            <BatchField label="Trecho" error={form.errors.trecho_id}><select value={form.data.trecho_id} onChange={(e) => changeTrecho(e.target.value)} required><option value="">Selecione</option>{obraTrechos.map((item) => <option key={item.id} value={item.id}>{item.codigo} - {item.nome}</option>)}</select></BatchField>
                            <BatchField label="Fase" error={form.errors.project_phase_id}><select value={form.data.project_phase_id} onChange={(e) => changePhase(e.target.value)} required>{projectPhases.map((item) => <option key={item.id} value={item.id}>{item.code} - {item.name}</option>)}</select></BatchField>
                            <BatchField label="Tipo de documento" error={form.errors.document_type}><select value={form.data.document_type} onChange={(e) => changeDocumentType(e.target.value)}>{Object.entries(documentTypes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></BatchField>
                            <BatchField label="Título do pacote" error={form.errors.title}><input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="Ex: Entrega executiva - lote 03" required /></BatchField>
                        </div>
                    </section>

                    <section className="border-t border-[var(--border)] pt-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-semibold">Projetos do pacote</h3>
                                <p className="mt-1 text-xs text-[var(--ink-500)]">Disciplinas diferentes usam o mesmo sequencial. Ele só varia quando houver mais de um projeto na mesma disciplina.</p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <label className="sig-btn sig-btn-secondary sig-btn-sm cursor-pointer"><UploadCloud size={14} />Selecionar arquivos<input className="sr-only" type="file" multiple accept={acceptedFiles} onChange={(e) => addFiles(e.target.files)} /></label>
                            </div>
                        </div>

                        {form.data.items.length === 0 ? (
                            <div className="mt-3 flex min-h-24 items-center justify-center rounded-md border border-dashed border-[var(--border)] bg-[var(--surface-muted)] px-4 py-5 text-center">
                                <div>
                                    <UploadCloud size={20} className="mx-auto text-[var(--ink-400)]" />
                                    <p className="mt-2 text-sm font-medium text-[var(--ink-700)]">Selecione os arquivos do pacote</p>
                                    <p className="mt-1 text-xs text-[var(--ink-500)]">Escolha pelo menos dois arquivos para configurar e submeter os projetos em lote.</p>
                                </div>
                            </div>
                        ) : (
                            <div className="mt-3 grid gap-3">
                                {form.data.items.map((item, index) => {
                                    const meta = itemMeta[index];
                                    const rowTitle = meta.existing?.title || item.title;
                                    return (
                                    <article key={item.key} className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
                                        <div className="grid items-start gap-3 lg:grid-cols-[1fr_120px_1.35fr_1.35fr_38px]">
                                            <BatchField label="Disciplina" error={form.errors[`items.${index}.disciplina_id`]}><select value={item.disciplina_id} onChange={(e) => changeItemDiscipline(index, e.target.value)} required><option value="">Selecione</option>{contractDisciplines.map((discipline) => <option key={discipline.id} value={discipline.id}>{discipline.sigla} - {discipline.nome}</option>)}</select></BatchField>
                                            <BatchField label="Sequencial" error={form.errors[`items.${index}.document_number`]}><input value={item.document_number} inputMode="numeric" pattern="[0-9]{3}" minLength={3} maxLength={3} readOnly={index > 0 && disciplineCounts[String(item.disciplina_id || '')] === 1} title={index > 0 && disciplineCounts[String(item.disciplina_id || '')] === 1 ? `Sequencial compartilhado com as demais disciplinas: ${baseSequence}` : undefined} onChange={(e) => changeItemSequence(index, e.target.value)} onBlur={() => changeItemSequence(index, normalizeSequence(item.document_number))} required /></BatchField>
                                            <BatchField label="Título" error={form.errors[`items.${index}.title`]}><input value={rowTitle} onChange={(e) => !meta.existing && updateItem(index, 'title', e.target.value)} readOnly={Boolean(meta.existing)} placeholder="Título do projeto" required={!meta.existing} /></BatchField>
                                            <BatchField label="Arquivo" error={form.errors[`items.${index}.file`]}><label className="sig-input flex cursor-pointer items-center gap-2 bg-white"><UploadCloud size={15} className="shrink-0 text-[var(--primary)]" /><span className="min-w-0 truncate text-sm">{item.file?.name || 'Selecionar arquivo'}</span><input className="sr-only" type="file" accept={acceptedFiles} onChange={(e) => updateItem(index, 'file', e.target.files?.[0] || null)} /></label></BatchField>
                                            <button type="button" className="sig-btn sig-btn-ghost mt-[19px] !min-h-10 !px-2 text-[var(--red)]" onClick={() => setItems(form.data.items.filter((_, itemIndex) => itemIndex !== index))} aria-label="Remover projeto"><Trash2 size={16} /></button>
                                        </div>
                                        <div className="mt-3 flex flex-wrap items-start justify-between gap-2 border-t border-[var(--border)] pt-3 text-xs">
                                            <ProjectIdentity
                                                className="min-w-0 flex-1"
                                                eap={meta.code ? `${meta.code}-${meta.revision}` : 'Complete a EAP'}
                                                fileName={item.file?.name}
                                                title={rowTitle}
                                            />
                                            <span className={`sig-pill ${meta.existing ? 'sig-pill-amber' : 'sig-pill-blue'}`}>{meta.existing ? `Nova revisão ${meta.revision}` : 'Novo projeto R00'}</span>
                                        </div>
                                        {meta.existing && (
                                            <div className="mt-3 flex items-start gap-2 rounded-md border border-[var(--amber)] bg-[var(--amber-50)] px-3 py-2 text-xs leading-5 text-[var(--amber)]">
                                                <TriangleAlert size={16} className="mt-0.5 shrink-0" />
                                                <span>A EAP <strong>{meta.code}</strong> já existe. Este arquivo entrará como revisão <strong>{meta.revision}</strong> de “{meta.existing.title}”.</span>
                                            </div>
                                        )}
                                        {meta.existing && !meta.requiresCap && (
                                            <div className="mt-3"><BatchField label="Correções realizadas" error={form.errors[`items.${index}.revision_change_summary`]}><textarea rows={2} value={item.revision_change_summary} onChange={(e) => updateItem(index, 'revision_change_summary', e.target.value)} placeholder="Descreva o que foi corrigido após a devolução" /></BatchField></div>
                                        )}
                                    </article>
                                    );
                                })}
                            </div>
                        )}
                    </section>

                    {form.data.items.length > 0 && <section className="border-t border-[var(--border)] pt-5">
                        {requiresCap ? (
                            <>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="flex min-w-0 items-start gap-3">
                                        <span className="grid size-9 shrink-0 place-items-center rounded-md bg-[var(--primary-50)] text-[var(--primary)]"><FileText size={18} /></span>
                                        <div>
                                            <h3 className="text-sm font-semibold">CAP consolidada do pacote</h3>
                                            <p className="mt-1 max-w-3xl text-xs leading-5 text-[var(--ink-500)]">Este pacote contém revisão de projeto aprovado. A CAP reunirá os projetos e registrará o motivo, as alterações e os impactos desta entrega.</p>
                                        </div>
                                    </div>
                                    <span className="sig-pill sig-pill-blue">{form.data.items.length} projetos</span>
                                </div>

                                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                                    <BatchField label="Motivo das alterações" error={form.errors.cap_reason}>
                                        <textarea className="min-h-[104px] w-full resize-y" rows={4} value={form.data.cap_reason} onChange={(e) => form.setData('cap_reason', e.target.value)} required placeholder="Explique por que o pacote está sendo revisado" />
                                    </BatchField>
                                    <BatchField label="Descrição consolidada" error={form.errors.cap_description}>
                                        <textarea className="min-h-[104px] w-full resize-y" rows={4} value={form.data.cap_description} onChange={(e) => form.setData('cap_description', e.target.value)} required placeholder="Resuma as alterações realizadas nos projetos" />
                                    </BatchField>
                                </div>

                                <div className="mt-4">
                                    <span className="eyebrow mb-2 block">Impactos das alterações</span>
                                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        {Object.entries(capImpactLabels).map(([value, label]) => (
                                            <label key={value} className={`flex min-h-10 cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm ${form.data.cap_impacts.includes(value) ? 'border-[var(--primary)] bg-[var(--primary-50)] text-[var(--primary)]' : 'border-[var(--border)] bg-white text-[var(--ink-700)]'}`}>
                                                <input className="size-4 shrink-0 accent-[var(--primary)]" type="checkbox" checked={form.data.cap_impacts.includes(value)} onChange={() => toggleImpact(value)} />
                                                <span>{label}</span>
                                            </label>
                                        ))}
                                    </div>
                                    {form.errors.cap_impacts && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.cap_impacts}</span>}
                                </div>
                            </>
                        ) : (
                            <div className="flex flex-wrap items-start justify-between gap-3 rounded-md border border-[var(--border)] bg-[var(--surface-muted)] px-4 py-3">
                                <div className="flex min-w-0 items-start gap-3">
                                    <FileCheck2 size={19} className="mt-0.5 shrink-0 text-[var(--green)]" />
                                    <div>
                                        <h3 className="text-sm font-semibold">Primeira submissão do pacote</h3>
                                        <p className="mt-1 text-xs leading-5 text-[var(--ink-500)]">Os projetos selecionados ainda não possuem versão aprovada. O pacote seguirá para análise sem gerar CAP.</p>
                                    </div>
                                </div>
                                <span className="sig-pill bg-white text-[var(--ink-600)]">Sem CAP</span>
                            </div>
                        )}
                    </section>}
                </div>

                <footer className="sticky bottom-0 flex justify-end gap-2 border-t border-[var(--border)] bg-white px-5 py-4 sm:px-6">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onClose} disabled={form.processing}>Cancelar</button>
                    <button className="sig-btn sig-btn-primary" disabled={form.processing || !canSubmitPackage}><Send size={15} />{form.processing ? 'Enviando pacote...' : 'Submeter pacote'}</button>
                </footer>
            </form>
        </div>
    );
}

function BatchField({ label, error, children }) {
    return <label className="min-w-0 [&>input]:w-full [&>select]:w-full [&>textarea]:w-full"><span className="eyebrow mb-1 block">{label}</span>{children}{error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}</label>;
}
