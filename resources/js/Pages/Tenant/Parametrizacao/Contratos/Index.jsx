import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Building2, ClipboardList, GitBranch, Link2, Plus, Save, ShieldCheck, SlidersHorizontal, X } from 'lucide-react';
import { useMemo, useState } from 'react';

function contractLabel(contract) {
    return `${contract.code} - ${contract.obra?.nome || contract.name}`;
}

function empresaLabel(empresa) {
    const tipo = empresa.tipo_empresa?.nome || 'sem tipo';
    const sigla = empresa.sigla ? ` · ${empresa.sigla}` : '';

    return `${empresa.nome}${sigla} · ${tipo}`;
}

function countLabel(total, singular, plural) {
    return `${total} ${total === 1 ? singular : plural}`;
}

export default function ParametrizacaoContratosIndex({ tenant, contracts, obras, empresas }) {
    const page = usePage();
    const defaultContract = contracts[0];
    const [formOpen, setFormOpen] = useState(false);
    const form = useForm({
        contract_id: defaultContract?.id ?? '',
        obra_id: defaultContract?.obra_id ?? '',
        cliente_empresa_id: defaultContract?.cliente_empresa_id ?? '',
        construtora_empresa_id: defaultContract?.construtora_empresa_id ?? '',
        gerenciadora_empresa_id: defaultContract?.fiscalizadora_empresa_id ?? '',
    });

    const selectedContractId = String(form.data.contract_id || '');
    const selectedContract = useMemo(
        () => contracts.find((contract) => String(contract.id) === selectedContractId),
        [contracts, selectedContractId],
    );
    const obrasDoContrato = useMemo(
        () => obras.filter((obra) => String(obra.contract_id) === selectedContractId),
        [obras, selectedContractId],
    );
    const empresasDoContrato = useMemo(
        () => empresas.filter((empresa) => String(empresa.contract_id) === selectedContractId),
        [empresas, selectedContractId],
    );
    const clientesDoContrato = useMemo(
        () => empresasDoContrato.filter((empresa) => empresa.tipo_empresa?.nome === 'cliente'),
        [empresasDoContrato],
    );
    const construtorasDoContrato = useMemo(
        () => empresasDoContrato.filter((empresa) => empresa.tipo_empresa?.nome === 'construtora'),
        [empresasDoContrato],
    );
    const gerenciadorasDoContrato = useMemo(
        () => empresasDoContrato.filter((empresa) => empresa.tipo_empresa?.nome === 'gerenciadora'),
        [empresasDoContrato],
    );

    const setContract = (contractId) => {
        const contract = contracts.find((item) => String(item.id) === String(contractId));

        form.setData({
            contract_id: contractId,
            obra_id: contract?.obra_id ?? '',
            cliente_empresa_id: contract?.cliente_empresa_id ?? '',
            construtora_empresa_id: contract?.construtora_empresa_id ?? '',
            gerenciadora_empresa_id: contract?.fiscalizadora_empresa_id ?? '',
        });
    };

    const submit = (event) => {
        event.preventDefault();

        form.post(route('tenant.parametrizacao.contrato.store', page.props.currentTenant.slug), {
            preserveScroll: true,
            onSuccess: () => setFormOpen(false),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Parametrização - Contrato" />

            <section className={`sig-content grid gap-6 ${formOpen ? 'xl:grid-cols-[420px_minmax(0,1fr)]' : ''}`}>
                {formOpen && (
                <form className="sig-card p-5" onSubmit={submit}>
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <SlidersHorizontal size={14} />
                        <span className="eyebrow">Parametrização</span>
                    </div>
                    <h1 className="mt-2 text-xl font-semibold">Vincular contrato</h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        Defina a obra principal, o cliente, a construtora e a gerenciadora de cada contrato usando os cadastros separados por contrato.
                    </p>

                    {page.props.flash.success && (
                        <div className="mt-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}

                    <div className="mt-5 grid gap-3">
                        <Field label="Contrato" error={form.errors.contract_id}>
                            <select
                                value={form.data.contract_id}
                                onChange={(event) => setContract(event.target.value)}
                                required
                            >
                                <option value="">Selecione o contrato</option>
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>
                                        {contractLabel(contract)}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Obra do contrato" error={form.errors.obra_id}>
                            <select
                                value={form.data.obra_id ?? ''}
                                onChange={(event) => form.setData('obra_id', event.target.value)}
                                disabled={!selectedContractId || obrasDoContrato.length === 0}
                            >
                                <option value="">Sem obra vinculada</option>
                                {obrasDoContrato.map((obra) => (
                                    <option key={obra.id} value={obra.id}>
                                        {obra.codigo} - {obra.nome}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Cliente" error={form.errors.cliente_empresa_id}>
                            <select
                                value={form.data.cliente_empresa_id ?? ''}
                                onChange={(event) => form.setData('cliente_empresa_id', event.target.value)}
                                disabled={!selectedContractId || clientesDoContrato.length === 0}
                            >
                                <option value="">Sem cliente vinculado</option>
                                {clientesDoContrato.map((empresa) => (
                                    <option key={empresa.id} value={empresa.id}>
                                        {empresaLabel(empresa)}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Construtora" error={form.errors.construtora_empresa_id}>
                            <select
                                value={form.data.construtora_empresa_id ?? ''}
                                onChange={(event) => form.setData('construtora_empresa_id', event.target.value)}
                                disabled={!selectedContractId || construtorasDoContrato.length === 0}
                            >
                                <option value="">Sem construtora vinculada</option>
                                {construtorasDoContrato.map((empresa) => (
                                    <option key={empresa.id} value={empresa.id}>
                                        {empresaLabel(empresa)}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Gerenciadora" error={form.errors.gerenciadora_empresa_id}>
                            <select
                                value={form.data.gerenciadora_empresa_id ?? ''}
                                onChange={(event) => form.setData('gerenciadora_empresa_id', event.target.value)}
                                disabled={!selectedContractId || gerenciadorasDoContrato.length === 0}
                            >
                                <option value="">Sem gerenciadora vinculada</option>
                                {gerenciadorasDoContrato.map((empresa) => (
                                    <option key={empresa.id} value={empresa.id}>
                                        {empresaLabel(empresa)}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </div>

                    <div className="mt-5 flex flex-wrap gap-2">
                    <button className="sig-btn sig-btn-primary" disabled={form.processing || contracts.length === 0}>
                        <Save size={15} />
                        Salvar vínculos
                    </button>
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={() => setFormOpen(false)}>
                        <X size={15} />
                        Fechar
                    </button>
                    </div>

                    {selectedContract && (
                        <div className="mt-5 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4 text-sm text-[var(--ink-500)]">
                            <div className="mono text-xs text-[var(--ink-700)]">{selectedContract.code}</div>
                            <div className="mt-1 font-semibold text-[var(--ink-900)]">{selectedContract.obra?.nome || selectedContract.name}</div>
                            <div className="mt-2 grid gap-1">
                                <span>Obras disponíveis: {obrasDoContrato.length}</span>
                                <span>Clientes disponíveis: {clientesDoContrato.length}</span>
                                <span>Construtoras disponíveis: {construtorasDoContrato.length}</span>
                                <span>Gerenciadoras disponíveis: {gerenciadorasDoContrato.length}</span>
                            </div>
                        </div>
                    )}
                </form>
                )}

                <section className="param-list-card sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <ClipboardList size={14} />
                                <span className="eyebrow">Contratos parametrizados</span>
                            </div>
                            <h2 className="mt-1 text-[15px] font-semibold">
                                {countLabel(contracts.length, 'contrato cadastrado', 'contratos cadastrados')}
                            </h2>
                        </div>
                        <button type="button" className="sig-btn sig-btn-primary sig-btn-sm" onClick={() => setFormOpen(true)}>
                            <Plus size={13} />
                            Vincular contrato
                        </button>
                    </header>

                    {!formOpen && page.props.flash.success && (
                        <div className="border-b border-[var(--border)] bg-[var(--green-50)] px-5 py-3 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}

                    {contracts.length > 0 ? (
                        <>
                        <div className="param-desktop-table overflow-x-auto">
                        <table className="sig-table">
                            <thead>
                                <tr>
                                    <th>Contrato</th>
                                    <th>Obra</th>
                                    <th>Cliente</th>
                                    <th>Construtora</th>
                                    <th>Gerenciadora</th>
                                </tr>
                            </thead>
                            <tbody>
                                {contracts.map((contract) => (
                                    <tr key={contract.id}>
                                        <td>
                                            <div className="mono text-xs">{contract.code}</div>
                                            <div className="font-semibold">{contract.name}</div>
                                        </td>
                                        <td>
                                            {contract.obra ? (
                                                <span className="inline-flex items-center gap-2 text-sm text-[var(--ink-700)]">
                                                    <GitBranch size={14} />
                                                    {contract.obra.codigo} - {contract.obra.nome}
                                                </span>
                                            ) : (
                                                <Empty>Sem obra</Empty>
                                            )}
                                        </td>
                                        <td>
                                            {contract.cliente_empresa ? (
                                                <EmpresaPill icon={Building2} empresa={contract.cliente_empresa} />
                                            ) : (
                                                <Empty>Sem cliente</Empty>
                                            )}
                                        </td>
                                        <td>
                                            {contract.construtora_empresa ? (
                                                <EmpresaPill icon={Link2} empresa={contract.construtora_empresa} />
                                            ) : (
                                                <Empty>Sem construtora</Empty>
                                            )}
                                        </td>
                                        <td>
                                            {contract.gerenciadora_empresa ? (
                                                <EmpresaPill icon={ShieldCheck} empresa={contract.gerenciadora_empresa} />
                                            ) : (
                                                <Empty>Sem gerenciadora</Empty>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        </div>

                        <div className="param-responsive-list divide-y divide-[var(--border)]">
                            {contracts.map((contract) => (
                                <article key={contract.id} className="p-5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h3 className="text-sm font-semibold text-[var(--ink-900)]">{contract.name}</h3>
                                        <span className="sig-pill sig-pill-blue">{contract.code}</span>
                                    </div>

                                    <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                        <CompactInfo
                                            label="Obra"
                                            value={contract.obra ? `${contract.obra.codigo} - ${contract.obra.nome}` : 'Sem obra'}
                                        />
                                        <CompactInfo
                                            label="Cliente"
                                            value={contract.cliente_empresa ? `${contract.cliente_empresa.nome} - ${contract.cliente_empresa.tipo_empresa?.nome || 'sem tipo'}` : 'Sem cliente'}
                                        />
                                        <CompactInfo
                                            label="Construtora"
                                            value={contract.construtora_empresa ? `${contract.construtora_empresa.nome} - ${contract.construtora_empresa.tipo_empresa?.nome || 'sem tipo'}` : 'Sem construtora'}
                                        />
                                        <CompactInfo
                                            label="Gerenciadora"
                                            value={contract.gerenciadora_empresa ? `${contract.gerenciadora_empresa.nome} - ${contract.gerenciadora_empresa.tipo_empresa?.nome || 'sem tipo'}` : 'Sem gerenciadora'}
                                        />
                                    </div>
                                </article>
                            ))}
                        </div>
                        </>
                    ) : (
                        <div className="p-12 text-center text-sm text-[var(--ink-500)]">
                            Nenhum contrato cadastrado ainda.
                        </div>
                    )}
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function CompactInfo({ label, value }) {
    return (
        <div>
            <div className="eyebrow">{label}</div>
            <div className="mt-1 break-words text-[13px] font-semibold text-[var(--ink-800)]">{value}</div>
        </div>
    );
}

function EmpresaPill({ icon: Icon, empresa }) {
    return (
        <span className="inline-flex items-center gap-2 text-sm text-[var(--ink-700)]">
            <Icon size={14} />
            <span>
                <span className="font-semibold">{empresa.nome}</span>
                <span className="text-[var(--ink-500)]"> · {empresa.tipo_empresa?.nome || 'sem tipo'}</span>
            </span>
        </span>
    );
}

function Empty({ children }) {
    return <span className="text-sm text-[var(--ink-400)]">{children}</span>;
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
