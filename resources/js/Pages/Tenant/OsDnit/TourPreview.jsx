import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import OsDnitTour from '@/Components/OsDnitTour';
import { SupervisionAnalysis, StatusBadge } from '@/Components/OsDnitWorkflow';
import { osDnitTourSteps, tourSections } from '@/Support/osDnitTour';
import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Check, CheckCircle2, ClipboardList, Download, Eye, LockKeyhole, Paperclip, Plane, Plus, Save, Search, Send, ShieldCheck, Users, X } from 'lucide-react';

// Exemplos locais: o tour não usa IDs, formulários ou endpoints de gravação reais.
const requested = [
    { product_id: 'demo-18e', product_code: '18E', product_name: 'Levantamentos Topográficos em Ponto Localizado', quantity: 2, unit: 'unid' },
    { product_id: 'demo-21g', product_code: '21G', product_name: 'Elaboração de Projetos Básico e Executivo de Pontos Críticos', quantity: 1, unit: 'unid' },
];
const reviewed = [{ ...requested[0], quantity: 3 }, requested[1]];
const statuses = { draft: 'Demanda', awaiting_concremat: 'Em análise Supervisão', awaiting_contract_inspector: 'Aguardando fiscal do contrato', approved: 'Aprovada' };
const comment = 'Recomendo três levantamentos para contemplar os pontos adicionais identificados no trecho.';

function Field({ label, value, type = 'text', multiline = false, children }) {
    return <label className="block min-w-0 text-xs font-semibold text-slate-700"><span>{label}</span>{children || (multiline
        ? <textarea readOnly rows={3} value={value} className="mt-2 w-full rounded-lg border-slate-300 text-sm font-normal" />
        : <input readOnly type={type} value={value} className="mt-2 w-full rounded-lg border-slate-300 text-sm font-normal" />)}</label>;
}

function PageHeader({ title, description, children }) {
    return <header className="flex flex-wrap items-start justify-between gap-4"><div><div className="eyebrow">Ordem de Serviço</div><h1 className="mt-2 text-2xl font-semibold">{title}</h1><p className="mt-2 max-w-3xl text-sm text-slate-500">{description}</p></div>{children}</header>;
}

function DemoButton({ children, primary = false, ...props }) {
    return <button type="button" className={`sig-btn ${primary ? 'sig-btn-primary' : 'sig-btn-secondary'}`} {...props}>{children}</button>;
}

function RoleCard({ title, target, selected }) {
    return <fieldset data-tour={`dnit-${target}`} className="sig-card min-w-0 space-y-3 p-5"><legend className="sr-only">{title}</legend><h2 className="flex items-center gap-2 text-sm font-semibold"><Users size={17} />{title}</h2>{['Ana Silva', 'Beatriz Costa', 'Carlos Lima'].map(name => <label key={name} className="flex items-center gap-3 rounded-lg border border-slate-100 p-3 text-sm"><input type="checkbox" checked={name === selected} readOnly className="shrink-0 rounded border-slate-300 text-blue-600" /><span>{name}</span></label>)}</fieldset>;
}

function Responsibles() {
    return <><PageHeader title="Responsáveis" description="Defina quem solicita, analisa e autoriza os serviços em cada contrato." />
        <section data-tour="dnit-contract" className="sig-card p-5"><Field label="Contrato"><select value="demo" disabled className="mt-2 w-full rounded-lg border-slate-300 text-sm"><option value="demo">CT-DEMO/2026 — Contrato de demonstração</option></select></Field></section>
        <div data-tour="dnit-roles" className="grid gap-4 lg:grid-cols-3"><RoleCard title="Analista DNIT" target="analyst" selected="Ana Silva" /><RoleCard title="Supervisão" target="concremat-role" selected="Beatriz Costa" /><RoleCard title="Fiscal do contrato" target="inspector-role" selected="Carlos Lima" /></div>
        <div className="flex justify-end"><DemoButton data-tour="dnit-save-responsibles" primary><Save size={16} />Salvar responsáveis</DemoButton></div>
    </>;
}

