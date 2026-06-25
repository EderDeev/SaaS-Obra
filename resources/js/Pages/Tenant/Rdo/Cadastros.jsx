import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Building2, Construction, Pencil, Plus, Trash2, Users } from 'lucide-react';
import { useState } from 'react';

const tabs = [
    { key: 'mao-obra', label: 'Mão de obra', icon: Users },
    { key: 'equipamentos', label: 'Equipamentos', icon: Construction },
    { key: 'subcontratadas', label: 'Subcontratadas', icon: Building2 },
];

export default function Cadastros({ maoObra = [], equipamentos = [], subcontratadas = [] }) {
    const { currentTenant, flash = {}, errors = {} } = usePage().props;
    const [activeTab, setActiveTab] = useState('mao-obra');
    const [editing, setEditing] = useState(null);

    const laborForm = useForm({ descricao: '', tipo: 'direta', unidade: 'pessoa', active: true });
    const equipmentForm = useForm({ codigo: '', descricao: '', unidade: 'unidade', propriedade: 'proprio', active: true });
    const subcontractorForm = useForm({
        razao_social: '', nome_fantasia: '', cnpj: '', responsavel: '', telefone: '', email: '', active: true,
    });

    const reset = () => {
        setEditing(null);
        laborForm.reset();
        equipmentForm.reset();
        subcontractorForm.reset();
    };

    const submit = (event) => {
        event.preventDefault();
        const config = formConfig(activeTab, currentTenant.slug, editing, laborForm, equipmentForm, subcontractorForm);
        const options = { preserveScroll: true, onSuccess: reset };
        editing ? config.form.patch(config.updateUrl, options) : config.form.post(config.storeUrl, options);
    };

    const startEdit = (tab, item) => {
        setActiveTab(tab);
        setEditing(item);
        if (tab === 'mao-obra') {
            laborForm.setData({ descricao: item.descricao, tipo: item.tipo, unidade: item.unidade, active: item.active });
        } else if (tab === 'equipamentos') {
            equipmentForm.setData({
                codigo: item.codigo || '', descricao: item.descricao, unidade: item.unidade,
                propriedade: item.propriedade, active: item.active,
            });
        } else {
            subcontractorForm.setData({
                razao_social: item.razao_social, nome_fantasia: item.nome_fantasia || '', cnpj: item.cnpj || '',
                responsavel: item.responsavel || '', telefone: item.telefone || '', email: item.email || '', active: item.active,
            });
        }
    };

    const remove = (tab, item, label) => {
        if (!window.confirm(`Remover "${label}" do cadastro do RDO?`)) return;
        const config = formConfig(tab, currentTenant.slug, item, laborForm, equipmentForm, subcontractorForm);
        router.delete(config.updateUrl, { preserveScroll: true, onSuccess: reset });
    };

    const currentForm = activeTab === 'mao-obra' ? laborForm : activeTab === 'equipamentos' ? equipmentForm : subcontractorForm;

    return (
        <AuthenticatedLayout>
            <Head title="RDO - Cadastros" />
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6">
                <header>
                    <span className="eyebrow">Diário de Obra · RDO</span>
                    <h1 className="mt-2 text-3xl font-bold">Cadastros</h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        Catálogos reutilizáveis para agilizar o preenchimento diário dos RDOs.
                    </p>
                </header>

                {flash.success && <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 font-semibold text-emerald-700">{flash.success}</div>}
                {Object.values(errors).length > 0 && <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 font-semibold text-red-700">{Object.values(errors)[0]}</div>}

                <nav className="flex flex-wrap gap-2 rounded-xl border border-[var(--border)] bg-white p-2 shadow-sm">
                    {tabs.map(({ key, label, icon: Icon }) => (
                        <button key={key} type="button" onClick={() => { setActiveTab(key); reset(); }}
                            className={`inline-flex items-center gap-2 rounded-lg px-4 py-2.5 font-bold ${activeTab === key ? 'bg-[var(--primary)] text-white' : 'text-[var(--ink-600)] hover:bg-[var(--surface-muted)]'}`}>
                            <Icon size={17} /> {label}
                        </button>
                    ))}
                </nav>

                <form onSubmit={submit} className="rounded-xl border border-[var(--border)] bg-white shadow-sm">
                    <header className="border-b border-[var(--border)] px-5 py-4">
                        <h2 className="text-lg font-bold">{editing ? 'Editar cadastro' : `Cadastrar ${tabs.find((tab) => tab.key === activeTab)?.label.toLowerCase()}`}</h2>
                    </header>
                    <div className="p-5">
                        {activeTab === 'mao-obra' && <LaborForm form={laborForm} />}
                        {activeTab === 'equipamentos' && <EquipmentForm form={equipmentForm} />}
                        {activeTab === 'subcontratadas' && <SubcontractorForm form={subcontractorForm} />}
                        <div className="mt-5 flex justify-end gap-2">
                            {editing && <button type="button" onClick={reset} className="sig-btn">Cancelar</button>}
                            <button type="submit" disabled={currentForm.processing} className="sig-btn sig-btn-primary">
                                {editing ? <Pencil size={16} /> : <Plus size={16} />} {editing ? 'Salvar alterações' : 'Cadastrar'}
                            </button>
                        </div>
                    </div>
                </form>

                {activeTab === 'mao-obra' && (
                    <Catalog title="Mão de obra cadastrada" empty="Nenhuma função cadastrada.">
                        {maoObra.map((item) => (
                            <Row key={item.id} title={item.descricao} subtitle={`${item.tipo === 'direta' ? 'Direta' : 'Indireta'} · ${item.unidade}`} active={item.active}
                                onEdit={() => startEdit('mao-obra', item)} onDelete={() => remove('mao-obra', item, item.descricao)} />
                        ))}
                    </Catalog>
                )}
                {activeTab === 'equipamentos' && (
                    <Catalog title="Equipamentos cadastrados" empty="Nenhum equipamento cadastrado.">
                        {equipamentos.map((item) => (
                            <Row key={item.id} title={`${item.codigo ? `${item.codigo} - ` : ''}${item.descricao}`}
                                subtitle={`${propertyLabel(item.propriedade)} · ${item.unidade}`} active={item.active}
                                onEdit={() => startEdit('equipamentos', item)} onDelete={() => remove('equipamentos', item, item.descricao)} />
                        ))}
                    </Catalog>
                )}
                {activeTab === 'subcontratadas' && (
                    <Catalog title="Subcontratadas cadastradas" empty="Nenhuma subcontratada cadastrada.">
                        {subcontratadas.map((item) => (
                            <Row key={item.id} title={item.razao_social}
                                subtitle={[item.nome_fantasia, item.cnpj, item.responsavel].filter(Boolean).join(' · ')} active={item.active}
                                onEdit={() => startEdit('subcontratadas', item)} onDelete={() => remove('subcontratadas', item, item.razao_social)} />
                        ))}
                    </Catalog>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function LaborForm({ form }) {
    return <div className="grid gap-4 md:grid-cols-3">
        <Field label="Função / descrição" error={form.errors.descricao}><input className="sig-input" value={form.data.descricao} onChange={(e) => form.setData('descricao', e.target.value)} placeholder="Ex.: Pedreiro" /></Field>
        <Field label="Classificação" error={form.errors.tipo}><select className="sig-input" value={form.data.tipo} onChange={(e) => form.setData('tipo', e.target.value)}><option value="direta">Mão de obra direta</option><option value="indireta">Mão de obra indireta</option></select></Field>
        <Field label="Unidade" error={form.errors.unidade}><input className="sig-input" value={form.data.unidade} onChange={(e) => form.setData('unidade', e.target.value)} /></Field>
        <ActiveField form={form} />
    </div>;
}

function EquipmentForm({ form }) {
    return <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <Field label="Código" error={form.errors.codigo}><input className="sig-input" value={form.data.codigo} onChange={(e) => form.setData('codigo', e.target.value)} placeholder="Opcional" /></Field>
        <Field label="Equipamento" error={form.errors.descricao}><input className="sig-input" value={form.data.descricao} onChange={(e) => form.setData('descricao', e.target.value)} placeholder="Ex.: Escavadeira hidráulica" /></Field>
        <Field label="Unidade" error={form.errors.unidade}><input className="sig-input" value={form.data.unidade} onChange={(e) => form.setData('unidade', e.target.value)} /></Field>
        <Field label="Propriedade" error={form.errors.propriedade}><select className="sig-input" value={form.data.propriedade} onChange={(e) => form.setData('propriedade', e.target.value)}><option value="proprio">Próprio</option><option value="locado">Locado</option><option value="subcontratada">Subcontratada</option></select></Field>
        <ActiveField form={form} />
    </div>;
}

function SubcontractorForm({ form }) {
    return <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <Field label="Razão social" error={form.errors.razao_social}><input className="sig-input" value={form.data.razao_social} onChange={(e) => form.setData('razao_social', e.target.value)} /></Field>
        <Field label="Nome fantasia" error={form.errors.nome_fantasia}><input className="sig-input" value={form.data.nome_fantasia} onChange={(e) => form.setData('nome_fantasia', e.target.value)} /></Field>
        <Field label="CNPJ" error={form.errors.cnpj}>
            <input
                className="sig-input"
                value={form.data.cnpj}
                onChange={(event) => form.setData('cnpj', formatCnpj(event.target.value))}
                placeholder="00.000.000/0000-00"
                inputMode="numeric"
                maxLength={18}
            />
        </Field>
        <Field label="Responsável" error={form.errors.responsavel}><input className="sig-input" value={form.data.responsavel} onChange={(e) => form.setData('responsavel', e.target.value)} /></Field>
        <Field label="Telefone" error={form.errors.telefone}><input className="sig-input" value={form.data.telefone} onChange={(e) => form.setData('telefone', e.target.value)} /></Field>
        <Field label="E-mail" error={form.errors.email}><input type="email" className="sig-input" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></Field>
        <ActiveField form={form} />
    </div>;
}

function ActiveField({ form }) {
    return <label className="flex items-center gap-3 rounded-lg border border-[var(--border)] px-4 py-3">
        <input type="checkbox" className="h-5 w-5 rounded text-[var(--primary)]" checked={form.data.active} onChange={(e) => form.setData('active', e.target.checked)} />
        <span className="font-semibold">Cadastro ativo</span>
    </label>;
}

function Field({ label, error, children }) {
    return <label className="grid gap-1.5"><span className="eyebrow">{label}</span>{children}{error && <span className="text-xs font-semibold text-red-600">{error}</span>}</label>;
}

function Catalog({ title, empty, children }) {
    const items = Array.isArray(children) ? children : children ? [children] : [];
    return <section className="overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-sm">
        <header className="border-b border-[var(--border)] px-5 py-4"><h2 className="text-lg font-bold">{title}</h2></header>
        {items.length === 0 ? <p className="p-8 text-center text-sm text-[var(--ink-500)]">{empty}</p> : <div className="divide-y divide-[var(--border)]">{children}</div>}
    </section>;
}

function Row({ title, subtitle, active, onEdit, onDelete }) {
    return <article className="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
        <div><p className="font-bold">{title}</p><p className="mt-0.5 text-sm text-[var(--ink-500)]">{subtitle || 'Sem informações adicionais'}</p></div>
        <div className="flex items-center gap-2">
            <span className={`rounded-full px-3 py-1 text-xs font-bold ${active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>{active ? 'Ativo' : 'Inativo'}</span>
            <button type="button" onClick={onEdit} className="sig-btn"><Pencil size={15} /> Editar</button>
            <button type="button" onClick={onDelete} className="sig-btn border-red-200 bg-red-50 text-red-700"><Trash2 size={15} /> Remover</button>
        </div>
    </article>;
}

function propertyLabel(value) {
    return { proprio: 'Próprio', locado: 'Locado', subcontratada: 'Subcontratada' }[value] || value;
}

function formatCnpj(value) {
    const digits = String(value || '').replace(/\D/g, '').slice(0, 14);

    return digits
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2')
        .replace(/(\d{4})(\d)/, '$1-$2');
}

function formConfig(tab, tenant, editing, laborForm, equipmentForm, subcontractorForm) {
    if (tab === 'mao-obra') return {
        form: laborForm,
        storeUrl: route('tenant.diario-obra.rdo.cadastros.mao-obra.store', tenant),
        updateUrl: editing ? route('tenant.diario-obra.rdo.cadastros.mao-obra.update', [tenant, editing.id]) : null,
    };
    if (tab === 'equipamentos') return {
        form: equipmentForm,
        storeUrl: route('tenant.diario-obra.rdo.cadastros.equipamentos.store', tenant),
        updateUrl: editing ? route('tenant.diario-obra.rdo.cadastros.equipamentos.update', [tenant, editing.id]) : null,
    };
    return {
        form: subcontractorForm,
        storeUrl: route('tenant.diario-obra.rdo.cadastros.subcontratadas.store', tenant),
        updateUrl: editing ? route('tenant.diario-obra.rdo.cadastros.subcontratadas.update', [tenant, editing.id]) : null,
    };
}
