import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Field } from '@/Components/OsDnitOrderUi';
import OsDnitKilometerInput from '@/Components/OsDnitKilometerInput';
import { normalizeKilometers } from '@/Support/osDnitKilometers';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Paperclip, Save } from 'lucide-react';
import { useEffect } from 'react';
import { consumeAssistantDraft } from '@/Utils/assistantDraft';

export default function CreateOrder({ contracts, highways, lots, numberPreviews, order = null }) {
    const { currentTenant } = usePage().props;
    const editing = Boolean(order);
    const title = editing ? `Editar OS ${order.request_number}` : 'Criar OS';
    const form = useForm({ contract_id: order ? String(order.contract_id) : contracts.length === 1 ? String(contracts[0].id) : '',
        supervision_lot_id: order?.supervision_lot_id ? String(order.supervision_lot_id) : '', highway_id: order ? String(order.highway_id) : '',
        km_start: normalizeKilometers(order?.km_start) ?? '', km_end: normalizeKilometers(order?.km_end) ?? '', subject: order?.subject || '',
        desired_deadline: order?.desired_deadline?.slice(0, 10) || '', technical_need: order?.technical_need || '',
        justification: order?.justification || '', sei_reference: lots.find((lot) => lot.id === order?.supervision_lot_id)?.sei_reference || order?.sei_reference || '', attachments: [],
        ...(order ? { version: order.workflow_version, _method: 'patch' } : {}) });
    useEffect(() => {
        if (editing) return;
        const draft = consumeAssistantDraft(currentTenant.id, 'os_dnit');
        if (!draft || !contracts.some((contract) => String(contract.id) === String(draft.contract_id))) return;
        const lot = lots.find((lot) => String(lot.contract_id) === String(draft.contract_id) && (String(lot.id) === String(draft.supervision_lot_id) || lot.name === draft.supervision_lot));
        const highway = highways.find((road) => String(road.id) === String(draft.highway_id) && String(road.contract_id) === String(draft.contract_id));
        form.setData((data) => ({ ...data, ...Object.fromEntries(Object.entries(draft).filter(([key]) => Object.hasOwn(data, key))), highway_id: highway ? String(highway.id) : '', attachments: [],
            supervision_lot_id: lot ? String(lot.id) : '', sei_reference: lot?.sei_reference || draft.sei_reference || '',
            km_start: normalizeKilometers(draft.km_start) ?? '', km_end: normalizeKilometers(draft.km_end) ?? '' }));
    }, []);
    const contractLots = lots.filter((lot) => String(lot.contract_id) === String(form.data.contract_id));
    const selectedLot = contractLots.find((lot) => String(lot.id) === String(form.data.supervision_lot_id));
    const contractHighways = highways.filter((highway) => String(highway.contract_id) === String(form.data.contract_id));
    const unavailable = !form.data.contract_id || contractHighways.length === 0 || contractLots.length === 0;
    const propsFor = (name, required = true) => ({
        id: `os-${name}`, value: form.data[name], onChange: (event) => form.setData(name, event.target.value), required,
        'aria-invalid': Boolean(form.errors[name]), 'aria-describedby': form.errors[name] ? `os-${name}-error` : undefined,
        className: 'w-full rounded-lg border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500',
    });
    const submit = (event) => {
        event.preventDefault();
        form.post(editing ? route('tenant.os-dnit.orders.update', [currentTenant.slug, order.id]) : route('tenant.os-dnit.orders.store', currentTenant.slug), { forceFormData: true, preserveScroll: true });
    };

    return <AuthenticatedLayout>
        <Head title={`Ordem de Serviço · ${title}`} />
        <div className="sig-content os-dnit-page space-y-6">
            {contracts.length === 0 && <p role="status" className="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">Para criar uma OS, um administrador deve cadastrar você como Analista DNIT em Responsáveis de um contrato ao qual tenha vínculo ativo.</p>}
            <header><Link href={route('tenant.os-dnit.orders.index', currentTenant.slug)} className="inline-flex items-center gap-2 text-sm text-[var(--ink-500)]"><ArrowLeft size={16} />Voltar às ordens de serviço</Link><div className="eyebrow mt-4">Ordem de Serviço</div><h1 className="mt-2 text-2xl font-semibold">{title}</h1><p className="mt-2 text-sm text-[var(--ink-500)]">Preencha a demanda. Os produtos e as quantidades serão definidos na Análise Supervisão.</p></header>
            {unavailable && <div role="status" className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p className="font-semibold">Complete os cadastros para criar uma OS:</p><ul className="mt-2 list-inside list-disc space-y-1">{!form.data.contract_id && <li>Selecione um contrato com o qual você tenha vínculo ativo.</li>}{form.data.contract_id && contractHighways.length === 0 && <li><Link href={route('tenant.os-dnit.settings.index', currentTenant.slug)} className="underline">Cadastre uma rodovia ativa neste contrato na Parametrização.</Link></li>}{form.data.contract_id && contractLots.length === 0 && <li><Link href={route('tenant.os-dnit.settings.index', currentTenant.slug)} className="underline">Cadastre um lote de supervisão ativo neste contrato.</Link></li>}</ul></div>}
            {Object.keys(form.errors).length > 0 && <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p className="font-semibold">Confira os campos indicados para salvar a OS.</p><ul className="mt-2 list-inside list-disc">{[...new Set(Object.values(form.errors))].map((error) => <li key={error}>{error}</li>)}</ul></div>}
            <form onSubmit={submit} className="space-y-6">
                <section className="sig-card" aria-labelledby="demand-title">
                    <header className="border-b border-slate-200 px-5 py-4"><h2 id="demand-title" className="text-lg font-semibold">Identificação da demanda</h2></header>
                    <div className="space-y-6 p-5">
                        <fieldset className="space-y-4"><legend className="text-sm font-bold text-[var(--ink-700)]">Vínculo contratual</legend><div className="grid gap-4 md:grid-cols-3">
                            <Field name="request_number" label="Número da solicitação" error={form.errors.request_number}><input id="os-request_number" readOnly value={order?.request_number || numberPreviews[form.data.contract_id] || ''} placeholder="Selecione o contrato" className="w-full rounded-lg border-slate-200 bg-slate-50 text-sm text-slate-500" /><span className="text-xs text-[var(--ink-500)]">{editing ? 'Número atribuído pelo sistema.' : 'Prévia por contrato e ano. Número confirmado ao salvar.'}</span></Field>
                            <Field name="contract_id" label="Contrato de supervisão" error={form.errors.contract_id}><select {...propsFor('contract_id')} disabled={editing} onChange={(event) => { form.setData({ ...form.data, contract_id: event.target.value, highway_id: '', supervision_lot_id: '', sei_reference: '' }); form.clearErrors(); }}><option value="">Selecione o contrato</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} — {contract.name}</option>)}</select></Field>
                            <Field name="supervision_lot_id" label="Lote de supervisão" error={form.errors.supervision_lot_id}><select {...propsFor('supervision_lot_id')} onChange={(event) => { const lot = contractLots.find((lot) => String(lot.id) === event.target.value); form.setData({ ...form.data, supervision_lot_id: event.target.value, sei_reference: lot?.sei_reference || '' }); }}><option value="">Selecione o lote</option>{contractLots.map((lot) => <option key={lot.id} value={lot.id}>{lot.name}</option>)}</select></Field>
                        </div></fieldset>
                        <fieldset className="space-y-4"><legend className="text-sm font-bold text-[var(--ink-700)]">Localização e objeto</legend><div className="grid gap-4 md:grid-cols-3">
                            <Field name="highway_id" label="Rodovia" error={form.errors.highway_id}><select {...propsFor('highway_id')}><option value="">Selecione a rodovia</option>{contractHighways.map((highway) => <option key={highway.id} value={highway.id}>{highway.name}</option>)}</select></Field>
                            <Field name="km_start" label="Km inicial" error={form.errors.km_start}><OsDnitKilometerInput {...propsFor('km_start')} onValueChange={(value) => form.setData('km_start', value)} /></Field>
                            <Field name="km_end" label="Km final" error={form.errors.km_end}><OsDnitKilometerInput {...propsFor('km_end')} onValueChange={(value) => form.setData('km_end', value)} /></Field>
                        </div><div className="grid gap-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                            <Field name="subject" label="Assunto da solicitação" error={form.errors.subject}><input {...propsFor('subject')} maxLength={255} /></Field>
                            <Field name="desired_deadline" label="Prazo pretendido" error={form.errors.desired_deadline}><input {...propsFor('desired_deadline')} type="date" /></Field>
                        </div></fieldset>
                        <Field name="technical_need" label="Descrição da necessidade técnica" error={form.errors.technical_need}><textarea {...propsFor('technical_need')} rows={4} maxLength={20000} /></Field>
                        <Field name="justification" label="Justificativa / resultado esperado" error={form.errors.justification}><textarea {...propsFor('justification')} rows={4} maxLength={20000} /></Field>
                        <Field name="sei_reference" label="Referência SEI / processo (opcional)" error={form.errors.sei_reference}><input {...propsFor('sei_reference', false)} readOnly={Boolean(selectedLot?.sei_reference)} maxLength={255} />{selectedLot?.sei_reference && <span className="text-xs text-slate-500">Referência vinculada ao lote de supervisão.</span>}</Field>
                        <div className="space-y-2">{editing && order.attachments.length > 0 && <p className="text-sm text-slate-500">Anexos existentes preservados: {order.attachments.map((a) => a.original_name).join(", ")}</p>}<label htmlFor="os-attachments" className="inline-flex items-center gap-2 text-sm font-semibold"><Paperclip size={16} />{editing ? 'Adicionar anexos (opcional)' : 'Anexos (opcional)'}</label><input id="os-attachments" type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip" onChange={(event) => { form.setData('attachments', Array.from(event.target.files || [])); form.clearErrors(); }} className="block w-full rounded-lg border border-slate-300 p-2 text-sm" /><p className="text-xs text-[var(--ink-500)]">Até 10 arquivos de 10 MB cada. PDF, Word, Excel, imagens JPG/PNG ou ZIP.</p>{form.data.attachments.map((file, index) => <p key={`${index}-${file.name}`} className="break-all text-xs text-[var(--ink-500)]">{file.name} · {(file.size / 1024 / 1024).toFixed(2)} MB</p>)}</div>
                    </div>
                </section>

                {form.progress && <p role="status" className="text-sm text-[var(--ink-500)]">Enviando arquivos: {form.progress.percentage}%</p>}
                <div className="flex flex-wrap justify-end gap-3"><Link href={route('tenant.os-dnit.orders.index', currentTenant.slug)} className="sig-btn sig-btn-secondary">Cancelar</Link><button type="submit" disabled={form.processing || unavailable} className="sig-btn sig-btn-primary"><Save size={16} />{form.processing ? 'Salvando…' : editing ? 'Salvar alterações' : 'Salvar demanda'}</button></div>
            </form>
        </div>
    </AuthenticatedLayout>;
}
