import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowDownAZ,
    ArrowUpAZ,
    ArrowUpDown,
    CalendarDays,
    Check,
    ChevronDown,
    Download,
    Eye,
    FileArchive,
    FileCheck2,
    File,
    FileSearch,
    FileText,
    Filter,
    Grid2X2,
    List,
    MessageSquare,
    MoreHorizontal,
    RotateCw,
    Rows3,
    Search,
    Tag,
    UploadCloud,
    UserRound,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const statusLabels = {
    uploaded: 'Enviado',
    processing: 'Processando',
    indexed: 'Indexado',
    failed: 'Falhou',
    archived: 'Arquivado',
};

const statusClasses = {
    uploaded: 'bg-blue-50 text-blue-700',
    processing: 'bg-amber-50 text-amber-700',
    indexed: 'bg-emerald-50 text-emerald-700',
    failed: 'bg-rose-50 text-rose-700',
    archived: 'bg-slate-100 text-slate-700',
};

function formatBytes(bytes = 0) {
    const value = Number(bytes || 0);

    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;

    return `${(value / 1024 / 1024).toFixed(1)} MB`;
}

function formatDate(date) {
    if (!date) return '—';

    return new Date(date).toLocaleDateString('pt-BR');
}

function StatCard({ icon: Icon, label, value, tone = 'blue' }) {
    const tones = {
        blue: 'bg-blue-50 text-blue-700',
        emerald: 'bg-emerald-50 text-emerald-700',
        amber: 'bg-amber-50 text-amber-700',
        slate: 'bg-slate-100 text-slate-700',
    };

    return (
        <div className="rounded-2xl border border-[var(--border)] bg-white p-4 shadow-sm">
            <div className={`mb-3 inline-flex h-10 w-10 items-center justify-center rounded-xl ${tones[tone] || tones.blue}`}>
                <Icon size={20} />
            </div>
            <div className="text-2xl font-bold text-[var(--ink-900)]">{value}</div>
            <div className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--ink-500)]">{label}</div>
        </div>
    );
}

function DocumentPreview({ document, compact = false }) {
    const isPdf = document.mime_type === 'application/pdf' || String(document.extension || '').toLowerCase() === 'pdf';
    const isImage = String(document.mime_type || '').startsWith('image/');

    if (isPdf && document.preview_url) {
        return (
            <iframe
                src={`${document.preview_url}#page=1&toolbar=0&navpanes=0&scrollbar=0&view=FitH`}
                title={document.title}
                className="pointer-events-none h-full w-full rounded-lg border border-slate-200 bg-white"
                loading="lazy"
                tabIndex="-1"
            />
        );
    }

    if (isImage && document.preview_url) {
        return (
            <img
                src={document.preview_url}
                alt={document.title}
                className={`h-full w-full rounded-lg border border-slate-200 object-cover ${compact ? 'object-top' : ''}`}
                loading="lazy"
            />
        );
    }

    return (
        <div className="flex h-full w-full flex-col items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-400">
            <FileText size={compact ? 28 : 42} />
            <span className="mt-2 text-[10px] font-bold uppercase tracking-[0.18em]">{document.extension || 'doc'}</span>
        </div>
    );
}

function DocumentHoverPreview({
    document,
    offsetClass = 'bottom-[72px]',
    hoverClass = 'group-hover:block',
    placement = 'top',
    alignClass = 'left-1/2 -translate-x-1/2',
    visible = false,
}) {
    const isPdf = document.mime_type === 'application/pdf' || String(document.extension || '').toLowerCase() === 'pdf';
    const isImage = String(document.mime_type || '').startsWith('image/');

    if (!document.preview_url || (!isPdf && !isImage)) {
        return null;
    }

    const visibilityClass = visible ? 'block' : `hidden ${hoverClass}`;

    return (
        <div className={`pointer-events-auto absolute ${alignClass} ${offsetClass} z-[9999] w-[min(520px,calc(100vw-32px))] max-w-[520px] rounded-xl border border-slate-200 bg-white shadow-2xl ${visibilityClass}`}>
            <div className="border-b border-slate-200 px-3 py-2 text-sm font-bold text-[var(--ink-900)]">
                {document.title}
            </div>
            <div className="pointer-events-auto h-[360px] overflow-hidden rounded-b-xl bg-white">
                {isPdf ? (
                    <iframe
                        src={`${document.preview_url}#toolbar=0&navpanes=0&view=FitH`}
                        title={`Pré-visualização de ${document.title}`}
                        className="h-full w-full border-0"
                        loading="lazy"
                    />
                ) : (
                    <div className="h-full overflow-auto p-3">
                        <img src={document.preview_url} alt={document.title} className="mx-auto max-w-full rounded border border-slate-200" loading="lazy" />
                    </div>
                )}
            </div>
            <div
                className={`absolute left-1/2 h-4 w-4 -translate-x-1/2 rotate-45 border-slate-200 bg-white ${
                    placement === 'bottom'
                        ? '-top-2 border-l border-t'
                        : 'top-full -translate-y-2 border-b border-r'
                }`}
            />
        </div>
    );
}

