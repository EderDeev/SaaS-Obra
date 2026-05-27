import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Cloud, Database, HardDrive, RefreshCw, Trash2, TriangleAlert } from 'lucide-react';

function formatBytes(bytes) {
    const value = Number(bytes || 0);

    if (value >= 1024 ** 3) {
        return `${(value / 1024 ** 3).toLocaleString('pt-BR', { maximumFractionDigits: 2 })} GB`;
    }

    if (value >= 1024 ** 2) {
        return `${(value / 1024 ** 2).toLocaleString('pt-BR', { maximumFractionDigits: 2 })} MB`;
    }

    if (value >= 1024) {
        return `${(value / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} KB`;
    }

    return `${value.toLocaleString('pt-BR')} B`;
}

function formatDateTime(value) {
    if (!value) {
        return 'Nao enviado';
    }

    return new Date(value).toLocaleString('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
}

function percent(value, total) {
    if (!total) {
        return 0;
    }

    return Math.min(100, Math.round((Number(value || 0) / Number(total)) * 100));
}

function fileDisplayName(version) {
    return version?.stored_name || version?.original_name || 'Arquivo sem nome';
}

function documentLabel(version) {
    const document = version.document;

    if (!document) {
        return 'Documento removido';
    }

    return document.code ? `${document.code} - ${document.title}` : document.title;
}

export default function PlatformApsIndex({ stats, liveBucket, tenantRows, recentVersions }) {
    const page = usePage();
    const estimatedUsagePercent = percent(stats.aps_source_bytes, stats.storage_limit_bytes);
    const liveUsagePercent = percent(liveBucket.total_size, liveBucket.limit_bytes);

    const deleteVersion = (version) => {
        router.delete(route('platform.aps.versions.destroy', version.id), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Uso APS" />

            <section className="sig-content grid gap-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div className="min-w-0">
                        <div className="eyebrow">Platform - Autodesk APS</div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">Uso APS</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Controle global do bucket APS, arquivos enviados para processamento e limpeza de storage.
                        </p>
                    </div>
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={() => router.reload()}>
                        <RefreshCw size={15} />
                        Atualizar
                    </button>
                </header>

                {page.props.flash.success && (
                    <div className="rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                        {page.props.flash.success}
                    </div>
                )}
                {page.props.flash.error && (
                    <div className="rounded-lg bg-[var(--red-50)] px-3 py-2 text-sm text-[var(--red)]">
                        {page.props.flash.error}
                    </div>
                )}

                <div className="grid gap-3 lg:grid-cols-4 sm:grid-cols-2">
                    <Metric icon={HardDrive} label="Storage estimado" value={formatBytes(stats.aps_source_bytes)} sub={`${estimatedUsagePercent}% de ${formatBytes(stats.storage_limit_bytes)}`} />
                    <Metric icon={Cloud} label="Bucket APS" value={liveBucket.configured ? 'Configurado' : 'Pendente'} sub={liveBucket.bucket_key || 'Configure o .env'} />
                    <Metric icon={CheckCircle2} label="Processados" value={stats.ready_count} sub={`${stats.processing_count} na fila/processando`} />
                    <Metric icon={TriangleAlert} label="Falhas APS" value={stats.failed_count} sub={`${stats.aps_versions_count} versoes no APS`} />
                </div>

                <section className="sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <Database size={14} />
                                <span className="eyebrow">Bucket em tempo real</span>
                            </div>
                            <h2 className="mt-1 text-[15px] font-semibold text-[var(--ink-900)]">
                                {liveBucket.configured ? liveBucket.bucket_key : 'APS nao configurado'}
                            </h2>
                        </div>
                        {liveBucket.region && <span className="sig-pill sig-pill-blue">Regiao {liveBucket.region}</span>}
                    </header>

                    <div className="grid gap-4 px-5 py-5 lg:grid-cols-[320px_minmax(0,1fr)]">
                        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4">
                            <div className="eyebrow">Uso lido do bucket</div>
                            <div className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">{formatBytes(liveBucket.total_size)}</div>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                {liveUsagePercent}% de {formatBytes(liveBucket.limit_bytes)}. {liveBucket.object_count} objeto(s) listados.
                            </p>
                            <div className="mt-4 h-2 overflow-hidden rounded-full bg-white">
                                <div className="h-full rounded-full bg-[var(--primary)]" style={{ width: `${liveUsagePercent}%` }} />
                            </div>
                            {liveBucket.truncated && (
                                <p className="mt-3 text-xs text-[var(--amber)]">Listagem limitada aos primeiros 500 objetos.</p>
                            )}
                        </div>

                        <div className="rounded-lg border border-[var(--border)] bg-white p-4">
                            {liveBucket.error ? (
                                <div className="flex gap-3 text-sm text-[var(--red)]">
                                    <TriangleAlert size={18} />
                                    <div>
                                        <div className="font-semibold">Nao foi possivel consultar o bucket APS.</div>
                                        <div className="mt-1">{liveBucket.error}</div>
                                    </div>
                                </div>
                            ) : liveBucket.configured ? (
                                <div className="grid gap-2 text-sm text-[var(--ink-600)]">
                                    <div><span className="font-semibold text-[var(--ink-900)]">Bucket:</span> {liveBucket.bucket_key}</div>
                                    <div><span className="font-semibold text-[var(--ink-900)]">Objetos:</span> {liveBucket.object_count}</div>
                                    <div><span className="font-semibold text-[var(--ink-900)]">Leitura:</span> OSS da Autodesk via API</div>
                                    <div className="text-xs text-[var(--ink-500)]">
                                        O valor em tempo real depende do retorno de tamanho dos objetos pelo OSS. O estimado local usa os tamanhos dos arquivos originais enviados pelo sistema.
                                    </div>
                                </div>
                            ) : (
                                <div className="text-sm text-[var(--ink-500)]">
                                    Configure `AUTODESK_APS_CLIENT_ID`, `AUTODESK_APS_CLIENT_SECRET` e `AUTODESK_APS_BUCKET_KEY` para consultar o bucket.
                                </div>
                            )}
                        </div>
                    </div>
                </section>

                <section className="grid gap-6 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                    <div className="sig-card overflow-hidden">
                        <header className="border-b border-[var(--border)] px-5 py-4">
                            <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Uso por tenant</h2>
                        </header>
                        {tenantRows.length > 0 ? (
                            <table className="sig-table">
                                <thead>
                                    <tr>
                                        <th>Tenant</th>
                                        <th>Storage</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {tenantRows.map((tenant) => (
                                        <tr key={tenant.id}>
                                            <td>
                                                <div className="font-semibold">{tenant.name}</div>
                                                <div className="mono text-xs text-[var(--ink-500)]">{tenant.slug}</div>
                                            </td>
                                            <td>
                                                <div className="font-semibold">{formatBytes(tenant.aps_source_bytes)}</div>
                                                <div className="text-xs text-[var(--ink-500)]">{tenant.aps_versions_count} versao(oes)</div>
                                            </td>
                                            <td>
                                                <span className="sig-pill sig-pill-green">{tenant.ready_count} ok</span>
                                                {tenant.failed_count > 0 && <span className="sig-pill sig-pill-red ml-1">{tenant.failed_count} falha</span>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        ) : (
                            <div className="p-8 text-sm text-[var(--ink-500)]">Nenhum arquivo enviado ao APS ainda.</div>
                        )}
                    </div>

                    <div className="sig-card overflow-hidden">
                        <header className="border-b border-[var(--border)] px-5 py-4">
                            <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Versoes no APS</h2>
                        </header>
                        {recentVersions.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="sig-table min-w-[960px]">
                                    <thead>
                                        <tr>
                                            <th>Arquivo</th>
                                            <th>Tenant</th>
                                            <th>Projeto</th>
                                            <th>Status</th>
                                            <th>Tamanho</th>
                                            <th>Acoes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {recentVersions.map((version) => (
                                            <tr key={version.id}>
                                                <td>
                                                    <div className="max-w-[240px] truncate font-semibold">{fileDisplayName(version)}</div>
                                                    <div className="mono max-w-[240px] truncate text-xs text-[var(--ink-500)]">{version.aps_object_id}</div>
                                                </td>
                                                <td>
                                                    <div className="font-semibold">{version.tenant?.name || 'Sem tenant'}</div>
                                                    <div className="mono text-xs text-[var(--ink-500)]">{version.tenant?.slug}</div>
                                                </td>
                                                <td>
                                                    <div className="max-w-[260px] truncate font-semibold">{documentLabel(version)}</div>
                                                    <div className="text-xs text-[var(--ink-500)]">
                                                        {version.document?.contract?.code || 'Sem contrato'} - {version.document?.obra?.nome || 'Sem obra'}
                                                    </div>
                                                </td>
                                                <td>
                                                    <span className="sig-pill sig-pill-blue">{version.derivative_status}</span>
                                                    <div className="mt-1 text-xs text-[var(--ink-500)]">{formatDateTime(version.submitted_to_aps_at)}</div>
                                                </td>
                                                <td>{formatBytes(version.file_size)}</td>
                                                <td className="text-right">
                                                    <div className="flex justify-end gap-2">
                                                        {version.url && (
                                                            <a href={version.url} download={fileDisplayName(version)} className="sig-btn sig-btn-secondary sig-btn-sm">
                                                                Baixar local
                                                            </a>
                                                        )}
                                                        <ConfirmActionButton
                                                            title="Limpar arquivo do APS"
                                                            message={`Deseja remover ${fileDisplayName(version)} do bucket APS? O arquivo local e o historico do sistema serao mantidos.`}
                                                            confirmLabel="Limpar APS"
                                                            onConfirm={() => deleteVersion(version)}
                                                        >
                                                            <Trash2 size={13} />
                                                            Limpar APS
                                                        </ConfirmActionButton>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="p-8 text-sm text-[var(--ink-500)]">Nenhuma versao com APS registrada.</div>
                        )}
                    </div>
                </section>

                <section className="sig-card overflow-hidden">
                    <header className="border-b border-[var(--border)] px-5 py-4">
                        <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Objetos lidos do bucket</h2>
                    </header>
                    {liveBucket.objects.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="sig-table min-w-[920px]">
                                <thead>
                                    <tr>
                                        <th>Object key</th>
                                        <th>Tamanho</th>
                                        <th>SHA1</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {liveBucket.objects.slice(0, 80).map((object, index) => (
                                        <tr key={`${object.object_key}-${index}`}>
                                            <td>
                                                <div className="mono max-w-[640px] truncate text-xs">{object.object_key || object.object_id || 'Sem chave'}</div>
                                            </td>
                                            <td>{formatBytes(object.size)}</td>
                                            <td><span className="mono text-xs text-[var(--ink-500)]">{object.sha1 || '-'}</span></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="p-8 text-sm text-[var(--ink-500)]">
                            {liveBucket.configured && !liveBucket.error ? 'Nenhum objeto retornado pelo bucket.' : 'Aguardando configuracao APS para listar objetos.'}
                        </div>
                    )}
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function Metric({ icon: Icon, label, value, sub }) {
    return (
        <div className="sig-card p-[18px]">
            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                <Icon size={14} />
                <span className="eyebrow">{label}</span>
            </div>
            <div className="mono mt-2 text-[28px] font-semibold">{value}</div>
            <p className="text-[12.5px] text-[var(--ink-500)]">{sub}</p>
        </div>
    );
}
