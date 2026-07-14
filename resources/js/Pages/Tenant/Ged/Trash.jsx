import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GedTour from '@/Components/GedTour';
import { Head, Link, router } from '@inertiajs/react';
import { ArchiveRestore, ArrowLeft, FileText, RotateCcw, Trash2, X } from 'lucide-react';
import { useMemo, useState } from 'react';

function formatDateTime(value) {
    if (!value) return '--';

    return new Date(value).toLocaleString('pt-BR');
}

function remainingDays(deletedAt, trashDelayDays) {
    if (!deletedAt) return null;

    const deletedAtMs = new Date(deletedAt).getTime();
    if (Number.isNaN(deletedAtMs)) return null;

    const elapsedDays = Math.floor((Date.now() - deletedAtMs) / 86400000);

    return Math.max(0, Number(trashDelayDays || 30) - elapsedDays);
}

function DocumentSelectionToggle({ checked, onToggle, className = '' }) {
    return (
        <button
            type="button"
            aria-pressed={checked}
            className={`inline-flex h-5 w-5 items-center justify-center rounded border text-white transition ${checked ? 'border-emerald-700 bg-emerald-700' : 'border-slate-300 bg-white hover:border-emerald-700'} ${className}`}
            onClick={onToggle}
        >
            {checked && <span className="h-2 w-2 rounded-sm bg-white" />}
        </button>
    );
}

