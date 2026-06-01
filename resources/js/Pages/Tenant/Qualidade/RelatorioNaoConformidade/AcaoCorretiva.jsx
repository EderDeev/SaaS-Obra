import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { rncDisciplinaLabel } from '@/Support/rnc';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ClipboardCheck, ClipboardX, Download, FileArchive, Plus } from 'lucide-react';
import { useRef } from 'react';

const shortDate = (date) => {
    if (!date) return '-';

    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date));
};

const fileSize = (bytes) => {
    if (!bytes) return '0 KB';

    if (bytes >= 1024 * 1024) {
        return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
};

const statusInfo = {
    pending: { label: 'Em analise', className: 'sig-pill-amber' },
    approved: { label: 'Aprovada', className: 'sig-pill-green' },
    rejected: { label: 'Reprovada', className: 'sig-pill-red' },
};

export default function RncAcaoCorretiva({ tenant, rnc, acoesCorretivas }) {
    const page = usePage();
    const fileInputRef = useRef(null);
    const form = useForm({
        descricao_proposta: '',
        prazo_execucao_proposto: '',
        attachment: null,
    });

    const submit = (event) => {
        event.preventDefault();

        form.post(route('tenant.qualidade.rnc.acao-corretiva.store', [tenant.slug, rnc.id]), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();

                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Acao corretiva - RNC ${rnc.formatted_number}`} />

            <section className="sig-content">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <ClipboardCheck size={14} />
                            <span className="eyebrow">Acao corretiva</span>
                        </div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">
                            RNC {rnc.formatted_number}
                        </h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            {rnc.obra?.codigo} - {rnc.obra?.nome}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('tenant.qualidade.rnc.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                            <ArrowLeft size={15} />
                            Voltar
                        </Link>
                        <Link href={route('tenant.qualidade.rnc.show', [tenant.slug, rnc.id])} className="sig-btn sig-btn-secondary">
                            <ClipboardX size={15} />
                            Abrir RNC
                        </Link>
                    </div>
                </div>

                {page.props.flash.success && (
                    <div className="mb-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                        {page.props.flash.success}
                    </div>
                )}

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                    <article className="sig-card overflow-hidden">
                        <section className="border-b border-[var(--border)] p-5">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="sig-pill">{rnc.status}</span>
                                <span className="sig-pill sig-pill-blue">{rncDisciplinaLabel(rnc)}</span>
                                <span className="sig-pill">{rnc.gravidade}</span>
                            </div>
                            <h2 className="mt-4 text-xl font-semibold text-[var(--ink-900)]">Resumo da nao conformidade</h2>

                            <div className="mt-5 grid gap-3 md:grid-cols-2">
                                <Meta label="Contrato" value={`${rnc.contract?.code || ''} - ${rnc.contract?.name || ''}`} />
                                <Meta label="Obra" value={`${rnc.obra?.codigo || ''} - ${rnc.obra?.nome || ''}`} />
                                <Meta label="Data abertura" value={shortDate(rnc.opened_at)} />
                                <Meta label="Notificada em" value={shortDate(rnc.notified_at)} />
                                <Meta label="Contratante" value={rnc.contratante?.sigla || rnc.contratante?.nome} />
                                <Meta label="Contratada" value={rnc.contratada?.sigla || rnc.contratada?.nome} />
                            </div>
                        </section>

                        <section className="border-b border-[var(--border)] p-5">
                            <div className="eyebrow">Descricao e recomendacao</div>
                            <TextBlock title="Descricao do problema" value={rnc.descricao_problema} />
                            <TextBlock title="Acoes corretivas recomendadas" value={rnc.acoes_corretivas_recomendadas} />
                        </section>

                        <section className="p-5">
                            <div className="eyebrow">Imagens</div>
                            {rnc.photos?.length > 0 ? (
                                <div className="mt-4 grid gap-4 md:grid-cols-2">
                                    {rnc.photos.map((photo) => (
                                        <figure key={photo.id} className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
                                            <img
                                                src={photo.url}
                                                alt={photo.original_name || `Imagem ${photo.position}`}
                                                className="h-64 w-full rounded-md bg-white object-contain"
                                            />
                                            <figcaption className="mt-3 text-sm text-[var(--ink-500)]">
                                                <span className="font-semibold text-[var(--ink-800)]">Imagem {photo.position}</span>
                                                {photo.comment ? ` - ${photo.comment}` : ''}
                                            </figcaption>
                                        </figure>
                                    ))}
                                </div>
                            ) : (
                                <div className="mt-4 rounded-lg border border-dashed border-[var(--border-strong)] p-8 text-center text-sm text-[var(--ink-500)]">
                                    Nenhuma imagem cadastrada nesta RNC.
                                </div>
                            )}
                        </section>
                    </article>

                    <aside className="grid gap-6 content-start">
                        <form className="sig-card p-5" onSubmit={submit}>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <ClipboardCheck size={14} />
                                <span className="eyebrow">Enviar proposta</span>
                            </div>
                            <h2 className="mt-2 text-xl font-semibold text-[var(--ink-900)]">Proposta de acao corretiva</h2>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                Descreva a proposta, informe o prazo de execucao e envie um arquivo .zip de ate 30 MB.
                            </p>

                            <div className="mt-5 grid gap-4">
                                <Field label="Descricao da proposta" error={form.errors.descricao_proposta}>
                                    <textarea
                                        value={form.data.descricao_proposta}
                                        onChange={(event) => form.setData('descricao_proposta', event.target.value)}
                                        rows={7}
                                        placeholder="Descreva como a nao conformidade sera tratada"
                                        required
                                    />
                                </Field>

                                <Field label="Prazo proposto para executar a acao" error={form.errors.prazo_execucao_proposto}>
                                    <input
                                        type="date"
                                        value={form.data.prazo_execucao_proposto}
                                        onChange={(event) => form.setData('prazo_execucao_proposto', event.target.value)}
                                        required
                                    />
                                </Field>

                                <Field label="Anexo zipado" error={form.errors.attachment}>
                                    <input
                                        ref={fileInputRef}
                                        type="file"
                                        accept=".zip,application/zip"
                                        onChange={(event) => form.setData('attachment', event.target.files?.[0] || null)}
                                        required
                                    />
                                </Field>

                                {form.data.attachment && (
                                    <div className="flex items-center gap-3 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-3 py-2 text-sm">
                                        <FileArchive size={16} />
                                        <span className="min-w-0 flex-1 truncate">{form.data.attachment.name}</span>
                                        <span className="text-xs text-[var(--ink-500)]">{fileSize(form.data.attachment.size)}</span>
                                    </div>
                                )}
                            </div>

                            <button
                                className="sig-btn sig-btn-primary mt-5"
                                disabled={form.processing || !form.data.descricao_proposta || !form.data.prazo_execucao_proposto || !form.data.attachment}
                            >
                                <Plus size={15} />
                                {form.processing ? 'Enviando...' : 'Enviar proposta'}
                            </button>
                        </form>

                        <section className="sig-card overflow-hidden">
                            <header className="border-b border-[var(--border)] px-5 py-4">
                                <div className="eyebrow">Historico de propostas</div>
                                <h2 className="mt-1 text-[15px] font-semibold text-[var(--ink-900)]">
                                    {acoesCorretivas.length} envio(s)
                                </h2>
                            </header>
                            {acoesCorretivas.length > 0 ? (
                                <div className="grid gap-3 p-4">
                                    {acoesCorretivas.map((acao) => (
                                        <div key={acao.id} className="rounded-lg border border-[var(--border)] p-3">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <a
                                                    href={acao.download_url || acao.url}
                                                    download
                                                    className="flex min-w-0 flex-1 items-center gap-2 text-[13px] font-semibold text-[var(--ink-900)] hover:text-[var(--primary)]"
                                                >
                                                    <Download size={14} />
                                                    <span className="min-w-0 flex-1 truncate">{acao.attachment_original_name}</span>
                                                </a>
                                                <span className={`sig-pill ${statusInfo[acao.status]?.className || ''}`}>
                                                    {statusInfo[acao.status]?.label || acao.status}
                                                </span>
                                            </div>
                                            <div className="mt-2 text-[12px] text-[var(--ink-500)]">
                                                {acao.user?.name} - {acao.submitted_at_formatted || shortDate(acao.submitted_at)} - {fileSize(acao.attachment_size)}
                                            </div>
                                            <div className="mt-2 text-[12px] font-semibold text-[var(--ink-700)]">
                                                Prazo proposto: {acao.prazo_execucao_proposto_formatted || shortDate(acao.prazo_execucao_proposto)}
                                            </div>
                                            <p className="mt-2 line-clamp-3 text-[12.5px] leading-5 text-[var(--ink-600)]">
                                                {acao.descricao_proposta}
                                            </p>
                                            {acao.review_observation && (
                                                <div className="mt-3 rounded-lg bg-[var(--surface-muted)] p-3 text-[12.5px] leading-5 text-[var(--ink-600)]">
                                                    <span className="font-semibold text-[var(--ink-900)]">
                                                        {acao.status === 'rejected' ? 'Motivo da reprovacao: ' : 'Observacoes da analise: '}
                                                    </span>
                                                    {acao.review_observation}
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="p-8 text-center text-sm text-[var(--ink-500)]">
                                    Nenhuma proposta enviada ainda.
                                </div>
                            )}
                        </section>
                    </aside>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Meta({ label, value }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-3 py-2">
            <div className="eyebrow">{label}</div>
            <div className="mt-1 text-[13px] font-semibold text-[var(--ink-800)]">{value || '-'}</div>
        </div>
    );
}

function TextBlock({ title, value }) {
    return (
        <div className="mt-4 rounded-lg border border-[var(--border)] bg-white p-4">
            <h3 className="text-[13px] font-semibold text-[var(--ink-900)]">{title}</h3>
            <p className="mt-2 whitespace-pre-line text-sm leading-6 text-[var(--ink-500)]">{value}</p>
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
