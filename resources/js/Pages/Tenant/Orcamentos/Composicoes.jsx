import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Building2, CheckCircle2, Clock3, Eye, FileSpreadsheet, Globe2, Plus, Search, UploadCloud, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import OrcamentoShell from './Partials/OrcamentoShell';

const states = [
    { value: 'AC', label: 'Acre' },
    { value: 'AL', label: 'Alagoas' },
    { value: 'AP', label: 'Amapa' },
    { value: 'AM', label: 'Amazonas' },
    { value: 'BA', label: 'Bahia' },
    { value: 'CE', label: 'Ceara' },
    { value: 'DF', label: 'Distrito Federal' },
    { value: 'ES', label: 'Espirito Santo' },
    { value: 'GO', label: 'Goias' },
    { value: 'MA', label: 'Maranhao' },
    { value: 'MT', label: 'Mato Grosso' },
    { value: 'MS', label: 'Mato Grosso do Sul' },
    { value: 'MG', label: 'Minas Gerais' },
    { value: 'PA', label: 'Para' },
    { value: 'PB', label: 'Paraiba' },
    { value: 'PR', label: 'Parana' },
    { value: 'PE', label: 'Pernambuco' },
    { value: 'PI', label: 'Piaui' },
    { value: 'RJ', label: 'Rio de Janeiro' },
    { value: 'RN', label: 'Rio Grande do Norte' },
    { value: 'RS', label: 'Rio Grande do Sul' },
    { value: 'RO', label: 'Rondonia' },
    { value: 'RR', label: 'Roraima' },
    { value: 'SC', label: 'Santa Catarina' },
    { value: 'SP', label: 'Sao Paulo' },
    { value: 'SE', label: 'Sergipe' },
    { value: 'TO', label: 'Tocantins' },
];

const baseOptions = [
    { value: 'SINAPI', label: 'SINAPI' },
    { value: 'SICRO', label: 'SICRO' },
    { value: 'PROPRIA', label: 'Base propria' },
];

const orderOptions = [
    { value: 'code', label: 'Codigo' },
    { value: 'description', label: 'Descricao' },
    { value: 'unit', label: 'Unidade' },
];

const modelOptions = [
    { value: 'SINAPI', label: 'SINAPI' },
    { value: 'SICRO', label: 'SICRO' },
    { value: 'PROPRIA', label: 'Base propria' },
];

