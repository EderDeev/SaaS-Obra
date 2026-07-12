import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Building2,
    Calendar,
    ClipboardCheck,
    Download,
    FileText,
    FilePlus2,
    FolderKanban,
    History,
    ImagePlus,
    Layers,
    MapPin,
    Plus,
    Save,
    Settings,
    Upload,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const activityStatusLabels = {
    todo: 'A fazer',
    in_progress: 'Em andamento',
    review: 'Em revisão',
    done: 'Concluída',
};

const projectStatusLabels = {
    em_analise: 'Em análise',
    em_aprovacao: 'Em aprovação',
    ativo: 'Aprovado',
    inativo: 'Inativo',
    reprovado: 'Reprovado',
};

const rncStatusLabels = {
    aberta: 'Aberta',
    aguardando_acao_corretiva: 'Aguardando ação corretiva',
    aguardando_analise: 'Aguardando análise',
    aguardando_evidencia: 'Aguardando evidência',
    finalizada: 'Finalizada',
};

const shortDate = (date) => {
    if (!date) return 'Não informado';

    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date));
};

const currencyLocaleMap = {
    BRL: 'pt-BR',
    USD: 'en-US',
    JPY: 'ja-JP',
    CNY: 'zh-CN',
    EUR: 'de-DE',
};

const money = (value, currency = 'BRL') => Number(value || 0).toLocaleString(currencyLocaleMap[currency] || 'pt-BR', {
    style: 'currency',
    currency,
    maximumFractionDigits: currency === 'JPY' ? 0 : 2,
});

const additiveTypeLabel = {
    cost: 'Custo',
    deadline: 'Prazo',
    cost_deadline: 'Custo e prazo',
};

function amountFromDigits(value, currency = 'BRL') {
    const digits = value.replace(/\D/g, '');

    if (!digits) return '';

    const fractionDigits = currency === 'JPY' ? 0 : 2;

    return (Number(digits) / (10 ** fractionDigits)).toFixed(fractionDigits);
}

function fileSize(bytes = 0) {
    const size = Number(bytes || 0);

    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;

    return `${(size / 1024 / 1024).toFixed(1)} MB`;
}

function dateInputValue(date) {
    if (!date) return '';

    const rawDate = String(date);

    if (/^\d{4}-\d{2}-\d{2}/.test(rawDate)) {
        return rawDate.slice(0, 10);
    }

    return new Date(date).toISOString().slice(0, 10);
}

function addCalendarDays(date, days) {
    if (!date) return '';

    const [year, month, day] = date.split('-').map(Number);
    const parsedDate = new Date(year, month - 1, day);
    parsedDate.setDate(parsedDate.getDate() + days);

    return dateInputValue(parsedDate);
}

function calendarDiffDays(startDate, endDate) {
    if (!startDate || !endDate) return null;

    const [startYear, startMonth, startDay] = startDate.split('-').map(Number);
    const [endYear, endMonth, endDay] = endDate.split('-').map(Number);
    const start = Date.UTC(startYear, startMonth - 1, startDay);
    const end = Date.UTC(endYear, endMonth - 1, endDay);

    return Math.max(0, Math.round((end - start) / 86400000));
}

function remainingDaysLabel(date) {
    if (!date) return 'Prazo não informado';

    const dateOnly = dateInputValue(date);
    const [year, month, day] = dateOnly.split('-').map(Number);
    const today = new Date();
    const todayUtc = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
    const endUtc = Date.UTC(year, month - 1, day);
    const diff = Math.round((endUtc - todayUtc) / 86400000);

    if (diff > 1) return `Faltam ${diff} dias`;
    if (diff === 1) return 'Falta 1 dia';
    if (diff === 0) return 'Encerra hoje';
    if (diff === -1) return 'Vencido há 1 dia';

    return `Vencido há ${Math.abs(diff)} dias`;
}

function remainingDaysPillClass(date) {
    if (!date) return 'sig-pill-red';

    const dateOnly = dateInputValue(date);
    const [year, month, day] = dateOnly.split('-').map(Number);
    const today = new Date();
    const todayUtc = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
    const endUtc = Date.UTC(year, month - 1, day);
    const diff = Math.round((endUtc - todayUtc) / 86400000);

    if (diff > 180) return 'sig-pill-green';
    if (diff >= 90) return 'sig-pill-amber';

    return 'sig-pill-red';
}

