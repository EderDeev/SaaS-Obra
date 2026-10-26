import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Check, RotateCcw, Save, Settings2 } from 'lucide-react';
import { useState } from 'react';
import { Errors } from '../InstrucaoCampo/Ui';
import PdfModelPreview from './PdfModelPreview';
import LogoUploadField from './LogoUploadField';
import ModelFieldsEditor from './ModelFieldsEditor';

const emptyUploads = { left_logo: null, right_logo: null, remove_left_logo: false, remove_right_logo: false };

export default function PdfSettings({ tenant, contracts, templates, settings, defaults, defaultFields = {}, logos = {} }) {
    const initial = contracts[0]?.id || '';
    const configuration = (id) => settings[id] || { layout: 'standard', options: { ...defaults.standard }, field_definitions: defaultFields.standard || [] };
    const form = useForm({ contract_id: initial, ...configuration(initial), ...emptyUploads });
    const [saved, setSaved] = useState(false);
    const setData = (...args) => { setSaved(false); form.setData(...args); };
    const option = (key, value) => setData('options', { ...form.data.options, [key]: value });
    const fieldsForLayout = (layout) => layout === form.data.layout ? form.data.field_definitions : [...(defaultFields[layout] || []), ...form.data.field_definitions.filter(field => !['work_type', 'pavement_type'].includes(field.key))];
    const changeLayout = (layout) => setData({ ...form.data, layout, field_definitions: fieldsForLayout(layout), options: {
        ...form.data.options, title: defaults[layout].title, color: defaults[layout].color,
    } });
    function uploadLogo(side, file) {
        const key = `${side}_logo`;
        if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) { form.setError(key, 'Envie uma imagem PNG, JPG ou WebP.'); return; }
        if (file.size > 4 * 1024 * 1024) { form.setError(key, 'A imagem pode ter até 4 MB.'); return; }
        form.clearErrors(key);
        setData({ ...form.data, [key]: file, [`remove_${key}`]: false });
    }

    return <AuthenticatedLayout><Head title="Parametrização da RNC" />
        <div className="mx-auto grid w-full max-w-6xl gap-6 p-4 sm:p-6">
            <header><span className="eyebrow flex items-center gap-2"><Settings2 size={15} />Qualidade / RNC</span><h1 className="mt-2 text-2xl font-semibold">Parametrização</h1><p className="mt-2 text-sm text-[var(--ink-500)]">Escolha o modelo do PDF para cada contrato. Sem configuração, o sistema usa o modelo padrão.</p></header>
            {!contracts.length ? <div className="sig-card p-6 text-sm text-[var(--ink-500)]">Nenhum contrato disponível para parametrização com o seu acesso.</div> : <form className="grid gap-5" onSubmit={(event) => {
                event.preventDefault();
                const id = form.data.contract_id;
                form.transform(({ field_definitions, ...data }) => ({ ...data, fields_json: JSON.stringify(field_definitions) })).post(route('tenant.qualidade.rnc.settings.store', tenant.slug), { forceFormData: true, preserveScroll: true, onSuccess: (page) => {
                    form.setData({ contract_id: id, ...(page.props.settings[id] || configuration(id)), ...emptyUploads });
                    setSaved(true);
                } });
            }}>
                <Errors errors={form.errors} />
                <fieldset disabled={form.processing} className="grid min-w-0 gap-5 disabled:opacity-75">
                    <section className="sig-card p-5"><label className="grid gap-2 text-sm font-medium">Contrato<select className="sig-input w-full" value={form.data.contract_id} onChange={(event) => { const id = event.target.value; setData({ contract_id: id, ...configuration(id), ...emptyUploads }); form.clearErrors(); }} required>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} · {contract.name}</option>)}</select></label></section>
                    <section className="sig-card p-5"><h2 className="text-base font-semibold">Modelo do documento</h2><div className="mt-4 grid gap-4 sm:grid-cols-2">{templates.map((template) => {
                        const selected = form.data.layout === template.value;
                        return <article key={template.value} className={`relative min-w-0 rounded-xl border-2 p-4 transition ${selected ? 'border-[var(--primary)] bg-blue-50/40' : 'border-[var(--border)] hover:border-blue-200'}`}>
                            <label className="flex cursor-pointer items-start gap-3"><input type="radio" name="pdf-layout" value={template.value} checked={selected} onChange={() => changeLayout(template.value)} className="mt-1" /><span className="min-w-0 flex-1"><span className="block text-sm font-semibold">{template.name}</span><span className="mt-1 block text-xs leading-5 text-[var(--ink-500)]">{template.description}</span></span>{selected && <Check size={17} className="shrink-0 text-[var(--primary)]" />}</label>
                            <PdfModelPreview name={template.name} endpoint={route('tenant.qualidade.rnc.settings.preview', tenant.slug)} payload={{ ...form.data, field_definitions: fieldsForLayout(template.value), layout: template.value, options: { ...form.data.options, ...(!selected ? { title: defaults[template.value].title, color: defaults[template.value].color } : {}) } }} />
                        </article>;
                    })}</div></section>
                    <section className="sig-card grid gap-5 p-5"><h2 className="text-base font-semibold">Personalização do contrato</h2>
                        <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_180px]"><label className="grid gap-2 text-sm font-medium">Título do documento<input className="sig-input w-full" value={form.data.options.title} maxLength={180} onChange={(event) => option('title', event.target.value)} /></label><label className="grid gap-2 text-sm font-medium">Cor dos títulos<input type="color" className="h-10 w-full cursor-pointer rounded border border-[var(--border)] bg-white p-1" value={form.data.options.color} onChange={(event) => option('color', event.target.value)} /></label></div>
                        <div className="grid gap-4 sm:grid-cols-2">{[['left', 'Logo à esquerda'], ['right', 'Logo à direita']].map(([side, label]) => <LogoUploadField key={`${form.data.contract_id}-${side}`} label={label} file={form.data[`${side}_logo`]} current={logos[form.data.contract_id]?.[side]} removed={form.data[`remove_${side}_logo`]} error={form.errors[`${side}_logo`]} onChange={(file) => uploadLogo(side, file)} onRemove={() => setData({ ...form.data, [`${side}_logo`]: null, [`remove_${side}_logo`]: true })} onUndo={() => setData(`remove_${side}_logo`, false)} />)}</div>
                        <p className="-mt-2 text-xs text-[var(--ink-500)]">Cada nova RNC guarda as logos configuradas neste contrato no momento da criação. Alterar ou remover essas imagens não muda as logos das RNCs anteriores, em nenhuma etapa. A prévia mostra as alterações antes de salvar.</p>
                    </section>
                    <ModelFieldsEditor fields={form.data.field_definitions} errors={form.errors} onChange={(fields) => setData('field_definitions', fields)} />
                    <div className="rounded-xl bg-blue-50 p-4 text-xs leading-5 text-blue-900">Cada RNC preserva o modelo, os campos e as logos da sua criação. Alterações nesta parametrização valem somente para novas RNCs. Os PDFs enviados para assinatura continuam intactos.</div>
                    <div className="flex flex-wrap items-center justify-between gap-3"><button type="button" className="sig-btn sig-btn-secondary" onClick={() => setData({ ...form.data, options: { ...defaults[form.data.layout] } })}><RotateCcw size={15} />Restaurar opções do modelo</button><button type="submit" className="sig-btn sig-btn-primary"><Save size={16} />{form.processing ? 'Salvando...' : 'Salvar para este contrato'}</button></div>
                </fieldset>
                {saved && <p role="status" className="text-sm text-green-700">Modelo de PDF salvo para o contrato selecionado.</p>}
            </form>}
        </div>
    </AuthenticatedLayout>;
}