export default function OrcamentosComposicoes({
    tenant,
    filters: initialFilters = {},
    hasSearched = false,
    composicoes = [],
    totalComposicoes = 0,
    canManageTenantComposicoes = false,
    canManageGlobalComposicoes = false,
    typeOptions = [],
}) {
    const page = usePage();
    const compositionRows = composicoes?.data ?? composicoes;
    const pagination = composicoes?.data ? composicoes : null;
    const [filters, setFilters] = useState({
        search: initialFilters.search ?? '',
        type: initialFilters.type ?? 'all',
        orderBy: initialFilters.orderBy ?? 'code',
        base: initialFilters.base ?? 'SINAPI',
        baseScope: initialFilters.baseScope ?? 'official',
        state: initialFilters.state ?? 'PA',
        perPage: initialFilters.perPage ?? 50,
    });
    const [activePanel, setActivePanel] = useState(null);
    const tenantImportForm = useForm({
        scope: 'tenant',
        modelo: 'SINAPI',
        file: null,
    });
    const globalImportForm = useForm({
        scope: 'global',
        modelo: 'SINAPI',
        file: null,
    });
    const analyticImportForm = useForm({
        scope: 'tenant',
        modelo: 'SINAPI',
        file: null,
    });
    const [currentImportLabel, setCurrentImportLabel] = useState(null);
    const activeImportForm = tenantImportForm.processing
        ? tenantImportForm
        : globalImportForm.processing
            ? globalImportForm
            : analyticImportForm.processing
                ? analyticImportForm
                : null;

    const visiblePanels = useMemo(() => ({
        create: canManageTenantComposicoes,
        importTenant: canManageTenantComposicoes,
        importGlobal: canManageGlobalComposicoes,
        importAnalytic: canManageTenantComposicoes || canManageGlobalComposicoes,
    }), [canManageGlobalComposicoes, canManageTenantComposicoes]);
    const updateFilter = (field, value) => {
        setFilters((current) => ({ ...current, [field]: value }));
    };

    const submitSearch = (event) => {
        event.preventDefault();

        router.get(route('tenant.orcamentos.composicoes.index', tenant.slug), { ...filters, searched: 1 }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const togglePanel = (panel) => {
        setActivePanel((current) => (current === panel ? null : panel));
    };

    const submitImport = (event, form, label) => {
        event.preventDefault();
        setCurrentImportLabel(label);

        form.post(route('tenant.orcamentos.composicoes.import', tenant.slug), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setActivePanel(null);
            },
            onFinish: () => setCurrentImportLabel(null),
        });
    };

    const submitAnalyticImport = (event) => {
        event.preventDefault();
        setCurrentImportLabel('Importacao analitica de composicoes');

        analyticImportForm.post(route('tenant.orcamentos.composicoes.import-analitico', tenant.slug), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                analyticImportForm.reset();
                setActivePanel(null);
            },
            onFinish: () => setCurrentImportLabel(null),
        });
    };

    return (
        <OrcamentoShell
            tenant={tenant}
            active="composicoes"
            title="Composicoes"
            subtitle="Pesquise composicoes oficiais ou proprias para estruturar servicos, coeficientes e custos do orcamento."
            showNav={false}
        >
            {page.props.flash?.success && (
                <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                    {page.props.flash.success}
                </div>
            )}

            {activeImportForm && (
                <ImportProgressOverlay form={activeImportForm} title={currentImportLabel ?? 'Importando arquivo'} />
            )}

            {page.props.flash?.import_result && (
                <ImportResultFeedback result={page.props.flash.import_result} />
            )}

            <section className="mb-5 flex flex-wrap items-center gap-2">
                {visiblePanels.create && (
                    <ActionLink href={route('tenant.orcamentos.composicoes.create', tenant.slug)} icon={Plus} tone="blue">
                        Criar composicao
                    </ActionLink>
                )}

                {visiblePanels.importTenant && (
                    <ActionButton active={activePanel === 'importTenant'} icon={Building2} tone="green" onClick={() => togglePanel('importTenant')}>
                        Importar Tenant
                    </ActionButton>
                )}

                {visiblePanels.importGlobal && (
                    <ActionButton active={activePanel === 'importGlobal'} icon={Globe2} tone="amber" onClick={() => togglePanel('importGlobal')}>
                        Importar global
                    </ActionButton>
                )}

                {visiblePanels.importAnalytic && (
                    <ActionButton active={activePanel === 'importAnalytic'} icon={FileSpreadsheet} tone="violet" onClick={() => togglePanel('importAnalytic')}>
                        Importar analitico
                    </ActionButton>
                )}
            </section>

            {activePanel === 'importTenant' && (
                <ImportCompositionPanel
                    description="As composicoes importadas ficarao disponiveis apenas para este tenant."
                    form={tenantImportForm}
                    icon={Building2}
                    onClose={() => setActivePanel(null)}
                    onSubmit={(event) => submitImport(event, tenantImportForm, 'Importacao de composicoes do tenant')}
                    title="Importar composicoes do tenant"
                />
            )}

            {activePanel === 'importGlobal' && (
                <ImportCompositionPanel
                    description="As composicoes importadas ficarao disponiveis para todos os tenants. Use apenas bases oficiais ou corporativas."
                    form={globalImportForm}
                    icon={Globe2}
                    onClose={() => setActivePanel(null)}
                    onSubmit={(event) => submitImport(event, globalImportForm, 'Importacao global de composicoes')}
                    title="Importar composicoes globais"
                />
            )}

            {activePanel === 'importAnalytic' && (
                <ImportAnalyticPanel
                    canManageGlobal={canManageGlobalComposicoes}
                    canManageTenant={canManageTenantComposicoes}
                    form={analyticImportForm}
                    onClose={() => setActivePanel(null)}
                    onSubmit={submitAnalyticImport}
                />
            )}

            <CompositionSearchPanel
                filters={filters}
                typeOptions={typeOptions}
                onChange={updateFilter}
                onSubmit={submitSearch}
            />

            <ComposicoesList
                composicoes={compositionRows}
                filters={filters}
                hasSearched={hasSearched}
                pagination={pagination}
                setFilters={setFilters}
                tenant={tenant}
                totalComposicoes={totalComposicoes}
            />
        </OrcamentoShell>
    );
}