function PreviewEyeAction({ document, compact = false, iconOnly = false, previewPlacement = 'top', previewAlignClass = 'left-1/2 -translate-x-1/2' }) {
    const [previewOpen, setPreviewOpen] = useState(false);
    const closeTimer = useRef(null);
    const previewOffsetClass = previewPlacement === 'bottom' ? 'top-[18px]' : 'bottom-[38px]';

    function openPreview() {
        if (closeTimer.current) {
            clearTimeout(closeTimer.current);
        }

        setPreviewOpen(true);
    }

    function scheduleClosePreview() {
        if (closeTimer.current) {
            clearTimeout(closeTimer.current);
        }

        closeTimer.current = setTimeout(() => setPreviewOpen(false), 220);
    }

    return (
        <span
            className={`group/preview relative inline-flex ${compact ? 'flex-1' : ''}`}
            onMouseEnter={openPreview}
            onMouseLeave={scheduleClosePreview}
            onFocus={openPreview}
            onBlur={scheduleClosePreview}
        >
            <DocumentHoverPreview
                document={document}
                offsetClass={previewOffsetClass}
                hoverClass="group-hover/preview:block"
                placement={previewPlacement}
                alignClass={previewAlignClass}
                visible={previewOpen}
            />
            <a
                href={document.preview_url || document.download_url}
                target="_blank"
                rel="noreferrer"
                className={
                    iconOnly
                        ? 'inline-flex text-slate-400 hover:text-blue-600'
                        : compact
                            ? 'inline-flex flex-1 items-center justify-center px-3 py-2 text-slate-600 hover:bg-slate-50'
                            : 'sig-btn sig-btn-ghost !min-h-9 !px-3'
                }
                title="Ver"
            >
                <Eye size={15} />
                {!compact && !iconOnly && <span>Ver</span>}
            </a>
        </span>
    );
}

function DocumentTags({ tags = [], className = '' }) {
    if (!tags.length) return null;

    return (
        <div className={`flex flex-wrap gap-1.5 ${className}`}>
            {tags.map((tag) => (
                <span
                    key={tag.id}
                    className="rounded-full px-2 py-0.5 text-[11px] font-semibold text-white"
                    style={{ backgroundColor: tag.color }}
                >
                    {tag.name}
                </span>
            ))}
        </div>
    );
}

function DocumentNotesLink({ document, tenant, compact = false, className = '' }) {
    const count = Number(document.notes_count || 0);

    if (count <= 0) return null;

    return (
        <Link
            href={route('tenant.ged.notes', [tenant.slug, document.id])}
            className={`inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800 shadow-sm transition hover:border-emerald-400 hover:bg-emerald-100 ${className}`}
            title="Abrir notas do documento"
        >
            <MessageSquare size={compact ? 13 : 14} />
            <span>{count}</span>
            {!compact && <span>{count === 1 ? 'Nota' : 'Notas'}</span>}
        </Link>
    );
}

function ViewButton({ active, icon: Icon, label, onClick }) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            onClick={onClick}
            className={`inline-flex h-10 flex-1 items-center justify-center border-y border-r border-emerald-700 px-3 text-sm transition first:rounded-l-lg first:border-l last:rounded-r-lg ${
                active ? 'bg-emerald-800 text-white shadow-sm' : 'bg-white text-emerald-800 hover:bg-emerald-50'
            }`}
        >
            <Icon size={17} />
        </button>
    );
}

