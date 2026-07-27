import RncTour from '@/Components/RncTour';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    CheckCircle2,
    ClipboardCheck,
    ClipboardX,
    Download,
    Eye,
    FileArchive,
    FileText,
    Gauge,
    ImagePlus,
    MapPin,
    Menu,
    Minus,
    MoreVertical,
    Plus,
    Printer,
    RotateCw,
    SearchCheck,
    Send,
    ShieldCheck,
    UserRoundCog,
    Users,
    XCircle,
} from 'lucide-react';

const roles = [
    {
        name: 'Responsável Operacional',
        detail: 'Cria, notifica, analisa e evidencia a RNC.',
        permissions: 'Criar · Notificar · Analisar · Evidenciar · Visualizar',
        tone: 'blue',
    },
    {
        name: 'Responsável da Construtora',
        detail: 'Prepara e envia a proposta de ação corretiva.',
        permissions: 'Ação corretiva · Visualizar',
        tone: 'amber',
    },
    {
        name: 'Responsável de Acompanhamento',
        detail: 'Acompanha a ocorrência e recebe as comunicações.',
        permissions: 'Visualizar',
        tone: 'green',
    },
];

export default function RncTourPreview({ tenant, screen = 'responsibles' }) {
    return (
        <AuthenticatedLayout>
            <Head title="Tutorial operacional de RNC" />
            <section className="sig-content">
                {screen === 'responsibles' && <ResponsiblesPreview />}
                {screen === 'create' && <CreatePreview />}
                {screen === 'notify' && <NotifyPreview />}
                {screen === 'corrective-action' && <CorrectiveActionPreview />}
                {screen === 'review' && <ReviewPreview />}
                {screen === 'evidence' && <EvidencePreview />}
                {screen === 'final-pdf' && <FinalPdfPreview />}
                {screen === 'dashboard' && <DashboardPreview />}
            </section>
            <RncTour key={screen} section={screen} tenantSlug={tenant.slug} />
        </AuthenticatedLayout>
    );
}

function PageHeader({ eyebrow, icon: Icon, title, subtitle, actions = null, tour }) {
    return (
        <header data-tour={tour} className="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <div className="flex items-center gap-2 text-[var(--ink-500)]">
                    <Icon size={14} />
                    <span className="eyebrow">{eyebrow}</span>
                </div>
                <h1 className="mt-2 text-2xl font-semibold text-[var(--ink-900)]">{title}</h1>
                <p className="mt-1 text-sm text-[var(--ink-500)]">{subtitle}</p>
            </div>
            {actions}
        </header>
    );
}