function Catalog({ modal = false }) {
    return <><PageHeader title="Produtos" description="Catálogo de produtos para as ordens de serviço do DNIT."><DemoButton data-tour="dnit-new-product" primary><Plus size={16} />Novo produto</DemoButton></PageHeader>
        {modal ? <ModalPreview title="Novo produto"><div data-tour="dnit-product-fields" className="space-y-5"><Field label="Contrato" value="CT-DEMO/2026 — Contrato de demonstração" /><div className="grid gap-4 sm:grid-cols-[120px_minmax(0,1fr)]"><Field label="Código" value="02" /><Field label="Descrição" value="Controle de Obras" /></div><div className="grid gap-4 sm:grid-cols-3"><Field label="Categoria" value="Gestão e controle" /><Field label="Unidade" value="mês" /><Field label="Frequência" value="Mensal" /></div><div className="flex justify-end"><DemoButton primary><Save size={15} />Salvar produto</DemoButton></div></div></ModalPreview>
        : <section data-tour="dnit-catalog" className="space-y-4"><div className="sig-card grid gap-4 p-4 sm:grid-cols-3"><Field label="Contrato" value="CT-DEMO/2026" /><Field label="Buscar produto" value="" ><div className="mt-2 flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 font-normal text-slate-500"><Search size={16} />Código ou descrição</div></Field><Field label="Categoria" value="Todas as categorias" /></div>
            {[{ product_code: '02', product_name: 'Controle de Obras', unit: 'mês', category: 'Gestão e controle', frequency: 'Mensal' }, ...requested.map(item => ({ ...item, category: item.product_code === '21G' ? 'Projetos e inspeções' : 'Levantamentos e estudos', frequency: 'Por demanda' }))].map(item => <article key={item.product_code} className="sig-card flex flex-wrap items-center justify-between gap-3 p-5"><div className="min-w-0"><h2 className="break-words text-sm font-semibold"><span className="mr-2 font-mono text-blue-700">{item.product_code}</span>{item.product_name}</h2><p className="mt-2 text-xs text-slate-500">{item.category} · Unidade: {item.unit} · {item.frequency}</p></div><span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{item.frequency}</span></article>)}</section>}
    </>;
}

function ProductLines({ items = requested, review = false }) {
    return <div className="divide-y divide-slate-100">{items.map(item => <div key={item.product_id} className="flex flex-wrap items-center gap-4 py-4"><div className="min-w-0 flex-1 basis-52"><h3 className="break-words text-sm font-semibold"><span className="mr-2 font-mono text-blue-700">{item.product_code}</span>{item.product_name}</h3><p className="mt-1 text-xs text-slate-500">Por demanda · Unidade: {item.unit}</p></div><div className="w-32"><Field label={`Quantidade (${item.unit})`} value={item.quantity} /></div></div>)}</div>;
}

function CreateRequest() {
    return <><PageHeader title="Criar OS" description="Preencha a demanda. A Supervisão definirá os produtos na análise." />
        <div data-tour="dnit-request-fields" className="space-y-6"><section data-tour="dnit-identification" className="sig-card space-y-5 p-5"><h2 className="font-semibold">Identificação da demanda</h2><div className="grid gap-4 sm:grid-cols-3"><Field label="Número da solicitação" value="001/2026" /><Field label="Contrato de supervisão" value="CT-DEMO/2026" /><Field label="Lote de supervisão" value="Lote 01" /></div><div className="grid gap-4 sm:grid-cols-3"><Field label="Rodovia" value="BR-364/AC" /><Field label="Km inicial" value="4,76" /><Field label="Km final" value="4,98" /></div></section>
        <section data-tour="dnit-request-details" className="sig-card space-y-5 p-5"><div className="grid gap-4 sm:grid-cols-2"><Field label="Assunto da solicitação" value="Levantamento topográfico do trecho" /><Field label="Prazo pretendido" type="date" value="2026-11-10" /></div><Field label="Descrição da necessidade técnica" multiline value="Realizar levantamento topográfico dos pontos críticos para subsidiar o projeto de recuperação do trecho." /><Field label="Justificativa / resultado esperado" multiline value="Obter informações de campo para definir a intervenção e dimensionar o serviço com segurança." /><Field label="Referência SEI / processo" value="Processo de exemplo" /><p className="flex items-center gap-2 text-sm text-slate-600"><Paperclip size={16} />Anexos · croqui-do-trecho.pdf</p></section>
        </div>
        <div className="flex justify-end"><DemoButton data-tour="dnit-save-draft" primary><Save size={16} />Salvar demanda</DemoButton></div>
    </>;
}

