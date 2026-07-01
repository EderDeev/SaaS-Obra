import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, CalendarClock, Copy, Save } from 'lucide-react';
import { useEffect } from 'react';

const weekdays = [
    { value: 0, label: 'Dom' },
    { value: 1, label: 'Seg' },
    { value: 2, label: 'Ter' },
    { value: 3, label: 'Qua' },
    { value: 4, label: 'Qui' },
    { value: 5, label: 'Sex' },
    { value: 6, label: 'Sáb' },
];

export default function Settings({ contracts, obras, users, filters, configuration }) {
    const { currentTenant } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({
        configuration_id: configuration?.id || '',
        contract_id: filters.contract_id || '',
        obra_ids: configuration?.obra_ids || (filters.obra_id ? [Number(filters.obra_id)] : []),
        responsible_user_id: configuration?.responsible_user_id || '',
        start_date: configuration?.start_date || new Date().toLocaleDateString('en-CA'),
        end_date: configuration?.end_date || '',
        generation_time: configuration?.generation_time || '00:00',
        timezone: configuration?.timezone || 'America/Sao_Paulo',
        generation_weekdays: configuration?.generation_weekdays || [0, 1, 2, 3, 4, 5, 6],
        generate_on_holidays: configuration?.generate_on_holidays ?? true,
        copy_previous_day: configuration?.copy_previous_day ?? false,
        copy_workforce: configuration?.copy_workforce ?? true,
        copy_equipment: configuration?.copy_equipment ?? true,
        copy_pending_activities: configuration?.copy_pending_activities ?? true,
        require_photos: configuration?.require_photos ?? false,
        digital_signature_enabled: configuration?.digital_signature_enabled ?? true,
        submission_deadline_days: configuration?.submission_deadline_days ?? 7,
        active: configuration?.active ?? true,
    });

    useEffect(() => {
        setData((current) => ({
            ...current,
            configuration_id: configuration?.id || '',
            contract_id: filters.contract_id || '',
            obra_ids: configuration?.obra_ids || (filters.obra_id ? [Number(filters.obra_id)] : []),
            responsible_user_id: configuration?.responsible_user_id || '',
            start_date: configuration?.start_date || new Date().toLocaleDateString('en-CA'),
            end_date: configuration?.end_date || '',
            generation_time: configuration?.generation_time || '00:00',
            generation_weekdays: configuration?.generation_weekdays || [0, 1, 2, 3, 4, 5, 6],
            copy_previous_day: configuration?.copy_previous_day ?? false,
            copy_workforce: configuration?.copy_workforce ?? true,
            copy_equipment: configuration?.copy_equipment ?? true,
            copy_pending_activities: configuration?.copy_pending_activities ?? true,
            require_photos: configuration?.require_photos ?? false,
            digital_signature_enabled: configuration?.digital_signature_enabled ?? true,
            submission_deadline_days: configuration?.submission_deadline_days ?? 7,
            active: configuration?.active ?? true,
        }));
    }, [configuration]);

    const filter = (key, value) => {
        const next = { ...filters, [key]: value || undefined };
        if (key === 'contract_id') delete next.obra_id;
        router.get(route('tenant.diario-obra.rdo.settings', currentTenant.slug), next, { replace: true });
    };

    const toggleWeekday = (day) => {
        setData('generation_weekdays', data.generation_weekdays.includes(day)
            ? data.generation_weekdays.filter((value) => value !== day)
            : [...data.generation_weekdays, day].sort());
    };

    const toggleObra = (obraId) => {
        setData('obra_ids', data.obra_ids.includes(obraId)
            ? data.obra_ids.filter((id) => id !== obraId)
            : [...data.obra_ids, obraId]);
    };

    const submit = (event) => {
        event.preventDefault();
        post(route('tenant.diario-obra.rdo.settings.store', currentTenant.slug));
    };

    return (
        <AuthenticatedLayout>
            <Head title="RDO - Parametrização" />
            <div className="mx-auto max-w-6xl px-4 py-6 sm:px-6">
                <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow">Diário de Obra · RDO</span>
                        <h1 className="mt-2 text-3xl font-bold">Parametrização</h1>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">Defina quando e como os diários serão criados automaticamente.</p>
                    </div>
                    <Link href={route('tenant.diario-obra.rdo.calendar', currentTenant.slug)} className="inline-flex items-center gap-2 rounded-lg border border-[var(--border)] bg-white px-4 py-2.5 font-bold">
                        <ArrowLeft size={17} /> Voltar ao calendário
                    </Link>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <section className="rounded-xl border border-[var(--border)] bg-white p-5 shadow-sm">
                        <div className="mb-4 flex items-center gap-3">
                            <CalendarClock className="text-[var(--primary)]" size={22} />
                            <div>
                                <h2 className="text-lg font-bold">Escopo e geração</h2>
                                <p className="text-sm text-[var(--ink-500)]">Selecione todas as obras ou frentes de serviço que farão parte do RDO consolidado.</p>
                            </div>
                        </div>
                        <div className="grid gap-4 md:grid-cols-2">
                            <Field label="Contrato" error={errors.contract_id}>
                                <select className="sig-input w-full" value={data.contract_id} onChange={(event) => filter('contract_id', event.target.value)}>
                                    {contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} - {contract.name}</option>)}
                                </select>
                            </Field>
                            <Field label="Data inicial" error={errors.start_date}>
                                <input type="date" className="sig-input w-full" value={data.start_date} onChange={(event) => setData('start_date', event.target.value)} />
                            </Field>
                            <Field label="Data final (opcional)" error={errors.end_date}>
                                <input type="date" className="sig-input w-full" value={data.end_date} onChange={(event) => setData('end_date', event.target.value)} />
                            </Field>
                            <Field label="Horário de geração" error={errors.generation_time}>
                                <input type="time" className="sig-input w-full" value={data.generation_time} onChange={(event) => setData('generation_time', event.target.value)} />
                            </Field>
                            <Field label="Responsável padrão" error={errors.responsible_user_id}>
                                <select className="sig-input w-full" value={data.responsible_user_id} onChange={(event) => setData('responsible_user_id', event.target.value)}>
                                    <option value="">Definir depois</option>
                                    {users.map((user) => <option key={user.id} value={user.id}>{user.name} - {user.email}</option>)}
                                </select>
                            </Field>
                        </div>
                        <div className="mt-4">
                            <div className="mb-2 flex items-center justify-between gap-3">
                                <span className="eyebrow">Obras / frentes de serviço</span>
                                <span className="text-xs font-semibold text-[var(--ink-500)]">{data.obra_ids.length} selecionada(s)</span>
                            </div>
                            <div className="grid gap-2 md:grid-cols-2">
                                {obras.map((obra) => (
                                    <label
                                        key={obra.id}
                                        className={`flex cursor-pointer items-center gap-3 rounded-lg border px-4 py-3 ${
                                            data.obra_ids.includes(obra.id)
                                                ? 'border-[var(--primary)] bg-[var(--primary-50)]'
                                                : 'border-[var(--border)] bg-white'
                                        }`}
                                    >
                                        <input
                                            type="checkbox"
                                            className="h-5 w-5 rounded border-[var(--border)] text-[var(--primary)]"
                                            checked={data.obra_ids.includes(obra.id)}
                                            onChange={() => toggleObra(obra.id)}
                                        />
                                        <span>
                                            <strong className="mono text-xs">{obra.codigo}</strong>
                                            <span className="ml-2 font-semibold">{obra.nome}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            {(errors.obra_ids || errors['obra_ids.0']) && (
                                <p className="mt-1 text-xs text-red-600">{errors.obra_ids || errors['obra_ids.0']}</p>
                            )}
                        </div>
                        <div className="mt-4">
                            <span className="eyebrow mb-2 block">Dias que geram RDO</span>
                            <div className="flex flex-wrap gap-2">
                                {weekdays.map((day) => (
                                    <button key={day.value} type="button" onClick={() => toggleWeekday(day.value)}
                                        className={`rounded-lg border px-4 py-2 text-sm font-bold ${data.generation_weekdays.includes(day.value) ? 'border-[var(--primary)] bg-[var(--primary-50)] text-[var(--primary)]' : 'border-[var(--border)] bg-white'}`}>
                                        {day.label}
                                    </button>
                                ))}
                            </div>
                            {errors.generation_weekdays && <p className="mt-1 text-xs text-red-600">{errors.generation_weekdays}</p>}
                        </div>
                    </section>

                    <section className="rounded-xl border border-[var(--border)] bg-white p-5 shadow-sm">
                        <div className="mb-4 flex items-center gap-3">
                            <Copy className="text-[var(--primary)]" size={22} />
                            <div>
                                <h2 className="text-lg font-bold">Continuidade do dia anterior</h2>
                                <p className="text-sm text-[var(--ink-500)]">Evita redigitação, mantendo cada RDO como um registro independente.</p>
                            </div>
                        </div>
                        <Toggle label="Copiar dados do RDO anterior" checked={data.copy_previous_day} onChange={(value) => setData('copy_previous_day', value)} />
                        <div className={`mt-3 grid gap-3 md:grid-cols-3 ${data.copy_previous_day ? '' : 'pointer-events-none opacity-45'}`}>
                            <Toggle compact label="Copiar mão de obra" checked={data.copy_workforce} onChange={(value) => setData('copy_workforce', value)} />
                            <Toggle compact label="Copiar equipamentos" checked={data.copy_equipment} onChange={(value) => setData('copy_equipment', value)} />
                            <Toggle compact label="Copiar atividades pendentes" checked={data.copy_pending_activities} onChange={(value) => setData('copy_pending_activities', value)} />
                        </div>
                    </section>

                    <section className="rounded-xl border border-[var(--border)] bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-bold">Regras de preenchimento</h2>
                        <div className="mt-4 grid gap-4 md:grid-cols-2">
                            <Toggle compact label="Exigir ao menos uma foto para envio" checked={data.require_photos} onChange={(value) => setData('require_photos', value)} />
                            <Toggle compact label="Usar assinatura digital" checked={data.digital_signature_enabled} onChange={(value) => setData('digital_signature_enabled', value)} />
                            <Field label="Prazo para envio (dias)" error={errors.submission_deadline_days}>
                                <input
                                    type="number"
                                    min="1"
                                    max="365"
                                    className="sig-input w-full"
                                    value={data.submission_deadline_days}
                                    onChange={(event) => setData('submission_deadline_days', event.target.value)}
                                />
                                <span className="mt-1 block text-xs text-[var(--ink-500)]">
                                    O prazo padrão é de 7 dias após a data do RDO.
                                </span>
                            </Field>
                            <Toggle compact label="Parametrização ativa" checked={data.active} onChange={(value) => setData('active', value)} />
                        </div>
                        {!data.digital_signature_enabled && (
                            <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
                                Com a assinatura digital desativada, o RDO aprovado ficará aguardando upload manual do documento assinado.
                            </div>
                        )}
                    </section>

                    <div className="flex justify-end">
                        <button disabled={processing || !data.contract_id || data.obra_ids.length === 0} className="inline-flex items-center gap-2 rounded-lg bg-[var(--primary)] px-5 py-3 font-bold text-white disabled:opacity-50">
                            <Save size={17} /> Salvar parametrização
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}

function Field({ label, error, children }) {
    return <label><span className="eyebrow mb-1.5 block">{label}</span>{children}{error && <span className="mt-1 block text-xs text-red-600">{error}</span>}</label>;
}

function Toggle({ label, checked, onChange, compact = false }) {
    return (
        <label className={`flex cursor-pointer items-center justify-between gap-4 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] ${compact ? 'px-4 py-3' : 'p-4'}`}>
            <span className="font-semibold">{label}</span>
            <input type="checkbox" className="h-5 w-5 rounded border-[var(--border)] text-[var(--primary)]" checked={checked} onChange={(event) => onChange(event.target.checked)} />
        </label>
    );
}
