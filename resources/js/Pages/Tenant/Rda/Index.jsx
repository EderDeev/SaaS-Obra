import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import FullCalendar from '@fullcalendar/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

function formatLocalDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function ObraPickerModal({ date, obras = [], apontamentos = [], processing = false, onSelect, onClose }) {
    const rdaByObra = new Map(apontamentos.map((rda) => [Number(rda.obra_id), rda]));

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4">
            <div className="w-full max-w-2xl rounded-2xl bg-white p-5 shadow-2xl">
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Escolha a obra / frente</span>
                        <h2 className="mt-1 text-xl font-black text-[var(--ink-900)]">Preencher RDA</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">
                            Selecione em qual obra será feito o apontamento do dia {date?.split('-').reverse().join('/')}.
                        </p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg border border-[var(--border)] px-3 py-2 text-sm font-bold">
                        Fechar
                    </button>
                </div>

                <div className="grid gap-3">
                    {obras.map((obra) => {
                        const rda = rdaByObra.get(Number(obra.id));
                        const label = rda ? (rda.status === 'publicado' ? 'Ver RDA publicado' : 'Editar RDA') : 'Preencher RDA';

                        return (
                            <button
                                key={obra.id}
                                type="button"
                                disabled={processing}
                                onClick={() => onSelect(obra.id)}
                                className="flex items-center justify-between gap-4 rounded-xl border border-[var(--border)] bg-white px-4 py-3 text-left transition hover:border-[var(--primary)] hover:bg-blue-50 disabled:opacity-60"
                            >
                                <div>
                                    <p className="font-black text-[var(--ink-900)]">{obra.codigo} - {obra.nome}</p>
                                    <p className="mt-1 text-xs font-semibold text-[var(--ink-500)]">
                                        {rda ? rda.status_label : 'Nenhum RDA criado nesta frente'}
                                    </p>
                                </div>
                                <span className="rounded-lg bg-[var(--primary)] px-3 py-2 text-xs font-black text-white">
                                    {processing ? 'Abrindo...' : label}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

export default function RdaIndex({
    contracts = [],
    obras = [],
    filters = {},
    configuration = null,
    validDays = [],
    existingRdos = [],
    apontamentos = [],
    summary = {},
}) {
    const { currentTenant } = usePage().props;
    const initialRender = useRef(true);
    const [creatingDate, setCreatingDate] = useState(null);
    const [obraPickerDate, setObraPickerDate] = useState(null);
    const validDaySet = useMemo(() => new Set(validDays), [validDays]);
    const rdosByDate = useMemo(() => new Map(existingRdos.map((rdo) => [rdo.reference_date, rdo])), [existingRdos]);
    const rdasByDate = useMemo(() => {
        const grouped = new Map();
        apontamentos.forEach((rda) => {
            const items = grouped.get(rda.reference_date) || [];
            items.push(rda);
            grouped.set(rda.reference_date, items);
        });
        return grouped;
    }, [apontamentos]);
    const rdaFor = (date, obraId) => (rdasByDate.get(date) || []).find((rda) => Number(rda.obra_id) === Number(obraId));

    const changeFilter = (key, value) => {
        const next = { ...filters, [key]: value || undefined };
        if (key === 'contract_id') {
            delete next.obra_id;
        }

        router.get(route('tenant.diario-obra.rda.index', currentTenant.slug), next, {
            preserveState: false,
            replace: true,
        });
    };

    const fillRda = (date, obraId = null) => {
        const selectedObraId = obraId || filters.obra_id || obras[0]?.id;
        if (!selectedObraId) return;

        if (!obraId && obras.length > 1) {
            setObraPickerDate(date);
            return;
        }

        const existing = rdaFor(date, selectedObraId);
        const rdo = rdosByDate.get(date);

        if (existing?.url && existing.rdo_diario_id) {
            router.visit(existing.url);
            return;
        }

        if (!rdo) return;

        setCreatingDate(date);
        router.post(route('tenant.diario-obra.rda.store', currentTenant.slug),
            {
                contract_id: filters.contract_id,
                obra_id: selectedObraId,
                reference_date: date,
            },
            {
                preserveScroll: true,
                onFinish: () => setCreatingDate(null),
            });
    };

    return (
        <AuthenticatedLayout>
            <Head title="RDA" />

            <div className="mx-auto max-w-[1700px] px-4 py-6 sm:px-6">
                <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Diário de Obra</span>
                        <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">RDA</h1>
                        <p className="mt-1 max-w-3xl text-sm text-[var(--ink-500)]">
                            Registro Diário de Atividades para apontamentos de campo. Ele herda a parametrização do RDO e serve como insumo operacional para o RDO oficial.
                        </p>
                    </div>
                    <div className="rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                        <strong>{summary.month_label}</strong>
                        <span className="ml-2 text-blue-700">· {summary.days_in_month} dias no período</span>
                    </div>
                </div>

                <div className="mb-5 grid gap-3 rounded-xl border border-[var(--border)] bg-white p-4 shadow-sm md:grid-cols-3">
                    <label>
                        <span className="eyebrow mb-1.5 block">Contrato</span>
                        <select className="sig-input w-full" value={filters.contract_id || ''} onChange={(event) => changeFilter('contract_id', event.target.value)}>
                            {contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} - {contract.name}</option>)}
                        </select>
                    </label>
                    <label>
                        <span className="eyebrow mb-1.5 block">Obra / frente herdada do RDO</span>
                        <select className="sig-input w-full" value={filters.obra_id || ''} onChange={(event) => changeFilter('obra_id', event.target.value)} disabled={!configuration}>
                            {obras.map((obra) => <option key={obra.id} value={obra.id}>{obra.codigo} - {obra.nome}</option>)}
                        </select>
                    </label>
                    <label>
                        <span className="eyebrow mb-1.5 block">Mês</span>
                        <input className="sig-input w-full" type="month" value={filters.month || ''} onChange={(event) => changeFilter('month', event.target.value)} />
                    </label>
                </div>

                {!configuration && (
                    <div className="mb-5 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
                        <div className="flex items-center gap-2 font-black">
                            <Info size={18} /> RDA depende da parametrização do RDO
                        </div>
                        <p className="mt-2 text-sm">
                            Este contrato ainda não possui parametrização ativa de RDO para gerar os apontamentos de RDA. Configure o RDO primeiro para definir prazo, frentes e calendário de geração.
                        </p>
                        <Link className="mt-3 inline-flex rounded-lg bg-amber-600 px-4 py-2 text-sm font-bold text-white" href={route('tenant.diario-obra.rdo.settings', currentTenant.slug)}>
                            Abrir parametrização do RDO
                        </Link>
                    </div>
                )}

                <div className="grid gap-5">
                    <p className="mb-[-0.75rem] text-xs font-semibold text-[var(--ink-500)] sm:hidden">Deslize lateralmente para ver todos os dias da semana.</p>
                    <section className="rdo-calendar rounded-xl border border-[var(--border)] bg-white p-3 shadow-sm sm:p-5">
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
                            events={[
                                ...existingRdos.map((rdo) => ({
                                    id: `rdo-${rdo.id}`,
                                    title: rdo.code,
                                    start: rdo.reference_date,
                                    allDay: true,
                                    url: rdo.url,
                                    className: 'rda-rdo-event',
                                })),
                                ...apontamentos.map((rda) => ({
                                    id: `rda-${rda.id}`,
                                    title: rda.status_label,
                                    start: rda.reference_date,
                                    allDay: true,
                                    url: rda.url,
                                    className: rda.status === 'publicado' ? 'rda-published-event' : 'rda-draft-event',
                                })),
                            ]}
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
                                const valid = validDaySet.has(date);
                                const rdo = rdosByDate.get(date);
                                const rdasInDate = rdasByDate.get(date) || [];
                                const rda = filters.obra_id ? rdaFor(date, filters.obra_id) : rdasInDate[0];
                                const rdaCanFill = Boolean(rda?.can_fill);
                                const rdoCanFill = Boolean(rdo?.can_fill);
                                const canOpenRda = Boolean(
                                    rda?.status === 'publicado'
                                    || rdaCanFill
                                    || (!rda && rdoCanFill)
                                );
                                const rdaStatusLabel = rda
                                    ? (rda.status === 'publicado'
                                        ? 'Publicado'
                                        : (rdaCanFill ? (rda.rdo_diario_id ? (rdasInDate.length > 1 ? `${rdasInDate.length} RDA(s)` : rda.status_label) : 'Preencher RDA') : 'Prazo vencido'))
                                    : (rdo ? (rdoCanFill ? 'Com RDO' : 'Prazo vencido') : valid ? 'Aguardando RDO' : 'Fora regra');
                                const rdaActionLabel = rda
                                    ? (rda.status === 'publicado' ? 'Ver RDA' : 'Editar RDA')
                                    : 'Preencher RDA';

                                return (
                                    <div className="flex min-h-[58px] w-full flex-col gap-2">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className={arg.isOther ? 'text-[var(--ink-300)]' : ''}>{arg.dayNumberText}</span>
                                            {!arg.isOther && configuration && (
                                                <span className={`rounded-md px-2 py-1 text-[10px] font-bold ${rda?.status === 'publicado' ? 'bg-emerald-50 text-emerald-700' : (rda || rdo) && !canOpenRda ? 'bg-red-50 text-red-700' : rda ? 'bg-amber-50 text-amber-700' : rdo ? 'bg-blue-50 text-blue-700' : valid ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-[var(--ink-400)]'}`}>
                                                    {rdaStatusLabel}
                                                </span>
                                            )}
                                        </div>
                                        {!arg.isOther && configuration && valid && canOpenRda && (
                                            <button
                                                type="button"
                                                className="self-start rounded-md bg-[var(--primary)] px-2 py-1 text-[10px] font-bold text-white disabled:opacity-60"
                                                disabled={creatingDate === date}
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    fillRda(date);
                                                }}
                                            >
                                                {creatingDate === date ? 'Abrindo...' : rdaActionLabel}
                                            </button>
                                        )}
                                    </div>
                                );
                            }}
                        />
                    </section>

                </div>
                {obraPickerDate && (
                    <ObraPickerModal
                        date={obraPickerDate}
                        obras={obras}
                        apontamentos={rdasByDate.get(obraPickerDate) || []}
                        processing={creatingDate === obraPickerDate}
                        onSelect={(obraId) => fillRda(obraPickerDate, obraId)}
                        onClose={() => setObraPickerDate(null)}
                    />
                )}
            </div>
        </AuthenticatedLayout>
    );
}
