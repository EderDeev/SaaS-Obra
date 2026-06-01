import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { rncDisciplinaLabel } from '@/Support/rnc';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ClipboardX, Download, ImagePlus, MapPin, Pencil } from 'lucide-react';

const gravityClass = {
    Leve: 'sig-pill-blue',
    Media: 'sig-pill-amber',
    'Média': 'sig-pill-amber',
    'MÃ©dia': 'sig-pill-amber',
    Grave: 'sig-pill-red',
    Gravissima: 'sig-pill-red',
    'Gravíssima': 'sig-pill-red',
    'GravÃ­ssima': 'sig-pill-red',
};

const shortDate = (date) => {
    if (!date) return '-';

    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date));
};

const dateTime = (date) => {
    if (!date) return '-';

    return new Intl.DateTimeFormat('pt-BR', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(date));
};

const pdfUrl = (tenant, rnc) => `${route('tenant.qualidade.rnc.pdf', [tenant.slug, rnc.id])}?v=${encodeURIComponent(rnc.updated_at || rnc.notified_at || rnc.id)}`;

export default function RelatorioNaoConformidadeShow({ tenant, rnc }) {
    const page = usePage();
    const latestAction = rnc.acoes_corretivas?.[0];
    const approvedAction = rnc.acoes_corretivas?.find((action) => action.status === 'approved');
    const latestEvidence = rnc.evidencias?.[0];
    const canEditRnc = rnc.user_permissions?.includes('edit_rnc');
    const proposalReview = latestAction
        ? {
              pending: {
                  status: 'Em analise',
                  data: '-',
                  detalhe: 'Aguardando analise da proposta enviada',
              },
              approved: {
                  status: 'Aprovada',
                  data: latestAction.reviewed_at ? dateTime(latestAction.reviewed_at) : '-',
                  detalhe: `Aprovada por ${latestAction.reviewer?.name || 'usuario responsavel'}. Pode iniciar o processo corretivo.`,
              },
              rejected: {
                  status: 'Reprovada',
                  data: latestAction.reviewed_at ? dateTime(latestAction.reviewed_at) : '-',
                  detalhe: `Reprovada por ${latestAction.reviewer?.name || 'usuario responsavel'}. Motivo: ${latestAction.review_observation || '-'}`,
              },
          }[latestAction.status] || {
              status: 'Em analise',
              data: '-',
              detalhe: 'Aguardando analise da proposta enviada',
          }
        : {
              status: 'Pendente',
              data: '-',
              detalhe: 'Aguardando envio da proposta de acao corretiva',
          };
    const flowRows = [
        {
            etapa: 'Abertura da RNC',
            status: 'Concluida',
            data: shortDate(rnc.opened_at),
            responsavel: rnc.creator?.name || '-',
            detalhe: rnc.creator?.name ? `Aberta por ${rnc.creator.name}` : 'Registro inicial da nao conformidade',
        },
        {
            etapa: 'Notificacao aos responsaveis',
            status: rnc.notified_at ? 'Concluida' : 'Pendente',
            data: rnc.notified_at ? dateTime(rnc.notified_at) : '-',
            responsavel: 'Responsaveis da RNC',
            detalhe: rnc.notified_at ? 'Responsaveis notificados por email e alerta interno' : 'Aguardando envio da notificacao',
        },
        {
            etapa: 'Proposta de acao corretiva',
            status: latestAction ? 'Enviada' : 'Pendente',
            data: latestAction?.submitted_at ? dateTime(latestAction.submitted_at) : '-',
            responsavel: latestAction?.user?.name || '-',
            detalhe: latestAction?.user?.name
                ? `Enviada por ${latestAction.user.name}. Prazo proposto: ${shortDate(latestAction.prazo_execucao_proposto)}`
                : 'Aguardando proposta do responsavel',
        },
        {
            etapa: 'Analise da proposta',
            status: proposalReview.status,
            data: proposalReview.data,
            responsavel: latestAction?.reviewer?.name || '-',
            detalhe: proposalReview.detalhe,
        },
        {
            etapa: 'Evidencias da correcao',
            status: latestEvidence || rnc.finalized_at ? 'Finalizada' : latestAction?.status === 'approved' ? 'Pendente' : 'Aguardando',
            data: latestEvidence?.submitted_at ? dateTime(latestEvidence.submitted_at) : rnc.finalized_at ? dateTime(rnc.finalized_at) : '-',
            responsavel: latestEvidence?.user?.name || rnc.finalized_by?.name || '-',
            detalhe: latestEvidence?.user?.name
                ? `Evidencias enviadas por ${latestEvidence.user.name}`
                : latestAction?.status === 'approved'
                  ? 'Aguardando envio das evidencias da correcao'
                  : 'Aguardando aprovacao da proposta',
        },
    ];

    return (
        <AuthenticatedLayout>
            <Head title={`RNC ${rnc.formatted_number}`} />

            <section className="sig-content">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <ClipboardX size={14} />
                            <span className="eyebrow">Previa do documento</span>
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
                        {canEditRnc && (
                            <Link href={route('tenant.qualidade.rnc.edit', [tenant.slug, rnc.id])} className="sig-btn sig-btn-secondary">
                                <Pencil size={15} />
                                Editar
                            </Link>
                        )}
                        <a
                            href={pdfUrl(tenant, rnc)}
                            className="sig-btn sig-btn-primary"
                            target="_blank"
                            rel="noreferrer"
                        >
                            <Download size={15} />
                            Visualizar PDF
                        </a>
                    </div>
                </div>

                {page.props.flash.success && (
                    <div className="mb-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                        {page.props.flash.success}
                    </div>
                )}

                <article className="sig-card overflow-hidden">
                    <section className="border-b border-[var(--border)] p-5">
                        <div className="mb-5 grid gap-3 md:grid-cols-[minmax(0,220px)_minmax(0,1fr)_minmax(0,220px)] md:items-center">
                            <CompanyLogoCard label="Contratante" company={rnc.contratante} align="left" />
                            <div className="hidden text-center md:block">
                                <div className="eyebrow text-[var(--ink-500)]">Relatorio de Nao Conformidade</div>
                                <div className="mt-1 text-lg font-semibold text-[var(--ink-900)]">RNC {rnc.formatted_number}</div>
                            </div>
                            <CompanyLogoCard label="Contratada" company={rnc.contratada} align="right" />
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <span className={`sig-pill ${gravityClass[rnc.gravidade] || ''}`}>{rnc.gravidade}</span>
                            <span className="sig-pill sig-pill-blue">{rncDisciplinaLabel(rnc)}</span>
                            <span className="sig-pill">{rnc.status}</span>
                        </div>
                        <h2 className="mt-4 text-xl font-semibold text-[var(--ink-900)]">Relatorio de Nao Conformidade</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">{tenant.name}</p>

                        <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            <Meta label="Contrato" value={`${rnc.contract?.code || ''} - ${rnc.contract?.name || ''}`} />
                            <Meta label="Obra" value={`${rnc.obra?.codigo || ''} - ${rnc.obra?.nome || ''}`} />
                            <Meta
                                label="Projeto vinculado"
                                value={rnc.project_document ? `${rnc.project_document.code || 'Sem codigo'} - ${rnc.project_document.title}` : 'Sem projeto vinculado'}
                            />
                            <Meta label="Data abertura" value={shortDate(rnc.opened_at)} />
                            <Meta label="Prazo resposta acao corretiva" value={shortDate(rnc.prazo_resposta_acao_corretiva)} />
                            <Meta label="Contratante" value={rnc.contratante?.sigla || rnc.contratante?.nome} />
                            <Meta label="Contratada" value={rnc.contratada?.sigla || rnc.contratada?.nome} />
                            <Meta label="Local" value={`${rnc.contract?.city || '-'}${rnc.contract?.state ? ` / ${rnc.contract.state}` : ''}`} />
                            <Meta label="Criado por" value={rnc.creator?.name} />
                        </div>

                        <div className="mt-4 flex flex-wrap gap-3 text-[12.5px] text-[var(--ink-500)]">
                            <span className="inline-flex items-center gap-1">
                                <MapPin size={14} />
                                {rnc.latitude && rnc.longitude ? `${rnc.latitude}, ${rnc.longitude}` : 'Sem coordenada'}
                            </span>
                            <span className="inline-flex items-center gap-1">
                                <ImagePlus size={14} />
                                {rnc.photos?.length || 0} foto(s)
                            </span>
                        </div>
                    </section>

                    <section className="border-b border-[var(--border)] p-5">
                        <div className="eyebrow">Observacoes e comentarios</div>
                        <TextBlock title="Descricao do problema" value={rnc.descricao_problema} />
                        <TextBlock title="Observacao" value={rnc.observacao || 'Sem observacoes adicionais.'} />
                        <TextBlock title="Acoes corretivas recomendadas" value={rnc.acoes_corretivas_recomendadas} />
                    </section>

                    <section className="p-5">
                        <div className="eyebrow">Imagens</div>
                        {rnc.photos?.length > 0 ? (
                            <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {rnc.photos.map((photo) => (
                                    <PhotoFigure
                                        key={photo.id}
                                        photo={photo}
                                        title={`Imagem ${photo.position}`}
                                        detail={photo.comment}
                                    />
                                ))}
                            </div>
                        ) : (
                            <div className="mt-4 rounded-lg border border-dashed border-[var(--border-strong)] p-8 text-center text-sm text-[var(--ink-500)]">
                                Nenhuma imagem cadastrada nesta RNC.
                            </div>
                        )}
                    </section>

                    <section className="border-t border-[var(--border)] p-5">
                        <div className="eyebrow">Proposta de acao corretiva aprovada</div>
                        {approvedAction ? (
                            <div className="mt-4 grid gap-4">
                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                    <Meta label="Responsavel pela proposta" value={approvedAction.user?.name} />
                                    <Meta label="Enviada em" value={dateTime(approvedAction.submitted_at)} />
                                    <Meta label="Prazo execucao proposto" value={shortDate(approvedAction.prazo_execucao_proposto)} />
                                    <Meta label="Aprovada por" value={approvedAction.reviewer?.name} />
                                </div>
                                <TextBlock title="Proposta aprovada" value={approvedAction.descricao_proposta} />
                                {approvedAction.review_observation && (
                                    <TextBlock title="Observacao da analise" value={approvedAction.review_observation} />
                                )}
                            </div>
                        ) : (
                            <div className="mt-4 rounded-lg border border-dashed border-[var(--border-strong)] p-8 text-center text-sm text-[var(--ink-500)]">
                                Nenhuma proposta de acao corretiva aprovada ate o momento.
                            </div>
                        )}
                    </section>

                    <section className="border-t border-[var(--border)] p-5">
                        <div className="eyebrow">Evidencias da correcao</div>
                        {latestEvidence ? (
                            <div className="mt-4 grid gap-4">
                                <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4">
                                    <div className="grid gap-3 md:grid-cols-3">
                                        <Meta label="Enviado por" value={latestEvidence.user?.name} />
                                        <Meta label="Enviado em" value={dateTime(latestEvidence.submitted_at)} />
                                        <Meta label="Status" value="RNC finalizada" />
                                    </div>
                                    <a
                                        href={route('tenant.qualidade.rnc.evidencias.download', [tenant.slug, rnc.id, latestEvidence.id])}
                                        download
                                        className="sig-btn sig-btn-secondary mt-4"
                                    >
                                        <Download size={15} />
                                        {latestEvidence.attachment_original_name}
                                    </a>
                                </div>

                                {latestEvidence.photos?.length > 0 && (
                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                        {latestEvidence.photos.map((photo) => (
                                            <PhotoFigure
                                                key={photo.id}
                                                photo={photo}
                                                title={`Evidencia ${photo.position}`}
                                                detail={photo.comment || photo.original_name}
                                            />
                                        ))}
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="mt-4 rounded-lg border border-dashed border-[var(--border-strong)] p-8 text-center text-sm text-[var(--ink-500)]">
                                Nenhuma evidencia de correcao enviada ainda.
                            </div>
                        )}
                    </section>

                    <section className="border-t border-[var(--border)] p-5">
                        <div className="eyebrow">Fluxo da RNC</div>
                        <div className="mt-4 overflow-hidden rounded-lg border border-[var(--border)]">
                            <table className="sig-table">
                                <thead>
                                    <tr>
                                        <th>Etapa</th>
                                        <th>Status</th>
                                        <th>Data</th>
                                        <th>Responsavel</th>
                                        <th>Detalhe</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {flowRows.map((row) => (
                                        <tr key={row.etapa}>
                                            <td className="font-semibold text-[var(--ink-900)]">{row.etapa}</td>
                                            <td>
                                                <span className={`sig-pill ${statusPillClass(row.status)}`}>
                                                    {row.status}
                                                </span>
                                            </td>
                                            <td>{row.data}</td>
                                            <td>{row.responsavel}</td>
                                            <td className="text-[var(--ink-500)]">{row.detalhe}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </article>
            </section>
        </AuthenticatedLayout>
    );
}

function CompanyLogoCard({ label, company, align = 'left' }) {
    const textAlignClass = align === 'right' ? 'text-right' : 'text-left';

    return (
        <div className={`rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3 ${textAlignClass}`}>
            <div className={`flex items-center gap-3 ${align === 'right' ? 'justify-end' : ''}`}>
                {align === 'right' && (
                    <div className="min-w-0">
                        <div className="truncate text-[13px] font-semibold text-[var(--ink-900)]">{company?.sigla || company?.nome || '-'}</div>
                        <div className="truncate text-[11.5px] text-[var(--ink-500)]">{company?.nome || 'Sem empresa'}</div>
                    </div>
                )}
                <div className="flex h-16 w-28 shrink-0 items-center justify-center overflow-hidden rounded-md border border-[var(--border)] bg-white px-2">
                    {company?.logo_url ? (
                        <img src={company.logo_url} alt={`Logo ${label}`} className="max-h-full max-w-full object-contain" />
                    ) : (
                        <span className="line-clamp-3 text-center text-[10.5px] font-bold leading-tight text-[var(--ink-600)]">
                            {company?.nome || 'Empresa sem logo'}
                        </span>
                    )}
                </div>
                {align !== 'right' && (
                    <div className="min-w-0">
                        <div className="truncate text-[13px] font-semibold text-[var(--ink-900)]">{company?.sigla || company?.nome || '-'}</div>
                        <div className="truncate text-[11.5px] text-[var(--ink-500)]">{company?.nome || 'Sem empresa'}</div>
                    </div>
                )}
            </div>
        </div>
    );
}

function PhotoFigure({ photo, title, detail }) {
    return (
        <figure className="flex h-full flex-col rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-2">
            <div className="aspect-[4/3] w-full overflow-hidden rounded-md bg-white">
                <img
                    src={photo.url}
                    alt={photo.original_name || title}
                    className="h-full w-full object-cover"
                    loading="lazy"
                />
            </div>
            <figcaption className="min-h-10 pt-2 text-[12.5px] leading-5 text-[var(--ink-500)]">
                <span className="font-semibold text-[var(--ink-800)]">{title}</span>
                {detail ? ` - ${detail}` : ''}
            </figcaption>
        </figure>
    );
}

function statusPillClass(status) {
    if (['Concluida', 'Enviada', 'Aprovada', 'Finalizada'].includes(status)) {
        return ['Aprovada', 'Finalizada'].includes(status) ? 'sig-pill-green' : 'sig-pill-blue';
    }

    if (status === 'Reprovada') {
        return 'sig-pill-red';
    }

    return 'sig-pill-amber';
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
