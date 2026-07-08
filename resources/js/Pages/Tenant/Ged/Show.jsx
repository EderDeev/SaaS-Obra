import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CalendarDays,
    ChevronDown,
    Database,
    Download,
    FileText,
    History,
    MessageSquare,
    MoreHorizontal,
    Plus,
    Save,
    Send,
    Shield,
    Tags,
    Trash2,
    UserRound,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

const sectionIcons = {
    details: FileText,
    content: FileText,
    metadata: Database,
    notes: MessageSquare,
    history: History,
    permissions: Shield,
};

function formatDateTime(value) {
    if (!value) return '—';

    return new Date(value).toLocaleString('pt-BR');
}

function formatBytes(bytes = 0) {
    const value = Number(bytes || 0);

    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;

    return `${(value / 1024 / 1024).toFixed(1)} MB`;
}

function Field({ label, children, hint }) {
    return (
        <div className="grid gap-1.5">
            <div className="text-xs font-semibold text-[var(--ink-700)]">{label}</div>
            {children}
            {hint && <span className="text-xs text-[var(--ink-500)]">{hint}</span>}
        </div>
    );
}

function Control({ value, placeholder = '—' }) {
    return (
        <div className="flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm text-[var(--ink-800)]">
            <span className={value ? '' : 'text-[var(--ink-400)]'}>{value || placeholder}</span>
        </div>
    );
}

function SelectLike({ value, placeholder = 'Selecione' }) {
    return (
        <div className="flex min-h-10 items-center justify-between rounded-lg border border-slate-300 bg-white px-3 text-sm text-[var(--ink-800)]">
            <span className={value ? '' : 'text-[var(--ink-400)]'}>{value || placeholder}</span>
            <ChevronDown size={16} className="text-[var(--ink-400)]" />
        </div>
    );
}

function TagPill({ tag }) {
    return (
        <span className="rounded-full px-2.5 py-1 text-xs font-bold text-white" style={{ backgroundColor: tag.color }}>
            {tag.name}
        </span>
    );
}

function TagMultiSelect({ tags = [], selectedIds = [], onToggle, disabled = false }) {
    const [open, setOpen] = useState(false);
    const normalizedSelected = selectedIds.map(Number);
    const selectedTags = tags.filter((tag) => normalizedSelected.includes(Number(tag.id)));

    return (
        <div className="relative">
            <div
                role="button"
                tabIndex={disabled ? -1 : 0}
                aria-disabled={disabled}
                onClick={() => !disabled && setOpen((value) => !value)}
                onKeyDown={(event) => {
                    if (!disabled && (event.key === 'Enter' || event.key === ' ')) {
                        event.preventDefault();
                        setOpen((value) => !value);
                    }
                }}
                className={`flex min-h-11 w-full items-center justify-between gap-2 rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-left text-sm outline-none transition hover:border-blue-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 ${disabled ? 'cursor-not-allowed bg-slate-50 text-slate-400' : 'cursor-pointer'}`}
            >
                <span className="flex min-w-0 flex-1 flex-wrap gap-1.5">
                    {selectedTags.length === 0 ? (
                        <span className="px-1 text-[var(--ink-400)]">Selecione etiquetas</span>
                    ) : (
                        selectedTags.map((tag) => (
                            <span
                                key={tag.id}
                                className="inline-flex max-w-full items-center gap-1 rounded-md px-2 py-1 text-xs font-bold text-white"
                                style={{ backgroundColor: tag.color || '#2563eb' }}
                                onClick={(event) => event.stopPropagation()}
                            >
                                <span className="truncate">{tag.name}</span>
                                <button
                                    type="button"
                                    className="rounded p-0.5 text-white/90 hover:bg-white/20"
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        onToggle(tag.id);
                                    }}
                                    title="Remover etiqueta"
                                >
                                    <X size={12} />
                                </button>
                            </span>
                        ))
                    )}
                </span>
                <ChevronDown size={16} className={`shrink-0 text-[var(--ink-400)] transition ${open ? 'rotate-180' : ''}`} />
            </div>

            {open && (
                <div className="absolute left-0 right-0 z-[80] mt-1 max-h-56 overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-xl">
                    {tags.length === 0 ? (
                        <div className="px-3 py-2 text-sm text-[var(--ink-400)]">Sem etiquetas cadastradas para este contrato</div>
                    ) : (
                        tags.map((tag) => {
                            const selected = normalizedSelected.includes(Number(tag.id));

                            return (
                                <button
                                    key={tag.id}
                                    type="button"
                                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50"
                                    onClick={() => onToggle(tag.id)}
                                >
                                    <span className="inline-flex h-4 w-4 items-center justify-center rounded border border-slate-300 bg-white">
                                        {selected && <CheckIcon />}
                                    </span>
                                    <span
                                        className="inline-flex rounded-md px-2 py-1 text-xs font-bold"
                                        style={{ backgroundColor: tag.color || '#2563eb', color: '#fff' }}
                                    >
                                        {tag.name}
                                    </span>
                                </button>
                            );
                        })
                    )}
                </div>
            )}
        </div>
    );
}

