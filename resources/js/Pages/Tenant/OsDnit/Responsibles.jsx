import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import OsDnitTourButton from '@/Components/OsDnitTourButton';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Save, Users } from 'lucide-react';

export default function Responsibles({ contracts, roles, participants, responsibles, canManage }) {
    const { currentTenant, flash = {} } = usePage().props;
    const assignmentsFor = (id) => responsibles.filter((r) => String(r.contract_id) === String(id)
        && participants.some((p) => String(p.contract_id) === String(id) && p.user_id === r.user_id))
        .map(({ user_id, role }) => ({ user_id, role }));
    const first = contracts.length === 1 ? String(contracts[0].id) : '';
    const form = useForm({ contract_id: first, assignments: assignmentsFor(first) });
    const users = [...new Map(participants.filter((p) => String(p.contract_id) === form.data.contract_id).map((p) => [p.user_id, p.user])).values()];
    const toggle = (user_id, role) => form.setData('assignments', form.data.assignments.some((a) => a.user_id === user_id && a.role === role)
        ? form.data.assignments.filter((a) => a.user_id !== user_id || a.role !== role) : [...form.data.assignments, { user_id, role }]);
    return <AuthenticatedLayout><Head title="OS DNIT · Responsáveis" /><div className="sig-content os-dnit-page space-y-6">
        <header className="flex flex-wrap items-start justify-between gap-4"><div><div className="eyebrow">OS DNIT</div><h1 className="mt-2 text-2xl font-semibold">Responsáveis</h1><p className="mt-2 text-sm text-[var(--ink-500)]">Defina quem solicita, analisa e autoriza os serviços em cada contrato. Somente usuários com vínculo ativo podem atuar.</p></div><OsDnitTourButton tenantSlug={currentTenant.slug} /></header>
        {flash.success && <p role="status" className="rounded-lg bg-emerald-50 p-4 text-emerald-800">{flash.success}</p>}
        {Object.keys(form.errors).length > 0 && <div role="alert" className="rounded-lg bg-red-50 p-4 text-sm text-red-800">{Object.values(form.errors).map((e, i) => <p key={i}>{e}</p>)}</div>}
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('tenant.os-dnit.responsibles.store', currentTenant.slug), { preserveScroll: true }); }} className="space-y-5">
            <div className="sig-card p-5"><label htmlFor="responsible-contract" className="mb-2 block text-sm font-semibold">Contrato</label><select id="responsible-contract" required value={form.data.contract_id} disabled={form.processing} onChange={(e) => { form.setData({ contract_id: e.target.value, assignments: assignmentsFor(e.target.value) }); form.clearErrors(); }} className="w-full rounded-lg border-slate-300 text-sm"><option value="">Selecione o contrato</option>{contracts.map((c) => <option key={c.id} value={c.id}>{c.code} — {c.name}</option>)}</select></div>
            {form.data.contract_id && <div className="grid gap-4 lg:grid-cols-3">{Object.entries(roles).map(([role, label]) => <fieldset key={role} className="sig-card min-w-0 space-y-3 p-5"><legend className="sr-only">{label}</legend><h2 className="flex items-center gap-2 font-semibold"><Users size={17} className="shrink-0" />{label}</h2>{users.map((u) => <label key={u.id} className="flex items-center gap-3 rounded-lg border border-slate-100 p-3 text-sm"><input type="checkbox" checked={form.data.assignments.some((a) => a.user_id === u.id && a.role === role)} disabled={!canManage || form.processing} onChange={() => toggle(u.id, role)} className="shrink-0 rounded border-slate-300 text-blue-600" /><span className="min-w-0 break-words">{u.name}</span></label>)}</fieldset>)}</div>}
            {!canManage && <p className="text-sm text-[var(--ink-500)]">Um administrador vinculado ao contrato pode alterar os responsáveis.</p>}
            {canManage && <div className="flex justify-end"><button className="sig-btn sig-btn-primary" disabled={!form.data.contract_id || form.processing}><Save size={16} />Salvar responsáveis</button></div>}
        </form>
    </div></AuthenticatedLayout>;
}
