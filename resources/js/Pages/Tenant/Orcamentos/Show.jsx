import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    BadgePercent,
    Blocks,
    Box,
    Check,
    ClipboardList,
    Copy,
    Database,
    Download,
    Eye,
    EyeOff,
    FileSpreadsheet,
    ListTree,
    Loader2,
    Pencil,
    Trash2,
    X,
} from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import OrcamentoShell from './Partials/OrcamentoShell';

const currencyFormatter = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const decimalFormatter = new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const orderDepth = (value) => String(value ?? '').split('.').filter(Boolean).length || 1;

const lastOrderSegment = (value) => Number(String(value ?? '').split('.').filter(Boolean).at(-1) ?? 0);

const parentEtapaOrder = (value) => {
    const segments = String(value ?? '').split('.').filter(Boolean);

    if (segments.length <= 1) {
        return null;
    }

    return segments.slice(0, -1).join('.');
};

const isDirectChildOrder = (candidate, parent) => {
    const candidateOrder = String(candidate ?? '');
    const parentOrder = String(parent ?? '');

    return candidateOrder.startsWith(`${parentOrder}.`) && orderDepth(candidateOrder) === orderDepth(parentOrder) + 1;
};

const nextRootEtapaOrder = (etapas) => {
    const maxRoot = etapas
        .filter((etapa) => orderDepth(etapa.item ?? etapa.ordem) === 1)
        .reduce((max, etapa) => Math.max(max, Number(etapa.item ?? etapa.ordem ?? 0)), 0);

    return String(maxRoot + 1);
};

const nextDirectChildPosition = (targetEtapa, etapas) => {
    const parentOrder = String(targetEtapa?.item ?? targetEtapa?.ordem ?? '');
    const itemMax = (targetEtapa?.itens ?? []).reduce((max, item) => Math.max(max, Number(item.ordem ?? 0)), 0);
    const childEtapaMax = etapas
        .filter((etapa) => isDirectChildOrder(etapa.item ?? etapa.ordem, parentOrder))
        .reduce((max, etapa) => Math.max(max, lastOrderSegment(etapa.item ?? etapa.ordem)), 0);

    return Math.max(itemMax, childEtapaMax) + 1;
};

const nextChildEtapaOrder = (targetEtapa, etapas) => {
    const parentOrder = String(targetEtapa?.item ?? targetEtapa?.ordem ?? '');

    return `${parentOrder}.${nextDirectChildPosition(targetEtapa, etapas)}`;
};