function ConfirmModal({ title = 'Confirmar', message, details, confirmLabel, onCancel, onConfirm }) {
    return (
        <div className="fixed inset-0 z-[10000] flex items-center justify-center bg-slate-950/45 p-4">
            <div className="w-full max-w-md overflow-hidden rounded-xl bg-white shadow-2xl">
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h3 className="text-xl font-bold text-[var(--ink-900)]">{title}</h3>
                    <button type="button" onClick={onCancel} className="rounded-lg p-2 text-slate-600 ring-2 ring-blue-200 hover:bg-slate-100">
                        <X size={19} />
                    </button>
                </div>

                <div className="space-y-4 px-5 py-4 text-sm text-[var(--ink-700)]">
                    <p className="font-bold text-[var(--ink-900)]">{message}</p>
                    {details && <p>{details}</p>}
                </div>

                <div className="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onCancel}>Cancelar</button>
                    <button type="button" className="sig-btn bg-rose-500 text-white hover:bg-rose-600" onClick={onConfirm}>
                        {confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}

export default function GedTrash({ tenant, documents, trashDelayDays = 30 }) {
    const [selectedIds, setSelectedIds] = useState([]);
    const [confirmState, setConfirmState] = useState(null);
    const pageDocumentIds = useMemo(() => (documents.data || []).map((document) => document.id), [documents.data]);
    const allPageSelected = pageDocumentIds.length > 0 && pageDocumentIds.every((id) => selectedIds.includes(id));
    const selectedCount = selectedIds.length;
    const hasDocuments = (documents.data || []).length > 0;

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

    function postTrashAction(action, ids = null) {
        const payload = ids && ids.length ? { action, document_ids: ids } : { action };

        router.post(route('tenant.ged.trash.action', tenant.slug), payload, {
            preserveScroll: true,
            onSuccess: () => {
                setSelectedIds([]);
                setConfirmState(null);
            },
        });
    }

    function restoreSelected(ids = selectedIds) {
        if (!ids.length) return;

        postTrashAction('restore', ids);
    }

    function askPermanentDelete(ids = selectedIds) {
        if (!ids.length) return;

        setConfirmState({
            message: `Excluir definitivamente ${ids.length} documento${ids.length === 1 ? '' : 's'}?`,
            details: 'Esta acao nao pode ser desfeita.',
            confirmLabel: 'Excluir definitivamente',
            onConfirm: () => postTrashAction('empty', ids),
        });
    }

    function askEmptyTrash() {
        if (!hasDocuments) return;

        setConfirmState({
            message: 'Esvaziar a lixeira?',
            details: 'Todos os documentos da lixeira serao excluidos definitivamente. Esta acao nao pode ser desfeita.',
            confirmLabel: 'Esvaziar lixeira',
            onConfirm: () => postTrashAction('empty'),
        });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Lixeira da documentação" />

            <div className="space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-8 xl:px-10">
                <div data-tour="ged-trash-overview" className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Link href={route('tenant.ged.index', tenant.slug)} className="inline-flex items-center gap-2 text-sm font-semibold text-emerald-800 hover:underline">
                            <ArrowLeft size={16} />
                            Voltar para documentacao
                        </Link>
                        <h1 className="mt-3 text-2xl font-bold text-[var(--ink-900)]">Lixeira</h1>
                        <p className="mt-1 max-w-3xl text-sm text-[var(--ink-600)]">
                            Documentos movidos para a lixeira podem ser restaurados ou excluidos definitivamente.
                        </p>
                    </div>

                    <div data-tour="ged-trash-actions" className="flex flex-wrap gap-2">
                        <button type="button" className="sig-btn sig-btn-secondary" disabled={!selectedCount} onClick={() => setSelectedIds([])}>
                            <X size={16} />
                            Limpar selecao
                        </button>
                        <button type="button" className="sig-btn border-emerald-700 bg-white text-emerald-800 hover:bg-emerald-50" disabled={!selectedCount} onClick={() => restoreSelected()}>
                            <RotateCcw size={16} />
                            Restaurar os itens selecionados
                        </button>
                        <button type="button" className="sig-btn border-rose-500 bg-white text-rose-600 hover:bg-rose-50" disabled={!selectedCount} onClick={() => askPermanentDelete()}>
                            <Trash2 size={16} />
                            Excluir os itens selecionados
                        </button>
                        <button type="button" className="sig-btn border-rose-500 bg-white text-rose-600 hover:bg-rose-50" disabled={!hasDocuments} onClick={askEmptyTrash}>
                            <Trash2 size={16} />
                            Esvaziar Lixeira
                        </button>
                    </div>
                </div>

                <div data-tour="ged-trash-list" className="overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-[0.12em] text-[var(--ink-500)]">
                                <tr>
                                    <th className="w-10 px-4 py-3">
                                        <DocumentSelectionToggle checked={allPageSelected} onToggle={togglePageSelection} />
                                    </th>
                                    <th className="px-4 py-3">Nome</th>
                                    <th className="px-4 py-3">Restantes</th>
                                    <th className="px-4 py-3">Acoes</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[var(--border)]">
                                {!hasDocuments && (
                                    <tr>
                                        <td colSpan="4" className="px-5 py-10 text-center text-sm text-[var(--ink-500)]">
                                            Nenhum documento na lixeira.
                                        </td>
                                    </tr>
                                )}

                                {(documents.data || []).map((document) => {
                                    const selected = selectedIds.includes(document.id);
                                    const remaining = remainingDays(document.deleted_at, trashDelayDays);

                                    return (
                                        <tr key={document.id} className={`align-middle hover:bg-emerald-50/40 ${selected ? 'bg-emerald-50/60' : ''}`}>
                                            <td className="px-4 py-3">
                                                <DocumentSelectionToggle checked={selected} onToggle={() => toggleDocumentSelection(document.id)} />
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-start gap-3">
                                                    <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-700">
                                                        <FileText size={18} />
                                                    </div>
                                                    <div className="min-w-0">
                                                        <div className="font-semibold text-[var(--ink-900)]">{document.title}</div>
                                                        <div className="mt-1 text-xs text-[var(--ink-500)]">
                                                            {document.original_filename || document.document_number || 'Sem arquivo'} · Excluido em {formatDateTime(document.deleted_at)}
                                                        </div>
                                                        <div className="mt-1 flex flex-wrap gap-1.5 text-xs text-[var(--ink-500)]">
                                                            {document.contract && <span>{document.contract.code} - {document.contract.name}</span>}
                                                            {document.type && <span className="rounded-full bg-cyan-100 px-2 py-0.5 font-bold text-cyan-800">{document.type.name}</span>}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                {remaining === null ? '--' : `${remaining} dia${remaining === 1 ? '' : 's'}`}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap gap-2">
                                                    <button type="button" className="sig-btn sig-btn-secondary !min-h-9 !px-3" onClick={() => restoreSelected([document.id])}>
                                                        <RotateCcw size={15} />
                                                        Restaurar
                                                    </button>
                                                    <button type="button" className="sig-btn border-rose-500 bg-white text-rose-600 hover:bg-rose-50 !min-h-9 !px-3" onClick={() => askPermanentDelete([document.id])}>
                                                        <Trash2 size={15} />
                                                        Excluir
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex flex-col gap-3 border-t border-[var(--border)] px-4 py-3 text-sm text-[var(--ink-600)] sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            {documents.total || 0} documento{Number(documents.total || 0) === 1 ? '' : 's'} na lixeira
                            {selectedCount > 0 && ` (${selectedCount} selecionado${selectedCount === 1 ? '' : 's'})`}
                        </div>

                        {documents.links?.length > 3 && (
                            <div className="flex flex-wrap items-center gap-2">
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
                </div>

                <Link href={route('tenant.ged.index', tenant.slug)} className="sig-btn sig-btn-secondary w-fit">
                    <ArchiveRestore size={16} />
                    Voltar para documentos
                </Link>
            </div>

            {confirmState && (
                <ConfirmModal
                    message={confirmState.message}
                    details={confirmState.details}
                    confirmLabel={confirmState.confirmLabel}
                    onCancel={() => setConfirmState(null)}
                    onConfirm={confirmState.onConfirm}
                />
            )}
            <GedTour tenant={tenant} section="trash" />
        </AuthenticatedLayout>
    );
}
