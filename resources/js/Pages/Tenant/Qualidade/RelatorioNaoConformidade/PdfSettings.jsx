import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Check, RotateCcw, Save, Settings2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Errors } from '../InstrucaoCampo/Ui';
import PdfModelPreview from './PdfModelPreview';

const logoOptions = [['contratante', 'Contratante da RNC'], ['contratada', 'Contratada da RNC'], ['fiscalizadora', 'Fiscalizadora / gerenciadora do contrato'], ['none', 'Sem logotipo']];

export default function PdfSettings({ tenant, contracts, templates, settings, defaults }) {
    const initial = contracts[0]?.id || '';
    const configuration = (id) => settings[id] || { layout: 'standard', options: { ...defaults.standard } };
    const form = useForm({ contract_id: initial, ...configuration(initial) });
    const [saved, setSaved] = useState(false);
    useEffect(() => { setSaved(false); }, [form.data]);
    const option = (key, value) => form.setData('options', { ...form.data.options, [key]: value });
    const changeLayout = (layout) => form.setData({ ...form.data, layout, options: {
        ...form.data.options, title: defaults[layout].title, color: defaults[layout].color,
    } });

    return <AuthenticatedLayout><Head title="Parametrização da RNC" />
        <div className="mx-auto grid w-full max-w-6xl gap-6 p-4 sm:p-6">
            <header><span className="eyebrow flex items-center gap-2"><Settings2 size={15} />Qualidade / RNC</span><h1 className="mt-2 text-2xl font-semibold">Parametrização</h1><p className="mt-2 text-sm text-[var(--ink-500)]">Escolha o modelo do PDF para cada contrato. Sem configuração, o sistema usa o modelo padrão.</p></header>
            {!contracts.length ? <div className="sig-card p-6 text-sm text-[var(--ink-500)]">Nenhum contrato disponível para parametrização com o seu acesso.</div> : <form className="grid gap-5" onSubmit={(event) => {
                event.preventDefault(); form.post(route('tenant.qualidade.rnc.settings.store', tenant.slug), { preserveScroll: true, onSuccess: () => setSaved(true) });
            }}>
                <Errors errors={form.errors} />
                <fieldset disabled={form.processing} className="grid min-w-0 gap-5 disabled:opacity-75">
                    <section className="sig-card p-5"><label className="grid gap-2 text-sm font-medium">Contrato<select className="sig-input w-full" value={form.data.contract_id} onChange={(event) => { const id = event.target.value; form.setData({ contract_id: id, ...configuration(id) }); form.clearErrors(); }} required>{contracts.map((contract) => <option key={contract.id} value={contract.id}>{contract.code} · {contract.name}</option>)}</select></label></section>
                    <section className="sig-card p-5"><h2 className="text-base font-semibold">Modelo do documento</h2><div className="mt-4 grid gap-4 sm:grid-cols-2">{templates.map((template) => {
                        const selected = form.data.layout === template.value;
                        return <article key={template.value} className={`relative min-w-0 rounded-xl border-2 p-4 transition ${selected ? 'border-[var(--primary)] bg-blue-50/40' : 'border-[var(--border)] hover:border-blue-200'}`}>
                            <label className="flex cursor-pointer items-start gap-3"><input type="radio" name="pdf-layout" value={template.value} checked={selected} onChange={() => changeLayout(template.value)} className="mt-1" /><span className="min-w-0 flex-1"><span className="block text-sm font-semibold">{template.name}</span><span className="mt-1 block text-xs leading-5 text-[var(--ink-500)]">{template.description}</span></span>{selected && <Check size={17} className="shrink-0 text-[var(--primary)]" />}</label>
                            <PdfModelPreview name={template.name} endpoint={route('tenant.qualidade.rnc.settings.preview', tenant.slug)} payload={{ ...form.data, layout: template.value, options: { ...form.data.options, ...(!selected ? { title: defaults[template.value].title, color: defaults[template.value].color } : {}) } }} />
                        </article>;
                    })}</div></section>
                    <section className="sig-card grid gap-5 p-5"><h2 className="text-base font-semibold">Personalização do contrato</h2>
                        <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_180px]"><label className="grid gap-2 text-sm font-medium">Título do documento<input className="sig-input w-full" value={form.data.options.title} maxLength={180} onChange={(event) => option('title', event.target.value)} /></label><label className="grid gap-2 text-sm font-medium">Cor dos títulos<input type="color" className="h-10 w-full cursor-pointer rounded border border-[var(--border)] bg-white p-1" value={form.data.options.color} onChange={(event) => option('color', event.target.value)} /></label></div>
                        <div className="grid gap-4 sm:grid-cols-2">{[['left_logo', 'Logotipo à esquerda'], ['right_logo', 'Logotipo à direita']].map(([key, label]) => <label key={key} className="grid gap-2 text-sm font-medium">{label}<select className="sig-input w-full" value={form.data.options[key]} onChange={(event) => option(key, event.target.value)}>{logoOptions.map(([value, name]) => <option key={value} value={value}>{name}</option>)}</select></label>)}</div>
                        <p className="-mt-2 text-xs text-[var(--ink-500)]">Os logotipos vêm do cadastro das empresas. Se não houver imagem, o PDF mostra o nome da empresa.</p>
                        {form.data.layout === 'inspection_form' && <div className="grid gap-4 sm:grid-cols-2"><label className="grid gap-2 text-sm font-medium">Tipo de obra (opcional)<select className="sig-input w-full" value={form.data.options.work_type} onChange={(e) => option('work_type', e.target.value)}><option value="">Sem marcação</option><option>Pista Nova</option><option>Pista Existente</option></select></label><label className="grid gap-2 text-sm font-medium">Tipo de pavimento (opcional)<select className="sig-input w-full" value={form.data.options.pavement_type} onChange={(e) => option('pavement_type', e.target.value)}><option value="">Sem marcação</option><option>Rígido</option><option>Flexível</option></select></label></div>}
                        <label className="grid gap-2 text-sm font-medium">Observações fixas do contrato (opcional)<textarea className="sig-input min-h-32 w-full" rows={5} maxLength={10000} value={form.data.options.contract_notes} onChange={(event) => option('contract_notes', event.target.value)} placeholder="Ex.: orientações de qualidade ou referências do contrato que devem aparecer em todas as RNCs." /></label>
                    </section>
                    <div className="rounded-xl bg-blue-50 p-4 text-xs leading-5 text-blue-900">A configuração vale para os próximos PDFs gerados deste contrato, em todas as etapas. Arquivos já enviados para assinatura e PDFs assinados mantêm a versão original.</div>
                    <div className="flex flex-wrap items-center justify-between gap-3"><button type="button" className="sig-btn sig-btn-secondary" onClick={() => form.setData({ ...form.data, options: { ...defaults[form.data.layout] } })}><RotateCcw size={15} />Restaurar opções do modelo</button><button type="submit" className="sig-btn sig-btn-primary"><Save size={16} />{form.processing ? 'Salvando...' : 'Salvar para este contrato'}</button></div>
                </fieldset>
                {saved && <p role="status" className="text-sm text-green-700">Modelo de PDF salvo para o contrato selecionado.</p>}
            </form>}
        </div>
    </AuthenticatedLayout>;
}
