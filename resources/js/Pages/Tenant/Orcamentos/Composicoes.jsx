import { Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertCircle, Building2, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, Clock3, Eye, FileSpreadsheet, Filter, Globe2, Plus, Search, UploadCloud, X } from 'lucide-react';
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
    { value: 'SICRO3', label: 'SICRO3' },
];

const orderOptions = [
    { value: 'code', label: 'Código' },
    { value: 'description', label: 'Descrição' },
    { value: 'unit', label: 'Unidade' },
];

const officialModelOptions = [
    { value: 'SINAPI', label: 'SINAPI' },
    { value: 'SICRO3', label: 'SICRO3' },
];

export default function OrcamentosComposicoes({
    tenant,
    filters: initialFilters = {},
    hasSearched = false,
    composicoes = [],
    totalComposicoes = 0,
    compositionSummary = { official: 0, own: 0 },
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
        file: null,
        first_item_row: '',
        last_item_row: '',
        data: '',
        fonte_column: '',
        tipo_column: '',
        codigo_column: '',
        descricao_column: '',
        unidade_column: '',
        preco_unitario_column: '',
        preco_desonerado_column: '',
        preco_nao_desonerado_column: '',
    });
    const globalImportForm = useForm({
        scope: 'global',
        modelo: 'SINAPI',
        file: null,
        first_item_row: '',
        last_item_row: '',
        data_column: '',
        fonte_column: '',
        tipo_column: '',
        codigo_column: '',
        descricao_column: '',
        unidade_column: '',
        uf_column: '',
        preco_unitario_column: '',
        preco_desonerado_column: '',
        preco_nao_desonerado_column: '',
    });
    const analyticImportForm = useForm({
        scope: 'global',
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
        importAnalytic: canManageGlobalComposicoes,
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

    const clearFilters = () => {
        const defaults = {
            search: '',
            type: 'all',
            orderBy: 'code',
            base: 'SINAPI',
            baseScope: 'official',
            state: 'PA',
            perPage: 50,
        };

        setFilters(defaults);
        router.get(route('tenant.orcamentos.composicoes.index', tenant.slug), {}, {
            preserveScroll: true,
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
            title="Composições"
            subtitle="Pesquise composições oficiais ou próprias para estruturar serviços, coeficientes e custos do orçamento."
            showNav={false}
            eyebrow="Orçamentos · Bases de preço"
            actions={(
                <>
                    {visiblePanels.importTenant && (
                        <ActionButton active={activePanel === 'importTenant'} icon={Building2} onClick={() => togglePanel('importTenant')}>
                            Importar base própria
                        </ActionButton>
                    )}
                    {visiblePanels.importGlobal && (
                        <ActionButton active={activePanel === 'importGlobal'} icon={Globe2} onClick={() => togglePanel('importGlobal')}>
                            Importar global
                        </ActionButton>
                    )}
                    {visiblePanels.importAnalytic && (
                        <ActionButton active={activePanel === 'importAnalytic'} icon={FileSpreadsheet} onClick={() => togglePanel('importAnalytic')}>
                            Importar analítico
                        </ActionButton>
                    )}
                    {visiblePanels.create && (
                        <ActionLink href={route('tenant.orcamentos.composicoes.create', tenant.slug)} icon={Plus} primary>
                            Criar composição
                        </ActionLink>
                    )}
                </>
            )}
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

            {activePanel === 'importTenant' && (
                <ImportOwnCompositionPanel
                    form={tenantImportForm}
                    onClose={() => setActivePanel(null)}
                    onSubmit={(event) => submitImport(event, tenantImportForm, 'Importacao de composicoes da base propria')}
                />
            )}

            {activePanel === 'importGlobal' && (
                <ImportCompositionPanel
                    description="As composicoes importadas ficarao disponiveis para toda a plataforma. Use apenas bases oficiais ou corporativas."
                    form={globalImportForm}
                    icon={Globe2}
                    onClose={() => setActivePanel(null)}
                    onSubmit={(event) => submitImport(event, globalImportForm, 'Importacao global de composicoes')}
                    title="Importar composicoes globais"
                />
            )}

            {activePanel === 'importAnalytic' && (
                <ImportAnalyticPanel
                    form={analyticImportForm}
                    onClose={() => setActivePanel(null)}
                    onSubmit={submitAnalyticImport}
                />
            )}

            <CompositionSearchPanel
                compositionSummary={compositionSummary}
                filters={filters}
                typeOptions={typeOptions}
                onChange={updateFilter}
                onClear={clearFilters}
                onSubmit={submitSearch}
            />

            <ComposicoesList
                composicoes={compositionRows}
                filters={filters}
                hasSearched={hasSearched}
                pagination={pagination}
                setFilters={setFilters}
                tenant={tenant}
                totalComposicoes={filters.baseScope === 'own'
                    ? compositionSummary.own
                    : compositionSummary.official}
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
                                : 'Upload concluido. A importacao sera processada em segundo plano.'}
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
                        Depois do upload, voce pode continuar usando o sistema. A importacao segue na fila do servidor.
                    </span>
                </div>
            </div>
        </section>
    );
}

function ImportResultFeedback({ result }) {
    const duplicated = Number(result.duplicated ?? result.duplicates ?? 0);
    const compositionHeaders = Number(result.composition_headers ?? 0);
    const changed = Number(result.created ?? 0) + Number(result.updated ?? 0);
    const status = result.status ?? (changed > 0 ? 'success' : 'warning');
    const isSuccess = status === 'success';
    const statusLabel = isSuccess ? 'Sucesso' : 'Atencao';
    const message = result.message ?? (isSuccess
        ? 'Importacao concluida com sucesso.'
        : 'Importacao concluida, mas nenhum registro novo foi gravado.');
    const total = Number(result.read ?? 0) || Number(result.created ?? 0) + Number(result.updated ?? 0) + duplicated + Number(result.skipped ?? 0);
    const metrics = [
        { label: 'Criadas', value: result.created ?? 0, tone: 'text-emerald-700 bg-emerald-50 border-emerald-200' },
        { label: 'Atualizadas', value: result.updated ?? 0, tone: 'text-blue-700 bg-blue-50 border-blue-200' },
        { label: 'Duplicadas ignoradas', value: duplicated, tone: 'text-violet-700 bg-violet-50 border-violet-200' },
        ...(compositionHeaders > 0
            ? [{ label: 'Cabeçalhos ignorados', value: compositionHeaders, tone: 'text-cyan-700 bg-cyan-50 border-cyan-200' }]
            : []),
        { label: 'Linhas ignoradas', value: result.skipped ?? 0, tone: 'text-amber-700 bg-amber-50 border-amber-200' },
        { label: 'Total lido', value: total, tone: 'text-[var(--ink-700)] bg-[var(--surface-muted)] border-[var(--border)]' },
    ];

    return (
        <section className={`mb-5 rounded-lg border bg-white p-4 shadow-[var(--shadow-sm)] ${isSuccess ? 'border-emerald-200' : 'border-amber-200'}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-bold text-[var(--ink-900)]">{result.title ?? 'Resumo da importacao'}</h2>
                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                        Escopo: <strong>{result.scope_label ?? '-'}</strong>
                        {' '}| Base: <strong>{result.base ?? '-'}</strong>
                    </p>
                </div>
                <span className={`rounded-full px-3 py-1 text-xs font-bold ${isSuccess ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                    {statusLabel}
                </span>
            </div>

            <div className={`mt-4 rounded-lg border px-4 py-3 text-sm font-semibold ${isSuccess ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800'}`}>
                {message}
            </div>

            <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
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
    const currentPage = Number(pagination?.current_page ?? 1);
    const lastPage = Number(pagination?.last_page ?? 1);
    const updatePerPage = (perPage) => {
        const nextFilters = { ...filters, perPage };

        setFilters(nextFilters);
        router.get(route('tenant.orcamentos.composicoes.index', tenant.slug), { ...nextFilters, searched: 1 }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };
    const goTo = (page) => {
        if (page >= 1 && page <= lastPage) {
            router.get(route('tenant.orcamentos.composicoes.index', tenant.slug), {
                ...filters,
                page,
                searched: 1,
            }, { preserveScroll: true, preserveState: true, replace: true });
        }
    };

    return (
        <section className="sig-card overflow-hidden">
            <header className="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
                <div className="min-w-0">
                    <p className="text-[10.5px] font-bold uppercase tracking-[0.1em] text-[var(--ink-400)]">
                        {compositionResultContext(filters, composicoes)}
                    </p>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        {hasSearched
                            ? <>Exibindo <DataValue>{from}–{to}</DataValue> de <DataValue>{filteredTotal}</DataValue> resultados filtrados · <DataValue>{totalComposicoes}</DataValue> na base</>
                            : `${formatCount(totalComposicoes)} composições disponíveis. Selecione os filtros para carregar a listagem.`}
                    </p>
                </div>
                {hasSearched && (
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="flex items-center gap-2 text-xs text-[var(--ink-400)]">
                            Itens por página
                            <select
                                className="sig-input !h-9 !min-h-9 !w-20 !px-3 font-mono !text-sm"
                                value={filters.perPage}
                                onChange={(event) => updatePerPage(event.target.value)}
                            >
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                        <span className="h-6 w-px bg-[var(--border)]" />
                        <div className="flex items-center gap-2">
                            <PageArrow
                                disabled={!pagination?.prev_page_url}
                                icon={ChevronLeft}
                                label="Página anterior"
                                onClick={() => goTo(currentPage - 1)}
                            />
                            <span className="min-w-16 text-center font-mono text-xs text-[var(--ink-600)]">
                                {currentPage} / {lastPage}
                            </span>
                            <PageArrow
                                disabled={!pagination?.next_page_url}
                                icon={ChevronRight}
                                label="Próxima página"
                                onClick={() => goTo(currentPage + 1)}
                            />
                        </div>
                    </div>
                )}
            </header>

            {!hasSearched ? (
                <div className="p-8 text-center text-sm text-[var(--ink-500)]">
                    Selecione os filtros para carregar a listagem de composições.
                </div>
            ) : composicoes.length === 0 ? (
                <div className="p-8 text-center text-sm text-[var(--ink-500)]">
                    Nenhuma composição encontrada para os filtros informados.
                </div>
            ) : (
                <>
                    <div className="hidden overflow-x-auto xl:block">
                        <table className="w-full min-w-[1080px] table-fixed border-collapse text-left">
                            <colgroup>
                                <col className="w-[10%]" />
                                <col className="w-[29%]" />
                                <col className="w-[17%]" />
                                <col className="w-[7%]" />
                                <col className="w-[9%]" />
                                <col className="w-[6%]" />
                                <col className="w-[9%]" />
                                <col className="w-[9%]" />
                                <col className="w-[4%]" />
                            </colgroup>
                            <thead>
                                <tr className="border-y border-[var(--border)] bg-[var(--surface-muted)] text-[var(--ink-400)]">
                                    <TableHeader>CÓDIGO</TableHeader>
                                    <TableHeader>DESCRIÇÃO</TableHeader>
                                    <TableHeader>TIPO</TableHeader>
                                    <TableHeader>UNID.</TableHeader>
                                    <TableHeader>ESTADO</TableHeader>
                                    <TableHeader className="text-center">ITENS</TableHeader>
                                    <TableHeader className="text-right">ONERADO</TableHeader>
                                    <TableHeader className="text-right">DESONERADO</TableHeader>
                                    <TableHeader />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[var(--border)] bg-white">
                                {composicoes.map((composicao) => (
                                    <tr key={composicao.id} className="transition-colors hover:bg-[var(--surface-muted)]">
                                        <TableCell className="whitespace-nowrap font-mono text-[12.5px] font-medium">
                                            <Link
                                                className="text-[var(--primary)] underline-offset-4 hover:underline"
                                                href={route('tenant.orcamentos.composicoes.show', [tenant.slug, composicao.id])}
                                            >
                                                {composicao.codigo}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex min-w-0 flex-col gap-1">
                                                <span className="text-[13.5px] font-medium leading-5 text-[var(--ink-900)]">
                                                    {composicao.descricao}
                                                </span>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="text-[11.5px] text-[var(--ink-400)]">
                                                        {composicao.base_label ?? composicao.scope_label ?? composicao.modelo} · Modelo {composicao.modelo} · {firstReferenceLabel(composicao)}
                                                    </span>
                                                    <PriceQualityNote composicao={composicao} compact />
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-[12.5px] leading-5 text-[var(--ink-600)]">{composicao.tipo_composicao}</TableCell>
                                        <TableCell className="whitespace-nowrap font-mono text-xs">{composicao.unidade}</TableCell>
                                        <TableCell className="whitespace-nowrap text-xs">{composicao.estado_label}</TableCell>
                                        <TableCell className={`text-center font-mono text-xs ${Number(composicao.items_count ?? 0) === 0 ? 'text-[var(--ink-300)]' : ''}`}>
                                            {composicao.items_count ?? 0}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <PriceDisplay composicao={composicao} value={composicao.effective_preco_onerado} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <PriceDisplay composicao={composicao} value={composicao.effective_preco_desonerado} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <OpenButton compact composicao={composicao} tenant={tenant} />
                                        </TableCell>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="grid gap-2 border-t border-[var(--border)] bg-[var(--surface-muted)] p-3 xl:hidden">
                        {composicoes.map((composicao) => (
                            <article key={composicao.id} className="rounded-lg border border-[var(--border)] bg-white p-3 shadow-[var(--shadow-sm)]">
                                <div className="flex min-w-0 flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link
                                            className="break-words font-mono text-xs font-semibold text-[var(--primary)] underline-offset-4 hover:underline"
                                            href={route('tenant.orcamentos.composicoes.show', [tenant.slug, composicao.id])}
                                        >
                                            {composicao.codigo}
                                        </Link>
                                    </div>
                                    <OpenButton composicao={composicao} tenant={tenant} />
                                </div>
                                <h3 className="mt-2 break-words text-sm font-semibold leading-5 text-[var(--ink-900)]">{composicao.descricao}</h3>
                                <p className="mt-1 break-words text-xs text-[var(--ink-500)]">{composicao.tipo_composicao}</p>
                                <div className="mt-3 grid grid-cols-2 gap-3 border-t border-[var(--border)] pt-3 text-sm">
                                    <MobileMetric label="Unidade" value={composicao.unidade} />
                                    <MobileMetric label="Estado" value={composicao.estado_label} />
                                    <MobileMetric label="Itens" value={composicao.items_count ?? 0} />
                                    <MobileMetric label="Onerado" value={formatCurrency(composicao.effective_preco_onerado)} />
                                    <MobileMetric label="Desonerado" value={formatCurrency(composicao.effective_preco_desonerado)} />
                                </div>
                                <PriceQualityNote composicao={composicao} />
                            </article>
                        ))}
                    </div>
                </>
            )}

            {hasSearched && pagination && (
                <Pagination filters={filters} pagination={pagination} tenant={tenant} />
            )}
        </section>
    );
}

function PriceDisplay({ composicao, showNote = false, value }) {
    return (
        <div className="flex flex-col items-end gap-1">
            <span className="font-mono">{formatCurrency(value)}</span>
            {showNote && <PriceQualityNote composicao={composicao} compact />}
        </div>
    );
}

function PriceQualityNote({ compact = false, composicao }) {
    const missing = Number(composicao.missing_price_items_count ?? 0);
    const isCalculated = ['analytic', 'items'].includes(composicao.price_source);

    if (!isCalculated && missing <= 0) {
        return null;
    }

    return (
        <div className={`flex flex-wrap items-center gap-1 text-[10px] font-semibold ${compact ? 'justify-start' : 'mt-2'}`}>
            {isCalculated && (
                <span className="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-blue-700">
                    Calculado pelos itens
                </span>
            )}
            {missing > 0 && (
                <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">
                    <AlertCircle size={11} />
                    {missing} sem preco
                </span>
            )}
        </div>
    );
}

function OpenButton({ compact = false, composicao, tenant }) {
    return (
        <Link
            className={`inline-flex min-h-8 items-center justify-center gap-1 rounded-md border border-[var(--border)] bg-white text-xs font-bold text-[var(--ink-600)] transition hover:border-[var(--border-strong)] hover:bg-[var(--surface-muted)] hover:text-[var(--primary)] ${
                compact ? 'w-8 px-0' : 'px-3'
            }`}
            href={route('tenant.orcamentos.composicoes.show', [tenant.slug, composicao.id])}
            title="Abrir"
        >
            <Eye size={13} />
            {!compact && 'Abrir'}
        </Link>
    );
}

function Pagination({ filters, pagination, tenant }) {
    const goTo = (page) => {
        if (page) {
            router.get(route('tenant.orcamentos.composicoes.index', tenant.slug), {
                ...filters,
                page,
                searched: 1,
            }, { preserveScroll: true, preserveState: true, replace: true });
        }
    };

    return (
        <footer className="flex flex-col gap-3 border-t border-[var(--border)] bg-white px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-xs text-[var(--ink-400)]">
                Valores em R$ referentes à base e ao estado selecionados.
            </p>
            <div className="flex flex-wrap items-center gap-1">
                {pagination.links
                    .filter((link) => !String(link.label).includes('Previous') && !String(link.label).includes('Next'))
                    .map((link, index) => {
                        const label = paginationLabel(link.label);
                        const page = Number(label);

                        return (
                            <button
                                key={`${link.label}-${index}`}
                                className={`flex h-8 min-w-8 items-center justify-center rounded-md border px-2 text-xs font-bold transition ${
                                    link.active
                                        ? 'border-[var(--primary)] bg-[var(--primary)] text-white'
                                        : 'border-[var(--border)] bg-white text-[var(--ink-600)] hover:bg-[var(--primary-50)]'
                                } ${!link.url ? 'cursor-not-allowed opacity-45' : ''}`}
                                disabled={!link.url || !Number.isFinite(page)}
                                type="button"
                                onClick={() => goTo(page)}
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

function ActionLink({ children, href, icon: Icon, primary = false }) {
    return (
        <Link
            className={`sig-btn min-h-10 ${primary ? 'sig-btn-primary' : 'sig-btn-secondary'}`}
            href={href}
        >
            <Icon size={15} />
            {children}
        </Link>
    );
}

function ActionButton({ active, children, icon: Icon, onClick }) {
    return (
        <button
            className={`sig-btn min-h-10 ${active ? 'sig-btn-primary' : 'sig-btn-secondary'}`}
            type="button"
            onClick={onClick}
        >
            <Icon size={15} />
            {children}
        </button>
    );
}

function ImportOwnCompositionPanel({ form, onClose, onSubmit }) {
    return (
        <section className="mb-5 overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-[var(--shadow-sm)]">
            <header className="border-b border-[var(--border)]">
                <div className="bg-[var(--primary)] px-5 py-3 text-sm font-bold text-white">
                    Selecione o arquivo e informe os campos relevantes.
                </div>
                <div className="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                    <div className="flex items-start gap-3">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700">
                            <Building2 size={17} />
                        </span>
                        <div>
                            <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Importar composicoes da base propria</h2>
                            <p className="mt-1 text-xs leading-5 text-[var(--ink-500)]">
                                As composicoes serao gravadas no tenant atual como Base propria. Informe as letras das colunas da sua planilha.
                            </p>
                        </div>
                    </div>
                    <button className="sig-btn sig-btn-ghost" type="button" onClick={onClose}>
                        <X size={15} />
                        Fechar
                    </button>
                </div>
            </header>

            <form className="grid gap-5 p-5" onSubmit={onSubmit}>
                <div className="grid gap-4 lg:grid-cols-[minmax(240px,420px)_1fr]">
                    <CompositionImportField label="Arquivo" error={form.errors.file}>
                        <input
                            accept=".csv,.txt,.tsv"
                            className="sig-input"
                            onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                            type="file"
                        />
                    </CompositionImportField>
                    <div className="rounded-lg border border-dashed border-[var(--border-strong)] bg-[var(--surface-muted)] px-4 py-3 text-xs leading-5 text-[var(--ink-500)]">
                        Suporta arquivos <strong>CSV, TXT e TSV</strong>. Para XLSX/XLS/ODS, exporte a planilha como CSV antes de importar.
                    </div>
                </div>

                <div className="grid max-w-3xl gap-4">
                    <CompositionImportField label="Numero da linha do primeiro item" error={form.errors.first_item_row}>
                        <input className="sig-input" value={form.data.first_item_row} onChange={(event) => form.setData('first_item_row', event.target.value)} inputMode="numeric" placeholder="2" />
                    </CompositionImportField>
                    <CompositionImportField label="Numero da linha do ultimo item" error={form.errors.last_item_row}>
                        <input className="sig-input" value={form.data.last_item_row} onChange={(event) => form.setData('last_item_row', event.target.value)} inputMode="numeric" placeholder="Ex: 250" />
                    </CompositionImportField>
                </div>

                <div className="grid max-w-3xl gap-4">
                    <CompositionColumnLetterField form={form} field="fonte_column" label="Letra da Coluna da Fonte" optional />
                    <CompositionColumnLetterField form={form} field="tipo_column" label="Letra da coluna de Tipo" optional />
                    <CompositionColumnLetterField form={form} field="codigo_column" label="Letra da Coluna do Codigo" />
                    <CompositionColumnLetterField form={form} field="descricao_column" label="Letra da Coluna da Descricao" />
                    <CompositionColumnLetterField form={form} field="unidade_column" label="Letra da Coluna da Unidade" />
                    <CompositionColumnLetterField
                        form={form}
                        field="preco_unitario_column"
                        label="Letra da Coluna do Preco Unitario"
                        hint="Opcional. Use para planilhas no modelo SICRO3, quando houver apenas um preco unitario."
                        optional
                    />
                    <CompositionColumnLetterField form={form} field="preco_desonerado_column" label="Letra da Coluna do Preco Unitario Desonerado" optional />
                    <CompositionColumnLetterField
                        form={form}
                        field="preco_nao_desonerado_column"
                        label="Letra da Coluna do Preco Unitario Nao Desonerado"
                        hint="Opcional. Use para planilhas no modelo SINAPI."
                        optional
                    />
                </div>

                <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-4 py-3 text-xs leading-5 text-[var(--ink-500)]">
                    <p>
                        <strong className="text-[var(--ink-700)]">Campos obrigatorios:</strong> primeira linha, ultima linha, data de referencia, codigo, descricao e unidade.
                    </p>
                    <p>
                        Fonte, tipo e precos sao opcionais. Se nenhum preco for informado, a composicao sera importada com valor zero e podera ser detalhada depois.
                    </p>
                    <p>
                        Todas as linhas entram como <strong>Base propria</strong> do tenant atual, sem vinculo com base global.
                    </p>
                </div>

                <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] pt-4">
                    <button className="sig-btn sig-btn-secondary" type="button" onClick={onClose}>
                        Cancelar
                    </button>
                    <button className="sig-btn sig-btn-primary" disabled={form.processing || !form.data.file} type="submit">
                        <UploadCloud size={15} />
                        {form.processing ? 'Importando...' : 'Salvar as alteracoes'}
                    </button>
                </div>
            </form>
        </section>
    );
}

function CompositionColumnLetterField({ field, form, hint = null, label, optional = false }) {
    return (
        <CompositionImportField label={label} error={form.errors[field]} hint={hint}>
            <input
                className="sig-input"
                value={form.data[field]}
                onChange={(event) => form.setData(field, event.target.value.toUpperCase())}
                placeholder={optional ? 'Opcional' : 'Ex: A'}
            />
        </CompositionImportField>
    );
}

function CompositionImportField({ children, error, hint = null, label }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-bold text-[var(--ink-500)]">{label}</span>
            {children}
            {hint && <span className="mt-1 block text-[11px] leading-4 text-[var(--ink-400)]">{hint}</span>}
            {error && <span className="mt-1 block text-xs font-semibold text-rose-600">{error}</span>}
        </label>
    );
}

function ImportCompositionPanel({ description, form, icon: Icon, onClose, onSubmit, title }) {
    return (
        <section className="mb-5 overflow-hidden rounded-lg border border-[var(--border)] bg-white shadow-[var(--shadow-sm)]">
            <header className="border-b border-[var(--border)]">
                <div className="bg-[var(--primary)] px-5 py-3 text-sm font-bold text-white">
                    Selecione o arquivo e informe os campos relevantes.
                </div>
                <div className="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
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
                </div>
            </header>

            <form className="grid gap-5 p-5" onSubmit={onSubmit}>
                <div className="grid gap-4 lg:grid-cols-[220px_minmax(240px,420px)_1fr]">
                    <label className="block">
                        <span className="mb-1 block text-xs font-bold text-[var(--ink-500)]">Base da importacao</span>
                        <select
                            className="sig-input"
                            value={form.data.modelo}
                            onChange={(event) => form.setData('modelo', event.target.value)}
                        >
                            {officialModelOptions.map((option) => (
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

                    <div className="rounded-lg border border-dashed border-[var(--border-strong)] bg-[var(--surface-muted)] px-4 py-3 text-xs leading-5 text-[var(--ink-500)]">
                        Suporta arquivos <strong>CSV, TXT e TSV</strong>. Para XLSX/XLS/ODS, exporte a planilha como CSV antes de importar.
                    </div>
                </div>

                <div className="grid max-w-3xl gap-4">
                    <CompositionImportField label="Numero da linha do primeiro item" error={form.errors.first_item_row}>
                        <input className="sig-input" value={form.data.first_item_row} onChange={(event) => form.setData('first_item_row', event.target.value)} inputMode="numeric" placeholder="2" />
                    </CompositionImportField>
                    <CompositionImportField label="Numero da linha do ultimo item" error={form.errors.last_item_row}>
                        <input className="sig-input" value={form.data.last_item_row} onChange={(event) => form.setData('last_item_row', event.target.value)} inputMode="numeric" placeholder="Ex: 250000" />
                    </CompositionImportField>
                </div>

                <div className="grid max-w-3xl gap-4">
                    <CompositionColumnLetterField form={form} field="fonte_column" label="Letra da Coluna da Fonte" optional />
                    <CompositionColumnLetterField form={form} field="tipo_column" label="Letra da coluna de Tipo" optional />
                    <CompositionColumnLetterField form={form} field="codigo_column" label="Letra da Coluna do Codigo" />
                    <CompositionColumnLetterField form={form} field="descricao_column" label="Letra da Coluna da Descricao" />
                    <CompositionColumnLetterField form={form} field="unidade_column" label="Letra da Coluna da Unidade" />
                    <CompositionColumnLetterField form={form} field="uf_column" label="Letra da Coluna da UF" />
                    <CompositionColumnLetterField form={form} field="data_column" label="Letra da Coluna da Data de Referencia" />
                    <CompositionColumnLetterField
                        form={form}
                        field="preco_unitario_column"
                        label="Letra da Coluna do Preco Unitario"
                        hint="Opcional. Use para planilhas no modelo SICRO3, quando houver apenas um preco unitario."
                        optional
                    />
                    <CompositionColumnLetterField form={form} field="preco_desonerado_column" label="Letra da Coluna do Preco Unitario Desonerado" optional />
                    <CompositionColumnLetterField
                        form={form}
                        field="preco_nao_desonerado_column"
                        label="Letra da Coluna do Preco Unitario Nao Desonerado"
                        hint="Opcional. Use para planilhas no modelo SINAPI."
                        optional
                    />
                </div>

                <div className="rounded-lg border border-dashed border-[var(--border-strong)] bg-[var(--surface-muted)] px-4 py-3 text-xs leading-5 text-[var(--ink-500)]">
                    <p>
                        <strong className="text-[var(--ink-700)]">Campos obrigatorios:</strong> base, arquivo, primeira linha, ultima linha, codigo, descricao, unidade, UF e data de referencia.
                    </p>
                    <p>
                        Fonte, tipo e precos sao opcionais. Duplicados ja existentes na base global serao atualizados conforme a chave base + codigo + UF + data.
                    </p>
                    <p>
                        A importacao global fica disponivel para todos os tenants e deve ser usada para bases oficiais SINAPI ou SICRO3.
                    </p>
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

function ImportAnalyticPanel({ form, onClose, onSubmit }) {
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
                <input type="hidden" value="global" name="scope" />
                <div className="grid gap-4 lg:grid-cols-[220px_1fr]">
                    <label className="block">
                        <span className="mb-1 block text-xs font-bold text-[var(--ink-500)]">Base</span>
                        <select
                            className="sig-input"
                            value={form.data.modelo}
                            onChange={(event) => form.setData('modelo', event.target.value)}
                        >
                            {officialModelOptions.map((option) => (
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
                    <strong className="text-[var(--ink-800)]">Escopo:</strong>{' '}
                    Global. O analitico importado fica disponivel para todos os tenants e nao cria vinculos de Base propria.
                    <br />
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

function CompositionSearchPanel({
    compositionSummary = { official: 0, own: 0 },
    filters,
    onChange,
    onClear,
    onSubmit,
    typeOptions = [],
}) {
    return (
        <section className="mb-5">
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <ScopeChip
                    active={filters.baseScope === 'official'}
                    count={compositionSummary.official}
                    label="Oficiais"
                    onClick={() => onChange('baseScope', 'official')}
                />
                <ScopeChip
                    active={filters.baseScope === 'own'}
                    count={compositionSummary.own}
                    label="Próprias"
                    onClick={() => onChange('baseScope', 'own')}
                />
            </div>

            <form className="grid gap-3 lg:grid-cols-12" onSubmit={onSubmit}>
                <label className="relative block lg:col-span-4">
                    <span className="sr-only">Buscar por descrição ou código</span>
                    <Search className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--ink-400)]" size={17} />
                    <input
                        className="sig-input h-[52px] w-full"
                        style={{ paddingLeft: 42 }}
                        placeholder="Buscar por descrição ou código"
                        type="search"
                        value={filters.search}
                        onChange={(event) => onChange('search', event.target.value)}
                    />
                </label>

                <CompactSelect
                    className="lg:col-span-2"
                    disabled={filters.baseScope === 'own'}
                    label="Banco"
                    value={filters.base}
                    onChange={(value) => onChange('base', value)}
                >
                    {filters.baseScope === 'own'
                        ? <option value={filters.base}>Base própria</option>
                        : baseOptions.map((option) => (
                            <option key={option.value} value={option.value}>{option.label}</option>
                        ))}
                </CompactSelect>

                <CompactSelect
                    className="lg:col-span-2"
                    label="Estado"
                    value={filters.state}
                    onChange={(value) => onChange('state', value)}
                >
                    {states.map((state) => (
                        <option key={state.value} value={state.value}>{state.label}</option>
                    ))}
                </CompactSelect>

                <CompactSelect
                    className="lg:col-span-2"
                    label="Tipo"
                    value={filters.type}
                    onChange={(value) => onChange('type', value)}
                >
                    <option value="all">Todos os tipos</option>
                    {typeOptions.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                </CompactSelect>

                <CompactSelect
                    className="lg:col-span-2"
                    label="Ordenar por"
                    value={filters.orderBy}
                    onChange={(value) => onChange('orderBy', value)}
                >
                    {orderOptions.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                </CompactSelect>

                <div className="flex flex-wrap items-center justify-end gap-2 lg:col-span-12">
                    <button className="sig-btn sig-btn-ghost" type="button" onClick={onClear}>
                        Limpar filtros
                    </button>
                    <button className="sig-btn sig-btn-primary min-w-36 justify-center" type="submit">
                        <Filter size={15} />
                        Aplicar filtros
                    </button>
                </div>
            </form>
        </section>
    );
}

function ScopeChip({ active, count, label, onClick }) {
    return (
        <button
            className={`inline-flex min-h-9 items-center gap-2 rounded-full border px-4 text-sm transition ${
                active
                    ? 'border-[var(--border-strong)] bg-[var(--primary-50)] font-semibold text-[var(--ink-900)]'
                    : 'border-[var(--border)] bg-white text-[var(--ink-700)] hover:border-[var(--border-strong)]'
            }`}
            type="button"
            onClick={onClick}
        >
            <span>{label}</span>
            <span className="font-mono text-xs text-[var(--ink-400)]">{formatCount(count)}</span>
        </button>
    );
}

function CompactSelect({ children, className = '', disabled = false, label, onChange, value }) {
    return (
        <label
            className={`sig-input relative h-[52px] min-w-0 flex-col justify-center gap-0.5 py-1.5 pl-3.5 pr-9 ${
                disabled ? 'cursor-not-allowed bg-[var(--surface-muted)] opacity-75' : ''
            } ${className}`}
            style={{ alignItems: 'stretch' }}
        >
            <span className="pointer-events-none block text-[9.5px] font-bold uppercase leading-[1.2] tracking-[0.05em] text-[var(--ink-400)]">
                {label}
            </span>
            <select
                className="m-0 h-5 min-h-5 w-full appearance-none border-0 bg-transparent p-0 text-sm font-semibold leading-5 text-[var(--ink-900)] outline-none focus:border-0 focus:ring-0"
                disabled={disabled}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {children}
            </select>
            <ChevronDown
                aria-hidden="true"
                className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[var(--ink-400)]"
                size={15}
            />
        </label>
    );
}

function TableHeader({ children, className = '' }) {
    return (
        <th className={`px-4 py-3 text-[10.5px] font-bold uppercase tracking-[0.08em] ${className}`}>
            {children}
        </th>
    );
}

function TableCell({ children, className = '' }) {
    return (
        <td className={`break-words px-4 py-3.5 align-middle text-[13px] text-[var(--ink-700)] ${className}`}>
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

function DataValue({ children }) {
    return (
        <span className="font-mono text-xs font-medium text-[var(--ink-700)]">
            {typeof children === 'number' ? formatCount(children) : children}
        </span>
    );
}

function PageArrow({ disabled, icon: Icon, label, onClick }) {
    return (
        <button
            aria-label={label}
            className={`flex h-9 w-9 items-center justify-center rounded-md border border-[var(--border)] bg-white text-[var(--ink-500)] transition hover:border-[var(--border-strong)] hover:text-[var(--primary)] ${
                disabled ? 'cursor-not-allowed opacity-40' : ''
            }`}
            disabled={disabled}
            title={label}
            type="button"
            onClick={onClick}
        >
            <Icon size={15} />
        </button>
    );
}

function compositionResultContext(filters, composicoes) {
    const scope = filters.baseScope === 'own' ? 'Base própria' : filters.base;
    const state = states.find((item) => item.value === filters.state)?.label ?? filters.state;
    const reference = referenceDateLabel(composicoes[0]);

    return ['Composições cadastradas', scope, state, reference]
        .filter(Boolean)
        .join(' · ');
}

function referenceDateLabel(composicao) {
    const reference = composicao?.base_references?.[0];
    const rawDate = reference?.data ?? reference?.date ?? '';

    if (/^\d{2}\/\d{4}$/.test(rawDate)) {
        return rawDate;
    }

    if (/^\d{4}-\d{2}/.test(rawDate)) {
        return `${rawDate.slice(5, 7)}/${rawDate.slice(0, 4)}`;
    }

    return null;
}

function formatCount(value) {
    return new Intl.NumberFormat('pt-BR').format(Number(value ?? 0));
}

function formatCurrency(value) {
    const parsed = Number(value ?? 0);

    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number.isNaN(parsed) ? 0 : parsed);
}