function formatCnpj(value) {
    const digits = value.replace(/\D/g, '').slice(0, 14);

    if (digits.length <= 2) return digits;
    if (digits.length <= 5) return `${digits.slice(0, 2)}.${digits.slice(2)}`;
    if (digits.length <= 8) return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5)}`;
    if (digits.length <= 12) return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5, 8)}/${digits.slice(8)}`;

    return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5, 8)}/${digits.slice(8, 12)}-${digits.slice(12)}`;
}

const initials = (name = '?') => name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase();

const tipoEmpresaLabel = (tipo) => tipo?.label || {
    gerenciadora: 'Gerenciadora',
    construtora: 'Construtora',
    cliente: 'Cliente',
}[tipo?.nome] || tipo?.nome || 'Sem tipo';

export default function ContractShow({
    tenant,
    contract,
    recentActivities = [],
    recentRncs = [],
    recentProjects = [],
    capabilities = {},
    parametrizacao = {},
    additives = [],
}) {
    const [showParametrizacao, setShowParametrizacao] = useState(false);
    const [showAdditive, setShowAdditive] = useState(false);
    const [showAdditiveHistory, setShowAdditiveHistory] = useState(false);
    const badge = (contract.code || contract.name || '?').replace(/[^A-Za-z0-9]/g, '').slice(0, 2).toUpperCase();
    const contractTitle = contract.obra?.nome || contract.name;
    const cliente = contract.cliente_empresa?.nome || contract.client_company_name || 'Cliente não informado';
    const construtora = contract.construtora_empresa?.nome || contract.contractor_company_name || 'Construtora não informada';
    const location = [contract.city, contract.state].filter(Boolean).join(' - ') || 'Local não informado';

    return (
        <AuthenticatedLayout>
            <Head title={contractTitle} />

            <section className="sig-content fade-in">
                <header className="flex flex-wrap items-start gap-5">
                    <div className="min-w-0 flex-1">
                        <div className="mb-2 flex flex-wrap items-center gap-2">
                            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--primary-50)] text-[15px] font-bold text-[var(--primary)]">
                                {badge}
                            </span>
                            <span className="mono text-[13px] text-[var(--ink-500)]">{contract.code}</span>
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-[26px] font-semibold leading-tight text-[var(--ink-900)]">{contractTitle}</h1>
                            <span className={`sig-pill ${remainingDaysPillClass(contract.ends_at)} text-[12.5px] font-semibold`}>
                                <Calendar size={13} />
                                {remainingDaysLabel(contract.ends_at)}
                            </span>
                        </div>
                        <div className="mt-2 flex flex-wrap gap-x-5 gap-y-2 text-[13.5px] text-[var(--ink-500)]">
                            <span className="flex items-center gap-1.5"><Users size={14} /> {cliente}</span>
                            <span className="flex items-center gap-1.5"><FolderKanban size={14} /> {construtora}</span>
                            <span className="flex items-center gap-1.5"><MapPin size={14} /> {location}</span>
                            <span className="flex items-center gap-1.5"><Calendar size={14} /> até {shortDate(contract.ends_at)}</span>
                        </div>
                    </div>
                    <QuickActions
                        tenant={tenant}
                        capabilities={capabilities}
                        onParametrize={() => setShowParametrizacao(true)}
                        onAdditive={() => setShowAdditive(true)}
                    />
                </header>

                <section className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <Metric icon={Activity} label="Atividades abertas" value={contract.open_activities_count} />
                    <Metric icon={AlertTriangle} label="Atividades atrasadas" value={contract.overdue_activities_count} attention={contract.overdue_activities_count > 0} />
                    <Metric icon={ClipboardCheck} label="RNCs abertas" value={contract.open_rncs_count} attention={contract.open_rncs_count > 0} />
                    <Metric icon={FileText} label="Projetos pendentes" value={contract.pending_projects_count} />
                    <Metric icon={FolderKanban} label="Projetos aprovados" value={contract.approved_projects_count} />
                </section>

                <section className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,1fr)]">
                    <div className="grid content-start gap-5">
                        <ModulePanel
                            title="Atividades"
                            subtitle="Acompanhamento das tarefas recentes deste contrato"
                            href={capabilities.viewActivities ? route('tenant.activities.index', tenant.slug) : null}
                        >
                            {recentActivities.map((item) => (
                                <ListRow
                                    key={item.id}
                                    title={item.title}
                                    meta={`${item.category === 'quality' ? 'Qualidade' : 'Projeto'} · ${shortDate(item.due_date)}`}
                                    status={activityStatusLabels[item.status] || item.status}
                                    attention={item.status !== 'done' && item.due_date && new Date(item.due_date) < new Date()}
                                />
                            ))}
                            <EmptyState show={recentActivities.length === 0}>Nenhuma atividade cadastrada neste contrato.</EmptyState>
                        </ModulePanel>

                        <div className="grid gap-5 lg:grid-cols-2">
                            <ModulePanel
                                title="Projetos"
                                subtitle="Documentos submetidos recentemente"
                                href={capabilities.viewProjects ? route('tenant.projects.index', tenant.slug) : null}
                            >
                                {recentProjects.map((item) => (
                                    <ListRow
                                        key={item.id}
                                        title={item.title}
                                        meta={`${item.disciplina?.sigla || 'Sem disciplina'} · ${item.latest_version?.revision || 'R00'}`}
                                        status={projectStatusLabels[item.status] || item.status}
                                    />
                                ))}
                                <EmptyState show={recentProjects.length === 0}>Nenhum projeto submetido neste contrato.</EmptyState>
                            </ModulePanel>

                            <ModulePanel
                                title="RNCs"
                                subtitle="Não conformidades mais recentes"
                                href={capabilities.viewRncs ? route('tenant.qualidade.rnc.index', tenant.slug) : null}
                            >
                                {recentRncs.map((item) => (
                                    <ListRow
                                        key={item.id}
                                        href={capabilities.viewRncs ? route('tenant.qualidade.rnc.show', [tenant.slug, item.id]) : null}
                                        title={`${String(item.sequence_number).padStart(3, '0')}-${item.sequence_year}`}
                                        meta={`${item.disciplina?.nome || 'Sem disciplina'} · ${item.gravidade}`}
                                        status={rncStatusLabels[item.status] || item.status}
                                        attention={item.status !== 'finalizada'}
                                    />
                                ))}
                                <EmptyState show={recentRncs.length === 0}>Nenhuma RNC cadastrada neste contrato.</EmptyState>
                            </ModulePanel>
                        </div>
                    </div>

                    <aside className="grid content-start gap-5">
                        <ContractDetails contract={contract} cliente={cliente} construtora={construtora} location={location} />
                        <AdditiveSummaryCard contract={contract} additives={additives} onHistory={() => setShowAdditiveHistory(true)} />
                        <TeamCard participants={contract.participants || []} />
                    </aside>
                </section>
            </section>

            {showParametrizacao && (
                <ContractParametrizacaoModal
                    tenant={tenant}
                    contract={contract}
                    parametrizacao={parametrizacao}
                    onClose={() => setShowParametrizacao(false)}
                />
            )}

            {showAdditive && (
                <ContractAdditiveModal
                    tenant={tenant}
                    contract={contract}
                    onClose={() => setShowAdditive(false)}
                    onHistory={() => {
                        setShowAdditive(false);
                        setShowAdditiveHistory(true);
                    }}
                />
            )}

            {showAdditiveHistory && (
                <ContractAdditiveHistoryModal
                    tenant={tenant}
                    contract={contract}
                    additives={additives}
                    onClose={() => setShowAdditiveHistory(false)}
                />
            )}
        </AuthenticatedLayout>
    );
}

function Metric({ icon: Icon, label, value, attention = false }) {
    return (
        <div className="sig-card p-4">
            <div className="flex items-center gap-2 text-[var(--ink-500)]"><Icon size={14} /><span className="eyebrow">{label}</span></div>
            <strong className={`mono mt-3 block text-3xl ${attention ? 'text-[var(--red)]' : 'text-[var(--ink-900)]'}`}>{Number(value || 0)}</strong>
        </div>
    );
}

function QuickActions({ tenant, capabilities, onParametrize, onAdditive }) {
    const actions = [
        capabilities.manageContracts && { label: 'Parametrizar', icon: Settings, onClick: onParametrize },
        capabilities.manageContracts && { label: 'Aditivo', icon: FilePlus2, onClick: onAdditive },
        capabilities.createActivity && { label: 'Nova atividade', icon: Plus, href: route('tenant.activities.index', tenant.slug) },
        capabilities.uploadProject && { label: 'Submeter projeto', icon: Upload, href: route('tenant.projects.index', tenant.slug) },
        capabilities.createRnc && { label: 'Nova RNC', icon: ClipboardCheck, href: route('tenant.qualidade.rnc.create', tenant.slug) },
    ].filter(Boolean);

    if (actions.length === 0) return null;

    return (
        <div className="flex flex-wrap gap-2">
            {actions.map(({ label, icon: Icon, href, onClick }) => (
                href ? (
                    <Link key={label} className="sig-btn sig-btn-secondary" href={href}>
                        <Icon size={14} />
                        {label}
                    </Link>
                ) : (
                    <button key={label} className="sig-btn sig-btn-primary" type="button" onClick={onClick}>
                        <Icon size={14} />
                        {label}
                    </button>
                )
            ))}
        </div>
    );
}

function AdditiveSummaryCard({ contract, additives, onHistory }) {
    const latest = contract.latest_additive || additives?.[0];
    const count = Number(contract.contract_additives_count || additives?.length || 0);

    return (
        <section className="sig-card p-5">
            <header className="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Aditivos</h2>
                    <p className="mt-0.5 text-xs text-[var(--ink-500)]">{count} registro(s) neste contrato</p>
                </div>
                <button className="sig-btn sig-btn-secondary sig-btn-sm" type="button" onClick={onHistory}>
                    <History size={13} />
                    Histórico
                </button>
            </header>
            {latest ? (
                <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
                    <span className="sig-pill sig-pill-amber">Aditivo {latest.sequence_number} · {additiveTypeLabel[latest.type] || 'Registrado'}</span>
                    <strong className="mt-2 block text-sm text-[var(--ink-900)]">{latest.title}</strong>
                    <div className="mt-2 grid gap-1 text-xs text-[var(--ink-500)]">
                        {latest.amount && <span>Custo: {money(latest.amount, contract.currency)}</span>}
                        {latest.deadline_days && <span>Prazo: {latest.deadline_days} dia(s)</span>}
                        {latest.new_ends_at && <span>Nova vigência final: {shortDate(latest.new_ends_at)}</span>}
                    </div>
                </div>
            ) : (
                <p className="text-sm text-[var(--ink-500)]">Nenhum aditivo cadastrado.</p>
            )}
        </section>
    );
}

export function ContractAdditiveModal({ tenant, contract, onClose, onHistory }) {
    const fileInputRef = useRef(null);
    const [amountDisplay, setAmountDisplay] = useState('');
    const [selectedTypes, setSelectedTypes] = useState([]);
    const [isSaving, setIsSaving] = useState(false);
    const form = useForm({
        type: '',
        title: '',
        motivation: '',
        amount: '',
        deadline_days: '',
        new_ends_at: '',
        attachment: null,
    });
    const isCost = selectedTypes.includes('cost');
    const isDeadline = selectedTypes.includes('deadline');
    const selectedType = isCost && isDeadline ? 'cost_deadline' : isCost ? 'cost' : isDeadline ? 'deadline' : '';
    const currentEndsAt = dateInputValue(contract.ends_at);
    const minNewEndsAt = addCalendarDays(currentEndsAt, 1);
    const deadlineDaysPreview = calendarDiffDays(currentEndsAt, form.data.new_ends_at);
    const storeUrl = route('tenant.contracts.additives.store', [tenant.slug, contract.id]);

    const validateBeforeSubmit = () => {
        const errors = {};

        if (!selectedType) {
            errors.type = 'Selecione Custo, Prazo ou ambos.';
        }

        if (!form.data.title?.trim()) {
            errors.title = 'Informe o titulo do aditivo.';
        }

        if (isCost && !form.data.amount) {
            errors.amount = 'Informe o valor do aditivo de custo.';
        }

        if (isDeadline && !form.data.new_ends_at) {
            errors.new_ends_at = 'Informe a nova data final do prazo.';
        }

        if (!form.data.motivation?.trim()) {
            errors.motivation = 'Informe a motivacao do aditivo.';
        }

        if (!form.data.attachment) {
            errors.attachment = 'Envie o documento do aditivo.';
        }

        if (Object.keys(errors).length > 0) {
            form.setError(errors);
            return false;
        }

        form.clearErrors();
        return true;
    };

    const updateAmount = (value) => {
        const amount = amountFromDigits(value, contract.currency);

        form.setData('amount', amount);
        setAmountDisplay(amount ? money(amount, contract.currency) : '');
    };

    const toggleType = (type) => {
        const nextTypes = selectedTypes.includes(type)
            ? selectedTypes.filter((selectedType) => selectedType !== type)
            : [...selectedTypes, type];

        setSelectedTypes(nextTypes);
        form.setData({
            ...form.data,
            type: nextTypes.includes('cost') && nextTypes.includes('deadline')
                ? 'cost_deadline'
                : nextTypes.includes('cost')
                    ? 'cost'
                    : nextTypes.includes('deadline')
                        ? 'deadline'
                        : '',
            amount: nextTypes.includes('cost') ? form.data.amount : '',
            deadline_days: '',
            new_ends_at: nextTypes.includes('deadline') ? form.data.new_ends_at : '',
        });

        if (!nextTypes.includes('cost')) {
            setAmountDisplay('');
        }
    };

    const submit = (event = null) => {
        event?.preventDefault();

        if (isSaving || !validateBeforeSubmit()) {
            return;
        }

        router.post(storeUrl, {
            type: selectedType,
            title: form.data.title,
            motivation: form.data.motivation,
            amount: isCost ? form.data.amount : '',
            deadline_days: '',
            new_ends_at: isDeadline ? form.data.new_ends_at : '',
            attachment: form.data.attachment,
        }, {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => setIsSaving(true),
            onError: (errors) => form.setError(errors),
            onSuccess: () => {
                form.reset();
                setAmountDisplay('');
                setSelectedTypes([]);

                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }

                onClose();
            },
            onFinish: () => setIsSaving(false),
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4">
            <form id={`contract-additive-form-${contract.id}`} className="sig-card flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden" action={storeUrl} method="post" encType="multipart/form-data" onSubmit={submit} noValidate>
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-6 py-5">
                    <div>
                        <span className="eyebrow">Contrato {contract.code}</span>
                        <h2 className="mt-1 text-xl font-semibold text-[var(--ink-900)]">Novo aditivo</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">Registre custo, prazo ou ambos com documento de suporte.</p>
                    </div>
                    <button className="sig-btn sig-btn-ghost" type="button" onClick={onClose} aria-label="Fechar">
                        <X size={18} />
                    </button>
                </header>

                <div className="grid gap-5 overflow-y-auto px-6 py-5">
                    <div>
                        <span className="eyebrow mb-2 block">Tipo de aditivo</span>
                        <div className="flex flex-wrap gap-2">
                            {[
                                ['cost', 'Custo'],
                                ['deadline', 'Prazo'],
                            ].map(([value, label]) => (
                                <button
                                    key={value}
                                    className={`sig-btn ${selectedTypes.includes(value) ? 'sig-btn-primary' : 'sig-btn-secondary'}`}
                                    type="button"
                                    onClick={() => toggleType(value)}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        {form.errors.type && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.type}</span>}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <InputField label="Título" value={form.data.title} onChange={(value) => form.setData('title', value)} error={form.errors.title} required />
                        {isCost && (
                            <InputField
                                label="Valor do aditivo"
                                value={amountDisplay}
                                onChange={updateAmount}
                                error={form.errors.amount}
                                placeholder={money(0, contract.currency)}
                                required
                            />
                        )}
                        {isDeadline && (
                            <>
                                <InputField
                                    label="Nova data final"
                                    type="date"
                                    value={form.data.new_ends_at}
                                    onChange={(value) => form.setData('new_ends_at', value)}
                                    error={form.errors.new_ends_at}
                                    min={minNewEndsAt}
                                    required
                                />
                            </>
                        )}
                    </div>
                    {isDeadline && currentEndsAt && (
                        <p className="text-xs text-[var(--ink-500)]">
                            Vigência atual termina em {shortDate(currentEndsAt)}. {deadlineDaysPreview > 0 ? `Aditivo de ${deadlineDaysPreview} dia(s).` : 'Escolha uma data posterior.'}
                        </p>
                    )}

                    <TextAreaField label="Motivação" value={form.data.motivation} onChange={(value) => form.setData('motivation', value)} error={form.errors.motivation} required />

                    <div>
                        <span className="eyebrow mb-1 block">Documento do aditivo</span>
                        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
                            <label className="sig-btn sig-btn-secondary sig-btn-sm w-fit">
                                <Upload size={14} />
                                Selecionar documento
                                <input
                                    ref={fileInputRef}
                                    className="sr-only"
                                    type="file"
                                    accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip"
                                    onChange={(event) => form.setData('attachment', event.target.files?.[0] || null)}
                                />
                            </label>
                            {form.data.attachment && (
                                <div className="mt-3 flex items-center gap-2 rounded-md bg-white px-3 py-2 text-sm text-[var(--ink-700)]">
                                    <FileText size={14} />
                                    <span className="min-w-0 flex-1 truncate">{form.data.attachment.name}</span>
                                    <span className="text-xs text-[var(--ink-500)]">{fileSize(form.data.attachment.size)}</span>
                                </div>
                            )}
                        </div>
                        {form.errors.attachment && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.attachment}</span>}
                    </div>
                </div>

                <footer className="flex flex-wrap justify-between gap-2 border-t border-[var(--border)] bg-[var(--surface-muted)] px-6 py-4">
                    <button type="button" className="sig-btn sig-btn-secondary" onClick={onHistory}>
                        <History size={15} />
                        Histórico de aditivos
                    </button>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" className="sig-btn sig-btn-secondary" onClick={onClose}>
                            <X size={15} />
                            Cancelar
                        </button>
                        <button type="button" className="sig-btn sig-btn-primary" disabled={isSaving} form={`contract-additive-form-${contract.id}`} onClick={submit}>
                            <Save size={15} />
                            {isSaving ? 'Salvando...' : 'Salvar aditivo'}
                        </button>
                    </div>
                </footer>
            </form>
        </div>
    );
}

export function ContractAdditiveHistoryModal({ tenant, contract, additives, onClose }) {
    const baseDocumentName = contract.base_document_original_name;
    const additivesInSequence = [...additives].sort((first, second) => Number(first.sequence_number || 0) - Number(second.sequence_number || 0));
    const firstCostAdditive = additivesInSequence.find((additive) => additive.previous_total_value !== null && additive.previous_total_value !== undefined);
    const firstDeadlineAdditive = additivesInSequence.find((additive) => additive.previous_ends_at);
    const baseTotalValue = firstCostAdditive?.previous_total_value ?? contract.total_value;
    const baseEndsAt = firstDeadlineAdditive?.previous_ends_at ?? contract.ends_at;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4">
            <div className="sig-card flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden">
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-6 py-5">
                    <div>
                        <span className="eyebrow">Contrato {contract.code}</span>
                        <h2 className="mt-1 text-xl font-semibold text-[var(--ink-900)]">Histórico de aditivos</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">{additives.length} registro(s) cadastrados.</p>
                    </div>
                    <button className="sig-btn sig-btn-ghost" type="button" onClick={onClose} aria-label="Fechar">
                        <X size={18} />
                    </button>
                </header>

                <div className="overflow-y-auto px-6 py-5">
                    <div className="grid gap-3">
                        <article className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0 flex-1">
                                    <div className="mb-2 flex flex-wrap gap-2">
                                        <span className="sig-pill sig-pill-blue">Contrato base</span>
                                        <span className="sig-pill sig-pill-green">Inicial</span>
                                    </div>
                                    <h3 className="truncate text-sm font-semibold text-[var(--ink-900)]">{contract.name}</h3>
                                    <p className="mt-1 text-xs text-[var(--ink-500)]">
                                        Criado em {shortDate(contract.created_at)}
                                    </p>
                                </div>
                                {contract.base_document_path ? (
                                    <a className="sig-btn sig-btn-secondary sig-btn-sm" href={route('tenant.contracts.base-document.download', [tenant.slug, contract.id])}>
                                        <Download size={13} />
                                        Documento
                                    </a>
                                ) : (
                                    <span className="sig-pill">Sem documento</span>
                                )}
                            </div>
                            <div className="mt-3 grid gap-2 text-xs text-[var(--ink-600)] sm:grid-cols-3">
                                <MetaLine label="Valor inicial" value={money(baseTotalValue, contract.currency)} />
                                <MetaLine label="Vigência inicial" value={shortDate(contract.starts_at)} />
                                <MetaLine label="Vigência final inicial" value={shortDate(baseEndsAt)} />
                            </div>
                            {baseDocumentName && (
                                <p className="mt-2 truncate text-xs text-[var(--ink-400)]">
                                    {baseDocumentName} · {fileSize(contract.base_document_size)}
                                </p>
                            )}
                        </article>
                        {additives.map((additive) => (
                            <article key={additive.id} className="rounded-lg border border-[var(--border)] bg-white p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0 flex-1">
                                        <div className="mb-2 flex flex-wrap gap-2">
                                            <span className="sig-pill sig-pill-amber">Aditivo {additive.sequence_number}</span>
                                            <span className="sig-pill sig-pill-blue">{additiveTypeLabel[additive.type] || additive.type}</span>
                                        </div>
                                        <h3 className="truncate text-sm font-semibold text-[var(--ink-900)]">{additive.title}</h3>
                                        <p className="mt-1 text-xs text-[var(--ink-500)]">
                                            {additive.user?.name || 'Usuário'} · {shortDate(additive.created_at)}
                                        </p>
                                    </div>
                                    <a className="sig-btn sig-btn-secondary sig-btn-sm" href={route('tenant.contracts.additives.download', [tenant.slug, contract.id, additive.id])}>
                                        <Download size={13} />
                                        Anexo
                                    </a>
                                </div>
                                <div className="mt-3 grid gap-2 text-xs text-[var(--ink-600)] sm:grid-cols-3">
                                    <MetaLine label="Custo" value={additive.amount ? money(additive.amount, contract.currency) : '-'} />
                                    <MetaLine label="Prazo" value={additive.deadline_days ? `${additive.deadline_days} dia(s)` : '-'} />
                                    <MetaLine label="Nova data final" value={additive.new_ends_at ? shortDate(additive.new_ends_at) : '-'} />
                                </div>
                                <p className="mt-3 text-sm text-[var(--ink-600)]">{additive.motivation}</p>
                                <p className="mt-2 truncate text-xs text-[var(--ink-400)]">
                                    {additive.attachment_original_name} · {fileSize(additive.attachment_size)}
                                </p>
                            </article>
                        ))}
                        {additives.length === 0 && (
                            <p className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-6 text-center text-sm text-[var(--ink-500)]">
                                Nenhum aditivo cadastrado para este contrato.
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

function MetaLine({ label, value }) {
    return (
        <span>
            <span className="eyebrow block">{label}</span>
            <span className="font-medium text-[var(--ink-800)]">{value}</span>
        </span>
    );
}

export function ContractParametrizacaoModal({ tenant, contract, parametrizacao, onClose }) {
    const [tab, setTab] = useState('vinculos');
    const tabs = [
        { id: 'empresas', label: 'Empresas', icon: Building2 },
        { id: 'obras', label: 'Obras', icon: MapPin },
        { id: 'disciplinas', label: 'Disciplinas', icon: Layers },
        { id: 'vinculos', label: 'Vínculos', icon: Settings },
    ];

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4">
            <div className="sig-card flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden">
                <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-6 py-5">
                    <div>
                        <span className="eyebrow">Parametrização do contrato</span>
                        <h2 className="mt-1 text-xl font-semibold text-[var(--ink-900)]">{contract.code}</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">Crie e vincule empresas, obras e disciplinas deste contrato.</p>
                    </div>
                    <button className="sig-btn sig-btn-ghost" type="button" onClick={onClose} aria-label="Fechar">
                        <X size={18} />
                    </button>
                </header>

                <nav className="flex flex-wrap gap-2 border-b border-[var(--border)] bg-[var(--surface-muted)] px-6 py-3">
                    {tabs.map(({ id, label, icon: Icon }) => (
                        <button
                            key={id}
                            className={`sig-btn ${tab === id ? 'sig-btn-primary' : 'sig-btn-secondary'}`}
                            type="button"
                            onClick={() => setTab(id)}
                        >
                            <Icon size={14} />
                            {label}
                        </button>
                    ))}
                </nav>

                <div className="overflow-y-auto px-6 py-5">
                    {tab === 'vinculos' && <ContractLinksTab tenant={tenant} contract={contract} parametrizacao={parametrizacao} />}
                    {tab === 'empresas' && <EmpresaQuickTab tenant={tenant} contract={contract} parametrizacao={parametrizacao} />}
                    {tab === 'obras' && <ObraQuickTab tenant={tenant} contract={contract} parametrizacao={parametrizacao} />}
                    {tab === 'disciplinas' && <DisciplinaQuickTab tenant={tenant} contract={contract} parametrizacao={parametrizacao} />}
                </div>
            </div>
        </div>
    );
}

function ContractLinksTab({ tenant, contract, parametrizacao }) {
    const empresas = parametrizacao.empresas || [];
    const obras = parametrizacao.obras || [];
    const empresasByTipo = useMemo(() => {
        const normalized = (nome = '') => nome.toLowerCase();

        return {
            cliente: empresas.filter((empresa) => normalized(empresa.tipo_empresa?.nome).includes('cliente')),
            construtora: empresas.filter((empresa) => normalized(empresa.tipo_empresa?.nome).includes('construtora')),
            gerenciadora: empresas.filter((empresa) => normalized(empresa.tipo_empresa?.nome).includes('gerenciadora')),
        };
    }, [empresas]);
    const form = useForm({
        obra_id: contract.obra_id ? String(contract.obra_id) : '',
        cliente_empresa_id: contract.cliente_empresa_id ? String(contract.cliente_empresa_id) : '',
        construtora_empresa_id: contract.construtora_empresa_id ? String(contract.construtora_empresa_id) : '',
        gerenciadora_empresa_id: contract.fiscalizadora_empresa_id ? String(contract.fiscalizadora_empresa_id) : '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.patch(route('tenant.contracts.parametrizacao.update', [tenant.slug, contract.id]), {
            preserveScroll: true,
        });
    };

    return (
        <form className="grid gap-5 lg:grid-cols-2" onSubmit={submit}>
            <SelectField label="Obra principal" value={form.data.obra_id} onChange={(value) => form.setData('obra_id', value)} error={form.errors.obra_id}>
                <option value="">Sem obra principal</option>
                {obras.map((obra) => <option key={obra.id} value={obra.id}>{obra.codigo} - {obra.nome}</option>)}
            </SelectField>
            <SelectField label="Cliente" value={form.data.cliente_empresa_id} onChange={(value) => form.setData('cliente_empresa_id', value)} error={form.errors.cliente_empresa_id}>
                <option value="">Sem cliente</option>
                {empresasByTipo.cliente.map((empresa) => <option key={empresa.id} value={empresa.id}>{empresa.sigla} - {empresa.nome}</option>)}
            </SelectField>
            <SelectField label="Construtora" value={form.data.construtora_empresa_id} onChange={(value) => form.setData('construtora_empresa_id', value)} error={form.errors.construtora_empresa_id}>
                <option value="">Sem construtora</option>
                {empresasByTipo.construtora.map((empresa) => <option key={empresa.id} value={empresa.id}>{empresa.sigla} - {empresa.nome}</option>)}
            </SelectField>
            <SelectField label="Gerenciadora" value={form.data.gerenciadora_empresa_id} onChange={(value) => form.setData('gerenciadora_empresa_id', value)} error={form.errors.gerenciadora_empresa_id}>
                <option value="">Sem gerenciadora</option>
                {empresasByTipo.gerenciadora.map((empresa) => <option key={empresa.id} value={empresa.id}>{empresa.sigla} - {empresa.nome}</option>)}
            </SelectField>
            <div className="lg:col-span-2">
                <button className="sig-btn sig-btn-primary" disabled={form.processing}>
                    <Save size={14} />
                    Salvar vínculos
                </button>
            </div>
        </form>
    );
}

function EmpresaQuickTab({ tenant, contract, parametrizacao }) {
    const logoInputRef = useRef(null);
    const logoPreviewRef = useRef(null);
    const [logoPreview, setLogoPreview] = useState(null);
    const [logoPreviewOrigin, setLogoPreviewOrigin] = useState(null);
    const form = useForm({
        contract_id: String(contract.id),
        nome: '',
        sigla: '',
        cnpj: '',
        tipo_empresa_id: parametrizacao.tiposEmpresa?.[0]?.id ? String(parametrizacao.tiposEmpresa[0].id) : '',
        logo: null,
    });

    useEffect(() => () => {
        if (logoPreviewRef.current) {
            URL.revokeObjectURL(logoPreviewRef.current);
        }
    }, []);

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

    const selectLogo = (event) => {
        const file = event.target.files?.[0] || null;

        if (logoPreviewRef.current) {
            URL.revokeObjectURL(logoPreviewRef.current);
            logoPreviewRef.current = null;
        }

        if (!file) {
            clearLogo();

            return;
        }

        const previewUrl = URL.createObjectURL(file);
        logoPreviewRef.current = previewUrl;
        setLogoPreview(previewUrl);
        setLogoPreviewOrigin('selected');
        form.setData('logo', file);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tenant.parametrizacao.empresas.store', tenant.slug), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset('nome', 'sigla', 'cnpj', 'logo');
                clearLogo();
            },
        });
    };

    return (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,0.95fr)_minmax(280px,1fr)]">
            <form className="grid gap-4" onSubmit={submit}>
                <InputField label="Nome" value={form.data.nome} onChange={(value) => form.setData('nome', value)} error={form.errors.nome} required />
                <InputField label="Sigla" value={form.data.sigla} onChange={(value) => form.setData('sigla', value.toUpperCase())} error={form.errors.sigla} required />
                <InputField label="CNPJ" value={form.data.cnpj} onChange={(value) => form.setData('cnpj', formatCnpj(value))} error={form.errors.cnpj} placeholder="00.000.000/0000-00" required />
                <SelectField label="Tipo" value={form.data.tipo_empresa_id} onChange={(value) => form.setData('tipo_empresa_id', value)} error={form.errors.tipo_empresa_id}>
                    {parametrizacao.tiposEmpresa?.map((tipo) => <option key={tipo.id} value={tipo.id}>{tipoEmpresaLabel(tipo)}</option>)}
                </SelectField>
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
                                <p className="mt-2 text-[12px] text-[var(--ink-500)]">Opcional. Use PNG, JPG ou WebP com ate 4 MB.</p>
                            </div>
                        </div>
                    </div>
                    {form.errors.logo && <span className="mt-1 block text-xs text-[var(--red)]">{form.errors.logo}</span>}
                </div>
                <button className="sig-btn sig-btn-primary" disabled={form.processing}>
                    <Plus size={14} />
                    Criar empresa
                </button>
            </form>
            <MiniList title="Empresas vinculadas" items={(parametrizacao.empresas || []).map((empresa) => ({
                id: empresa.id,
                title: empresa.nome,
                meta: `${empresa.sigla || 'Sem sigla'} · ${tipoEmpresaLabel(empresa.tipo_empresa)} · ${empresa.cnpj || 'Sem CNPJ'}`,
                imageUrl: empresa.logo_url,
            }))} />
        </div>
    );
}

function ObraQuickTab({ tenant, contract, parametrizacao }) {
    const obrasPai = (parametrizacao.obras || []).filter((obra) => obra.tipo === 'pai');
    const form = useForm({
        contract_id: String(contract.id),
        nome: '',
        codigo: '',
        tipo: 'pai',
        obra_pai_id: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tenant.parametrizacao.obras.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => form.reset('nome', 'codigo', 'obra_pai_id'),
        });
    };

    return (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,0.95fr)_minmax(280px,1fr)]">
            <form className="grid gap-4" onSubmit={submit}>
                <InputField label="Código" value={form.data.codigo} onChange={(value) => form.setData('codigo', value.toUpperCase())} error={form.errors.codigo} required />
                <InputField label="Nome" value={form.data.nome} onChange={(value) => form.setData('nome', value)} error={form.errors.nome} required />
                <SelectField label="Tipo" value={form.data.tipo} onChange={(value) => form.setData((data) => ({ ...data, tipo: value, obra_pai_id: value === 'pai' ? '' : data.obra_pai_id }))} error={form.errors.tipo}>
                    <option value="pai">Obra / Frente principal</option>
                    <option value="filha">Subfrente</option>
                </SelectField>
                {form.data.tipo === 'filha' && (
                    <SelectField label="Obra pai" value={form.data.obra_pai_id} onChange={(value) => form.setData('obra_pai_id', value)} error={form.errors.obra_pai_id}>
                        <option value="">Selecione</option>
                        {obrasPai.map((obra) => <option key={obra.id} value={obra.id}>{obra.codigo} - {obra.nome}</option>)}
                    </SelectField>
                )}
                <button className="sig-btn sig-btn-primary" disabled={form.processing}>
                    <Plus size={14} />
                    Criar obra
                </button>
            </form>
            <MiniList title="Obras vinculadas" items={(parametrizacao.obras || []).map((obra) => ({
                id: obra.id,
                title: `${obra.codigo} - ${obra.nome}`,
                meta: obra.tipo === 'filha' ? 'Subfrente' : 'Frente principal',
            }))} />
        </div>
    );
}

function DisciplinaQuickTab({ tenant, contract, parametrizacao }) {
    const form = useForm({
        contract_id: String(contract.id),
        nome: '',
        sigla: '',
        descricao: '',
        cor: '#2563eb',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tenant.parametrizacao.disciplinas.store', tenant.slug), {
            preserveScroll: true,
            onSuccess: () => form.reset('nome', 'sigla', 'descricao'),
        });
    };

    return (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,0.95fr)_minmax(280px,1fr)]">
            <form className="grid gap-4" onSubmit={submit}>
                <InputField label="Nome" value={form.data.nome} onChange={(value) => form.setData('nome', value)} error={form.errors.nome} required />
                <InputField label="Sigla" value={form.data.sigla} onChange={(value) => form.setData('sigla', value.toUpperCase())} error={form.errors.sigla} required />
                <InputField label="Cor" type="color" value={form.data.cor} onChange={(value) => form.setData('cor', value)} error={form.errors.cor} required />
                <TextAreaField label="Descrição" value={form.data.descricao} onChange={(value) => form.setData('descricao', value)} error={form.errors.descricao} />
                <button className="sig-btn sig-btn-primary" disabled={form.processing}>
                    <Plus size={14} />
                    Criar disciplina
                </button>
            </form>
            <MiniList title="Disciplinas vinculadas" items={(parametrizacao.disciplinas || []).map((disciplina) => ({
                id: disciplina.id,
                title: disciplina.nome,
                meta: disciplina.sigla,
                color: disciplina.cor,
            }))} />
        </div>
    );
}

function InputField({ label, value, onChange, error, type = 'text', required = false, placeholder = '', min = undefined }) {
    return (
        <label>
            <span className="eyebrow mb-1 block">{label}</span>
            <span className="sig-input">
                <input type={type} value={value} onChange={(event) => onChange(event.target.value)} required={required} placeholder={placeholder} min={min} />
            </span>
            {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
        </label>
    );
}

function TextAreaField({ label, value, onChange, error, required = false }) {
    return (
        <label>
            <span className="eyebrow mb-1 block">{label}</span>
            <span className="sig-input">
                <textarea value={value} onChange={(event) => onChange(event.target.value)} rows={4} required={required} />
            </span>
            {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
        </label>
    );
}

function SelectField({ label, value, onChange, error, children }) {
    return (
        <label>
            <span className="eyebrow mb-1 block">{label}</span>
            <span className="sig-input">
                <select value={value} onChange={(event) => onChange(event.target.value)}>
                    {children}
                </select>
            </span>
            {error && <span className="mt-1 block text-xs text-[var(--red)]">{error}</span>}
        </label>
    );
}

function MiniList({ title, items }) {
    return (
        <section className="rounded-xl border border-[var(--border)] bg-white">
            <header className="border-b border-[var(--border)] px-4 py-3">
                <h3 className="text-sm font-semibold text-[var(--ink-900)]">{title}</h3>
                <p className="text-xs text-[var(--ink-500)]">{items.length} registro(s)</p>
            </header>
            <div className="max-h-[420px] divide-y divide-[var(--border)] overflow-y-auto">
                {items.map((item) => (
                    <div key={item.id} className="flex items-center gap-3 px-4 py-3">
                        {item.imageUrl ? (
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-[var(--border)] bg-white">
                                <img src={item.imageUrl} alt={item.title} className="h-full w-full object-contain" />
                            </span>
                        ) : item.color && <span className="h-3 w-3 rounded-full" style={{ backgroundColor: item.color }} />}
                        <span className="min-w-0 flex-1">
                            <strong className="block truncate text-sm text-[var(--ink-900)]">{item.title}</strong>
                            <span className="block truncate text-xs text-[var(--ink-500)]">{item.meta}</span>
                        </span>
                    </div>
                ))}
                {items.length === 0 && <p className="px-4 py-6 text-sm text-[var(--ink-500)]">Nenhum registro vinculado a este contrato.</p>}
            </div>
        </section>
    );
}

function ModulePanel({ title, subtitle, href, children }) {
    return (
        <section className="sig-card overflow-hidden">
            <header className="flex items-start justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                <div>
                    <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">{title}</h2>
                    <p className="mt-0.5 text-xs text-[var(--ink-500)]">{subtitle}</p>
                </div>
                {href && <Link className="sig-btn sig-btn-ghost sig-btn-sm" href={href}>Ver módulo</Link>}
            </header>
            <div className="divide-y divide-[var(--border)]">{children}</div>
        </section>
    );
}

function ListRow({ title, meta, status, href, attention = false }) {
    const content = (
        <>
            <span className="min-w-0 flex-1">
                <strong className="block truncate text-[13px] text-[var(--ink-900)]">{title}</strong>
                <span className="mt-0.5 block truncate text-xs text-[var(--ink-500)]">{meta}</span>
            </span>
            <span className={`sig-pill ${attention ? 'sig-pill-amber' : 'sig-pill-blue'}`}>{status}</span>
        </>
    );

    return href ? (
        <Link className="flex items-center gap-3 px-5 py-3 hover:bg-[var(--surface-muted)]" href={href}>{content}</Link>
    ) : (
        <div className="flex items-center gap-3 px-5 py-3">{content}</div>
    );
}

function EmptyState({ show, children }) {
    return show ? <p className="px-5 py-4 text-sm text-[var(--ink-500)]">{children}</p> : null;
}

function ContractDetails({ contract, cliente, construtora, location }) {
    return (
        <section className="sig-card p-5">
            <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Dados do contrato</h2>
            <dl className="mt-4 grid gap-3 text-[13px]">
                <InfoRow label="Código" value={contract.code} />
                <InfoRow label="Cliente" value={cliente} />
                <InfoRow label="Construtora" value={construtora} />
                <InfoRow label="Local" value={location} />
                <InfoRow label="Vigência" value={`${shortDate(contract.starts_at)} até ${shortDate(contract.ends_at)} · ${remainingDaysLabel(contract.ends_at)}`} />
                <InfoRow label="Valor" value={money(contract.total_value, contract.currency)} />
            </dl>
        </section>
    );
}

function InfoRow({ label, value }) {
    return (
        <div className="grid grid-cols-[92px_minmax(0,1fr)] gap-3">
            <dt className="text-[var(--ink-500)]">{label}</dt>
            <dd className="text-right font-medium text-[var(--ink-900)]">{value}</dd>
        </div>
    );
}

function TeamCard({ participants }) {
    return (
        <section className="sig-card p-5">
            <header className="mb-4 flex items-center justify-between">
                <h2 className="text-[15px] font-semibold text-[var(--ink-900)]">Equipe no contrato</h2>
                <span className="text-xs text-[var(--ink-400)]">{participants.length}</span>
            </header>
            <ul className="grid gap-3">
                {participants.map((participant) => (
                    <li key={participant.id} className="flex items-center gap-3">
                        <span className="sig-avatar">{initials(participant.user.name)}</span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-[13px] font-semibold text-[var(--ink-900)]">{participant.user.name}</span>
                            <span className="block truncate text-[11.5px] text-[var(--ink-500)]">{participant.side} · {participant.role}</span>
                        </span>
                    </li>
                ))}
            </ul>
            {participants.length === 0 && <p className="text-sm text-[var(--ink-500)]">Nenhum participante vinculado.</p>}
        </section>
    );
}