function CheckIcon() {
    return <span className="h-2 w-2 rounded-sm bg-blue-600" />;
}

function ReadOnlyDetailSection({ document }) {
    return (
        <div className="space-y-4">
            <Field label="Título">
                <Control value={document.title} />
            </Field>

            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Correspondente">
                    <SelectLike value={document.correspondent?.name} placeholder="Sem correspondente" />
                </Field>
                <Field label="Tipo de documento">
                    <SelectLike value={document.type?.name} placeholder="Sem tipo" />
                </Field>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Data do documento">
                    <Control value={document.document_date ? new Date(document.document_date).toLocaleDateString('pt-BR') : null} />
                </Field>
                <Field label="Número / Código">
                    <Control value={document.document_number} placeholder="Sem código" />
                </Field>
            </div>

            <Field label="Etiquetas">
                <div className="min-h-10 rounded-lg border border-slate-300 bg-white p-2">
                    <div className="flex flex-wrap gap-2">
                        {document.tags?.length ? document.tags.map((tag) => <TagPill key={tag.id} tag={tag} />) : <span className="text-sm text-[var(--ink-400)]">Sem etiquetas</span>}
                    </div>
                </div>
            </Field>

            <Field label="Contrato">
                <Control value={document.contract ? `${document.contract.code} - ${document.contract.name}` : null} placeholder="Sem contrato" />
            </Field>

            <Field label="Descrição">
                <textarea
                    className="min-h-28 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-[var(--ink-800)] outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                    value={document.description || ''}
                    readOnly
                    placeholder="Nenhuma descrição cadastrada."
                />
            </Field>
        </div>
    );
}

function QuickCreateModal({ type, urls = {}, contracts = [], currentContractId = '', onClose }) {
    const labels = {
        correspondent: {
            title: 'Criar novo correspondente',
            description: 'Cadastre rapidamente um remetente/correspondente para vincular ao documento.',
            action: urls.correspondent,
        },
        type: {
            title: 'Criar tipo documental',
            description: 'Crie um novo tipo vinculado a um contrato.',
            action: urls.type,
        },
        tag: {
            title: 'Criar etiqueta',
            description: 'Crie uma etiqueta vinculada a um contrato.',
            action: urls.tag,
        },
    };
    const modal = labels[type];
    const form = useForm(
        type === 'tag'
            ? { name: '', color: '#2563eb', contract_id: currentContractId || '' }
            : type === 'type'
                ? { name: '', contract_id: currentContractId || '' }
                : { name: '', contract_id: currentContractId || '' },
    );

    if (!modal) return null;

    function submit() {
        form.post(modal.action, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    return (
        <div className="fixed inset-0 z-[10000] flex items-center justify-center bg-slate-950/45 p-4">
            <div className="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 p-5">
                    <div>
                        <h3 className="text-xl font-bold text-[var(--ink-900)]">{modal.title}</h3>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">{modal.description}</p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100">
                        <X size={18} />
                    </button>
                </div>

                <div className="space-y-4 p-5">
                    <Field label="Nome">
                        <input className="ged-control" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} autoFocus />
                        {form.errors.name && <span className="text-xs font-semibold text-rose-600">{form.errors.name}</span>}
                    </Field>

                    {(type === 'correspondent' || type === 'type' || type === 'tag') && (
                        <Field label="Contrato">
                            <select className="ged-control" required value={form.data.contract_id} onChange={(event) => form.setData('contract_id', event.target.value)}>
                                <option value="">Selecione um contrato</option>
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>{contract.code} - {contract.name}</option>
                                ))}
                            </select>
                            {form.errors.contract_id && <span className="text-xs font-semibold text-rose-600">{form.errors.contract_id}</span>}
                        </Field>
                    )}

                    {type === 'tag' && (
                        <Field label="Cor">
                            <div className="flex items-center gap-3">
                                <input type="color" value={form.data.color} onChange={(event) => form.setData('color', event.target.value)} className="h-10 w-14 rounded-lg border border-slate-300 bg-white p-1" />
                                <input className="ged-control" value={form.data.color} onChange={(event) => form.setData('color', event.target.value)} />
                            </div>
                        </Field>
                    )}
                </div>

                <div className="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 p-4">
                    <button type="button" onClick={onClose} className="sig-btn sig-btn-ghost">Cancelar</button>
                    <button type="button" onClick={submit} className="sig-btn sig-btn-primary" disabled={form.processing}>
                        <Save size={16} />
                        Salvar
                    </button>
                </div>
            </div>
        </div>
    );
}