function ModalPreview({ title, children }) {
    return <section aria-label={title} className="relative isolate overflow-hidden rounded-2xl border border-slate-200 bg-slate-200/60 p-3 sm:p-7"><div className="pointer-events-none absolute inset-0 space-y-5 p-6 opacity-30 blur-sm" aria-hidden="true"><div className="h-12 rounded-lg bg-white" /><div className="h-40 rounded-xl bg-white" /><div className="h-40 rounded-xl bg-white" /></div><div className="relative overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl"><header className="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4"><h2 className="font-semibold">{title}</h2><X size={17} className="shrink-0 text-slate-400" aria-hidden="true" /></header><div className="space-y-5 p-4 sm:p-5">{children}</div></div></section>;
}

function Submission({ confirmation = false }) {
    return <><PageHeader title="Ordem de Serviço" description="Solicitações, análise e autorização dos serviços por contrato." />
        {confirmation ? <ModalPreview title="Enviar OS para análise?"><div data-tour="dnit-confirmation" className="space-y-5"><p className="text-sm leading-relaxed text-slate-600">Deseja enviar a OS 001/2026 à Supervisão? Após o envio, a solicitação ficará bloqueada para edição.</p><div className="flex flex-wrap justify-end gap-3"><DemoButton>Cancelar</DemoButton><DemoButton primary><Send size={15} />Confirmar envio</DemoButton></div></div></ModalPreview>
        : <><div className="flex flex-wrap gap-2">{['Todas as OSs', 'Demanda (Analista DNIT)', 'Análise Supervisão', 'Fiscal do Contrato', 'Finalizadas'].map((label,i) => <span key={label} className={`rounded-lg border px-3 py-2 text-xs font-semibold ${i === 1 ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 bg-white'}`}>{label}</span>)}</div><article className="sig-card space-y-5 p-5"><div className="flex flex-wrap items-start justify-between gap-3"><div><span className="font-mono text-xs font-semibold text-blue-700">OS 001/2026</span><h2 className="mt-2 font-semibold">Levantamento topográfico do trecho</h2></div><StatusBadge status="draft" statuses={statuses} /></div><p className="text-sm text-slate-500">CT-DEMO/2026 · Lote 01 · BR-364/AC · Km 4,76 a 4,98</p><div className="flex flex-wrap justify-end gap-2"><DemoButton><Eye size={15} />Acessar</DemoButton><DemoButton data-tour="dnit-submit" primary><Send size={15} />Enviar para análise</DemoButton></div></article></>}
    </>;
}

