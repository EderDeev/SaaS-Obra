import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CalendarDays, ChevronDown, ClipboardList, FileText, Plus, Settings2, X } from 'lucide-react';
import { useState } from 'react';

const currentReference = () => {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const year = String(now.getFullYear()).slice(-2);

    return `${month}/${year}`;
};

const normalizeReference = (value) => {
    const digits = value.replace(/\D/g, '').slice(0, 4);

    if (digits.length <= 2) {
        return digits;
    }

    return `${digits.slice(0, 2)}/${digits.slice(2)}`;
};

export default function BoletimMedicaoIndex({
    selectedContractId,
    contracts = [],
    boletins = [],
    tipos = [],
}) {
    const page = usePage();
    const tenant = page.props.currentTenant;
    const [showCreate, setShowCreate] = useState(false);
    const [openManageId, setOpenManageId] = useState(null);
    const [openReportId, setOpenReportId] = useState(null);
    const form = useForm({
        contract_id: selectedContractId || '',
        periodo_referencia: currentReference(),
        tipo: 'normal',
    });

    const changeContract = (contractId) => {
        form.setData('contract_id', contractId);
        router.get(
            route('tenant.medicao.boletim-medicao.index', tenant.slug),
            { contract_id: contractId },
            { preserveScroll: true, preserveState: false }
        );
    };

    const submit = (event) => {
        event.preventDefault();

        form.post(route('tenant.medicao.boletim-medicao.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => {
                form.setData((data) => ({
                    ...data,
                    periodo_referencia: currentReference(),
                    tipo: 'normal',
                }));
                setShowCreate(false);
            },
        });
    };

    const boletimManageUrl = (boletim) => `${route('tenant.medicao.folha-rosto.index', tenant.slug)}?boletim_id=${boletim.id}`;
    const boletimReportUrl = (boletim, relatorio = 'pleito_preliminar') => `${route('tenant.medicao.relatorios.index', tenant.slug)}?contract_id=${boletim.contract?.id || ''}&boletim_id=${boletim.id}&relatorio=${relatorio}`;
    const statusClass = (status) => {
        if (status === 'congelado') {
            return 'bg-blue-50 text-blue-700';
        }

        if (status === 'finalizado') {
            return 'bg-slate-100 text-slate-700';
        }

        return 'bg-emerald-50 text-emerald-700';
    };

    const freezeBoletim = (boletim) => {
        if (!window.confirm(`Congelar ${boletim.codigo}? O envio de Folhas de Rosto será pausado.`)) {
            return;
        }

        router.patch(route('tenant.medicao.boletim-medicao.freeze', [tenant.slug, boletim.id]), {}, {
            preserveScroll: true,
            onSuccess: () => setOpenManageId(null),
        });
    };

    const finishBoletim = (boletim) => {
        if (!window.confirm(`Finalizar ${boletim.codigo}? O envio de Folhas de Rosto será pausado.`)) {
            return;
        }

        router.patch(route('tenant.medicao.boletim-medicao.finish', [tenant.slug, boletim.id]), {}, {
            preserveScroll: true,
            onSuccess: () => setOpenManageId(null),
        });
    };

    const reopenBoletim = (boletim) => {
        if (!window.confirm(`Reabrir ${boletim.codigo}? O envio de Folhas de Rosto será liberado novamente.`)) {
            return;
        }

        router.patch(route('tenant.medicao.boletim-medicao.reopen', [tenant.slug, boletim.id]), {}, {
            preserveScroll: true,
            onSuccess: () => setOpenManageId(null),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Boletim Medição" />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                <section className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <span className="eyebrow">Medição</span>
                        <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">Boletim Medição</h1>
                        <p className="mt-2 max-w-3xl text-sm text-[var(--ink-500)]">
                            Abra uma competência mensal por contrato e tipo. As Folhas de Rosto criadas pelo Gerenciar ficam vinculadas ao boletim.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={() => setShowCreate((current) => !current)}
                        className="sig-btn sig-btn-primary justify-center"
                    >
                        {showCreate ? <X size={16} /> : <Plus size={16} />}
                        {showCreate ? 'Fechar cadastro' : 'Cadastrar Boletim'}
                    </button>
                </section>

                {page.props.flash?.success && (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                        {page.props.flash.success}
                    </div>
                )}

                {Object.values(page.props.errors || {}).length > 0 && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                        {Object.values(page.props.errors)[0]}
                    </div>
                )}

                {showCreate && (
                    <section className="sig-card p-5">
                        <form onSubmit={submit} className="grid gap-4 xl:grid-cols-[minmax(360px,1fr)_240px_220px_180px] xl:items-start">
                            <label className="grid min-w-0 gap-1.5 text-sm">
                                <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Contrato</span>
                                <select
                                    value={form.data.contract_id || ''}
                                    onChange={(event) => changeContract(event.target.value)}
                                    className="sig-input"
                                >
                                    {contracts.map((contract) => (
                                        <option key={contract.id} value={contract.id}>
                                            {contract.code} - {contract.name}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <label className="grid min-w-[220px] gap-1.5 text-sm">
                                <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Mês de referência</span>
                                <input
                                    type="text"
                                    inputMode="numeric"
                                    maxLength={5}
                                    value={form.data.periodo_referencia}
                                    onChange={(event) => form.setData('periodo_referencia', normalizeReference(event.target.value))}
                                    className="sig-input"
                                    placeholder="01/26"
                                />
                                <span className="whitespace-nowrap text-xs text-[var(--ink-500)]">Use o formato MM/AA, exemplo: 01/26.</span>
                            </label>

                            <label className="grid min-w-[200px] gap-1.5 text-sm">
                                <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Tipo</span>
                                <select
                                    value={form.data.tipo}
                                    onChange={(event) => form.setData('tipo', event.target.value)}
                                    className="sig-input"
                                >
                                    {tipos.map((tipo) => (
                                        <option key={tipo.value} value={tipo.value}>{tipo.label}</option>
                                    ))}
                                </select>
                            </label>

                            <button
                                type="submit"
                                disabled={form.processing || !form.data.contract_id || !/^(0[1-9]|1[0-2])\/\d{2}$/.test(form.data.periodo_referencia) || !form.data.tipo}
                                className="sig-btn sig-btn-primary justify-center disabled:opacity-50"
                            >
                                <Plus size={16} />
                                Abrir Boletim
                            </button>
                        </form>
                    </section>
                )}

                <section className="sig-card overflow-visible">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                        <div>
                            <h2 className="text-lg font-bold text-[var(--ink-900)]">Boletins abertos e históricos</h2>
                            <p className="text-sm text-[var(--ink-500)]">{boletins.length} boletim(ns) encontrado(s).</p>
                        </div>
                    </header>

                    {boletins.length === 0 ? (
                        <div className="p-10 text-center">
                            <ClipboardList className="mx-auto text-[var(--ink-400)]" size={34} />
                            <p className="mt-3 font-bold text-[var(--ink-900)]">Nenhum Boletim de Medição criado</p>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                Clique em Cadastrar Boletim para abrir a primeira competência.
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y divide-[var(--border)] pb-3">
                            {boletins.map((boletim) => (
                                <article
                                    key={boletim.id}
                                    className="grid items-center gap-3 px-4 py-2.5 md:grid-cols-[120px_110px_minmax(190px,1fr)_160px_100px_160px_160px]"
                                >
                                    <div>
                                        <span className="mono text-base font-bold text-[var(--ink-900)]">{boletim.codigo}</span>
                                        <p className="text-[11px] font-semibold text-[var(--ink-500)]">{boletim.tipo_label}</p>
                                    </div>

                                    <div className="flex items-center gap-2 text-sm text-[var(--ink-800)]">
                                        <CalendarDays size={16} className="text-[var(--primary)]" />
                                        <strong>{boletim.periodo_formatado}</strong>
                                    </div>

                                    <div className="min-w-0">
                                        <p className="mono truncate text-sm font-bold text-[var(--ink-900)]">{boletim.contract?.code}</p>
                                        <p className="truncate text-xs text-[var(--ink-500)]">{boletim.contract?.name}</p>
                                    </div>

                                    <span className={`w-fit rounded-full px-3 py-1.5 text-xs font-bold ${statusClass(boletim.status)}`}>
                                        {boletim.status_label}
                                    </span>

                                    <div className="text-sm">
                                        <span className="text-xs font-bold uppercase text-[var(--ink-500)]">FRs</span>
                                        <p className="font-black text-[var(--ink-900)]">
                                            {boletim.folhas_rosto_abertas}/{boletim.folhas_rosto_total}
                                        </p>
                                    </div>

                                    <div className="relative">
                                        <button
                                            type="button"
                                            onClick={() => setOpenReportId((current) => current === boletim.id ? null : boletim.id)}
                                            className="sig-btn w-full justify-between bg-green-600 text-white hover:bg-green-700 !min-h-10"
                                            title="Relatórios do boletim"
                                        >
                                            <span className="inline-flex items-center gap-2">
                                                <FileText size={16} />
                                                Relatórios
                                            </span>
                                            <ChevronDown size={16} />
                                        </button>
                                        {openReportId === boletim.id && (
                                            <div className="absolute right-0 top-[calc(100%+8px)] z-50 w-60 overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_18px_45px_rgba(15,23,42,0.16)]">
                                                <a
                                                    href={boletimReportUrl(boletim)}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="block px-4 py-3 text-sm font-semibold text-[var(--ink-800)] hover:bg-[var(--surface-muted)]"
                                                >
                                                    Pleito preliminar
                                                </a>
                                                <a
                                                    href={boletimReportUrl(boletim, 'analise_pleito')}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="block px-4 py-3 text-sm font-semibold text-[var(--ink-800)] hover:bg-[var(--surface-muted)]"
                                                >
                                                    Análise do Pleito
                                                </a>
                                                <a
                                                    href={boletimReportUrl(boletim, 'sintetico')}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="block px-4 py-3 text-sm font-semibold text-[var(--ink-800)] hover:bg-[var(--surface-muted)]"
                                                >
                                                    Sintético
                                                </a>
                                            </div>
                                        )}
                                    </div>

                                    <div className="relative">
                                        <button
                                            type="button"
                                            onClick={() => setOpenManageId((current) => current === boletim.id ? null : boletim.id)}
                                            className="sig-btn sig-btn-primary w-full justify-between !min-h-10"
                                        >
                                            <span className="inline-flex items-center gap-2">
                                                <Settings2 size={16} />
                                                Gerenciar
                                            </span>
                                            <ChevronDown size={16} />
                                        </button>

                                        {openManageId === boletim.id && (
                                            <div className="absolute right-0 top-[calc(100%+8px)] z-50 w-56 overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_18px_45px_rgba(15,23,42,0.16)]">
                                                <Link
                                                    href={boletimManageUrl(boletim)}
                                                    className="block px-4 py-3 text-sm font-semibold text-[var(--ink-800)] hover:bg-[var(--surface-muted)]"
                                                >
                                                    Acessar Folhas de Rosto
                                                </Link>
                                                <button
                                                    type="button"
                                                    disabled={boletim.status === 'finalizado'}
                                                    onClick={() => freezeBoletim(boletim)}
                                                    className="block w-full px-4 py-3 text-left text-sm font-semibold text-blue-700 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    Congelar boletim
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={boletim.status === 'finalizado'}
                                                    onClick={() => finishBoletim(boletim)}
                                                    className="block w-full px-4 py-3 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    Finalizar boletim
                                                </button>
                                                {boletim.status !== 'aberto_lancamento' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => reopenBoletim(boletim)}
                                                        className="block w-full px-4 py-3 text-left text-sm font-semibold text-emerald-700 hover:bg-emerald-50"
                                                    >
                                                        Reabrir boletim
                                                    </button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
