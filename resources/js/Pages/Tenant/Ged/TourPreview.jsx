import GedTour from '@/Components/GedTour';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    ChevronDown,
    Database,
    Download,
    FileText,
    History,
    MessageSquare,
    Paperclip,
    Pencil,
    Save,
    Shield,
    Tags,
    Trash2,
    UploadCloud,
    UserRound,
    X,
} from 'lucide-react';
import { useState } from 'react';

const tabs = [
    { key: 'details', label: 'Detalhes', icon: FileText },
    { key: 'content', label: 'Conteudo', icon: FileText },
    { key: 'attachments', label: 'Anexos', icon: Paperclip },
    { key: 'metadata', label: 'Metadados', icon: Database },
    { key: 'notes', label: 'Notas', icon: MessageSquare },
    { key: 'history', label: 'Historico', icon: History },
    { key: 'permissions', label: 'Permissoes', icon: Shield },
];

export default function GedTourPreview({ tenant }) {
    const [activeSection, setActiveSection] = useState('details');
    const activeTab = tabs.find((tab) => tab.key === activeSection) || tabs[0];
    const ActiveIcon = activeTab.icon;

    return (
        <AuthenticatedLayout>
            <Head title="Carta de encaminhamento" />
            <div className="space-y-4 px-1 pb-8 sm:px-2">
                <div data-tour="ged-document-header" className="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                    <div>
                        <div className="eyebrow">Documentacao</div>
                        <h1 className="mt-2 text-2xl font-bold text-[var(--ink-900)]">Carta de encaminhamento - Contrato CT-001</h1>
                        <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-[var(--ink-600)]">
                            <span className="inline-flex items-center gap-1"><FileText size={15} /> carta-encaminhamento-ct-001.pdf</span>
                            <span className="inline-flex items-center gap-1"><Tags size={15} /> Sem tipo</span>
                            <span className="inline-flex items-center gap-1"><UserRound size={15} /> Admin Plataforma</span>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" className="sig-btn sig-btn-danger !min-h-9 !px-3"><Trash2 size={16} /> Excluir</button>
                        <button type="button" className="sig-btn sig-btn-secondary !min-h-9 !px-3"><Download size={16} /> Baixar</button>
                    </div>
                </div>

                <div className="grid gap-5 xl:grid-cols-[520px_minmax(0,1fr)]">
                    <section className="rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div className="flex items-center justify-between gap-2 border-b border-slate-200 p-3">
                            <div className="flex items-center gap-2">
                                <button type="button" className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-[var(--ink-600)]" title="Voltar"><X size={16} /></button>
                                <button type="button" className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-[var(--ink-600)] opacity-40" title="Documento anterior"><ArrowLeft size={16} /></button>
                                <button type="button" className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-[var(--ink-600)] opacity-40" title="Proximo documento"><ArrowRight size={16} /></button>
                            </div>
                            {activeSection === 'details' && <button type="button" className="inline-flex items-center gap-1 rounded-lg bg-emerald-800 px-3 py-2 text-sm font-semibold text-white"><Save size={15} /> Salvar</button>}
                        </div>

                        <div className="border-b border-slate-200 px-3">
                            <nav data-tour="ged-document-tabs" className="flex flex-wrap gap-x-5">
                                {tabs.map((tab) => {
                                    const Icon = tab.icon;
                                    const active = tab.key === activeSection;
                                    return <button key={tab.key} data-tour={`ged-document-tab-${tab.key}`} type="button" onClick={() => setActiveSection(tab.key)} className={`inline-flex items-center gap-1.5 border-b-2 px-0 py-3 text-sm font-semibold transition ${active ? 'border-emerald-800 text-emerald-900' : 'border-transparent text-[var(--ink-600)] hover:border-slate-300 hover:text-[var(--ink-900)]'}`}><Icon size={15} />{tab.label}</button>;
                                })}
                            </nav>
                        </div>

                        <div className="p-4" data-tour={`ged-document-section-${activeSection}`}>
                            <div className="mb-4 flex items-center gap-2">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-700"><ActiveIcon size={18} /></div>
                                <div><h2 className="text-lg font-bold text-[var(--ink-900)]">{activeTab.label}</h2><p className="text-xs text-[var(--ink-500)]">Dados do documento no GED.</p></div>
                            </div>
                            <PreviewSection section={activeSection} />
                        </div>
                    </section>

                    <PreviewViewer />
                </div>
            </div>
            <GedTour tenant={tenant} section="document" activeDocumentSection={activeSection} onDocumentSectionChange={setActiveSection} />
        </AuthenticatedLayout>
    );
}