function ImportProgressOverlay({ form, title }) {
    const progress = Math.max(0, Math.min(100, Math.round(form.progress?.percentage ?? 100)));
    const isUploading = progress < 100;

    return (
        <section className="mb-5 overflow-hidden rounded-lg border border-blue-200 bg-white shadow-[var(--shadow-sm)]">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-blue-100 bg-blue-50 px-4 py-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-white text-blue-700 shadow-sm">
                        {isUploading ? <UploadCloud size={17} /> : <Clock3 size={17} />}
                    </span>
                    <div>
                        <h2 className="text-sm font-bold text-[var(--ink-900)]">{title}</h2>
                        <p className="mt-0.5 text-xs text-[var(--ink-500)]">
                            {isUploading
                                ? 'Enviando arquivo para o servidor.'
                                : 'Upload concluido. Processando e gravando os lotes no PostgreSQL.'}
                        </p>
                    </div>
                </div>
                <span className="rounded-full bg-white px-3 py-1 text-xs font-bold text-blue-700">
                    {isUploading ? `${progress}%` : 'Processando'}
                </span>
            </header>

            <div className="p-4">
                <div className="h-3 overflow-hidden rounded-full bg-blue-100">
                    <div
                        className="h-full rounded-full bg-blue-600 transition-all duration-300"
                        style={{ width: `${progress}%` }}
                    />
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-[var(--ink-500)]">
                    <CheckCircle2 size={14} className={progress >= 100 ? 'text-emerald-600' : 'text-blue-600'} />
                    <span>
                        Mantenha esta tela aberta ate o resumo final aparecer.
                    </span>
                </div>
            </div>
        </section>
    );
}

function ImportResultFeedback({ result }) {
    const duplicated = Number(result.duplicated ?? result.duplicates ?? 0);
    const total = Number(result.read ?? 0) || Number(result.created ?? 0) + Number(result.updated ?? 0) + duplicated + Number(result.skipped ?? 0);
    const metrics = [
        { label: 'Criadas', value: result.created ?? 0, tone: 'text-emerald-700 bg-emerald-50 border-emerald-200' },
        { label: 'Atualizadas', value: result.updated ?? 0, tone: 'text-blue-700 bg-blue-50 border-blue-200' },
        { label: 'Duplicadas ignoradas', value: duplicated, tone: 'text-violet-700 bg-violet-50 border-violet-200' },
        { label: 'Linhas ignoradas', value: result.skipped ?? 0, tone: 'text-amber-700 bg-amber-50 border-amber-200' },
        { label: 'Total lido', value: total, tone: 'text-[var(--ink-700)] bg-[var(--surface-muted)] border-[var(--border)]' },
    ];

    return (
        <section className="mb-5 rounded-lg border border-emerald-200 bg-white p-4 shadow-[var(--shadow-sm)]">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-bold text-[var(--ink-900)]">{result.title ?? 'Resumo da importacao'}</h2>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                        Escopo: <strong>{result.scope_label ?? '-'}</strong>
                        {' '}| Base: <strong>{result.base ?? '-'}</strong>
                    </p>
                </div>
                <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
                    Concluido
                </span>
            </div>

            <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                {metrics.map((metric) => (
                    <div key={metric.label} className={`rounded-lg border px-4 py-3 ${metric.tone}`}>
                        <span className="block text-[11px] font-bold uppercase tracking-[0.08em]">{metric.label}</span>
                        <strong className="mt-1 block text-2xl">{metric.value}</strong>
                    </div>
                ))}
            </div>
        </section>
    );
}