function AddButton({ title, onClick }) {
    return (
        <button type="button" onClick={onClick} className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-blue-100 bg-blue-50 text-blue-700 hover:bg-blue-100" title={title}>
            <Plus size={15} />
        </button>
    );
}

function DetailSection({ document, lookups = {}, quickStoreUrls = {} }) {
    const [modal, setModal] = useState(null);
    const contracts = lookups.contracts || [];
    const types = lookups.types || [];
    const tags = lookups.tags || [];
    const correspondents = lookups.correspondents || [];
    const selectedTagIds = (document.tags || []).map((tag) => tag.id);
    const form = useForm({
        title: document.title || '',
        correspondent_id: document.correspondent?.id || '',
        document_type_id: document.type?.id || '',
        document_date: document.document_date || '',
        tag_ids: selectedTagIds,
        contract_id: document.contract?.id || '',
        description: document.description || '',
    });

    const filteredTypes = types.filter((item) => form.data.contract_id && Number(item.contract_id) === Number(form.data.contract_id));
    const filteredTags = tags.filter((item) => form.data.contract_id && Number(item.contract_id) === Number(form.data.contract_id));
    const filteredCorrespondents = correspondents.filter((item) => form.data.contract_id && Number(item.contract_id) === Number(form.data.contract_id));

    function toggleTag(tagId) {
        const current = form.data.tag_ids || [];
        const exists = current.map(Number).includes(Number(tagId));
        form.setData('tag_ids', exists ? current.filter((id) => Number(id) !== Number(tagId)) : [...current, tagId]);
    }

    function submit(event) {
        event.preventDefault();
        form.put(document.update_url, { preserveScroll: true });
    }

    return (
        <form id="ged-detail-form" onSubmit={submit} className="space-y-4">
            <Field label="Título">
                <input className="ged-control" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} />
                {form.errors.title && <span className="text-xs font-semibold text-rose-600">{form.errors.title}</span>}
            </Field>

            <div className="grid gap-4 md:grid-cols-2">
                <Field label={<span className="flex items-center justify-between gap-2">Correspondente <AddButton title="Criar correspondente" onClick={() => setModal('correspondent')} /></span>}>
                    <select className="ged-control" value={form.data.correspondent_id} onChange={(event) => form.setData('correspondent_id', event.target.value)}>
                        <option value="">Sem correspondente</option>
                        {filteredCorrespondents.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </select>
                </Field>
                <Field label={<span className="flex items-center justify-between gap-2">Tipo de documento <AddButton title="Criar tipo documental" onClick={() => setModal('type')} /></span>}>
                    <select className="ged-control" value={form.data.document_type_id} onChange={(event) => form.setData('document_type_id', event.target.value)}>
                        <option value="">Sem tipo</option>
                        {filteredTypes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </select>
                </Field>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Data do documento">
                    <input className="ged-control" type="date" value={form.data.document_date || ''} onChange={(event) => form.setData('document_date', event.target.value)} />
                </Field>
                <Field label="Número / Código">
                    <Control value={document.document_number} placeholder="Gerado automaticamente" />
                </Field>
            </div>

            <Field label={<span className="flex items-center justify-between gap-2">Etiquetas <AddButton title="Criar etiqueta" onClick={() => setModal('tag')} /></span>}>
                <TagMultiSelect
                    tags={filteredTags}
                    selectedIds={form.data.tag_ids || []}
                    onToggle={toggleTag}
                    disabled={!form.data.contract_id}
                />
            </Field>

            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Contrato">
                    <select className="ged-control" required value={form.data.contract_id} onChange={(event) => form.setData({ ...form.data, contract_id: event.target.value, document_type_id: '', correspondent_id: '', tag_ids: [] })}>
                        <option value="">Selecione um contrato</option>
                        {contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} - {contract.name}</option>)}
                    </select>
                </Field>
            </div>

            <Field label="Descrição">
                <textarea className="ged-control min-h-28 py-2" value={form.data.description || ''} onChange={(event) => form.setData('description', event.target.value)} placeholder="Nenhuma descrição cadastrada." />
            </Field>

            <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" onClick={() => form.reset()} className="sig-btn sig-btn-ghost">Descartar</button>
                <button type="submit" className="sig-btn sig-btn-primary" disabled={form.processing}>
                    <Save size={16} />
                    Salvar detalhes
                </button>
            </div>

            {modal && <QuickCreateModal type={modal} urls={quickStoreUrls} contracts={contracts} currentContractId={form.data.contract_id} onClose={() => setModal(null)} />}
        </form>
    );
}

