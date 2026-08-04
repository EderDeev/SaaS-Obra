import { useForm } from '@inertiajs/react';
import { Loader2, Search, ShieldCheck, Trash2, UserPlus, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const userInitials = (name) => name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase();

export default function BudgetAccessDialog({ tenant, orcamento, onCancel }) {
    const [selectedUsers, setSelectedUsers] = useState([]);
    const [search, setSearch] = useState('');
    const [searchResults, setSearchResults] = useState([]);
    const [searchOpen, setSearchOpen] = useState(false);
    const [loading, setLoading] = useState(true);
    const [searchLoading, setSearchLoading] = useState(false);
    const [loadError, setLoadError] = useState('');
    const [searchError, setSearchError] = useState('');
    const [loadVersion, setLoadVersion] = useState(0);
    const searchContainerRef = useRef(null);
    const form = useForm({ accesses: [] });

    useEffect(() => {
        const controller = new AbortController();

        const loadAccesses = async () => {
            setLoading(true);
            setLoadError('');

            try {
                const response = await fetch(
                    route('tenant.orcamentos.accesses.index', [tenant.slug, orcamento.id]),
                    {
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: controller.signal,
                    },
                );

                if (!response.ok) {
                    throw new Error(response.status === 403
                        ? 'Você não tem permissão para gerenciar os acessos deste orçamento.'
                        : 'Não foi possível carregar os acessos deste orçamento.');
                }

                const payload = await response.json();
                const loadedUsers = payload.users ?? [];

                setSelectedUsers(loadedUsers);
                form.setData(
                    'accesses',
                    loadedUsers
                        .filter((user) => !user.automatic && user.access_level)
                        .map((user) => ({ user_id: user.id, access_level: user.access_level })),
                );
            } catch (error) {
                if (error.name !== 'AbortError') {
                    setLoadError(error.message);
                }
            } finally {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            }
        };

        loadAccesses();

        return () => controller.abort();
    }, [tenant.slug, orcamento.id, loadVersion]);

    useEffect(() => {
        const term = search.trim();

        if (!searchOpen) {
            setSearchResults([]);
            setSearchError('');
            setSearchLoading(false);
            return undefined;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setSearchLoading(true);
            setSearchError('');

            try {
                const url = new URL(
                    route('tenant.orcamentos.accesses.index', [tenant.slug, orcamento.id]),
                    window.location.origin,
                );
                url.searchParams.set('available', '1');

                if (term !== '') {
                    url.searchParams.set('search', term);
                }

                const response = await fetch(url.toString(), {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: controller.signal,
                });

                if (!response.ok) {
                    throw new Error('Não foi possível pesquisar os usuários.');
                }

                const payload = await response.json();
                setSearchResults(payload.users ?? []);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    setSearchError(error.message);
                }
            } finally {
                if (!controller.signal.aborted) {
                    setSearchLoading(false);
                }
            }
        }, term === '' ? 0 : 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [search, searchOpen, tenant.slug, orcamento.id]);

    useEffect(() => {
        const closeSearch = (event) => {
            if (!searchContainerRef.current?.contains(event.target)) {
                setSearchOpen(false);
            }
        };

        document.addEventListener('mousedown', closeSearch);

        return () => document.removeEventListener('mousedown', closeSearch);
    }, []);

    const selectedIds = useMemo(
        () => new Set(selectedUsers.map((user) => Number(user.id))),
        [selectedUsers],
    );

    const selectedAccess = (user) => (
        user.automatic
            ? 'edit'
            : form.data.accesses.find((access) => Number(access.user_id) === Number(user.id))?.access_level ?? 'view'
    );

    const addUser = (user) => {
        if (selectedIds.has(Number(user.id)) || !user.can_view_globally) {
            return;
        }

        setSelectedUsers((current) => [...current, { ...user, access_level: 'view' }]);
        form.setData('accesses', [
            ...form.data.accesses,
            { user_id: user.id, access_level: 'view' },
        ]);
        setSearch('');
        setSearchResults([]);
        setSearchOpen(false);
    };

    const removeUser = (user) => {
        if (user.automatic) {
            return;
        }

        setSelectedUsers((current) => current.filter((item) => Number(item.id) !== Number(user.id)));
        form.setData(
            'accesses',
            form.data.accesses.filter((access) => Number(access.user_id) !== Number(user.id)),
        );
    };

    const setUserAccess = (user, accessLevel) => {
        if (user.automatic) {
            return;
        }

        form.setData('accesses', [
            ...form.data.accesses.filter((access) => Number(access.user_id) !== Number(user.id)),
            { user_id: user.id, access_level: accessLevel },
        ]);
    };

    const submit = (event) => {
        event.preventDefault();

        form.put(route('tenant.orcamentos.accesses.update', [tenant.slug, orcamento.id]), {
            preserveScroll: true,
            onSuccess: onCancel,
        });
    };

    return (
        <div
            className="fixed inset-0 z-[120] flex items-center justify-center bg-[rgba(11,16,32,0.48)] px-4 py-6"
            role="presentation"
            onMouseDown={() => !form.processing && onCancel()}
        >
            <form
                className="flex max-h-[min(760px,92vh)] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_24px_80px_rgba(11,16,32,0.24)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="budget-access-title"
                onSubmit={submit}
                onMouseDown={(event) => {
                    event.stopPropagation();

                    if (!searchContainerRef.current?.contains(event.target)) {
                        setSearchOpen(false);
                    }
                }}
            >
                <header className="flex items-start gap-4 border-b border-[var(--border)] px-5 py-4">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                        <ShieldCheck size={21} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 id="budget-access-title" className="text-[17px] font-semibold text-[var(--ink-900)]">
                            Acessos do orçamento
                        </h2>
                        <p className="mt-1 text-sm leading-5 text-[var(--ink-500)]">
                            {orcamento.codigo} · Adicione usuários e defina a função de cada um neste orçamento.
                        </p>
                    </div>
                    <button
                        type="button"
                        className="sig-btn sig-btn-ghost !min-h-9 !px-2"
                        title="Fechar"
                        disabled={form.processing}
                        onClick={onCancel}
                    >
                        <X size={17} />
                    </button>
                </header>

                {!loading && !loadError && (
                    <div className="border-b border-[var(--border)] px-5 py-4">
                        <label className="mb-2 block text-[11px] font-bold uppercase text-[var(--ink-500)]">
                            Adicionar usuário
                        </label>
                        <div ref={searchContainerRef} className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--ink-400)]" size={16} />
                            <input
                                className="w-full !pl-10"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                onFocus={() => setSearchOpen(true)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Escape') {
                                        setSearchOpen(false);
                                    }
                                }}
                                placeholder="Pesquise por nome ou e-mail"
                                autoComplete="off"
                            />
                            {searchLoading && (
                                <Loader2 className="absolute right-3 top-1/2 -translate-y-1/2 animate-spin text-[var(--ink-400)]" size={16} />
                            )}

                            {searchOpen && (
                                <div className="absolute left-0 right-0 top-[calc(100%+6px)] z-20 max-h-64 overflow-y-auto rounded-lg border border-[var(--border)] bg-white shadow-xl">
                                    {searchLoading ? (
                                        <p className="flex items-center gap-2 px-4 py-3 text-sm text-[var(--ink-500)]">
                                            <Loader2 className="animate-spin" size={15} />
                                            Carregando usuários...
                                        </p>
                                    ) : searchError ? (
                                        <p className="px-4 py-3 text-sm text-red-700">{searchError}</p>
                                    ) : searchResults.length === 0 ? (
                                        <p className="px-4 py-3 text-sm text-[var(--ink-500)]">Nenhum usuário encontrado.</p>
                                    ) : searchResults.map((user) => {
                                        const alreadyAdded = selectedIds.has(Number(user.id));

                                        return (
                                            <div key={user.id} className="flex items-center gap-3 border-b border-[var(--border)] px-3 py-2.5 last:border-b-0">
                                                {user.avatar_url ? (
                                                    <img className="h-9 w-9 shrink-0 rounded-full object-cover" src={user.avatar_url} alt={user.name} />
                                                ) : (
                                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--primary-50)] text-xs font-bold text-[var(--primary)]">
                                                        {userInitials(user.name)}
                                                    </span>
                                                )}
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-semibold text-[var(--ink-900)]">{user.name}</p>
                                                    <p className="truncate text-xs text-[var(--ink-500)]">{user.email}</p>
                                                </div>
                                                <button
                                                    type="button"
                                                    className="sig-btn sig-btn-secondary !min-h-8 !px-3"
                                                    disabled={alreadyAdded || !user.can_view_globally}
                                                    title={!user.can_view_globally ? 'O usuário não possui a permissão global para visualizar orçamentos.' : undefined}
                                                    onClick={() => addUser(user)}
                                                >
                                                    <UserPlus size={15} />
                                                    {alreadyAdded ? 'Adicionado' : 'Adicionar'}
                                                </button>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                        <p className="mt-1.5 text-xs text-[var(--ink-400)]">
                            Ao selecionar a busca, serão exibidos até 20 usuários. Digite para refinar os resultados.
                        </p>
                    </div>
                )}

                <div className="min-h-0 flex-1 overflow-y-auto">
                    {loading ? (
                        <div className="flex min-h-56 items-center justify-center gap-2 text-sm font-medium text-[var(--ink-500)]">
                            <Loader2 className="animate-spin" size={18} />
                            Carregando acessos...
                        </div>
                    ) : loadError ? (
                        <div className="flex min-h-56 flex-col items-center justify-center gap-3 px-5 text-center">
                            <p className="text-sm font-medium text-[var(--red)]">{loadError}</p>
                            <button type="button" className="sig-btn sig-btn-secondary" onClick={() => setLoadVersion((value) => value + 1)}>
                                Carregar novamente
                            </button>
                        </div>
                    ) : selectedUsers.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-[var(--ink-500)]">Nenhum usuário adicionado.</p>
                    ) : (
                        <div>
                            <p className="border-b border-[var(--border)] px-5 py-2.5 text-[11px] font-bold uppercase text-[var(--ink-500)]">
                                Usuários com acesso
                            </p>
                            <div className="divide-y divide-[var(--border)]">
                                {selectedUsers.map((user) => (
                                    <div key={user.id} className="grid gap-3 px-5 py-3 sm:grid-cols-[minmax(0,1fr)_230px_36px] sm:items-center">
                                        <div className="flex min-w-0 items-center gap-3">
                                            {user.avatar_url ? (
                                                <img className="h-9 w-9 shrink-0 rounded-full object-cover" src={user.avatar_url} alt={user.name} />
                                            ) : (
                                                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--primary-50)] text-xs font-bold text-[var(--primary)]">
                                                    {userInitials(user.name)}
                                                </span>
                                            )}
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <strong className="truncate text-sm text-[var(--ink-900)]">{user.name}</strong>
                                                    {user.automatic && (
                                                        <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">
                                                            Acesso automático
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="truncate text-xs text-[var(--ink-500)]">{user.email}</p>
                                                <p className="mt-0.5 text-[11px] text-[var(--ink-400)]">
                                                    {user.automatic_reason || user.role_label}
                                                </p>
                                            </div>
                                        </div>

                                        <div>
                                            <label className="mb-1 block text-[10px] font-bold uppercase text-[var(--ink-400)]">
                                                Função no orçamento
                                            </label>
                                            <select
                                                value={selectedAccess(user)}
                                                disabled={user.automatic || form.processing}
                                                onChange={(event) => setUserAccess(user, event.target.value)}
                                            >
                                                <option value="view">Somente visualizar</option>
                                                <option value="edit" disabled={!user.can_edit_globally}>Visualizar e editar</option>
                                            </select>
                                            {!user.automatic && user.can_view_globally && !user.can_edit_globally && (
                                                <p className="mt-1 text-[10px] leading-4 text-amber-700">
                                                    A edição exige a permissão global “Editar orçamento”.
                                                </p>
                                            )}
                                        </div>

                                        <button
                                            type="button"
                                            className="sig-btn sig-btn-ghost !min-h-9 !px-2 text-red-600"
                                            title={user.automatic ? 'Este acesso é automático.' : 'Remover acesso'}
                                            disabled={user.automatic || form.processing}
                                            onClick={() => removeUser(user)}
                                        >
                                            <Trash2 size={16} />
                                        </button>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {(form.errors.accesses || form.errors['accesses.0.user_id']) && (
                    <p className="border-t border-red-100 bg-red-50 px-5 py-2 text-xs font-medium text-red-700">
                        {form.errors.accesses || form.errors['accesses.0.user_id']}
                    </p>
                )}

                <footer className="flex flex-wrap justify-end gap-2 border-t border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" disabled={form.processing} onClick={onCancel}>
                        Cancelar
                    </button>
                    <button type="submit" className="sig-btn sig-btn-primary" disabled={loading || Boolean(loadError) || form.processing}>
                        {form.processing ? <Loader2 className="animate-spin" size={16} /> : <ShieldCheck size={16} />}
                        {form.processing ? 'Salvando...' : 'Salvar acessos'}
                    </button>
                </footer>
            </form>
        </div>
    );
}
