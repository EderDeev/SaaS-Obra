import RdoTour from '@/Components/RdoTour';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
    ArrowLeft,
    BarChart3,
    Building2,
    CalendarClock,
    CalendarDays,
    Camera,
    ChevronLeft,
    ChevronRight,
    CheckCircle2,
    ClipboardList,
    CloudSun,
    Construction,
    Copy,
    Download,
    Eye,
    FileSignature,
    FileText,
    HardHat,
    History,
    MessageSquareText,
    PenTool,
    Plus,
    RotateCcw,
    Save,
    Send,
    Settings,
    ShieldCheck,
    Smartphone,
    TrendingUp,
    UploadCloud,
    UserRoundCheck,
    Users,
    WifiOff,
} from 'lucide-react';

const sectionCards = [
    [CloudSun, 'Condições do tempo', 'Manhã, tarde, noite e chuva.'],
    [Users, 'Mão de obra', 'Efetivo próprio e terceirizado.'],
    [Construction, 'Equipamentos', 'Uso, disponibilidade e paralisações.'],
    [FileText, 'Atividades e ocorrências', 'Serviços executados e interferências.'],
    [Camera, 'Registro fotográfico', 'Fotos de campo e comentários.'],
    [MessageSquareText, 'Comentários', 'Construtora, gerenciadora e cliente.'],
];

export default function RdoTourPreview({ tenant, screen = 'settings' }) {
    return (
        <AuthenticatedLayout>
            <Head title="Tutorial operacional de Diário de Obra" />
            <main className={`mx-auto px-4 py-6 sm:px-6 ${screen === 'settings' ? 'max-w-6xl' : screen === 'rda' ? 'max-w-[1400px]' : screen === 'consolidation' || screen === 'approval' || screen === 'signature' ? 'max-w-7xl' : 'max-w-[1700px]'}`}>
                {screen === 'settings' && <SettingsPreview />}
                {screen === 'catalogs' && <CatalogsPreview />}
                {screen === 'responsibles' && <ResponsiblesPreview />}
                {screen === 'calendar' && <CalendarPreview />}
                {screen === 'rda' && <RdaPreview />}
                {screen === 'consolidation' && <ConsolidationPreview />}
                {screen === 'approval' && <ApprovalPreview />}
                {screen === 'signature' && <SignaturePreview />}
                {screen === 'dashboard' && <DashboardPreview />}
            </main>
            <RdoTour key={screen} section={screen} tenantSlug={tenant.slug} />
        </AuthenticatedLayout>
    );
}

function PageHeader({ title, description, eyebrow = 'Diário de Obra · RDO', action = null }) {
    return (
        <header className="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div>
                <span className="eyebrow">{eyebrow}</span>
                <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">{title}</h1>
                <p className="mt-1 max-w-3xl text-sm text-[var(--ink-500)]">{description}</p>
            </div>
            {action}
        </header>
    );
}

function SettingsPreview() {
    return (
        <>
            <PageHeader
                title="Parametrização"
                description="Defina quando e como os diários serão criados automaticamente."
                action={<button type="button" className="sig-btn sig-btn-secondary"><ArrowLeft size={17} /> Voltar ao calendário</button>}
            />
            <div className="space-y-4">
                <section data-tour="rdo-settings-scope" className="sig-card p-5">
                    <div className="mb-4 flex items-center gap-3">
                        <CalendarClock className="text-[var(--primary)]" size={22} />
                        <div>
                            <h2 className="text-lg font-bold">Escopo e geração</h2>
                            <p className="text-sm text-[var(--ink-500)]">Selecione todas as obras ou frentes de serviço que farão parte do RDO consolidado.</p>
                        </div>
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        <FakeField label="Contrato" value="025/2026 - Corredor Troncal" />
                        <FakeField label="Data inicial" value="27/07/2026" />
                        <FakeField label="Data final (opcional)" value="31/12/2026" />
                        <FakeField label="Horário de geração" value="00:00" />
                        <FakeField label="Responsável padrão" value="Definir depois" />
                    </div>
                    <div className="mt-4">
                        <div className="mb-2 flex items-center justify-between gap-3">
                            <span className="eyebrow">Obras / frentes de serviço</span>
                            <span className="text-xs font-semibold text-[var(--ink-500)]">2 selecionada(s)</span>
                        </div>
                        <div className="grid gap-2 md:grid-cols-2">
                            <SelectionRow code="001" name="Trecho Norte" />
                            <SelectionRow code="002" name="Trecho Sul" />
                        </div>
                    </div>
                    <div className="mt-4">
                        <span className="eyebrow mb-2 block">Dias que geram RDO</span>
                        <div className="flex flex-wrap gap-2">
                            {['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'].map((day) => (
                                <span key={day} className="rounded-lg border border-[var(--primary)] bg-[var(--primary-50)] px-4 py-2 text-sm font-bold text-[var(--primary)]">{day}</span>
                            ))}
                        </div>
                    </div>
                </section>

                <section className="sig-card p-5">
                    <div className="mb-4 flex items-center gap-3">
                        <Copy className="text-[var(--primary)]" size={22} />
                        <div>
                            <h2 className="text-lg font-bold">Continuidade do dia anterior</h2>
                            <p className="text-sm text-[var(--ink-500)]">Evita redigitação, mantendo cada RDO como um registro independente.</p>
                        </div>
                    </div>
                    <FakeToggle label="Copiar dados do RDO anterior" checked />
                    <div className="mt-3 grid gap-3 md:grid-cols-3">
                        <ToggleBox label="Copiar mão de obra" />
                        <ToggleBox label="Copiar equipamentos" />
                        <ToggleBox label="Copiar atividades pendentes" />
                    </div>
                </section>

                <section data-tour="rdo-settings-rules" className="sig-card p-5">
                    <h2 className="text-lg font-bold">Regras de preenchimento</h2>
                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        <ToggleBox label="Exigir ao menos uma foto para envio" />
                        <ToggleBox label="Usar assinatura digital" />
                        <FakeField label="Prazo para envio (dias)" value="7" hint="O prazo padrão é de 7 dias após a data do RDO." />
                        <ToggleBox label="Parametrização ativa" />
                    </div>
                </section>
                <div className="flex justify-end">
                    <button type="button" className="sig-btn sig-btn-primary"><Save size={17} /> Salvar parametrização</button>
                </div>
            </div>
        </>
    );
}

