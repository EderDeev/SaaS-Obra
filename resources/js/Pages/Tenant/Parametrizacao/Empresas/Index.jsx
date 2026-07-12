import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Building2, Filter, ImagePlus, Pencil, Plus, Save, SlidersHorizontal, Trash2, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

function formatCnpj(value) {
    const digits = value.replace(/\D/g, '').slice(0, 14);

    if (digits.length <= 2) return digits;
    if (digits.length <= 5) return `${digits.slice(0, 2)}.${digits.slice(2)}`;
    if (digits.length <= 8) return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5)}`;
    if (digits.length <= 12) return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5, 8)}/${digits.slice(8)}`;

    return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5, 8)}/${digits.slice(8, 12)}-${digits.slice(12)}`;
}

function initials(name = '') {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

const tipoEmpresaLabel = (tipo) => tipo?.label || {
    gerenciadora: 'Gerenciadora',
    construtora: 'Construtora',
    cliente: 'Cliente',
}[tipo?.nome] || tipo?.nome || 'Sem tipo';

export default function ParametrizacaoEmpresasIndex({ tenant, empresas, contracts, tiposEmpresa }) {
    const page = usePage();
    const defaultTipoEmpresaId = tiposEmpresa[0]?.id ?? '';
    const defaultContractId = contracts[0]?.id ?? '';
    const logoInputRef = useRef(null);
    const logoPreviewRef = useRef(null);
    const [editingEmpresa, setEditingEmpresa] = useState(null);
    const [logoPreview, setLogoPreview] = useState(null);
    const [logoPreviewOrigin, setLogoPreviewOrigin] = useState(null);
    const [contractFilter, setContractFilter] = useState('todos');
    const [tipoFilter, setTipoFilter] = useState('todos');
    const [formOpen, setFormOpen] = useState(false);
    const form = useForm({
        nome: '',
        contract_id: defaultContractId,
        cnpj: '',
        sigla: '',
        tipo_empresa_id: defaultTipoEmpresaId,
        logo: null,
    });

    useEffect(() => () => {
        if (logoPreviewRef.current) {
            URL.revokeObjectURL(logoPreviewRef.current);
        }
    }, []);

    const filteredEmpresas = useMemo(() => empresas.filter((empresa) => {
        if (contractFilter !== 'todos' && String(empresa.contract_id) !== String(contractFilter)) {
            return false;
        }

        if (tipoFilter !== 'todos' && String(empresa.tipo_empresa_id) !== String(tipoFilter)) {
            return false;
        }

        return true;
    }), [empresas, contractFilter, tipoFilter]);

    const selectLogo = (event) => {
        const file = event.target.files?.[0] || null;

        if (logoPreviewRef.current) {
            URL.revokeObjectURL(logoPreviewRef.current);
            logoPreviewRef.current = null;
        }

        if (!file) {
            form.setData('logo', null);
            setLogoPreview(null);

            return;
        }

        const previewUrl = URL.createObjectURL(file);
        logoPreviewRef.current = previewUrl;
        setLogoPreview(previewUrl);
        setLogoPreviewOrigin('selected');
        form.setData('logo', file);
    };

    const clearLogo = () => {
        if (logoPreviewRef.current) {
            URL.revokeObjectURL(logoPreviewRef.current);
            logoPreviewRef.current = null;
        }

        setLogoPreview(null);
        setLogoPreviewOrigin(null);
        form.setData('logo', null);

        if (logoInputRef.current) {
            logoInputRef.current.value = '';
        }
    };

    const resetForm = () => {
        clearLogo();
        setEditingEmpresa(null);
        form.clearErrors();
        form.setData({
            nome: '',
            contract_id: defaultContractId,
            cnpj: '',
            sigla: '',
            tipo_empresa_id: defaultTipoEmpresaId,
            logo: null,
        });
    };

    const openCreateForm = () => {
        resetForm();
        setFormOpen(true);
    };

    const closeForm = () => {
        resetForm();
        setFormOpen(false);
    };

    const startEditing = (empresa) => {
        if (logoPreviewRef.current) {
            URL.revokeObjectURL(logoPreviewRef.current);
            logoPreviewRef.current = null;
        }

        setEditingEmpresa(empresa);
        setFormOpen(true);
        setLogoPreview(empresa.logo_url || null);
        setLogoPreviewOrigin(empresa.logo_url ? 'stored' : null);
        form.clearErrors();
        form.setData({
            nome: empresa.nome || '',
            contract_id: empresa.contract_id || defaultContractId,
            cnpj: empresa.cnpj || '',
            sigla: empresa.sigla || '',
            tipo_empresa_id: empresa.tipo_empresa_id || defaultTipoEmpresaId,
            logo: null,
        });
    };

    const submit = (event) => {
        event.preventDefault();

        const targetRoute = editingEmpresa
            ? route('tenant.parametrizacao.empresas.update', [page.props.currentTenant.slug, editingEmpresa.id])
            : route('tenant.parametrizacao.empresas.store', page.props.currentTenant.slug);

        form.transform((data) => (editingEmpresa ? { ...data, _method: 'patch' } : data));

        form.post(targetRoute, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                resetForm();
                setFormOpen(false);
            },
        });
    };

    const deleteEmpresa = (empresa) => {
        if (editingEmpresa?.id === empresa.id) {
            resetForm();
        }

        router.delete(route('tenant.parametrizacao.empresas.destroy', [page.props.currentTenant.slug, empresa.id]), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Parametrização - Empresas" />

            <section className={`sig-content grid gap-6 ${formOpen ? 'xl:grid-cols-[380px_minmax(0,1fr)]' : ''}`}>
                {formOpen && (
                <form className="sig-card p-5" onSubmit={submit}>
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <SlidersHorizontal size={14} />
                        <span className="eyebrow">Parametrização</span>
                    </div>
                    <h1 className="mt-2 text-xl font-semibold">{editingEmpresa ? 'Editar empresa' : 'Cadastrar empresa'}</h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        {editingEmpresa
                            ? 'Atualize os dados da empresa ou selecione uma nova logo para substituir a atual.'
                            : `Empresas relacionadas ao tenant ${tenant.name}: gerenciadoras, construtoras e clientes.`}
                    </p>

                    {page.props.flash.success && (
                        <div className="mt-4 rounded-lg bg-[var(--green-50)] px-3 py-2 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}
                    {page.props.flash.error && (
                        <div className="mt-4 rounded-lg bg-[var(--red-50)] px-3 py-2 text-sm text-[var(--red)]">
                            {page.props.flash.error}
                        </div>
                    )}

                    <div className="mt-5 grid gap-3">
                        <Field label="Contrato" error={form.errors.contract_id}>
                            <select
                                value={form.data.contract_id}
                                onChange={(event) => form.setData('contract_id', event.target.value)}
                                required
                            >
                                <option value="">Selecione o contrato</option>
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>
                                        {contract.code} - {contract.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Nome" error={form.errors.nome}>
                            <input
                                value={form.data.nome}
                                onChange={(event) => form.setData('nome', event.target.value)}
                                placeholder="Ex: Construtora Horizonte"
                                required
                            />
                        </Field>

                        <Field label="CNPJ" error={form.errors.cnpj}>
                            <input
                                value={form.data.cnpj}
                                onChange={(event) => form.setData('cnpj', formatCnpj(event.target.value))}
                                placeholder="00.000.000/0001-00"
                                inputMode="numeric"
                                maxLength={18}
                                required
                            />
                        </Field>

                        <div className="grid gap-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                            <Field label="Sigla" error={form.errors.sigla}>
                                <input
                                    value={form.data.sigla}
                                    onChange={(event) => form.setData('sigla', event.target.value.toUpperCase())}
                                    placeholder="CHZ"
                                    maxLength={20}
                                    required
                                />
                            </Field>

                            <Field label="Tipo de empresa" error={form.errors.tipo_empresa_id}>
                                <select
                                    value={form.data.tipo_empresa_id}
                                    onChange={(event) => form.setData('tipo_empresa_id', event.target.value)}
                                    required
                                >
                                    {tiposEmpresa.map((tipo) => (
                                        <option key={tipo.id} value={tipo.id}>
                                            {tipoEmpresaLabel(tipo)}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </div>

                        <div>
                            <span className="eyebrow mb-1 block">Logo da empresa</span>
                            <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-[var(--border)] bg-white text-[12px] font-bold text-[var(--ink-500)]">
                                        {logoPreview ? (
                                            <img src={logoPreview} alt="Preview da logo" className="h-full w-full object-contain" />
                                        ) : (
                                            <Building2 size={20} />
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap gap-2">
                                            <label className="sig-btn sig-btn-secondary sig-btn-sm">
                                                <ImagePlus size={14} />
                                                Selecionar logo
                                                <input
                                                    ref={logoInputRef}
                                                    className="sr-only"
                                                    type="file"
                                                    accept="image/png,image/jpeg,image/webp"
                                                    onChange={selectLogo}
                                                />
                                            </label>
                                            {logoPreviewOrigin === 'selected' && (
                                                <button type="button" className="sig-btn sig-btn-ghost sig-btn-sm" onClick={clearLogo}>
                                                    <X size={14} />
                                                    Remover
                                                </button>
                                            )}
                                        </div>
                                        <p className="mt-2 text-[12px] text-[var(--ink-500)]">
                                            {logoPreviewOrigin === 'stored'
                                                ? 'Logo atual cadastrada. Selecione uma nova imagem para substituir.'
                                                : 'Opcional. Use PNG, JPG ou WebP com ate 4 MB.'}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            {form.errors.logo && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.logo}</span>}
                        </div>
                    </div>

                    <div className="mt-5 flex flex-wrap gap-2">
                        <button className="sig-btn sig-btn-primary" disabled={form.processing || tiposEmpresa.length === 0 || contracts.length === 0}>
                            {editingEmpresa ? <Save size={15} /> : <Plus size={15} />}
                            {editingEmpresa ? 'Salvar alterações' : 'Criar empresa'}
                        </button>
                        <button type="button" className="sig-btn sig-btn-secondary" onClick={closeForm}>
                            <X size={15} />
                            {editingEmpresa ? 'Cancelar' : 'Fechar'}
                        </button>
                        {editingEmpresa && (
                            <button type="button" className="sig-btn sig-btn-ghost" onClick={resetForm}>
                                <X size={15} />
                                Limpar
                            </button>
                        )}
                    </div>
                </form>
                )}

                <section className="param-list-card sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <Building2 size={14} />
                                <span className="eyebrow">Empresas cadastradas</span>
                            </div>
                            <h2 className="mt-1 text-[15px] font-semibold">
                                {filteredEmpresas.length} de {empresas.length} empresas
                            </h2>
                        </div>
                        <button type="button" className="sig-btn sig-btn-primary sig-btn-sm" onClick={openCreateForm}>
                            <Plus size={13} />
                            Criar empresa
                        </button>
                    </header>

                    {!formOpen && page.props.flash.success && (
                        <div className="border-b border-[var(--border)] bg-[var(--green-50)] px-5 py-3 text-sm text-[var(--green)]">
                            {page.props.flash.success}
                        </div>
                    )}
                    {!formOpen && page.props.flash.error && (
                        <div className="border-b border-[var(--border)] bg-[var(--red-50)] px-5 py-3 text-sm text-[var(--red)]">
                            {page.props.flash.error}
                        </div>
                    )}

                    <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 lg:grid-cols-2">
                        <label>
                            <span className="eyebrow mb-1 flex items-center gap-1">
                                <Filter size={12} />
                                Contrato
                            </span>
                            <span className="sig-input bg-white">
                                <select value={contractFilter} onChange={(event) => setContractFilter(event.target.value)}>
                                    <option value="todos">Todos os contratos</option>
                                    {contracts.map((contract) => (
                                        <option key={contract.id} value={contract.id}>
                                            {contract.code} - {contract.name}
                                        </option>
                                    ))}
                                </select>
                            </span>
                        </label>

                        <label>
                            <span className="eyebrow mb-1 flex items-center gap-1">
                                <Filter size={12} />
                                Tipo
                            </span>
                            <span className="sig-input bg-white">
                                <select value={tipoFilter} onChange={(event) => setTipoFilter(event.target.value)}>
                                    <option value="todos">Todos os tipos</option>
                                    {tiposEmpresa.map((tipo) => (
                                        <option key={tipo.id} value={tipo.id}>
                                            {tipoEmpresaLabel(tipo)}
                                        </option>
                                    ))}
                                </select>
                            </span>
                        </label>
                    </div>

                    {filteredEmpresas.length > 0 ? (
                        <>
                        <div className="param-desktop-table overflow-x-auto">
                        <table className="sig-table min-w-[940px]">
                            <thead>
                                <tr>
                                    <th>Empresa</th>
                                    <th>Contrato</th>
                                    <th>CNPJ</th>
                                    <th>Tipo</th>
                                    <th>Sigla</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredEmpresas.map((empresa) => (
                                    <tr key={empresa.id}>
                                        <td>
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] text-[11px] font-bold text-[var(--ink-600)]">
                                                    {empresa.logo_url ? (
                                                        <img src={empresa.logo_url} alt={empresa.nome} className="h-full w-full object-contain" />
                                                    ) : (
                                                        initials(empresa.sigla || empresa.nome)
                                                    )}
                                                </div>
                                                <div className="min-w-0">
                                                    <div className="font-semibold">{empresa.nome}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div className="mono text-xs">{empresa.contract?.code}</div>
                                            <div className="text-xs text-[var(--ink-500)]">{empresa.contract?.name}</div>
                                        </td>
                                        <td className="mono">{empresa.cnpj}</td>
                                        <td>
                                            <span className="sig-pill sig-pill-blue">{tipoEmpresaLabel(empresa.tipo_empresa)}</span>
                                        </td>
                                        <td className="font-semibold">{empresa.sigla}</td>
                                        <td>
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <button
                                                    type="button"
                                                    className="sig-btn sig-btn-secondary sig-btn-sm"
                                                    onClick={() => startEditing(empresa)}
                                                >
                                                    <Pencil size={14} />
                                                    Editar
                                                </button>
                                                <ConfirmActionButton
                                                    title="Deletar empresa"
                                                    message={`Deseja mesmo excluir a empresa ${empresa.nome}? Esta acao nao deve ser feita por engano.`}
                                                    confirmLabel="Deletar empresa"
                                                    onConfirm={() => deleteEmpresa(empresa)}
                                                >
                                                    <Trash2 size={14} />
                                                    Deletar
                                                </ConfirmActionButton>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        </div>

                        <div className="param-responsive-list divide-y divide-[var(--border)]">
                            {filteredEmpresas.map((empresa) => (
                                <article key={empresa.id} className="p-5">
                                    <div className="flex items-start gap-3">
                                        <div className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] text-[11px] font-bold text-[var(--ink-600)]">
                                            {empresa.logo_url ? (
                                                <img src={empresa.logo_url} alt={empresa.nome} className="h-full w-full object-contain" />
                                            ) : (
                                                initials(empresa.sigla || empresa.nome)
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className="text-sm font-semibold text-[var(--ink-900)]">{empresa.nome}</h3>
                                                <span className="sig-pill sig-pill-blue">{tipoEmpresaLabel(empresa.tipo_empresa)}</span>
                                            </div>
                                            <div className="mono mt-1 text-xs text-[var(--ink-500)]">{empresa.sigla || '-'}</div>
                                        </div>
                                    </div>

                                    <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                        <CompactInfo label="Contrato" value={`${empresa.contract?.code || '-'} - ${empresa.contract?.name || 'Sem contrato'}`} />
                                        <CompactInfo label="CNPJ" value={empresa.cnpj || '-'} />
                                    </div>

                                    <div className="mt-4 flex flex-wrap gap-2 border-t border-[var(--border)] pt-4">
                                        <button
                                            type="button"
                                            className="sig-btn sig-btn-secondary sig-btn-sm"
                                            onClick={() => startEditing(empresa)}
                                        >
                                            <Pencil size={14} />
                                            Editar
                                        </button>
                                        <ConfirmActionButton
                                            title="Deletar empresa"
                                            message={`Deseja mesmo excluir a empresa ${empresa.nome}? Esta acao nao deve ser feita por engano.`}
                                            confirmLabel="Deletar empresa"
                                            onConfirm={() => deleteEmpresa(empresa)}
                                        >
                                            <Trash2 size={14} />
                                            Deletar
                                        </ConfirmActionButton>
                                    </div>
                                </article>
                            ))}
                        </div>
                        </>
                    ) : (
                        <div className="p-12 text-center text-sm text-[var(--ink-500)]">
                            {empresas.length === 0 ? 'Nenhuma empresa cadastrada ainda.' : 'Nenhuma empresa encontrada para os filtros selecionados.'}
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

function Field({ label, error, children }) {
    return (
        <label>
            <span className="eyebrow mb-1 block">{label}</span>
            <span className="sig-input">{children}</span>
            {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
        </label>
    );
}
