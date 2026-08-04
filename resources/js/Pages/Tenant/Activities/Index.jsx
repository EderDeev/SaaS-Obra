import ConfirmActionButton from '@/Components/ConfirmActionButton';
import ActivityTour, { startActivityTour } from '@/Components/ActivityTour';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { consumeAssistantDraft } from '@/Utils/assistantDraft';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Calendar,
    CheckCircle2,
    Circle,
    CircleHelp,
    Clock3,
    Download,
    Flag,
    Globe2,
    KanbanSquare,
    LockKeyhole,
    MessageSquare,
    Paperclip,
    Pencil,
    Plane,
    Plus,
    Search,
    Send,
    Save,
    Tag,
    Trash2,
    Upload,
    UserRound,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const statusColumns = [
    { value: 'todo', label: 'A fazer', icon: Circle, tone: 'var(--ink-500)' },
    { value: 'in_progress', label: 'Em andamento', icon: Clock3, tone: 'var(--primary)' },
    { value: 'review', label: 'Em revisão', icon: Flag, tone: 'var(--amber)' },
    { value: 'done', label: 'Concluídas', icon: CheckCircle2, tone: 'var(--green)' },
];

const priorityMeta = {
    low: { label: 'Baixa', className: 'sig-pill-blue' },
    normal: { label: 'Normal', className: '' },
    high: { label: 'Alta', className: 'sig-pill-amber' },
    urgent: { label: 'Urgente', className: 'sig-pill-red' },
};

const categoryMeta = {
    project: { label: 'Projeto', className: 'sig-pill-blue' },
    quality: { label: 'Qualidade', className: 'sig-pill-green' },
    budget: { label: 'Orçamento', className: 'sig-pill-amber' },
    measurement: { label: 'Medição', className: 'sig-pill-blue' },
    documentation: { label: 'Documentação', className: 'sig-pill-green' },
    service_order: { label: 'Ordem de Serviço', className: 'sig-pill-amber' },
    construction_diary: { label: 'Diário de Obra', className: 'sig-pill-green' },
    contract: { label: 'Contrato', className: 'sig-pill-blue' },
    administrative: { label: 'Administrativo', className: '' },
    field: { label: 'Campo', className: 'sig-pill-green' },
    client: { label: 'Cliente', className: 'sig-pill-blue' },
};

const visibilityMeta = {
    public: { label: 'Pública', icon: Globe2, className: 'sig-pill-blue' },
    restricted: { label: 'Restrita', icon: LockKeyhole, className: 'sig-pill-amber' },
};

const shortDate = (date) => {
    if (!date) return null;

    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short' }).format(new Date(date));
};

const fullDate = (date) => {
    if (!date) return 'Sem prazo';

    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' }).format(new Date(date));
};

const initials = (name = '?') => name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase();

const activityAssignees = (activity) => {
    if (activity.assignees?.length) {
        return activity.assignees;
    }

    return activity.assignee ? [activity.assignee] : [];
};

function UserAvatar({ user, className = '', title }) {
    const [avatarLoadFailed, setAvatarLoadFailed] = useState(false);

    useEffect(() => {
        setAvatarLoadFailed(false);
    }, [user?.avatar_url]);

    if (user?.avatar_url && !avatarLoadFailed) {
        return (
            <img
                src={user.avatar_url}
                alt={user.name}
                title={title || user.name}
                className={`sig-avatar object-cover ${className}`}
                onError={() => setAvatarLoadFailed(true)}
            />
        );
    }

    return (
        <span className={`sig-avatar ${className}`} title={title || user?.name}>
            {initials(user?.name)}
        </span>
    );
}