function ComposicoesList({ composicoes, filters, hasSearched, pagination, setFilters, tenant, totalComposicoes }) {
    const from = pagination?.from ?? (composicoes.length ? 1 : 0);
    const to = pagination?.to ?? composicoes.length;
    const filteredTotal = pagination?.total ?? composicoes.length;
    const updatePerPage = (perPage) => {
        const nextFilters = { ...filters, perPage };

        setFilters(nextFilters);
        router.get(route('tenant.orcamentos.composicoes.index', tenant.slug), { ...nextFilters, searched: 1 }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    return (
        <section className="sig-card overflow-hidden">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                <div className="min-w-0">
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Composicoes cadastradas</h2>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                        {hasSearched
                            ? `${totalComposicoes} composicao(oes) disponiveis. Exibindo ${from} a ${to} de ${filteredTotal} resultado(s) filtrado(s).`
                            : `${totalComposicoes} composicao(oes) disponiveis. Informe UF, base e clique em Buscar para listar.`}
                    </p>
                </div>
                {hasSearched && (
                    <>
                        <span className="sig-pill sig-pill-blue inline-flex items-center gap-1">
                            Pagina {pagination?.current_page ?? 1} de {pagination?.last_page ?? 1}
                        </span>
                        <label className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.04em] text-[var(--ink-500)]">
                            Itens por pagina
                            <select
                                className="sig-input !h-9 !min-h-9 !w-24 !px-3 !text-sm !normal-case !tracking-normal"
                                value={filters.perPage}
                                onChange={(event) => updatePerPage(event.target.value)}
                            >
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </>
                )}
            </header>

            {!hasSearched ? (
                <div className="p-8 text-center text-sm text-[var(--ink-500)]">
                    Preencha os filtros e clique em Buscar para carregar a listagem de composicoes.
                </div>
            ) : composicoes.length === 0 ? (
                <div className="p-8 text-center text-sm text-[var(--ink-500)]">
                    Nenhuma composicao encontrada para os filtros informados.
                </div>
            ) : (
                <>
                    <div className="hidden [@media(min-width:1700px)]:block">
                        <table className="w-full table-fixed border-collapse text-left">
                            <colgroup>
                                <col className="w-[9%]" />
                                <col className="w-[30%]" />
                                <col className="w-[14%]" />
                                <col className="w-[7%]" />
                                <col className="w-[9%]" />
                                <col className="w-[6%]" />
                                <col className="w-[10%]" />
                                <col className="w-[10%]" />
                                <col className="w-[5%]" />
                            </colgroup>
                            <thead>
                                <tr className="bg-[var(--ink-900)] text-white">
                                    <TableHeader>CODIGO</TableHeader>
                                    <TableHeader>DESCRICAO</TableHeader>
                                    <TableHeader>TIPO</TableHeader>
                                    <TableHeader>UNID.</TableHeader>
                                    <TableHeader>ESTADO</TableHeader>
                                    <TableHeader className="text-center">ITENS</TableHeader>
                                    <TableHeader className="text-right">PRECO ONERADO</TableHeader>
                                    <TableHeader className="text-right">PRECO DESONERADO</TableHeader>
                                    <TableHeader className="text-right">ACOES</TableHeader>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[var(--border)] bg-white">
                                {composicoes.map((composicao) => (
                                    <tr key={composicao.id} className="transition-colors hover:bg-[var(--primary-50)]/50">
                                        <TableCell className="font-mono text-[13px] font-semibold">
                                            <Link
                                                className="text-[var(--primary)] underline-offset-4 transition hover:underline"
                                                href={route('tenant.orcamentos.composicoes.show', [tenant.slug, composicao.id])}
                                            >
                                                {composicao.codigo}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex min-w-0 flex-col gap-1">
                                                <span className="text-[13px] font-semibold leading-6 text-[var(--ink-900)]">
                                                    {composicao.descricao}
                                                </span>
                                                <span className="text-[11px] font-medium text-[var(--ink-400)]">
                                                    {composicao.modelo} - {firstReferenceLabel(composicao)}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-[12px] text-[var(--ink-700)]">{composicao.tipo_composicao}</TableCell>
                                        <TableCell className="font-semibold text-[var(--ink-800)]">{composicao.unidade}</TableCell>
                                        <TableCell>{composicao.estado_label}</TableCell>
                                        <TableCell className="text-center font-semibold">{composicao.items_count ?? 0}</TableCell>
                                        <TableCell className="text-right font-mono">{formatCurrency(composicao.preco_onerado)}</TableCell>
                                        <TableCell className="text-right font-mono">{formatCurrency(composicao.preco_desonerado)}</TableCell>
                                        <TableCell className="text-right">
                                            <OpenButton compact composicao={composicao} tenant={tenant} />
                                        </TableCell>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="grid gap-3 bg-[var(--surface-muted)] p-3 [@media(min-width:1700px)]:hidden">
                        {composicoes.map((composicao) => (
                            <article key={composicao.id} className="rounded-lg border border-[var(--border)] bg-white p-4 shadow-[var(--shadow-sm)]">
                                <div className="flex min-w-0 flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <span className="text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--ink-400)]">Codigo</span>
                                        <Link
                                            className="break-words font-mono text-[14px] font-semibold text-[var(--primary)] underline-offset-4 hover:underline"
                                            href={route('tenant.orcamentos.composicoes.show', [tenant.slug, composicao.id])}
                                        >
                                            {composicao.codigo}
                                        </Link>
                                    </div>
                                    <OpenButton composicao={composicao} tenant={tenant} />
                                </div>
                                <h3 className="mt-3 break-words text-[15px] font-bold text-[var(--ink-900)]">{composicao.descricao}</h3>
                                <p className="mt-1 break-words text-xs text-[var(--ink-500)]">{composicao.tipo_composicao}</p>
                                <div className="mt-3 grid gap-3 border-t border-[var(--border)] pt-3 text-sm sm:grid-cols-2">
                                    <MobileMetric label="Unidade" value={composicao.unidade} />
                                    <MobileMetric label="Estado" value={composicao.estado_label} />
                                    <MobileMetric label="Itens" value={composicao.items_count ?? 0} />
                                    <MobileMetric label="Onerado" value={formatCurrency(composicao.preco_onerado)} />
                                    <MobileMetric label="Desonerado" value={formatCurrency(composicao.preco_desonerado)} />
                                </div>
                            </article>
                        ))}
                    </div>
                </>
            )}

            {hasSearched && pagination && (
                <Pagination pagination={pagination} />
            )}
        </section>
    );
}

function OpenButton({ compact = false, composicao, tenant }) {
    return (
        <Link
            className={`inline-flex min-h-8 items-center justify-center gap-1 rounded-md border border-[var(--border)] bg-white text-xs font-bold text-[var(--primary)] transition hover:bg-[var(--primary-50)] ${
                compact ? 'w-9 px-0' : 'px-3'
            }`}
            href={route('tenant.orcamentos.composicoes.show', [tenant.slug, composicao.id])}
            title="Abrir"
        >
            <Eye size={13} />
            {!compact && 'Abrir'}
        </Link>
    );
}

function Pagination({ pagination }) {
    const goTo = (url) => {
        if (url) {
            router.get(url, {}, { preserveScroll: true, preserveState: true });
        }
    };

    return (
        <footer className="flex flex-col gap-3 border-t border-[var(--border)] bg-white px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex flex-wrap items-center gap-2">
                <button
                    className={`sig-btn sig-btn-secondary min-h-9 ${!pagination.prev_page_url ? 'cursor-not-allowed opacity-45' : ''}`}
                    disabled={!pagination.prev_page_url}
                    type="button"
                    onClick={() => goTo(pagination.prev_page_url)}
                >
                    Anterior
                </button>
                <button
                    className={`sig-btn sig-btn-primary min-h-9 ${!pagination.next_page_url ? 'cursor-not-allowed opacity-45' : ''}`}
                    disabled={!pagination.next_page_url}
                    type="button"
                    onClick={() => goTo(pagination.next_page_url)}
                >
                    Proxima
                </button>
                <span className="text-xs font-medium text-[var(--ink-500)]">
                    Pagina {pagination.current_page} de {pagination.last_page}
                </span>
            </div>

            <div className="flex flex-wrap items-center gap-1">
                {pagination.links
                    .filter((link) => !String(link.label).includes('Previous') && !String(link.label).includes('Next'))
                    .map((link, index) => {
                        const label = paginationLabel(link.label);

                        return (
                            <button
                                key={`${link.label}-${index}`}
                                className={`min-h-8 rounded-md border px-3 text-xs font-bold transition ${
                                    link.active
                                        ? 'border-[var(--primary)] bg-[var(--primary)] text-white'
                                        : 'border-[var(--border)] bg-white text-[var(--ink-600)] hover:bg-[var(--primary-50)]'
                                } ${!link.url ? 'cursor-not-allowed opacity-45' : ''}`}
                                disabled={!link.url}
                                type="button"
                                onClick={() => goTo(link.url)}
                            >
                                {label}
                            </button>
                        );
                    })}
            </div>
        </footer>
    );
}

function paginationLabel(label) {
    return String(label)
        .replace('&laquo; Previous', 'Anterior')
        .replace('Next &raquo;', 'Proxima')
        .replace('&laquo;', '')
        .replace('&raquo;', '');
}

function ActionLink({ children, href, icon: Icon, tone }) {
    const palette = actionTone(tone);

    return (
        <Link
            className="inline-flex min-h-10 items-center gap-2 rounded-lg border px-4 text-sm font-bold transition hover:-translate-y-0.5"
            href={href}
            style={{
                background: palette.background,
                borderColor: palette.border,
                color: palette.color,
                boxShadow: '0 10px 20px rgba(15, 23, 42, 0.12)',
            }}
        >
            <Icon size={15} />
            {children}
        </Link>
    );
}

function ActionButton({ active, children, icon: Icon, onClick, tone }) {
    const palette = actionTone(tone);

    return (
        <button
            className="inline-flex min-h-10 items-center gap-2 rounded-lg border px-4 text-sm font-bold transition hover:-translate-y-0.5"
            style={{
                background: active ? palette.background : palette.softBackground,
                borderColor: active ? palette.border : palette.softBorder,
                color: active ? palette.color : palette.softColor,
                boxShadow: active ? '0 10px 20px rgba(15, 23, 42, 0.12)' : 'var(--shadow-sm)',
            }}
            type="button"
            onClick={onClick}
        >
            <Icon size={15} />
            {children}
        </button>
    );
}

function actionTone(tone) {
    const tones = {
        blue: {
            background: 'var(--primary)',
            border: 'var(--primary)',
            color: '#fff',
            softBackground: 'var(--primary-50)',
            softBorder: 'var(--border)',
            softColor: 'var(--primary)',
        },
        green: {
            background: '#059669',
            border: '#059669',
            color: '#fff',
            softBackground: '#ecfdf5',
            softBorder: '#bbf7d0',
            softColor: '#047857',
        },
        amber: {
            background: '#d97706',
            border: '#d97706',
            color: '#fff',
            softBackground: '#fffbeb',
            softBorder: '#fde68a',
            softColor: '#b45309',
        },
        violet: {
            background: '#6d28d9',
            border: '#6d28d9',
            color: '#fff',
            softBackground: '#f5f3ff',
            softBorder: '#ddd6fe',
            softColor: '#5b21b6',
        },
    };

    return tones[tone] ?? tones.blue;
}

function ImportCompositionPanel({ description, form, icon: Icon, onClose, onSubmit, title }) {
    return (
        <section className="mb-5 overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-[var(--shadow-sm)]">
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                <div className="flex items-start gap-3">
                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                        <Icon size={17} />
                    </span>
                    <div>
                        <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">{title}</h2>
                        <p className="mt-1 text-xs leading-5 text-[var(--ink-500)]">{description}</p>
                    </div>
                </div>
                <button
                    className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--border)] bg-white text-[var(--ink-500)] transition hover:bg-[var(--primary-50)] hover:text-[var(--primary)]"
                    type="button"
                    onClick={onClose}
                    aria-label="Fechar painel"
                >
                    <X size={16} />
                </button>
            </header>

            <form className="grid gap-4 p-5" onSubmit={onSubmit}>
                <div className="grid gap-4 lg:grid-cols-[220px_1fr]">
                    <label className="block">
                        <span className="mb-1 block text-xs font-bold text-[var(--ink-500)]">Base da importacao</span>
                        <select
                            className="sig-input"
                            value={form.data.modelo}
                            onChange={(event) => form.setData('modelo', event.target.value)}
                        >
                            {modelOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        {form.errors.modelo && <span className="mt-1 block text-xs font-semibold text-rose-600">{form.errors.modelo}</span>}
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-bold text-[var(--ink-500)]">Arquivo CSV</span>
                        <input
                            accept=".csv,.txt,.tsv"
                            className="sig-input"
                            type="file"
                            onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                        />
                        {form.errors.file && <span className="mt-1 block text-xs font-semibold text-rose-600">{form.errors.file}</span>}
                    </label>
                </div>

                <div className="rounded-lg border border-dashed border-[var(--border-strong)] bg-[var(--surface-muted)] px-4 py-3 text-xs leading-5 text-[var(--ink-500)]">
                    <strong className="text-[var(--ink-700)]">Colunas obrigatorias:</strong>{' '}
                    grupo, codigo, descricao, unidade, uf, preco_nao_desonerado, preco_desonerado, data.
                    <br />
                    A coluna <strong>data</strong> e obrigatoria e pode vir como <strong>04/26</strong>, <strong>04/2026</strong> ou <strong>2026-04-01</strong>. Valores com virgula decimal serao convertidos automaticamente.
                    <br />
                    Limite por importacao: <strong>100 MB</strong>. Linhas duplicadas no mesmo arquivo serao ignoradas, sem atualizar registros.
                </div>

                <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] pt-4">
                    <button className="sig-btn sig-btn-secondary" type="button" onClick={onClose}>
                        Cancelar
                    </button>
                    <button className="sig-btn sig-btn-primary" disabled={form.processing || !form.data.file} type="submit">
                        <UploadCloud size={15} />
                        {form.processing ? 'Importando...' : 'Importar CSV'}
                    </button>
                </div>
            </form>
        </section>
    );
}