function FilterDropdown({ icon: Icon, label, activeLabel, children }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="relative min-w-0">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                className={`inline-flex min-h-10 w-full min-w-0 items-center justify-between gap-2 rounded border px-3 text-sm font-medium transition sm:w-auto sm:max-w-[230px] ${
                    activeLabel
                        ? 'border-emerald-700 bg-emerald-50 text-emerald-900'
                        : 'border-emerald-700 bg-white text-emerald-800 hover:bg-emerald-50'
                }`}
            >
                <Icon size={16} />
                <span className="truncate whitespace-nowrap">{activeLabel || label}</span>
                <ChevronDown size={14} className={`transition ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="absolute left-0 z-[120] mt-1 w-[min(18rem,calc(100vw-2rem))] rounded-lg border border-slate-200 bg-white p-3 shadow-xl">
                    {children}
                </div>
            )}
        </div>
    );
}

function DropdownSelect({ value, onChange, options = [], placeholder = 'Todos', getLabel = (item) => item.name }) {
    return (
        <select className="ged-control" value={value || ''} onChange={(event) => onChange(event.target.value)}>
            <option value="">{placeholder}</option>
            {options.map((option) => (
                <option key={option.id} value={option.id}>
                    {getLabel(option)}
                </option>
            ))}
        </select>
    );
}

function DocumentSelectionToggle({ checked, onToggle, className = '' }) {
    return (
        <button
            type="button"
            onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                onToggle();
            }}
            className={`inline-flex h-5 w-5 items-center justify-center rounded border transition ${
                checked
                    ? 'border-emerald-800 bg-emerald-800 text-white'
                    : 'border-slate-300 bg-white text-transparent hover:border-emerald-700 hover:text-emerald-700'
            } ${className}`}
            title={checked ? 'Remover seleção' : 'Selecionar documento'}
            aria-pressed={checked}
        >
            <Check size={13} />
        </button>
    );
}

function BulkActionsDropdown({ tenant, selectedIds = [], onRotate }) {
    const [open, setOpen] = useState(false);

    function reprocess() {
        if (!selectedIds.length) return;

        router.post(
            route('tenant.ged.bulk-action', tenant.slug),
            {
                action: 'reprocess',
                document_ids: selectedIds,
            },
            {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
            },
        );
    }

    return (
        <div className="relative">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                className="inline-flex min-h-10 items-center gap-2 rounded-l border border-emerald-700 bg-emerald-800 px-3 text-sm font-medium text-white hover:bg-emerald-900"
            >
                <MoreHorizontal size={16} />
                Ações
                <ChevronDown size={14} />
            </button>

            {open && (
                <div className="absolute left-0 z-[120] mt-1 w-48 rounded-lg border border-slate-200 bg-white py-2 shadow-xl">
                    <button type="button" className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-[var(--ink-800)] hover:bg-slate-50" onClick={reprocess}>
                        <RotateCw size={16} />
                        Reprocessar
                    </button>
                    <button
                        type="button"
                        className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-[var(--ink-800)] hover:bg-slate-50"
                        onClick={() => {
                            setOpen(false);
                            onRotate?.();
                        }}
                    >
                        <RotateCw size={16} />
                        Girar
                    </button>
                    <button type="button" className="flex w-full cursor-not-allowed items-center gap-2 px-3 py-2 text-left text-sm text-slate-400" disabled>
                        <FileArchive size={16} />
                        Juntar
                    </button>
                </div>
            )}
        </div>
    );
}

function BulkDownloadDropdown({ tenant, selectedIds = [] }) {
    const [open, setOpen] = useState(false);
    const [options, setOptions] = useState({
        include_archive: true,
        include_original: false,
        use_formatted_name: false,
    });

    function toggleOption(field) {
        setOptions((state) => ({ ...state, [field]: !state[field] }));
    }

    function downloadSelected() {
        if (!selectedIds.length) return;

        const query = new URLSearchParams({
            ids: selectedIds.join(','),
            include_archive: options.include_archive ? '1' : '0',
            include_original: options.include_original ? '1' : '0',
            use_formatted_name: options.use_formatted_name ? '1' : '0',
        });

        window.location.href = `${route('tenant.ged.bulk-download', tenant.slug)}?${query.toString()}`;
    }

    return (
        <div className="relative inline-flex">
            <button
                type="button"
                onClick={downloadSelected}
                className="inline-flex min-h-10 items-center gap-2 border border-emerald-700 bg-white px-3 text-sm font-medium text-emerald-800 hover:bg-emerald-50"
            >
                <Download size={16} />
                Baixar
            </button>
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                className="inline-flex min-h-10 items-center rounded-r border-y border-r border-emerald-700 bg-emerald-700 px-2 text-white hover:bg-emerald-800"
                title="Opções de download"
            >
                <ChevronDown size={14} />
            </button>

            {open && (
                <div className="absolute right-0 z-[120] mt-11 w-56 rounded-lg border border-slate-200 bg-white p-4 shadow-xl">
                    <div className="mb-2 text-sm text-[var(--ink-800)]">Incluir:</div>
                    <label className="flex cursor-pointer items-start gap-2 py-1 text-sm text-[var(--ink-800)]">
                        <input type="checkbox" className="mt-1 accent-emerald-800" checked={options.include_archive} onChange={() => toggleOption('include_archive')} />
                        <span>Arquivos arquivados</span>
                    </label>
                    <label className="flex cursor-pointer items-start gap-2 py-1 text-sm text-[var(--ink-800)]">
                        <input type="checkbox" className="mt-1 accent-emerald-800" checked={options.include_original} onChange={() => toggleOption('include_original')} />
                        <span>Arquivos originais</span>
                    </label>
                    <label className="flex cursor-pointer items-start gap-2 py-1 text-sm text-[var(--ink-800)]">
                        <input type="checkbox" className="mt-1 accent-emerald-800" checked={options.use_formatted_name} onChange={() => toggleOption('use_formatted_name')} />
                        <span>Usar nome do arquivo formatado</span>
                    </label>
                </div>
            )}
        </div>
    );
}

function RotateDocumentsModal({ tenant, selectedIds = [], selectedDocuments = [], onClose }) {
    const [degrees, setDegrees] = useState(90);
    const previewDocument = selectedDocuments.find((document) => document.preview_url && (document.mime_type === 'application/pdf' || String(document.extension || '').toLowerCase() === 'pdf'));
    const pdfCount = selectedDocuments.filter((document) => document.mime_type === 'application/pdf' || String(document.extension || '').toLowerCase() === 'pdf').length || selectedIds.length;

    function rotateLeft() {
        setDegrees((value) => value - 90);
    }

    function rotateRight() {
        setDegrees((value) => value + 90);
    }

    function normalizedDegrees() {
        const normalized = ((degrees % 360) + 360) % 360;
        return normalized === 270 ? -90 : normalized;
    }

    function submit() {
        router.post(
            route('tenant.ged.bulk-action', tenant.slug),
            {
                action: 'rotate',
                degrees: normalizedDegrees(),
                document_ids: selectedIds,
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
            },
        );
    }

    return (
        <div className="fixed inset-0 z-[10000] flex items-center justify-center bg-slate-950/45 p-4">
            <div className="flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded bg-white shadow-2xl">
                <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h3 className="text-xl font-bold text-[var(--ink-900)]">Confirmar Rotação</h3>
                    <button type="button" className="rounded-lg border border-blue-200 p-1.5 text-slate-700 hover:bg-blue-50" onClick={onClose}>
                        <span className="sr-only">Fechar</span>
                        ×
                    </button>
                </div>

                <div className="relative flex min-h-[480px] flex-1 items-center justify-center overflow-auto bg-white p-6">
                    {previewDocument ? (
                        <div className="relative flex w-full justify-center">
                            <iframe
                                src={`${previewDocument.preview_url}#page=1&toolbar=0&navpanes=0&view=FitH`}
                                title={`Prévia de ${previewDocument.title}`}
                                className="h-[560px] w-[390px] max-w-full origin-center rounded border border-slate-200 bg-white shadow-sm transition"
                                style={{ transform: `rotate(${degrees}deg) scale(${Math.abs(degrees % 180) === 90 ? 0.72 : 1})` }}
                            />
                        </div>
                    ) : (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            Selecione ao menos um PDF para girar.
                        </div>
                    )}

                    <button
                        type="button"
                        onClick={rotateLeft}
                        className="absolute bottom-12 left-20 inline-flex h-10 w-10 items-center justify-center rounded bg-slate-600 text-white shadow hover:bg-slate-700"
                        title="Girar para a esquerda"
                    >
                        <RotateCw size={18} className="-scale-x-100" />
                    </button>
                    <button
                        type="button"
                        onClick={rotateRight}
                        className="absolute bottom-12 right-20 inline-flex h-10 w-10 items-center justify-center rounded bg-slate-600 text-white shadow hover:bg-slate-700"
                        title="Girar para a direita"
                    >
                        <RotateCw size={18} />
                    </button>
                </div>

                <div className="border-t border-slate-200 px-4 py-3 text-sm italic text-slate-500">
                    Note que apenas PDFs serão girados.
                </div>

                <div className="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="text-sm font-semibold text-[var(--ink-800)]">
                        Essa operação irá girar permanentemente a versão original do(s) {pdfCount} documento(s).
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" className="sig-btn sig-btn-ghost" onClick={onClose}>Cancelar</button>
                        <button type="button" className="sig-btn bg-rose-500 text-white hover:bg-rose-600" onClick={submit} disabled={!selectedIds.length || normalizedDegrees() === 0}>
                            Prosseguir
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

