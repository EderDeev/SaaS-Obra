import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Building2, ChevronDown, ChevronRight, ClipboardList, Eye } from 'lucide-react';
import { useState } from 'react';

const formatCurrency = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));

export default function FolhaRostoIndex({ selectedContractId, contracts = [], grupos = [], boletim = null }) {
    const tenant = usePage().props.currentTenant;
    const [expandedObras, setExpandedObras] = useState({});

    const changeContract = (contractId) => {
        router.get(
            route('tenant.medicao.folha-rosto.index', tenant.slug),
            boletim ? { boletim_id: boletim.id } : { contract_id: contractId },
            { preserveScroll: true, preserveState: false }
        );
    };

    const folhaUrl = (ordem) => {
        const baseUrl = route('tenant.medicao.folha-rosto.show', [tenant.slug, ordem.id]);

        return boletim ? `${baseUrl}?boletim_id=${boletim.id}` : baseUrl;
    };

    const toggleObra = (key) => {
        setExpandedObras((current) => ({
            ...current,
            [key]: !current[key],
        }));
    };

    return (
        <AuthenticatedLayout>
            <Head title={boletim ? `Gerenciar ${boletim.codigo}` : 'Folha de Rosto'} />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                <section>
                    {boletim && (
                        <Link
                            href={route('tenant.medicao.boletim-medicao.index', tenant.slug)}
                            className="mb-4 inline-flex items-center gap-2 text-sm font-bold text-[var(--primary)]"
                        >
                            <ArrowLeft size={16} />
                            Voltar para Boletim Medição
                        </Link>
                    )}
                    <span className="eyebrow">Medição</span>
                    <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">
                        {boletim ? `Gerenciar ${boletim.codigo}` : 'Folha de Rosto'}
                    </h1>
                    <p className="mt-2 text-sm text-[var(--ink-500)]">
                        {boletim
                            ? `${boletim.periodo_formatado} · ${boletim.tipo_label} · ${boletim.status_label}`
                            : 'Acompanhe as OS aprovadas por obra e os pleitos de medição abertos em cada uma.'}
                    </p>
                </section>

                <section className="sig-card p-5">
                    <label className="grid gap-1.5 text-sm">
                        <span className="font-bold uppercase tracking-wide text-[var(--ink-500)]">Contrato</span>
                        <select
                            value={selectedContractId || ''}
                            onChange={(event) => changeContract(event.target.value)}
                            disabled={Boolean(boletim)}
                            className="sig-input"
                        >
                            {contracts.map((contract) => (
                                <option key={contract.id} value={contract.id}>
                                    {contract.code} - {contract.name}
                                </option>
                            ))}
                        </select>
                        {boletim && (
                            <span className="text-xs text-[var(--ink-500)]">
                                O contrato fica travado porque esta tela está vinculada ao boletim selecionado.
                            </span>
                        )}
                    </label>
                </section>

                {grupos.length === 0 ? (
                    <section className="sig-card p-10 text-center">
                        <ClipboardList className="mx-auto text-[var(--ink-400)]" size={34} />
                        <p className="mt-3 font-bold text-[var(--ink-900)]">Nenhuma OS liberada para medição</p>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            As OS aparecem aqui depois da aprovação.
                        </p>
                    </section>
                ) : grupos.map((grupo) => {
                    const obraKey = String(grupo.obra?.id || 'sem-obra');
                    const isOpen = Boolean(expandedObras[obraKey]);
                    const totalFrsAbertas = grupo.ordens.reduce((total, ordem) => total + Number(ordem.folhas_rosto_abertas || 0), 0);
                    const custoTotal = grupo.ordens.reduce((total, ordem) => total + Number(ordem.custo_previsto || 0), 0);

                    return (
                    <section key={obraKey} className="sig-card overflow-hidden">
                        <button
                            type="button"
                            onClick={() => toggleObra(obraKey)}
                            className="grid w-full items-center gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 text-left md:grid-cols-[minmax(0,1fr)_140px_170px_32px]"
                        >
                            <div className="flex min-w-0 items-center gap-3">
                                <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-white text-[var(--primary)]">
                                    <Building2 size={19} />
                                </span>
                                <div className="min-w-0">
                                    <h2 className="truncate font-bold text-[var(--ink-900)]">
                                        {grupo.obra?.codigo} - {grupo.obra?.nome}
                                    </h2>
                                    <p className="text-xs text-[var(--ink-500)]">{grupo.ordens.length} OS liberada(s)</p>
                                </div>
                            </div>
                            <div className="hidden text-sm md:block">
                                <span className="text-xs font-bold uppercase text-[var(--ink-500)]">FRs abertas</span>
                                <p className="font-black text-amber-600">{totalFrsAbertas}</p>
                            </div>
                            <div className="hidden text-sm md:block">
                                <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Custo total</span>
                                <p className="font-bold text-[var(--ink-900)]">{formatCurrency(custoTotal)}</p>
                            </div>
                            <span className="justify-self-end text-[var(--ink-500)]">
                                {isOpen ? <ChevronDown size={18} /> : <ChevronRight size={18} />}
                            </span>
                        </button>

                        {isOpen && (
                        <div className="divide-y divide-[var(--border)]">
                            {grupo.ordens.map((ordem) => (
                                <article
                                    key={ordem.id}
                                    className="grid items-center gap-3 p-4 md:grid-cols-[150px_minmax(0,1fr)_140px_160px_150px]"
                                >
                                    <p className="mono text-sm font-bold text-[var(--primary)]">{ordem.codigo}</p>
                                    <div className="min-w-0">
                                        <h3 className="truncate font-bold text-[var(--ink-900)]">{ordem.titulo}</h3>
                                        <p className="truncate text-xs text-[var(--ink-500)]">
                                            Solicitante: {ordem.solicitante || 'Não identificado'}
                                        </p>
                                    </div>
                                    <div>
                                        <span className="text-xs font-bold uppercase text-[var(--ink-500)]">FRs abertas</span>
                                        <p className="mt-1 text-xl font-black text-amber-600">{ordem.folhas_rosto_abertas}</p>
                                    </div>
                                    <div>
                                        <span className="text-xs font-bold uppercase text-[var(--ink-500)]">Custo da OS</span>
                                        <p className="mt-1 text-sm font-bold text-[var(--ink-900)]">{formatCurrency(ordem.custo_previsto)}</p>
                                    </div>
                                    <Link
                                        href={folhaUrl(ordem)}
                                        className="sig-btn sig-btn-primary justify-center"
                                    >
                                        <Eye size={16} />
                                        Acessar FR&apos;s
                                    </Link>
                                </article>
                            ))}
                        </div>
                        )}
                    </section>
                    );
                })}
            </div>
        </AuthenticatedLayout>
    );
}
