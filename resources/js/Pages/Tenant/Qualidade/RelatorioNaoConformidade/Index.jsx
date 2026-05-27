import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Bell, CheckCircle2, ClipboardCheck, ClipboardX, Download, Eye, ImagePlus, MapPin, Pencil, Plus, SearchCheck, Trash2 } from 'lucide-react';

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
    if (!date) return 'sem data';

    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date));
};

const pdfUrl = (tenant, rnc) => `${route('tenant.qualidade.rnc.pdf', [tenant.slug, rnc.id])}?v=${encodeURIComponent(rnc.updated_at || rnc.notified_at || rnc.id)}`;

const RNC_PERMISSION = {
    create: 'create_rnc',
    notify: 'notify_rnc',
    correctiveAction: 'corrective_action_rnc',
    edit: 'edit_rnc',
    delete: 'delete_rnc',
    review: 'review_rnc',
    evidence: 'evidence_rnc',
};

const can = (rnc, permission) => rnc.user_permissions?.includes(permission);

export default function RelatorioNaoConformidadeIndex({ tenant, rncs, canCreateRnc }) {
    const page = usePage();

    const actionButton = (rnc) => {
        const latestAction = rnc.acoes_corretivas?.[0];

        if (rnc.status === 'finalizada' || rnc.finalized_at || rnc.evidencias_count > 0) {
            return (
                <span className="sig-pill sig-pill-green">
                    <CheckCircle2 size={13} />
                    Finalizada
                </span>
            );
        }

        if (!rnc.notified_at) {
            if (!can(rnc, RNC_PERMISSION.notify)) {
                return null;
            }

            return (
                <Link
                    href={route('tenant.qualidade.rnc.notify', [tenant.slug, rnc.id])}
                    method="post"
                    as="button"
                    className="sig-btn sig-btn-secondary sig-btn-sm"
                    preserveScroll
                >
                    <Bell size={13} />
                    Notificar
                </Link>
            );
        }

        if (!latestAction || latestAction.status === 'rejected') {
            if (!can(rnc, RNC_PERMISSION.correctiveAction)) {
                return null;
            }

            return (
                <Link
                    href={route('tenant.qualidade.rnc.acao-corretiva.create', [tenant.slug, rnc.id])}
                    className="sig-btn sig-btn-secondary sig-btn-sm"
                >
                    <ClipboardCheck size={13} />
                    Acao corretiva
                </Link>
            );
        }

        if (latestAction.status === 'pending') {
            if (!can(rnc, RNC_PERMISSION.review)) {
                return <span className="sig-pill sig-pill-amber">Em analise</span>;
            }

            return (
                <Link
                    href={route('tenant.qualidade.rnc.analisar-proposta.create', [tenant.slug, rnc.id])}
                    className="sig-btn sig-btn-secondary sig-btn-sm"
                >
                    <SearchCheck size={13} />
                    Analisar Proposta
                </Link>
            );
        }

        if (latestAction.status === 'approved') {
            if (!can(rnc, RNC_PERMISSION.evidence)) {
                return <span className="sig-pill sig-pill-blue">Aguardando evidencia</span>;
            }

            return (
                <Link
                    href={route('tenant.qualidade.rnc.evidencias.create', [tenant.slug, rnc.id])}
                    className="sig-btn sig-btn-secondary sig-btn-sm"
                >
                    <ImagePlus size={13} />
                    Evidenciar
                </Link>
            );
        }

        return (
            <span className="sig-pill sig-pill-blue">
                <CheckCircle2 size={13} />
                Proposta aprovada
            </span>
        );
    };

    return (
        <AuthenticatedLayout>
            <Head title="Relatorio Nao Conformidade" />

            <section className="sig-content">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <ClipboardX size={14} />
                            <span className="eyebrow">Qualidade</span>
                        </div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">Relatorio Nao Conformidade</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            {rncs.length} RNCs cadastradas em {tenant.name}
                        </p>
                    </div>
                    {canCreateRnc && (
                        <Link href={route('tenant.qualidade.rnc.create', tenant.slug)} className="sig-btn sig-btn-primary">
                            <Plus size={15} />
                            Nova RNC
                        </Link>
                    )}
                </div>

                {page.props.flash.success && (
                    <div className="mb-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                        {page.props.flash.success}
                    </div>
                )}

                {rncs.length > 0 ? (
                    <section className="sig-card overflow-hidden">
                        <table className="sig-table">
                            <thead>
                                <tr>
                                    <th>RNC</th>
                                    <th>Natureza / Gravidade</th>
                                    <th>Obra</th>
                                    <th>Empresas</th>
                                    <th>Abertura</th>
                                    <th>Status</th>
                                    <th className="text-right">Acoes</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rncs.map((rnc) => (
                                    <tr key={rnc.id}>
                                        <td>
                                            <span className="mono font-semibold text-[var(--ink-900)]">{rnc.formatted_number}</span>
                                        </td>
                                        <td>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="sig-pill sig-pill-blue">{rnc.natureza}</span>
                                                <span className={`sig-pill ${gravityClass[rnc.gravidade] || ''}`}>{rnc.gravidade}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div className="font-semibold text-[var(--ink-900)]">
                                                {rnc.obra?.codigo} - {rnc.obra?.nome}
                                            </div>
                                            <div className="mono mt-1 text-[12px] text-[var(--ink-500)]">
                                                {rnc.contract?.code}
                                            </div>
                                            <div className="mt-2 flex flex-wrap items-center gap-3 text-[12px] text-[var(--ink-500)]">
                                                <span className="inline-flex items-center gap-1">
                                                    <MapPin size={13} />
                                                    {rnc.latitude && rnc.longitude ? `${rnc.latitude}, ${rnc.longitude}` : 'Sem coordenada'}
                                                </span>
                                                <span className="inline-flex items-center gap-1">
                                                    <ImagePlus size={13} />
                                                    {rnc.photos_count || 0} foto(s)
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <Meta label="Contratante" value={rnc.contratante?.sigla || rnc.contratante?.nome} />
                                            <div className="mt-2">
                                                <Meta label="Contratada" value={rnc.contratada?.sigla || rnc.contratada?.nome} />
                                            </div>
                                        </td>
                                        <td>{shortDate(rnc.opened_at)}</td>
                                        <td>
                                            <span className="sig-pill">{rnc.status}</span>
                                        </td>
                                        <td>
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Link
                                                    href={route('tenant.qualidade.rnc.show', [tenant.slug, rnc.id])}
                                                    className="sig-btn sig-btn-secondary sig-btn-sm"
                                                >
                                                    <Eye size={13} />
                                                    Abrir
                                                </Link>
                                                {can(rnc, RNC_PERMISSION.edit) && (
                                                    <Link
                                                        href={route('tenant.qualidade.rnc.edit', [tenant.slug, rnc.id])}
                                                        className="sig-btn sig-btn-secondary sig-btn-sm"
                                                    >
                                                        <Pencil size={13} />
                                                        Editar
                                                    </Link>
                                                )}
                                                {actionButton(rnc)}
                                                <a
                                                    href={pdfUrl(tenant, rnc)}
                                                    className="sig-btn sig-btn-secondary sig-btn-sm"
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    <Download size={13} />
                                                    PDF
                                                </a>
                                                {can(rnc, RNC_PERMISSION.delete) && (
                                                    <ConfirmActionButton
                                                        title="Excluir RNC"
                                                        message={`Deseja mesmo excluir a RNC ${rnc.formatted_number}? Ela saira da listagem, mas o historico sera mantido.`}
                                                        confirmLabel="Excluir RNC"
                                                        onConfirm={() => router.delete(route('tenant.qualidade.rnc.destroy', [tenant.slug, rnc.id]), { preserveScroll: true })}
                                                    >
                                                        <Trash2 size={13} />
                                                        Excluir
                                                    </ConfirmActionButton>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                ) : (
                    <div className="sig-card p-12 text-center">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-[var(--surface-muted)] text-[var(--ink-500)]">
                            <ClipboardX size={22} />
                        </div>
                        <h2 className="mt-4 text-[16px] font-semibold text-[var(--ink-900)]">Nenhuma RNC cadastrada</h2>
                        <p className="mx-auto mt-1 max-w-md text-sm text-[var(--ink-500)]">
                            Crie uma RNC para acompanhar status, gravidade, prazo e gerar o PDF do registro.
                        </p>
                        {canCreateRnc && (
                            <Link href={route('tenant.qualidade.rnc.create', tenant.slug)} className="sig-btn sig-btn-primary mt-5">
                                <Plus size={15} />
                                Nova RNC
                            </Link>
                        )}
                    </div>
                )}
            </section>
        </AuthenticatedLayout>
    );
}

function Meta({ label, value }) {
    return (
        <div>
            <div className="eyebrow">{label}</div>
            <div className="mt-1 truncate text-[13px] font-semibold text-[var(--ink-800)]">{value || '-'}</div>
        </div>
    );
}