const dueInfo = (date, status) => {
    if (status === 'done') {
        return { label: 'Concluída', className: 'sig-pill-green' };
    }

    if (!date) {
        return { label: 'Sem prazo', className: '' };
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const due = new Date(date);
    due.setHours(0, 0, 0, 0);

    const days = Math.ceil((due.getTime() - today.getTime()) / 86400000);

    if (days < 0) {
        const overdueDays = Math.abs(days);

        return {
            label: `${overdueDays} dia${overdueDays === 1 ? '' : 's'} atrasada`,
            className: 'sig-pill-red',
        };
    }

    if (days === 0) {
        return { label: 'Vence hoje', className: 'sig-pill-amber' };
    }

    return {
        label: `${days} dia${days === 1 ? '' : 's'} restantes`,
        className: days <= 3 ? 'sig-pill-amber' : 'sig-pill-green',
    };
};

const fileSize = (bytes) => {
    if (!bytes) return '';

    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};

export default function ActivitiesIndex({
    tenant,
    contracts,
    activities,
    assigneesByContract,
    priorities,
    categories,
    visibilities,
    canCreateActivities,
    canEditActivities,
    canDeleteActivities,
    canViewActivityMetrics,
    tourMode = false,
    tourScreen = null,
}) {
    const page = usePage();
    const [query, setQuery] = useState('');
    const [contractFilter, setContractFilter] = useState('todos');
    const [categoryFilter, setCategoryFilter] = useState('todos');
    const [showCreate, setShowCreate] = useState(tourMode && tourScreen === 'create');
    const [draggedActivityId, setDraggedActivityId] = useState(null);
    const [selectedActivityId, setSelectedActivityId] = useState(
        tourMode && tourScreen === 'detail' ? activities[0]?.id ?? null : null,
    );
    const [tourFlowStatus, setTourFlowStatus] = useState(
        tourMode && tourScreen === 'flow' ? 'todo' : null,
    );
    const [assigneeQuery, setAssigneeQuery] = useState('');
    const defaultContractId = contracts[0]?.id ? String(contracts[0].id) : '';
    const tourAssigneeIds = tourMode
        ? (assigneesByContract[defaultContractId] || []).map((user) => user.id)
        : [];
    const form = useForm({
        contract_id: defaultContractId,
        assigned_to_ids: tourAssigneeIds,
        title: tourMode ? 'Validar medição mensal da obra' : '',
        description: tourMode
            ? 'Conferir os quantitativos executados, validar a memória de cálculo e registrar as pendências antes do fechamento da medição.'
            : '',
        category: tourMode ? 'measurement' : 'project',
        visibility: tourMode ? 'restricted' : 'public',
        priority: tourMode ? 'high' : 'normal',
        due_date: tourMode ? activities[0]?.due_date || '' : '',
    });

    useEffect(() => {
        if (tourMode || !canCreateActivities) {
            return;
        }

        const assistantDraft = consumeAssistantDraft(tenant.id, 'activity');

        if (!assistantDraft) {
            return;
        }

        form.setData((current) => ({
            ...current,
            ...assistantDraft,
            assigned_to_ids: [],
        }));
        setShowCreate(true);
    }, []);

    const activitiesForBoard = useMemo(() => activities.map((activity) => (
        tourMode && tourScreen === 'flow' && activity._tourData
            ? { ...activity, status: tourFlowStatus || 'todo' }
            : activity
    )), [activities, tourFlowStatus, tourMode, tourScreen]);
    const selectedActivity = activitiesForBoard.find((activity) => activity.id === selectedActivityId);
    const assigneesForSelectedContract = assigneesByContract[String(form.data.contract_id)] || [];
    const filteredAssigneesForSelectedContract = useMemo(() => {
        const q = assigneeQuery.trim().toLowerCase();

        if (!q) {
            return assigneesForSelectedContract;
        }

        return assigneesForSelectedContract.filter((user) => [
            user.name,
            user.email,
        ].filter(Boolean).join(' ').toLowerCase().includes(q));
    }, [assigneeQuery, assigneesForSelectedContract]);
    const selectedAssignees = useMemo(() => assigneesForSelectedContract.filter(
        (user) => form.data.assigned_to_ids.includes(user.id),
    ), [assigneesForSelectedContract, form.data.assigned_to_ids]);

    const filteredActivities = useMemo(() => {
        const q = query.trim().toLowerCase();

        return activitiesForBoard.filter((activity) => {
            if (contractFilter !== 'todos' && String(activity.contract_id) !== String(contractFilter)) {
                return false;
            }

            if (categoryFilter !== 'todos' && String(activity.category || 'project') !== String(categoryFilter)) {
                return false;
            }

            if (!q) {
                return true;
            }

            return [
                activity.title,
                activity.description,
                categories?.[activity.category],
                activity.contract?.code,
                activity.contract?.name,
                activity.contract?.obra?.nome,
                ...activityAssignees(activity).map((user) => user.name),
            ].filter(Boolean).join(' ').toLowerCase().includes(q);
        });
    }, [activitiesForBoard, categories, categoryFilter, contractFilter, query]);

    useEffect(() => {
        if (!tourMode || tourScreen !== 'flow') {
            return undefined;
        }

        const updateFlowStatus = (event) => {
            if (statusColumns.some((column) => column.value === event.detail)) {
                setTourFlowStatus(event.detail);
            }
        };

        window.addEventListener('activities:tour-flow-status', updateFlowStatus);

        return () => window.removeEventListener('activities:tour-flow-status', updateFlowStatus);
    }, [tourMode, tourScreen]);

    const submit = (event) => {
        event.preventDefault();

        if (tourMode) {
            return;
        }

        form.post(route('tenant.activities.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => {
                form.setData({
                    contract_id: form.data.contract_id || defaultContractId,
                    assigned_to_ids: [],
                    title: '',
                    description: '',
                    category: 'project',
                    visibility: 'public',
                    priority: 'normal',
                    due_date: '',
                });
                setAssigneeQuery('');
                setShowCreate(false);
            },
        });
    };

    const updateContract = (contractId) => {
        form.setData((data) => ({
            ...data,
            contract_id: contractId,
            assigned_to_ids: [],
        }));
        setAssigneeQuery('');
    };

    const toggleAssignee = (userId) => {
        const normalizedUserId = Number(userId);

        form.setData('assigned_to_ids', form.data.assigned_to_ids.includes(normalizedUserId)
            ? form.data.assigned_to_ids.filter((id) => id !== normalizedUserId)
            : [...form.data.assigned_to_ids, normalizedUserId]);
    };

    const moveActivity = (status) => {
        if (tourMode) {
            return;
        }

        if (!draggedActivityId) {
            return;
        }

        const activity = activities.find((item) => item.id === draggedActivityId);
        const canMoveActivity = activity?.can_move ?? activity?.can_edit ?? canEditActivities;

        setDraggedActivityId(null);

        if (!activity || !canMoveActivity || activity.status === status) {
            return;
        }

        router.patch(
            route('tenant.activities.update', [tenant.slug, draggedActivityId]),
            { status },
            { preserveScroll: true },
        );
    };

    return (
        <AuthenticatedLayout>
            <Head title="Atividades" />

            <section className="sig-content fade-in">
                <div data-tour="activities-overview" className="flex flex-wrap items-end gap-6">
                    <div className="min-w-0 flex-1">
                        <div className="eyebrow">Workspace · Atividades</div>
                        <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">Atividades</h1>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {!tourMode && (
                            <button className="sig-btn sig-btn-secondary" type="button" onClick={() => startActivityTour(tenant.slug)}>
                                <Plane size={15} />
                                Iniciar tour
                            </button>
                        )}
                        {(tourMode || canViewActivityMetrics) && (
                            <Link
                                href={tourMode ? '#' : route('tenant.activities.metrics', tenant.slug)}
                                className="sig-btn sig-btn-secondary"
                                onClick={(event) => tourMode && event.preventDefault()}
                            >
                                <BarChart3 size={15} />
                                Métricas
                            </Link>
                        )}
                        {canCreateActivities && (
                            <button
                                className="sig-btn sig-btn-primary"
                                type="button"
                                onClick={() => !tourMode && setShowCreate(true)}
                            >
                                <Plus size={15} />
                                Nova atividade
                            </button>
                        )}
                    </div>
                </div>

                <div data-tour="activities-filters" className="mt-6 flex flex-wrap items-center gap-3">
                    <label className="sig-input min-w-[240px] max-w-[360px] flex-1">
                        <Search size={15} />
                        <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar por título, contrato ou responsável" />
                    </label>

                    <label className="sig-input max-w-[320px]">
                        <KanbanSquare size={15} />
                        <select value={contractFilter} onChange={(event) => setContractFilter(event.target.value)}>
                            <option value="todos">Todos os contratos</option>
                            {contracts.map((contract) => (
                                <option key={contract.id} value={contract.id}>
                                    {contract.code} · {contract.name}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="sig-input max-w-[240px]">
                        <Tag size={15} />
                        <select value={categoryFilter} onChange={(event) => setCategoryFilter(event.target.value)}>
                            <option value="todos">Todas as categorias</option>
                            {Object.entries(categories || {}).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </select>
                    </label>
                </div>

                {page.props.flash.success && (
                    <div className="mt-4 rounded-lg bg-[var(--green-50)] px-4 py-3 text-sm font-semibold text-[var(--green)]">
                        {page.props.flash.success}
                    </div>
                )}

                {showCreate && canCreateActivities && (
                    <div
                        data-tour="activities-create-modal"
                        className="fixed inset-0 z-[90] flex items-start justify-center overflow-y-auto bg-[rgba(11,16,32,0.45)] px-4 py-6"
                        onMouseDown={() => !tourMode && setShowCreate(false)}
                    >
                        <section
                            className="w-full max-w-5xl overflow-hidden rounded-xl bg-white shadow-[0_24px_80px_rgba(11,16,32,0.25)]"
                            onMouseDown={(event) => event.stopPropagation()}
                        >
                            <header data-tour="activities-create-header" className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-6 py-5">
                                <div>
                                    <div className="eyebrow">Nova atividade</div>
                                    <h2 className="mt-1 text-xl font-semibold text-[var(--ink-900)]">Criar atividade</h2>
                                    <p className="mt-1 text-[13px] text-[var(--ink-500)]">Defina o contrato, os responsáveis e quem poderá visualizar o card.</p>
                                </div>
                                <button className="sig-btn sig-btn-ghost !min-h-9 !px-2" type="button" onClick={() => !tourMode && setShowCreate(false)} title="Fechar">
                                    <X size={18} />
                                </button>
                            </header>
                            <form className="grid max-h-[calc(100vh-150px)] grid-cols-1 gap-4 overflow-y-auto p-6 md:grid-cols-2 xl:grid-cols-4" onSubmit={submit}>
                        <div data-tour="activities-create-fields" className="grid gap-4 md:col-span-2 md:grid-cols-2 xl:col-span-4 xl:grid-cols-4">
                        <Field label="Título" error={form.errors.title}>
                            <input value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required placeholder="Ex: Validar diário de obra" />
                        </Field>
                        <Field label="Contrato" error={form.errors.contract_id}>
                            <select value={form.data.contract_id} onChange={(event) => updateContract(event.target.value)} required>
                                {contracts.map((contract) => (
                                    <option key={contract.id} value={contract.id}>
                                        {contract.code} · {contract.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Categoria" error={form.errors.category}>
                            <select value={form.data.category} onChange={(event) => form.setData('category', event.target.value)} required>
                                {Object.entries(categories || {}).map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Prioridade" error={form.errors.priority}>
                            <select value={form.data.priority} onChange={(event) => form.setData('priority', event.target.value)} required>
                                {priorities.map((priority) => (
                                    <option key={priority} value={priority}>
                                        {priorityMeta[priority]?.label || priority}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Prazo" error={form.errors.due_date}>
                            <input value={form.data.due_date} onChange={(event) => form.setData('due_date', event.target.value)} type="date" />
                        </Field>
                        </div>
                        <div data-tour="activities-create-visibility" className="md:col-span-2 xl:col-span-4">
                            <VisibilitySelector
                                value={form.data.visibility}
                                onChange={(value) => form.setData('visibility', value)}
                                error={form.errors.visibility}
                                options={visibilities}
                            />
                        </div>
                        <div data-tour="activities-create-assignees" className="md:col-span-2 xl:col-span-4">
                            <div className="min-w-0">
                                <span className="eyebrow mb-1 block">Responsáveis</span>
                                <div className="rounded-lg border border-[var(--border)] bg-white p-3">
                                    <label className="sig-input">
                                        <Search size={15} />
                                        <input
                                            value={assigneeQuery}
                                            onChange={(event) => setAssigneeQuery(event.target.value)}
                                            placeholder="Buscar usuário do contrato"
                                        />
                                    </label>

                                    {selectedAssignees.length > 0 && (
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {selectedAssignees.map((user) => (
                                                <button
                                                    key={user.id}
                                                    type="button"
                                                    className="inline-flex items-center gap-2 rounded-full border border-[var(--primary)] bg-[var(--primary-50)] px-3 py-1.5 text-[12px] font-semibold text-[var(--primary)]"
                                                    onClick={() => toggleAssignee(user.id)}
                                                    title="Remover responsável"
                                                >
                                                    <span>{user.name}</span>
                                                    <X size={12} />
                                                </button>
                                            ))}
                                        </div>
                                    )}

                                    <div className="mt-3 grid max-h-56 gap-2 overflow-y-auto pr-1 sm:grid-cols-2 xl:grid-cols-3">
                                        {filteredAssigneesForSelectedContract.length > 0 ? filteredAssigneesForSelectedContract.map((user) => {
                                            const checked = form.data.assigned_to_ids.includes(user.id);

                                            return (
                                                <button
                                                    key={user.id}
                                                    type="button"
                                                    className={`flex min-w-0 items-center gap-3 rounded-lg border px-3 py-2 text-left transition ${checked ? 'border-[var(--primary)] bg-[var(--primary-50)]' : 'border-[var(--border)] bg-white hover:bg-[var(--surface-muted)]'}`}
                                                    onClick={() => toggleAssignee(user.id)}
                                                >
                                                    <UserAvatar user={user} className="!h-8 !w-8 !text-[11px]" />
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-[12.5px] font-semibold text-[var(--ink-900)]">{user.name}</span>
                                                        <span className="block truncate text-[11px] text-[var(--ink-500)]">{user.email}</span>
                                                    </span>
                                                    <span className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border ${checked ? 'border-[var(--primary)] bg-[var(--primary)] text-white' : 'border-[var(--border-strong)]'}`}>
                                                        {checked && <CheckCircle2 size={12} />}
                                                    </span>
                                                </button>
                                            );
                                        }) : (
                                            <div className="rounded-lg border border-dashed border-[var(--border-strong)] px-3 py-6 text-center text-[12.5px] text-[var(--ink-500)] sm:col-span-2 xl:col-span-3">
                                                {assigneesForSelectedContract.length === 0
                                                    ? 'Nenhum usuário vinculado a este contrato.'
                                                    : 'Nenhum usuário encontrado para essa busca.'}
                                            </div>
                                        )}
                                    </div>
                                </div>
                                {form.errors.assigned_to_ids && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.assigned_to_ids}</span>}
                            </div>
                        </div>
                        <div className="md:col-span-2 xl:col-span-4">
                            <Field label="Descrição" error={form.errors.description}>
                                <textarea
                                    value={form.data.description}
                                    onChange={(event) => form.setData('description', event.target.value)}
                                    rows={3}
                                    placeholder="Detalhes da atividade"
                                />
                            </Field>
                        </div>
                        <div className="flex flex-wrap items-center gap-2 md:col-span-2 xl:col-span-4">
                            <button data-tour="activities-create-action" className="sig-btn sig-btn-primary" disabled={form.processing}>
                                <Plus size={15} />
                                Criar atividade
                            </button>
                            <button className="sig-btn sig-btn-secondary" type="button" onClick={() => !tourMode && setShowCreate(false)}>
                                Cancelar
                            </button>
                        </div>
                            </form>
                        </section>
                    </div>
                )}

                <div data-tour="activities-board" className="mt-6 grid min-w-0 gap-4 xl:grid-cols-4 lg:grid-cols-2">
                    {statusColumns.map((column) => {
                        const Icon = column.icon;
                        const columnActivities = filteredActivities.filter((activity) => activity.status === column.value);

                        return (
                            <section
                                key={column.value}
                                data-tour={`activities-column-${column.value}`}
                                className="min-w-0 overflow-hidden rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3"
                                onDragOver={(event) => event.preventDefault()}
                                onDrop={() => moveActivity(column.value)}
                            >
                                <header className="mb-3 flex items-center justify-between gap-3 px-1">
                                    <div className="flex items-center gap-2">
                                        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-white" style={{ color: column.tone }}>
                                            <Icon size={15} />
                                        </span>
                                        <div>
                                            <h2 className="text-[13.5px] font-semibold text-[var(--ink-900)]">{column.label}</h2>
                                            <p className="text-[11.5px] text-[var(--ink-500)]">
                                                {columnActivities.length} {columnActivities.length === 1 ? 'card' : 'cards'}
                                            </p>
                                        </div>
                                    </div>
                                </header>

                                <div className="grid min-w-0 gap-3">
                                    {columnActivities.map((activity) => {
                                        const canMoveActivity = activity.can_move ?? activity.can_edit ?? canEditActivities;

                                        return (
                                            <ActivityCard
                                                key={activity.id}
                                                activity={activity}
                                                dragging={draggedActivityId === activity.id}
                                                onClick={() => setSelectedActivityId(activity.id)}
                                                canEditActivities={canMoveActivity}
                                                onDragStart={() => canMoveActivity && setDraggedActivityId(activity.id)}
                                                onDragEnd={() => setDraggedActivityId(null)}
                                                tourTarget={activity._tourData
                                                    ? (tourScreen === 'flow' ? 'activities-flow-card' : 'activities-card')
                                                    : undefined}
                                            />
                                        );
                                    })}

                                    {columnActivities.length === 0 && (
                                        <div className="rounded-lg border border-dashed border-[var(--border-strong)] bg-white px-3 py-8 text-center text-[12.5px] text-[var(--ink-500)]">
                                            Sem cards
                                        </div>
                                    )}
                                </div>
                            </section>
                        );
                    })}
                </div>
            </section>

            {selectedActivity && (
                <ActivityModal
                    activity={selectedActivity}
                    tenant={tenant}
                    assigneesByContract={assigneesByContract}
                    priorities={priorities}
                    categories={categories}
                    visibilities={visibilities}
                    canEditActivities={selectedActivity.can_edit ?? canEditActivities}
                    canDeleteActivities={selectedActivity.can_delete ?? canDeleteActivities}
                    onClose={() => !tourMode && setSelectedActivityId(null)}
                    tourMode={tourMode}
                />
            )}

            {tourMode && <ActivityTour section={tourScreen} tenantSlug={tenant.slug} />}
        </AuthenticatedLayout>
    );
}

function ActivityCard({ activity, dragging, canEditActivities, onClick, onDragStart, onDragEnd, tourTarget }) {
    const priority = priorityMeta[activity.priority] || priorityMeta.normal;
    const category = categoryMeta[activity.category || 'project'] || categoryMeta.project;
    const visibility = visibilityMeta[activity.visibility || 'public'] || visibilityMeta.public;
    const VisibilityIcon = visibility.icon;
    const due = dueInfo(activity.due_date, activity.status);
    const dueDate = shortDate(activity.due_date);
    const contractName = activity.contract?.obra?.nome || activity.contract?.name;
    const assignees = activityAssignees(activity);

    return (
        <article
            data-tour={tourTarget}
            draggable={canEditActivities}
            onClick={onClick}
            onDragStart={onDragStart}
            onDragEnd={onDragEnd}
            className={`sig-card min-w-0 max-w-full overflow-hidden p-4 transition hover:border-[var(--border-strong)] hover:shadow-[var(--shadow-md)] ${canEditActivities ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer'} ${dragging ? 'opacity-60 ring-2 ring-[var(--primary-100)]' : ''}`}
        >
            <div className="mb-3 flex min-w-0 flex-wrap items-start justify-between gap-2">
                <span className="flex flex-wrap gap-2">
                    <span className={`sig-pill ${category.className}`}>
                        <Tag size={12} />
                        {category.label}
                    </span>
                    <span className={`sig-pill ${priority.className}`}>
                        <span className="sig-pill-dot" />
                        {priority.label}
                    </span>
                    <span className={`sig-pill ${visibility.className}`}>
                        <VisibilityIcon size={12} />
                        {visibility.label}
                    </span>
                </span>
                <span className={`sig-pill min-w-0 ${due.className}`}>
                    <Clock3 size={12} />
                    <span className="truncate">{due.label}</span>
                </span>
            </div>

            <h3 className="text-[14px] font-semibold leading-5 text-[var(--ink-900)]">{activity.title}</h3>
            {activity.description && (
                <p className="mt-2 line-clamp-3 text-[12.5px] leading-5 text-[var(--ink-500)]">{activity.description}</p>
            )}

            <div className="mt-3 flex flex-wrap gap-2 text-[11.5px] text-[var(--ink-500)]">
                {dueDate && (
                    <span className="flex items-center gap-1">
                        <Calendar size={12} />
                        {dueDate}
                    </span>
                )}
                <span className="flex items-center gap-1">
                    <MessageSquare size={12} />
                    {activity.comments?.length || 0}
                </span>
                <span className="flex items-center gap-1">
                    <Paperclip size={12} />
                    {activity.files?.length || 0}
                </span>
            </div>

            <div className="mt-4 flex min-w-0 items-center justify-between gap-3 border-t border-[var(--border)] pt-3">
                <div className="min-w-0 flex-1">
                    <div className="mono truncate text-[11.5px] font-semibold text-[var(--ink-700)]">{activity.contract?.code}</div>
                    <div className="truncate text-[11.5px] text-[var(--ink-500)]">{contractName}</div>
                </div>
                {assignees.length > 0 ? (
                    <div className="flex min-w-0 shrink-0 items-center justify-end">
                        <div className="flex -space-x-2">
                            {assignees.slice(0, 3).map((user) => (
                                <UserAvatar
                                    key={user.id}
                                    user={user}
                                    className="!h-8 !w-8 border-2 border-white !text-[11px]"
                                />
                            ))}
                        </div>
                        {assignees.length > 3 && (
                            <span className="ml-1 text-[11px] font-semibold text-[var(--ink-500)]">+{assignees.length - 3}</span>
                        )}
                    </div>
                ) : (
                    <span className="flex items-center gap-1.5 text-[11.5px] text-[var(--ink-500)]">
                        <UserRound size={13} />
                        Livre
                    </span>
                )}
            </div>
        </article>
    );
}

function ActivityModal({ activity, tenant, assigneesByContract, priorities, categories, visibilities, canEditActivities, canDeleteActivities, onClose, tourMode = false }) {
    const commentForm = useForm({ body: '' });
    const fileForm = useForm({ file: null });
    const [editing, setEditing] = useState(false);
    const editForm = useForm({
        title: activity.title || '',
        description: activity.description || '',
        category: activity.category || 'project',
        visibility: activity.visibility || 'public',
        priority: activity.priority || 'normal',
        due_date: activity.due_date ? String(activity.due_date).slice(0, 10) : '',
        assigned_to_ids: activityAssignees(activity).map((user) => user.id),
    });
    const priority = priorityMeta[activity.priority] || priorityMeta.normal;
    const category = categoryMeta[activity.category || 'project'] || categoryMeta.project;
    const visibility = visibilityMeta[activity.visibility || 'public'] || visibilityMeta.public;
    const VisibilityIcon = visibility.icon;
    const due = dueInfo(activity.due_date, activity.status);
    const assignees = activityAssignees(activity);
    const contractName = activity.contract?.obra?.nome || activity.contract?.name;
    const assignableUsers = assigneesByContract?.[String(activity.contract_id)] || [];
    const canInteract = activity.can_interact ?? tourMode;

    const toggleEditAssignee = (userId) => {
        const normalizedUserId = Number(userId);

        editForm.setData('assigned_to_ids', editForm.data.assigned_to_ids.includes(normalizedUserId)
            ? editForm.data.assigned_to_ids.filter((id) => id !== normalizedUserId)
            : [...editForm.data.assigned_to_ids, normalizedUserId]);
    };

    const submitEdit = (event) => {
        event.preventDefault();

        if (tourMode) {
            return;
        }

        editForm.patch(route('tenant.activities.update', [tenant.slug, activity.id]), {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const deleteActivity = () => {
        if (tourMode) {
            return;
        }

        router.delete(route('tenant.activities.destroy', [tenant.slug, activity.id]), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const submitComment = (event) => {
        event.preventDefault();

        if (tourMode) {
            return;
        }

        commentForm.post(route('tenant.activities.comments.store', [tenant.slug, activity.id]), {
            preserveScroll: true,
            onSuccess: () => commentForm.reset(),
        });
    };

    const submitFile = (event) => {
        event.preventDefault();

        if (tourMode) {
            return;
        }

        if (!fileForm.data.file) {
            return;
        }

        fileForm.post(route('tenant.activities.files.store', [tenant.slug, activity.id]), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => fileForm.reset(),
        });
    };

    return (
        <div className="fixed inset-0 z-[90] flex items-start justify-center overflow-y-auto bg-[rgba(11,16,32,0.45)] px-4 py-6" onMouseDown={onClose}>
            <section data-tour="activities-detail-modal" className="w-full max-w-5xl rounded-xl bg-white shadow-[0_24px_80px_rgba(11,16,32,0.25)]" onMouseDown={(event) => event.stopPropagation()}>
                <header data-tour="activities-detail-header" className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-6 py-5">
                    <div className="min-w-0">
                        <div data-tour="activities-detail-status" className="mb-2 flex flex-wrap items-center gap-2">
                            <span className={`sig-pill ${category.className}`}><Tag size={12} />{category.label}</span>
                            <span className={`sig-pill ${priority.className}`}><span className="sig-pill-dot" />{priority.label}</span>
                            <span className={`sig-pill ${visibility.className}`}><VisibilityIcon size={12} />{visibility.label}</span>
                            <span className={`sig-pill ${due.className}`}><Clock3 size={12} />{due.label}</span>
                            <span className="mono text-[12px] text-[var(--ink-500)]">{activity.contract?.code}</span>
                        </div>
                        <h2 className="text-xl font-semibold text-[var(--ink-900)]">{activity.title}</h2>
                        <p className="mt-1 text-[13px] text-[var(--ink-500)]">{contractName} · prazo {fullDate(activity.due_date)}</p>
                    </div>
                    <div className="flex shrink-0 flex-wrap justify-end gap-2">
                        {canEditActivities && (
                            <button className="sig-btn sig-btn-secondary sig-btn-sm" type="button" onClick={() => setEditing((value) => !value)}>
                                <Pencil size={14} />
                                {editing ? 'Cancelar edição' : 'Editar'}
                            </button>
                        )}
                        {canDeleteActivities && (
                            <ConfirmActionButton
                                title="Excluir atividade"
                                message={`Deseja mesmo excluir a atividade "${activity.title}"? Esta acao nao deve ser feita por engano.`}
                                confirmLabel="Excluir atividade"
                                onConfirm={deleteActivity}
                            >
                                <Trash2 size={14} />
                                Excluir
                            </ConfirmActionButton>
                        )}
                        <button className="sig-btn sig-btn-ghost !min-h-9 !px-2" type="button" onClick={onClose} title="Fechar">
                            <X size={18} />
                        </button>
                    </div>
                </header>

                {editing && (
                    <form className="grid gap-4 border-b border-[var(--border)] bg-[var(--surface-muted)] p-6" onSubmit={submitEdit}>
                        <div className="grid gap-3 md:grid-cols-2">
                            <Field label="Título" error={editForm.errors.title}>
                                <input value={editForm.data.title} onChange={(event) => editForm.setData('title', event.target.value)} required />
                            </Field>
                            <Field label="Prioridade" error={editForm.errors.priority}>
                                <select value={editForm.data.priority} onChange={(event) => editForm.setData('priority', event.target.value)} required>
                                    {priorities.map((item) => (
                                        <option key={item} value={item}>{priorityMeta[item]?.label || item}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Categoria" error={editForm.errors.category}>
                                <select value={editForm.data.category} onChange={(event) => editForm.setData('category', event.target.value)} required>
                                    {Object.entries(categories || {}).map(([value, label]) => (
                                        <option key={value} value={value}>{label}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Prazo" error={editForm.errors.due_date}>
                                <input value={editForm.data.due_date} onChange={(event) => editForm.setData('due_date', event.target.value)} type="date" />
                            </Field>
                        </div>
                        <VisibilitySelector
                            value={editForm.data.visibility}
                            onChange={(value) => editForm.setData('visibility', value)}
                            error={editForm.errors.visibility}
                            options={visibilities}
                        />
                        <div>
                            <span className="eyebrow mb-1 block">Responsáveis</span>
                            <div className="grid max-h-48 gap-2 overflow-y-auto rounded-lg border border-[var(--border)] bg-white p-3 sm:grid-cols-2">
                                {assignableUsers.map((user) => {
                                    const checked = editForm.data.assigned_to_ids.includes(user.id);

                                    return (
                                        <button
                                            key={user.id}
                                            type="button"
                                            className={`flex min-w-0 items-center gap-3 rounded-lg border px-3 py-2 text-left transition ${checked ? 'border-[var(--primary)] bg-[var(--primary-50)]' : 'border-[var(--border)] bg-white hover:bg-[var(--surface-muted)]'}`}
                                            onClick={() => toggleEditAssignee(user.id)}
                                        >
                                            <UserAvatar user={user} className="!h-8 !w-8 !text-[11px]" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[12.5px] font-semibold text-[var(--ink-900)]">{user.name}</span>
                                                <span className="block truncate text-[11px] text-[var(--ink-500)]">{user.email}</span>
                                            </span>
                                            <span className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border ${checked ? 'border-[var(--primary)] bg-[var(--primary)] text-white' : 'border-[var(--border-strong)]'}`}>
                                                {checked && <CheckCircle2 size={12} />}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                            {editForm.errors.assigned_to_ids && <span className="mt-1 block text-xs text-[var(--red)]">{editForm.errors.assigned_to_ids}</span>}
                        </div>
                        <Field label="Descrição" error={editForm.errors.description}>
                            <textarea value={editForm.data.description} onChange={(event) => editForm.setData('description', event.target.value)} rows={3} />
                        </Field>
                        <div>
                            <button className="sig-btn sig-btn-primary" disabled={editForm.processing}>
                                <Save size={14} />
                                Salvar atividade
                            </button>
                        </div>
                    </form>
                )}

                <div className="grid gap-6 p-6 lg:grid-cols-[minmax(0,1.45fr)_minmax(300px,0.8fr)]">
                    <div className="grid content-start gap-5">
                        <section data-tour="activities-detail-comments">
                            <h3 className="text-[14px] font-semibold text-[var(--ink-900)]">Descrição</h3>
                            <p className="mt-2 whitespace-pre-wrap rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4 text-[13px] leading-6 text-[var(--ink-700)]">
                                {activity.description || 'Sem descrição.'}
                            </p>
                        </section>

                        <section>
                            <div className="mb-3 flex items-center justify-between gap-3">
                                <h3 className="flex items-center gap-2 text-[14px] font-semibold text-[var(--ink-900)]">
                                    <MessageSquare size={15} />
                                    Comentários
                                </h3>
                                <span className="text-[12px] text-[var(--ink-500)]">{activity.comments?.length || 0}</span>
                            </div>

                            <div className="grid gap-3">
                                {(activity.comments || []).map((comment) => (
                                    <article key={comment.id} className="rounded-lg border border-[var(--border)] p-3">
                                        <div className="mb-1 flex items-center justify-between gap-3">
                                            <span className="font-semibold text-[13px] text-[var(--ink-900)]">{comment.user?.name || 'Usuário'}</span>
                                            <span className="text-[11px] text-[var(--ink-500)]">{shortDate(comment.created_at)}</span>
                                        </div>
                                        <p className="whitespace-pre-wrap text-[13px] leading-5 text-[var(--ink-600)]">{comment.body}</p>
                                    </article>
                                ))}

                                {(!activity.comments || activity.comments.length === 0) && (
                                    <div className="rounded-lg border border-dashed border-[var(--border-strong)] px-4 py-6 text-center text-[12.5px] text-[var(--ink-500)]">
                                        Nenhum comentário ainda.
                                    </div>
                                )}
                            </div>

                            {canInteract && (
                                <form className="mt-3 grid gap-2" onSubmit={submitComment}>
                                    <label className="sig-input">
                                        <textarea
                                            value={commentForm.data.body}
                                            onChange={(event) => commentForm.setData('body', event.target.value)}
                                            placeholder="Escrever comentário"
                                            rows={3}
                                            required
                                        />
                                    </label>
                                    {commentForm.errors.body && <span className="text-xs text-[var(--red)]">{commentForm.errors.body}</span>}
                                    <div>
                                        <button className="sig-btn sig-btn-primary" disabled={commentForm.processing}>
                                            <Send size={14} />
                                            Enviar comentário
                                        </button>
                                    </div>
                                </form>
                            )}
                        </section>
                    </div>

                    <aside className="grid content-start gap-5">
                        <section data-tour="activities-detail-responsibles" className="sig-card p-4">
                            <h3 className="mb-3 flex items-center gap-2 text-[14px] font-semibold text-[var(--ink-900)]">
                                <Users size={15} />
                                Responsáveis
                            </h3>
                            <div className="grid gap-2">
                                {assignees.length > 0 ? assignees.map((user) => (
                                    <div key={user.id} className="flex items-center gap-3 rounded-lg bg-[var(--surface-muted)] px-3 py-2">
                                        <UserAvatar user={user} className="!h-8 !w-8 !text-[11px]" />
                                        <span className="min-w-0">
                                            <span className="block truncate text-[13px] font-semibold text-[var(--ink-900)]">{user.name}</span>
                                            <span className="block truncate text-[11.5px] text-[var(--ink-500)]">{user.email}</span>
                                        </span>
                                    </div>
                                )) : (
                                    <p className="text-[12.5px] text-[var(--ink-500)]">Nenhum responsável atribuído.</p>
                                )}
                            </div>
                        </section>

                        <section data-tour="activities-detail-files" className="sig-card p-4">
                            <h3 className="mb-3 flex items-center gap-2 text-[14px] font-semibold text-[var(--ink-900)]">
                                <Paperclip size={15} />
                                Arquivos
                            </h3>
                            <div className="grid gap-2">
                                {(activity.files || []).map((file) => (
                                    <a key={file.id} href={file.url} target="_blank" rel="noreferrer" className="flex items-center gap-3 rounded-lg border border-[var(--border)] px-3 py-2 hover:bg-[var(--surface-muted)]">
                                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[var(--primary)]">
                                            <Download size={14} />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-[12.5px] font-semibold text-[var(--ink-900)]">{file.name}</span>
                                            <span className="block text-[11px] text-[var(--ink-500)]">{fileSize(file.size)}</span>
                                        </span>
                                    </a>
                                ))}

                                {(!activity.files || activity.files.length === 0) && (
                                    <div className="rounded-lg border border-dashed border-[var(--border-strong)] px-3 py-6 text-center text-[12.5px] text-[var(--ink-500)]">
                                        Nenhum arquivo anexado.
                                    </div>
                                )}
                            </div>

                            {canInteract && (
                                <form className="mt-3 grid gap-2" onSubmit={submitFile}>
                                    <label className="sig-input">
                                        <input type="file" onChange={(event) => fileForm.setData('file', event.target.files?.[0] || null)} />
                                    </label>
                                    {fileForm.errors.file && <span className="text-xs text-[var(--red)]">{fileForm.errors.file}</span>}
                                    <button className="sig-btn sig-btn-secondary" disabled={fileForm.processing || !fileForm.data.file}>
                                        <Upload size={14} />
                                        Anexar arquivo
                                    </button>
                                </form>
                            )}
                        </section>
                    </aside>
                </div>
            </section>
        </div>
    );
}

function VisibilitySelector({ value, onChange, error, options }) {
    const descriptions = {
        public: 'Todos os usuários do contrato com acesso às atividades podem visualizar este card.',
        restricted: 'Somente o criador e os responsáveis vinculados à atividade podem visualizar este card.',
    };

    return (
        <div className="min-w-0">
            <div className="mb-2 flex items-center gap-1.5">
                <span className="eyebrow">Visibilidade do card</span>
                <span
                    className="inline-flex text-[var(--ink-400)]"
                    title="A visibilidade controla quem poderá encontrar e abrir esta atividade."
                    aria-label="Ajuda sobre a visibilidade da atividade"
                >
                    <CircleHelp size={14} />
                </span>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                {Object.entries(options || { public: 'Pública', restricted: 'Restrita' }).map(([option, label]) => {
                    const meta = visibilityMeta[option] || visibilityMeta.public;
                    const Icon = meta.icon;
                    const selected = value === option;

                    return (
                        <button
                            key={option}
                            type="button"
                            className={`flex min-w-0 items-start gap-3 rounded-lg border p-3 text-left transition ${
                                selected
                                    ? 'border-[var(--primary)] bg-[var(--primary-50)] ring-1 ring-[var(--primary-100)]'
                                    : 'border-[var(--border)] bg-white hover:border-[var(--border-strong)] hover:bg-[var(--surface-muted)]'
                            }`}
                            onClick={() => onChange(option)}
                            aria-pressed={selected}
                        >
                            <span className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${selected ? 'bg-[var(--primary)] text-white' : 'bg-[var(--surface-muted)] text-[var(--ink-600)]'}`}>
                                <Icon size={16} />
                            </span>
                            <span className="min-w-0">
                                <span className="block text-[13px] font-semibold text-[var(--ink-900)]">{label}</span>
                                <span className="mt-1 block text-[11.5px] leading-4 text-[var(--ink-500)]">{descriptions[option]}</span>
                            </span>
                        </button>
                    );
                })}
            </div>
            {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
        </div>
    );
}

function Field({ label, error, children }) {
    return (
        <label className="min-w-0">
            <span className="eyebrow mb-1 block">{label}</span>
            <span className="sig-input min-w-0">{children}</span>
            {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
        </label>
    );
}
