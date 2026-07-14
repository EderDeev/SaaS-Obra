import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GedTour from '@/Components/GedTour';
import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, FileText, Inbox, Mail, Paperclip } from 'lucide-react';

function formatDateTime(value) {
    if (!value) return '--';

    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return value;

    return date.toLocaleString('pt-BR');
}

function TriageCard({ message }) {
    const pdfCandidates = message.metadata?.pdf_candidates || [];
    const supportAttachments = message.metadata?.support_attachment_names || [];
    const form = useForm({
        main_pdf: pdfCandidates[0] || '',
    });

    function submit(event) {
        event.preventDefault();

        if (!form.data.main_pdf) return;

        form.post(message.resolve_url, {
            preserveScroll: true,
        });
    }

    return (
        <form data-tour="ged-triage-card" onSubmit={submit} className="rounded-xl border border-[var(--border)] bg-white p-4 shadow-sm">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="sig-pill sig-pill-amber">
                            <AlertTriangle size={13} />
                            Triagem
                        </span>
                        {message.rule?.contract && (
                            <span className="sig-pill sig-pill-blue">
                                {message.rule.contract.code} - {message.rule.contract.name}
                            </span>
                        )}
                    </div>

                    <h2 className="mt-3 truncate text-lg font-bold text-[var(--ink-900)]">{message.subject || 'Sem assunto'}</h2>
                    <div className="mt-2 grid gap-1 text-sm text-[var(--ink-600)]">
                        <span className="inline-flex items-center gap-2">
                            <Mail size={15} />
                            {message.from || 'Remetente nao informado'}
                        </span>
                        <span className="inline-flex items-center gap-2">
                            <Inbox size={15} />
                            {message.account?.name || message.account?.email || 'Conta nao informada'} · {message.account?.mailbox || 'INBOX'}
                        </span>
                        <span>Recebido em {formatDateTime(message.received_at)} · triado em {formatDateTime(message.processed_at)}</span>
                    </div>
                </div>

                <div className="shrink-0 text-sm font-semibold text-[var(--ink-700)]">
                    {message.attachments_count || 0} anexo{Number(message.attachments_count || 0) === 1 ? '' : 's'}
                </div>
            </div>

            <div className="mt-5 grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
                <div data-tour="ged-triage-pdf-choice">
                    <div className="mb-2 text-xs font-bold uppercase tracking-[0.14em] text-[var(--ink-500)]">Escolha o PDF principal</div>
                    <div className="space-y-2">
                        {pdfCandidates.length === 0 && (
                            <div className="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-[var(--ink-500)]">
                                Nenhum PDF candidato registrado nesta pendencia.
                            </div>
                        )}

                        {pdfCandidates.map((filename) => (
                            <label key={filename} className={`flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm transition ${form.data.main_pdf === filename ? 'border-blue-500 bg-blue-50 text-blue-900' : 'border-slate-200 hover:bg-slate-50'}`}>
                                <input
                                    type="radio"
                                    name={`main_pdf_${message.id}`}
                                    value={filename}
                                    checked={form.data.main_pdf === filename}
                                    onChange={(event) => form.setData('main_pdf', event.target.value)}
                                />
                                <FileText size={16} className="shrink-0 text-blue-700" />
                                <span className="min-w-0 flex-1 truncate font-semibold">{filename}</span>
                            </label>
                        ))}
                    </div>
                    {form.errors.main_pdf && <span className="mt-2 block text-xs font-semibold text-rose-600">{form.errors.main_pdf}</span>}
                </div>

                <div data-tour="ged-triage-attachments">
                    <div className="mb-2 text-xs font-bold uppercase tracking-[0.14em] text-[var(--ink-500)]">Arquivos que viram anexos</div>
                    <div className="max-h-44 overflow-y-auto rounded-lg border border-slate-200 bg-slate-50 p-2">
                        {supportAttachments.length === 0 && (
                            <div className="px-2 py-3 text-sm text-[var(--ink-500)]">Nenhum arquivo extra registrado.</div>
                        )}

                        {supportAttachments.map((filename) => (
                            <div key={filename} className="flex items-center gap-2 rounded-md bg-white px-2 py-1.5 text-xs text-[var(--ink-700)]">
                                <Paperclip size={14} className="shrink-0 text-blue-700" />
                                <span className="min-w-0 flex-1 truncate">{filename}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            <div className="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-xs text-[var(--ink-500)]">
                    Ao resolver, o sistema busca o e-mail original, importa o PDF escolhido e coloca os outros arquivos na aba Anexos.
                </p>
                <button data-tour="ged-triage-resolve" type="submit" className="sig-btn sig-btn-primary" disabled={form.processing || !form.data.main_pdf}>
                    <CheckCircle2 size={16} />
                    Resolver triagem
                </button>
            </div>
        </form>
    );
}

export default function GedTriage({ tenant, messages }) {
    const hasMessages = (messages.data || []).length > 0;

    return (
        <AuthenticatedLayout>
            <Head title="Triagem de e-mails" />

            <div className="space-y-6 px-4 pb-10 pt-6 sm:px-6 lg:px-8 xl:px-10">
                <div data-tour="ged-triage-overview" className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Link href={route('tenant.ged.index', tenant.slug)} className="inline-flex items-center gap-2 text-sm font-semibold text-emerald-800 hover:underline">
                            <ArrowLeft size={16} />
                            Voltar para documentacao
                        </Link>
                        <h1 className="mt-3 text-2xl font-bold text-[var(--ink-900)]">Triagem</h1>
                        <p className="mt-1 max-w-3xl text-sm text-[var(--ink-600)]">
                            E-mails com mais de um PDF ficam aqui para escolher qual arquivo sera o documento principal.
                        </p>
                    </div>

                    <span className="sig-pill sig-pill-amber w-fit">{messages.total || 0} pendente{Number(messages.total || 0) === 1 ? '' : 's'}</span>
                </div>

                {!hasMessages && (
                    <div data-tour="ged-triage-list" className="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
                            <CheckCircle2 size={24} />
                        </div>
                        <h2 className="mt-4 text-lg font-bold text-[var(--ink-900)]">Nenhuma triagem pendente</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">Quando um e-mail chegar com varios PDFs, ele aparecera aqui.</p>
                    </div>
                )}

                <div data-tour="ged-triage-list" className="space-y-4">
                    {(messages.data || []).map((message) => (
                        <TriageCard key={message.id} message={message} />
                    ))}
                </div>

                {messages.links?.length > 3 && (
                    <div className="flex flex-wrap items-center gap-2">
                        {messages.links.map((link, index) => (
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
            <GedTour tenant={tenant} section="triage" />
        </AuthenticatedLayout>
    );
}
