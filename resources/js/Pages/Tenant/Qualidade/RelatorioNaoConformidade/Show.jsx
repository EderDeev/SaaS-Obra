import SignaturePanel from './SignaturePanel';
import PdfDownload from './PdfDownload';
import WorkflowButton from './WorkflowButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { rncDisciplinaLabel } from '@/Support/rnc';
import { projectEap } from '@/Utils/projectEap';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Download, ImagePlus, Pencil } from 'lucide-react';

const gravityClass = {
    Leve: 'sig-pill-blue', Media: 'sig-pill-amber', 'Média': 'sig-pill-amber', 'MÃ©dia': 'sig-pill-amber',
    Grave: 'sig-pill-red', Gravissima: 'sig-pill-red', 'Gravíssima': 'sig-pill-red', 'GravÃ­ssima': 'sig-pill-red',
};

const shortDate = (date) => {
    if (!date) return '—';
    const value = /^\d{4}-\d{2}-\d{2}$/.test(date) ? `${date}T12:00:00` : date;
    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(value));
};
const dateTime = (date) => date ? new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
}).format(new Date(date)) : '—';
const companyLabel = (company) => [company?.sigla, company?.nome].filter(Boolean).filter((name, index, all) => all.indexOf(name) === index).join(' — ');
const proposalLabels = { pending: 'Em análise', approved: 'Aprovada', rejected: 'Reprovada' };