function PreviewSection({ section }) {
    if (section === 'content') return <ContentSection />;
    if (section === 'attachments') return <AttachmentsSection />;
    if (section === 'notes') return <NotesSection />;
    if (section === 'permissions') return <PermissionsSection />;
    if (section === 'metadata') return <MetadataSection />;
    if (section === 'history') return <HistorySection />;
    return <DetailsSection />;
}

function Field({ label, children }) {
    return <div className="grid gap-1.5"><div className="text-xs font-semibold text-[var(--ink-700)]">{label}</div>{children}</div>;
}

function Control({ value, select = false }) {
    return <div className="flex min-h-10 items-center justify-between rounded-lg border border-slate-300 bg-white px-3 text-sm text-[var(--ink-800)]"><span>{value}</span>{select && <ChevronDown size={16} className="text-[var(--ink-400)]" />}</div>;
}

function DetailsSection() {
    return <div className="space-y-4">
        <Field label="Titulo"><Control value="Carta de encaminhamento - Contrato CT-001" /></Field>
        <div className="grid gap-4 md:grid-cols-2"><Field label="Correspondente"><Control value="Sem correspondente" select /></Field><Field label="Tipo de documento"><Control value="Sem tipo" select /></Field></div>
        <div className="grid gap-4 md:grid-cols-2"><Field label="Data do documento"><Control value="01/01/2026" /></Field><Field label="Numero / Codigo"><Control value="001/2026" /></Field></div>
        <Field label="Etiquetas"><div className="min-h-10 rounded-lg border border-slate-300 bg-white p-2 text-sm text-[var(--ink-400)]">Sem etiquetas</div></Field>
        <Field label="Contrato"><Control value="CT-001 - Contrato de Obras" /></Field>
        <Field label="Descricao"><textarea readOnly value="Carta de encaminhamento dos documentos de apoio para analise e providencias contratuais." className="min-h-28 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-[var(--ink-800)] outline-none" /></Field>
    </div>;
}

function ContentSection() {
    return <div className="space-y-4">
        <div className="rounded-xl border border-emerald-100 bg-emerald-50 p-3 text-sm text-emerald-900"><div className="font-bold">OCR processado</div></div>
        <Field label="Texto OCR"><textarea readOnly value={'CARTA DE ENCAMINHAMENTO\n\nEncaminhamos para conhecimento e providencias o conjunto de documentos referente ao Contrato CT-001.\n\nAtenciosamente,\nEquipe de Documentacao'} className="min-h-[360px] rounded-lg border border-slate-300 bg-white px-3 py-2 font-mono text-xs leading-5 text-[var(--ink-800)] outline-none" /></Field>
    </div>;
}

function AttachmentsSection() {
    return <div className="space-y-5">
        <div data-tour="ged-attachments-upload" className="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <Field label="Arquivos"><div className="flex min-h-24 flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white px-4 py-5 text-center"><UploadCloud size={24} className="text-blue-700" /><span className="mt-2 text-sm font-bold text-[var(--ink-900)]">Selecionar arquivos</span><span className="mt-1 text-xs text-[var(--ink-500)]">ZIP, video, planilha, imagem, PDF ou qualquer arquivo de suporte ate 100 MB por arquivo.</span></div></Field>
            <div className="mt-4 grid gap-4 md:grid-cols-2"><Field label="Titulo"><Control value="Opcional" /></Field><Field label="Observacao"><Control value="Opcional" /></Field></div>
            <div className="mt-4 flex justify-end"><button type="button" className="sig-btn sig-btn-primary" disabled><UploadCloud size={16} /> Enviar anexos</button></div>
        </div>
        <div data-tour="ged-attachments-list" className="space-y-3"><AttachmentRow title="Memorial descritivo" filename="memorial-descritivo.pdf" meta="284.9 KB  PDF  01/01/2026, 10:30" /><AttachmentRow title="Planilha de apoio" filename="planilha-apoio.xlsx" meta="32.2 KB  XLSX  01/01/2026, 10:31" /></div>
    </div>;
}

