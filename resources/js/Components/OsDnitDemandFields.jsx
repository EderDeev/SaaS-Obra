import { Field } from '@/Components/OsDnitOrderUi';
import OsDnitKilometerInput from '@/Components/OsDnitKilometerInput';
import { Paperclip } from 'lucide-react';

// Shared by the live form and the tutorial so labels, order and layout stay aligned.
export default function OsDnitDemandFields({ form, editing, order, numberPreviews, contracts, contractLots, contractHighways, selectedLot, propsFor }) {
    return (
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
                        <div className="space-y-2">{editing && order.attachments.length > 0 && <p className="text-sm text-slate-500">Anexos existentes preservados: {order.attachments.map((a) => a.original_name).join(", ")}</p>}<label htmlFor="os-attachments" className="inline-flex items-center gap-2 text-sm font-semibold"><Paperclip size={16} />{editing ? 'Adicionar anexos (opcional)' : 'Anexos (opcional)'}</label><input id="os-attachments" type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip" onChange={(event) => { form.setData('attachments', Array.from(event.target.files || [])); form.clearErrors(); }} className="block w-full rounded-lg border border-slate-300 p-2 text-sm" /><p className="text-xs text-[var(--ink-500)]">Até 10 arquivos de 30 MB cada. PDF, Word, Excel, imagens JPG/PNG ou ZIP.</p>{form.data.attachments.map((file, index) => <p key={`${index}-${file.name}`} className="break-all text-xs text-[var(--ink-500)]">{file.name} · {(file.size / 1024 / 1024).toFixed(2)} MB</p>)}</div>
                    </div>
                </section>
    );
}