export default function RelatorioNaoConformidadeShow({ tenant, rnc, signatureData }) {
    const { flash = {} } = usePage().props;
    const actions = rnc.acoes_corretivas || [];
    const evidences = rnc.evidencias || [];
    const latestAction = actions[0];
    const latestEvidence = evidences[0];
    const finalized = rnc.status === 'finalizada' || Boolean(rnc.finalized_at);
    const canEditRnc = rnc.user_permissions?.includes('edit_rnc');
    const linkedProjects = rnc.project_documents?.length ? rnc.project_documents : rnc.project_document ? [rnc.project_document] : [];
    const activeStage = finalized || latestEvidence ? 4 : !rnc.notified_at ? 0 : latestAction?.status === 'approved' ? 3 : latestAction?.status === 'pending' ? 2 : 1;
    const statusLabel = finalized ? 'Finalizada' : latestEvidence ? 'Aguardando assinaturas' : latestAction?.status === 'pending' ? 'Em análise da proposta'
        : latestAction?.status === 'approved' ? 'Aguardando evidências' : latestAction?.status === 'rejected' ? 'Proposta devolvida'
            : rnc.status === 'aberta' ? 'Aberta' : rnc.status || 'Aberta';
    const proposalReview = latestAction
        ? {
              pending: {
                  status: 'Em análise',
                  data: '-',
                  detalhe: 'Aguardando análise da proposta enviada',
              },
              approved: {
                  status: 'Aprovada',
                  data: latestAction.reviewed_at ? dateTime(latestAction.reviewed_at) : '-',
                  detalhe: `Aprovada por ${latestAction.reviewer?.name || 'usuário responsável'}. Pode iniciar o processo corretivo.`,
              },
              rejected: {
                  status: 'Reprovada',
                  data: latestAction.reviewed_at ? dateTime(latestAction.reviewed_at) : '-',
                  detalhe: `Reprovada por ${latestAction.reviewer?.name || 'usuário responsável'}. Motivo: ${latestAction.review_observation || '-'}`,
              },
          }[latestAction.status] || {
              status: 'Em análise',
              data: '-',
              detalhe: 'Aguardando análise da proposta enviada',
          }
        : {
              status: 'Pendente',
              data: '-',
              detalhe: 'Aguardando envio da proposta de ação corretiva',
          };
    const flowRows = [
        {
            etapa: 'Abertura da RNC',
            status: 'Concluída',
            data: shortDate(rnc.opened_at),
            responsavel: rnc.creator?.name || '-',
            detalhe: rnc.creator?.name ? `Aberta por ${rnc.creator.name}` : 'Registro inicial da não conformidade',
        },
        {
            etapa: 'Notificação aos responsáveis',
            status: rnc.notified_at ? 'Concluída' : 'Pendente',
            data: rnc.notified_at ? dateTime(rnc.notified_at) : '-',
            responsavel: 'Responsáveis da RNC',
            detalhe: rnc.notified_at ? 'Responsáveis notificados por e-mail e alerta interno' : 'Aguardando envio da notificação',
        },
        {
            etapa: 'Proposta de ação corretiva',
            status: latestAction ? 'Enviada' : 'Pendente',
            data: latestAction?.submitted_at ? dateTime(latestAction.submitted_at) : '-',
            responsavel: latestAction?.user?.name || '-',
            detalhe: latestAction?.user?.name
                ? `Enviada por ${latestAction.user.name}. Prazo proposto: ${shortDate(latestAction.prazo_execucao_proposto)}`
                : 'Aguardando proposta do responsável',
        },
        {
            etapa: 'Análise da proposta',
            status: proposalReview.status,
            data: proposalReview.data,
            responsavel: latestAction?.reviewer?.name || '-',
            detalhe: proposalReview.detalhe,
        },
        {
            etapa: 'Evidências da correção',
            status: latestEvidence ? 'Registradas' : latestAction?.status === 'approved' ? 'Pendente' : 'Aguardando',
            data: latestEvidence?.submitted_at ? dateTime(latestEvidence.submitted_at) : rnc.finalized_at ? dateTime(rnc.finalized_at) : '-',
            responsavel: latestEvidence?.user?.name || rnc.finalized_by?.name || '-',
            detalhe: latestEvidence?.user?.name
                ? `Evidências enviadas por ${latestEvidence.user.name}`
                : finalized
                  ? `RNC finalizada${rnc.finalized_by?.name ? ` por ${rnc.finalized_by.name}` : ''}`
                  : latestAction?.status === 'approved'
                    ? 'Aguardando envio das evidências da correção'
                    : 'Aguardando aprovação da proposta',
        },
        {
            etapa: 'Assinaturas das partes',
            status: signatureData?.has_signed_pdf ? 'Concluída' : signatureData?.request ? 'Em andamento' : 'Pendente',
            data: signatureData?.request?.completed_at ? dateTime(signatureData.request.completed_at) : signatureData?.request?.sent_at ? dateTime(signatureData.request.sent_at) : '-',
            responsavel: 'Partes selecionadas para assinatura',
            detalhe: signatureData?.has_signed_pdf ? 'Documento final assinado pelas partes.' : 'Aguardando todas as assinaturas e o recebimento do documento assinado.',
        },
    ];

    return (
        <AuthenticatedLayout>
            <Head title={`RNC ${rnc.formatted_number}`} />
            <div className="sig-content quality-mobile-page rnc-detail-page space-y-6">
                <header>
                    <Link href={route('tenant.qualidade.rnc.index', tenant.slug)} className="inline-flex items-center gap-2 text-sm text-[var(--ink-500)]">
                        <ArrowLeft size={16} />Voltar às RNCs
                    </Link>
                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <h1 className="break-words text-2xl font-semibold">{rnc.assunto || `RNC ${rnc.formatted_number}`}</h1><p className="mt-1 text-sm text-[var(--ink-500)]">RNC {rnc.formatted_number}</p>
                        <span className={`sig-pill ${finalized ? 'sig-pill-green' : latestAction?.status === 'rejected' ? 'sig-pill-amber' : 'sig-pill-blue'}`}>{statusLabel}</span>
                        <div className="flex flex-wrap gap-2 sm:ml-auto">
                            {!finalized && !latestEvidence && rnc.notified_at && (!latestAction || latestAction.status === 'rejected') && rnc.user_permissions?.includes('corrective_action_rnc') && <WorkflowButton tenant={tenant} rncId={rnc.id} stage="proposal">{latestAction ? 'Revisar proposta' : 'Ação corretiva'}</WorkflowButton>}
                            {!finalized && !latestEvidence && rnc.notified_at && latestAction?.status === 'pending' && rnc.user_permissions?.includes('review_rnc') && <WorkflowButton tenant={tenant} rncId={rnc.id} stage="review">Analisar proposta</WorkflowButton>}
                            {!finalized && !latestEvidence && latestAction?.status === 'approved' && rnc.user_permissions?.includes('evidence_rnc') && <WorkflowButton tenant={tenant} rncId={rnc.id} stage="evidence">Evidenciar correção</WorkflowButton>}
                            {canEditRnc && <Link href={route('tenant.qualidade.rnc.edit', [tenant.slug, rnc.id])} className="sig-btn sig-btn-secondary"><Pencil size={15} />Editar</Link>}
                            <PdfDownload tenant={tenant} rnc={rnc} />
                        </div>
                    </div>
                    <p className="mt-2 break-words text-sm text-[var(--ink-500)]">{[rnc.obra?.codigo, rnc.obra?.nome].filter(Boolean).join(' — ') || 'Relatório de Não Conformidade'}</p>
                </header>

                {flash.success && <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{flash.success}</div>}

                <section className="sig-card p-5" aria-label="Etapas da RNC">
                    <ol className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        {['Abertura da RNC', 'Proposta de ação corretiva', 'Análise da proposta', 'Evidências da correção', 'Assinaturas das partes'].map((label, index) => {
                            const current = index === activeStage;
                            const complete = index < activeStage || finalized;
                            return <li key={label} aria-current={current && !finalized ? 'step' : undefined}
                                className={`flex min-w-0 items-center gap-3 rounded-lg border px-4 py-3 text-sm ${current && !finalized ? 'border-blue-200 bg-blue-50 font-semibold text-blue-800' : complete ? 'border-emerald-100 bg-emerald-50/50 text-emerald-800' : 'border-slate-100 text-slate-500'}`}>
                                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-xs font-semibold">{complete ? <Check size={14} aria-label="Concluída" /> : index + 1}</span>
                                <span>{label}</span>
                            </li>;
                        })}
                    </ol>
                </section>

                <DetailSection title="Identificação da RNC">
                    <dl className="grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-3">
                        <Detail label="Número do relatório">{rnc.formatted_number}</Detail>
                        <Detail label="Contrato">{[rnc.contract?.code, rnc.contract?.name].filter(Boolean).join(' — ')}</Detail>
                        <Detail label="Obra">{[rnc.obra?.codigo, rnc.obra?.nome].filter(Boolean).join(' — ')}</Detail>
                        <Detail label="Disciplina">{rncDisciplinaLabel(rnc)}</Detail>
                        <Detail label="Gravidade"><span className={`sig-pill ${gravityClass[rnc.gravidade] || ''}`}>{rnc.gravidade || '—'}</span></Detail>
                        <Detail label="Data de abertura">{shortDate(rnc.opened_at)}</Detail>
                        <Detail label="Prazo de resposta da ação corretiva">{shortDate(rnc.prazo_resposta_acao_corretiva)}</Detail>
                        <Detail label="Criado por">{rnc.creator?.name}</Detail>
                        <Detail label="Local">{[rnc.contract?.city, rnc.contract?.state].filter(Boolean).join(' / ')}</Detail>
                        <Detail label="Contratante"><CompanyIdentity company={rnc.contratante} /></Detail>
                        <Detail label="Contratada"><CompanyIdentity company={rnc.contratada} /></Detail>
                        <Detail label="Coordenadas">{rnc.latitude != null && rnc.longitude != null ? `${rnc.latitude}, ${rnc.longitude}` : 'Sem coordenadas'}</Detail>
                        {rnc.instrucao_campo && <div className="sm:col-span-2 lg:col-span-3"><Detail label="IC de referência">
                            {rnc.instrucao_campo.can_view
                                ? <Link className="font-semibold text-[var(--primary)] hover:underline" href={route('tenant.qualidade.ic.show', [tenant.slug, rnc.instrucao_campo.id])}>IC {rnc.instrucao_campo.formatted_number}</Link>
                                : <span className="font-semibold">IC {rnc.instrucao_campo.formatted_number}</span>}
                            {rnc.instrucao_campo.assunto && <span> — {rnc.instrucao_campo.assunto}</span>}
                            {rnc.instrucao_campo.notified_at && <p className="mt-1 text-xs font-normal text-[var(--ink-500)]">Comunicada aos envolvidos em {dateTime(rnc.instrucao_campo.notified_at)}.</p>}
                        </Detail></div>}
                        <div className="sm:col-span-2 lg:col-span-3">
                            <Detail label="Projetos vinculados">{linkedProjects.length ? <ul className="space-y-2">{linkedProjects.map((project) => <li key={project.id}><span className="font-mono text-[var(--primary)]">{projectEap(project, project.latest_version) || 'Sem código'}</span>{project.title && <span> — {project.title}</span>}</li>)}</ul> : 'Sem projeto vinculado'}</Detail>
                        </div>
                    </dl>
                </DetailSection>

                <DetailSection title="Descrição da não conformidade">
                    <dl className="space-y-5 p-5">
                        <Detail label="Descrição do problema">{rnc.descricao_problema}</Detail>
                        <Detail label="Observações">{rnc.observacao || 'Sem observações adicionais.'}</Detail>
                        <Detail label="Ações corretivas recomendadas">{rnc.acoes_corretivas_recomendadas}</Detail>
                    </dl>
                </DetailSection>

                <DetailSection title="Registro fotográfico" icon={ImagePlus} count={rnc.photos?.length || 0}>
                    <div className="p-5"><PhotoGallery photos={rnc.photos} empty="Nenhuma imagem cadastrada nesta RNC." /></div>
                </DetailSection>

                <DetailSection title="Propostas de ação corretiva">
                    <div className="divide-y divide-slate-100 px-5">
                        {actions.length ? actions.map((action, index) => <article key={action.id} className="space-y-5 py-5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="text-sm font-semibold">{actions.length > 1 ? `Proposta ${actions.length - index}` : 'Proposta de ação corretiva'}{index === 0 && actions.length > 1 && <span className="ml-2 text-xs font-normal text-[var(--ink-500)]">Mais recente</span>}</h3>
                                <span className={`sig-pill ${statusPillClass(proposalLabels[action.status])}`}>{proposalLabels[action.status] || action.status}</span>
                            </div>
                            <dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                                <Detail label="Responsável pela proposta">{action.user?.name}</Detail>
                                <Detail label="Enviada em">{dateTime(action.submitted_at)}</Detail>
                                <Detail label="Prazo de execução proposto">{shortDate(action.prazo_execucao_proposto)}</Detail>
                                <div className="sm:col-span-2 lg:col-span-3"><Detail label="Descrição da proposta">{action.descricao_proposta}</Detail></div>
                            </dl>
                            {action.status !== 'pending' && <div className={`rounded-lg border p-4 ${action.status === 'rejected' ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-slate-50'}`}>
                                <h4 className="mb-4 text-sm font-semibold">Análise da proposta</h4>
                                <dl className="grid gap-4 sm:grid-cols-2">
                                    <Detail label="Analisada por">{action.reviewer?.name}</Detail>
                                    <Detail label="Data da análise">{dateTime(action.reviewed_at)}</Detail>
                                    <div className="sm:col-span-2"><Detail label={action.status === 'rejected' ? 'Motivo da reprovação' : 'Observação da análise'}>{action.review_observation}</Detail></div>
                                </dl>
                            </div>}
                        </article>) : <EmptyState>Nenhuma proposta de ação corretiva enviada até o momento.</EmptyState>}
                    </div>
                </DetailSection>

                <DetailSection title="Evidências da correção">
                    <div className="divide-y divide-slate-100 px-5">
                        {evidences.length ? evidences.map((evidence) => <article key={evidence.id} className="space-y-5 py-5">
                            <dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                                <Detail label="Enviado por">{evidence.user?.name}</Detail>
                                <Detail label="Enviado em">{dateTime(evidence.submitted_at)}</Detail>
                                <Detail label="Situação"><span className="sig-pill sig-pill-blue">Evidências registradas</span></Detail>
                                <Detail label="O problema foi resolvido?"><span className={`sig-pill ${evidence.resolution_result === 'yes' ? 'sig-pill-green' : evidence.resolution_result === 'partial' ? 'sig-pill-amber' : evidence.resolution_result === 'no' ? 'sig-pill-red' : 'sig-pill-muted'}`}>{evidence.resolution_label || 'Não informado'}</span></Detail>
                                {evidence.resolution_reason && <div className="sm:col-span-2 lg:col-span-3"><Detail label="Motivo"><p className="whitespace-pre-wrap break-words">{evidence.resolution_reason}</p></Detail></div>}
                            </dl>
                            {evidence.attachment_original_name && <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 p-4">
                                <p className="min-w-0 flex-1 break-all text-sm font-medium">{evidence.attachment_original_name}</p>
                                <a href={route('tenant.qualidade.rnc.evidencias.download', [tenant.slug, rnc.id, evidence.id])} download className="sig-btn sig-btn-secondary"><Download size={15} />Baixar anexo</a>
                            </div>}
                            {evidence.photos?.length > 0 && <PhotoGallery photos={evidence.photos} prefix="Evidência" />}
                        </article>) : <EmptyState>Nenhuma evidência de correção enviada ainda.</EmptyState>}
                    </div>
                </DetailSection>

                {latestEvidence && signatureData && <SignaturePanel tenant={tenant} rnc={rnc} data={signatureData} />}

                <DetailSection title="Fluxo da RNC">
                    <ol className="divide-y divide-slate-100 px-5">
                        {flowRows.map((row) => <li key={row.etapa} className="space-y-2 py-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <strong className="text-sm">{row.etapa}</strong>
                                <span className={`sig-pill ${statusPillClass(row.status)}`}>{row.status}</span>
                            </div>
                            <p className="text-xs text-[var(--ink-500)]">{row.responsavel} · {row.data}</p>
                            <p className="whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 text-sm">{row.detalhe}</p>
                        </li>)}
                    </ol>
                </DetailSection>
            </div>
        </AuthenticatedLayout>
    );
}