const sortOptions = [
    ['nsa', 'NSA'],
    ['correspondent', 'Correspondente'],
    ['title', 'Título'],
    ['type', 'Tipo de Documento'],
    ['created', 'Criado'],
    ['added', 'Adicionado'],
    ['modified', 'Modificado'],
    ['notes', 'Notas'],
    ['owner', 'Proprietário'],
    ['pages', 'Páginas'],
];

function DocumentActions({ document, compact = false, previewPlacement = 'top', previewAlignClass = 'left-1/2 -translate-x-1/2' }) {
    return (
        <div className={`flex ${compact ? 'w-full divide-x divide-slate-200 rounded-lg border border-slate-200' : 'flex-wrap gap-2'}`}>
            <a
                href={document.details_url || document.preview_url || document.download_url}
                className={
                    compact
                        ? 'inline-flex flex-1 items-center justify-center px-3 py-2 text-slate-600 hover:bg-slate-50'
                        : 'sig-btn sig-btn-ghost !min-h-9 !px-3'
                }
                title="Abrir"
            >
                <FileText size={15} />
                {!compact && <span>Abrir</span>}
            </a>
            <PreviewEyeAction document={document} compact={compact} previewPlacement={previewPlacement} previewAlignClass={previewAlignClass} />
            <a
                href={document.download_url}
                className={
                    compact
                        ? 'inline-flex flex-1 items-center justify-center px-3 py-2 text-slate-600 hover:bg-slate-50'
                        : 'sig-btn sig-btn-secondary !min-h-9 !px-3'
                }
                title="Baixar"
            >
                <Download size={15} />
                {!compact && <span>Baixar</span>}
            </a>
        </div>
    );
}

function EmptyDocuments() {
    return (
        <div className="px-5 py-10 text-center text-sm text-[var(--ink-500)]">
            Nenhum documento encontrado. Envie o primeiro arquivo para iniciar o GED.
        </div>
    );
}