function AttachmentRow({ title, filename, meta }) {
    return <div className="rounded-lg border border-slate-200 bg-white px-3 py-2 shadow-sm"><div className="flex items-start gap-2"><Paperclip size={15} className="mt-0.5 shrink-0 text-blue-700" /><div className="min-w-0 flex-1"><div className="truncate text-sm font-bold text-[var(--ink-900)]">{title}</div><div className="mt-0.5 truncate text-xs text-[var(--ink-500)]">{filename}</div><div className="mt-1 flex flex-wrap gap-2 text-xs text-[var(--ink-500)]"><span>{meta}</span><span className="rounded-full bg-emerald-50 px-2 py-0.5 font-bold text-emerald-700">OCR concluido</span></div></div><div className="flex shrink-0 items-center gap-1"><button type="button" className="inline-flex h-8 w-8 items-center justify-center rounded border border-slate-200 text-[var(--ink-700)]" title="Baixar"><Download size={15} /></button><button data-tour="ged-attachment-edit" type="button" className="inline-flex h-8 w-8 items-center justify-center rounded border border-slate-200 text-[var(--ink-700)]" title="Editar"><Pencil size={15} /></button><button type="button" className="inline-flex h-8 w-8 items-center justify-center rounded border border-rose-100 text-rose-700" title="Excluir"><Trash2 size={15} /></button></div></div></div>;
}

function NotesSection() {
    return <div className="space-y-4"><div className="space-y-2 border-b border-slate-300 pb-4"><textarea className="min-h-20 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm outline-none" placeholder="Inserir nota" /><div className="flex justify-end"><button type="button" className="rounded bg-emerald-800 px-3 py-2 text-sm font-semibold text-white">Adicionar nota</button></div></div><div className="overflow-hidden rounded border border-slate-300 bg-white"><div className="min-h-14 px-3 py-3 text-sm text-[var(--ink-900)]">Documento conferido e encaminhado para analise contratual.</div><div className="border-t border-slate-300 bg-slate-50 px-3 py-2 text-xs font-semibold text-emerald-800">Admin Plataforma - 01 de jan. de 2026</div></div></div>;
}

function PermissionsSection() {
    return <div className="space-y-4"><Field label="Proprietario"><Control value="Admin Plataforma" select /></Field><Field label="Usuarios com acesso"><div className="rounded-lg border border-slate-300 p-3 text-sm text-[var(--ink-500)]">Nenhum usuario adicional selecionado.</div></Field><Field label="Empresas com acesso"><div className="rounded-lg border border-slate-300 p-3 text-sm text-[var(--ink-500)]">Nenhuma empresa adicional selecionada.</div></Field></div>;
}

function MetadataSection() {
    return <div className="space-y-4 text-sm"><MetadataRow label="Data de adicao" value="01 de jan. de 2026" /><MetadataRow label="Nome do arquivo original" value="carta-encaminhamento-ct-001.pdf" /><MetadataRow label="Tamanho do arquivo original" value="284.9 KB" /><MetadataRow label="Tipo mime original" value="application/pdf" /></div>;
}

function MetadataRow({ label, value }) {
    return <div className="grid gap-2 md:grid-cols-[180px_1fr]"><div className="text-[var(--ink-900)]">{label}</div><div className="break-all text-[var(--ink-700)]">{value}</div></div>;
}

function HistorySection() {
    return <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-[var(--ink-700)]"><div className="font-bold text-[var(--ink-900)]">Documento criado</div><div className="mt-1 text-xs">Admin Plataforma - 01 de jan. de 2026</div></div>;
}

function PreviewViewer() {
    return <section className="flex min-h-[720px] flex-col overflow-hidden rounded-xl border border-slate-200 bg-slate-100 shadow-sm"><div className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-white p-2 text-sm"><div className="flex overflow-hidden rounded-lg border border-slate-300"><span className="border-r border-slate-300 bg-slate-50 px-3 py-2">Pagina</span><span className="w-14 px-3 py-2">1</span><span className="border-l border-slate-300 bg-slate-50 px-3 py-2">de 1</span></div><div className="rounded-lg border border-slate-300 px-3 py-2">100%</div></div><div className="flex flex-1 items-center justify-center p-6"><div className="flex aspect-[0.72] w-full max-w-[620px] flex-col border-8 border-neutral-500 bg-white p-8 shadow"><div className="border-b border-slate-300 pb-5 text-center"><FileText className="mx-auto text-blue-700" size={42} /><div className="mt-3 font-bold text-slate-800">CARTA DE ENCAMINHAMENTO</div></div><div className="space-y-4 pt-8 text-sm leading-7 text-slate-600"><p>Prezados,</p><p>Encaminhamos os documentos de apoio relacionados ao Contrato CT-001 para conhecimento e providencias.</p><p>Atenciosamente,<br />Equipe de Documentacao</p></div></div></div></section>;
}