function ResponsiblesPreview() {
    return (
        <div className="grid gap-6 2xl:grid-cols-[400px_minmax(0,1fr)]">
            <section className="sig-card p-5">
                <div data-tour="rnc-responsibles">
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <ShieldCheck size={14} />
                        <span className="eyebrow">Relatório Não Conformidade</span>
                    </div>
                    <h1 className="mt-2 text-xl font-semibold text-[var(--ink-900)]">Responsáveis</h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        Defina o papel de cada usuário no fluxo de RNC do contrato. Todos os responsáveis recebem alertas no sistema e por e-mail.
                    </p>
                </div>

                <div data-tour="rnc-responsibles-form" className="mt-5 grid gap-4">
                    <FakeField label="Contrato" value="025/2026 - Corredor Troncal" />

                    <div data-tour="rnc-responsibility-types">
                        <span className="eyebrow mb-2 block">Tipo de responsabilidade</span>
                        <div className="grid gap-2">
                            {roles.map((role, index) => (
                                <button
                                    key={role.name}
                                    type="button"
                                    className={`flex w-full items-start gap-3 rounded-lg border p-3 text-left ${
                                        index === 0
                                            ? 'border-[var(--primary)] bg-[var(--primary-50)]'
                                            : 'border-[var(--border)] bg-white'
                                    }`}
                                >
                                    <span className={`mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md ${
                                        index === 0
                                            ? 'bg-[var(--primary)] text-white'
                                            : 'bg-[var(--surface-muted)] text-[var(--ink-600)]'
                                    }`}>
                                        {index === 0 ? <ShieldCheck size={16} /> : index === 1 ? <UserRoundCog size={16} /> : <Eye size={16} />}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block text-sm font-semibold text-[var(--ink-900)]">{role.name}</span>
                                        <span className="mt-0.5 block text-xs leading-5 text-[var(--ink-500)]">{role.detail}</span>
                                        <span className="mt-1 block text-[11px] leading-4 text-[var(--ink-500)]">{role.permissions}</span>
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>

                    <div>
                        <span className="eyebrow mb-1 block">Usuário responsável</span>
                        <div className="sig-input flex items-center gap-2 text-[var(--ink-500)]">
                            <SearchCheck size={15} />
                            <span className="text-sm">Pesquisar por nome ou email</span>
                        </div>
                        <div className="mt-2 grid gap-1 rounded-lg border border-[var(--border)] bg-white p-1">
                            <UserChoice initials="MC" name="Marina Costa" email="marina@empresa.com" selected />
                            <UserChoice initials="CA" name="Carlos Almeida" email="carlos@construtora.com" />
                            <UserChoice initials="AR" name="Ana Ribeiro" email="ana@cliente.com" />
                        </div>
                    </div>
                </div>

                <button type="button" className="sig-btn sig-btn-primary mt-5">
                    <Plus size={15} />
                    Salvar responsável
                </button>
            </section>

            <section className="sig-card overflow-hidden">
                <header className="border-b border-[var(--border)] px-5 py-4">
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <Users size={14} />
                        <span className="eyebrow">Equipe responsável</span>
                    </div>
                    <h2 className="mt-1 text-[15px] font-semibold text-[var(--ink-900)]">3 responsável(is) cadastrado(s)</h2>
                </header>
                <div className="overflow-x-auto">
                    <table className="sig-table min-w-[820px]">
                        <thead><tr><th>Usuário</th><th>Contrato</th><th>Responsabilidade</th><th>Cadastrado em</th><th>Ações</th></tr></thead>
                        <tbody>
                            <ResponsibleRow name="Marina Costa" email="marina@empresa.com" role="Responsável Operacional" />
                            <ResponsibleRow name="Carlos Almeida" email="carlos@construtora.com" role="Responsável da Construtora" />
                            <ResponsibleRow name="Ana Ribeiro" email="ana@cliente.com" role="Responsável de Acompanhamento" />
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}

function CreatePreview() {
    return (
        <section className="sig-card p-5">
            <div data-tour="rnc-create-header" className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex items-center gap-2 text-[var(--ink-500)]">
                        <ClipboardX size={14} />
                        <span className="eyebrow">Qualidade</span>
                    </div>
                    <h1 className="mt-2 text-xl font-semibold text-[var(--ink-900)]">Nova RNC</h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">
                        Registre não conformidades vinculadas à obra e às empresas do contrato.
                    </p>
                </div>
                <button type="button" className="sig-btn sig-btn-secondary">Voltar</button>
            </div>

            <div data-tour="rnc-create-classification" className="mt-5 grid gap-4">
                <FakeField label="Obra" value="001 - Corredor Troncal" />
                <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-3 py-2">
                    <span className="mono text-xs font-semibold text-[var(--ink-800)]">025/2026</span>
                    <span className="mt-0.5 block text-sm text-[var(--ink-500)]">Corredor Troncal</span>
                </div>
                <FakeField label="Projetos vinculados" value="0252026-001-DRE-EP-PRJ-001-R02 · 1 selecionado" />
                <div className="grid gap-4 md:grid-cols-2">
                    <FakeField label="Contratante" value="XCLI - X Cliente" />
                    <FakeField label="Contratada" value="XCTR - X Construtora" />
                    <FakeField label="Data abertura" value="23/07/2026" />
                    <FakeField label="Prazo para resposta de ação corretiva" value="30/07/2026" />
                </div>

                <section className="rounded-lg border border-[var(--border)] bg-white p-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <span className="eyebrow">Latitude e longitude</span>
                            <p className="mt-1 text-xs text-[var(--ink-500)]">Clique no mapa, arraste o marcador ou use a localização do navegador.</p>
                        </div>
                        <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm"><MapPin size={14} /> Usar localização</button>
                    </div>
                    <div className="relative mt-3 h-44 overflow-hidden rounded-md bg-[linear-gradient(35deg,#d7e8d5_25%,#edf3e8_25%,#edf3e8_50%,#d7e8d5_50%,#d7e8d5_75%,#edf3e8_75%)]">
                        <div className="absolute inset-x-0 top-[45%] h-3 rotate-[-5deg] bg-white/90" />
                        <div className="absolute inset-y-0 left-[63%] w-3 rotate-[12deg] bg-white/90" />
                        <div className="absolute left-[58%] top-[42%] flex h-8 w-8 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-blue-600 text-white shadow-lg">
                            <MapPin size={16} />
                        </div>
                    </div>
                    <div className="mt-3 grid gap-4 md:grid-cols-2">
                        <FakeField label="Latitude" value="-23.5565569" />
                        <FakeField label="Longitude" value="-46.6491232" />
                    </div>
                </section>

                <div className="grid gap-4 md:grid-cols-2">
                    <FakeField label="Disciplina" value="DRE - Drenagem" />
                    <FakeField label="Gravidade" value="Grave" />
                </div>
            </div>

            <div data-tour="rnc-create-description" className="mt-4 grid gap-4">
                <FakeTextarea label="Descrição do problema" value="Infiltração identificada na junta de concretagem do trecho executado." />
                <FakeTextarea label="Observação" value="Ocorrência verificada durante a inspeção de qualidade." />
                <FakeTextarea label="Ações corretivas recomendadas" value="Tratar a junta, revisar a impermeabilização e apresentar evidências." />
                <section className="rounded-lg border border-[var(--border)] bg-white p-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <span className="eyebrow">Registro fotográfico</span>
                            <p className="mt-1 text-xs text-[var(--ink-500)]">Adicione até 12 imagens, organize a posição e comente cada foto.</p>
                        </div>
                        <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm"><ImagePlus size={14} /> Adicionar fotos</button>
                    </div>
                    <div className="mt-3 grid grid-cols-2 gap-3">
                        <Photo label="Imagem 1 · Junta com infiltração" compact />
                        <Photo label="Imagem 2 · Vista geral do trecho" compact />
                    </div>
                </section>
            </div>
            <button type="button" className="sig-btn sig-btn-primary mt-5"><Plus size={15} /> Criar RNC</button>
        </section>
    );
}

function NotifyPreview() {
    return (
        <>
            <PageHeader
                eyebrow="Qualidade"
                icon={ClipboardX}
                title="Relatório Não Conformidade"
                subtitle="1 RNC cadastrada"
                actions={<button type="button" className="sig-btn sig-btn-primary"><Plus size={15} /> Nova RNC</button>}
            />
            <section className="sig-card overflow-hidden">
                <table className="sig-table">
                    <thead><tr><th>RNC</th><th>Disciplina / Gravidade</th><th>Obra</th><th>Empresas</th><th>Abertura</th><th>Status</th><th>Ações</th></tr></thead>
                    <tbody>
                        <tr data-tour="rnc-notify-row">
                            <td><strong className="mono text-[var(--primary)]">001-2026</strong><div className="mt-1 text-xs text-[var(--ink-500)]">RNC aberta</div></td>
                            <td><span className="sig-pill sig-pill-blue">DRE - Drenagem</span><span className="sig-pill sig-pill-red ml-1">Grave</span></td>
                            <td>
                                <strong>001 - Corredor Troncal</strong>
                                <div className="mt-1 text-xs text-[var(--ink-500)]">025/2026</div>
                                <div className="mt-1 flex flex-wrap gap-2 text-[11px] text-[var(--ink-500)]">
                                    <span><MapPin size={11} className="mr-1 inline" />-23.5565569, -46.6491232</span>
                                    <span><ImagePlus size={11} className="mr-1 inline" />4 foto(s)</span>
                                </div>
                            </td>
                            <td>
                                <div className="text-[11px] uppercase text-[var(--ink-500)]">Contratante</div>
                                <strong className="text-sm">XCLI</strong>
                                <div className="mt-1 text-[11px] uppercase text-[var(--ink-500)]">Contratada</div>
                                <strong className="text-sm">XCTR</strong>
                            </td>
                            <td>23 jul. 2026</td>
                            <td><span className="sig-pill sig-pill-blue">aberta</span></td>
                            <td>
                                <div data-tour="rnc-notify-action" className="flex flex-wrap gap-2">
                                    <button type="button" className="sig-btn sig-btn-primary sig-btn-sm"><Bell size={14} /> Notificar</button>
                                    <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm"><Eye size={14} /> Abrir</button>
                                    <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm">Editar</button>
                                    <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm"><Download size={14} /> PDF</button>
                                    <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm text-[var(--red)]">Excluir</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </>
    );
}

function CorrectiveActionPreview() {
    return (
        <>
            <PageHeader eyebrow="Ação corretiva" icon={ClipboardCheck} title="RNC 001-2026" subtitle="001 - Corredor Troncal" />
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                <article data-tour="rnc-corrective-summary" className="sig-card overflow-hidden">
                    <RncSummary status="Notificada" />
                    <TextSection title="Descrição do problema" text="Infiltração identificada na junta de concretagem do trecho executado." />
                    <TextSection title="Ações corretivas recomendadas" text="Tratar a junta, revisar a impermeabilização e apresentar evidências." />
                    <section className="p-5">
                        <span className="eyebrow">Imagens</span>
                        <div className="mt-3 grid grid-cols-2 gap-3">
                            <Photo label="Imagem 1 · Junta com infiltração" />
                            <Photo label="Imagem 2 · Vista geral do trecho" />
                        </div>
                    </section>
                </article>
                <aside className="grid content-start gap-6">
                    <section data-tour="rnc-corrective-form" className="sig-card p-5">
                        <span className="eyebrow">Enviar proposta</span>
                        <h2 className="mt-2 text-xl font-semibold">Proposta de ação corretiva</h2>
                        <div className="mt-5 grid gap-4">
                            <FakeTextarea label="Descrição da proposta" value="Remover o material comprometido, tratar a junta e reaplicar a impermeabilização." />
                            <FakeField label="Prazo proposto para executar a ação" value="30/07/2026" />
                            <FakeField label="Anexo zipado" value="proposta-correcao-rnc-001.zip · 4,2 MB" />
                        </div>
                        <button type="button" className="sig-btn sig-btn-primary mt-5"><Plus size={15} /> Enviar proposta</button>
                    </section>
                    <section data-tour="rnc-corrective-history" className="sig-card p-4">
                        <span className="eyebrow">Histórico de propostas</span>
                        <div className="mt-3 rounded-lg border border-[var(--border)] p-3">
                            <div className="flex justify-between gap-2"><strong className="text-sm">proposta-correcao-rnc-001.zip</strong><span className="sig-pill sig-pill-amber">Em análise</span></div>
                            <p className="mt-2 text-xs text-[var(--ink-500)]">Carlos Almeida · 24 jul. 2026 · prazo 30 jul. 2026</p>
                        </div>
                    </section>
                </aside>
            </div>
        </>
    );
}

function ReviewPreview() {
    return (
        <>
            <PageHeader eyebrow="Análise da proposta" icon={SearchCheck} title="RNC 001-2026" subtitle="001 - Corredor Troncal" />
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                <article className="sig-card overflow-hidden">
                    <RncSummary status="Em análise" />
                    <section data-tour="rnc-review-proposal" className="p-5">
                        <span className="eyebrow">Proposta enviada</span>
                        <div className="mt-4 rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-4">
                            <div className="flex flex-wrap items-center gap-2"><span className="sig-pill sig-pill-amber">Aguardando análise</span><strong className="text-sm">Carlos Almeida · 24 jul. 2026</strong></div>
                            <p className="mt-3 text-sm leading-6 text-[var(--ink-600)]">Remover o material comprometido, tratar a junta e reaplicar a impermeabilização.</p>
                            <button type="button" className="sig-btn sig-btn-secondary mt-4"><FileArchive size={15} /> proposta-correcao-rnc-001.zip</button>
                        </div>
                    </section>
                </article>
                <section data-tour="rnc-review-decision" className="sig-card h-fit p-5">
                    <span className="eyebrow">Parecer</span>
                    <h2 className="mt-2 text-xl font-semibold">Analisar proposta</h2>
                    <div className="mt-5 grid grid-cols-2 gap-2">
                        <Decision icon={CheckCircle2} label="Aprovar" tone="green" selected />
                        <Decision icon={XCircle} label="Reprovar" tone="red" />
                    </div>
                    <div className="mt-4"><FakeTextarea label="Observações da análise" value="Proposta aprovada conforme escopo e prazo apresentados." /></div>
                    <button type="button" className="sig-btn sig-btn-primary mt-5 w-full"><Send size={15} /> Enviar análise</button>
                </section>
            </div>
        </>
    );
}

function EvidencePreview() {
    return (
        <>
            <PageHeader eyebrow="Evidenciar correção" icon={ImagePlus} title="RNC 001-2026" subtitle="001 - Corredor Troncal" />
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                <article data-tour="rnc-evidence-approved" className="sig-card overflow-hidden">
                    <RncSummary status="Proposta aprovada" green />
                    <TextSection title="Descrição da proposta" text="Remover o material comprometido, tratar a junta e reaplicar a impermeabilização." />
                    <TextSection title="Observações da análise" text="Proposta aprovada conforme escopo e prazo apresentados." />
                </article>
                <section className="sig-card h-fit p-5">
                    <span className="eyebrow">Finalizar RNC</span>
                    <h2 className="mt-2 text-xl font-semibold">Evidências da correção</h2>
                    <div data-tour="rnc-evidence-photos" className="mt-5 rounded-lg border border-[var(--border)] p-3">
                        <div className="flex items-center justify-between gap-3"><span className="eyebrow">Registro fotográfico</span><button type="button" className="sig-btn sig-btn-secondary sig-btn-sm"><ImagePlus size={14} /> Adicionar fotos</button></div>
                        <div className="mt-3 grid grid-cols-2 gap-2">
                            <Photo label="Posição 1 · Junta tratada" compact />
                            <Photo label="Posição 2 · Serviço concluído" compact />
                        </div>
                    </div>
                    <div data-tour="rnc-evidence-finish" className="mt-4">
                        <FakeField label="Documento zipado de evidências" value="evidencias-rnc-001.zip · 8,7 MB" />
                        <button type="button" className="sig-btn sig-btn-primary mt-4 w-full"><Send size={15} /> Enviar evidências e finalizar</button>
                    </div>
                </section>
            </div>
        </>
    );
}

function FinalPdfPreview() {
    return (
        <div className="overflow-hidden bg-[#262626] shadow-xl">
            <div className="grid h-14 grid-cols-[1fr_auto_1fr] items-center bg-[#3b3b3b] px-4 text-sm text-white/85">
                <div className="flex items-center gap-5">
                    <Menu size={19} />
                    <span className="font-medium">pdf</span>
                </div>
                <div className="flex items-center gap-3">
                    <span className="bg-[#202124] px-2 py-1 text-white">1</span>
                    <span>/</span>
                    <span>3</span>
                    <span className="mx-1 h-6 w-px bg-white/20" />
                    <Minus size={17} />
                    <span className="bg-[#202124] px-2 py-1 text-white">100%</span>
                    <Plus size={17} />
                    <span className="mx-1 h-6 w-px bg-white/20" />
                    <FileText size={17} />
                    <RotateCw size={17} />
                </div>
                <div data-tour="rnc-final-pdf-actions" className="flex items-center justify-end gap-5">
                    <Download size={18} />
                    <Printer size={18} />
                    <MoreVertical size={18} />
                </div>
            </div>

            <div className="grid h-[900px] grid-cols-[210px_minmax(0,1fr)]">
                <aside className="border-r border-white/15 bg-[#292a2c] px-7 py-5">
                    <PdfThumbnail page="1" active>
                        <div className="mx-auto mt-2 h-2 w-14 bg-slate-700" />
                        <div className="mx-auto mt-2 h-1 w-20 bg-slate-300" />
                        <div className="mt-4 grid grid-cols-4 gap-1">
                            {[1, 2, 3, 4, 5, 6, 7, 8].map((item) => <i key={item} className="h-1 bg-slate-300" />)}
                        </div>
                        <div className="mt-4 h-px bg-slate-400" />
                        <div className="mt-3 space-y-1">
                            {[1, 2, 3, 4, 5].map((item) => <i key={item} className="block h-1 bg-slate-200" />)}
                        </div>
                    </PdfThumbnail>
                    <PdfThumbnail page="2">
                        <div className="mt-2 grid grid-cols-2 gap-1">
                            {[1, 2, 3, 4].map((item) => <i key={item} className="h-8 bg-slate-300" />)}
                        </div>
                        <div className="mt-3 space-y-1">
                            {[1, 2, 3].map((item) => <i key={item} className="block h-1 bg-slate-200" />)}
                        </div>
                    </PdfThumbnail>
                    <PdfThumbnail page="3">
                        <div className="mt-3 grid grid-cols-3 gap-1">
                            {[1, 2, 3, 4, 5, 6].map((item) => <i key={item} className="h-2 bg-slate-200" />)}
                        </div>
                    </PdfThumbnail>
                </aside>

                <main className="overflow-auto bg-[#262626] px-3 py-1">
                    <article
                        className="mx-auto aspect-[210/297] w-full max-w-[794px] bg-white px-[30px] py-[26px] font-sans text-[#182033] shadow-lg"
                    >
                        <header data-tour="rnc-final-pdf" className="grid grid-cols-[28%_44%_28%] items-center text-center">
                            <CompanyLogo variant="client" />
                            <div>
                                <h1 className="text-[22px] font-bold leading-[1.25] text-[#0b1020]">
                                    Relatório de Não Conformidade
                                </h1>
                                <p className="mt-1 text-[11px] text-[#5b6479]">Xconstruction · RNC 001-2026</p>
                                <p className="mt-2 text-[11px] leading-[1.45] text-[#5b6479]">
                                    Projetos vinculados: 0252026-001-DRE-EP-PRJ-001-R02 - Projeto de drenagem do trecho 2
                                </p>
                            </div>
                            <CompanyLogo variant="contractor" />
                        </header>

                        <div className="mt-6 grid grid-cols-4 gap-x-5 gap-y-4">
                            <PdfMeta label="Contrato" value="025/2026 - Corredor Troncal" />
                            <PdfMeta label="Obra" value="001 - Corredor Troncal" />
                            <PdfMeta label="Data de abertura" value="23/07/2026" />
                            <PdfMeta label="Criado por" value="Marina Costa" />
                            <PdfMeta label="Contratante" value="X Cliente Engenharia" />
                            <PdfMeta label="Contratada" value="X Construtora Ltda." />
                            <PdfMeta label="Local" value="Picos / PI" />
                            <PdfMeta label="Disciplina" value={<PdfBadge tone="blue">DRE - Drenagem</PdfBadge>} />
                        </div>
                        <div className="mt-4 grid grid-cols-3 gap-x-5">
                            <PdfMeta label="Gravidade" value={<PdfBadge tone="red">Grave</PdfBadge>} />
                            <PdfMeta label="Latitude" value="-23.5565569" />
                            <PdfMeta label="Longitude" value="-46.6491232" />
                        </div>

                        <section className="mt-10">
                            <h2 className="pb-2 text-[13px] font-bold uppercase text-[#0b1020]">
                                Observações e comentários
                            </h2>
                            <PdfSection
                                title="Descrição do problema"
                                text="Durante a inspeção foi identificada infiltração na junta de concretagem do trecho executado. A ocorrência apresenta umidade contínua e falha localizada no tratamento da impermeabilização, exigindo correção para evitar o avanço da manifestação."
                                first
                            />
                            <PdfSection
                                title="Observação"
                                text="A não conformidade foi verificada no trecho 2, próximo à estaca 145. O local foi sinalizado e registrado fotograficamente para acompanhamento da equipe responsável."
                            />
                            <PdfSection
                                title="Ações corretivas recomendadas"
                                text="Remover o material comprometido, preparar a superfície, tratar novamente a junta e reaplicar o sistema de impermeabilização. Após a execução, apresentar registros fotográficos e documentação de apoio para análise e encerramento."
                            />
                        </section>
                    </article>
                </main>
            </div>
        </div>
    );
}

function LegacyFinalPdfPreview() {
    return (
        <>
            <PageHeader
                eyebrow="RNC finalizada"
                icon={FileText}
                title="RNC 001-2026"
                subtitle="Documento final da não conformidade"
                actions={(
                    <div data-tour="rnc-final-pdf-actions" className="flex gap-2">
                        <button type="button" className="sig-btn sig-btn-secondary"><Eye size={15} /> Visualizar PDF</button>
                        <button type="button" className="sig-btn sig-btn-primary"><Download size={15} /> Baixar PDF</button>
                    </div>
                )}
            />
            <div className="mx-auto max-w-[1040px] rounded-md bg-[#303236] p-5 shadow-inner">
                <div className="mb-3 flex items-center justify-between rounded bg-[#3c3f43] px-4 py-2 text-xs text-white/80">
                    <span>pdf</span>
                    <span>1 / 3 · 100%</span>
                </div>
                <article data-tour="rnc-final-pdf" className="min-h-[860px] bg-white px-10 py-9 shadow-lg md:px-16">
                    <header className="grid grid-cols-[120px_minmax(0,1fr)_120px] items-center text-center">
                        <Logo name="" />
                        <div>
                            <p className="text-2xl font-bold leading-tight text-slate-950">Relatório de Não<br />Conformidade</p>
                            <p className="mt-3 text-xs text-slate-500">Xconstruction · RNC 001-2026</p>
                            <p className="mt-2 text-[11px] leading-5 text-slate-500">
                                Projetos vinculados: 0252026-001-DRE-EP-PRJ-001-R02 -<br />
                                Projeto de drenagem do trecho 2
                            </p>
                        </div>
                        <Logo name="" />
                    </header>
                    <div className="mt-7 grid grid-cols-2 gap-x-8 gap-y-5 md:grid-cols-4">
                        <PdfMeta label="Contrato" value="025/2026 - Corredor Troncal" />
                        <PdfMeta label="Obra" value="001 - Corredor Troncal" />
                        <PdfMeta label="Data de abertura" value="23/07/2026" />
                        <PdfMeta label="Criado por" value="Marina Costa" />
                        <PdfMeta label="Contratante" value="X Cliente Engenharia" />
                        <PdfMeta label="Contratada" value="X Construtora Ltda." />
                        <PdfMeta label="Local" value="Trecho 2 / Estaca 145" />
                        <PdfMeta label="Disciplina" value="DRE - Drenagem" />
                        <PdfMeta label="Gravidade" value="Grave" />
                        <PdfMeta label="Latitude" value="-23.5565569" />
                        <PdfMeta label="Longitude" value="-46.6491232" />
                    </div>
                    <h2 className="mt-10 border-b border-slate-300 pb-2 text-sm font-bold uppercase text-slate-950">Observações e comentários</h2>
                    <PdfSection title="Descrição do problema" text="Infiltração identificada na junta de concretagem do trecho executado, com presença de umidade e falha localizada no tratamento da impermeabilização." />
                    <PdfSection title="Observação" text="Ocorrência verificada durante a inspeção de qualidade realizada no trecho 2." />
                    <PdfSection title="Ações corretivas recomendadas" text="Remover o material comprometido, tratar a junta, reaplicar a impermeabilização e apresentar evidências da execução." />
                </article>
            </div>
        </>
    );
}

function DashboardPreview() {
    const metrics = [
        ['Total de RNCs', '18', ClipboardX, 'blue'],
        ['Atraso resposta', '2', AlertTriangle, 'red'],
        ['Atraso execução', '1', AlertTriangle, 'red'],
        ['Em análise', '3', SearchCheck, 'amber'],
        ['Finalizadas', '12', CheckCircle2, 'green'],
    ];

    return (
        <>
            <PageHeader
                eyebrow="Relatório Não Conformidade"
                icon={Gauge}
                title="Dashboard RNC"
                subtitle="Visão consolidada das RNCs em Xconstruction"
                actions={<button type="button" className="sig-btn sig-btn-secondary">Ver RNCs</button>}
            />
            <div data-tour="rnc-dashboard-metrics" className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                {metrics.map(([label, value, Icon, color]) => (
                    <section key={label} className="sig-card p-4">
                        <div className="flex items-center justify-between">
                            <div><span className="eyebrow">{label}</span><strong className="mt-2 block text-3xl">{value}</strong></div>
                            <div className={`flex h-11 w-11 items-center justify-center rounded-lg ${tone(color)}`}><Icon size={21} /></div>
                        </div>
                    </section>
                ))}
            </div>
            <div data-tour="rnc-dashboard-analysis" className="mt-6 grid gap-6 xl:grid-cols-2">
                <section className="sig-card p-5">
                    <span className="eyebrow">Status das RNCs</span>
                        <div className="mt-5 flex items-center gap-8">
                        <div className="flex h-40 w-40 items-center justify-center rounded-full bg-[conic-gradient(#11805a_0_66%,#3164f4_66%_83%,#b58105_83%)]">
                            <div className="flex h-24 w-24 flex-col items-center justify-center rounded-full bg-white"><strong className="text-3xl">18</strong><span className="eyebrow mt-1">RNCs</span></div>
                        </div>
                        <div className="min-w-0 flex-1 space-y-2">
                            <Legend color="#11805a" label="Finalizadas" value="12" />
                            <Legend color="#0b5fff" label="Abertas" value="3" />
                            <Legend color="#b58105" label="Em análise" value="3" />
                        </div>
                    </div>
                </section>
                <section className="sig-card p-5">
                    <span className="eyebrow">Aberturas nos últimos 6 meses</span>
                    <div className="mt-5 flex h-48 items-end gap-4 border-b border-[var(--border)] px-3">
                        {[35, 62, 48, 82, 56, 72].map((height, index) => (
                            <div key={index} className="flex flex-1 items-end justify-center rounded-t-md bg-[var(--surface-muted)]" style={{ height: '100%' }}>
                                <div className="w-full rounded-t-md bg-violet-600" style={{ height: `${height}%` }} />
                            </div>
                        ))}
                    </div>
                    <div className="mt-2 grid grid-cols-6 text-center text-[10px] text-[var(--ink-500)]"><span>fev.</span><span>mar.</span><span>abr.</span><span>mai.</span><span>jun.</span><span>jul.</span></div>
                </section>
                <section className="sig-card overflow-hidden xl:col-span-2">
                    <header className="border-b border-[var(--border)] px-5 py-4"><span className="eyebrow">Últimas RNCs registradas</span></header>
                    <table className="sig-table"><thead><tr><th>RNC</th><th>Obra</th><th>Disciplina</th><th>Status</th><th>Abertura</th></tr></thead><tbody><tr><td className="mono font-semibold text-[var(--primary)]">001-2026</td><td>001 - Corredor Troncal</td><td>DRE - Drenagem</td><td><span className="sig-pill sig-pill-green">finalizada</span></td><td>23 jul. 2026</td></tr></tbody></table>
                </section>
                <section className="sig-card p-5">
                    <span className="eyebrow">Gravidade</span>
                    <div className="mt-4 grid gap-3">
                        <DashboardBar label="Grave" value="8" width="80%" />
                        <DashboardBar label="Média" value="6" width="60%" />
                        <DashboardBar label="Leve" value="4" width="40%" />
                    </div>
                </section>
                <section className="sig-card p-5">
                    <span className="eyebrow">Disciplina</span>
                    <div className="mt-4 grid gap-3">
                        <DashboardBar label="DRE - Drenagem" value="9" width="90%" />
                        <DashboardBar label="ARQ - Arquitetura" value="5" width="50%" />
                        <DashboardBar label="PAV - Pavimentação" value="4" width="40%" />
                    </div>
                </section>
            </div>
        </>
    );
}

function UserChoice({ initials, name, email, selected = false }) {
    return (
        <button type="button" className={`flex w-full items-center gap-3 rounded-md px-3 py-2 text-left ${selected ? 'bg-[var(--primary-50)] text-[var(--primary)]' : ''}`}>
            <span className="sig-avatar">{initials}</span>
            <span className="min-w-0 flex-1">
                <span className="block truncate text-[13px] font-semibold">{name}</span>
                <span className="block truncate text-[12px] text-[var(--ink-500)]">{email}</span>
            </span>
            {selected && <CheckCircle2 size={16} />}
        </button>
    );
}

function ResponsibleRow({ name, email, role }) {
    return (
        <tr>
            <td>
                <div className="flex items-center gap-3">
                    <span className="sig-avatar">{name.split(' ').map((part) => part[0]).slice(0, 2).join('')}</span>
                    <span><strong className="block">{name}</strong><span className="text-xs text-[var(--ink-500)]">{email}</span></span>
                </div>
            </td>
            <td><span className="mono text-xs">025/2026</span><strong className="block text-sm">Corredor Troncal</strong></td>
            <td><span className="text-sm font-semibold">{role}</span></td>
            <td><span className="text-sm font-semibold">23/07/2026</span><span className="block text-xs text-[var(--ink-500)]">11:40</span></td>
            <td><div className="flex gap-2"><button type="button" className="sig-btn sig-btn-secondary sig-btn-sm">Editar</button><button type="button" className="sig-btn sig-btn-secondary sig-btn-sm text-[var(--red)]">Remover</button></div></td>
        </tr>
    );
}

function RncSummary({ status, green = false }) {
    return (
        <section className="border-b border-[var(--border)] p-5">
            <div className="flex flex-wrap gap-2"><span className={`sig-pill ${green ? 'sig-pill-green' : 'sig-pill-amber'}`}>{status}</span><span className="sig-pill sig-pill-blue">DRE - Drenagem</span><span className="sig-pill sig-pill-red">Grave</span></div>
            <h2 className="mt-4 text-xl font-semibold">Resumo da não conformidade</h2>
            <div className="mt-4 grid gap-3 md:grid-cols-2"><Meta label="Contrato" value="025/2026 - Corredor Troncal" /><Meta label="Obra" value="001 - Corredor Troncal" /><Meta label="Contratante" value="X Cliente Engenharia" /><Meta label="Contratada" value="X Construtora Ltda." /></div>
        </section>
    );
}

function FakeField({ label, value }) {
    return <label className="block"><span className="eyebrow mb-1 block">{label}</span><span className="sig-input flex min-h-11 items-center text-sm text-[var(--ink-800)]">{value}</span></label>;
}

function FakeTextarea({ label, value }) {
    return <label className="block"><span className="eyebrow mb-1 block">{label}</span><span className="block min-h-24 rounded-lg border border-[var(--border)] bg-white px-3 py-2 text-sm leading-6 text-[var(--ink-700)]">{value}</span></label>;
}

function Meta({ label, value }) {
    return <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] px-3 py-2"><span className="eyebrow">{label}</span><strong className="mt-1 block text-[13px]">{value}</strong></div>;
}

function TextSection({ title, text }) {
    return <section className="border-b border-[var(--border)] p-5"><span className="eyebrow">{title}</span><p className="mt-3 text-sm leading-6 text-[var(--ink-600)]">{text}</p></section>;
}

function Photo({ label, compact = false }) {
    return <figure className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-2"><div className={`flex items-center justify-center rounded-md bg-[linear-gradient(145deg,#cbd5e1,#f8fafc)] text-slate-500 ${compact ? 'h-20' : 'h-32'}`}><ImagePlus size={24} /></div><figcaption className="mt-2 text-[11px] font-semibold text-[var(--ink-600)]">{label}</figcaption></figure>;
}

function Decision({ icon: Icon, label, tone: color, selected = false }) {
    return <div className={`rounded-lg border p-3 ${selected ? color === 'green' ? 'border-emerald-600 bg-emerald-50' : 'border-red-600 bg-red-50' : 'border-[var(--border)]'}`}><Icon size={17} className={color === 'green' ? 'text-emerald-700' : 'text-red-700'} /><strong className="mt-2 block text-sm">{label}</strong></div>;
}

function PdfThumbnail({ page, active = false, children }) {
    return (
        <div className="mb-5 text-center text-xs text-white/90">
            <div className={`mx-auto aspect-[210/297] w-[112px] bg-white p-2 ${active ? 'ring-4 ring-blue-300' : ''}`}>
                {children}
            </div>
            <span className="mt-3 block">{page}</span>
        </div>
    );
}

function CompanyLogo({ variant }) {
    const contractor = variant === 'contractor';

    return (
        <div className={`flex h-[54px] items-center ${contractor ? 'justify-end' : 'justify-start'}`}>
            <div className={`flex h-11 w-11 items-center justify-center rounded-full border-2 ${
                contractor
                    ? 'border-teal-500 bg-cyan-50 text-teal-700'
                    : 'border-slate-500 bg-slate-50 text-slate-700'
            }`}>
                <ShieldCheck size={24} />
            </div>
        </div>
    );
}

function PdfBadge({ tone: color, children }) {
    const classes = color === 'red'
        ? 'bg-[#fde7eb] text-[#c8364a]'
        : 'bg-[#e7efff] text-[#1d68d8]';

    return <span className={`inline-block rounded-full px-2 py-1 text-[10px] font-bold ${classes}`}>{children}</span>;
}

function Logo({ name }) {
    return <div className="flex items-center justify-center gap-2"><div className="flex h-12 w-12 items-center justify-center rounded-full border-2 border-blue-900 bg-blue-50 text-blue-900"><ShieldCheck size={23} /></div>{name && <span className="text-xs font-semibold text-slate-700">{name}</span>}</div>;
}

function PdfMeta({ label, value }) {
    return <div><p className="text-[9px] font-bold uppercase tracking-wider text-slate-500">{label}</p><p className="mt-1 text-[11px] font-semibold text-slate-900">{value}</p></div>;
}

function PdfSection({ title, text, first = false }) {
    return (
        <section className={`${first ? 'mt-3' : 'mt-3 border-t border-[#d8dde7] pt-3'}`}>
            <p className="text-[9px] font-bold uppercase tracking-[0.04em] text-[#5b6479]">{title}</p>
            <p className="mt-1 text-[11px] leading-[1.45] text-[#182033]">{text}</p>
        </section>
    );
}

function PdfPhoto() {
    return <div className="h-24 rounded border border-slate-300 bg-[linear-gradient(145deg,#dbe4ea,#f8fafc)]" />;
}

function Legend({ color, label, value }) {
    return <div className="flex items-center justify-between rounded-md bg-[var(--surface-muted)] px-3 py-2 text-xs"><span className="flex items-center gap-2"><i className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: color }} />{label}</span><strong>{value}</strong></div>;
}

function DashboardBar({ label, value, width }) {
    return (
        <div>
            <div className="flex items-center justify-between gap-3 text-xs"><strong>{label}</strong><span className="mono text-[var(--ink-500)]">{value}</span></div>
            <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-[var(--surface-muted)]"><div className="h-full rounded-full bg-[var(--primary)]" style={{ width }} /></div>
        </div>
    );
}

function tone(color) {
    const tones = {
        blue: 'bg-blue-50 text-blue-700',
        red: 'bg-red-50 text-red-700',
        amber: 'bg-amber-50 text-amber-700',
        green: 'bg-emerald-50 text-emerald-700',
    };
    return tones[color] || tones.blue;
}