function Analysis({ inspector = false, returning = false }) {
    return <><PageHeader title="Ordem de Serviço" description="Abra Analisar OS na listagem para atuar na etapa correspondente ao seu papel." />
        <ModalPreview title={inspector ? 'Decisão do fiscal do contrato · OS 001/2026' : 'Análise Supervisão · OS 001/2026'}>
            {inspector ? <>
                <section data-tour="dnit-comparison" className="overflow-hidden rounded-xl border border-slate-200"><h3 className="border-b border-slate-200 px-4 py-3 text-sm font-semibold">Análise Supervisão</h3><SupervisionAnalysis items={reviewed} deadline="2026-11-20" comment="Produtos definidos pela Supervisão." /></section>
                <section data-tour={returning ? 'dnit-return' : 'dnit-decision'} className="space-y-5 rounded-xl border border-slate-200 p-5"><h3 className="font-semibold">Decisão do fiscal do contrato</h3><div className="flex flex-wrap gap-3"><span className={`rounded-lg border px-4 py-2 text-sm ${!returning ? 'border-emerald-400 bg-emerald-50 text-emerald-800' : 'border-slate-200'}`}>Aprovar serviço</span><span className={`rounded-lg border px-4 py-2 text-sm ${returning ? 'border-amber-400 bg-amber-50 text-amber-900' : 'border-slate-200'}`}>Reprovar e devolver</span></div><Field label={returning ? 'Motivo da reprovação' : 'Comentário da decisão'} multiline value={returning ? 'Reavaliar o quantitativo previsto e justificar a necessidade do ponto adicional.' : 'Serviço aprovado conforme a análise técnica apresentada.'} />{returning && <Field label="Devolver para"><select disabled value="concremat" className="mt-2 w-full rounded-lg border-slate-300 text-sm"><option value="concremat">Supervisão — produtos, quantidades ou prazo</option><option value="analyst">Analista DNIT — pedido de serviço</option></select></Field>}<div className="flex flex-wrap justify-end gap-3"><DemoButton><Download size={15} />Baixar PDF</DemoButton><DemoButton primary><ShieldCheck size={16} />{returning ? 'Reprovar e devolver' : 'Aprovar serviço'}</DemoButton></div></section>
            </> : <div data-tour="dnit-review" className="space-y-5">
                <section data-tour="dnit-original" className="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4"><h3 className="flex items-center gap-2 text-sm font-semibold"><LockKeyhole size={17} />Consultar pedido do analista e anexos</h3><p className="text-sm text-slate-600">Levantamento topográfico do trecho · BR-364/AC · Km 4,76 a 4,98</p><p className="text-xs text-slate-500">Prazo solicitado: 10/11/2026 · 2 produtos · 1 anexo</p></section>
                <section data-tour="dnit-review-products" className="space-y-4"><h3 className="text-sm font-semibold">Produtos solicitados</h3><div className="rounded-xl border border-slate-200 px-4"><ProductLines items={reviewed} review /></div><div className="grid gap-4 sm:grid-cols-2"><Field label="Prazo estimado pela Supervisão" type="date" value="2026-11-14" /><div className="rounded-lg bg-amber-50 p-4 text-xs text-amber-900">Prazo solicitado pelo analista: 10/11/2026<br /><strong className="mt-2 block">Prazo alterado</strong></div></div></section>
                <section data-tour="dnit-review-comment" className="space-y-4"><Field label="Comentário da análise" multiline value={comment} /><div className="flex flex-wrap justify-end gap-3"><DemoButton><Save size={15} />Salvar análise</DemoButton><DemoButton primary><Send size={15} />Encaminhar ao fiscal</DemoButton></div></section>
            </div>}
        </ModalPreview>
    </>;
}

