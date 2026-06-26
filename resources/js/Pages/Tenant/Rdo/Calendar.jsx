import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import FullCalendar from '@fullcalendar/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ClipboardPenLine, Copy, Eye, Plus, Settings, X } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const statusTone = {
    rascunho: 'bg-amber-50 text-amber-700',
    em_aprovacao: 'bg-blue-50 text-blue-700',
    devolvido_construtora: 'bg-red-50 text-red-700',
    pendente_comprovacao: 'bg-amber-50 text-amber-700',
    arquivado: 'bg-emerald-50 text-emerald-700',
    aguardando_assinatura: 'bg-blue-50 text-blue-700',
    pronto_assinatura: 'bg-violet-50 text-violet-700',
    assinado: 'bg-emerald-50 text-emerald-700',
};

function calendarStatusTone(rdo) {
    if (rdo.status === 'arquivado') {
        if (rdo.signature_status === 'completed') return statusTone.assinado;
        if (rdo.signature_status === 'waiting') return statusTone.aguardando_assinatura;
        return statusTone.pronto_assinatura;
    }

    return statusTone[rdo.status] || statusTone.rascunho;
}

function formatLocalDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

export default function Calendar({ contracts, obras, filters, configuration, rdos, copyOptions = [] }) {
    const { currentTenant } = usePage().props;
    const initialRender = useRef(true);
    const [createDate, setCreateDate] = useState(null);
    const [copyFromId, setCopyFromId] = useState('');
    const [creating, setCreating] = useState(false);
    const rdoDates = useMemo(() => new Set(rdos.map((rdo) => rdo.reference_date)), [rdos]);
    const events = useMemo(() => rdos.map((rdo) => ({
        id: String(rdo.id),
        title: rdo.code,
        start: rdo.reference_date,
        allDay: true,
        extendedProps: rdo,
    })), [rdos]);

    const changeFilter = (key, value) => {
        const next = { ...filters, [key]: value || undefined };
        if (key === 'contract_id') {
            delete next.obra_id;
        }
        router.get(route('tenant.diario-obra.rdo.calendar', currentTenant.slug), next, {
            preserveState: false,
            replace: true,
        });
    };

    const openCreate = (date) => {
        if (!configuration || rdoDates.has(date)) return;
        setCreateDate(date);
        setCopyFromId('');
    };

    const generate = () => {
        if (!configuration || !createDate || creating) return;
        setCreating(true);
        router.post(route('tenant.diario-obra.rdo.generate', currentTenant.slug), {
            configuration_id: configuration.id,
            reference_date: createDate,
            copy_from_rdo_id: copyFromId || null,
        }, {
            preserveScroll: true,
            onSuccess: () => setCreateDate(null),
            onFinish: () => setCreating(false),
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="RDO - Calendário" />
            <div className="mx-auto max-w-[1700px] px-4 py-6 sm:px-6">
                <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Diário de Obra</span>
                        <h1 className="mt-2 text-3xl font-bold">RDO</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">Acompanhe, preencha e envie os registros diários pelo calendário.</p>
                    </div>
                    <Link
                        href={route('tenant.diario-obra.rdo.settings', currentTenant.slug)}
                        className="inline-flex items-center gap-2 rounded-lg bg-[var(--primary)] px-4 py-2.5 font-bold text-white"
                    >
                        <Settings size={17} /> Parametrizar RDO
                    </Link>
                </div>

                <div className="mb-4 grid gap-3 rounded-xl border border-[var(--border)] bg-white p-4 shadow-sm md:grid-cols-2">
                    <label>
                        <span className="eyebrow mb-1.5 block">Contrato</span>
                        <select className="sig-input w-full" value={filters.contract_id || ''} onChange={(event) => changeFilter('contract_id', event.target.value)}>
                            {contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} - {contract.name}</option>)}
                        </select>
                    </label>
                    <label>
                        <span className="eyebrow mb-1.5 block">Obra / frente</span>
                        <select className="sig-input w-full" value={filters.obra_id || ''} onChange={(event) => changeFilter('obra_id', event.target.value)}>
                            {obras.map((obra) => <option key={obra.id} value={obra.id}>{obra.codigo} - {obra.nome}</option>)}
                        </select>
                    </label>
                </div>

                {configuration?.obras?.length > 0 && (
                    <div className="mb-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3">
                        <span className="eyebrow text-blue-700">RDO consolidado para {configuration.obras.length} obra(s) / frente(s)</span>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {configuration.obras.map((obra) => (
                                <span key={obra.id} className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-blue-900 shadow-sm">
                                    {obra.codigo} - {obra.nome}
                                </span>
                            ))}
                        </div>
                    </div>
                )}

                {!configuration && (
                    <div className="mb-4 flex items-center justify-between gap-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-900">
                        <span>Essa obra ainda não possui parametrização para geração dos RDOs.</span>
                        <Link className="font-bold underline" href={route('tenant.diario-obra.rdo.settings', {
                            tenant: currentTenant.slug,
                            contract_id: filters.contract_id,
                            obra_id: filters.obra_id,
                        })}>Configurar agora</Link>
                    </div>
                )}

                <div className="rdo-calendar rounded-xl border border-[var(--border)] bg-white p-3 shadow-sm sm:p-5">
                    <FullCalendar
                        plugins={[dayGridPlugin, interactionPlugin]}
                        initialView="dayGridMonth"
                        initialDate={`${filters.month}-01`}
                        locale="pt-br"
                        firstDay={0}
                        height="auto"
                        fixedWeekCount
                        showNonCurrentDates
                        dayMaxEvents={false}
                        events={events}
                        headerToolbar={{ left: 'prev,next today', center: 'title', right: '' }}
                        buttonText={{ today: 'Hoje' }}
                        datesSet={(dateInfo) => {
                            if (initialRender.current) {
                                initialRender.current = false;
                                return;
                            }
                            const month = formatLocalDate(dateInfo.view.currentStart).slice(0, 7);
                            if (month !== filters.month) changeFilter('month', month);
                        }}
                        dayCellContent={(arg) => {
                            const date = formatLocalDate(arg.date);
                            const weekdayEnabled = configuration?.generation_weekdays?.includes(arg.date.getDay());
                            const withinStart = !configuration?.start_date || date >= configuration.start_date;
                            const withinEnd = !configuration?.end_date || date <= configuration.end_date;
                            const canGenerate = !arg.isOther && configuration?.active && weekdayEnabled && withinStart && withinEnd && !rdoDates.has(date);

                            return (
                                <div className="flex w-full items-start justify-between gap-2">
                                    <span className={arg.isOther ? 'text-[var(--ink-300)]' : ''}>{arg.dayNumberText}</span>
                                    {canGenerate && (
                                        <button
                                            type="button"
                                            className="rounded-md border border-[var(--border)] bg-white px-2 py-1 text-[10px] font-bold text-[var(--primary)] hover:bg-[var(--primary-50)]"
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                openCreate(date);
                                            }}
                                        >
                                            + Criar RDO
                                        </button>
                                    )}
                                </div>
                            );
                        }}
                        eventContent={(arg) => {
                            const rdo = arg.event.extendedProps;
                            return (
                                <div className="w-full rounded-lg border border-[var(--border)] bg-white p-2 shadow-sm">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="mono truncate text-[11px] font-bold">{rdo.code}</span>
                                        <span className={`rounded-full px-2 py-0.5 text-[9px] font-bold ${calendarStatusTone(rdo)}`}>
                                            {rdo.calendar_status_label || rdo.status_label}
                                        </span>
                                    </div>
                                    <div className="mt-2 grid gap-1">
                                        <Link href={rdo.show_url} className="inline-flex items-center justify-center gap-1 rounded-md bg-[var(--primary)] px-2 py-1.5 text-[10px] font-bold text-white">
                                            {rdo.status === 'rascunho' || rdo.status === 'devolvido_construtora' || rdo.status === 'pendente_comprovacao'
                                                ? <ClipboardPenLine size={12} />
                                                : <Eye size={12} />}
                                            {rdo.calendar_action_label || (rdo.status === 'rascunho' ? 'Preencher' : 'Visualizar')}
                                        </Link>
                                    </div>
                                </div>
                            );
                        }}
                    />
                </div>
            </div>
            {createDate && (
                <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" onMouseDown={(event) => event.target === event.currentTarget && setCreateDate(null)}>
                    <div className="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Criar RDO">
                        <header className="flex items-center justify-between border-b border-[var(--border)] px-5 py-4">
                            <div>
                                <span className="eyebrow">Novo diário de obra</span>
                                <h2 className="text-xl font-bold">Criar RDO de {new Date(`${createDate}T12:00:00`).toLocaleDateString('pt-BR')}</h2>
                            </div>
                            <button type="button" onClick={() => setCreateDate(null)} className="rounded-lg p-2 hover:bg-slate-100" aria-label="Fechar"><X size={20} /></button>
                        </header>
                        <div className="space-y-4 p-5">
                            <div className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                                Você pode criar um RDO vazio ou copiar todos os preenchimentos de um RDO anterior.
                            </div>
                            <label className="grid gap-1.5">
                                <span className="eyebrow">Copiar dados de outro RDO (opcional)</span>
                                <select className="sig-input" value={copyFromId} onChange={(event) => setCopyFromId(event.target.value)}>
                                    <option value="">Criar RDO vazio</option>
                                    {copyOptions.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}
                                </select>
                            </label>
                            {copyFromId && (
                                <p className="flex items-center gap-2 text-sm font-semibold text-[var(--ink-600)]">
                                    <Copy size={16} /> Clima, efetivo, equipamentos, atividades, fotos e comentários serão copiados.
                                </p>
                            )}
                        </div>
                        <footer className="flex justify-end gap-2 border-t border-[var(--border)] px-5 py-4">
                            <button type="button" onClick={() => setCreateDate(null)} className="sig-btn">Cancelar</button>
                            <button type="button" onClick={generate} disabled={creating} className="sig-btn sig-btn-primary">
                                <Plus size={16} /> {creating ? 'Criando...' : 'Criar RDO'}
                            </button>
                        </footer>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
