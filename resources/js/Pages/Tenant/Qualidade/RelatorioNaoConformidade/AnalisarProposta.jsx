import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { rncDisciplinaLabel } from '@/Support/rnc';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, ClipboardX, Download, FileArchive, SearchCheck, Send, XCircle } from 'lucide-react';

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

export default function RncAnalisarProposta({ tenant, rnc, acaoCorretiva, acoesCorretivas }) {
    const page = usePage();
    const form = useForm({
        decision: '',
        review_observation: '',
    });

    const submit = (event) => {
        event.preventDefault();

        form.post(route('tenant.qualidade.rnc.analisar-proposta.store', [tenant.slug, rnc.id]), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Analisar proposta - RNC ${rnc.formatted_number}`} />

            <section className="sig-content">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <SearchCheck size={14} />
                            <span className="eyebrow">Analise da proposta</span>
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
                                <span className="sig-pill sig-pill-amber">Em analise</span>
                                <span className="sig-pill sig-pill-blue">{rncDisciplinaLabel(rnc)}</span>
                                <span className="sig-pill">{rnc.gravidade}</span>
                            </div>
                            <h2 className="mt-4 text-xl font-semibold text-[var(--ink-900)]">Resumo da nao conformidade</h2>

                            <div className="mt-5 grid gap-3 md:grid-cols-2">
                                <Meta label="Contrato" value={`${rnc.contract?.code || ''} - ${rnc.contract?.name || ''}`} />
                                <Meta label="Obra" value={`${rnc.obra?.codigo || ''} - ${rnc.obra?.nome || ''}`} />
                                <Meta label="Data abertura" value={shortDate(rnc.opened_at)} />
                                <Meta label="Prazo resposta" value={shortDate(rnc.prazo_resposta_acao_corretiva)} />
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
                            <div className="eyebrow">Proposta enviada</div>
                            <div className="mt-4 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4">
                                <div className="flex flex-wrap items-center gap-3">
                                    <span className="sig-pill sig-pill-amber">Aguardando analise</span>
                                    <span className="text-sm font-semibold text-[var(--ink-900)]">
                                        {acaoCorretiva.user?.name} - {acaoCorretiva.submitted_at_formatted}
                                    </span>
                                </div>
                                <div className="mt-3 grid gap-3 md:grid-cols-2">
                                    <Meta label="Prazo proposto para execucao" value={acaoCorretiva.prazo_execucao_proposto_formatted || shortDate(acaoCorretiva.prazo_execucao_proposto)} />
                                    <Meta label="Arquivo da proposta" value={acaoCorretiva.attachment_original_name} />
                                </div>
                                <p className="mt-3 whitespace-pre-line text-sm leading-6 text-[var(--ink-600)]">
                                    {acaoCorretiva.descricao_proposta}
                                </p>
                                <a
                                    href={acaoCorretiva.download_url || acaoCorretiva.url}
                                    download
                                    className="sig-btn sig-btn-secondary mt-4"
                                >
                                    <FileArchive size={15} />
                                    {acaoCorretiva.attachment_original_name}
                                    <span className="text-xs text-[var(--ink-500)]">{fileSize(acaoCorretiva.attachment_size)}</span>
                                </a>
                            </div>
                        </section>
                    </article>

                    <aside className="grid gap-6 content-start">
                        <form className="sig-card p-5" onSubmit={submit}>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <SearchCheck size={14} />
                                <span className="eyebrow">Parecer</span>
                            </div>
                            <h2 className="mt-2 text-xl font-semibold text-[var(--ink-900)]">Analisar Proposta</h2>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                Registre as observacoes da analise e escolha se a proposta sera aprovada ou reprovada.
                            </p>

                            <div className="mt-5">
                                <div>
                                    <span className="eyebrow mb-2 block">Resultado da analise</span>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        <DecisionOption
                                            checked={form.data.decision === 'approved'}
                                            description="Aceitar e liberar o inicio do processo corretivo."
                                            icon={CheckCircle2}
                                            label="Aprovar"
                                            tone="green"
                                            value="approved"
                                            onChange={(value) => form.setData('decision', value)}
                                        />
                                        <DecisionOption
                                            checked={form.data.decision === 'rejected'}
                                            description="Solicitar uma nova proposta aos responsaveis."
                                            icon={XCircle}
                                            label="Reprovar"
                                            tone="red"
                                            value="rejected"
                                            onChange={(value) => form.setData('decision', value)}
                                        />
                                    </div>
                                    {form.errors.decision && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.decision}</span>}
                                </div>

                                <div className="mt-4">
                                <Field label="Observacoes da analise" error={form.errors.review_observation}>
                                    <textarea
                                        value={form.data.review_observation}
                                        onChange={(event) => form.setData('review_observation', event.target.value)}
                                        rows={8}
                                        placeholder="Explique o parecer, motivo de reprovacao ou condicoes para seguir"
                                        required
                                    />
                                </Field>
                                </div>
                            </div>

                            <button
                                type="submit"
                                className="sig-btn sig-btn-primary mt-5 w-full"
                                disabled={form.processing || !form.data.decision || !form.data.review_observation}
                            >
                                <Send size={15} />
                                {form.processing ? 'Enviando...' : 'Enviar analise'}
                            </button>
                        </form>

                        <section className="sig-card overflow-hidden">
                            <header className="border-b border-[var(--border)] px-5 py-4">
                                <div className="eyebrow">Historico</div>
                                <h2 className="mt-1 text-[15px] font-semibold text-[var(--ink-900)]">
                                    {acoesCorretivas.length} proposta(s)
                                </h2>
                            </header>
                            <div className="grid gap-3 p-4">
                                {acoesCorretivas.map((acao) => (
                                    <a
                                        key={acao.id}
                                        href={acao.download_url || acao.url}
                                        download
                                        className="rounded-lg border border-[var(--border)] p-3 hover:bg-[var(--surface-muted)]"
                                    >
                                        <div className="flex items-center gap-2 text-[13px] font-semibold text-[var(--ink-900)]">
                                            <Download size={14} />
                                            <span className="min-w-0 flex-1 truncate">{acao.attachment_original_name}</span>
                                        </div>
                                        <div className="mt-2 text-[12px] text-[var(--ink-500)]">
                                            {acao.user?.name} - {acao.submitted_at_formatted || shortDate(acao.submitted_at)}
                                        </div>
                                        <div className="mt-1 text-[12px] font-semibold text-[var(--ink-700)]">
                                            Prazo proposto: {acao.prazo_execucao_proposto_formatted || shortDate(acao.prazo_execucao_proposto)}
                                        </div>
                                    </a>
                                ))}
                            </div>
                        </section>
                    </aside>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function DecisionOption({ checked, description, icon: Icon, label, onChange, tone, value }) {
    const toneClass = tone === 'green' ? 'text-[var(--green)]' : 'text-[var(--red)]';
    const selectedClass = checked
        ? tone === 'green'
            ? 'border-[var(--green)] bg-[var(--green-50)]'
            : 'border-[var(--red)] bg-[var(--red-50)]'
        : 'border-[var(--border)] bg-white hover:bg-[var(--surface-muted)]';

    return (
        <label className={`flex cursor-pointer gap-3 rounded-lg border p-3 transition ${selectedClass}`}>
            <input
                type="radio"
                name="decision"
                value={value}
                checked={checked}
                onChange={() => onChange(value)}
                className="mt-1"
            />
            <span className="min-w-0 flex-1">
                <span className={`flex items-center gap-2 text-sm font-semibold ${checked ? toneClass : 'text-[var(--ink-900)]'}`}>
                    <Icon size={15} />
                    {label}
                </span>
                <span className="mt-1 block text-[12px] leading-5 text-[var(--ink-500)]">{description}</span>
            </span>
        </label>
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