function CatalogsPreview() {
    return (
        <>
            <PageHeader title="Cadastros" description="Catálogos reutilizáveis para agilizar o preenchimento diário dos RDOs e RDAs." />
            <div data-tour="rdo-catalogs-tabs" className="sig-card flex flex-wrap gap-2 p-3">
                <button type="button" className="sig-btn sig-btn-primary"><Users size={16} /> Mão de obra</button>
                <button type="button" className="sig-btn sig-btn-secondary"><Construction size={16} /> Equipamentos</button>
                <button type="button" className="sig-btn sig-btn-secondary"><Building2 size={16} /> Subcontratadas</button>
            </div>
            <section className="sig-card mt-5 overflow-hidden">
                <header className="border-b border-[var(--border)] px-5 py-4"><h2 className="text-lg font-bold">Cadastrar mão de obra</h2></header>
                <div className="grid gap-4 p-5 md:grid-cols-[1fr_1fr_0.7fr_auto] md:items-end">
                    <FakeField label="Função / descrição" value="Pedreiro" />
                    <FakeField label="Classificação" value="Mão de obra direta" />
                    <FakeField label="Unidade" value="pessoa" />
                    <button type="button" className="sig-btn sig-btn-primary"><Plus size={16} /> Cadastrar</button>
                </div>
            </section>
            <section data-tour="rdo-catalogs-list" className="sig-card mt-5 overflow-hidden">
                <header className="border-b border-[var(--border)] px-5 py-4"><h2 className="text-lg font-bold">Mão de obra cadastrada</h2></header>
                <div className="divide-y divide-[var(--border)]">
                    <ListRow title="Pedreiro" meta="Mão de obra direta · pessoa" />
                    <ListRow title="Servente" meta="Mão de obra direta · pessoa" />
                    <ListRow title="Engenheiro de campo" meta="Mão de obra indireta · pessoa" />
                </div>
            </section>
        </>
    );
}

function ResponsiblesPreview() {
    return (
        <>
            <PageHeader title="Responsáveis" description="Defina quem preenche, aprova e assina em cada obra ou frente de serviço." />
            <section className="sig-card p-5">
                <span className="eyebrow">Contrato</span>
                <div className="mt-2 rounded-lg border border-[var(--border)] px-4 py-3 font-semibold">025/2026 - Corredor Troncal</div>
                <div className="mt-4 grid gap-3 md:grid-cols-3">
                    <CompanyBox label="Construtora" value="X Construtora" />
                    <CompanyBox label="Gerenciadora" value="X Gerenciadora" />
                    <CompanyBox label="Cliente" value="X Cliente" />
                </div>
            </section>
            <section data-tour="rdo-responsibles-form" className="sig-card mt-5 overflow-hidden">
                <header className="border-b border-[var(--border)] px-5 py-4">
                    <h2 className="text-lg font-bold">Cadastrar responsável do RDO</h2>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">Os responsáveis são vinculados por frente e etapa do fluxo.</p>
                </header>
                <div className="grid gap-4 p-5 md:grid-cols-3 xl:grid-cols-[1fr_1fr_1.2fr_auto] xl:items-end">
                    <FakeField label="Frente de serviço" value="001 - Trecho Norte" />
                    <FakeField label="Responsabilidade" value="Aprovação da gerenciadora" />
                    <FakeField label="Usuário" value="Marina Costa" />
                    <button type="button" className="sig-btn sig-btn-primary"><Plus size={16} /> Cadastrar</button>
                </div>
            </section>
            <section data-tour="rdo-responsibles-flow" className="sig-card mt-5 overflow-hidden">
                <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                    <div><h2 className="text-lg font-bold">Responsáveis por frente</h2><p className="text-sm text-[var(--ink-500)]">RDO, assinatura e preenchimento do RDA.</p></div>
                    <UserRoundCheck className="text-[var(--primary)]" size={22} />
                </header>
                <div className="divide-y divide-[var(--border)]">
                    <ResponsibilityRow name="Carlos Almeida" role="Preenchimento da construtora · RDO" />
                    <ResponsibilityRow name="Marina Costa" role="Aprovação da gerenciadora · RDO" />
                    <ResponsibilityRow name="João Martins" role="Assinatura digital · RDO" />
                    <ResponsibilityRow name="Paulo Santos" role="Preenchimento de campo · RDA" tone="blue" />
                </div>
            </section>
        </>
    );
}