function DetailSection({ title, icon: Icon, count, children }) {
    return <section className="sig-card min-w-0" aria-label={title}>
        <h2 className="flex flex-wrap items-center gap-2 border-b border-slate-200 px-5 py-4 text-lg font-semibold">{Icon && <Icon size={18} />}{title}{count != null && <span className="sig-pill">{count}</span>}</h2>
        {children}
    </section>;
}

function Detail({ label, children }) {
    return <div className="min-w-0"><dt className="text-xs font-semibold text-[var(--ink-500)]">{label}</dt><dd className="mt-1 whitespace-pre-wrap break-words text-sm text-[var(--ink-900)]">{children || '—'}</dd></div>;
}

function CompanyIdentity({ company }) {
    return <span className="flex items-center gap-3">{company?.logo_url && <img src={company.logo_url} alt="" className="h-9 w-16 shrink-0 object-contain" />}<span className="min-w-0">{companyLabel(company) || '—'}</span></span>;
}

function PhotoGallery({ photos = [], prefix = 'Imagem', empty }) {
    if (!photos?.length) return <EmptyState>{empty}</EmptyState>;
    return <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">{photos.map((photo, index) => <figure key={photo.id} className="min-w-0 overflow-hidden rounded-lg border border-slate-200">
        <a href={photo.url} target="_blank" rel="noreferrer" className="block aspect-[4/3] bg-slate-50" aria-label={`Abrir ${prefix.toLowerCase()} ${photo.position || index + 1}`}>
            <img src={photo.url} alt={photo.original_name || `${prefix} ${photo.position || index + 1}`} className="h-full w-full object-contain" loading="lazy" />
        </a>
        <figcaption className="break-words p-3 text-xs leading-5 text-[var(--ink-500)]"><span className="block font-semibold text-[var(--ink-900)]">{prefix} {photo.position || index + 1}</span>{photo.comment || photo.original_name}</figcaption>
    </figure>)}</div>;
}

function EmptyState({ children }) {
    return <p className="py-5 text-sm text-[var(--ink-500)]">{children}</p>;
}

function statusPillClass(status) {
    if (['Concluída', 'Enviada', 'Aprovada', 'Finalizada'].includes(status)) return ['Aprovada', 'Finalizada'].includes(status) ? 'sig-pill-green' : 'sig-pill-blue';
    return status === 'Reprovada' ? 'sig-pill-red' : 'sig-pill-amber';
}
