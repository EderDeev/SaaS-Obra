import SigLogo from '@/Components/SigLogo';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    Activity,
    Bell,
    BookOpen,
    Building2,
    Calculator,
    CalendarDays,
    ChevronDown,
    ChevronRight,
    ClipboardList,
    FileText,
    FolderOpen,
    Gauge,
    HardDrive,
    Home,
    KeyRound,
    LogOut,
    Menu,
    PanelLeftClose,
    PanelLeftOpen,
    Ruler,
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
    const navigationContracts = props.navigationContracts || [];
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
    const [orcamentosOpen, setOrcamentosOpen] = useState(() => route().current('tenant.orcamentos.*'));
    const [medicaoOpen, setMedicaoOpen] = useState(() => route().current('tenant.medicao.*'));
    const [ordemServicoOpen, setOrdemServicoOpen] = useState(() => route().current('tenant.ordem-servico.*'));
    const [diarioObraOpen, setDiarioObraOpen] = useState(() => route().current('tenant.diario-obra.*'));
    const [gedOpen, setGedOpen] = useState(() => route().current('tenant.ged.*'));
    const [rdoOpen, setRdoOpen] = useState(() => route().current('tenant.diario-obra.rdo.*'));
    const [rdaOpen, setRdaOpen] = useState(() => route().current('tenant.diario-obra.rda.*'));
    const [notificationsOpen, setNotificationsOpen] = useState(false);
    const [mobileNavOpen, setMobileNavOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return window.localStorage.getItem('deming:sidebar-collapsed') === 'true';
    });
    const notificationsRef = useRef(null);
    const mobileNavRef = useRef(null);
    const userMenuRef = useRef(null);
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

    useEffect(() => {
        if (!mobileNavOpen && !userMenuOpen) {
            return undefined;
        }

        function handlePointerDown(event) {
            if (mobileNavOpen && !mobileNavRef.current?.contains(event.target)) {
                setMobileNavOpen(false);
            }

            if (userMenuOpen && !userMenuRef.current?.contains(event.target)) {
                setUserMenuOpen(false);
            }
        }

        function handleKeyDown(event) {
            if (event.key === 'Escape') {
                setMobileNavOpen(false);
                setUserMenuOpen(false);
            }
        }

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [mobileNavOpen, userMenuOpen]);
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
            ...(parametrizacaoCan.view_parametrizacao_disciplinas ? [{
                label: 'Disciplinas',
                href: route('tenant.parametrizacao.disciplinas.index', tenant.slug),
                active: route().current('tenant.parametrizacao.disciplinas.*'),
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
    const orcamentoItems = tenant
        ? [
            {
                label: 'Listar Orçamentos',
                href: route('tenant.orcamentos.index', tenant.slug),
                active: route().current('tenant.orcamentos.index'),
            },
            {
                label: 'Composições',
                href: route('tenant.orcamentos.composicoes.index', tenant.slug),
                active: route().current('tenant.orcamentos.composicoes.*'),
            },
            {
                label: 'Insumos',
                href: route('tenant.orcamentos.insumos.index', tenant.slug),
                active: route().current('tenant.orcamentos.insumos.*'),
            },
        ]
        : [];
    const medicaoItems = tenant
        ? [
            {
                label: 'Boletim Medição',
                href: route('tenant.medicao.boletim-medicao.index', tenant.slug),
                active: route().current('tenant.medicao.boletim-medicao.*'),
            },
            {
                label: 'Relatórios Medição',
                href: route('tenant.medicao.relatorios.index', tenant.slug),
                active: route().current('tenant.medicao.relatorios.*'),
            },
            {
                label: 'B.I',
                href: route('tenant.medicao.bi.index', tenant.slug),
                active: route().current('tenant.medicao.bi.*'),
            },
            {
                label: 'Folha de Rosto',
                href: route('tenant.medicao.folha-rosto.index', tenant.slug),
                active: route().current('tenant.medicao.folha-rosto.*'),
            },
            {
                label: 'Analisar Pleito',
                href: route('tenant.medicao.analisar-pleito.index', tenant.slug),
                active: route().current('tenant.medicao.analisar-pleito.index'),
            },
            {
                label: 'Item',
                href: route('tenant.medicao.item.index', tenant.slug),
                active: route().current('tenant.medicao.item.*'),
            },
            {
                label: 'Índice de Reajuste',
                href: route('tenant.medicao.indice-reajuste.index', tenant.slug),
                active: route().current('tenant.medicao.indice-reajuste.*'),
            },
            {
                label: 'Responsáveis análise',
                href: route('tenant.medicao.analisar-pleito.responsaveis.index', tenant.slug),
                active: route().current('tenant.medicao.analisar-pleito.responsaveis.*'),
            },
        ]
        : [];
    const ordemServicoItems = tenant
        ? [
        {
            label: 'OS',
            href: route('tenant.ordem-servico.os.index', tenant.slug),
            active: route().current('tenant.ordem-servico.os.*'),
        },
        {
            label: 'Análise OS',
            href: route('tenant.ordem-servico.analise.index', tenant.slug),
            active: route().current('tenant.ordem-servico.analise.*'),
        },
        {
            label: 'Responsáveis',
            href: route('tenant.ordem-servico.responsaveis.index', tenant.slug),
            active: route().current('tenant.ordem-servico.responsaveis.*'),
        },
    ]
    : [];
    const diarioObraItems = tenant
        ? [{
            label: 'RDO',
            active: route().current('tenant.diario-obra.rdo.*'),
            children: [
                {
                    label: 'Calendário',
                    href: route('tenant.diario-obra.rdo.calendar', tenant.slug),
                    active: route().current('tenant.diario-obra.rdo.calendar') || route().current('tenant.diario-obra.rdo.show'),
                },
                {
                    label: 'Dashboard',
                    href: route('tenant.diario-obra.rdo.dashboard', tenant.slug),
                    active: route().current('tenant.diario-obra.rdo.dashboard'),
                },
                {
                    label: 'Responsáveis',
                    href: route('tenant.diario-obra.rdo.responsaveis.index', tenant.slug),
                    active: route().current('tenant.diario-obra.rdo.responsaveis.*'),
                },
                {
                    label: 'Cadastros',
                    href: route('tenant.diario-obra.rdo.cadastros.index', tenant.slug),
                    active: route().current('tenant.diario-obra.rdo.cadastros.*'),
                },
                {
                    label: 'Parametrização',
                    href: route('tenant.diario-obra.rdo.settings', tenant.slug),
                    active: route().current('tenant.diario-obra.rdo.settings*'),
                },
            ],
        },
        {
            label: 'RDA',
            active: route().current('tenant.diario-obra.rda.*'),
            children: [
                {
                    label: 'Calendário',
                    href: route('tenant.diario-obra.rda.index', tenant.slug),
                    active: route().current('tenant.diario-obra.rda.index') || route().current('tenant.diario-obra.rda.show'),
                },
                {
                    label: 'Responsáveis',
                    href: route('tenant.diario-obra.rda.responsaveis.index', tenant.slug),
                    active: route().current('tenant.diario-obra.rda.responsaveis.*'),
                },
            ],
        }]
        : [];
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
            ...(projectCan.view_projects ? [{
                label: 'Lista Mestra',
                href: route('tenant.projects.master-list.index', tenant.slug),
                active: route().current('tenant.projects.master-list.*'),
            }] : []),
            ...(projectCan.manage_project_responsibles ? [{
                label: 'Responsaveis',
                href: route('tenant.projects.responsaveis.index', tenant.slug),
                active: route().current('tenant.projects.responsaveis.*'),
            }] : []),
        ]
        : [];
    const gedItems = tenant
        ? [
            {
                label: 'Documentos',
                href: route('tenant.ged.index', tenant.slug),
                active: route().current('tenant.ged.index'),
            },
            {
                label: 'E-mail',
                href: route('tenant.ged.email', tenant.slug),
                active: route().current('tenant.ged.email'),
            },
            {
                label: 'Lixeira',
                href: route('tenant.ged.trash', tenant.slug),
                active: route().current('tenant.ged.trash'),
            },
            {
                label: 'Parametrização',
                href: route('tenant.ged.settings', tenant.slug),
                active: route().current('tenant.ged.settings'),
            },
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
            { label: 'Orçamentos', icon: Calculator, active: route().current('tenant.orcamentos.*'), children: orcamentoItems },
            { label: 'Medição', icon: Ruler, active: route().current('tenant.medicao.*'), children: medicaoItems },
            { label: 'Ordem de Serviço', icon: ClipboardList, active: route().current('tenant.ordem-servico.*'), children: ordemServicoItems },
            { label: 'Diário de Obra', icon: CalendarDays, active: route().current('tenant.diario-obra.*'), children: diarioObraItems },
            { label: 'Documentação', icon: FileText, active: route().current('tenant.ged.*'), children: gedItems },
            ...(projectItems.length > 0 ? [
                { label: 'Projetos', icon: FolderOpen, active: route().current('tenant.projects.*'), children: projectItems },
            ] : []),
            ...(qualidadeItems.length > 0 ? [
                { label: 'Qualidade', icon: ShieldCheck, active: route().current('tenant.qualidade.*'), children: qualidadeItems },
            ] : []),
            { label: 'Tutoriais', icon: BookOpen, href: route('tenant.tutorials.index', tenant.slug), active: route().current('tenant.tutorials.*') },
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
                { label: route().current('tenant.contracts.*') ? 'Contratos' : route().current('tenant.activities.*') ? 'Atividades' : route().current('tenant.orcamentos.*') ? 'Orçamentos' : route().current('tenant.medicao.*') ? 'Medição' : route().current('tenant.ordem-servico.*') ? 'Ordem de Serviço' : route().current('tenant.diario-obra.*') ? 'Diário de Obra' : route().current('tenant.projects.*') ? 'Projetos' : route().current('tenant.users.*') ? 'Usuários' : route().current('tenant.parametrizacao.*') ? 'Parametrização' : route().current('tenant.qualidade.*') ? 'Qualidade' : route().current('tenant.tutorials.*') ? 'Tutoriais' : 'Visão geral' },
            ]
            : [
                { label: 'Platform' },
                { label: route().current('platform.tenants.*') ? 'Tenants' : route().current('platform.aps.*') ? 'Uso APS' : 'Super Admin' },
            ];

    const mobileNavItems = [
        ...navItems,
        ...(parametrizacaoItems.length > 0 ? [{
            label: 'ParametrizaÃ§Ã£o',
            icon: SlidersHorizontal,
            active: route().current('tenant.parametrizacao.*'),
            children: parametrizacaoItems,
        }] : []),
    ];
    const normalizedMobileNavItems = mobileNavItems.map((item) => (
        item.children === parametrizacaoItems
            ? { ...item, label: 'Parametrização' }
            : item
    ));

    return (
        <div className={`sig-shell ${sidebarCollapsed ? 'sidebar-collapsed' : ''}`}>
            <aside className="sig-side">
                <div className="flex h-[60px] items-center border-b border-[var(--side-border)] px-[18px]">
                    <Link href={tenant ? route('tenant.dashboard', tenant.slug) : route('dashboard')}>
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
                        const childrenOpen = item.label === 'Qualidade'
                            ? qualidadeOpen
                            : item.label === 'Diário de Obra'
                                ? diarioObraOpen
                            : item.label === 'Orçamentos'
                                ? orcamentosOpen
                                : item.label === 'Medição'
                                    ? medicaoOpen
                                    : item.label === 'Ordem de Serviço'
                                        ? ordemServicoOpen
                                        : item.label === 'Documentação'
                                            ? gedOpen
                                            : projectOpen;
                        const toggleChildren = item.label === 'Qualidade'
                            ? () => setQualidadeOpen((open) => !open)
                            : item.label === 'Diário de Obra'
                                ? () => setDiarioObraOpen((open) => !open)
                            : item.label === 'Orçamentos'
                                ? () => setOrcamentosOpen((open) => !open)
                                : item.label === 'Medição'
                                    ? () => setMedicaoOpen((open) => !open)
                                    : item.label === 'Ordem de Serviço'
                                        ? () => setOrdemServicoOpen((open) => !open)
                                        : item.label === 'Documentação'
                                            ? () => setGedOpen((open) => !open)
                                            : () => setProjectOpen((open) => !open);

                        if (item.children) {
                            return (
                                <div key={item.label}>
                                    <button
                                        type="button"
                                        className={`sig-nav-item border-0 bg-transparent text-left ${item.active ? 'active' : ''}`}
                                        onClick={toggleChildren}
                                    >
                                        <Icon size={17} strokeWidth={1.8} />
                                        <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                        <ChevronRight
                                            size={15}
                                            className={`transition-transform ${childrenOpen ? 'rotate-90' : ''}`}
                                        />
                                    </button>
                                    {childrenOpen && (
                                        <div className="ml-7">
                                            {item.children.map((child) => {
                                                const nestedOpen = child.label === 'RDA' ? rdaOpen : rdoOpen;
                                                const toggleNestedOpen = child.label === 'RDA' ? setRdaOpen : setRdoOpen;

                                                return child.children ? (
                                                <div key={child.label}>
                                                    <button
                                                        type="button"
                                                        onClick={() => toggleNestedOpen((open) => !open)}
                                                        className={`sig-nav-item !w-[calc(100%_-_16px)] border-0 bg-transparent !py-2 text-left !text-[12.5px] ${child.active ? 'active' : ''}`}
                                                    >
                                                        <span className="h-1.5 w-1.5 rounded-full bg-current opacity-60" />
                                                        <span className="min-w-0 flex-1 truncate">{child.label}</span>
                                                        <ChevronRight size={13} className={`transition-transform ${nestedOpen ? 'rotate-90' : ''}`} />
                                                    </button>
                                                    {nestedOpen && (
                                                        <div className="ml-5">
                                                            {child.children.map((grandchild) => (
                                                                <Link
                                                                    key={grandchild.label}
                                                                    href={grandchild.href}
                                                                    className={`sig-nav-item !w-[calc(100%_-_16px)] !py-1.5 !text-[12px] ${grandchild.active ? 'active' : ''}`}
                                                                >
                                                                    <span className="h-1 w-1 rounded-full bg-current opacity-50" />
                                                                    <span className="min-w-0 flex-1 truncate">{grandchild.label}</span>
                                                                </Link>
                                                            ))}
                                                        </div>
                                                    )}
                                                </div>
                                            ) : (
                                                <Link
                                                    key={child.label}
                                                    href={child.href}
                                                    className={`sig-nav-item !w-[calc(100%_-_16px)] !py-2 !text-[12.5px] ${child.active ? 'active' : ''}`}
                                                >
                                                    <span className="h-1.5 w-1.5 rounded-full bg-current opacity-60" />
                                                    <span className="min-w-0 flex-1 truncate">{child.label}</span>
                                                    {child.badge && <span className="sig-pill bg-white px-2 py-0.5 text-[10.5px]">{child.badge}</span>}
                                                </Link>
                                            );
                                            })}
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

                </nav>
            </aside>

            <section className="sig-main">
                <header className="sig-topbar">
                    <div ref={mobileNavRef} className="relative lg:hidden">
                        <button
                            type="button"
                            className="sig-btn sig-btn-primary !min-h-9 !px-3"
                            aria-haspopup="menu"
                            aria-expanded={mobileNavOpen}
                            onClick={() => setMobileNavOpen((open) => !open)}
                        >
                            <Menu size={17} />
                            Menu
                            <ChevronDown size={14} className={`transition-transform ${mobileNavOpen ? 'rotate-180' : ''}`} />
                        </button>

                        {mobileNavOpen && (
                            <div
                                className="absolute left-0 top-[calc(100%+10px)] z-50 w-[min(340px,calc(100vw-32px))] overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_18px_45px_rgba(15,23,42,0.14)]"
                                role="menu"
                                aria-label="Menu principal"
                            >
                                <div className="max-h-[70vh] overflow-y-auto p-2">
                                    <MobileNavList items={normalizedMobileNavItems} onNavigate={() => setMobileNavOpen(false)} />
                                </div>
                            </div>
                        )}
                    </div>

                    <button
                        type="button"
                        className="sig-btn sig-btn-ghost !hidden !min-h-9 !px-2 lg:!inline-flex"
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

                    <div ref={userMenuRef} className="relative">
                        <button
                            type="button"
                            className="flex items-center gap-2 rounded-full border border-[var(--border)] bg-white p-1 pr-2 shadow-sm transition hover:bg-[var(--surface-muted)]"
                            aria-haspopup="menu"
                            aria-expanded={userMenuOpen}
                            title="Menu do usuário"
                            onClick={() => setUserMenuOpen((open) => !open)}
                        >
                            <UserAvatar user={user} className="!h-8 !w-8 !text-[11px]" />
                            <ChevronDown size={14} className={`hidden text-[var(--ink-500)] transition-transform sm:block ${userMenuOpen ? 'rotate-180' : ''}`} />
                        </button>

                        {userMenuOpen && (
                            <div
                                className="absolute right-0 top-[calc(100%+10px)] z-50 w-[min(340px,calc(100vw-32px))] overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-[0_18px_45px_rgba(15,23,42,0.14)]"
                                role="menu"
                                aria-label="Menu do usuário"
                            >
                                <div className="border-b border-[var(--border)] px-4 py-3">
                                    <div className="flex items-center gap-3">
                                        <UserAvatar user={user} />
                                        <div className="min-w-0">
                                            <div className="truncate text-sm font-semibold text-[var(--ink-900)]">{user.name}</div>
                                            <div className="truncate text-xs text-[var(--ink-500)]">{user.email}</div>
                                        </div>
                                    </div>
                                </div>

                                {tenant && navigationContracts.length > 0 && (
                                    <div className="border-b border-[var(--border)] p-2">
                                        <div className="eyebrow px-2 py-1 text-[10px]">Contratos</div>
                                        <div className="max-h-56 overflow-y-auto">
                                            {navigationContracts.map((navigationContract) => {
                                                const active = String(contract?.id) === String(navigationContract.id);

                                                return (
                                                    <Link
                                                        key={navigationContract.id}
                                                        href={route('tenant.contracts.show', [tenant.slug, navigationContract.id])}
                                                        onClick={() => setUserMenuOpen(false)}
                                                        className={`flex items-start gap-2 rounded-lg px-2 py-2 text-sm hover:bg-[var(--surface-muted)] ${active ? 'bg-[var(--blue-50)] text-[var(--primary)]' : 'text-[var(--ink-700)]'}`}
                                                        role="menuitem"
                                                    >
                                                        <span className={`mt-1 h-2 w-2 shrink-0 rounded-full ${active ? 'bg-[var(--primary)]' : 'bg-[var(--ink-300)]'}`} />
                                                        <span className="min-w-0">
                                                            <span className="mono block truncate text-[12px] font-semibold">{navigationContract.code}</span>
                                                            <span className="block truncate text-xs text-[var(--ink-500)]">{navigationContract.name}</span>
                                                        </span>
                                                    </Link>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}

                                <div className="p-2">
                                    <Link
                                        href={route('profile.edit')}
                                        onClick={() => setUserMenuOpen(false)}
                                        className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-[var(--ink-700)] hover:bg-[var(--surface-muted)]"
                                        role="menuitem"
                                    >
                                        <Settings size={16} />
                                        Editar perfil / foto
                                    </Link>
                                    <Link
                                        href={route('logout')}
                                        method="post"
                                        as="button"
                                        className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-semibold text-[var(--red)] hover:bg-[var(--red-50)]"
                                        role="menuitem"
                                    >
                                        <LogOut size={16} />
                                        Sair
                                    </Link>
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

function MobileNavList({ items, onNavigate, level = 0 }) {
    return (
        <div className={level > 0 ? 'ml-3 border-l border-[var(--border)] pl-2' : ''}>
            {items.map((item) => (
                <MobileNavItem key={`${level}-${item.label}`} item={item} onNavigate={onNavigate} level={level} />
            ))}
        </div>
    );
}

function MobileNavItem({ item, onNavigate, level }) {
    const [open, setOpen] = useState(Boolean(item.active));
    const Icon = item.icon;

    if (item.children?.length) {
        return (
            <div>
                <button
                    type="button"
                    className={`flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-semibold ${item.active ? 'bg-[var(--primary-50)] text-[var(--primary)]' : 'text-[var(--ink-700)] hover:bg-[var(--surface-muted)]'}`}
                    onClick={() => setOpen((current) => !current)}
                    role="menuitem"
                >
                    {Icon ? <Icon size={16} /> : <span className="h-1.5 w-1.5 rounded-full bg-current opacity-60" />}
                    <span className="min-w-0 flex-1 truncate">{item.label}</span>
                    <ChevronRight size={14} className={`transition-transform ${open ? 'rotate-90' : ''}`} />
                </button>
                {open && <MobileNavList items={item.children} onNavigate={onNavigate} level={level + 1} />}
            </div>
        );
    }

    return (
        <Link
            href={item.href}
            onClick={onNavigate}
            className={`flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold ${item.active ? 'bg-[var(--primary-50)] text-[var(--primary)]' : 'text-[var(--ink-700)] hover:bg-[var(--surface-muted)]'}`}
            role="menuitem"
        >
            {Icon ? <Icon size={16} /> : <span className="h-1.5 w-1.5 rounded-full bg-current opacity-60" />}
            <span className="min-w-0 flex-1 truncate">{item.label}</span>
        </Link>
    );
}