function CalendarPreview() {
    const days = Array.from({ length: 35 }, (_, index) => index - 2);
    return (
        <>
            <PageHeader
                title="RDO"
                description="Acompanhe, preencha e envie os registros diários pelo calendário."
                action={(
                    <div className="flex gap-2">
                        <button type="button" className="sig-btn sig-btn-secondary"><HardHat size={16} /> Iniciar tour</button>
                        <button type="button" className="sig-btn sig-btn-primary"><Settings size={16} /> Parametrizar RDO</button>
                    </div>
                )}
            />
            <section data-tour="rdo-calendar-overview" className="sig-card p-4">
                <div className="grid gap-3 md:grid-cols-[1.4fr_0.7fr_0.7fr_auto] md:items-end">
                    <FakeField label="Contrato" value="025/2026 - Corredor Troncal" />
                    <FakeField label="Data inicial" value="01/07/2026" />
                    <FakeField label="Data final" value="31/07/2026" />
                    <button type="button" className="sig-btn sig-btn-secondary"><Download size={16} /> Baixar lote</button>
                </div>
            </section>
            <section className="sig-card mt-5 overflow-hidden p-4">
                <div className="mb-4 grid grid-cols-[1fr_auto_1fr] items-center">
                    <div className="flex gap-2">
                        <div className="inline-flex overflow-hidden rounded-lg border border-[var(--primary)]">
                            <button type="button" className="bg-[var(--primary)] px-3 py-2 text-white"><ChevronLeft size={18} /></button>
                            <button type="button" className="border-l border-white/20 bg-[var(--primary)] px-3 py-2 text-white"><ChevronRight size={18} /></button>
                        </div>
                        <button type="button" className="sig-btn sig-btn-primary">Hoje</button>
                    </div>
                    <h2 className="text-lg font-bold">Julho de 2026</h2>
                    <span />
                </div>
                <div className="grid grid-cols-7 border-l border-t border-[var(--border)] text-center text-sm font-bold">
                    {['dom.', 'seg.', 'ter.', 'qua.', 'qui.', 'sex.', 'sáb.'].map((day) => <div key={day} className="border-b border-r border-[var(--border)] bg-white p-3">{day}</div>)}
                    {days.map((day, index) => (
                        <div key={index} className={`min-h-28 border-b border-r border-[var(--border)] p-2 text-left text-sm font-normal ${day < 1 ? 'bg-slate-50 text-slate-300' : 'bg-white'}`}>
                            {day > 0 && day <= 31 ? day : ''}
                            {day === 23 && (
                                <div data-tour="rdo-calendar-day" className="mt-2 rounded bg-amber-100 px-2 py-1.5 text-[10px] font-bold text-amber-800">
                                    RDO-023 · Rascunho
                                </div>
                            )}
                            {day === 22 && <div className="mt-2 rounded bg-emerald-100 px-2 py-1.5 text-[10px] font-bold text-emerald-800">RDO-022 · Aprovado</div>}
                        </div>
                    ))}
                </div>
            </section>
        </>
    );
}