export default function GedIndex({ tenant, documents, filters = {}, contracts = [], types = [], tags = [], correspondents = [], filterCorrespondents = [], stats = {} }) {
    const [showUpload, setShowUpload] = useState(false);
    const [showRotateModal, setShowRotateModal] = useState(false);
    const [viewMode, setViewMode] = useState(() => (typeof window === 'undefined' ? 'table' : window.localStorage.getItem('ged:viewMode') || 'table'));
    const [selectedIds, setSelectedIds] = useState([]);
    const [filterState, setFilterState] = useState({
        q: filters.q || '',
        status: filters.status || '',
        type_id: filters.type_id || '',
        tag_id: filters.tag_id || '',
        correspondent_id: filters.correspondent_id || '',
        contract_id: filters.contract_id || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        sort: filters.sort || 'added',
        direction: filters.direction || 'desc',
    });

    const uploadForm = useForm({
        file: null,
        title: '',
        document_date: '',
        description: '',
        contract_id: '',
        document_type_id: '',
        correspondent_empresa_id: '',
        tag_ids: [],
    });

    const uploadContractId = uploadForm.data.contract_id;
    const filteredTypes = useMemo(() => types.filter((type) => uploadContractId && String(type.contract_id) === String(uploadContractId)), [types, uploadContractId]);
    const filteredTags = useMemo(() => tags.filter((tag) => uploadContractId && String(tag.contract_id) === String(uploadContractId)), [tags, uploadContractId]);
    const filteredCorrespondents = useMemo(() => correspondents.filter((empresa) => uploadContractId && String(empresa.contract_id) === String(uploadContractId)), [correspondents, uploadContractId]);
    const filterContractId = filterState.contract_id;
    const filterTypes = useMemo(() => types.filter((type) => !filterContractId || String(type.contract_id) === String(filterContractId)), [types, filterContractId]);
    const filterTags = useMemo(() => tags.filter((tag) => !filterContractId || String(tag.contract_id) === String(filterContractId)), [tags, filterContractId]);
    const filteredFilterCorrespondents = useMemo(() => filterCorrespondents.filter((correspondent) => !filterContractId || String(correspondent.contract_id) === String(filterContractId)), [filterCorrespondents, filterContractId]);

    function applyFilters(event) {
        event.preventDefault();

        router.get(route('tenant.ged.index', tenant.slug), filterState, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function applyListingState(nextState) {
        setFilterState(nextState);
        router.get(route('tenant.ged.index', tenant.slug), nextState, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function resetFilters() {
        const clean = { q: '', status: '', type_id: '', tag_id: '', correspondent_id: '', contract_id: '', date_from: '', date_to: '', sort: 'added', direction: 'desc' };
        setFilterState(clean);
        router.get(route('tenant.ged.index', tenant.slug), clean, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function submitUpload(event) {
        event.preventDefault();

        uploadForm.post(route('tenant.ged.store', tenant.slug), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                uploadForm.reset();
                setShowUpload(false);
            },
        });
    }

    function toggleTag(tagId) {
        const current = uploadForm.data.tag_ids || [];
        const exists = current.includes(tagId);
        uploadForm.setData('tag_ids', exists ? current.filter((id) => id !== tagId) : [...current, tagId]);
    }

    function changeViewMode(mode) {
        setViewMode(mode);
        if (typeof window !== 'undefined') {
            window.localStorage.setItem('ged:viewMode', mode);
        }
    }

    const selectedTag = tags.find((tag) => String(tag.id) === String(filterState.tag_id));
    const selectedType = types.find((type) => String(type.id) === String(filterState.type_id));
    const selectedCorrespondent = filterCorrespondents.find((correspondent) => String(correspondent.id) === String(filterState.correspondent_id));
    const selectedContract = contracts.find((contract) => String(contract.id) === String(filterState.contract_id));
    const selectedSortLabel = sortOptions.find(([value]) => value === filterState.sort)?.[1] || 'Adicionado';
    const hasDateFilter = Boolean(filterState.date_from || filterState.date_to);
    const pageDocumentIds = (documents.data || []).map((document) => document.id);
    const selectedDocuments = (documents.data || []).filter((document) => selectedIds.includes(document.id));
    const allPageSelected = pageDocumentIds.length > 0 && pageDocumentIds.every((id) => selectedIds.includes(id));

    function toggleDocumentSelection(documentId) {
        setSelectedIds((current) => current.includes(documentId) ? current.filter((id) => id !== documentId) : [...current, documentId]);
    }

    function togglePageSelection() {
        setSelectedIds((current) => {
            if (allPageSelected) {
                return current.filter((id) => !pageDocumentIds.includes(id));
            }

            return Array.from(new Set([...current, ...pageDocumentIds]));
        });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Documentação" />

            <div className="space-y-6 px-3 pb-8 sm:px-5">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-[var(--ink-900)]">Documentação</h1>
                        <p className="mt-1 max-w-3xl text-sm text-[var(--ink-600)]">
                            Controle documentos por contrato, tipo, etiquetas e empresa.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-3 pt-1 lg:pr-2">
                        <Link href={route('tenant.ged.settings', tenant.slug)} className="sig-btn sig-btn-secondary">
                            <FileSearch size={17} />
                            Parametrização
                        </Link>
                        <button type="button" className="sig-btn sig-btn-primary" onClick={() => setShowUpload((open) => !open)}>
                            <UploadCloud size={17} />
                            Enviar documento
                        </button>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <StatCard icon={FileText} label="Documentos" value={stats.total || 0} />
                    <StatCard icon={UploadCloud} label="Enviados" value={stats.uploaded || 0} tone="slate" />
                    <StatCard icon={FileCheck2} label="Indexados" value={stats.indexed || 0} tone="emerald" />
                    <StatCard icon={FileArchive} label="Em processamento" value={stats.processing || 0} tone="amber" />
                </div>

                {showUpload && (
                    <div className="rounded-2xl border border-blue-100 bg-white p-6 shadow-sm">
                        <div className="mb-4 flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                <UploadCloud size={20} />
                            </div>
                            <div>
                                <h2 className="font-semibold text-[var(--ink-900)]">Enviar novo documento</h2>
                                <p className="text-sm text-[var(--ink-500)]">O arquivo será salvo no storage do sistema e preparado para OCR/indexação.</p>
                            </div>
                        </div>

                        <form onSubmit={submitUpload} className="grid gap-4 lg:grid-cols-12">
                            <div className="lg:col-span-5">
                                <label className="ged-label">Arquivo</label>
                                <input
                                    type="file"
                                    className="ged-control"
                                    onChange={(event) => uploadForm.setData('file', event.target.files?.[0] || null)}
                                />
                                {uploadForm.errors.file && <p className="mt-1 text-xs text-rose-600">{uploadForm.errors.file}</p>}
                            </div>

                            <div className="lg:col-span-4">
                                <label className="ged-label">Título</label>
                                <input className="ged-control" value={uploadForm.data.title} onChange={(event) => uploadForm.setData('title', event.target.value)} placeholder="Opcional, usa o nome do arquivo se vazio" />
                            </div>

                            <div className="lg:col-span-3">
                                <label className="ged-label">Data do documento</label>
                                <input type="date" className="ged-control" value={uploadForm.data.document_date} onChange={(event) => uploadForm.setData('document_date', event.target.value)} />
                            </div>
                            <div className="lg:col-span-3">
                                <label className="ged-label">Contrato</label>
                                <select className="ged-control" required value={uploadForm.data.contract_id} onChange={(event) => uploadForm.setData({ ...uploadForm.data, contract_id: event.target.value, document_type_id: '', correspondent_empresa_id: '', tag_ids: [] })}>
                                    <option value="">Selecione um contrato</option>
                                    {contracts.map((contract) => (
                                        <option key={contract.id} value={contract.id}>{contract.code} - {contract.name}</option>
                                    ))}
                                </select>
                                {uploadForm.errors.contract_id && <p className="mt-1 text-xs text-rose-600">{uploadForm.errors.contract_id}</p>}
                            </div>

                            <div className="lg:col-span-3">
                                <label className="ged-label">Tipo documental</label>
                                <select className="ged-control" value={uploadForm.data.document_type_id} onChange={(event) => uploadForm.setData('document_type_id', event.target.value)}>
                                    <option value="">Não classificado</option>
                                    {filteredTypes.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                                </select>
                            </div>

                            <div className="lg:col-span-4">
                                <label className="ged-label">Empresa / Correspondente</label>
                                <select className="ged-control" value={uploadForm.data.correspondent_empresa_id} onChange={(event) => uploadForm.setData('correspondent_empresa_id', event.target.value)}>
                                    <option value="">Não informado</option>
                                    {filteredCorrespondents.map((empresa) => (
                                        <option key={empresa.id} value={empresa.id}>{empresa.sigla ? `${empresa.sigla} - ` : ''}{empresa.nome}</option>
                                    ))}
                                </select>
                            </div>

                            <div className="lg:col-span-8">
                                <label className="ged-label">Descrição</label>
                                <input className="ged-control" value={uploadForm.data.description} onChange={(event) => uploadForm.setData('description', event.target.value)} />
                            </div>

                            <div className="lg:col-span-12">
                                <label className="ged-label">Etiquetas</label>
                                <div className="flex flex-wrap gap-2">
                                    {filteredTags.length === 0 && <span className="text-sm text-[var(--ink-500)]">Selecione um contrato com etiquetas cadastradas para classificar documentos.</span>}
                                    {filteredTags.map((tag) => {
                                        const checked = uploadForm.data.tag_ids.includes(tag.id);
                                        return (
                                            <button
                                                type="button"
                                                key={tag.id}
                                                className={`rounded-full border px-3 py-1 text-xs font-semibold ${checked ? 'border-blue-300 bg-blue-50 text-blue-700' : 'border-[var(--border)] bg-white text-[var(--ink-600)]'}`}
                                                onClick={() => toggleTag(tag.id)}
                                            >
                                                <span className="mr-1 inline-block h-2 w-2 rounded-full" style={{ backgroundColor: tag.color }} />
                                                {tag.name}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            <div className="flex justify-end gap-2 lg:col-span-12">
                                <button type="button" className="sig-btn sig-btn-ghost" onClick={() => setShowUpload(false)}>Cancelar</button>
                                <button type="submit" className="sig-btn sig-btn-primary" disabled={uploadForm.processing}>
                                    <UploadCloud size={16} />
                                    Salvar no GED
                                </button>
                            </div>
                        </form>
                    </div>
                )}


                <div className="rounded-2xl border border-[var(--border)] bg-white shadow-sm">
                    <form onSubmit={applyFilters} className="space-y-4 border-b border-[var(--border)] p-4 sm:p-5">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-end">
                            <div className="w-full lg:w-[420px] lg:flex-none">
                                <label className="ged-label">Buscar</label>
                                <div className="relative">
                                    <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--ink-400)]" size={16} />
                                    <input className="ged-control ged-control-with-icon" value={filterState.q} onChange={(event) => setFilterState((state) => ({ ...state, q: event.target.value }))} placeholder="Título, código, arquivo..." />
                                </div>
                            </div>

                            <div className="flex w-full flex-wrap items-end gap-2 lg:flex-1 lg:pl-4 xl:pl-8 [&>div]:w-full sm:[&>div]:w-auto">
                                <FilterDropdown icon={FileCheck2} label="Contrato" activeLabel={selectedContract ? `${selectedContract.code} - ${selectedContract.name}` : null}>
                                    <label className="ged-label">Contrato</label>
                                    <DropdownSelect
                                        value={filterState.contract_id}
                                        options={contracts}
                                        placeholder="Todos"
                                        getLabel={(contract) => `${contract.code} - ${contract.name}`}
                                        onChange={(value) => setFilterState((state) => ({
                                            ...state,
                                            contract_id: value,
                                            type_id: '',
                                            tag_id: '',
                                            correspondent_id: '',
                                        }))}
                                    />
                                </FilterDropdown>

                                <FilterDropdown icon={Tag} label="Etiquetas" activeLabel={selectedTag?.name}>
                                    <label className="ged-label">Etiqueta</label>
                                    <DropdownSelect value={filterState.tag_id} options={filterTags} placeholder="Todas" onChange={(value) => setFilterState((state) => ({ ...state, tag_id: value }))} />
                                </FilterDropdown>

                                <FilterDropdown icon={UserRound} label="Correspondente" activeLabel={selectedCorrespondent?.name}>
                                    <label className="ged-label">Correspondente</label>
                                    <DropdownSelect value={filterState.correspondent_id} options={filteredFilterCorrespondents} placeholder="Todos" onChange={(value) => setFilterState((state) => ({ ...state, correspondent_id: value }))} />
                                </FilterDropdown>

                                <FilterDropdown icon={FileText} label="Tipo de Documento" activeLabel={selectedType?.name}>
                                    <label className="ged-label">Tipo de Documento</label>
                                    <DropdownSelect value={filterState.type_id} options={filterTypes} placeholder="Todos" onChange={(value) => setFilterState((state) => ({ ...state, type_id: value }))} />
                                </FilterDropdown>

                                <FilterDropdown icon={CalendarDays} label="Datas" activeLabel={hasDateFilter ? 'Datas' : null}>
                                    <div className="grid gap-3">
                                        <div>
                                            <label className="ged-label">Criado de</label>
                                            <input type="date" className="ged-control" value={filterState.date_from} onChange={(event) => setFilterState((state) => ({ ...state, date_from: event.target.value }))} />
                                        </div>
                                        <div>
                                            <label className="ged-label">Criado até</label>
                                            <input type="date" className="ged-control" value={filterState.date_to} onChange={(event) => setFilterState((state) => ({ ...state, date_to: event.target.value }))} />
                                        </div>
                                    </div>
                                </FilterDropdown>

                                <button className="sig-btn sig-btn-primary w-full sm:w-auto sm:flex-none">
                                    <Filter size={16} />
                                    Filtrar
                                </button>
                                <button type="button" className="sig-btn sig-btn-ghost w-full sm:w-auto sm:flex-none" onClick={resetFilters}>Limpar</button>

                                <div className="btn-group flex-fill flex w-full sm:w-[170px] sm:flex-none">
                                    <ViewButton active={viewMode === 'table'} icon={List} label="Tabela" onClick={() => changeViewMode('table')} />
                                    <ViewButton active={viewMode === 'grid'} icon={Grid2X2} label="Grade" onClick={() => changeViewMode('grid')} />
                                    <ViewButton active={viewMode === 'detail'} icon={Rows3} label="Detalhado" onClick={() => changeViewMode('detail')} />
                                </div>

                                <div className="w-full sm:w-auto sm:flex-none">
                                    <FilterDropdown icon={ArrowUpDown} label="Ordenar" activeLabel={`Ordenar: ${selectedSortLabel}`}>
                                        <div className="space-y-3">
                                            <div className="grid grid-cols-2 gap-2">
                                                <button
                                                    type="button"
                                                    className={`inline-flex min-h-9 items-center justify-center rounded border px-3 text-sm font-semibold ${filterState.direction === 'asc' ? 'border-emerald-700 bg-emerald-800 text-white' : 'border-emerald-700 bg-white text-emerald-800'}`}
                                                    onClick={() => applyListingState({ ...filterState, direction: 'asc' })}
                                                >
                                                    <ArrowDownAZ size={16} />
                                                </button>
                                                <button
                                                    type="button"
                                                    className={`inline-flex min-h-9 items-center justify-center rounded border px-3 text-sm font-semibold ${filterState.direction === 'desc' ? 'border-emerald-700 bg-emerald-800 text-white' : 'border-emerald-700 bg-white text-emerald-800'}`}
                                                    onClick={() => applyListingState({ ...filterState, direction: 'desc' })}
                                                >
                                                    <ArrowUpAZ size={16} />
                                                </button>
                                            </div>

                                            <div className="-mx-3 max-h-80 overflow-y-auto">
                                                {sortOptions.map(([value, label]) => (
                                                    <button
                                                        key={value}
                                                        type="button"
                                                        className={`flex w-full px-3 py-2 text-left text-sm hover:bg-emerald-50 ${filterState.sort === value ? 'bg-emerald-800 font-semibold text-white hover:bg-emerald-800' : 'text-[var(--ink-800)]'}`}
                                                        onClick={() => applyListingState({ ...filterState, sort: value })}
                                                    >
                                                        {label}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    </FilterDropdown>
                                </div>

                                {selectedIds.length > 0 && (
                                    <div className="inline-flex">
                                        <BulkActionsDropdown tenant={tenant} selectedIds={selectedIds} onRotate={() => setShowRotateModal(true)} />
                                        <BulkDownloadDropdown tenant={tenant} selectedIds={selectedIds} />
                                    </div>
                                )}
                            </div>
                        </div>
                    </form>

                    {documents.data.length === 0 && <EmptyDocuments />}

                    {documents.data.length > 0 && viewMode === 'table' && (
                        <div className="overflow-visible">
                            <table className="min-w-full text-sm">
                                <thead className="bg-slate-50 text-left text-xs uppercase tracking-[0.12em] text-[var(--ink-500)]">
                                    <tr>
                                        <th className="w-10 px-4 py-3">
                                            <DocumentSelectionToggle checked={allPageSelected} onToggle={togglePageSelection} />
                                        </th>
                                        <th className="px-4 py-3">Correspondente</th>
                                        <th className="px-4 py-3">Título</th>
                                        <th className="px-4 py-3">Tipo</th>
                                        <th className="px-4 py-3">Proprietário</th>
                                        <th className="px-4 py-3">Notas</th>
                                        <th className="px-4 py-3">Criado</th>
                                        <th className="px-4 py-3">Páginas</th>
                                        <th className="px-4 py-3">Compartilhado</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[var(--border)]">
                                    {documents.data.map((document) => {
                                        const selected = selectedIds.includes(document.id);

                                        return (
                                        <tr key={document.id} className={`align-middle hover:bg-emerald-50/40 ${selected ? 'bg-emerald-50/60' : ''}`}>
                                            <td className="px-4 py-3">
                                                <DocumentSelectionToggle checked={selected} onToggle={() => toggleDocumentSelection(document.id)} />
                                            </td>
                                            <td className="px-4 py-3">{document.correspondent?.name || '—'}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <Link
                                                        href={route('tenant.ged.details', [tenant.slug, document.id])}
                                                        className="font-semibold text-emerald-800 underline-offset-2 hover:underline"
                                                    >
                                                        {document.title}
                                                    </Link>
                                                    <PreviewEyeAction document={document} iconOnly previewPlacement="top" />
                                                    <DocumentTags tags={document.tags} />
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="rounded-full bg-cyan-100 px-2.5 py-1 text-xs font-bold text-cyan-800">{document.type?.name || 'Sem tipo'}</span>
                                            </td>
                                            <td className="px-4 py-3">{document.uploader || '—'}</td>
                                            <td className="px-4 py-3">
                                                <DocumentNotesLink document={document} tenant={tenant} compact />
                                            </td>
                                            <td className="px-4 py-3">{formatDate(document.created_at)}</td>
                                            <td className="px-4 py-3">{document.page_count || '—'}</td>
                                            <td className="px-4 py-3">Não</td>
                                        </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {documents.data.length > 0 && viewMode === 'grid' && (
                        <div className="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                            {documents.data.map((document) => {
                                const selected = selectedIds.includes(document.id);

                                return (
                                <article key={document.id} className={`relative rounded-xl border bg-white shadow-sm transition hover:z-50 hover:-translate-y-0.5 hover:shadow-md ${selected ? 'border-emerald-800 ring-2 ring-emerald-100' : 'border-emerald-700/80'}`}>
                                    <div className="relative h-44 overflow-hidden rounded-t-xl bg-slate-100 p-2">
                                        <DocumentPreview document={document} compact />
                                        <DocumentSelectionToggle checked={selected} onToggle={() => toggleDocumentSelection(document.id)} className="absolute left-3 top-3" />
                                        <div className="absolute right-3 top-3 flex flex-col items-end gap-1">
                                            <span className="rounded-md bg-cyan-100 px-2 py-1 text-xs font-bold text-cyan-800">
                                                {document.type?.name || 'Sem tipo'}
                                            </span>
                                            <DocumentTags tags={document.tags} className="flex-col items-end" />
                                        </div>
                                        <DocumentNotesLink document={document} tenant={tenant} compact className="absolute bottom-3 right-3" />
                                    </div>
                                    <div className="space-y-3 p-3">
                                        <div>
                                            <h3 className="line-clamp-2 min-h-[44px] text-sm font-semibold text-[var(--ink-900)]">{document.title}</h3>
                                            <p className="mt-1 text-xs text-[var(--ink-500)]">{document.document_number || 'Sem código'}</p>
                                        </div>

                                        <div className="space-y-2 text-xs text-[var(--ink-600)]">
                                            <div className="flex items-center gap-2">
                                                <CalendarDays size={14} />
                                                <span>{formatDate(document.document_date || document.created_at)}</span>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <File size={14} />
                                                <span>{document.page_count || '—'} página(s)</span>
                                            </div>
                                        </div>

                                        <DocumentActions document={document} compact />
                                    </div>
                                </article>
                                );
                            })}
                        </div>
                    )}

                    {documents.data.length > 0 && viewMode === 'detail' && (
                        <div className="space-y-4 p-5">
                            {documents.data.map((document) => {
                                const selected = selectedIds.includes(document.id);

                                return (
                                <article key={document.id} className={`relative overflow-visible rounded-xl border bg-white p-3 shadow-sm hover:z-50 ${selected ? 'border-emerald-800 bg-emerald-50/30 ring-2 ring-emerald-100' : 'border-emerald-700/70'}`}>
                                    <div className="grid gap-4 md:grid-cols-[240px_1fr]">
                                        <div className="relative h-44 overflow-hidden rounded-lg bg-slate-100">
                                            <DocumentPreview document={document} compact />
                                            <DocumentSelectionToggle checked={selected} onToggle={() => toggleDocumentSelection(document.id)} className="absolute left-2 top-2" />
                                        </div>
                                        <div className="flex min-w-0 flex-col justify-between gap-3">
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h3 className="text-lg font-bold text-[var(--ink-900)]">{document.title}</h3>
                                                    <span className="rounded-md bg-cyan-100 px-2 py-1 text-xs font-bold text-cyan-800">{document.type?.name || 'Sem tipo'}</span>
                                                    <DocumentTags tags={document.tags} />
                                                </div>
                                                <p className="mt-2 line-clamp-3 text-sm text-[var(--ink-700)]">
                                                    {document.description || document.original_filename}
                                                </p>
                                                <div className="mt-4">
                                                    <DocumentActions document={document} previewAlignClass="left-0 translate-x-0" />
                                                </div>
                                            </div>

                                            <div className="flex justify-end">
                                                <div className="flex flex-wrap justify-end gap-x-4 gap-y-2 text-xs text-[var(--ink-600)]">
                                                    <DocumentNotesLink document={document} tenant={tenant} />
                                                    <span className="inline-flex items-center gap-1"><CalendarDays size={14} /> {formatDate(document.document_date || document.created_at)}</span>
                                                    <span className="inline-flex items-center gap-1"><File size={14} /> {document.page_count || '—'} página(s)</span>
                                                    <span>{document.correspondent?.name || 'Sem correspondente'}</span>
                                                    <span>{document.contract?.code || 'Sem contrato'}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                                );
                            })}
                        </div>
                    )}

                    <div className="hidden">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-[0.16em] text-[var(--ink-500)]">
                                <tr>
                                    <th className="px-5 py-3">Documento</th>
                                    <th className="px-5 py-3">Classificação</th>
                                    <th className="px-5 py-3">Contrato</th>
                                    <th className="px-5 py-3">Status</th>
                                    <th className="px-5 py-3">Arquivo</th>
                                    <th className="px-5 py-3 text-right">Ação</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[var(--border)]">
                                {documents.data.length === 0 && (
                                    <tr>
                                        <td colSpan="6" className="px-5 py-10 text-center text-[var(--ink-500)]">
                                            Nenhum documento encontrado. Envie o primeiro arquivo para iniciar o GED.
                                        </td>
                                    </tr>
                                )}

                                {documents.data.map((document) => (
                                    <tr key={document.id} className="align-top hover:bg-slate-50/70">
                                        <td className="px-5 py-4">
                                            <div className="font-semibold text-[var(--ink-900)]">{document.title}</div>
                                            <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                {document.document_number || 'Sem código'} · {formatDate(document.document_date)}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-1">
                                                {document.tags.map((tag) => (
                                                    <span key={tag.id} className="rounded-full px-2 py-0.5 text-[11px] font-semibold text-white" style={{ backgroundColor: tag.color }}>
                                                        {tag.name}
                                                    </span>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-5 py-4">
                                            <div>{document.type?.name || 'Não classificado'}</div>
                                            <div className="mt-1 text-xs text-[var(--ink-500)]">{document.correspondent?.name || 'Sem correspondente'}</div>
                                        </td>
                                        <td className="px-5 py-4">
                                            <div>{document.contract ? `${document.contract.code} - ${document.contract.name}` : 'Sem contrato'}</div>
                                        </td>
                                        <td className="px-5 py-4">
                                            <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClasses[document.status] || statusClasses.uploaded}`}>
                                                {statusLabels[document.status] || document.status}
                                            </span>
                                        </td>
                                        <td className="px-5 py-4">
                                            <div className="max-w-[220px] truncate font-medium">{document.original_filename}</div>
                                            <div className="mt-1 text-xs text-[var(--ink-500)]">{formatBytes(document.size_bytes)}</div>
                                        </td>
                                        <td className="px-5 py-4 text-right">
                                            <a href={document.download_url} className="sig-btn sig-btn-secondary !min-h-9 !px-3">
                                                <Download size={15} />
                                                Baixar
                                            </a>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {documents.links?.length > 3 && (
                        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-[var(--border)] p-4">
                            {documents.links.map((link, index) => (
                                <Link
                                    key={`${link.label}-${index}`}
                                    href={link.url || '#'}
                                    preserveScroll
                                    className={`rounded-lg border px-3 py-1.5 text-sm ${link.active ? 'border-blue-600 bg-blue-600 text-white' : 'border-[var(--border)] bg-white text-[var(--ink-700)]'} ${!link.url ? 'pointer-events-none opacity-40' : ''}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </div>

                {showRotateModal && (
                    <RotateDocumentsModal
                        tenant={tenant}
                        selectedIds={selectedIds}
                        selectedDocuments={selectedDocuments}
                        onClose={() => setShowRotateModal(false)}
                    />
                )}
            </div>
        </AuthenticatedLayout>
    );
}