function ContentSection({ document }) {
    const ocr = document.metadata?.ocr || {};
    const hasText = Boolean(document.extracted_text);
    const statusTone = {
        done: 'border-emerald-100 bg-emerald-50 text-emerald-900',
        processing: 'border-amber-100 bg-amber-50 text-amber-900',
        queued: 'border-blue-100 bg-blue-50 text-blue-900',
        failed: 'border-rose-100 bg-rose-50 text-rose-900',
        missing_engine: 'border-orange-100 bg-orange-50 text-orange-900',
        disabled: 'border-slate-200 bg-slate-50 text-slate-700',
    };
    const processingReferenceAt = ocr.started_at || ocr.queued_at || document.updated_at || document.created_at;
    const startedAt = processingReferenceAt ? new Date(processingReferenceAt) : null;
    const configuredTimeoutSeconds = Number(ocr.timeout_seconds || 0);
    const staleAfterMs = Math.max(configuredTimeoutSeconds + 900, 45 * 60) * 1000;
    const isStaleProcessing = ocr.status === 'processing'
        && !hasText
        && startedAt instanceof Date
        && !Number.isNaN(startedAt.getTime())
        && Date.now() - startedAt.getTime() > staleAfterMs;
    const displayStatus = hasText ? 'done' : (isStaleProcessing ? 'failed' : ocr.status);
    const currentTone = statusTone[displayStatus] || (hasText ? statusTone.done : statusTone.queued);
    const canReprocess = document.ocr_url && displayStatus !== 'processing';
    const isGenericProcessingMessage = ocr.message === 'Documento em processamento OCR.';
    const shouldShowOcrMessage = ocr.message
        && displayStatus !== 'done'
        && !(isStaleProcessing && isGenericProcessingMessage)
        && !(displayStatus === 'done' && isGenericProcessingMessage);

    function reprocessOcr() {
        if (!document.ocr_url) return;

        router.post(document.ocr_url, {}, {
            preserveScroll: true,
        });
    }

    return (
        <div className="space-y-4">
            <div className={`rounded-xl border p-3 text-sm ${currentTone}`}>
                <div className="font-bold">
                    {hasText
                        ? 'OCR processado'
                        : displayStatus === 'failed'
                            ? (isStaleProcessing ? 'OCR travado' : 'Falha no OCR')
                            : displayStatus === 'missing_engine'
                                ? 'Motor OCR não instalado'
                                : displayStatus === 'processing'
                                    ? 'OCR em processamento'
                                    : 'OCR aguardando processamento'}
                </div>
                {isStaleProcessing && (
                    <div className="mt-1 text-xs">
                        O processamento passou do tempo esperado. Reenvie o documento para a fila de OCR.
                    </div>
                )}
                {shouldShowOcrMessage && (
                    <div className="mt-1 text-xs">
                        {ocr.message}
                    </div>
                )}
                {canReprocess && (
                    <button type="button" onClick={reprocessOcr} className="mt-3 rounded-lg bg-white px-3 py-2 text-xs font-bold text-[var(--ink-800)] shadow-sm ring-1 ring-inset ring-slate-200 hover:bg-slate-50">
                        Reprocessar OCR
                    </button>
                )}
            </div>

            <Field label="Texto OCR">
                <textarea
                    className="min-h-[360px] rounded-lg border border-slate-300 bg-white px-3 py-2 font-mono text-xs leading-5 text-[var(--ink-800)] outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                    value={document.extracted_text || ''}
                    readOnly
                    placeholder={
                        displayStatus === 'failed' || displayStatus === 'missing_engine'
                            ? 'O OCR falhou. Verifique os detalhes acima e reprocesse o documento depois que o motor OCR estiver disponível.'
                            : 'OCR ainda não processado para este documento.'
                    }
                />
            </Field>
        </div>
    );
}

function MetadataSection({ document }) {
    const fileName = (document.archive_path || document.original_path || '').split('/').pop() || document.original_filename;
    const originalMetadata = document.metadata?.original_file_metadata || {};
    const originalMetadataRows = Object.entries(originalMetadata);
    const metadataRows = [
        ['Data de modificação', formatPaperlessDate(document.updated_at)],
        ['Data de adição', formatPaperlessDate(document.created_at)],
        ['Nome do arquivo', fileName],
        ['Nome do arquivo original', document.original_filename],
        ['Soma de verificação MD5 original', document.metadata?.original_md5],
        ['Tamanho do arquivo original', formatBytes(document.size_bytes)],
        ['Tipo mime original', document.mime_type],
    ];

    return (
        <div className="space-y-5 text-sm">
            <div className="space-y-4">
                {metadataRows.map(([label, value]) => (
                    <div key={label} className="grid gap-2 md:grid-cols-[220px_1fr]">
                        <div className="text-[var(--ink-900)]">{label}</div>
                        <div className="break-all text-[var(--ink-900)]">{value || '—'}</div>
                    </div>
                ))}
            </div>

            <details className="group" open>
                <summary className="flex cursor-pointer list-none items-center gap-3 py-2 text-base font-bold text-[var(--ink-900)]">
                    <span className="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-slate-500 text-white transition group-open:rotate-180">
                        <ChevronDown size={18} />
                    </span>
                    Metadados do documento original
                </summary>

                <div className="mt-2 space-y-4">
                    {originalMetadataRows.length === 0 && (
                        <div className="text-[var(--ink-500)]">Nenhum metadado original foi extraído para este documento.</div>
                    )}

                    {originalMetadataRows.map(([label, value]) => (
                        <div key={label} className="grid gap-2 md:grid-cols-[150px_1fr]">
                            <div className="break-all text-[var(--ink-900)]">{label}</div>
                            <div className="break-all text-[var(--ink-900)]">{String(value || '—')}</div>
                        </div>
                    ))}
                </div>
            </details>
        </div>
    );
}