function RdaPreview() {
    return (
        <>
            <header className="mb-5 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <button type="button" className="mb-3 inline-flex items-center gap-2 text-sm font-bold text-[var(--primary)]"><ArrowLeft size={16} /> Voltar para o calendário</button>
                    <span className="eyebrow block">Diário de Obra · RDA</span>
                    <h1 className="mt-2 text-3xl font-bold text-[var(--ink-900)]">RDA-20260723-014</h1>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">23/07/2026 · 025/2026 - Corredor Troncal</p>
                    <div className="mt-3 w-full max-w-xl"><FakeField label="Obra / frente preenchida" value="001 - Trecho Norte" /></div>
                </div>
                <span className="rounded-full bg-amber-50 px-3 py-1 text-xs font-black text-amber-700">Rascunho</span>
            </header>

            <section data-tour="rda-mobile-offline" className="mb-5 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-blue-200 bg-blue-50 p-4">
                <div className="flex items-start gap-3">
                    <span className="flex size-10 items-center justify-center rounded-xl bg-blue-700 text-white"><Smartphone size={20} /></span>
                    <div>
                        <h2 className="font-bold text-blue-950">Preenchimento mobile no campo</h2>
                        <p className="mt-1 max-w-3xl text-sm text-blue-800">O mesmo formulário pode ser preenchido offline no aplicativo e sincronizado quando a conexão retornar.</p>
                    </div>
                </div>
                <span className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1.5 text-xs font-bold text-blue-800"><WifiOff size={14} /> Modo offline</span>
            </section>

            <div className="space-y-5">
                <div data-tour="rda-fields" className="space-y-5">
                    <RdaSection title="Condições de tempo" icon={CloudSun}>
                        <div className="grid gap-4 md:grid-cols-4">
                            <FakeField label="Manhã" value="Ensolarado" />
                            <FakeField label="Tarde" value="Parcialmente nublado" />
                            <FakeField label="Noite" value="Sem chuva" />
                            <FakeField label="Chuva" value="0,00 mm" />
                        </div>
                    </RdaSection>
                    <RdaSection title="Atividades e ocorrências" icon={ClipboardList}>
                        <div className="grid gap-4 md:grid-cols-[1fr_1.3fr_auto] md:items-end">
                            <FakeField label="Atividade" value="Concretagem da laje do bloco A" />
                            <FakeField label="Descrição / ocorrência" value="Serviço executado conforme planejamento diário." />
                            <button type="button" className="sig-btn sig-btn-primary"><Plus size={16} /> Adicionar</button>
                        </div>
                    </RdaSection>
                </div>
                <RdaSection title="Mão de obra" icon={Users}>
                    <ResourceTable rows={[['Pedreiro', 'Mão de obra direta', '8'], ['Servente', 'Mão de obra direta', '12']]} />
                </RdaSection>
                <RdaSection title="Equipamentos" icon={Construction}>
                    <ResourceTable rows={[['Betoneira', 'Operando', '2'], ['Caminhão bomba', 'Operando', '1']]} />
                </RdaSection>
                <RdaSection title="Subcontratadas" icon={Building2}>
                    <ResourceTable rows={[['Concretar Serviços', 'Concretagem', '1 equipe']]} />
                </RdaSection>
                <RdaSection title="Registro fotográfico" icon={Camera}>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {[1, 2, 3].map((photo) => (
                            <div key={photo} className="flex aspect-[16/8] items-center justify-center rounded-lg border border-dashed border-[var(--border)] bg-slate-50 text-slate-400"><Camera size={24} /></div>
                        ))}
                    </div>
                </RdaSection>
            </div>
            <div data-tour="rda-publish" className="sticky bottom-0 mt-6 flex flex-wrap justify-end gap-3 border-t border-[var(--border)] bg-[var(--surface)] py-4">
                <button type="button" className="sig-btn sig-btn-secondary"><Save size={16} /> Salvar rascunho</button>
                <button type="button" className="sig-btn sig-btn-primary"><Send size={16} /> Publicar RDA</button>
            </div>
        </>
    );
}

