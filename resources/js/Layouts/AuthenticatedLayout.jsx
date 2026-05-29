import SigLogo from '@/Components/SigLogo';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    Activity,
    Bell,
    Building2,
    ChevronRight,
    ClipboardList,
    FileChartColumn,
    FolderOpen,
    Gauge,
    HardDrive,
    Home,
    KeyRound,
    Layers3,
    LogOut,
    PanelLeftClose,
    PanelLeftOpen,
    Search,
    Settings,
    ShieldCheck,
    SlidersHorizontal,
    Users,
} from 'lucide-react';

function initials(name = 'Usuário') {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function UserAvatar({ user, className = '' }) {
    const [avatarLoadFailed, setAvatarLoadFailed] = useState(false);

    useEffect(() => {
        setAvatarLoadFailed(false);
    }, [user?.avatar_url]);

    if (user?.avatar_url && !avatarLoadFailed) {
        return (
            <img
                src={user.avatar_url}
                alt={user.name}
                className={`sig-avatar object-cover ${className}`}
                onError={() => setAvatarLoadFailed(true)}
            />
        );
    }

    return <span className={`sig-avatar ${className}`}>{initials(user?.name)}</span>;
}

export default function AuthenticatedLayout({ children }) {
    const { props } = usePage();
    const user = props.auth.user;
    const tenant = props.currentTenant;
    const tenantRole = props.currentTenantRole;
    const tenantRoleLabel = props.currentTenantRoleLabel;
    const contract = props.contract;
    const isPlatformAdmin = Boolean(user?.is_platform_admin);
    const userCan = props.userPermissions?.can || {};
    const canManageTenantUsers = Boolean(userCan.view_users);
    const canManagePermissions = ['tenant_owner', 'tenant_admin'].includes(tenantRole);
    const rncCan = props.rncPermissions?.can || {};
    const activityCan = props.activityPermissions?.can || {};
    const projectCan = props.projectPermissions?.can || {};
    const parametrizacaoCan = props.parametrizacaoPermissions?.can || {};
    const [parametrizacaoOpen, setParametrizacaoOpen] = useState(() => route().current('tenant.parametrizacao.*'));
    const [qualidadeOpen, setQualidadeOpen] = useState(() => route().current('tenant.qualidade.*'));
    const [projectOpen, setProjectOpen] = useState(() => route().current('tenant.projects.*'));
    const [notificationsOpen, setNotificationsOpen] = useState(false);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return window.localStorage.getItem('deming:sidebar-collapsed') === 'true';
    });
    const notificationsRef = useRef(null);
    const notifications = props.notifications?.items || [];
    const unreadNotificationsCount = props.notifications?.unread_count ?? notifications.filter((notification) => notification.unread).length;

    useEffect(() => {
        if (typeof window !== 'undefined') {
            window.localStorage.setItem('deming:sidebar-collapsed', String(sidebarCollapsed));
        }
    }, [sidebarCollapsed]);

    useEffect(() => {
        if (!notificationsOpen) {
            return undefined;
        }

        function handlePointerDown(event) {
            if (!notificationsRef.current?.contains(event.target)) {
                setNotificationsOpen(false);
            }
        }

        function handleKeyDown(event) {
            if (event.key === 'Escape') {
                setNotificationsOpen(false);
            }
        }

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [notificationsOpen]);
    const parametrizacaoItems = tenant && parametrizacaoCan.view_parametrizacao
        ? [
            ...(parametrizacaoCan.view_parametrizacao_empresas ? [{
                label: 'Empresas',
                href: route('tenant.parametrizacao.empresas.index', tenant.slug),
                active: route().current('tenant.parametrizacao.empresas.*'),
            }] : []),
            ...(parametrizacaoCan.view_parametrizacao_obras ? [{
                label: 'Obras',
                href: route('tenant.parametrizacao.obras.index', tenant.slug),
                active: route().current('tenant.parametrizacao.obras.*'),
            }] : []),
            ...(parametrizacaoCan.view_parametrizacao_contrato ? [{
                label: 'Contrato',
                href: route('tenant.parametrizacao.contrato.index', tenant.slug),
                active: route().current('tenant.parametrizacao.contrato.*'),
            }] : []),
            ...(parametrizacaoCan.view_parametrizacao_disciplinas ? [{
                label: 'Disciplinas',
                href: route('tenant.parametrizacao.disciplinas.index', tenant.slug),
                active: route().current('tenant.parametrizacao.disciplinas.*'),
            }] : []),
            ...(parametrizacaoCan.view_parametrizacao_usuarios_contratos ? [{
                label: 'Usuários x Contratos',
                href: route('tenant.parametrizacao.usuarios-contratos.index', tenant.slug),
                active: route().current('tenant.parametrizacao.usuarios-contratos.*'),
            }] : []),
        ]
        : [];
    const rncChildren = tenant
        ? [
            ...(rncCan.view_rnc ? [
                {
                    label: 'RNCs',
                    href: route('tenant.qualidade.rnc.index', tenant.slug),
                    active: route().current('tenant.qualidade.rnc.index')
                        || route().current('tenant.qualidade.rnc.create')
                        || route().current('tenant.qualidade.rnc.edit')
                        || route().current('tenant.qualidade.rnc.show')
                        || route().current('tenant.qualidade.rnc.acao-corretiva.*')
                        || route().current('tenant.qualidade.rnc.analisar-proposta.*')
                        || route().current('tenant.qualidade.rnc.evidencias.*'),
                },
            ] : []),
            ...(rncCan.dashboard_rnc ? [
                {
                    label: 'Dashboard',
                    href: route('tenant.qualidade.rnc.dashboard', tenant.slug),
                    active: route().current('tenant.qualidade.rnc.dashboard'),
                },
            ] : []),
            ...(rncCan.responsibles_rnc ? [
                {
                    label: 'Alertas',
                    href: route('tenant.qualidade.rnc.responsaveis.index', tenant.slug),
                    active: route().current('tenant.qualidade.rnc.responsaveis.*'),
                },
            ] : []),
        ]
        : [];
    const qualidadeItems = rncChildren;
    const projectItems = tenant
        ? [
            ...(projectCan.view_projects ? [{
                label: 'Visualizar projetos',
                href: route('tenant.projects.visualizar.index', tenant.slug),
                active: route().current('tenant.projects.visualizar.*') || route().current('tenant.projects.viewer'),
            }, {
                label: 'Projetos revisados',
                href: route('tenant.projects.revisions.index', tenant.slug),
                active: route().current('tenant.projects.revisions.*'),
            }] : []),
            ...(projectCan.upload_project ? [{
                label: 'Submeter projeto',
                href: route('tenant.projects.index', tenant.slug),
                active: route().current('tenant.projects.index'),
            }] : []),
            ...(projectCan.review_project ? [{
                label: 'Analisar projeto',
                href: route('tenant.projects.review.index', tenant.slug),
                active: route().current('tenant.projects.review.*'),
            }] : []),
            ...(projectCan.manage_project_responsibles ? [{
                label: 'Responsaveis',
                href: route('tenant.projects.responsaveis.index', tenant.slug),
                active: route().current('tenant.projects.responsaveis.*'),
            }] : []),
        ]
        : [];

    const navItems = [];

    if (isPlatformAdmin) {
        navItems.push(
            { label: 'Super Admin', icon: Gauge, href: route('platform.dashboard'), active: route().current('platform.dashboard') },
            { label: 'Tenants', icon: Building2, href: route('platform.tenants.index'), active: route().current('platform.tenants.*') },
            { label: 'Uso APS', icon: HardDrive, href: route('platform.aps.index'), active: route().current('platform.aps.*') },
        );
    }

    if (tenant) {
        navItems.push(
            { label: 'Visão geral', icon: Home, href: route('tenant.dashboard', tenant.slug), active: route().current('tenant.dashboard') },
            { label: 'Contratos', icon: ClipboardList, href: route('tenant.contracts.index', tenant.slug), active: route().current('tenant.contracts.*'), badge: contract ? 'ativo' : null },
            ...(activityCan.view_activities ? [
                { label: 'Atividades', icon: Activity, href: route('tenant.activities.index', tenant.slug), active: route().current('tenant.activities.*') },
            ] : []),
            ...(projectItems.length > 0 ? [
                { label: 'Projetos', icon: FolderOpen, active: route().current('tenant.projects.*'), children: projectItems },
            ] : []),
            { label: 'Obras', icon: Building2, href: route('tenant.contracts.index', tenant.slug), active: false },
            { label: 'Medições', icon: Layers3, href: route('tenant.contracts.index', tenant.slug), active: false },
            { label: 'Relatórios', icon: FileChartColumn, href: route('tenant.contracts.index', tenant.slug), active: false },
        );

        if (canManageTenantUsers) {
            navItems.push({
                label: 'Usuários',
                icon: Users,
                href: route('tenant.users.index', tenant.slug),
                active: route().current('tenant.users.*'),
            });
        }

        if (canManagePermissions) {
            navItems.push({
                label: 'Permissões',
                icon: KeyRound,
                href: route('tenant.permissions.index', tenant.slug),
                active: route().current('tenant.permissions.*'),
            });
        }
    }

    const crumbs = contract && tenant
        ? [
            { label: tenant.name, href: route('tenant.dashboard', tenant.slug) },
            { label: 'Contratos', href: route('tenant.contracts.index', tenant.slug) },
            { label: contract.code },
        ]
        : tenant
            ? [
                { label: tenant.name, href: route('tenant.dashboard', tenant.slug) },
                { label: route().current('tenant.contracts.*') ? 'Contratos' : route().current('tenant.activities.*') ? 'Atividades' : route().current('tenant.projects.*') ? 'Projetos' : route().current('tenant.users.*') ? 'Usuários' : route().current('tenant.parametrizacao.*') ? 'Parametrização' : route().current('tenant.qualidade.*') ? 'Qualidade' : 'Visão geral' },
            ]
            : [
                { label: 'Platform' },
                { label: route().current('platform.tenants.*') ? 'Tenants' : route().current('platform.aps.*') ? 'Uso APS' : 'Super Admin' },
            ];

    return (
        <div className={`sig-shell ${sidebarCollapsed ? 'sidebar-collapsed' : ''}`}>
            <aside className="sig-side">
                <div className="flex h-[60px] items-center border-b border-[var(--side-border)] px-[18px]">
                    <Link href={route('dashboard')}>
                        <SigLogo size={24} />
                    </Link>
                </div>

                {contract && (
                    <div className="px-3 pb-2 pt-3">
                        <Link
                            href={route('tenant.contracts.index', tenant.slug)}
                            className="flex w-full items-center gap-3 rounded-lg border border-[var(--side-border)] bg-[var(--side-hover)] px-3 py-2.5 text-left"
                        >
                            <span className="flex h-7 w-7 items-center justify-center rounded-md bg-[var(--primary)] text-[11px] font-bold text-white">
                                {contract.code?.slice(0, 2)}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="eyebrow block text-[var(--side-fg-dim)]">Contrato ativo</span>
                                <span className="mono block truncate text-[12.5px] text-[var(--side-fg)]">{contract.code}</span>
                            </span>
                        </Link>
                    </div>
                )}

                <nav className="flex-1 overflow-y-auto py-3">
                    <div className="eyebrow px-5 pb-2 pt-1 text-[var(--side-fg-dim)]">Workspace</div>
                    {navItems.map((item) => {
                        const Icon = item.icon;

                        if (item.children) {
                            return (
                                <div key={item.label}>
                                    <button
                                        type="button"
                                        className={`sig-nav-item border-0 bg-transparent text-left ${item.active ? 'active' : ''}`}
                                        onClick={() => setProjectOpen((open) => !open)}
                                    >
                                        <Icon size={17} strokeWidth={1.8} />
                                        <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                        <ChevronRight
                                            size={15}
                                            className={`transition-transform ${projectOpen ? 'rotate-90' : ''}`}
                                        />
                                    </button>
                                    {projectOpen && (
                                        <div className="ml-7">
                                            {item.children.map((child) => (
                                                <Link
                                                    key={child.label}
                                                    href={child.href}
                                                    className={`sig-nav-item !w-[calc(100%_-_16px)] !py-2 !text-[12.5px] ${child.active ? 'active' : ''}`}
                                                >
                                                    <span className="h-1.5 w-1.5 rounded-full bg-current opacity-60" />
                                                    <span className="min-w-0 flex-1 truncate">{child.label}</span>
                                                </Link>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            );
                        }

                        return (
                            <Link
                                key={item.label}
                                href={item.href}
                                className={`sig-nav-item ${item.active ? 'active' : ''}`}
                            >
                                <Icon size={17} strokeWidth={1.8} />
                                <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                {item.badge && <span className="sig-pill bg-white px-2 py-0.5 text-[10.5px]">{item.badge}</span>}
                            </Link>
                        );
                    })}

                    {qualidadeItems.length > 0 && (
                        <div className="mt-2">
                            <button
                                type="button"
                                className={`sig-nav-item border-0 bg-transparent text-left ${route().current('tenant.qualidade.*') ? 'active' : ''}`}
                                onClick={() => setQualidadeOpen((open) => !open)}
                            >
                                <ShieldCheck size={17} strokeWidth={1.8} />
                                <span className="min-w-0 flex-1 truncate">Qualidade</span>
                                <ChevronRight
                                    size={15}
                                    className={`transition-transform ${qualidadeOpen ? 'rotate-90' : ''}`}
                                />
                            </button>
                            {qualidadeOpen && (
                                <div className="ml-7">
                                {qualidadeItems.map((item) => (
                                    <Link
                                        key={item.label}
                                        href={item.href}
                                        className={`sig-nav-item !w-[calc(100%_-_16px)] !py-2 !text-[12.5px] ${item.active ? 'active' : ''}`}
                                    >
                                        <span className="h-1.5 w-1.5 rounded-full bg-current opacity-60" />
                                        <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                    </Link>
                                ))}
                                </div>
                            )}
                        </div>
                    )}

                    {parametrizacaoItems.length > 0 && (
                        <div className="mt-2">
                            <button
                                type="button"
                                className={`sig-nav-item border-0 bg-transparent text-left ${route().current('tenant.parametrizacao.*') ? 'active' : ''}`}
                                onClick={() => setParametrizacaoOpen((open) => !open)}
                            >
                                <SlidersHorizontal size={17} strokeWidth={1.8} />
                                <span className="min-w-0 flex-1 truncate">Parametrização</span>
                                <ChevronRight
                                    size={15}
                                    className={`transition-transform ${parametrizacaoOpen ? 'rotate-90' : ''}`}
                                />
                            </button>
                            {parametrizacaoOpen && (
                                <div className="ml-7">
                                {parametrizacaoItems.map((item) => (
                                    <Link
                                        key={item.label}
                                        href={item.href}
                                        className={`sig-nav-item !w-[calc(100%_-_16px)] !py-2 !text-[12.5px] ${item.active ? 'active' : ''}`}
                                    >
                                        <span className="h-1.5 w-1.5 rounded-full bg-current opacity-60" />
                                        <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                    </Link>
                                ))}
                                </div>
                            )}
                        </div>
                    )}

                    <div className="mx-4 my-4 h-px bg-[var(--side-border)]" />
                    <div className="eyebrow px-5 pb-2 text-[var(--side-fg-dim)]">Sistema</div>
                    <Link href={route('profile.edit')} className={`sig-nav-item ${route().current('profile.*') ? 'active' : ''}`}>
                        <Settings size={17} strokeWidth={1.8} />
                        <span>Configurações</span>
                    </Link>
                </nav>

                <div className="sig-user-block flex items-center gap-3 border-t border-[var(--side-border)] p-3">
                    <UserAvatar user={user} />
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-[13px] font-semibold text-[var(--side-fg)]">{user.name}</div>
                        <div className="truncate text-[11.5px] text-[var(--side-fg-dim)]">{tenantRoleLabel || 'participante'}</div>
                    </div>
                    <Link href={route('logout')} method="post" as="button" className="rounded-md p-1.5 text-[var(--side-fg-dim)] hover:bg-[var(--side-hover)]" title="Sair">
                        <LogOut size={16} />
                    </Link>
                </div>
            </aside>

            <section className="sig-main">
                <header className="sig-topbar">
                    <button
                        type="button"
                        className="sig-btn sig-btn-ghost !min-h-9 !px-2"
                        title={sidebarCollapsed ? 'Mostrar menu lateral' : 'Esconder menu lateral'}
                        aria-label={sidebarCollapsed ? 'Mostrar menu lateral' : 'Esconder menu lateral'}
                        aria-pressed={sidebarCollapsed}
                        onClick={() => setSidebarCollapsed((collapsed) => !collapsed)}
                    >
                        {sidebarCollapsed ? <PanelLeftOpen size={18} /> : <PanelLeftClose size={18} />}
                    </button>

                    <div className="flex min-w-0 flex-1 items-center gap-2 text-[13.5px] text-[var(--ink-500)]">
                        {crumbs.map((crumb, index) => (
                            <span key={`${crumb.label}-${index}`} className="flex min-w-0 items-center gap-2">
                                {index > 0 && <ChevronRight size={14} />}
                                {crumb.href ? (
                                    <Link
                                        href={crumb.href}
                                        className={`truncate ${index === crumbs.length - 1 ? 'font-semibold text-[var(--ink-900)]' : ''}`}
                                    >
                                        {crumb.label}
                                    </Link>
                                ) : (
                                    <span className={`truncate ${index === crumbs.length - 1 ? 'font-semibold text-[var(--ink-900)]' : ''}`}>
                                        {crumb.label}
                                    </span>
                                )}
                            </span>
                        ))}
                    </div>

                    <div className="hidden w-[280px] items-center gap-2 rounded-lg border border-[var(--border)] bg-white px-3 py-2 text-[var(--ink-500)] lg:flex">
                        <Search size={15} />
                        <span className="text-[13px]">Buscar contrato, obra, medição...</span>
                    </div>

                    <div ref={notificationsRef} className="relative">
                        <button
                            type="button"
                            className="sig-btn sig-btn-ghost relative !min-h-9 !px-2"
                            title="Notificações"
                            aria-haspopup="menu"
                            aria-expanded={notificationsOpen}
                            onClick={() => setNotificationsOpen((open) => !open)}
                        >
                            <Bell size={18} />
                            {unreadNotificationsCount > 0 && (
                                <span className="absolute right-2 top-2 h-2 w-2 rounded-full bg-[var(--red)] ring-2 ring-white" />
                            )}
                        </button>

                        {notificationsOpen && (
                            <div
                                className="absolute right-0 top-[calc(100%+10px)] z-50 w-[min(340px,calc(100vw-32px))] overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_18px_45px_rgba(15,23,42,0.14)]"
                                role="menu"
                                aria-label="Notificações do usuário"
                            >
                                <div className="flex items-center justify-between gap-3 border-b border-[var(--border)] px-4 py-3">
                                    <div>
                                        <div className="text-[13.5px] font-semibold text-[var(--ink-900)]">Notificações</div>
                                        <div className="text-[11.5px] text-[var(--ink-500)]">Alertas vinculados ao seu usuário</div>
                                    </div>
                                    <span className="rounded-full bg-[var(--surface-muted)] px-2 py-1 text-[11px] font-semibold text-[var(--ink-700)]">
                                        {unreadNotificationsCount > 0 ? `${unreadNotificationsCount} nova${unreadNotificationsCount === 1 ? '' : 's'}` : 'Sem novas'}
                                    </span>
                                </div>

                                <div className="max-h-[360px] overflow-y-auto p-2">
                                    {notifications.length > 0 ? notifications.map((notification) => {
                                        const content = (
                                            <>
                                                <span
                                                    className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${notification.unread ? 'bg-[var(--red)]' : 'bg-[var(--ink-300)]'}`}
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center justify-between gap-3">
                                                        <span className="truncate text-[13px] font-semibold text-[var(--ink-900)]">
                                                            {notification.title}
                                                        </span>
                                                        <span className="shrink-0 text-[11px] text-[var(--ink-500)]">
                                                            {notification.time}
                                                        </span>
                                                    </span>
                                                    {notification.contract && (
                                                        <span className="mono mt-0.5 block text-[11px] text-[var(--ink-500)]">
                                                            {notification.contract}
                                                        </span>
                                                    )}
                                                    <span className="mt-0.5 block text-[12.5px] leading-5 text-[var(--ink-600)]">
                                                        {notification.body}
                                                    </span>
                                                </span>
                                            </>
                                        );

                                        return notification.url ? (
                                            <Link
                                                key={notification.id}
                                                href={notification.url}
                                                className="flex gap-3 rounded-lg px-3 py-2.5 text-left hover:bg-[var(--surface-muted)]"
                                                role="menuitem"
                                            >
                                                {content}
                                            </Link>
                                        ) : (
                                            <div
                                                key={notification.id}
                                                className="flex gap-3 rounded-lg px-3 py-2.5 text-left hover:bg-[var(--surface-muted)]"
                                                role="menuitem"
                                            >
                                                {content}
                                            </div>
                                        );
                                    }) : (
                                        <div className="px-3 py-6 text-center text-[12.5px] text-[var(--ink-500)]">
                                            Nenhuma notificação por enquanto.
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </header>

                <main>{children}</main>
            </section>
        </div>
    );
}
