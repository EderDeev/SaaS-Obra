import { ClipboardList, X } from 'lucide-react';

function formatDateTime(value) {
    if (!value) {
        return 'Data nao registrada';
    }

    return new Date(value).toLocaleString('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
}

function personName(person) {
    return person?.name || person?.email || 'Nao informado';
}

function InfoBlock({ label, value, mono = false }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-white p-3">
            <div className="eyebrow">{label}</div>
            <div className={`mt-1 text-sm font-semibold text-[var(--ink-900)] ${mono ? 'mono break-all' : ''}`}>
                {value || 'Nao informado'}
            </div>
        </div>
    );
}

function NarrativeBlock({ label, value }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4">
            <span className="eyebrow">{label}</span>
            <p className="mt-2 whitespace-pre-line text-sm leading-6 text-[var(--ink-700)]">
                {value || 'Nao informado'}
            </p>
        </div>
    );
}

export default function ProjectCapModal({ document, version, capImpactLabels = {}, onClose }) {
    const capVersion = version || document?.latest_version || {};
    const impacts = Array.isArray(capVersion.cap_impacts) ? capVersion.cap_impacts : [];
    const analysisPerson = capVersion.reviewer || document?.reviewer;
    const approvalPerson = capVersion.approver || document?.approver;

    return (
        <div
            className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.48)] px-4 py-6"
            role="presentation"
            onMouseDown={onClose}
        >
            <section
                className="max-h-[92vh] w-full max-w-4xl overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="project-cap-title"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <ClipboardList size={15} />
                            <span className="eyebrow">Controle e Alteracao de Projetos</span>
                        </div>
                        <h2 id="project-cap-title" className="mt-1 truncate text-[17px] font-semibold text-[var(--ink-900)]">
                            {capVersion.cap_number || 'CAP sem numero'}
                        </h2>
                        <p className="mt-1 text-[12.5px] text-[var(--ink-500)]">
                            {document?.code || 'Sem EAP'} - {capVersion.revision || 'Sem revisao'}
                        </p>
                    </div>
                    <button type="button" className="sig-btn sig-btn-ghost !min-h-9 !px-2" title="Fechar" onClick={onClose}>
                        <X size={18} />
                    </button>
                </header>

                <div className="max-h-[calc(92vh-130px)] overflow-y-auto px-5 py-5">
                    <div className="grid gap-3 sm:grid-cols-4">
                        <InfoBlock label="Numero CAP" value={capVersion.cap_number} />
                        <InfoBlock label="Projeto - EAP" value={document?.code} mono />
                        <InfoBlock label="Responsavel solicitacao" value={personName(capVersion.cap_requester || capVersion.uploader)} />
                        <InfoBlock label="Data" value={formatDateTime(capVersion.cap_requested_at || capVersion.created_at)} />
                    </div>

                    <div className="mt-4 grid gap-3 md:grid-cols-2">
                        <NarrativeBlock label="Motivo da alteracao" value={capVersion.cap_reason} />
                        <NarrativeBlock label="Descricao da alteracao" value={capVersion.cap_description || capVersion.revision_change_summary} />
                    </div>

                    <div className="mt-4 rounded-lg border border-[var(--border)] bg-white p-4">
                        <span className="eyebrow">Impactos</span>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {impacts.length > 0 ? impacts.map((impact) => (
                                <span key={impact} className="sig-pill sig-pill-blue">
                                    {capImpactLabels[impact] || impact}
                                </span>
                            )) : (
                                <span className="text-sm text-[var(--ink-500)]">Nenhum impacto informado.</span>
                            )}
                        </div>
                    </div>

                    <div className="mt-4 overflow-hidden rounded-lg border border-[var(--border)] bg-white">
                        <div className="border-b border-[var(--border)] bg-[var(--surface-muted)] px-4 py-3 text-center text-sm font-semibold text-[var(--ink-900)]">
                            Analise
                        </div>
                        <div className="grid gap-0 md:grid-cols-[160px_1fr]">
                            <div className="border-b border-[var(--border)] px-4 py-3 text-sm font-semibold md:border-b-0 md:border-r">
                                Analise
                            </div>
                            <div className="border-b border-[var(--border)] px-4 py-3 text-sm text-[var(--ink-700)] md:border-b">
                                <div className="font-semibold">{personName(analysisPerson)} - {formatDateTime(capVersion.reviewed_at || document?.reviewed_at)}</div>
                                <div className="mt-1 whitespace-pre-line">{capVersion.review_notes || document?.review_notes || 'Ainda sem analise registrada.'}</div>
                            </div>
                            <div className="px-4 py-3 text-sm font-semibold md:border-r">
                                Aprovacao
                            </div>
                            <div className="px-4 py-3 text-sm text-[var(--ink-700)]">
                                <div className="font-semibold">{personName(approvalPerson)} - {formatDateTime(capVersion.approved_at || document?.approved_at)}</div>
                                <div className="mt-1 whitespace-pre-line">{capVersion.approval_notes || document?.approval_notes || 'Ainda sem aprovacao registrada.'}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <footer className="flex justify-end border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onClose}>
                        Fechar
                    </button>
                </footer>
            </section>
        </div>
    );
}