function formatPaperlessDate(value) {
    if (!value) return '—';

    return new Date(value).toLocaleDateString('pt-BR', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function NotesSection({ document }) {
    const form = useForm({ body: '' });
    const notes = document.notes || [];

    function submit(event) {
        event.preventDefault();

        if (!form.data.body.trim()) {
            return;
        }

        form.post(document.notes_store_url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <div className="space-y-4">
            <form onSubmit={submit} className="space-y-2 border-b border-slate-300 pb-4">
                <textarea
                    className="min-h-20 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm text-[var(--ink-800)] outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                    value={form.data.body}
                    onChange={(event) => form.setData('body', event.target.value)}
                    placeholder="Inserir nota"
                />
                {form.errors.body && <div className="text-xs font-semibold text-rose-600">{form.errors.body}</div>}
                <div className="flex justify-end">
                    <button type="submit" className="rounded bg-emerald-800 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-900 disabled:opacity-60" disabled={form.processing || !form.data.body.trim()}>
                        Adicionar nota
                    </button>
                </div>
            </form>

            <div className="space-y-3">
                {notes.length === 0 && <div className="text-sm text-[var(--ink-500)]">Nenhuma nota adicionada.</div>}

                {notes.map((note) => (
                    <div key={note.id} className="overflow-hidden rounded border border-slate-300 bg-white">
                        <div className="min-h-14 whitespace-pre-wrap px-3 py-3 text-sm text-[var(--ink-900)]">{note.body}</div>
                        <div className="border-t border-slate-300 bg-slate-50 px-3 py-2 text-xs font-semibold text-emerald-800">
                            {note.user?.name || 'Sistema'} - {formatPaperlessDate(note.created_at)}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

function HistorySection({ document }) {
    const events = document.history_events || [];
    const versions = document.versions || [];
    const fallbackEvents = versions.map((version) => ({
        id: `version-${version.id}`,
        title: `Versão ${version.version_number} enviada`,
        description: version.notes,
        created_at: version.created_at,
        actor: version.uploader,
        properties: {
            original_filename: version.original_filename,
            size_bytes: version.size_bytes,
            checksum: version.checksum,
        },
    }));
    const timeline = events.length ? events : fallbackEvents;

    return (
        <div className="space-y-3">
            <div className="rounded-xl border border-slate-200 bg-white p-3">
                <div className="text-sm font-bold text-[var(--ink-900)]">Eventos do documento</div>
                <div className="mt-1 text-xs text-[var(--ink-500)]">Linha do tempo de upload, alterações, versões e processamento OCR.</div>
            </div>

            {timeline.length === 0 && <div className="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-[var(--ink-500)]">Nenhum evento encontrado.</div>}

            {timeline.map((event) => (
                <div key={event.id} className="rounded-xl border border-slate-200 bg-white p-4">
                    <div className="flex items-start gap-3">
                        <div className="mt-0.5 flex h-8 w-8 items-center justify-center rounded-full bg-blue-50 text-blue-700">
                            <History size={16} />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="font-semibold text-[var(--ink-900)]">{event.title}</div>
                            <div className="mt-1 text-xs text-[var(--ink-500)]">
                                {formatDateTime(event.created_at)} · {event.actor?.name || 'Sistema'}
                            </div>
                            {event.description && <div className="mt-2 text-sm text-[var(--ink-700)]">{event.description}</div>}
                            {event.properties && Object.keys(event.properties).length > 0 && (
                                <details className="mt-3 rounded-lg border border-slate-200 bg-slate-50">
                                    <summary className="cursor-pointer px-3 py-2 text-xs font-bold uppercase tracking-[0.12em] text-[var(--ink-500)]">Detalhes</summary>
                                    <pre className="max-h-56 overflow-auto border-t border-slate-200 p-3 text-xs leading-5 text-[var(--ink-700)]">
                                        {JSON.stringify(event.properties, null, 2)}
                                    </pre>
                                </details>
                            )}
                        </div>
                    </div>
                </div>
            ))}
        </div>
    );
}

function PermissionMultiSelect({ options = [], selectedIds = [], onToggle, emptyLabel = 'Nenhum selecionado', renderMeta }) {
    const [open, setOpen] = useState(false);
    const normalizedSelected = (selectedIds || []).map(Number);
    const selectedOptions = options.filter((option) => normalizedSelected.includes(Number(option.id)));

    return (
        <div className="relative">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                className="flex min-h-10 w-full items-center justify-between gap-2 rounded border border-slate-300 bg-white px-3 py-2 text-left text-sm text-[var(--ink-800)] outline-none hover:border-blue-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
            >
                <span className="flex min-w-0 flex-1 flex-wrap gap-1.5">
                    {selectedOptions.length === 0 ? (
                        <span className="text-[var(--ink-400)]">{emptyLabel}</span>
                    ) : (
                        selectedOptions.map((option) => (
                            <span key={option.id} className="inline-flex max-w-full items-center gap-1 rounded bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">
                                <span className="truncate">{option.name}</span>
                                <span
                                    role="button"
                                    tabIndex={0}
                                    className="rounded p-0.5 hover:bg-blue-100"
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        onToggle(option.id);
                                    }}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' || event.key === ' ') {
                                            event.preventDefault();
                                            event.stopPropagation();
                                            onToggle(option.id);
                                        }
                                    }}
                                    title="Remover"
                                >
                                    <X size={12} />
                                </span>
                            </span>
                        ))
                    )}
                </span>
                <ChevronDown size={16} className={`shrink-0 text-[var(--ink-400)] transition ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="absolute left-0 right-0 z-[90] mt-1 max-h-64 overflow-y-auto rounded border border-slate-200 bg-white py-1 shadow-xl">
                    {options.length === 0 ? (
                        <div className="px-3 py-2 text-sm text-[var(--ink-400)]">Nenhuma opção disponível</div>
                    ) : (
                        options.map((option) => {
                            const selected = normalizedSelected.includes(Number(option.id));

                            return (
                                <button
                                    key={option.id}
                                    type="button"
                                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50"
                                    onClick={() => onToggle(option.id)}
                                >
                                    <span className="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded border border-slate-300 bg-white">
                                        {selected && <CheckIcon />}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-semibold text-[var(--ink-800)]">{option.name}</span>
                                        {renderMeta && <span className="block truncate text-xs text-[var(--ink-500)]">{renderMeta(option)}</span>}
                                    </span>
                                </button>
                            );
                        })
                    )}
                </div>
            )}
        </div>
    );
}

function PermissionsSection({ document, users = [], permissionGroups = [] }) {
    const permissions = document.permissions || {};
    const form = useForm({
        owner_user_id: permissions.owner_user_id || document.uploader?.id || '',
        view_user_ids: permissions.view?.user_ids || [],
        view_empresa_ids: permissions.view?.empresa_ids || [],
        edit_user_ids: permissions.edit?.user_ids || [],
        edit_empresa_ids: permissions.edit?.empresa_ids || [],
    });

    function toggle(field, id) {
        const current = form.data[field] || [];
        const exists = current.map(Number).includes(Number(id));
        form.setData(field, exists ? current.filter((value) => Number(value) !== Number(id)) : [...current, id]);
    }

    function submit(event) {
        event.preventDefault();
        form.patch(document.permissions_update_url, { preserveScroll: true });
    }

    return (
        <form id="ged-permissions-form" onSubmit={submit} className="space-y-5">
            <Field label="Proprietário">
                <select className="ged-control" value={form.data.owner_user_id || ''} onChange={(event) => form.setData('owner_user_id', event.target.value)}>
                    <option value="">Sem proprietário</option>
                    {users.map((user) => (
                        <option key={user.id} value={user.id}>
                            {user.name} {user.email ? `(${user.email})` : ''}
                        </option>
                    ))}
                </select>
            </Field>

            <p className="text-xs text-[var(--ink-500)]">
                Documentos sem proprietário podem ser visualizados e editados por usuários autorizados do contrato.
            </p>

            <div className="space-y-3 border-t border-slate-200 pt-4">
                <h3 className="text-base font-bold text-[var(--ink-900)]">Ver</h3>
                <Field label="Usuários">
                    <PermissionMultiSelect
                        options={users}
                        selectedIds={form.data.view_user_ids}
                        onToggle={(id) => toggle('view_user_ids', id)}
                        emptyLabel="Nenhum usuário selecionado"
                        renderMeta={(user) => [user.email, user.empresa_name].filter(Boolean).join(' · ')}
                    />
                </Field>
                <Field label="Grupos">
                    <PermissionMultiSelect
                        options={permissionGroups}
                        selectedIds={form.data.view_empresa_ids}
                        onToggle={(id) => toggle('view_empresa_ids', id)}
                        emptyLabel="Nenhum grupo selecionado"
                        renderMeta={(group) => `${group.users_count || 0} usuário(s) vinculado(s)`}
                    />
                </Field>
            </div>

            <div className="space-y-3 border-t border-slate-200 pt-4">
                <h3 className="text-base font-bold text-[var(--ink-900)]">Editar</h3>
                <Field label="Usuários">
                    <PermissionMultiSelect
                        options={users}
                        selectedIds={form.data.edit_user_ids}
                        onToggle={(id) => toggle('edit_user_ids', id)}
                        emptyLabel="Nenhum usuário selecionado"
                        renderMeta={(user) => [user.email, user.empresa_name].filter(Boolean).join(' · ')}
                    />
                </Field>
                <Field label="Grupos">
                    <PermissionMultiSelect
                        options={permissionGroups}
                        selectedIds={form.data.edit_empresa_ids}
                        onToggle={(id) => toggle('edit_empresa_ids', id)}
                        emptyLabel="Nenhum grupo selecionado"
                        renderMeta={(group) => `${group.users_count || 0} usuário(s) vinculado(s)`}
                    />
                </Field>
                <p className="text-xs text-[var(--ink-500)]">As permissões de edição também concedem permissões de visualização.</p>
            </div>

            <div className="flex justify-end border-t border-slate-200 pt-4">
                <button type="submit" className="sig-btn sig-btn-primary" disabled={form.processing}>
                    <Save size={16} />
                    Salvar permissões
                </button>
            </div>
        </form>
    );
}

function ActiveSection({ activeSection, document, users, permissionGroups, lookups, quickStoreUrls }) {
    if (activeSection === 'content') return <ContentSection document={document} />;
    if (activeSection === 'metadata') return <MetadataSection document={document} />;
    if (activeSection === 'notes') return <NotesSection document={document} />;
    if (activeSection === 'history') return <HistorySection document={document} />;
    if (activeSection === 'permissions') return <PermissionsSection document={document} users={users} permissionGroups={permissionGroups} />;

    return <DetailSection document={document} lookups={lookups} quickStoreUrls={quickStoreUrls} />;
}

function DocumentViewer({ document }) {
    const [page, setPage] = useState(1);
    const [zoom, setZoom] = useState('100');
    const pageCount = document.page_count || 1;

    const viewerUrl = useMemo(() => {
        const safePage = Math.min(Math.max(Number(page) || 1, 1), pageCount);
        return `${document.preview_url}#page=${safePage}&zoom=${zoom}&toolbar=0&navpanes=0`;
    }, [document.preview_url, page, pageCount, zoom]);

    return (
        <div className="flex min-h-[calc(100vh-190px)] flex-col rounded-xl border border-slate-300 bg-white shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-3">
                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex overflow-hidden rounded-lg border border-slate-300 text-sm">
                        <span className="border-r border-slate-300 bg-slate-50 px-3 py-2">Página</span>
                        <input
                            type="number"
                            min="1"
                            max={pageCount}
                            value={page}
                            onChange={(event) => setPage(event.target.value)}
                            className="w-16 border-0 px-2 py-2 outline-none"
                        />
                        <span className="border-l border-slate-300 bg-slate-50 px-3 py-2">de {pageCount}</span>
                    </div>

                    <div className="flex overflow-hidden rounded-lg border border-slate-300 text-sm">
                        <button type="button" className="border-r border-slate-300 px-3 py-2" onClick={() => setZoom((value) => String(Math.max(Number(value) - 10, 50)))}>-</button>
                        <select value={zoom} onChange={(event) => setZoom(event.target.value)} className="border-0 px-3 py-2 outline-none">
                            <option value="75">75%</option>
                            <option value="100">100%</option>
                            <option value="125">125%</option>
                            <option value="150">150%</option>
                            <option value="200">200%</option>
                        </select>
                        <button type="button" className="border-l border-slate-300 px-3 py-2" onClick={() => setZoom((value) => String(Math.min(Number(value) + 10, 200)))}>+</button>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <a href={document.download_url} className="sig-btn sig-btn-secondary !min-h-9 !px-3">
                        <Download size={16} />
                        Baixar
                    </a>
                    <button type="button" className="sig-btn sig-btn-secondary !min-h-9 !px-3">
                        <MoreHorizontal size={16} />
                        Ações
                    </button>
                    <button type="button" className="sig-btn sig-btn-secondary !min-h-9 !px-3">
                        <Send size={16} />
                        Enviar
                    </button>
                </div>
            </div>

            <div className="min-h-0 flex-1 bg-slate-100 p-2">
                <iframe
                    key={viewerUrl}
                    src={viewerUrl}
                    title={document.title}
                    className="h-full min-h-[680px] w-full border-8 border-neutral-500 bg-white"
                />
            </div>
        </div>
    );
}

export default function GedShow({ tenant, document, tabs = [], activeSection = 'details', navigation = {}, users = [], permissionGroups = [], lookups = {}, quickStoreUrls = {} }) {
    const ActiveIcon = sectionIcons[activeSection] || FileText;

    return (
        <AuthenticatedLayout>
            <Head title={`${document.title} - Documentação`} />

            <div className="space-y-4 px-1 pb-8 sm:px-2">
                <div className="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                    <div>
                        <div className="eyebrow">Documentação</div>
                        <h1 className="mt-2 text-2xl font-bold text-[var(--ink-900)]">{document.title}</h1>
                        <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-[var(--ink-600)]">
                            <span className="inline-flex items-center gap-1"><FileText size={15} /> {document.original_filename}</span>
                            <span className="inline-flex items-center gap-1"><Tags size={15} /> {document.type?.name || 'Sem tipo'}</span>
                            <span className="inline-flex items-center gap-1"><UserRound size={15} /> {document.uploader?.name || 'Sem proprietário'}</span>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <button type="button" className="sig-btn sig-btn-danger !min-h-9 !px-3 opacity-70" title="Exclusão será habilitada em uma etapa futura">
                            <Trash2 size={16} />
                            Excluir
                        </button>
                        <a href={document.download_url} className="sig-btn sig-btn-secondary !min-h-9 !px-3">
                            <Download size={16} />
                            Baixar
                        </a>
                    </div>
                </div>

                <div className="grid gap-5 xl:grid-cols-[520px_minmax(0,1fr)]">
                    <div className="rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div className="flex items-center justify-between gap-2 border-b border-slate-200 p-3">
                            <div className="flex items-center gap-2">
                                <Link
                                    href={document.index_url}
                                    className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-[var(--ink-600)] hover:bg-slate-50"
                                    title="Voltar"
                                >
                                    <X size={16} />
                                </Link>
                                <Link
                                    href={navigation.previous_url || '#'}
                                    className={`inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-[var(--ink-600)] hover:bg-slate-50 ${!navigation.previous_url ? 'pointer-events-none opacity-40' : ''}`}
                                    title="Documento anterior"
                                >
                                    <ArrowLeft size={16} />
                                </Link>
                                <Link
                                    href={navigation.next_url || '#'}
                                    className={`inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-[var(--ink-600)] hover:bg-slate-50 ${!navigation.next_url ? 'pointer-events-none opacity-40' : ''}`}
                                    title="Próximo documento"
                                >
                                    <ArrowRight size={16} />
                                </Link>
                            </div>

                            {activeSection === 'details' && (
                                <div className="flex gap-1">
                                    <button type="submit" form="ged-detail-form" className="inline-flex items-center gap-1 rounded-lg bg-emerald-800 px-3 py-2 text-sm font-semibold text-white">
                                        <Save size={15} />
                                        Salvar
                                    </button>
                                </div>
                            )}
                        </div>

                        <div className="border-b border-slate-200 px-3">
                            <nav className="flex gap-5 overflow-x-auto">
                                {tabs.map((tab) => {
                                    const Icon = sectionIcons[tab.key] || FileText;
                                    const active = tab.key === activeSection;

                                    return (
                                        <Link
                                            key={tab.key}
                                            href={tab.url}
                                            className={`inline-flex items-center gap-1.5 border-b-2 px-0 py-3 text-sm font-semibold transition ${
                                                active
                                                    ? 'border-emerald-800 text-emerald-900'
                                                    : 'border-transparent text-[var(--ink-600)] hover:border-slate-300 hover:text-[var(--ink-900)]'
                                            }`}
                                        >
                                            <Icon size={15} />
                                            {tab.label}
                                        </Link>
                                    );
                                })}
                            </nav>
                        </div>

                        <div className="p-4">
                            <div className="mb-4 flex items-center gap-2">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-700">
                                    <ActiveIcon size={18} />
                                </div>
                                <div>
                                    <h2 className="text-lg font-bold text-[var(--ink-900)]">{tabs.find((tab) => tab.key === activeSection)?.label || 'Detalhes'}</h2>
                                    <p className="text-xs text-[var(--ink-500)]">Dados do documento no GED.</p>
                                </div>
                            </div>

                            <ActiveSection activeSection={activeSection} document={document} users={users} permissionGroups={permissionGroups} lookups={lookups} quickStoreUrls={quickStoreUrls} />
                        </div>
                    </div>

                    <DocumentViewer document={document} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
