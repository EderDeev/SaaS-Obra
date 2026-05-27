import { AlertTriangle, X } from 'lucide-react';
import { useEffect, useState } from 'react';

export default function ConfirmActionButton({
    children,
    title = 'Confirmar exclusao',
    message = 'Deseja mesmo excluir este registro? Esta acao nao deve ser feita por engano.',
    confirmLabel = 'Excluir',
    cancelLabel = 'Cancelar',
    className = 'sig-btn sig-btn-secondary sig-btn-sm text-[var(--red)]',
    disabled = false,
    onConfirm,
}) {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const closeOnEscape = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('keydown', closeOnEscape);

        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [open]);

    const confirm = () => {
        setOpen(false);
        onConfirm?.();
    };

    return (
        <>
            <button
                type="button"
                className={className}
                disabled={disabled}
                onClick={() => setOpen(true)}
            >
                {children}
            </button>

            {open && (
                <div
                    className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.48)] px-4 py-6"
                    role="presentation"
                    onMouseDown={() => setOpen(false)}
                >
                    <section
                        className="w-full max-w-md overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="confirm-action-title"
                        onMouseDown={(event) => event.stopPropagation()}
                    >
                        <header className="flex items-start gap-4 border-b border-[var(--border)] px-5 py-4">
                            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[var(--red-50)] text-[var(--red)]">
                                <AlertTriangle size={21} />
                            </span>
                            <div className="min-w-0 flex-1">
                                <h2 id="confirm-action-title" className="text-[16px] font-semibold text-[var(--ink-900)]">
                                    {title}
                                </h2>
                                <p className="mt-1 text-[13px] leading-5 text-[var(--ink-500)]">
                                    {message}
                                </p>
                            </div>
                            <button
                                type="button"
                                className="sig-btn sig-btn-ghost !min-h-9 !px-2"
                                title="Fechar"
                                onClick={() => setOpen(false)}
                            >
                                <X size={17} />
                            </button>
                        </header>

                        <footer className="flex flex-wrap justify-end gap-2 bg-[var(--surface-muted)] px-5 py-4">
                            <button
                                type="button"
                                className="sig-btn sig-btn-secondary"
                                onClick={() => setOpen(false)}
                            >
                                {cancelLabel}
                            </button>
                            <button
                                type="button"
                                className="sig-btn sig-btn-primary bg-[var(--red)] hover:bg-[var(--red)]"
                                onClick={confirm}
                            >
                                {confirmLabel}
                            </button>
                        </footer>
                    </section>
                </div>
            )}
        </>
    );
}
