import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Field } from '@/Components/OsDnitOrderUi';
import OsDnitProductPicker from '@/Components/OsDnitProductPicker';
import OsDnitKilometerInput from '@/Components/OsDnitKilometerInput';
import { normalizeKilometers } from '@/Support/osDnitKilometers';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Package, Paperclip, Save, X } from 'lucide-react';
import { useEffect } from 'react';
import { consumeAssistantDraft } from '@/Utils/assistantDraft';

export default function CreateOrder({ contracts, highways, products, categories, frequencies, numberPreviews, order = null }) {
    const { currentTenant } = usePage().props;
    const editing = Boolean(order);
    const title = editing ? `Editar OS ${order.request_number}` : 'Criar OS';
    const form = useForm({ contract_id: order ? String(order.contract_id) : contracts.length === 1 ? String(contracts[0].id) : '',
        supervision_lot: order?.supervision_lot || '', highway_id: order ? String(order.highway_id) : '',
        km_start: normalizeKilometers(order?.km_start) ?? '', km_end: normalizeKilometers(order?.km_end) ?? '', subject: order?.subject || '',
        desired_deadline: order?.desired_deadline?.slice(0, 10) || '', technical_need: order?.technical_need || '',
        justification: order?.justification || '', sei_reference: order?.sei_reference || '', attachments: [],
        items: order?.items.map((i) => ({ product_id: i.product_id, quantity: i.quantity })) || [],
        ...(order ? { version: order.workflow_version, _method: 'patch' } : {}) });
    useEffect(() => {
        if (editing) return;
        const draft = consumeAssistantDraft(currentTenant.id, 'os_dnit');
        if (!draft || !contracts.some((contract) => String(contract.id) === String(draft.contract_id))) return;
        const productIds = new Set(products.filter((product) => String(product.contract_id) === String(draft.contract_id)).map((product) => product.id));
        const highway = highways.find((road) => String(road.id) === String(draft.highway_id) && String(road.contract_id) === String(draft.contract_id));
        form.setData((data) => ({ ...data, ...draft, highway_id: highway ? String(highway.id) : '', attachments: [],
            items: (draft.items || []).filter((item) => productIds.has(item.product_id)),
            km_start: normalizeKilometers(draft.km_start) ?? '', km_end: normalizeKilometers(draft.km_end) ?? '' }));
    }, []);
    const contractProducts = products.filter((product) => String(product.contract_id) === String(form.data.contract_id));
    const contractHighways = highways.filter((highway) => String(highway.contract_id) === String(form.data.contract_id));
    const unavailable = !form.data.contract_id || contractHighways.length === 0 || contractProducts.length === 0;
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
        <Head title={`OS DNIT · ${title}`} />
        <div className="sig-content os-dnit-page space-y-6">
            {contracts.length === 0 && <p role="status" className="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">Para criar uma OS, um administrador deve cadastrar você como Analista DNIT em Responsáveis de um contrato ao qual tenha vínculo ativo.</p>}
            <header><Link href={route('tenant.os-dnit.orders.index', currentTenant.slug)} className="inline-flex items-center gap-2 text-sm text-[var(--ink-500)]"><ArrowLeft size={16} />Voltar às ordens de serviço</Link><div className="eyebrow mt-4">OS DNIT</div><h1 className="mt-2 text-2xl font-semibold">{title}</h1><p className="mt-2 text-sm text-[var(--ink-500)]">Preencha a demanda e informe os produtos e quantidades solicitados.</p></header>
            {unavailable && <div role="status" className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p className="font-semibold">Complete os cadastros para criar uma OS:</p><ul className="mt-2 list-inside list-disc space-y-1">{!form.data.contract_id && <li>Selecione um contrato com o qual você tenha vínculo ativo.</li>}{form.data.contract_id && contractHighways.length === 0 && <li><Link href={route('tenant.os-dnit.settings.index', currentTenant.slug)} className="underline">Cadastre uma rodovia ativa neste contrato na Parametrização.</Link></li>}{form.data.contract_id && contractProducts.length === 0 && <li><Link href={route('tenant.os-dnit.products.index', currentTenant.slug)} className="underline">Cadastre os produtos solicitados neste contrato.</Link></li>}</ul></div>}
            {Object.keys(form.errors).length > 0 && <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p className="font-semibold">Confira os campos indicados para salvar a OS.</p><ul className="mt-2 list-inside list-disc">{[...new Set(Object.values(form.errors))].map((error) => <li key={error}>{error}</li>)}</ul></div>}
            <form onSubmit={submit} className="space-y-6">
                <section className="sig-card" aria-labelledby="demand-title">
                    <header className="border-b border-slate-200 px-5 py-4"><h2 id="demand-title" className="text-lg font-semibold">01 · Identificação da demanda</h2></header>
                    <div className="space-y-6 p-5">
                        <fieldset className="space-y-4"><legend className="text-sm font-bold text-[var(--ink-700)]">Vínculo contratual</legend><div className="grid gap-4 md:grid-cols-3">
                            <Field name="request_number" label="Número da solicitação" error={form.errors.request_number}><input id="os-request_number" readOnly value={order?.request_number || numberPreviews[form.data.contract_id] || ''} placeholder="Selecione o contrato" className="w-full rounded-lg border-slate-200 bg-slate-50 text-sm text-slate-500" /><span className="text-xs text-[var(--ink-500)]">{editing ? 'Número atribuído pelo sistema.' : 'Prévia por contrato e ano. Número confirmado ao salvar.'}</span></Field>
                            <Field name="contract_id" label="Contrato de supervisão" error={form.errors.contract_id}><select {...propsFor('contract_id')} disabled={editing} onChange={(event) => { form.setData({ ...form.data, contract_id: event.target.value, highway_id: '', items: [] }); form.clearErrors(); }}><option value="">Selecione o contrato</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} — {contract.name}</option>)}</select></Field>
                            <Field name="supervision_lot" label="Lote de supervisão" error={form.errors.supervision_lot}><input {...propsFor('supervision_lot')} maxLength={120} placeholder="Ex.: Lote 01" /></Field>
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
                        <Field name="sei_reference" label="Referência SEI / processo (opcional)" error={form.errors.sei_reference}><input {...propsFor('sei_reference', false)} maxLength={255} /></Field>
                        <div className="space-y-2">{editing && order.attachments.length > 0 && <p className="text-sm text-slate-500">Anexos existentes preservados: {order.attachments.map((a) => a.original_name).join(", ")}</p>}<label htmlFor="os-attachments" className="inline-flex items-center gap-2 text-sm font-semibold"><Paperclip size={16} />{editing ? 'Adicionar anexos (opcional)' : 'Anexos (opcional)'}</label><input id="os-attachments" type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip" onChange={(event) => { form.setData('attachments', Array.from(event.target.files || [])); form.clearErrors(); }} className="block w-full rounded-lg border border-slate-300 p-2 text-sm" /><p className="text-xs text-[var(--ink-500)]">Até 10 arquivos de 10 MB cada. PDF, Word, Excel, imagens JPG/PNG ou ZIP.</p>{form.data.attachments.map((file, index) => <p key={`${index}-${file.name}`} className="break-all text-xs text-[var(--ink-500)]">{file.name} · {(file.size / 1024 / 1024).toFixed(2)} MB</p>)}</div>
                    </div>
                </section>

                <section className="sig-card" aria-labelledby="products-title">
                    <header className="border-b border-slate-200 px-5 py-4"><h2 id="products-title" className="text-lg font-semibold">02 · Produtos solicitados</h2></header>
                    <div className="space-y-4 p-5">
                        <OsDnitProductPicker key={form.data.contract_id} products={contractProducts} categories={categories} frequencies={frequencies} items={form.data.items} disabled={form.processing || !form.data.contract_id} onChange={(items) => { form.setData('items', items); form.clearErrors(); }} />
                        {form.errors.items && <p role="alert" className="text-sm text-red-700">{form.errors.items}</p>}
                        {form.data.items.length === 0 && <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-[var(--ink-500)]"><Package size={22} className="mx-auto mb-2" />Adicione pelo menos um produto e informe a quantidade solicitada.</div>}
                        <div className="divide-y divide-slate-100">{form.data.items.map((item, index) => {
                            const product = products.find((product) => product.id === item.product_id);
                            return <div key={item.product_id} className="flex flex-wrap items-center gap-4 py-4">
                                <div className="min-w-0 flex-1 basis-64"><h3 className="break-words text-sm font-semibold"><span className="mr-2 font-mono text-[var(--primary)]">{product.code}</span>{product.name}</h3><p className="mt-1 text-xs text-[var(--ink-500)]">{categories[product.category]} · Unidade: {product.unit} · {frequencies[product.frequency]}</p>{form.errors[`items.${index}.product_id`] && <p role="alert" className="mt-1 text-sm text-red-700">{form.errors[`items.${index}.product_id`]}</p>}</div>
                                <div className="w-36"><label htmlFor={`quantity-${item.product_id}`} className="mb-1 block text-xs font-semibold">Quantidade ({product.unit})</label><input id={`quantity-${item.product_id}`} type="number" required min="0.001" max="999999999999.999" step="0.001" value={item.quantity} onChange={(event) => form.setData('items', form.data.items.map((line, i) => i === index ? { ...line, quantity: event.target.value } : line))} className="w-full rounded-lg border-slate-300 text-sm" aria-invalid={Boolean(form.errors[`items.${index}.quantity`])} />{form.errors[`items.${index}.quantity`] && <p role="alert" className="mt-1 text-xs text-red-700">{form.errors[`items.${index}.quantity`]}</p>}</div>
                                <button type="button" aria-label={`Remover produto ${product.code}`} disabled={form.processing} onClick={() => { form.setData('items', form.data.items.filter((_, i) => i !== index)); form.clearErrors(); }} className="rounded-lg p-2 text-slate-500 hover:bg-red-50 hover:text-red-700"><X size={18} /></button>
                            </div>;
                        })}</div>
                    </div>
                </section>
                {form.progress && <p role="status" className="text-sm text-[var(--ink-500)]">Enviando arquivos: {form.progress.percentage}%</p>}
                <div className="flex flex-wrap justify-end gap-3"><Link href={route('tenant.os-dnit.orders.index', currentTenant.slug)} className="sig-btn sig-btn-secondary">Cancelar</Link><button type="submit" disabled={form.processing || unavailable || form.data.items.length === 0} className="sig-btn sig-btn-primary"><Save size={16} />{form.processing ? 'Salvando…' : editing ? 'Salvar alterações' : 'Salvar rascunho'}</button></div>
            </form>
        </div>
    </AuthenticatedLayout>;
}