function Dashboard() {
    const metrics = [['Total de OSs', 12], ['Demandas', 2], ['Em análise Supervisão', 3], ['Com o fiscal do contrato', 2], ['Aprovadas', 4], ['Devolvidas', 1]];
    return <><PageHeader title="Dashboard" description="Acompanhe as solicitações, as etapas de análise e as aprovações dos contratos." />
        <section data-tour="dnit-dashboard-filters" className="sig-card grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4"><Field label="Contrato" value="CT-DEMO/2026" /><Field label="Rodovia" value="BR-364/AC" /><Field label="Período inicial" type="date" value="2026-09-01" /><Field label="Período final" type="date" value="2026-11-30" /></section>
        <section data-tour="dnit-metrics" className="grid grid-cols-2 gap-3 lg:grid-cols-3">{metrics.map(([label,value],i) => <article key={label} className="sig-card space-y-3 p-5"><span className="inline-flex rounded-lg bg-blue-50 p-2 text-blue-700">{i === 4 ? <CheckCircle2 size={18} /> : <ClipboardList size={18} />}</span><p className="text-xs text-slate-500">{label}</p><p className="text-3xl font-bold">{value}</p></article>)}</section>
        <section data-tour="dnit-charts" className="space-y-5"><div className="grid gap-5 lg:grid-cols-2"><div className="sig-card space-y-4 p-5"><h2 className="font-semibold">Distribuição por etapa</h2>{metrics.slice(1).map(([label,value]) => <div key={label}><div className="mb-2 flex justify-between gap-2 text-xs"><span>{label}</span><strong>{value}</strong></div><div className="h-2 rounded-full bg-slate-100"><div className="h-full rounded-full bg-blue-500" style={{width:`${value / 12 * 100}%`}} /></div></div>)}</div><div className="sig-card p-5"><h2 className="font-semibold">Evolução mensal</h2><p className="mt-2 text-xs text-slate-500">Enviadas e aprovadas por mês</p><div className="mt-7 flex items-end justify-around gap-4 border-b border-slate-200 pb-3">{[[6,2,'Set'],[4,3,'Out'],[7,5,'Nov']].map(([sent,approved,label]) => <div key={label} className="text-center text-xs"><div className="flex h-36 items-end gap-2"><div className="w-6 rounded-t bg-blue-500" style={{height:`${sent*16}px`}} /><div className="w-6 rounded-t bg-emerald-500" style={{height:`${approved*16}px`}} /></div><span className="mt-2 block">{label}</span></div>)}</div><p className="mt-3 text-xs text-slate-500">Azul: enviadas · Verde: aprovadas</p></div></div><div className="sig-card space-y-4 p-5"><h2 className="font-semibold">Pendências que exigem atenção</h2><div className="flex flex-wrap items-center justify-between gap-3"><div><p className="font-mono text-xs text-blue-700">OS 001/2026</p><p className="mt-1 text-sm font-semibold">Levantamento topográfico do trecho</p><p className="mt-2 text-xs text-slate-500">Há 2 dias nesta etapa · CT-DEMO/2026</p></div><div className="flex flex-wrap gap-2"><DemoButton><Eye size={15} />Acessar</DemoButton><DemoButton primary>Analisar OS</DemoButton></div></div></div></section>
    </>;
}

export default function TourPreview({ tenant }) {
    const [index, setIndex] = useState(0);
    const exiting = useRef(false);
    const step = osDnitTourSteps[index];
    const sectionIndex = tourSections.findIndex(([id]) => id === step.section);
    const exit = () => {
        if (exiting.current) return;
        exiting.current = true;
        router.visit(route('tenant.os-dnit.orders.index', tenant.slug));
    };
    const next = () => index === osDnitTourSteps.length - 1 ? exit() : setIndex(current => current + 1);
    return <AuthenticatedLayout osDnitTourScreen={step.section}>
        <Head title="Ordem de Serviço · Tour guiado" />
        <main className="sig-content os-dnit-page space-y-6 pb-16">
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3"><p className="flex items-center gap-2 text-xs font-semibold text-blue-900"><Plane size={17} />Tour Ordem de Serviço · Demonstração com dados fictícios</p><button type="button" onClick={exit} className="text-xs font-semibold text-blue-700 underline">Sair do tour</button></div>
            <ol aria-label="Etapas do tour" className="flex flex-wrap gap-2">{tourSections.map(([id,label],i) => <li key={id} aria-current={id === step.section ? 'step' : undefined} className={`inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-xs ${id === step.section ? 'border-blue-500 bg-blue-600 font-semibold text-white' : 'border-slate-200 bg-white text-slate-500'}`}><span>{i < sectionIndex ? <Check size={13} /> : i + 1}</span>{label}</li>)}</ol>
            <div key={step.view} className="space-y-6">
                {step.view === 'responsibles' && <Responsibles />}
                {['products','product-form'].includes(step.view) && <Catalog modal={step.view === 'product-form'} />}
                {step.view === 'create' && <CreateRequest />}
                {['submission','submit-confirm'].includes(step.view) && <Submission confirmation={step.view === 'submit-confirm'} />}
                {step.view === 'concremat' && <Analysis />}
                {['inspector','inspector-return'].includes(step.view) && <Analysis inspector returning={step.view === 'inspector-return'} />}
                {step.view === 'dashboard' && <Dashboard />}
            </div>
        </main>
        <OsDnitTour step={step} index={index} total={osDnitTourSteps.length} section={tourSections[sectionIndex][1]} onPrevious={() => setIndex(current => Math.max(0,current - 1))} onNext={next} onExit={exit} />
    </AuthenticatedLayout>;
}