function ImportAnalyticPanel({ canManageGlobal, canManageTenant, form, onClose, onSubmit }) {
    return (
        <section className="mb-5 overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-[var(--shadow-sm)]">
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                <div className="flex items-start gap-3">
                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-50 text-violet-700">
                        <FileSpreadsheet size={17} />
                    </span>
                    <div>
                        <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Importar analitico de composicoes</h2>
                        <p className="mt-1 text-xs leading-5 text-[var(--ink-500)]">
                            Vincula composicoes, insumos e subcomposicoes por codigo. Esse arquivo monta a estrutura analitica da composicao.
                        </p>
                    </div>
                </div>
                <button
                    className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--border)] bg-white text-[var(--ink-500)] transition hover:bg-[var(--primary-50)] hover:text-[var(--primary)]"
                    type="button"
                    onClick={onClose}
                    aria-label="Fechar painel"
                >
                    <X size={16} />
                </button>
            </header>

            <form className="grid gap-4 p-5" onSubmit={onSubmit}>
                <div className="grid gap-4 lg:grid-cols-[180px_220px_1fr]">
                    <label className="block">
                        <span className="mb-1 block text-xs font-bold text-[var(--ink-500)]">Escopo</span>
                        <select
                            className="sig-input"
                            value={form.data.scope}
                            onChange={(event) => form.setData('scope', event.target.value)}
                        >
                            {canManageTenant && <option value="tenant">Tenant</option>}
                            {canManageGlobal && <option value="global">Global</option>}
                        </select>
                        {form.errors.scope && <span className="mt-1 block text-xs font-semibold text-rose-600">{form.errors.scope}</span>}
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-bold text-[var(--ink-500)]">Base</span>
                        <select
                            className="sig-input"
                            value={form.data.modelo}
                            onChange={(event) => form.setData('modelo', event.target.value)}
                        >
                            {modelOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        {form.errors.modelo && <span className="mt-1 block text-xs font-semibold text-rose-600">{form.errors.modelo}</span>}
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-bold text-[var(--ink-500)]">Arquivo CSV</span>
                        <input
                            accept=".csv,.txt,.tsv"
                            className="sig-input"
                            type="file"
                            onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                        />
                        {form.errors.file && <span className="mt-1 block text-xs font-semibold text-rose-600">{form.errors.file}</span>}
                    </label>
                </div>

                <div className="rounded-lg border border-dashed border-violet-200 bg-violet-50/60 px-4 py-3 text-xs leading-5 text-[var(--ink-600)]">
                    <strong className="text-[var(--ink-800)]">Colunas obrigatorias:</strong>{' '}
                    codigo_da_composicao, tipo_item, codigo_do_item, coeficiente, data.
                    <br />
                    Colunas opcionais aceitas: grupo, descricao, unidade, uf.
                    <br />
                    O <strong>tipo_item</strong> aceita <strong>INSUMO</strong> ou <strong>COMPOSICAO</strong>. A coluna <strong>data</strong> e obrigatoria para separar a competencia mensal da base.
                    <br />
                    Limite por importacao: <strong>100 MB</strong>. Vinculos duplicados no mesmo arquivo serao ignorados, sem atualizar registros.
                </div>

                <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] pt-4">
                    <button className="sig-btn sig-btn-secondary" type="button" onClick={onClose}>
                        Cancelar
                    </button>
                    <button className="sig-btn sig-btn-primary" disabled={form.processing || !form.data.file} type="submit">
                        <UploadCloud size={15} />
                        {form.processing ? 'Importando...' : 'Importar analitico'}
                    </button>
                </div>
            </form>
        </section>
    );
}

