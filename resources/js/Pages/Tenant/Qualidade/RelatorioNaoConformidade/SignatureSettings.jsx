import { useForm } from '@inertiajs/react';
import { Save } from 'lucide-react';
import { useState } from 'react';
import { Errors } from '../InstrucaoCampo/Ui';

export default function SignatureSettings({ tenant, contracts, usersByContract, signatureRoles, signatureSettings }) {
    const initial = contracts[0]?.id || '';
    const values = (id) => Object.fromEntries(Object.keys(signatureRoles).map((role) => [role, signatureSettings[id]?.[role] || '']));
    const form = useForm({ contract_id: initial, signers: values(initial) });
    const [saved, setSaved] = useState(false);
    return <section className="sig-card min-w-0 p-4 sm:p-5 2xl:col-span-2">
        <h2 className="text-lg font-semibold">Responsáveis pelas assinaturas</h2>
        <p className="mt-1 text-sm text-[var(--ink-500)]">Escolha quais partes assinarão o documento final da RNC. Deixe sem responsável as partes que não precisam assinar.</p>
        <form className="mt-5 grid gap-4" onSubmit={(event) => { event.preventDefault(); setSaved(false); form.post(route('tenant.qualidade.rnc.responsaveis.signatures', tenant.slug), { preserveScroll: true, onSuccess: () => setSaved(true) }); }}>
            <Errors errors={form.errors} />
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <label className="grid min-w-0 gap-2 text-sm font-medium">Contrato<select className="sig-input w-full" value={form.data.contract_id} onChange={(event) => { const id = event.target.value; form.setData({ contract_id: id, signers: values(id) }); setSaved(false); }} required disabled={form.processing}><option value="">Selecione</option>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} · {contract.name}</option>)}</select></label>
                {Object.entries(signatureRoles).map(([role, label]) => <label key={role} className="grid min-w-0 gap-2 text-sm font-medium">{label}<select className="sig-input w-full" value={form.data.signers[role] || ''} disabled={!form.data.contract_id || form.processing} onChange={(event) => { form.setData('signers', { ...form.data.signers, [role]: event.target.value }); setSaved(false); }}><option value="">Não solicitar assinatura desta parte</option>{(usersByContract[form.data.contract_id] || []).map((user) => <option key={user.id} value={user.id}>{user.name} · {user.email}</option>)}</select></label>)}
            </div>
            <div className="flex flex-wrap items-center justify-end gap-3">{saved && <p role="status" className="text-sm text-green-700">Responsáveis pelas assinaturas salvos.</p>}<button type="submit" disabled={form.processing || !form.data.contract_id} className="sig-btn sig-btn-primary"><Save size={16} />{form.processing ? 'Salvando...' : 'Salvar assinaturas'}</button></div>
        </form>
    </section>;
}
