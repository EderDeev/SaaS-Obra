import { Send } from 'lucide-react';
import { useEffect, useId, useRef } from 'react';

export default function OsDnitConfirmDialog({ open, title, message, onCancel, onConfirm, confirmLabel = 'Confirmar envio', icon: Icon = Send, disabled = false }) {
    const dialog = useRef(null);
    const cancelButton = useRef(null);
    const titleId = useId();
    const messageId = useId();

    useEffect(() => {
        if (!open) return;
        const element = dialog.current;
        const previousFocus = document.activeElement;
        const previousOverflow = document.body.style.overflow;
        element.showModal();
        document.body.style.overflow = 'hidden';
        cancelButton.current?.focus();
        return () => {
            element.close();
            document.body.style.overflow = previousOverflow;
            if (previousFocus?.isConnected) previousFocus.focus();
        };
    }, [open]);

    return <dialog ref={dialog} role="alertdialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={messageId}
        className="os-dnit-confirm fixed inset-0 m-auto w-[calc(100vw-2rem)] max-w-md overflow-hidden rounded-2xl border-0 bg-white p-0 text-[var(--ink-900)] shadow-2xl backdrop:bg-slate-900/40 backdrop:backdrop-blur-sm"
        onCancel={(event) => { event.preventDefault(); event.stopPropagation(); if (!disabled) onCancel(); }}>
        <div className="p-6">
            <span className="mb-4 inline-flex rounded-full bg-blue-50 p-3 text-blue-700"><Icon size={22} /></span>
            <h2 id={titleId} className="text-lg font-semibold">{title}</h2>
            <p id={messageId} className="mt-2 text-sm leading-relaxed text-slate-600">{message}</p>
        </div>
        <footer className="flex flex-wrap justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
            <button ref={cancelButton} type="button" disabled={disabled} onClick={onCancel} className="sig-btn sig-btn-secondary">Cancelar</button>
            <button type="button" disabled={disabled} onClick={onConfirm} className="sig-btn sig-btn-primary"><Icon size={16} />{confirmLabel}</button>
        </footer>
    </dialog>;
}