function ConsolidationPreview() {
    return (
        <>
            <PageHeader
                title="RDO-023/07/2026"
                eyebrow="Diário de Obra · 23/07/2026"
                description="001 - Trecho Norte"
                action={(
                    <div className="flex gap-2">
                        <button type="button" className="sig-btn sig-btn-secondary"><FileText size={16} /> Gerar PDF</button>
                        <button type="button" className="sig-btn sig-btn-secondary"><ArrowLeft size={16} /> Voltar ao calendário</button>
                    </div>
                )}
            />
            <div className="mb-4 grid gap-3 rounded-xl border border-[var(--border)] bg-white p-4 shadow-sm md:grid-cols-4">
                <PdfMeta label="Contrato" value="025/2026 - Corredor Troncal" />
                <PdfMeta label="Status" value="Rascunho" />
                <PdfMeta label="Responsável" value="Carlos Almeida" />
                <PdfMeta label="Origem" value="Geração automática" />
            </div>
            <div className="mb-4 rounded-xl border border-[var(--border)] bg-white p-4 shadow-sm">
                <span className="eyebrow">Obras / frentes participantes</span>
                <div className="mt-2 flex flex-wrap gap-2"><SelectedChip text="001 - Trecho Norte" /><SelectedChip text="002 - Trecho Sul" /></div>
            </div>
            <section data-tour="rdo-rda-import" className="mb-4 rounded-xl border border-blue-200 bg-blue-50 p-5">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <span className="eyebrow text-blue-700">RDA publicado</span>
                        <h2 className="mt-1 text-lg font-bold text-blue-950">Registros disponíveis para este RDO</h2>
                        <p className="mt-1 text-sm text-blue-800">Use os apontamentos de campo para preencher as seções da frente correspondente.</p>
                    </div>
                    <span className="rounded-full bg-white px-3 py-1 text-xs font-bold text-blue-700">1 RDA</span>
                </div>
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-white p-4 shadow-sm">
                    <div><strong>RDA-20260723-014</strong><p className="text-xs text-[var(--ink-500)]">Paulo Santos · publicado às 18:42</p></div>
                    <div className="flex gap-2">
                        <button type="button" className="sig-btn sig-btn-secondary">Visualizar RDA</button>
                        <button type="button" className="sig-btn sig-btn-primary"><UploadCloud size={16} /> Importar dados</button>
                    </div>
                </div>
            </section>
            <div className="mb-4 flex justify-end"><button type="button" className="sig-btn sig-btn-primary"><ClipboardList size={16} /> Preencher RDO completo</button></div>
            <div data-tour="rdo-sections" className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {sectionCards.map(([Icon, title, description], index) => (
                    <button type="button" key={title} className="sig-card p-5 text-left transition hover:-translate-y-0.5 hover:border-[var(--primary)]">
                        <div className="flex items-start justify-between gap-3">
                            <Icon size={23} className="text-[var(--primary)]" />
                            <span className={`rounded-full px-2 py-1 text-[10px] font-bold ${index < 4 ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{index < 4 ? '1/1 preenchida' : '0/1 preenchida'}</span>
                        </div>
                        <h2 className="mt-4 font-bold">{title}</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">{description}</p>
                        <span className="mt-4 inline-block text-xs font-bold text-[var(--primary)]">{index < 4 ? 'Editar seção →' : 'Abrir seção →'}</span>
                    </button>
                ))}
            </div>
        </>
    );
}

function ApprovalPreview() {
    return (
        <>
            <PageHeader title="RDO-023/07/2026" description="Fluxo de análise e aprovação por frente de serviço." />
            <section data-tour="rdo-workflow" className="sig-card overflow-hidden">
                <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                    <div><span className="eyebrow">Fluxo de análise e aprovação</span><h2 className="mt-1 text-lg font-bold">Em análise</h2></div>
                    <button type="button" className="sig-btn sig-btn-secondary"><History size={16} /> Histórico</button>
                </header>
                <div className="grid gap-3 bg-slate-50 p-4 md:grid-cols-3">
                    <FlowStep title="Construtora" company="X Construtora" completed />
                    <FlowStep title="Aprovação conjunta" company="X Gerenciadora + X Cliente" active />
                    <FlowStep title="Arquivo" company="RDO aprovado" />
                </div>
                <div data-tour="rdo-workflow-actions" className="p-5">
                    <span className="eyebrow">Parecer / resposta por frente</span>
                    <div className="mt-3 rounded-lg border border-[var(--border)] p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div><strong>001 - Trecho Norte</strong><p className="text-xs text-[var(--ink-500)]">Todas as seções foram preenchidas.</p></div>
                            <label className="inline-flex items-center gap-2 text-sm font-semibold"><input type="checkbox" checked readOnly /> Selecionada</label>
                        </div>
                        <div className="mt-4 min-h-20 rounded-lg border border-[var(--border)] bg-white px-3 py-2 text-sm text-[var(--ink-500)]">Registre o parecer, a ressalva ou o motivo da devolução.</div>
                    </div>
                    <div className="mt-4 flex flex-wrap justify-end gap-2">
                        <button type="button" className="sig-btn border-red-300 bg-red-50 text-red-700">Devolver</button>
                        <button type="button" className="sig-btn border-amber-300 bg-amber-50 text-amber-800">Aprovar com ressalvas</button>
                        <button type="button" className="sig-btn border-emerald-600 bg-emerald-600 text-white"><CheckCircle2 size={16} /> Aprovar</button>
                    </div>
                </div>
            </section>
        </>
    );
}

function SignaturePreview() {
    return (
        <>
            <PageHeader
                eyebrow="Diário de Obra · 23/07/2026"
                title="RDO-023/07/2026"
                description="001 - Trecho Norte"
                action={(
                    <div data-tour="rdo-pdf" className="flex gap-2">
                        <button type="button" className="sig-btn sig-btn-secondary"><FileText size={16} /> Gerar PDF</button>
                        <button type="button" className="sig-btn sig-btn-secondary"><ArrowLeft size={16} /> Voltar ao calendário</button>
                    </div>
                )}
            />
            <div className="mb-4 grid gap-3 rounded-xl border border-[var(--border)] bg-white p-4 shadow-sm md:grid-cols-4">
                <PdfMeta label="Contrato" value="025/2026 - Corredor Troncal" />
                <PdfMeta label="Status" value="Arquivado" />
                <PdfMeta label="Responsável" value="Carlos Almeida" />
                <PdfMeta label="Origem" value="Geração automática" />
            </div>
            <section className="mb-5 overflow-hidden rounded-xl border border-[var(--border)] bg-white shadow-sm">
                <header className="flex items-center justify-between border-b border-[var(--border)] px-5 py-4">
                    <div><span className="eyebrow">Fluxo de análise e aprovação</span><h2 className="mt-1 text-lg font-bold">Arquivado</h2></div>
                    <span className="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700">Aprovado</span>
                </header>
                <div className="grid gap-2 bg-slate-50/70 p-4 md:grid-cols-3">
                    <FlowStep title="Construtora" company="X Construtora" completed />
                    <FlowStep title="Aprovação conjunta" company="X Gerenciadora + X Cliente" completed />
                    <FlowStep title="Arquivo" company="RDO aprovado" active />
                </div>
            </section>
            <section data-tour="rdo-digital-signature" className="sig-card overflow-hidden">
                <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                    <div>
                        <span className="eyebrow">Assinatura digital</span>
                        <h2 className="mt-1 text-lg font-bold">Aguardando assinaturas</h2>
                        <p className="mt-1 text-sm text-[var(--ink-500)]">Após o RDO ser aprovado, envie o PDF para assinatura da construtora, gerenciadora e cliente.</p>
                    </div>
                    <button type="button" className="sig-btn sig-btn-primary"><Send size={16} /> Enviar para assinatura</button>
                </header>
                <div className="space-y-4 p-5">
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">Em assinatura</span>
                        <span className="text-[var(--ink-500)]">Enviado em 23/07/2026 às 17:42</span>
                    </div>
                    <div className="grid gap-3 md:grid-cols-3">
                        <Signer name="Carlos Almeida" role="Construtora" completed />
                        <Signer name="Marina Costa" role="Gerenciadora" />
                        <Signer name="João Martins" role="Cliente" />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" className="sig-btn sig-btn-secondary"><FileText size={16} /> Baixar PDF enviado</button>
                        <button type="button" className="sig-btn border-emerald-600 bg-emerald-600 text-white"><CheckCircle2 size={16} /> Baixar PDF assinado</button>
                    </div>
                </div>
            </section>
        </>
    );
}

function DashboardPreview() {
    return (
        <>
            <PageHeader
                eyebrow="Diário de Obra"
                title="Dashboard RDO"
                description="Visão gerencial dos RDOs por período, status e frente de serviço."
                action={<button type="button" className="sig-btn sig-btn-primary"><CalendarDays size={17} /> Abrir calendário</button>}
            />
            <section className="mb-5 grid gap-3 rounded-xl border border-[var(--border)] bg-white p-4 shadow-sm md:grid-cols-2">
                <FakeField label="Contrato" value="025/2026 - Corredor Troncal" />
                <FakeField label="Mês de referência" value="julho de 2026" />
            </section>
            <div data-tour="rdo-dashboard-metrics" className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <Kpi icon={ClipboardList} label="RDOs no mês" value="24" hint="Total gerado no filtro" />
                <Kpi icon={Send} label="Enviados" value="22" hint="Submetidos para análise" />
                <Kpi icon={ShieldCheck} label="Aprovados" value="19" hint="Arquivados/aprovados" tone="green" />
                <Kpi icon={RotateCcw} label="Retornados" value="2" hint="Voltaram para ajustes" tone="red" />
                <Kpi icon={TrendingUp} label="Preenchimento médio" value="91%" hint="Campos obrigatórios" tone="violet" />
            </div>
            <div data-tour="rdo-dashboard-charts" className="mt-5 grid gap-5 xl:grid-cols-2">
                <section className="sig-card p-5">
                    <div className="flex items-start justify-between">
                        <div><h2 className="font-black">RDOs por status</h2><p className="mt-1 text-sm text-[var(--ink-500)]">Distribuição operacional do período</p></div>
                        <span className="flex size-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600"><FileSignature size={18} /></span>
                    </div>
                    <DonutChart />
                </section>
                <section className="sig-card p-5">
                    <div className="flex items-start justify-between">
                        <div><h2 className="font-black">Evolução diária</h2><p className="mt-1 text-sm text-[var(--ink-500)]">Criados, enviados e aprovados no mês</p></div>
                        <span className="flex size-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600"><TrendingUp size={18} /></span>
                    </div>
                    <LineChart />
                </section>
            </div>
            <div className="mt-5 grid gap-5 xl:grid-cols-[1.15fr_0.85fr]">
                <section className="sig-card p-5">
                    <div><h2 className="font-black">RDOs por frente de serviço</h2><p className="mt-1 text-sm text-[var(--ink-500)]">Frentes com mais registros no período</p></div>
                    <div className="mt-6 space-y-4">
                        <ChartBar label="001 - Trecho Norte" value="14" width="82%" tone="bg-blue-600" />
                        <ChartBar label="002 - Trecho Sul" value="10" width="61%" tone="bg-emerald-600" />
                    </div>
                </section>
                <section className="sig-card overflow-hidden">
                    <header className="border-b border-[var(--border)] px-5 py-4"><h2 className="font-black">RDOs recentes</h2><p className="mt-1 text-sm text-[var(--ink-500)]">Últimos registros do período selecionado.</p></header>
                    <div className="divide-y divide-[var(--border)]">
                        <RecentRdo code="RDO-023/07/2026" meta="23/07/2026 · Em análise" progress="92%" />
                        <RecentRdo code="RDO-022/07/2026" meta="22/07/2026 · Arquivado" progress="100%" />
                        <RecentRdo code="RDO-021/07/2026" meta="21/07/2026 · Aprovado" progress="100%" />
                    </div>
                </section>
            </div>
        </>
    );
}

function FakeField({ label, value, multiline = false, hint = '' }) {
    return (
        <div>
            <span className="eyebrow mb-1.5 block">{label}</span>
            <div className={`rounded-lg border border-[var(--border)] bg-white px-3 py-2 text-sm font-semibold text-[var(--ink-800)] ${multiline ? 'min-h-20' : 'min-h-10'}`}>{value}</div>
            {hint && <span className="mt-1 block text-xs text-[var(--ink-500)]">{hint}</span>}
        </div>
    );
}

function SelectedChip({ text }) {
    return <span className="rounded-full bg-[var(--primary-50)] px-3 py-1.5 text-xs font-bold text-[var(--primary)]">{text}</span>;
}

function FakeToggle({ label, checked = false }) {
    return (
        <div className="flex items-center justify-between gap-4 py-3 text-sm font-semibold">
            <span>{label}</span>
            <span className={`relative h-6 w-11 rounded-full ${checked ? 'bg-[var(--primary)]' : 'bg-slate-300'}`}><span className={`absolute top-1 size-4 rounded-full bg-white ${checked ? 'right-1' : 'left-1'}`} /></span>
        </div>
    );
}

function ToggleBox({ label }) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border border-[var(--border)] bg-white px-4 py-3 text-sm font-semibold">
            <span>{label}</span>
            <span className="relative h-6 w-11 rounded-full bg-[var(--primary)]"><span className="absolute right-1 top-1 size-4 rounded-full bg-white" /></span>
        </div>
    );
}

function SelectionRow({ code, name }) {
    return (
        <div className="flex items-center gap-3 rounded-lg border border-[var(--primary)] bg-[var(--primary-50)] px-4 py-3">
            <span className="flex size-5 items-center justify-center rounded bg-[var(--primary)] text-xs font-black text-white">✓</span>
            <span><strong className="mono text-xs">{code}</strong><span className="ml-2 font-semibold">{name}</span></span>
        </div>
    );
}

function RdaSection({ title, icon: Icon, children }) {
    return (
        <section className="sig-card overflow-hidden">
            <header className="flex items-center gap-3 border-b border-[var(--border)] px-5 py-4">
                <span className="flex size-10 items-center justify-center rounded-xl bg-[var(--primary-50)] text-[var(--primary)]"><Icon size={19} /></span>
                <h2 className="text-lg font-black text-[var(--ink-900)]">{title}</h2>
            </header>
            <div className="p-5">{children}</div>
        </section>
    );
}

function ResourceTable({ rows }) {
    return (
        <div className="overflow-hidden rounded-lg border border-[var(--border)]">
            <div className="grid grid-cols-[1.2fr_1fr_0.35fr] bg-slate-50 px-4 py-2 text-[10px] font-bold uppercase text-[var(--ink-500)]">
                <span>Item</span><span>Classificação / situação</span><span className="text-right">Quantidade</span>
            </div>
            {rows.map(([item, meta, quantity]) => (
                <div key={`${item}-${meta}`} className="grid grid-cols-[1.2fr_1fr_0.35fr] border-t border-[var(--border)] px-4 py-3 text-sm">
                    <strong>{item}</strong><span className="text-[var(--ink-500)]">{meta}</span><strong className="text-right">{quantity}</strong>
                </div>
            ))}
        </div>
    );
}

function ListRow({ title, meta, compact = false }) {
    return (
        <div className={`flex items-center justify-between gap-4 ${compact ? 'py-2' : 'px-5 py-3'}`}>
            <div><strong className="text-sm">{title}</strong><p className="text-xs text-[var(--ink-500)]">{meta}</p></div>
            {!compact && <button type="button" className="text-xs font-bold text-[var(--primary)]">Editar</button>}
        </div>
    );
}

function CompanyBox({ label, value }) {
    return <div className="rounded-lg border border-[var(--border)] bg-slate-50 p-4"><span className="eyebrow">{label}</span><strong className="mt-1 block text-sm">{value}</strong></div>;
}

function ResponsibilityRow({ name, role, tone = 'green' }) {
    return <div className="flex items-center gap-3 px-5 py-3"><span className={`flex size-9 items-center justify-center rounded-full text-xs font-bold ${tone === 'blue' ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700'}`}>{name.split(' ').map((part) => part[0]).slice(0, 2).join('')}</span><div><strong className="text-sm">{name}</strong><p className="text-xs text-[var(--ink-500)]">{role}</p></div></div>;
}

function FlowStep({ title, company, active = false, completed = false }) {
    return (
        <div className={`rounded-lg border p-4 ${active ? 'border-[var(--primary)] bg-white shadow-sm' : completed ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50'}`}>
            <div className="flex items-center gap-2">{completed ? <CheckCircle2 size={17} className="text-emerald-600" /> : <span className={`size-3 rounded-full ${active ? 'bg-[var(--primary)]' : 'bg-slate-300'}`} />}<strong className="text-sm">{title}</strong></div>
            <p className="mt-1 text-xs text-[var(--ink-500)]">{company}</p>
        </div>
    );
}

function PdfMeta({ label, value }) {
    return <div><span className="block font-bold uppercase text-slate-500">{label}</span><strong className="mt-1 block">{value}</strong></div>;
}

function Signer({ name, role, completed = false }) {
    return (
        <div className="rounded-lg border border-[var(--border)] p-3">
            <div className="flex items-center justify-between gap-2"><strong className="text-sm">{role}</strong><span className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${completed ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700'}`}>{completed ? 'Assinado' : 'Pendente'}</span></div>
            <p className="mt-2 text-sm font-semibold">{name}</p>
            <p className="text-xs text-[var(--ink-500)]">{name.toLowerCase().replace(' ', '.')}@empresa.com.br</p>
        </div>
    );
}

function Kpi({ icon: Icon, label, value, hint, tone = 'blue' }) {
    const tones = {
        blue: 'bg-blue-50 text-blue-700',
        green: 'bg-emerald-50 text-emerald-700',
        red: 'bg-red-50 text-red-700',
        violet: 'bg-violet-50 text-violet-700',
    };

    return (
        <section className="sig-card p-5">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <span className="text-xs font-bold uppercase tracking-[0.16em] text-[var(--ink-500)]">{label}</span>
                    <strong className="mt-2 block text-2xl font-black">{value}</strong>
                    <p className="mt-1 text-sm text-[var(--ink-500)]">{hint}</p>
                </div>
                <span className={`flex size-11 shrink-0 items-center justify-center rounded-2xl ${tones[tone]}`}><Icon size={19} /></span>
            </div>
        </section>
    );
}

function ChartBar({ label, value, width, tone }) {
    return <div><div className="mb-1.5 flex justify-between text-sm"><span>{label}</span><strong>{value}</strong></div><div className="h-2 rounded-full bg-slate-100"><div className={`h-2 rounded-full ${tone}`} style={{ width }} /></div></div>;
}

function DonutChart() {
    return (
        <div className="mt-5 flex min-h-[230px] items-center justify-center gap-10">
            <div className="relative size-40 rounded-full" style={{ background: 'conic-gradient(#059669 0 58%, #2563eb 58% 78%, #f59e0b 78% 91%, #ef4444 91% 100%)' }}>
                <div className="absolute inset-7 flex items-center justify-center rounded-full bg-white text-center"><span><strong className="block text-2xl">24</strong><small className="text-[var(--ink-500)]">RDOs</small></span></div>
            </div>
            <div className="space-y-2 text-sm">
                <Legend tone="bg-emerald-600" label="Arquivados" value="14" />
                <Legend tone="bg-blue-600" label="Em análise" value="5" />
                <Legend tone="bg-amber-500" label="Rascunhos" value="3" />
                <Legend tone="bg-red-500" label="Retornados" value="2" />
            </div>
        </div>
    );
}

function Legend({ tone, label, value }) {
    return <div className="flex min-w-36 items-center justify-between gap-4"><span className="flex items-center gap-2"><i className={`size-2.5 rounded-full ${tone}`} />{label}</span><strong>{value}</strong></div>;
}

function LineChart() {
    return (
        <div className="mt-6 min-h-[230px] rounded-xl bg-slate-50 p-4">
            <div className="flex h-44 items-end gap-3 border-b border-l border-slate-200 px-3">
                {[32, 44, 38, 72, 58, 88, 76, 96, 82, 100, 91, 112].map((height, index) => (
                    <div key={index} className="flex h-full flex-1 items-end gap-1">
                        <span className="w-1/2 rounded-t bg-blue-500" style={{ height }} />
                        <span className="w-1/2 rounded-t bg-emerald-500" style={{ height: Math.max(18, height - 18) }} />
                    </div>
                ))}
            </div>
            <div className="mt-3 flex justify-center gap-5 text-xs text-[var(--ink-500)]"><Legend tone="bg-blue-500" label="Criados" value="" /><Legend tone="bg-emerald-500" label="Aprovados" value="" /></div>
        </div>
    );
}

function RecentRdo({ code, meta, progress }) {
    return (
        <div className="flex items-center justify-between gap-4 px-5 py-4">
            <div className="min-w-0 flex-1">
                <strong className="font-mono text-sm text-[var(--primary)]">{code}</strong>
                <p className="mt-1 text-xs text-[var(--ink-500)]">{meta}</p>
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-[var(--primary)]" style={{ width: progress }} /></div>
            </div>
            <button type="button" className="inline-flex items-center gap-1.5 rounded-lg bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700"><Eye size={14} /> Ver</button>
        </div>
    );
}
