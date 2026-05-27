import ConfirmActionButton from '@/Components/ConfirmActionButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, ClipboardList, Filter, FolderOpen, Plus, Search, Trash2, UserRoundCheck, UsersRound } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

function contractLabel(contract) {
    return `${contract.code} - ${contract.name}`;
}

function initials(name = 'U') {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function Avatar({ user }) {
    if (user?.avatar_url) {
        return <img src={user.avatar_url} alt={user.name} className="sig-avatar object-cover" />;
    }

    return <span className="sig-avatar">{initials(user?.name)}</span>;
}

export default function ProjectResponsaveis({ tenant, contracts, disciplinasByContract, usersByContract, tipos, responsaveis }) {
    const page = usePage();
    const firstContractId = contracts[0]?.id ?? '';
    const firstDisciplinaId = disciplinasByContract[firstContractId]?.[0]?.id;
    const form = useForm({
        contract_id: firstContractId,
        disciplina_ids: firstDisciplinaId ? [firstDisciplinaId] : [],
        user_id: usersByContract[firstContractId]?.[0]?.id ?? '',
        tipo: 'analise',
    });
    const [query, setQuery] = useState('');
    const [contractFilter, setContractFilter] = useState('todos');
    const [disciplinaFilter, setDisciplinaFilter] = useState('todos');
    const [tipoFilter, setTipoFilter] = useState('todos');
    const [userFilter, setUserFilter] = useState('');

    const disciplinas = disciplinasByContract[form.data.contract_id] || [];
    const users = usersByContract[form.data.contract_id] || [];
    const filteredUsers = useMemo(() => {
        const term = query.trim().toLowerCase();

        if (!term) {
            return users;
        }

            return users.filter((user) => `${user.name} ${user.email}`.toLowerCase().includes(term));
    }, [query, users]);
    const disciplinasForFilter = useMemo(() => {
        const source = contractFilter === 'todos'
            ? Object.values(disciplinasByContract).flat()
            : disciplinasByContract[contractFilter] || [];

        return source
            .filter((disciplina, index, list) => list.findIndex((item) => String(item.id) === String(disciplina.id)) === index)
            .sort((a, b) => `${a.sigla} ${a.nome}`.localeCompare(`${b.sigla} ${b.nome}`));
    }, [disciplinasByContract, contractFilter]);
    const filteredResponsaveis = useMemo(() => {
        const userTerm = userFilter.trim().toLowerCase();

        return responsaveis.filter((responsavel) => {
            if (contractFilter !== 'todos' && String(responsavel.contract?.id) !== String(contractFilter)) {
                return false;
            }

            if (disciplinaFilter !== 'todos' && String(responsavel.disciplina?.id) !== String(disciplinaFilter)) {
                return false;
            }

            if (tipoFilter !== 'todos' && responsavel.tipo !== tipoFilter) {
                return false;
            }

            if (!userTerm) {
                return true;
            }

            return `${responsavel.user?.name || ''} ${responsavel.user?.email || ''}`.toLowerCase().includes(userTerm);
        });
    }, [responsaveis, contractFilter, disciplinaFilter, tipoFilter, userFilter]);

    useEffect(() => {
        if (disciplinas.length === 0) {
            form.setData('disciplina_ids', []);
        } else {
            const validIds = form.data.disciplina_ids.filter((id) => disciplinas.some((disciplina) => String(disciplina.id) === String(id)));

            if (validIds.length === 0) {
                form.setData('disciplina_ids', [disciplinas[0].id]);
            } else if (validIds.length !== form.data.disciplina_ids.length) {
                form.setData('disciplina_ids', validIds);
            }
        }

        if (users.length === 0) {
            form.setData('user_id', '');
        } else if (!users.some((user) => String(user.id) === String(form.data.user_id))) {
            form.setData('user_id', users[0].id);
        }
    }, [form.data.contract_id]);

    const submit = (event) => {
        event.preventDefault();

        form.post(route('tenant.projects.responsaveis.store', tenant.slug), {
            preserveScroll: true,
        });
    };

    const loadResponsavel = (responsavel) => {
        form.setData({
            contract_id: responsavel.contract?.id || '',
            disciplina_ids: responsavel.disciplina?.id ? [responsavel.disciplina.id] : [],
            user_id: responsavel.user?.id || '',
            tipo: responsavel.tipo || 'analise',
        });
        setQuery(`${responsavel.user?.name || ''} ${responsavel.user?.email || ''}`.trim());
    };

    const toggleDisciplina = (disciplinaId) => {
        const exists = form.data.disciplina_ids.some((id) => String(id) === String(disciplinaId));
        const nextIds = exists
            ? form.data.disciplina_ids.filter((id) => String(id) !== String(disciplinaId))
            : [...form.data.disciplina_ids, disciplinaId];

        form.setData('disciplina_ids', nextIds);
    };

    const selectAllDisciplinas = () => {
        form.setData('disciplina_ids', disciplinas.map((disciplina) => disciplina.id));
    };

    const clearDisciplinas = () => {
        form.setData('disciplina_ids', []);
    };

    const updateContractFilter = (contractId) => {
        setContractFilter(contractId);
        setDisciplinaFilter('todos');
    };

    return (
        <AuthenticatedLayout>
            <Head title="Projetos - Responsaveis" />

            <section className="sig-content grid gap-6 xl:grid-cols-[430px_minmax(0,1fr)]">
                <form className="sig-card p-5" onSubmit={submit}>
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <FolderOpen size={14} />
                        <span className="eyebrow">Projetos</span>
                    </div>
                    <h1 className="mt-2 text-xl font-semibold text-[var(--ink-900)]">Responsaveis</h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        Aloque os usuarios responsaveis por analisar ou aprovar projetos de cada disciplina dentro do contrato.
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

                    <div className="mt-5 grid gap-4">
                        <Field label="Contrato" error={form.errors.contract_id}>
                            <select
                                value={form.data.contract_id}
                                onChange={(event) => {
                                    form.setData('contract_id', event.target.value);
                                    setQuery('');
                                }}
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

                        <Field label="Tipo de responsabilidade" error={form.errors.tipo}>
                            <select value={form.data.tipo} onChange={(event) => form.setData('tipo', event.target.value)} required>
                                {Object.entries(tipos).map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </select>
                        </Field>

                        <div>
                            <div className="mb-1 flex items-center justify-between gap-2">
                                <span className="eyebrow">Disciplinas</span>
                                <div className="flex items-center gap-2">
                                    <button type="button" className="text-[11.5px] font-semibold text-[var(--primary)]" onClick={selectAllDisciplinas}>
                                        Todas
                                    </button>
                                    <button type="button" className="text-[11.5px] font-semibold text-[var(--ink-500)]" onClick={clearDisciplinas}>
                                        Limpar
                                    </button>
                                </div>
                            </div>
                            <div className="max-h-[240px] overflow-y-auto rounded-lg border border-[var(--border)] bg-white p-2">
                                {disciplinas.length > 0 ? disciplinas.map((disciplina) => {
                                    const selected = form.data.disciplina_ids.some((id) => String(id) === String(disciplina.id));

                                    return (
                                        <button
                                            key={disciplina.id}
                                            type="button"
                                            className={`mb-1 flex w-full items-center gap-3 rounded-md px-3 py-2 text-left transition last:mb-0 ${selected ? 'bg-[var(--primary-50)] text-[var(--primary)]' : 'hover:bg-[var(--surface-muted)]'}`}
                                            onClick={() => toggleDisciplina(disciplina.id)}
                                        >
                                            <span className="h-3.5 w-3.5 rounded-full border border-[var(--border)]" style={{ backgroundColor: disciplina.cor || '#2563eb' }} />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[13px] font-semibold">{disciplina.sigla} - {disciplina.nome}</span>
                                            </span>
                                            <span className={`flex h-5 w-5 items-center justify-center rounded border text-[12px] font-bold ${selected ? 'border-[var(--primary)] bg-[var(--primary)] text-white' : 'border-[var(--border-strong)] text-transparent'}`}>
                                                <Check size={13} />
                                            </span>
                                        </button>
                                    );
                                }) : (
                                    <div className="px-3 py-6 text-center text-[12.5px] text-[var(--ink-500)]">
                                        Nenhuma disciplina cadastrada para este contrato.
                                    </div>
                                )}
                            </div>
                            <div className="mt-1 flex items-center justify-between gap-2 text-[12px] text-[var(--ink-500)]">
                                <span>{form.data.disciplina_ids.length} disciplina(s) selecionada(s)</span>
                            </div>
                            {form.errors.disciplina_ids && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.disciplina_ids}</span>}
                        </div>

                        <div>
                            <span className="eyebrow mb-1 block">Usuario responsavel</span>
                            <div className="sig-input flex items-center gap-2">
                                <Search size={15} className="text-[var(--ink-500)]" />
                                <input
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    placeholder="Pesquisar por nome ou email"
                                />
                            </div>
                            {form.errors.user_id && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.user_id}</span>}

                            <div className="mt-2 max-h-[290px] overflow-y-auto rounded-lg border border-[var(--border)] bg-white p-1">
                                {filteredUsers.length > 0 ? filteredUsers.map((user) => {
                                    const selected = String(form.data.user_id) === String(user.id);

                                    return (
                                        <button
                                            key={user.id}
                                            type="button"
                                            className={`flex w-full items-center gap-3 rounded-md px-3 py-2 text-left transition ${selected ? 'bg-[var(--primary-50)] text-[var(--primary)]' : 'hover:bg-[var(--surface-muted)]'}`}
                                            onClick={() => form.setData('user_id', user.id)}
                                        >
                                            <Avatar user={user} />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[13px] font-semibold">{user.name}</span>
                                                <span className="block truncate text-[12px] text-[var(--ink-500)]">{user.email}</span>
                                            </span>
                                            {selected && <UserRoundCheck size={16} />}
                                        </button>
                                    );
                                }) : (
                                    <div className="px-3 py-6 text-center text-[12.5px] text-[var(--ink-500)]">
                                        Nenhum usuario disponivel para este contrato.
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    <button className="sig-btn sig-btn-primary mt-5" disabled={form.processing || !form.data.contract_id || form.data.disciplina_ids.length === 0 || !form.data.user_id}>
                        <Plus size={15} />
                        Salvar responsavel
                    </button>
                </form>

                <section className="sig-card overflow-hidden">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]">
                                <UsersRound size={14} />
                                <span className="eyebrow">Fluxo por disciplina</span>
                            </div>
                            <h2 className="mt-1 text-[15px] font-semibold text-[var(--ink-900)]">
                                {filteredResponsaveis.length} de {responsaveis.length} responsavel(is)
                            </h2>
                        </div>
                    </header>

                    <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 xl:grid-cols-4">
                        <FilterSelect label="Contrato" value={contractFilter} onChange={updateContractFilter}>
                            <option value="todos">Todos os contratos</option>
                            {contracts.map((contract) => (
                                <option key={contract.id} value={contract.id}>{contractLabel(contract)}</option>
                            ))}
                        </FilterSelect>

                        <FilterSelect label="Disciplina" value={disciplinaFilter} onChange={setDisciplinaFilter}>
                            <option value="todos">Todas as disciplinas</option>
                            {disciplinasForFilter.map((disciplina) => (
                                <option key={disciplina.id} value={disciplina.id}>{disciplina.sigla} - {disciplina.nome}</option>
                            ))}
                        </FilterSelect>

                        <FilterSelect label="Tipo" value={tipoFilter} onChange={setTipoFilter}>
                            <option value="todos">Todos os tipos</option>
                            {Object.entries(tipos).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </FilterSelect>

                        <label>
                            <span className="eyebrow mb-1 flex items-center gap-1">
                                <Search size={12} />
                                Usuario
                            </span>
                            <span className="sig-input bg-white">
                                <input value={userFilter} onChange={(event) => setUserFilter(event.target.value)} placeholder="Buscar por nome ou email" />
                            </span>
                        </label>
                    </div>

                    {filteredResponsaveis.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="sig-table min-w-[1080px]">
                                <thead>
                                    <tr>
                                        <th>Usuario</th>
                                        <th>Contrato</th>
                                        <th>Disciplina</th>
                                        <th>Tipo</th>
                                        <th>Cadastrado em</th>
                                        <th className="text-right">Acoes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredResponsaveis.map((responsavel) => (
                                        <tr key={responsavel.id}>
                                            <td>
                                                <div className="flex items-center gap-3">
                                                    <Avatar user={responsavel.user} />
                                                    <span className="min-w-0">
                                                        <span className="block truncate font-semibold text-[var(--ink-900)]">
                                                            {responsavel.user?.name}
                                                        </span>
                                                        <span className="block truncate text-xs text-[var(--ink-500)]">
                                                            {responsavel.user?.email}
                                                        </span>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <span className="inline-flex items-center gap-2 text-sm text-[var(--ink-700)]">
                                                    <ClipboardList size={14} />
                                                    <span>
                                                        <span className="mono text-xs">{responsavel.contract?.code}</span>
                                                        <span className="block font-semibold">{responsavel.contract?.name}</span>
                                                    </span>
                                                </span>
                                            </td>
                                            <td>
                                                <span className="inline-flex items-center gap-2 text-sm font-semibold text-[var(--ink-700)]">
                                                    <span className="h-3.5 w-3.5 rounded-full border border-[var(--border)]" style={{ backgroundColor: responsavel.disciplina?.cor || '#2563eb' }} />
                                                    {responsavel.disciplina?.sigla} - {responsavel.disciplina?.nome}
                                                </span>
                                            </td>
                                            <td>
                                                <span className={`sig-pill ${responsavel.tipo === 'aprovacao' ? 'sig-pill-amber' : 'sig-pill-blue'}`}>
                                                    {responsavel.tipo_label}
                                                </span>
                                            </td>
                                            <td>{responsavel.created_at}</td>
                                            <td>
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        className="sig-btn sig-btn-secondary sig-btn-sm"
                                                        onClick={() => loadResponsavel(responsavel)}
                                                    >
                                                        Editar
                                                    </button>
                                                    <ConfirmActionButton
                                                        title="Remover responsavel"
                                                        message={`Deseja mesmo remover ${responsavel.user?.name || 'este usuario'} desta responsabilidade?`}
                                                        confirmLabel="Remover responsavel"
                                                        className="sig-btn sig-btn-secondary sig-btn-sm text-[var(--red)]"
                                                        onConfirm={() => router.delete(route('tenant.projects.responsaveis.destroy', [tenant.slug, responsavel.id]), { preserveScroll: true })}
                                                    >
                                                        <Trash2 size={13} />
                                                        Remover
                                                    </ConfirmActionButton>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="p-12 text-center text-sm text-[var(--ink-500)]">
                            {responsaveis.length === 0
                                ? 'Nenhum responsavel cadastrado para analise de projetos.'
                                : 'Nenhum responsavel encontrado para os filtros selecionados.'}
                        </div>
                    )}
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function FilterSelect({ label, value, onChange, children }) {
    return (
        <label>
            <span className="eyebrow mb-1 flex items-center gap-1">
                <Filter size={12} />
                {label}
            </span>
            <span className="sig-input bg-white">
                <select value={value} onChange={(event) => onChange(event.target.value)}>
                    {children}
                </select>
            </span>
        </label>
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