function CompositionSearchPanel({ filters, onChange, onSubmit, typeOptions = [] }) {
    return (
        <section className="mb-5 overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-[var(--shadow-sm)]">
            <form onSubmit={onSubmit}>
                <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] p-4 lg:grid-cols-[minmax(0,1fr)_180px_220px]">
                    <div className="flex min-w-0 flex-col gap-2 sm:flex-row">
                        <input
                            className="sig-input min-w-0 flex-1"
                            placeholder="Pesquise por descricao ou codigo"
                            type="search"
                            value={filters.search}
                            onChange={(event) => onChange('search', event.target.value)}
                        />
                        <button className="sig-btn sig-btn-primary justify-center sm:min-w-[120px]" type="submit">
                            <Search size={15} />
                            Buscar
                        </button>
                    </div>

                    <select
                        className="sig-input"
                        value={filters.state}
                        onChange={(event) => onChange('state', event.target.value)}
                    >
                        {states.map((state) => (
                            <option key={state.value} value={state.value}>
                                {state.label}
                            </option>
                        ))}
                    </select>

                    <select
                        className="sig-input"
                        value={filters.base}
                        onChange={(event) => onChange('base', event.target.value)}
                    >
                        {baseOptions.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-[1.4fr_1fr_0.8fr]">
                    <label className="block">
                        <span className="mb-1 block text-xs font-bold text-[var(--ink-500)]">Tipo:</span>
                        <select
                            className="sig-input"
                            value={filters.type}
                            onChange={(event) => onChange('type', event.target.value)}
                        >
                            <option value="all">Todos os tipos</option>
                            {typeOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-bold text-[var(--ink-500)]">Ordenacao:</span>
                        <select
                            className="sig-input"
                            value={filters.orderBy}
                            onChange={(event) => onChange('orderBy', event.target.value)}
                        >
                            {orderOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <RadioGroup
                        label="Bases:"
                        name="composition-base-scope"
                        options={[
                            { value: 'official', label: 'Oficiais' },
                            { value: 'own', label: 'Propria' },
                        ]}
                        value={filters.baseScope}
                        onChange={(value) => onChange('baseScope', value)}
                    />
                </div>
            </form>
        </section>
    );
}

function RadioGroup({ label, name, options, value, onChange }) {
    return (
        <fieldset>
            <legend className="mb-1 text-xs font-bold text-[var(--ink-500)]">{label}</legend>
            <div className="flex flex-wrap gap-x-5 gap-y-2 xl:block">
                {options.map((option) => (
                    <label key={option.value} className="flex cursor-pointer items-center gap-2 text-sm text-[var(--ink-600)] xl:mb-1">
                        <input
                            checked={value === option.value}
                            className="h-4 w-4 accent-[var(--primary)]"
                            name={name}
                            type="radio"
                            value={option.value}
                            onChange={(event) => onChange(event.target.value)}
                        />
                        {option.label}
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

function TableHeader({ children, className = '' }) {
    return (
        <th className={`px-3 py-4 text-xs font-bold uppercase tracking-[0.02em] ${className}`}>
            {children}
        </th>
    );
}

function TableCell({ children, className = '' }) {
    return (
        <td className={`break-words px-3 py-3 align-top text-[13px] text-[var(--ink-700)] ${className}`}>
            {children}
        </td>
    );
}

function MobileMetric({ label, value }) {
    return (
        <div>
            <span className="block text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--ink-400)]">
                {label}
            </span>
            <span className="mt-1 block break-words font-semibold text-[var(--ink-900)]">
                {value}
            </span>
        </div>
    );
}

function firstReferenceLabel(composicao) {
    const reference = composicao.base_references?.[0];

    if (!reference) {
        return composicao.estado_label ?? '-';
    }

    return reference.codigo ?? `${reference.nome ?? composicao.modelo} ${reference.uf ?? ''}`.trim();
}

function formatCurrency(value) {
    const parsed = Number(value ?? 0);

    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(Number.isNaN(parsed) ? 0 : parsed);
}