export default function OrcamentoShow({
    tenant,
    orcamento,
    etapas = [],
    copySources = [],
    canManageOrcamentos = false,
}) {
    const page = usePage();
    const [showEtapaForm, setShowEtapaForm] = useState(false);
    const [addingAfterEtapaId, setAddingAfterEtapaId] = useState(null);
    const [editingEtapaId, setEditingEtapaId] = useState(null);
    const [deleteEtapa, setDeleteEtapa] = useState(null);
    const [compositionFormEtapaId, setCompositionFormEtapaId] = useState(null);
    const [compositionOptions, setCompositionOptions] = useState([]);
    const [compositionSearch, setCompositionSearch] = useState({ codigo: '', descricao: '' });
    const [compositionLoading, setCompositionLoading] = useState(false);
    const [selectedComposition, setSelectedComposition] = useState(null);
    const [insumoFormEtapaId, setInsumoFormEtapaId] = useState(null);
    const [insumoOptions, setInsumoOptions] = useState([]);
    const [insumoSearch, setInsumoSearch] = useState({ codigo: '', descricao: '' });
    const [insumoLoading, setInsumoLoading] = useState(false);
    const [selectedInsumo, setSelectedInsumo] = useState(null);
    const [editingItemId, setEditingItemId] = useState(null);
    const [bdiItem, setBdiItem] = useState(null);
    const [deleteItem, setDeleteItem] = useState(null);
    const [reportsModalOpen, setReportsModalOpen] = useState(false);
    const [selectedReports, setSelectedReports] = useState(['sintetico']);
    const [copyModalOpen, setCopyModalOpen] = useState(false);
    const [closeConfirmOpen, setCloseConfirmOpen] = useState(false);
    const [copySourceId, setCopySourceId] = useState('');
    const [copySource, setCopySource] = useState(null);
    const [copyEtapas, setCopyEtapas] = useState([]);
    const [copyLoading, setCopyLoading] = useState(false);
    const [copySubmitting, setCopySubmitting] = useState(false);
    const [copySelectedRows, setCopySelectedRows] = useState(new Set());
    const etapaForm = useForm({
        ordem: '',
        descricao: '',
        after_etapa_id: null,
    });
    const editEtapaForm = useForm({
        ordem: '',
        descricao: '',
    });
    const compositionForm = useForm({
        orcamento_composicao_id: '',
        ordem: '',
        quantidade: '1',
        aplicar_bdi: false,
    });
    const insumoForm = useForm({
        orcamento_insumo_id: '',
        ordem: '',
        quantidade: '1',
        valor_unitario_manual: '',
        aplicar_bdi: false,
    });
    const editItemForm = useForm({
        ordem: '',
        quantidade: '1',
        aplicar_bdi: false,
    });
    const bdiForm = useForm({
        bdi_percentual: '',
    });
    const isClosed = Boolean(orcamento.is_closed || orcamento.status === 'closed');
    const canEditOrcamento = canManageOrcamentos && !isClosed;

    const currentItems = etapas.flatMap((etapa) => etapa.itens ?? []);
    const totalSemBdi = currentItems.reduce(
        (total, item) => total + (Number(item.valor_unitario ?? 0) * Number(item.quantidade ?? 0)),
        0,
    );
    const totalComBdi = currentItems.reduce((total, item) => total + Number(item.valor_total ?? 0), 0);
    const totalBdi = Math.max(0, totalComBdi - totalSemBdi);

    const saveEtapa = () => {
        etapaForm.post(route('tenant.orcamentos.etapas.store', [tenant.slug, orcamento.id]), {
            preserveScroll: true,
            onSuccess: () => {
                etapaForm.reset();
                etapaForm.setData({
                    ordem: '',
                    descricao: '',
                    after_etapa_id: null,
                });
                setAddingAfterEtapaId(null);
                setShowEtapaForm(false);
            },
        });
    };

    const startAddEtapa = (afterEtapa = null) => {
        const targetEtapa = afterEtapa ?? null;
        const nextOrder = targetEtapa ? nextChildEtapaOrder(targetEtapa, etapas) : nextRootEtapaOrder(etapas);

        editEtapaForm.clearErrors();
        setEditingEtapaId(null);
        setEditingItemId(null);
        setCompositionFormEtapaId(null);
        setInsumoFormEtapaId(null);
        setSelectedComposition(null);
        setSelectedInsumo(null);
        etapaForm.clearErrors();
        etapaForm.setData({
            ordem: String(nextOrder),
            descricao: '',
            after_etapa_id: targetEtapa?.id ?? null,
        });
        setAddingAfterEtapaId(targetEtapa?.id ?? null);
        setShowEtapaForm(true);
    };

    const cancelAddEtapa = () => {
        etapaForm.clearErrors();
        etapaForm.reset();
        etapaForm.setData({
            ordem: '',
            descricao: '',
            after_etapa_id: null,
        });
        setAddingAfterEtapaId(null);
        setShowEtapaForm(false);
    };

    const startEditEtapa = (etapa) => {
        etapaForm.clearErrors();
        setShowEtapaForm(false);
        setAddingAfterEtapaId(null);
        setEditingItemId(null);
        setCompositionFormEtapaId(null);
        setInsumoFormEtapaId(null);
        setSelectedComposition(null);
        setSelectedInsumo(null);
        editEtapaForm.clearErrors();
        editEtapaForm.setData({
            ordem: String(etapa.ordem ?? ''),
            descricao: etapa.descricao ?? '',
        });
        setEditingEtapaId(etapa.id);
    };

    const cancelEditEtapa = () => {
        editEtapaForm.clearErrors();
        editEtapaForm.reset();
        setEditingEtapaId(null);
    };

    const saveEditEtapa = () => {
        if (!editingEtapaId) {
            return;
        }

        editEtapaForm.patch(route('tenant.orcamentos.etapas.update', [tenant.slug, orcamento.id, editingEtapaId]), {
            preserveScroll: true,
            onSuccess: () => {
                editEtapaForm.reset();
                setEditingEtapaId(null);
            },
        });
    };

    const toggleEtapaVisibility = (etapa) => {
        router.patch(route('tenant.orcamentos.etapas.toggle-hidden', [tenant.slug, orcamento.id, etapa.id]), {}, {
            preserveScroll: true,
        });
    };

    const confirmDeleteEtapa = () => {
        if (!deleteEtapa) {
            return;
        }

        router.delete(route('tenant.orcamentos.etapas.destroy', [tenant.slug, orcamento.id, deleteEtapa.id]), {
            preserveScroll: true,
            onFinish: () => setDeleteEtapa(null),
        });
    };

    const startAddComposicao = (etapa = null) => {
        const targetEtapa = etapa ?? etapas[etapas.length - 1] ?? null;

        if (!targetEtapa) {
            startAddEtapa();
            return;
        }

        etapaForm.clearErrors();
        editEtapaForm.clearErrors();
        compositionForm.clearErrors();
        setShowEtapaForm(false);
        setAddingAfterEtapaId(null);
        setEditingEtapaId(null);
        setEditingItemId(null);
        setSelectedComposition(null);
        setCompositionOptions([]);
        setCompositionSearch({ codigo: '', descricao: '' });
        insumoForm.clearErrors();
        setInsumoFormEtapaId(null);
        setSelectedInsumo(null);
        setInsumoOptions([]);
        setInsumoSearch({ codigo: '', descricao: '' });
        compositionForm.setData({
            orcamento_composicao_id: '',
            ordem: String(nextDirectChildPosition(targetEtapa, etapas)),
            quantidade: '1',
            aplicar_bdi: false,
        });
        setCompositionFormEtapaId(targetEtapa.id);
    };

    const cancelAddComposicao = () => {
        compositionForm.clearErrors();
        compositionForm.reset();
        setCompositionFormEtapaId(null);
        setSelectedComposition(null);
        setCompositionOptions([]);
        setCompositionSearch({ codigo: '', descricao: '' });
    };

    const startAddInsumo = (etapa = null) => {
        const targetEtapa = etapa ?? etapas[etapas.length - 1] ?? null;

        if (!targetEtapa) {
            startAddEtapa();
            return;
        }

        etapaForm.clearErrors();
        editEtapaForm.clearErrors();
        compositionForm.clearErrors();
        insumoForm.clearErrors();
        setShowEtapaForm(false);
        setAddingAfterEtapaId(null);
        setEditingEtapaId(null);
        setEditingItemId(null);
        setCompositionFormEtapaId(null);
        setSelectedComposition(null);
        setCompositionOptions([]);
        setCompositionSearch({ codigo: '', descricao: '' });
        setSelectedInsumo(null);
        setInsumoOptions([]);
        setInsumoSearch({ codigo: '', descricao: '' });
        insumoForm.setData({
            orcamento_insumo_id: '',
            ordem: String(nextDirectChildPosition(targetEtapa, etapas)),
            quantidade: '1',
            valor_unitario_manual: '',
            aplicar_bdi: false,
        });
        setInsumoFormEtapaId(targetEtapa.id);
    };

    const cancelAddInsumo = () => {
        insumoForm.clearErrors();
        insumoForm.reset();
        setInsumoFormEtapaId(null);
        setSelectedInsumo(null);
        setInsumoOptions([]);
        setInsumoSearch({ codigo: '', descricao: '' });
    };

    const searchCompositions = async (filters = compositionSearch) => {
        const codigo = (filters.codigo ?? '').trim();
        const descricao = (filters.descricao ?? '').trim();

        if (!codigo && !descricao) {
            setCompositionOptions([]);
            return;
        }

        setCompositionLoading(true);

        try {
            const params = new URLSearchParams();

            if (codigo) {
                params.set('codigo', codigo);
            }

            if (descricao) {
                params.set('descricao', descricao);
            }

            const response = await fetch(`${route('tenant.orcamentos.composicoes.options', [tenant.slug, orcamento.id])}?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('Falha ao buscar composicoes.');
            }

            const payload = await response.json();
            setCompositionOptions(payload.options ?? []);
        } catch (error) {
            setCompositionOptions([]);
        } finally {
            setCompositionLoading(false);
        }
    };

    useEffect(() => {
        if (!compositionFormEtapaId) {
            return undefined;
        }

        const codigo = compositionSearch.codigo.trim();
        const descricao = compositionSearch.descricao.trim();
        const matchesSelected = selectedComposition
            && selectedComposition.codigo === codigo
            && selectedComposition.descricao === descricao;

        if (!codigo && !descricao) {
            setCompositionOptions([]);
            setCompositionLoading(false);
            return undefined;
        }

        if (matchesSelected) {
            return undefined;
        }

        const timer = window.setTimeout(() => {
            searchCompositions({ codigo, descricao });
        }, 280);

        return () => window.clearTimeout(timer);
    }, [compositionFormEtapaId, compositionSearch.codigo, compositionSearch.descricao, selectedComposition]);

    const selectComposition = (composition) => {
        setSelectedComposition(composition);
        setCompositionSearch({
            codigo: composition.codigo ?? '',
            descricao: composition.descricao ?? '',
        });
        setCompositionOptions([]);
        compositionForm.setData({
            ...compositionForm.data,
            orcamento_composicao_id: composition.id,
        });
    };

    const updateCompositionSearch = (updater) => {
        setSelectedComposition(null);
        compositionForm.setData({
            ...compositionForm.data,
            orcamento_composicao_id: '',
        });
        setCompositionSearch(updater);
    };

    const saveComposicaoItem = () => {
        if (!compositionFormEtapaId) {
            return;
        }

        compositionForm.post(route('tenant.orcamentos.etapas.composicoes.store', [tenant.slug, orcamento.id, compositionFormEtapaId]), {
            preserveScroll: true,
            onSuccess: () => cancelAddComposicao(),
        });
    };

    const searchInsumos = async (filters = insumoSearch) => {
        const codigo = (filters.codigo ?? '').trim();
        const descricao = (filters.descricao ?? '').trim();

        if (!codigo && !descricao) {
            setInsumoOptions([]);
            return;
        }

        setInsumoLoading(true);

        try {
            const params = new URLSearchParams();

            if (codigo) {
                params.set('codigo', codigo);
            }

            if (descricao) {
                params.set('descricao', descricao);
            }

            const response = await fetch(`${route('tenant.orcamentos.insumos.options', [tenant.slug, orcamento.id])}?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('Falha ao buscar insumos.');
            }

            const payload = await response.json();
            setInsumoOptions(payload.options ?? []);
        } catch (error) {
            setInsumoOptions([]);
        } finally {
            setInsumoLoading(false);
        }
    };

    useEffect(() => {
        if (!insumoFormEtapaId) {
            return undefined;
        }

        const codigo = insumoSearch.codigo.trim();
        const descricao = insumoSearch.descricao.trim();
        const matchesSelected = selectedInsumo
            && selectedInsumo.codigo === codigo
            && selectedInsumo.descricao === descricao;

        if (!codigo && !descricao) {
            setInsumoOptions([]);
            setInsumoLoading(false);
            return undefined;
        }

        if (matchesSelected) {
            return undefined;
        }

        const timer = window.setTimeout(() => {
            searchInsumos({ codigo, descricao });
        }, 280);

        return () => window.clearTimeout(timer);
    }, [insumoFormEtapaId, insumoSearch.codigo, insumoSearch.descricao, selectedInsumo]);

    const selectInsumo = (insumo) => {
        setSelectedInsumo(insumo);
        setInsumoSearch({
            codigo: insumo.codigo ?? '',
            descricao: insumo.descricao ?? '',
        });
        setInsumoOptions([]);
        insumoForm.setData({
            ...insumoForm.data,
            orcamento_insumo_id: insumo.id,
            valor_unitario_manual: '',
        });
    };

    const updateInsumoSearch = (updater) => {
        setSelectedInsumo(null);
        insumoForm.setData({
            ...insumoForm.data,
            orcamento_insumo_id: '',
            valor_unitario_manual: '',
        });
        setInsumoSearch(updater);
    };

    const saveInsumoItem = () => {
        if (!insumoFormEtapaId) {
            return;
        }

        insumoForm.post(route('tenant.orcamentos.etapas.insumos.store', [tenant.slug, orcamento.id, insumoFormEtapaId]), {
            preserveScroll: true,
            onSuccess: () => cancelAddInsumo(),
        });
    };

    const openItemBdi = (item) => {
        bdiForm.clearErrors();
        bdiForm.setData({
            bdi_percentual: formatInputDecimal(item.bdi_percentual ?? orcamento.bdi_percentual ?? 0),
        });
        setBdiItem(item);
    };

    const cancelItemBdi = () => {
        bdiForm.clearErrors();
        bdiForm.reset();
        setBdiItem(null);
    };

    const saveItemBdi = () => {
        if (!bdiItem) {
            return;
        }

        bdiForm.patch(route('tenant.orcamentos.itens.toggle-bdi', [tenant.slug, orcamento.id, bdiItem.id]), {
            preserveScroll: true,
            onSuccess: () => {
                bdiForm.reset();
                setBdiItem(null);
            },
        });
    };

    const startEditItem = (item) => {
        etapaForm.clearErrors();
        editEtapaForm.clearErrors();
        compositionForm.clearErrors();
        insumoForm.clearErrors();
        editItemForm.clearErrors();
        setShowEtapaForm(false);
        setAddingAfterEtapaId(null);
        setEditingEtapaId(null);
        setCompositionFormEtapaId(null);
        setInsumoFormEtapaId(null);
        setSelectedComposition(null);
        setSelectedInsumo(null);
        setCompositionOptions([]);
        setInsumoOptions([]);
        editItemForm.setData({
            ordem: String(item.ordem ?? ''),
            quantidade: formatInputDecimal(item.quantidade ?? 1),
            aplicar_bdi: Boolean(item.aplicar_bdi),
        });
        setEditingItemId(item.id);
    };

    const cancelEditItem = () => {
        editItemForm.clearErrors();
        editItemForm.reset();
        setEditingItemId(null);
    };

    const saveEditItem = () => {
        if (!editingItemId) {
            return;
        }

        editItemForm.patch(route('tenant.orcamentos.itens.update', [tenant.slug, orcamento.id, editingItemId]), {
            preserveScroll: true,
            onSuccess: () => {
                editItemForm.reset();
                setEditingItemId(null);
            },
        });
    };

    const confirmDeleteItem = () => {
        if (!deleteItem) {
            return;
        }

        router.delete(route('tenant.orcamentos.itens.destroy', [tenant.slug, orcamento.id, deleteItem.id]), {
            preserveScroll: true,
            onFinish: () => setDeleteItem(null),
        });
    };

    const toggleReportType = (type) => {
        setSelectedReports((current) => (
            current.includes(type)
                ? current.filter((item) => item !== type)
                : [...current, type]
        ));
    };

    const downloadSelectedReports = () => {
        const reportRoutes = {
            sintetico: route('tenant.orcamentos.relatorios.sintetico', [tenant.slug, orcamento.id]),
            resumo: route('tenant.orcamentos.relatorios.resumo', [tenant.slug, orcamento.id]),
        };

        if (selectedReports.length > 1) {
            const params = new URLSearchParams();
            selectedReports.forEach((type) => params.append('reports[]', type));
            const zipUrl = `${route('tenant.orcamentos.relatorios.zip', [tenant.slug, orcamento.id])}?${params.toString()}`;
            const frame = document.createElement('iframe');
            frame.src = zipUrl;
            frame.style.display = 'none';
            document.body.appendChild(frame);
            window.setTimeout(() => frame.remove(), 60000);
            setReportsModalOpen(false);

            return;
        }

        const reportUrl = reportRoutes[selectedReports[0]];

        if (!reportUrl) {
            setReportsModalOpen(false);

            return;
        }

        const frame = document.createElement('iframe');
        frame.src = reportUrl;
        frame.style.display = 'none';
        document.body.appendChild(frame);
        window.setTimeout(() => frame.remove(), 60000);

        setReportsModalOpen(false);
    };

    const closeCopyDialog = () => {
        if (copySubmitting) {
            return;
        }

        resetCopyDialog();
    };

    const resetCopyDialog = () => {
        setCopyModalOpen(false);
        setCopySourceId('');
        setCopySource(null);
        setCopyEtapas([]);
        setCopySelectedRows(new Set());
    };

    const loadCopySource = async (sourceId) => {
        setCopySourceId(sourceId);
        setCopySource(null);
        setCopyEtapas([]);
        setCopySelectedRows(new Set());

        if (!sourceId) {
            return;
        }

        setCopyLoading(true);

        try {
            const response = await fetch(route('tenant.orcamentos.copy.preview', [tenant.slug, orcamento.id, sourceId]), {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Nao foi possivel carregar o orcamento de origem.');
            }

            const payload = await response.json();
            setCopySource(payload.orcamento);
            setCopyEtapas(payload.etapas ?? []);
        } catch (error) {
            window.alert(error.message);
        } finally {
            setCopyLoading(false);
        }
    };

    const submitCopy = () => {
        const etapaIds = [];
        const itemIds = [];

        copySelectedRows.forEach((rowId) => {
            const [type, id] = String(rowId).split('-');

            if (type === 'etapa') {
                etapaIds.push(Number(id));
            }

            if (type === 'item') {
                itemIds.push(Number(id));
            }
        });

        setCopySubmitting(true);

        router.post(route('tenant.orcamentos.copy.store', [tenant.slug, orcamento.id]), {
            source_orcamento_id: copySourceId,
            etapa_ids: etapaIds,
            item_ids: itemIds,
            price_mode: 'source',
        }, {
            preserveScroll: true,
            onSuccess: resetCopyDialog,
            onFinish: () => setCopySubmitting(false),
        });
    };

    const confirmCloseOrcamento = () => {
        router.patch(route('tenant.orcamentos.close', [tenant.slug, orcamento.id]), {}, {
            preserveScroll: true,
            onSuccess: () => setCloseConfirmOpen(false),
        });
    };

    return (
        <OrcamentoShell
            tenant={tenant}
            active="orcamentos"
            title={`${orcamento.codigo} - ${orcamento.descricao}`}
            subtitle="Monte a estrutura analitica do orcamento por etapas. Na proxima fase, cada etapa recebera composicoes e insumos."
            showNav={false}
        >
            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <Link className="sig-btn sig-btn-secondary" href={route('tenant.orcamentos.index', tenant.slug)}>
                    <ArrowLeft size={15} />
                    Voltar
                </Link>

                <div className="flex flex-wrap items-center gap-2">
                    {canEditOrcamento && (
                        <button
                            className="sig-btn sig-btn-secondary"
                            type="button"
                            onClick={() => setCopyModalOpen(true)}
                        >
                            <Copy size={15} />
                            Copiar orcamento
                        </button>
                    )}

                    {canEditOrcamento && (
                        <button
                            className="sig-btn sig-btn-primary"
                            type="button"
                            onClick={() => setCloseConfirmOpen(true)}
                        >
                            <Check size={15} />
                            Finalizar orçamento
                        </button>
                    )}

                    <button
                        className="sig-btn sig-btn-secondary"
                        type="button"
                        onClick={() => setReportsModalOpen(true)}
                    >
                        <FileSpreadsheet size={15} />
                        Relatórios
                    </button>

                    <span className={`inline-flex rounded-full px-3 py-1 text-xs font-bold ${isClosed ? 'bg-emerald-50 text-emerald-700' : 'bg-[var(--primary-50)] text-[var(--primary)]'}`}>
                        {orcamento.status_label}
                    </span>
                </div>
            </div>

            {page.props.flash?.success && (
                <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                    {page.props.flash.success}
                </div>
            )}

            {page.props.errors?.orcamento && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                    {page.props.errors.orcamento}
                </div>
            )}

            <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_420px]">
                <article className="sig-card p-5">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <span className="eyebrow flex items-center gap-2">
                                <ClipboardList size={14} />
                                Orcamento
                            </span>
                            <h2 className="mt-2 text-xl font-semibold text-[var(--ink-900)]">{orcamento.descricao}</h2>
                            <p className="mono mt-1 text-sm font-bold text-[var(--primary)]">{orcamento.codigo}</p>
                        </div>

                        <div className="grid gap-2 text-sm sm:grid-cols-2 xl:grid-cols-1">
                            <InfoLine label="Cliente" value={orcamento.cliente ?? 'Sem cliente'} />
                            <InfoLine label="Categoria" value={orcamento.categoria} />
                            <InfoLine label="Prazo" value={orcamento.prazo_entrega ?? 'Sem prazo'} />
                            {isClosed ? <InfoLine label="Finalizado em" value={orcamento.closed_at ?? 'Finalizado'} /> : null}
                        </div>
                    </div>
                </article>

                <article className="sig-card overflow-hidden">
                    <header className="border-b border-[var(--border)] px-5 py-4">
                        <span className="eyebrow flex items-center gap-2">
                            <Database size={14} />
                            Regras de calculo
                        </span>
                    </header>
                    <div className="divide-y divide-[var(--border)] text-sm">
                        <SummaryRow
                            label="Bases"
                            value={(orcamento.base_references ?? []).map((reference) => (
                                <span
                                    key={reference.codigo}
                                    className="inline-flex rounded-md bg-[var(--surface-muted)] px-2 py-1 text-[11px] font-bold text-[var(--ink-600)]"
                                >
                                    {reference.nome} - {reference.uf} - {reference.data}
                                </span>
                            ))}
                        />
                        <SummaryRow label="Encargos sociais" value={orcamento.encargos_sociais_label} />
                        <SummaryRow
                            label="BDI"
                            value={
                                <span className="inline-flex items-center gap-2">
                                    <BadgePercent size={14} />
                                    {decimalFormatter.format(Number(orcamento.bdi_percentual ?? 0))}%
                                </span>
                            }
                        />
                    </div>
                </article>
            </section>

            <section className="mt-5 flex flex-wrap gap-3">
                {canEditOrcamento && (
                    <button
                        className="sig-btn budget-toolbar-btn budget-toolbar-btn-etapa"
                        type="button"
                        onClick={() => startAddEtapa()}
                    >
                        <ListTree size={16} />
                    Adicionar etapa
                    </button>
                )}

                <button
                    className="sig-btn budget-toolbar-btn budget-toolbar-btn-composicao"
                    disabled={!canEditOrcamento || etapas.length === 0}
                    type="button"
                    onClick={() => startAddComposicao()}
                >
                    <Blocks size={16} />
                    Adicionar composicao
                </button>

                <button
                    className="sig-btn budget-toolbar-btn budget-toolbar-btn-insumo"
                    disabled={!canEditOrcamento || etapas.length === 0}
                    type="button"
                    onClick={() => startAddInsumo()}
                >
                    <Box size={16} />
                    Adicionar insumo
                </button>
            </section>

            <section className="mt-5 overflow-visible rounded-md border border-[var(--border)] bg-white shadow-[var(--shadow-sm)]">
                <BudgetItemsTable
                    canManage={canEditOrcamento}
                    addingAfterEtapaId={addingAfterEtapaId}
                    compositionForm={compositionForm}
                    compositionFormEtapaId={compositionFormEtapaId}
                    compositionLoading={compositionLoading}
                    compositionOptions={compositionOptions}
                    compositionSearch={compositionSearch}
                    editItemForm={editItemForm}
                    editEtapaForm={editEtapaForm}
                    editingItemId={editingItemId}
                    editingEtapaId={editingEtapaId}
                    encargosSociais={orcamento.encargos_sociais}
                    etapaForm={etapaForm}
                    etapas={etapas}
                    insumoForm={insumoForm}
                    insumoFormEtapaId={insumoFormEtapaId}
                    insumoLoading={insumoLoading}
                    insumoOptions={insumoOptions}
                    insumoSearch={insumoSearch}
                    permitirInsumosPrecoZerado={orcamento.permitir_insumos_preco_zerado}
                    onAddComposicao={startAddComposicao}
                    onAddEtapa={startAddEtapa}
                    onAddInsumo={startAddInsumo}
                    onCancelComposicao={cancelAddComposicao}
                    onCancelEditEtapa={cancelEditEtapa}
                    onCancelEditItem={cancelEditItem}
                    onCancelEtapa={cancelAddEtapa}
                    onCancelInsumo={cancelAddInsumo}
                    onDeleteEtapa={setDeleteEtapa}
                    onDeleteItem={setDeleteItem}
                    onEditEtapa={startEditEtapa}
                    onEditItem={startEditItem}
                    onSaveComposicao={saveComposicaoItem}
                    onSaveEtapa={saveEtapa}
                    onSaveEditEtapa={saveEditEtapa}
                    onSaveEditItem={saveEditItem}
                    onSaveInsumo={saveInsumoItem}
                    onSelectComposicao={selectComposition}
                    onSelectInsumo={selectInsumo}
                    onSetCompositionSearch={updateCompositionSearch}
                    onSetInsumoSearch={updateInsumoSearch}
                    onToggleItemBdi={openItemBdi}
                    onToggleEtapaVisibility={toggleEtapaVisibility}
                    selectedComposition={selectedComposition}
                    selectedInsumo={selectedInsumo}
                    showEtapaForm={showEtapaForm}
                />
            </section>

            <section className="mt-4 grid gap-2 lg:ml-auto lg:max-w-2xl">
                <BudgetTotalLine label="Total sem BDI" value={formatCurrency(totalSemBdi)} />
                <BudgetTotalLine label="Total do BDI" value={formatCurrency(totalBdi)} />
                <div className="flex items-center justify-end gap-10 border-t border-[var(--border)] px-2 py-4">
                    <span className="text-xs font-bold uppercase tracking-[0.08em] text-[var(--ink-500)]">Total</span>
                    <strong className="mono text-3xl text-[var(--ink-900)]">{formatCurrency(totalComBdi)}</strong>
                </div>
            </section>

            {reportsModalOpen && (
                <ReportsDialog
                    selectedReports={selectedReports}
                    onCancel={() => setReportsModalOpen(false)}
                    onDownload={downloadSelectedReports}
                    onToggle={toggleReportType}
                />
            )}

            {copyModalOpen && (
                <CopyBudgetDialog
                    copyEtapas={copyEtapas}
                    copyLoading={copyLoading}
                    copySelectedRows={copySelectedRows}
                    copySource={copySource}
                    copySourceId={copySourceId}
                    copySources={copySources}
                    copySubmitting={copySubmitting}
                    currentOrcamento={orcamento}
                    onCancel={closeCopyDialog}
                    onLoadSource={loadCopySource}
                    onSelectedRowsChange={setCopySelectedRows}
                    onSubmit={submitCopy}
                />
            )}

            {closeConfirmOpen && (
                <CloseBudgetDialog
                    orcamento={orcamento}
                    onCancel={() => setCloseConfirmOpen(false)}
                    onConfirm={confirmCloseOrcamento}
                />
            )}

            {deleteEtapa && (
                <DeleteEtapaDialog
                    etapa={deleteEtapa}
                    onCancel={() => setDeleteEtapa(null)}
                    onConfirm={confirmDeleteEtapa}
                />
            )}

            {deleteItem && (
                <DeleteItemDialog
                    item={deleteItem}
                    onCancel={() => setDeleteItem(null)}
                    onConfirm={confirmDeleteItem}
                />
            )}

            {bdiItem && (
                <BdiItemDialog
                    form={bdiForm}
                    item={bdiItem}
                    onCancel={cancelItemBdi}
                    onSave={saveItemBdi}
                />
            )}
        </OrcamentoShell>
    );
}

