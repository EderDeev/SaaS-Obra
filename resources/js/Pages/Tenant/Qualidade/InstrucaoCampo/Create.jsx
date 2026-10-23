import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import { useState } from 'react';
import { Card, Errors } from './Ui';
import PhotoRegister from './PhotoRegister';

export default function Create({ tenant, ic, contracts, obras, empresas, disciplinas }) {
    const today = `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}-${String(new Date().getDate()).padStart(2, '0')}`;
    const form = useForm({ _method: ic ? 'patch' : 'post', contract_id: ic?.contract_id || contracts[0]?.id || '', obra_id: ic?.obra_id || '', disciplina_id: ic?.disciplina_id || '', contratante_empresa_id: ic?.contratante_empresa_id || '', contratada_empresa_id: ic?.contratada_empresa_id || '', opened_at: ic?.opened_at?.slice(0, 10) || today, assunto: ic?.assunto || '', gravidade: ic?.gravidade || 'Leve', instrucao: ic?.instrucao || '', photos: [], photo_comments: [], remove_photos: [] });
    const [selectedPhotos, setSelectedPhotos] = useState(() => (ic?.photos || []).map((photo) => ({
        id: `existing-${photo.id}`,
        existingId: photo.id,
        previewUrl: route('tenant.qualidade.ic.photo', [tenant.slug, ic.id, photo.id]),
        originalName: photo.original_name,
        comment: photo.comment || '',
    })));
    const options = (items, field = 'nome') => items.filter((item) => String(item.contract_id) === String(form.data.contract_id)).map((item) => <option key={item.id} value={item.id}>{item.codigo ? `${item.codigo} · ` : ''}{item[field]}</option>);
    const input = (name, label, type = 'text', extra = {}) => <label className="grid content-start gap-2 text-sm font-medium">{label}<input className="sig-input w-full min-w-0" type={type} value={form.data[name]} onChange={(e) => form.setData(name, e.target.value)} {...extra} /></label>;
    const select = (name, label, children) => <label className="grid content-start gap-2 text-sm font-medium">{label}<select className="sig-input w-full min-w-0" value={form.data[name]} required onChange={(e) => form.setData(name, e.target.value)}><option value="">Selecione</option>{children}</select></label>;
    const submit = (event) => {
        event.preventDefault();
        const newPhotos = selectedPhotos.filter((photo) => photo.file);
        form.transform((data) => ({
            ...data,
            photos: newPhotos.map((photo) => photo.file),
            photo_comments: newPhotos.map((photo) => photo.comment),
            photo_positions: newPhotos.map((photo) => selectedPhotos.indexOf(photo) + 1),
            existing_photos: selectedPhotos.filter((photo) => photo.existingId).map((photo) => ({ id: photo.existingId, comment: photo.comment, position: selectedPhotos.indexOf(photo) + 1 })),
            remove_photos: (ic?.photos || []).filter((photo) => !selectedPhotos.some((item) => item.existingId === photo.id)).map((photo) => photo.id),
        }));
        form.post(route(ic ? 'tenant.qualidade.ic.update' : 'tenant.qualidade.ic.store', ic ? [tenant.slug, ic.id] : tenant.slug), { forceFormData: true });
    };
    return <AuthenticatedLayout><Head title={ic ? `Editar IC ${ic.formatted_number}` : 'Nova Instrução de Campo'} /><main className="sig-content mx-auto grid w-full max-w-6xl gap-5">
        <header className="flex flex-wrap items-center justify-between gap-4"><div><Link href={route('tenant.qualidade.ic.index', tenant.slug)} className="mb-3 inline-flex items-center gap-2 text-sm text-[var(--ink-500)]"><ArrowLeft size={15} />Instruções de Campo</Link><h1 className="text-2xl font-semibold">{ic ? `Editar IC ${ic.formatted_number}` : 'Nova Instrução de Campo'}</h1><p className="mt-1 text-sm text-[var(--ink-500)]">Registre a instrução e as evidências encontradas em campo.</p></div></header>
        <Errors errors={form.errors} /><form className="grid gap-5" onSubmit={submit}>
        <Card title="Identificação"><div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <label className="grid gap-2 text-sm font-medium">Contrato<select className="sig-input min-w-0" required disabled={Boolean(ic)} value={form.data.contract_id} onChange={(e) => form.setData({ ...form.data, contract_id: e.target.value, obra_id: '', disciplina_id: '', contratante_empresa_id: '', contratada_empresa_id: '' })}><option value="">Selecione</option>{contracts.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}</select></label>
            {select('obra_id', 'Obra', options(obras))}{input('opened_at', 'Data de abertura', 'date', { required: true })}
            {select('contratante_empresa_id', 'Contratante / cliente', options(empresas))}{select('contratada_empresa_id', 'Construtora', options(empresas))}{select('disciplina_id', 'Disciplina', options(disciplinas))}
            {select('gravidade', 'Gravidade', ['Leve', 'Média', 'Grave', 'Gravíssima'].map((value) => <option key={value}>{value}</option>))}
        </div></Card>
        <Card title="Assunto"><label className="grid gap-2 text-sm font-medium">Assunto<input className="sig-input w-full" value={form.data.assunto} onChange={(e) => form.setData('assunto', e.target.value)} required maxLength={255} placeholder="Resuma o motivo desta IC" /></label></Card>
        <Card title="Instrução"><label className="grid gap-2 text-sm font-medium" htmlFor="ic-instrucao">Instrução<textarea id="ic-instrucao" className="sig-input min-h-44 w-full resize-y" value={form.data.instrucao} onChange={(e) => form.setData('instrucao', e.target.value)} required maxLength={10000} placeholder="Descreva a situação encontrada e oriente o que deve ser corrigido." /></label></Card>
        <PhotoRegister photos={selectedPhotos} onChange={setSelectedPhotos} errors={form.errors} disabled={form.processing} />
        <footer className="flex flex-wrap justify-end gap-2"><Link className="sig-btn sig-btn-secondary" href={route('tenant.qualidade.ic.index', tenant.slug)}>Cancelar</Link><button disabled={form.processing || !contracts.length} className="sig-btn sig-btn-primary"><Save size={16} />{form.processing ? 'Salvando…' : 'Salvar rascunho'}</button></footer>
        </form></main></AuthenticatedLayout>;
}