function BudgetItemsTable({
    addingAfterEtapaId,
    canManage,
    compositionForm,
    compositionFormEtapaId,
    compositionLoading,
    compositionOptions,
    compositionSearch,
    editItemForm,
    editEtapaForm,
    editingItemId,
    editingEtapaId,
    encargosSociais,
    etapaForm,
    etapas,
    insumoForm,
    insumoFormEtapaId,
    insumoLoading,
    insumoOptions,
    insumoSearch,
    permitirInsumosPrecoZerado,
    onAddComposicao,
    onAddEtapa,
    onAddInsumo,
    onCancelComposicao,
    onCancelEditEtapa,
    onCancelEditItem,
    onCancelEtapa,
    onCancelInsumo,
    onDeleteEtapa,
    onDeleteItem,
    onEditEtapa,
    onEditItem,
    onSaveComposicao,
    onSaveEditEtapa,
    onSaveEditItem,
    onSaveEtapa,
    onSaveInsumo,
    onSelectComposicao,
    onSelectInsumo,
    onSetCompositionSearch,
    onSetInsumoSearch,
    onToggleItemBdi,
    onToggleEtapaVisibility,
    selectedComposition,
    selectedInsumo,
    showEtapaForm,
}) {
    const shouldRenderFormAtStart = showEtapaForm && etapas.length === 0;
    const formAnchorExists = etapas.some((etapa) => etapa.id === addingAfterEtapaId);
    const shouldRenderFormAtEnd = showEtapaForm && etapas.length > 0 && !formAnchorExists;
    const childEtapasByParent = etapas.reduce((map, etapa) => {
        const key = parentEtapaOrder(etapa.item ?? etapa.ordem) ?? '';

        if (!map.has(key)) {
            map.set(key, []);
        }

        map.get(key).push(etapa);

        return map;
    }, new Map());
    const rootEtapas = childEtapasByParent.get('') ?? [];
    const renderEtapaBranch = (etapa, level = 0) => {
        const etapaOrder = String(etapa.item ?? etapa.ordem ?? '');
        const childEtapas = childEtapasByParent.get(etapaOrder) ?? [];
        const childEntries = etapa.is_hidden
            ? []
            : [
                ...(etapa.itens ?? []).map((item) => ({
                    key: `item-${item.id}`,
                    item,
                    position: Number(item.ordem ?? 0),
                    type: 'item',
                })),
                ...childEtapas.map((childEtapa) => ({
                    etapa: childEtapa,
                    key: `etapa-${childEtapa.id}`,
                    position: lastOrderSegment(childEtapa.item ?? childEtapa.ordem),
                    type: 'etapa',
                })),
            ].sort((a, b) => a.position - b.position || (a.type === 'etapa' ? -1 : 1));

        return (
            <Fragment key={`branch-${etapa.id}`}>
                {editingEtapaId === etapa.id ? (
                    <EtapaEditRow
                        form={editEtapaForm}
                        key={`edit-${etapa.id}`}
                        onCancel={onCancelEditEtapa}
                        onSave={onSaveEditEtapa}
                    />
                ) : (
                    <EtapaRow
                        canManage={canManage}
                        etapa={etapa}
                        key={`etapa-${etapa.id}`}
                        level={level}
                        onAddComposicao={onAddComposicao}
                        onAddEtapa={onAddEtapa}
                        onAddInsumo={onAddInsumo}
                        onDeleteEtapa={onDeleteEtapa}
                        onEditEtapa={onEditEtapa}
                        onToggleEtapaVisibility={onToggleEtapaVisibility}
                    />
                )}

                {childEntries.map((entry) => {
                    if (entry.type === 'etapa') {
                        return renderEtapaBranch(entry.etapa, level + 1);
                    }

                    const item = entry.item;

                    return editingItemId === item.id ? (
                        <BudgetItemEditRow
                            form={editItemForm}
                            item={item}
                            key={`edit-item-${item.id}`}
                            onCancel={onCancelEditItem}
                            onSave={onSaveEditItem}
                        />
                    ) : (
                        <BudgetItemRow
                            canManage={canManage}
                            etapa={etapa}
                            item={item}
                            key={`item-${item.id}`}
                            level={level + 1}
                            onAddComposicao={onAddComposicao}
                            onAddEtapa={onAddEtapa}
                            onAddInsumo={onAddInsumo}
                            onDeleteItem={onDeleteItem}
                            onEditItem={onEditItem}
                            onToggleItemBdi={onToggleItemBdi}
                        />
                    );
                })}

                {compositionFormEtapaId === etapa.id && (
                    <ComposicaoFormRow
                        form={compositionForm}
                        options={compositionOptions}
                        loading={compositionLoading}
                        search={compositionSearch}
                        selectedComposition={selectedComposition}
                        encargosSociais={encargosSociais}
                        etapa={etapa}
                        onCancel={onCancelComposicao}
                        onSave={onSaveComposicao}
                        onSelect={onSelectComposicao}
                        onSetSearch={onSetCompositionSearch}
                    />
                )}

                {insumoFormEtapaId === etapa.id && (
                    <InsumoFormRow
                        encargosSociais={encargosSociais}
                        etapa={etapa}
                        form={insumoForm}
                        loading={insumoLoading}
                        onCancel={onCancelInsumo}
                        onSave={onSaveInsumo}
                        onSelect={onSelectInsumo}
                        onSetSearch={onSetInsumoSearch}
                        options={insumoOptions}
                        permitirInsumosPrecoZerado={permitirInsumosPrecoZerado}
                        search={insumoSearch}
                        selectedInsumo={selectedInsumo}
                    />
                )}

                {showEtapaForm && addingAfterEtapaId === etapa.id && (
                    <EtapaFormRow
                        form={etapaForm}
                        key={`new-after-${etapa.id}`}
                        onCancel={onCancelEtapa}
                        onSave={onSaveEtapa}
                    />
                )}
            </Fragment>
        );
    };

    return (
        <div className="budget-table-wrap">
            <table className="budget-table">
                <colgroup>
                    <col className="budget-col-arrow" />
                    <col className="budget-col-item" />
                    <col className="budget-col-code" />
                    <col className="budget-col-bank" />
                    <col />
                    <col className="budget-col-unit" />
                    <col className="budget-col-quantity" />
                    <col className="budget-col-money" />
                    <col className="budget-col-money" />
                    <col className="budget-col-total" />
                </colgroup>
                <thead>
                    <tr>
                        <th className="budget-text-center">
                            <span className="budget-arrow budget-arrow-white" />
                        </th>
                        <HeaderCell>Item</HeaderCell>
                        <HeaderCell>Codigo</HeaderCell>
                        <HeaderCell>Banco</HeaderCell>
                        <HeaderCell>Descricao</HeaderCell>
                        <HeaderCell>Und</HeaderCell>
                        <HeaderCell align="right">Quant.</HeaderCell>
                        <HeaderCell align="right">Valor unit</HeaderCell>
                        <HeaderCell align="right">Valor com BDI</HeaderCell>
                        <HeaderCell align="right">Total</HeaderCell>
                    </tr>
                </thead>
                <tbody>
                    {shouldRenderFormAtStart && (
                        <EtapaFormRow
                            form={etapaForm}
                            onCancel={onCancelEtapa}
                            onSave={onSaveEtapa}
                        />
                    )}

                    {rootEtapas.map((etapa) => renderEtapaBranch(etapa))}

                    {shouldRenderFormAtEnd && (
                        <EtapaFormRow
                            form={etapaForm}
                            onCancel={onCancelEtapa}
                            onSave={onSaveEtapa}
                        />
                    )}

                    {etapas.length === 0 && !showEtapaForm && (
                        <tr>
                            <td className="budget-empty" colSpan={10}>
                                Clique em adicionar etapa para criar a primeira divisao do orcamento.
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

function EtapaRow({ canManage, etapa, level = 0, onAddComposicao, onAddEtapa, onAddInsumo, onDeleteEtapa, onEditEtapa, onToggleEtapaVisibility }) {
    const ToggleIcon = etapa.is_hidden ? Eye : EyeOff;

    return (
        <tr className={`budget-row-etapa ${etapa.is_hidden ? 'budget-row-hidden' : ''}`}>
            <td className="budget-cell budget-hover-cell budget-text-center">
                <span className="budget-arrow" />
                {canManage && (
                    <div className="budget-action-menu">
                        <HoverAction tone="blue" icon={ListTree} onClick={() => onAddEtapa(etapa)}>Etapa</HoverAction>
                        <HoverAction tone="green" icon={Blocks} onClick={() => onAddComposicao(etapa)}>Composicao</HoverAction>
                        <HoverAction tone="yellow" icon={Box} onClick={() => onAddInsumo(etapa)}>Insumo</HoverAction>
                        <HoverAction tone="dark" icon={Pencil} onClick={() => onEditEtapa(etapa)}>Editar</HoverAction>
                        <HoverAction tone="dark" icon={ToggleIcon} onClick={() => onToggleEtapaVisibility(etapa)}>
                            {etapa.is_hidden ? 'Mostrar' : 'Ocultar'}
                        </HoverAction>
                        <HoverAction tone="red" icon={Trash2} onClick={() => onDeleteEtapa(etapa)}>Excluir</HoverAction>
                    </div>
                )}
            </td>
            <BudgetCell align="center" strong>{etapa.item}</BudgetCell>
            <BudgetCell />
            <BudgetCell />
            <BudgetCell strong uppercase>
                <span className="budget-indent" style={{ '--budget-depth': level }}>{etapa.descricao}</span>
                {etapa.is_hidden && <span className="budget-hidden-pill">Itens ocultos</span>}
            </BudgetCell>
            <BudgetCell />
            <BudgetCell align="right" />
            <BudgetCell align="right" />
            <BudgetCell align="right" />
            <BudgetCell align="right" strong>{formatPlainMoney(etapa.valor_total)}</BudgetCell>
        </tr>
    );
}

function BudgetItemRow({ canManage, etapa, item, level = 1, onAddComposicao, onAddEtapa, onAddInsumo, onDeleteItem, onEditItem, onToggleItemBdi }) {
    const isInsumo = item.item_type === 'insumo';

    return (
        <tr className={isInsumo ? 'budget-row-insumo' : 'budget-row-composicao'}>
            <td className="budget-cell budget-hover-cell budget-text-center">
                <span className="budget-item-kind">{isInsumo ? 'ins' : 'comp'}</span>
                {canManage && (
                    <div className="budget-action-menu">
                        <HoverAction tone="blue" icon={ListTree} onClick={() => onAddEtapa(etapa)}>Etapa</HoverAction>
                        <HoverAction tone="green" icon={Blocks} onClick={() => onAddComposicao(etapa)}>Composicao</HoverAction>
                        <HoverAction tone="yellow" icon={Box} onClick={() => onAddInsumo(etapa)}>Insumo</HoverAction>
                        <HoverAction tone="dark" icon={Pencil} onClick={() => onEditItem(item)}>Editar</HoverAction>
                        <HoverAction
                            tone={item.aplicar_bdi ? 'blue' : 'dark'}
                            icon={BadgePercent}
                            onClick={() => onToggleItemBdi(item)}
                        >
                            BDI
                        </HoverAction>
                        <HoverAction tone="red" icon={Trash2} onClick={() => onDeleteItem(item)}>Excluir</HoverAction>
                    </div>
                )}
            </td>
            <BudgetCell align="center" strong>{item.item}</BudgetCell>
            <BudgetCell strong>{item.codigo}</BudgetCell>
            <BudgetCell>{item.banco}</BudgetCell>
            <BudgetCell strong>
                <span className="budget-indent" style={{ '--budget-depth': level }}>{item.descricao}</span>
            </BudgetCell>
            <BudgetCell>{item.unidade}</BudgetCell>
            <BudgetCell align="right">{formatPlainMoney(item.quantidade)}</BudgetCell>
            <BudgetCell align="right">{formatPlainMoney(item.valor_unitario)}</BudgetCell>
            <BudgetCell align="right">
                <span className={item.aplicar_bdi ? 'budget-bdi-active' : ''}>
                    {formatPlainMoney(item.valor_com_bdi)}
                </span>
            </BudgetCell>
            <BudgetCell align="right" strong>{formatPlainMoney(item.valor_total)}</BudgetCell>
        </tr>
    );
}

function BudgetItemEditRow({ form, item, onCancel, onSave }) {
    const isInsumo = item.item_type === 'insumo';

    return (
        <tr className={isInsumo ? 'budget-row-insumo' : 'budget-row-composicao'}>
            <td className="budget-cell budget-text-center">
                <span className="budget-item-kind">{isInsumo ? 'ins' : 'comp'}</span>
            </td>
            <td className="budget-cell">
                <input
                    className="budget-input budget-input-center"
                    min="1"
                    type="number"
                    value={form.data.ordem}
                    onChange={(event) => form.setData('ordem', event.target.value)}
                />
                {form.errors.ordem && <ErrorText>{form.errors.ordem}</ErrorText>}
            </td>
            <BudgetCell strong>{item.codigo}</BudgetCell>
            <BudgetCell>{item.banco}</BudgetCell>
            <BudgetCell strong>{item.descricao}</BudgetCell>
            <BudgetCell>{item.unidade}</BudgetCell>
            <td className="budget-cell">
                <input
                    className="budget-input budget-input-right"
                    placeholder="Quant."
                    value={form.data.quantidade}
                    onChange={(event) => form.setData('quantidade', event.target.value)}
                />
                {form.errors.quantidade && <ErrorText>{form.errors.quantidade}</ErrorText>}
            </td>
            <BudgetCell align="right">{formatPlainMoney(item.valor_unitario)}</BudgetCell>
            <td className="budget-cell budget-text-center">
                <label className="budget-bdi-check" title="Aplicar BDI neste item">
                    <input
                        checked={Boolean(form.data.aplicar_bdi)}
                        type="checkbox"
                        onChange={(event) => form.setData('aplicar_bdi', event.target.checked)}
                    />
                    <BadgePercent size={14} />
                    BDI
                </label>
            </td>
            <td className="budget-cell">
                <div className="budget-edit-actions">
                    <button
                        className="budget-save-btn"
                        disabled={form.processing}
                        type="button"
                        onClick={onSave}
                    >
                        <Check size={17} strokeWidth={3} />
                    </button>
                    <button
                        className="budget-cancel-btn"
                        type="button"
                        onClick={onCancel}
                    >
                        <X size={16} strokeWidth={3} />
                    </button>
                </div>
            </td>
        </tr>
    );
}

function ComposicaoFormRow({
    encargosSociais,
    etapa,
    form,
    loading,
    onCancel,
    onSave,
    onSelect,
    onSetSearch,
    options,
    search,
    selectedComposition,
}) {
    const itemLabel = `${etapa.item}.${form.data.ordem || ((etapa.itens?.length ?? 0) + 1)}`;
    const selectedUnitPrice = encargosSociais === 'nao_desonerado'
        ? selectedComposition?.preco_unitario_nao_desonerado
        : selectedComposition?.preco_unitario_desonerado;

    return (
        <Fragment>
            <tr className="budget-row-composicao budget-row-form">
                <td className="budget-cell budget-text-center">
                    <span className="budget-item-kind">comp</span>
                </td>
                <td className="budget-cell">
                    <input
                        className="budget-input budget-input-center"
                        min="1"
                        type="number"
                        value={form.data.ordem}
                        onChange={(event) => form.setData('ordem', event.target.value)}
                    />
                    <span className="budget-inline-hint">{itemLabel}</span>
                    {form.errors.ordem && <ErrorText>{form.errors.ordem}</ErrorText>}
                </td>
                <td className="budget-cell">
                    <input
                        className="budget-input"
                        placeholder="Codigo"
                        value={search.codigo}
                        onChange={(event) => onSetSearch((current) => ({ ...current, codigo: event.target.value }))}
                    />
                </td>
                <td className="budget-cell">
                    <span className="budget-form-value">{selectedComposition?.base ?? '-'}</span>
                </td>
                <td className="budget-cell">
                    <div className="budget-composition-search">
                        <input
                            className="budget-input"
                            placeholder="Descricao"
                            value={search.descricao}
                            onChange={(event) => onSetSearch((current) => ({ ...current, descricao: event.target.value }))}
                        />
                        {form.errors.orcamento_composicao_id && <ErrorText>{form.errors.orcamento_composicao_id}</ErrorText>}
                    </div>
                </td>
                <td className="budget-cell">
                    <span className="budget-form-value">{selectedComposition?.unidade ?? '-'}</span>
                </td>
                <td className="budget-cell">
                    <input
                        className="budget-input budget-input-right"
                        placeholder="Quant."
                        value={form.data.quantidade}
                        onChange={(event) => form.setData('quantidade', event.target.value)}
                    />
                    {form.errors.quantidade && <ErrorText>{form.errors.quantidade}</ErrorText>}
                </td>
                <td className="budget-cell budget-text-right">
                    {formatPlainMoney(selectedUnitPrice ?? 0)}
                </td>
                <td className="budget-cell budget-text-center">
                    <button
                        className="budget-save-btn"
                        disabled={form.processing || !selectedComposition}
                        type="button"
                        onClick={onSave}
                    >
                        <Check size={17} strokeWidth={3} />
                    </button>
                </td>
                <td className="budget-cell budget-text-right">
                    <button
                        className="budget-cancel-btn"
                        type="button"
                        onClick={onCancel}
                    >
                        <X size={16} strokeWidth={3} />
                    </button>
                </td>
            </tr>
            <ComposicaoOptionsRow
                encargosSociais={encargosSociais}
                loading={loading}
                onSelect={onSelect}
                options={options}
                search={search}
            />
        </Fragment>
    );
}

function ComposicaoOptionsRow({ encargosSociais, loading, onSelect, options, search }) {
    const hasSearch = Boolean((search.codigo ?? '').trim() || (search.descricao ?? '').trim());

    if (!loading && !hasSearch && options.length === 0) {
        return null;
    }

    return (
        <tr className="budget-options-row">
            <td className="budget-options-cell" colSpan={10}>
                {loading ? (
                    <div className="budget-options-empty">
                        <Loader2 className="animate-spin" size={14} />
                        Buscando composicoes...
                    </div>
                ) : options.length === 0 ? (
                    <div className="budget-options-empty">
                        Nenhuma composicao encontrada para as bases deste orcamento.
                    </div>
                ) : (
                    <table className="budget-options-table">
                        <thead>
                            <tr>
                                <th>Codigo</th>
                                <th>Descricao</th>
                                <th>Unidade</th>
                                <th>Data</th>
                                <th className="budget-text-right">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            {options.map((option) => {
                                const value = encargosSociais === 'nao_desonerado'
                                    ? option.preco_unitario_nao_desonerado
                                    : option.preco_unitario_desonerado;

                                return (
                                    <tr key={option.id} onClick={() => onSelect(option)}>
                                        <td className="mono">{option.codigo}</td>
                                        <td>{option.descricao}</td>
                                        <td>{option.unidade ?? '-'}</td>
                                        <td>{option.data ?? '-'}</td>
                                        <td className="budget-text-right">{formatPlainMoney(value ?? 0)}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </td>
        </tr>
    );
}

function EtapaEditRow({ form, onCancel, onSave }) {
    return (
        <tr className="budget-row-form">
            <td className="budget-cell budget-text-center">
                <span className="budget-arrow" />
            </td>
            <td className="budget-cell">
                <input
                    className="budget-input budget-input-center"
                    inputMode="decimal"
                    placeholder="3.2"
                    type="text"
                    value={form.data.ordem}
                    onChange={(event) => form.setData('ordem', event.target.value)}
                />
                {form.errors.ordem && <ErrorText>{form.errors.ordem}</ErrorText>}
            </td>
            <td className="budget-cell" />
            <td className="budget-cell" />
            <td className="budget-cell">
                <input
                    className="budget-input"
                    placeholder="Descricao"
                    value={form.data.descricao}
                    onChange={(event) => form.setData('descricao', event.target.value)}
                />
                {form.errors.descricao && <ErrorText>{form.errors.descricao}</ErrorText>}
            </td>
            <td className="budget-cell" />
            <td className="budget-cell" />
            <td className="budget-cell" />
            <td className="budget-cell budget-text-center">
                <button
                    className="budget-save-btn"
                    disabled={form.processing}
                    type="button"
                    onClick={onSave}
                >
                    <Check size={17} strokeWidth={3} />
                </button>
            </td>
            <td className="budget-cell budget-text-right">
                <button
                    className="budget-cancel-btn"
                    type="button"
                    onClick={onCancel}
                >
                    <X size={16} strokeWidth={3} />
                </button>
            </td>
        </tr>
    );
}

function InsumoFormRow({
    encargosSociais,
    etapa,
    form,
    loading,
    onCancel,
    onSave,
    onSelect,
    onSetSearch,
    options,
    permitirInsumosPrecoZerado,
    search,
    selectedInsumo,
}) {
    const itemLabel = `${etapa.item}.${form.data.ordem || ((etapa.itens?.length ?? 0) + 1)}`;
    const selectedUnitPrice = encargosSociais === 'nao_desonerado'
        ? selectedInsumo?.preco_unitario_nao_desonerado
        : selectedInsumo?.preco_unitario_desonerado;
    const needsManualPrice = Boolean(selectedInsumo && Number(selectedUnitPrice ?? 0) <= 0);
    const canSave = selectedInsumo && (!needsManualPrice || String(form.data.valor_unitario_manual ?? '').trim());

    return (
        <Fragment>
            <tr className="budget-row-insumo budget-row-form">
                <td className="budget-cell budget-text-center">
                    <span className="budget-item-kind">ins</span>
                </td>
                <td className="budget-cell">
                    <input
                        className="budget-input budget-input-center"
                        min="1"
                        type="number"
                        value={form.data.ordem}
                        onChange={(event) => form.setData('ordem', event.target.value)}
                    />
                    <span className="budget-inline-hint">{itemLabel}</span>
                    {form.errors.ordem && <ErrorText>{form.errors.ordem}</ErrorText>}
                </td>
                <td className="budget-cell">
                    <input
                        className="budget-input"
                        placeholder="Codigo"
                        value={search.codigo}
                        onChange={(event) => onSetSearch((current) => ({ ...current, codigo: event.target.value }))}
                    />
                </td>
                <td className="budget-cell">
                    <span className="budget-form-value">{selectedInsumo?.base ?? '-'}</span>
                </td>
                <td className="budget-cell">
                    <div className="budget-composition-search">
                        <input
                            className="budget-input"
                            placeholder="Descricao"
                            value={search.descricao}
                            onChange={(event) => onSetSearch((current) => ({ ...current, descricao: event.target.value }))}
                        />
                        {form.errors.orcamento_insumo_id && <ErrorText>{form.errors.orcamento_insumo_id}</ErrorText>}
                    </div>
                </td>
                <td className="budget-cell">
                    <span className="budget-form-value">{selectedInsumo?.unidade ?? '-'}</span>
                </td>
                <td className="budget-cell">
                    <input
                        className="budget-input budget-input-right"
                        placeholder="Quant."
                        value={form.data.quantidade}
                        onChange={(event) => form.setData('quantidade', event.target.value)}
                    />
                    {form.errors.quantidade && <ErrorText>{form.errors.quantidade}</ErrorText>}
                </td>
                <td className="budget-cell budget-text-right">
                    {needsManualPrice ? (
                        <div>
                            <input
                                className="budget-input budget-input-right"
                                placeholder="Preco unitario"
                                value={form.data.valor_unitario_manual}
                                onChange={(event) => form.setData('valor_unitario_manual', event.target.value)}
                            />
                            {form.errors.valor_unitario_manual && <ErrorText>{form.errors.valor_unitario_manual}</ErrorText>}
                        </div>
                    ) : (
                        formatPlainMoney(selectedUnitPrice ?? 0)
                    )}
                </td>
                <td className="budget-cell budget-text-center">
                    <button
                        className="budget-save-btn"
                        disabled={form.processing || !canSave}
                        type="button"
                        onClick={onSave}
                    >
                        <Check size={17} strokeWidth={3} />
                    </button>
                </td>
                <td className="budget-cell budget-text-right">
                    <button
                        className="budget-cancel-btn"
                        type="button"
                        onClick={onCancel}
                    >
                        <X size={16} strokeWidth={3} />
                    </button>
                </td>
            </tr>

            {needsManualPrice && permitirInsumosPrecoZerado && (
                <tr className="budget-zero-price-row">
                    <td colSpan={10}>
                        Foi encontrado um insumo com preco unitario igual a zero. Informe o valor deste insumo para este orcamento.
                    </td>
                </tr>
            )}

            <InsumoOptionsRow
                encargosSociais={encargosSociais}
                loading={loading}
                onSelect={onSelect}
                options={options}
                search={search}
            />
        </Fragment>
    );
}

function InsumoOptionsRow({ encargosSociais, loading, onSelect, options, search }) {
    const hasSearch = Boolean((search.codigo ?? '').trim() || (search.descricao ?? '').trim());

    if (!loading && !hasSearch && options.length === 0) {
        return null;
    }

    return (
        <tr className="budget-options-row">
            <td className="budget-options-cell" colSpan={10}>
                {loading ? (
                    <div className="budget-options-empty">
                        <Loader2 className="animate-spin" size={14} />
                        Buscando insumos...
                    </div>
                ) : options.length === 0 ? (
                    <div className="budget-options-empty">
                        Nenhum insumo encontrado para as bases deste orcamento.
                    </div>
                ) : (
                    <table className="budget-options-table">
                        <thead>
                            <tr>
                                <th>Codigo</th>
                                <th>Descricao</th>
                                <th>Unidade</th>
                                <th>Data</th>
                                <th className="budget-text-right">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            {options.map((option) => {
                                const value = encargosSociais === 'nao_desonerado'
                                    ? option.preco_unitario_nao_desonerado
                                    : option.preco_unitario_desonerado;

                                return (
                                    <tr key={option.id} onClick={() => onSelect(option)}>
                                        <td className="mono">{option.codigo}</td>
                                        <td>{option.descricao}</td>
                                        <td>{option.unidade ?? '-'}</td>
                                        <td>{option.data ?? '-'}</td>
                                        <td className="budget-text-right">{formatPlainMoney(value ?? 0)}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </td>
        </tr>
    );
}

function EtapaFormRow({ form, onCancel, onSave }) {
    return (
        <tr className="budget-row-form">
            <td className="budget-cell budget-text-center">
                <span className="budget-arrow" />
            </td>
            <td className="budget-cell">
                <input
                    className="budget-input budget-input-center"
                    inputMode="decimal"
                    placeholder="3.2"
                    type="text"
                    value={form.data.ordem}
                    onChange={(event) => form.setData('ordem', event.target.value)}
                />
                {form.errors.ordem && <ErrorText>{form.errors.ordem}</ErrorText>}
            </td>
            <td className="budget-cell" />
            <td className="budget-cell" />
            <td className="budget-cell">
                <input
                    className="budget-input"
                    placeholder="Descricao"
                    value={form.data.descricao}
                    onChange={(event) => form.setData('descricao', event.target.value)}
                />
                {form.errors.descricao && <ErrorText>{form.errors.descricao}</ErrorText>}
            </td>
            <td className="budget-cell" />
            <td className="budget-cell" />
            <td className="budget-cell" />
            <td className="budget-cell budget-text-center">
                <button
                    className="budget-save-btn"
                    disabled={form.processing}
                    type="button"
                    onClick={onSave}
                >
                    <Check size={17} strokeWidth={3} />
                </button>
            </td>
            <td className="budget-cell budget-text-right">
                <button
                    className="budget-cancel-btn"
                    type="button"
                    onClick={onCancel}
                >
                    <X size={16} strokeWidth={3} />
                </button>
            </td>
        </tr>
    );
}

function HeaderCell({ align = 'left', children }) {
    return (
        <th className={align === 'right' ? 'budget-text-right' : 'budget-text-left'}>
            {children}
        </th>
    );
}

function BudgetCell({ align = 'left', children = '', strong = false, uppercase = false }) {
    return (
        <td
            className={`budget-cell ${
                align === 'center' ? 'budget-text-center' : align === 'right' ? 'budget-text-right' : 'budget-text-left'
            } ${strong ? 'budget-strong' : ''} ${uppercase ? 'budget-uppercase' : ''}`}
        >
            {children}
        </td>
    );
}

function HoverAction({ children, icon: Icon, onClick, tone }) {
    return (
        <button
            className={`budget-hover-action budget-hover-action-${tone}`}
            type="button"
            onClick={onClick}
        >
            <Icon size={15} strokeWidth={2.5} />
            {children}
        </button>
    );
}

const flattenCopyRows = (etapas = []) => {
    const childEtapasByParent = etapas.reduce((map, etapa) => {
        const key = parentEtapaOrder(etapa.item ?? etapa.ordem) ?? '';

        if (!map.has(key)) {
            map.set(key, []);
        }

        map.get(key).push(etapa);

        return map;
    }, new Map());
    const rows = [];

    const pushBranch = (etapa, level = 0) => {
        const etapaOrder = String(etapa.item ?? etapa.ordem ?? '');
        const childEtapas = childEtapasByParent.get(etapaOrder) ?? [];

        rows.push({
            banco: '-',
            codigo: '-',
            descricao: etapa.descricao,
            item: etapa.item,
            kind: 'etapa',
            level,
            rowId: `etapa-${etapa.id}`,
            total: etapa.valor_total,
            unidade: '-',
        });

        [
            ...(etapa.itens ?? []).map((item) => ({
                item,
                position: Number(item.ordem ?? 0),
                type: 'item',
            })),
            ...childEtapas.map((childEtapa) => ({
                etapa: childEtapa,
                position: lastOrderSegment(childEtapa.item ?? childEtapa.ordem),
                type: 'etapa',
            })),
        ]
            .sort((a, b) => a.position - b.position || (a.type === 'etapa' ? -1 : 1))
            .forEach((entry) => {
                if (entry.type === 'etapa') {
                    pushBranch(entry.etapa, level + 1);

                    return;
                }

                rows.push({
                    banco: entry.item.banco,
                    codigo: entry.item.codigo,
                    descricao: entry.item.descricao,
                    item: entry.item.item,
                    kind: entry.item.item_type === 'insumo' ? 'insumo' : 'composicao',
                    level: level + 1,
                    rowId: `item-${entry.item.id}`,
                    total: entry.item.valor_total,
                    unidade: entry.item.unidade,
                    quantidade: entry.item.quantidade,
                    valorUnitario: entry.item.valor_unitario,
                    valorComBdi: entry.item.valor_com_bdi,
                });
            });
    };

    (childEtapasByParent.get('') ?? []).forEach((etapa) => pushBranch(etapa));

    return rows;
};

function CopyBudgetDialog({
    copyEtapas,
    copyLoading,
    copySelectedRows,
    copySource,
    copySourceId,
    copySources,
    copySubmitting,
    currentOrcamento,
    onCancel,
    onLoadSource,
    onSelectedRowsChange,
    onSubmit,
}) {
    const rows = flattenCopyRows(copyEtapas);
    const selectedCount = copySelectedRows.size;
    const allSelected = rows.length > 0 && rows.every((row) => copySelectedRows.has(row.rowId));
    const activeStep = copySubmitting ? 3 : (copySource ? 2 : 1);

    const toggleRow = (row) => {
        const relatedRows = row.kind === 'etapa'
            ? rows.filter((candidate) => candidate.item === row.item || String(candidate.item).startsWith(`${row.item}.`))
            : [row];
        const shouldSelect = relatedRows.some((candidate) => !copySelectedRows.has(candidate.rowId));
        const next = new Set(copySelectedRows);

        relatedRows.forEach((candidate) => {
            if (shouldSelect) {
                next.add(candidate.rowId);
            } else {
                next.delete(candidate.rowId);
            }
        });

        onSelectedRowsChange(next);
    };

    const toggleAll = () => {
        onSelectedRowsChange(allSelected ? new Set() : new Set(rows.map((row) => row.rowId)));
    };

    return (
        <div
            className="fixed inset-0 z-[120] flex items-start justify-center overflow-y-auto bg-[rgba(11,16,32,0.48)] px-4 py-8"
            role="presentation"
            onMouseDown={onCancel}
        >
            <section
                className="w-full max-w-7xl overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="copy-budget-title"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex items-start gap-4 border-b border-[var(--border)] px-5 py-4">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[var(--primary-50)] text-[var(--primary)]">
                        <Copy size={21} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 id="copy-budget-title" className="text-[17px] font-semibold text-[var(--ink-900)]">
                            Copiar orçamento
                        </h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Importe etapas, composições e insumos de outro orçamento para {currentOrcamento.codigo}.
                        </p>
                    </div>
                    <button
                        type="button"
                        className="sig-btn sig-btn-ghost !min-h-9 !px-2"
                        title="Fechar"
                        onClick={onCancel}
                    >
                        <X size={17} />
                    </button>
                </header>

                <div className="grid border-b border-[var(--border)] text-sm font-semibold md:grid-cols-3">
                    <CopyStep active={activeStep === 1} eyebrow="Passo 1" label="Seleção do orçamento" />
                    <CopyStep active={activeStep === 2} eyebrow="Passo 2" label="Seleção dos itens" />
                    <CopyStep active={activeStep === 3} eyebrow="Passo 3" label="Processar a importação" />
                </div>

                <div className="max-h-[70vh] overflow-y-auto bg-[var(--surface-muted)] p-5">
                    <section className="rounded-lg border border-[var(--border)] bg-white">
                        <div className="border-b border-[var(--border)] bg-[var(--primary-900)] px-4 py-3 text-xs font-bold uppercase tracking-[0.06em] text-white">
                            Escolha os itens que devem ser importados
                        </div>

                        <div className="grid gap-5 p-4 lg:grid-cols-[360px_minmax(0,1fr)]">
                            <div className="space-y-3">
                                <label className="block">
                                    <span className="mb-1 block text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">
                                        Orçamento de origem
                                    </span>
                                    <select
                                        className="sig-input"
                                        value={copySourceId}
                                        disabled={copyLoading || copySubmitting}
                                        onChange={(event) => onLoadSource(event.target.value)}
                                    >
                                        <option value="">Selecione um orçamento</option>
                                        {copySources.map((source) => (
                                            <option key={source.id} value={source.id}>
                                                {source.codigo} - {source.descricao}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                {copySource && (
                                    <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4 text-sm">
                                        <span className="text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">
                                            Importando itens de
                                        </span>
                                        <strong className="mt-1 block text-lg text-[var(--ink-900)]">{copySource.descricao}</strong>
                                        <span className="mt-3 block text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">
                                            Para
                                        </span>
                                        <strong className="mt-1 block text-lg text-[var(--ink-900)]">{currentOrcamento.descricao}</strong>
                                    </div>
                                )}

                                <div className="rounded-lg border border-[var(--border)] bg-white p-4 text-sm">
                                    <span className="text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">
                                        Usar preços de insumos e composições
                                    </span>
                                    <label className="mt-3 flex items-center gap-2 text-[var(--ink-700)]">
                                        <input type="radio" checked readOnly className="accent-[var(--primary)]" />
                                        Do orçamento de origem
                                    </label>
                                    <p className="mt-2 text-xs leading-relaxed text-[var(--ink-500)]">
                                        A cópia preserva os valores, BDI diferenciado e totais já gravados no orçamento escolhido.
                                    </p>
                                </div>
                            </div>

                            <div className="min-w-0">
                                {copyLoading ? (
                                    <div className="flex min-h-[280px] items-center justify-center rounded-lg border border-dashed border-[var(--border)] text-sm font-semibold text-[var(--ink-500)]">
                                        <Loader2 className="mr-2 animate-spin" size={18} />
                                        Carregando orçamento...
                                    </div>
                                ) : rows.length === 0 ? (
                                    <div className="flex min-h-[280px] items-center justify-center rounded-lg border border-dashed border-[var(--border)] px-4 text-center text-sm font-medium text-[var(--ink-500)]">
                                        Selecione um orçamento para listar as etapas, composições e insumos disponíveis para cópia.
                                    </div>
                                ) : (
                                    <>
                                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                            <button type="button" className="sig-btn sig-btn-secondary !min-h-9" onClick={toggleAll}>
                                                {allSelected ? 'Desmarcar tudo' : 'Marcar tudo'}
                                            </button>
                                            <span className="text-sm font-semibold text-[var(--ink-500)]">
                                                {selectedCount} linha(s) selecionada(s)
                                            </span>
                                        </div>

                                        <div className="overflow-x-auto rounded-md border border-[var(--border)]">
                                            <table className="min-w-[1100px] w-full text-left text-xs">
                                                <thead className="bg-[#2d2d2d] text-white">
                                                    <tr>
                                                        <th className="w-10 px-3 py-2"></th>
                                                        <th className="px-3 py-2">Item</th>
                                                        <th className="px-3 py-2">Código</th>
                                                        <th className="px-3 py-2">Banco</th>
                                                        <th className="px-3 py-2">Descrição</th>
                                                        <th className="px-3 py-2">Tipo</th>
                                                        <th className="px-3 py-2">Und</th>
                                                        <th className="px-3 py-2 text-right">Quant.</th>
                                                        <th className="px-3 py-2 text-right">Valor unit.</th>
                                                        <th className="px-3 py-2 text-right">Valor com BDI</th>
                                                        <th className="px-3 py-2 text-right">Total</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {rows.map((row) => (
                                                        <CopyBudgetRow
                                                            key={row.rowId}
                                                            row={row}
                                                            selected={copySelectedRows.has(row.rowId)}
                                                            onToggle={() => toggleRow(row)}
                                                        />
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>
                    </section>
                </div>

                <footer className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] bg-white px-5 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" disabled={copySubmitting} onClick={onCancel}>
                        Voltar
                    </button>
                    <button
                        type="button"
                        className="sig-btn sig-btn-primary"
                        disabled={!copySource || selectedCount === 0 || copySubmitting}
                        onClick={onSubmit}
                    >
                        {copySubmitting ? <Loader2 className="animate-spin" size={16} /> : <Copy size={16} />}
                        Importar
                    </button>
                </footer>
            </section>
        </div>
    );
}

function CopyStep({ active, eyebrow, label }) {
    return (
        <div className={active ? 'bg-[var(--primary-900)] px-4 py-3 text-white' : 'bg-white px-4 py-3 text-[var(--ink-500)]'}>
            <span className="block text-xs">{eyebrow}</span>
            <span className="mt-1 block text-[11px] uppercase tracking-[0.06em]">{label}</span>
        </div>
    );
}

function CopyBudgetRow({ onToggle, row, selected }) {
    const rowClass = row.kind === 'etapa'
        ? 'bg-[#dceff8] font-semibold'
        : row.kind === 'insumo'
            ? 'bg-[#fff7dd]'
            : 'bg-[#e5f7dd]';
    const typeLabel = row.kind === 'etapa' ? '-' : (row.kind === 'insumo' ? 'Insumo' : 'Composição');

    return (
        <tr className={`${rowClass} border-t border-[rgba(15,23,42,0.08)]`}>
            <td className="px-3 py-2 align-top">
                <input
                    className="h-4 w-4 accent-[var(--primary)]"
                    type="checkbox"
                    checked={selected}
                    onChange={onToggle}
                />
            </td>
            <td className="px-3 py-2 align-top font-semibold">{row.item}</td>
            <td className="px-3 py-2 align-top mono">{row.codigo}</td>
            <td className="px-3 py-2 align-top">{row.banco}</td>
            <td className="px-3 py-2 align-top">
                <span className="budget-indent" style={{ '--budget-depth': row.level }}>{row.descricao}</span>
            </td>
            <td className="px-3 py-2 align-top">{typeLabel}</td>
            <td className="px-3 py-2 align-top">{row.unidade}</td>
            <td className="px-3 py-2 text-right align-top">{row.quantidade ? formatPlainMoney(row.quantidade) : ''}</td>
            <td className="px-3 py-2 text-right align-top">{row.valorUnitario ? formatPlainMoney(row.valorUnitario) : ''}</td>
            <td className="px-3 py-2 text-right align-top">{row.valorComBdi ? formatPlainMoney(row.valorComBdi) : ''}</td>
            <td className="px-3 py-2 text-right align-top font-semibold">{formatPlainMoney(row.total)}</td>
        </tr>
    );
}

function ReportsDialog({ selectedReports, onCancel, onDownload, onToggle }) {
    const hasSelection = selectedReports.length > 0;

    return (
        <div
            className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.48)] px-4 py-6"
            role="presentation"
            onMouseDown={onCancel}
        >
            <section
                className="w-full max-w-lg overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="reports-dialog-title"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex items-start gap-4 border-b border-[var(--border)] px-5 py-4">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[var(--primary-50)] text-[var(--primary)]">
                        <FileSpreadsheet size={21} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 id="reports-dialog-title" className="text-[17px] font-semibold text-[var(--ink-900)]">
                            Relatórios do orçamento
                        </h2>
                    </div>
                    <button
                        type="button"
                        className="sig-btn sig-btn-ghost !min-h-9 !px-2"
                        title="Fechar"
                        onClick={onCancel}
                    >
                        <X size={17} />
                    </button>
                </header>

                <div className="space-y-3 p-5">
                    <label className="flex cursor-pointer items-center gap-3 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-4 py-3">
                        <input
                            className="h-4 w-4 accent-[var(--primary)]"
                            type="checkbox"
                            checked={selectedReports.includes('sintetico')}
                            onChange={() => onToggle('sintetico')}
                        />
                        <span className="flex min-w-0 flex-1 items-center gap-3">
                            <FileSpreadsheet size={18} className="shrink-0 text-[var(--primary)]" />
                            <span>
                                <strong className="block text-sm text-[var(--ink-900)]">Relatório sintético</strong>
                                <span className="block text-xs font-medium text-[var(--ink-500)]">Excel com fórmulas</span>
                            </span>
                        </span>
                    </label>

                    <label className="flex cursor-pointer items-center gap-3 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-4 py-3">
                        <input
                            className="h-4 w-4 accent-[var(--primary)]"
                            type="checkbox"
                            checked={selectedReports.includes('resumo')}
                            onChange={() => onToggle('resumo')}
                        />
                        <span className="flex min-w-0 flex-1 items-center gap-3">
                            <ListTree size={18} className="shrink-0 text-[var(--primary)]" />
                            <span>
                                <strong className="block text-sm text-[var(--ink-900)]">Relatório resumo</strong>
                                <span className="block text-xs font-medium text-[var(--ink-500)]">Totais e peso por etapa</span>
                            </span>
                        </span>
                    </label>
                </div>

                <footer className="flex flex-wrap justify-end gap-2 bg-[var(--surface-muted)] px-5 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onCancel}>
                        Cancelar
                    </button>
                    <button
                        type="button"
                        className="sig-btn sig-btn-primary"
                        disabled={!hasSelection}
                        onClick={onDownload}
                    >
                        <Download size={16} />
                        Baixar
                    </button>
                </footer>
            </section>
        </div>
    );
}

function BdiItemDialog({ form, item, onCancel, onSave }) {
    const itemLabel = item.item_type === 'insumo' ? 'este insumo' : 'esta composicao';

    return (
        <div
            className="fixed inset-0 z-[120] flex items-start justify-center bg-[rgba(11,16,32,0.48)] px-4 py-20"
            role="presentation"
            onMouseDown={onCancel}
        >
            <section
                className="w-full max-w-2xl overflow-hidden rounded-sm border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="bdi-item-title"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex items-center justify-between border-b border-[var(--border)] px-5 py-3">
                    <h2 id="bdi-item-title" className="text-2xl font-medium text-[var(--ink-900)]">
                        BDI Diferenciado
                    </h2>
                    <button
                        className="sig-btn sig-btn-ghost !min-h-9 !px-2"
                        type="button"
                        title="Fechar"
                        onClick={onCancel}
                    >
                        <X size={17} />
                    </button>
                </header>

                <div className="px-5 py-5">
                    <p className="mb-5 text-[17px] text-[var(--ink-700)]">
                        Formulario para insercao de um BDI especifico para {itemLabel}.
                    </p>

                    <label className="block text-sm font-semibold text-[var(--ink-800)]" htmlFor="bdi-percentual">
                        BDI
                    </label>
                    <input
                        id="bdi-percentual"
                        className="mt-1 h-11 w-full border border-[var(--border)] px-3 text-[15px] outline-none focus:border-[var(--primary)] focus:ring-2 focus:ring-[rgba(11,95,255,0.14)]"
                        placeholder="Ex: 25,00"
                        value={form.data.bdi_percentual}
                        onChange={(event) => form.setData('bdi_percentual', event.target.value)}
                    />
                    {form.errors.bdi_percentual && <ErrorText>{form.errors.bdi_percentual}</ErrorText>}
                </div>

                <footer className="flex justify-end gap-3 border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onCancel}>
                        Cancelar
                    </button>
                    <button
                        type="button"
                        className="sig-btn sig-btn-primary"
                        disabled={form.processing}
                        onClick={onSave}
                    >
                        Salvar
                    </button>
                </footer>
            </section>
        </div>
    );
}

function DeleteEtapaDialog({ etapa, onCancel, onConfirm }) {
    return (
        <div
            className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.48)] px-4 py-6"
            role="presentation"
            onMouseDown={onCancel}
        >
            <section
                className="w-full max-w-md overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-etapa-title"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex items-start gap-4 border-b border-[var(--border)] px-5 py-4">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[var(--red-50)] text-[var(--red)]">
                        <AlertTriangle size={21} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 id="delete-etapa-title" className="text-[16px] font-semibold text-[var(--ink-900)]">
                            Excluir etapa
                        </h2>
                        <p className="mt-1 text-[13px] leading-5 text-[var(--ink-500)]">
                            Deseja mesmo excluir a etapa "{etapa.descricao}"? O registro sera mantido no historico por soft delete.
                        </p>
                    </div>
                    <button
                        type="button"
                        className="sig-btn sig-btn-ghost !min-h-9 !px-2"
                        title="Fechar"
                        onClick={onCancel}
                    >
                        <X size={17} />
                    </button>
                </header>

                <footer className="flex flex-wrap justify-end gap-2 bg-[var(--surface-muted)] px-5 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onCancel}>
                        Cancelar
                    </button>
                    <button
                        type="button"
                        className="sig-btn sig-btn-primary bg-[var(--red)] hover:bg-[var(--red)]"
                        onClick={onConfirm}
                    >
                        Excluir
                    </button>
                </footer>
            </section>
        </div>
    );
}

function DeleteItemDialog({ item, onCancel, onConfirm }) {
    return (
        <div
            className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.48)] px-4 py-6"
            role="presentation"
            onMouseDown={onCancel}
        >
            <section
                className="w-full max-w-md overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-item-title"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex items-start gap-4 border-b border-[var(--border)] px-5 py-4">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[var(--red-50)] text-[var(--red)]">
                        <AlertTriangle size={21} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 id="delete-item-title" className="text-[16px] font-semibold text-[var(--ink-900)]">
                            Excluir item
                        </h2>
                        <p className="mt-1 text-[13px] leading-5 text-[var(--ink-500)]">
                            Deseja mesmo excluir o item "{item.descricao}" deste orcamento? O registro sera mantido no historico por soft delete.
                        </p>
                    </div>
                    <button
                        type="button"
                        className="sig-btn sig-btn-ghost !min-h-9 !px-2"
                        title="Fechar"
                        onClick={onCancel}
                    >
                        <X size={17} />
                    </button>
                </header>

                <footer className="flex flex-wrap justify-end gap-2 bg-[var(--surface-muted)] px-5 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onCancel}>
                        Cancelar
                    </button>
                    <button
                        type="button"
                        className="sig-btn sig-btn-primary bg-[var(--red)] hover:bg-[var(--red)]"
                        onClick={onConfirm}
                    >
                        Excluir
                    </button>
                </footer>
            </section>
        </div>
    );
}

function CloseBudgetDialog({ orcamento, onCancel, onConfirm }) {
    return (
        <div
            className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.48)] px-4 py-6"
            role="presentation"
            onMouseDown={onCancel}
        >
            <section
                className="w-full max-w-lg overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="close-budget-title"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex items-start gap-4 border-b border-[var(--border)] px-5 py-4">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700">
                        <Check size={22} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 id="close-budget-title" className="text-[16px] font-semibold text-[var(--ink-900)]">
                            Finalizar orçamento
                        </h2>
                        <p className="mt-1 text-[13px] leading-5 text-[var(--ink-500)]">
                            Deseja finalizar o orçamento "{orcamento.codigo} - {orcamento.descricao}"? Depois disso ele poderá ser usado na medição e não poderá mais ser alterado.
                        </p>
                    </div>
                    <button
                        type="button"
                        className="sig-btn sig-btn-ghost !min-h-9 !px-2"
                        title="Fechar"
                        onClick={onCancel}
                    >
                        <X size={17} />
                    </button>
                </header>

                <footer className="flex flex-wrap justify-end gap-2 bg-[var(--surface-muted)] px-5 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onCancel}>
                        Cancelar
                    </button>
                    <button type="button" className="sig-btn sig-btn-primary" onClick={onConfirm}>
                        Finalizar orçamento
                    </button>
                </footer>
            </section>
        </div>
    );
}

function ErrorText({ children }) {
    return <span className="mt-1 block text-[10px] font-semibold text-red-600">{children}</span>;
}

function InfoLine({ label, value }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-3 py-2">
            <span className="block text-[11px] font-bold uppercase tracking-[0.06em] text-[var(--ink-400)]">{label}</span>
            <strong className="mt-1 block text-sm text-[var(--ink-900)]">{value}</strong>
        </div>
    );
}

function SummaryRow({ label, value }) {
    return (
        <div className="grid gap-2 px-5 py-3 sm:grid-cols-[140px_minmax(0,1fr)]">
            <span className="text-xs font-bold uppercase tracking-[0.06em] text-[var(--ink-500)]">{label}</span>
            <div className="flex flex-wrap gap-2 font-semibold text-[var(--ink-800)]">{value}</div>
        </div>
    );
}

function BudgetTotalLine({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-4 border-b border-[var(--border)] px-2 py-2">
            <span className="text-xs font-bold text-[var(--ink-600)]">{label}</span>
            <strong className="mono text-sm text-[var(--ink-900)]">{value}</strong>
        </div>
    );
}

function formatCurrency(value) {
    return currencyFormatter.format(Number(value ?? 0));
}

function formatPlainMoney(value) {
    return decimalFormatter.format(Number(value ?? 0));
}

function formatInputDecimal(value) {
    const number = Number(value ?? 1);

    if (!Number.isFinite(number)) {
        return '1';
    }

    return String(number).replace('.', ',');
}
