import ProjectTour from '@/Components/ProjectTour';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    ChevronUp,
    ClipboardCheck,
    ClipboardList,
    Download,
    Eye,
    FileSearch,
    FileText,
    FileUp,
    Filter,
    GitCompareArrows,
    History,
    ListFilter,
    Search,
    Send,
    UploadCloud,
    UserRoundCog,
    Users,
    X,
} from 'lucide-react';

const screenCopy = {
    submit: { title: 'Submeter projeto' },
    review: { title: 'Analisar projeto' },
    responsibles: { title: 'Responsáveis' },
    'master-list': { title: 'Lista Mestra' },
    revisions: { title: 'Projetos revisados' },
};

export default function ProjectModuleTourPreview({ tenant, section }) {
    return (
        <AuthenticatedLayout>
            <Head title={screenCopy[section]?.title || 'Projetos'} />

            <section className="sig-content grid gap-5">
                {section === 'submit' && <SubmitPreview />}
                {section === 'review' && <ReviewPreview />}
                {section === 'responsibles' && <ResponsiblesPreview />}
                {section === 'master-list' && <MasterListPreview />}
                {section === 'revisions' && <RevisionsPreview />}
            </section>

            <ProjectTour key={section} section={section} tenantSlug={tenant.slug} />
        </AuthenticatedLayout>
    );
}

function SubmitPreview() {
    return (
        <>
            <section className="sig-card overflow-hidden">
                <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-4">
                    <div>
                        <div className="flex items-center gap-2 text-[var(--ink-500)]"><FileUp size={14} /><span className="eyebrow">Projetos submetidos</span></div>
                        <h2 className="mt-1 text-[15px] font-semibold">2 de 2 documentos</h2>
                    </div>
                    <div className="flex gap-2">
                        <button type="button" className="sig-btn sig-btn-primary sig-btn-sm"><UploadCloud size={14} /> Submeter projeto</button>
                        <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm"><Eye size={14} /> Analisar projeto</button>
                    </div>
                </header>

                <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 md:grid-cols-3 xl:grid-cols-5">
                    <SelectField label="Contrato" value="Todos os contratos" />
                    <SelectField label="Obra" value="Todas as obras" />
                    <SelectField label="Disciplina" value="Todas as disciplinas" />
                    <SelectField label="Situação" value="Ativos e inativos" />
                    <TextField label="Busca" value="Buscar documento" />
                </div>

                <ProjectRow title="Planta do pavimento tipo" status="Aprovado" revision="R02" />
                <ProjectRow title="Projeto estrutural - Bloco A" status="Em revisão" revision="R03" />
                <footer className="flex items-center justify-between border-t border-[var(--border)] px-5 py-4 text-sm text-[var(--ink-500)]">
                    <span>Exibindo 1 a 2 de 2 projeto(s).</span>
                    <div className="flex gap-2"><button className="sig-btn sig-btn-secondary sig-btn-sm" type="button">Anterior</button><button className="sig-btn sig-btn-secondary sig-btn-sm" type="button">Próxima</button></div>
                </footer>
            </section>

            <div className="pointer-events-none fixed inset-0 z-40 grid place-items-center overflow-y-auto bg-[rgba(11,16,32,0.42)] p-4 sm:p-6">
                <aside data-tour="project-submit-form" className="my-auto max-h-[calc(100vh-2rem)] w-full max-w-[760px] overflow-y-auto rounded-lg border border-[var(--border)] bg-white p-5 shadow-[0_24px_80px_rgba(11,16,32,0.24)] sm:max-h-[calc(100vh-3rem)] sm:p-6">
                    <header className="flex items-start justify-between gap-3">
                        <div>
                            <div className="flex items-center gap-2 text-[var(--ink-500)]"><FileUp size={14} /><span className="eyebrow">Projetos</span></div>
                            <h1 className="mt-2 text-xl font-semibold">Submeter projeto</h1>
                            <p className="mt-1 text-sm leading-5 text-[var(--ink-500)]">Envie arquivos técnicos por contrato, obra, disciplina e revisão. Todo envio passa por análise e aprovação.</p>
                        </div>
                        <button type="button" className="sig-btn sig-btn-ghost !min-h-9 !px-2" title="Fechar"><X size={18} /></button>
                    </header>

                    <div data-tour="project-submit-fields" className="mt-5 grid gap-3 sm:grid-cols-2">
                        <SelectField label="Contrato" value="CT-001 - Jardim Central" />
                        <SelectField label="Obra" value="001 - Jardim Central" />
                        <SelectField label="Disciplina" value="ARQ - Arquitetura" />
                        <SelectField label="Fase do projeto" value="EXE - Projeto executivo" />
                        <SelectField label="Tipo de documento" value="Projeto" />
                        <DemoField label="Título" value="Planta do pavimento tipo" />
                        <div data-tour="project-submit-confirm" className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
                            <DemoField label="Sequencial" value="001" />
                            <DemoField label="Próxima revisão" value="R02" />
                        </div>
                        <div className="sm:col-span-2"><DemoField label="EAP prevista" value="CT001-001-ARQ-EXE-PRJ-001-R02" /></div>
                        <div className="sm:col-span-2">
                            <span className="eyebrow mb-1 block">Arquivo</span>
                            <button type="button" className="sig-btn sig-btn-secondary w-full justify-start"><UploadCloud size={15} /> Selecionar arquivo</button>
                            <p className="mt-1 text-xs text-[var(--ink-400)]">.dwg, .ifc, .rvt, .pdf, .dwfx ou .dwf. Máximo 50 MB.</p>
                        </div>
                        <div className="flex justify-end sm:col-span-2">
                            <button type="button" className="sig-btn sig-btn-primary mt-1"><Send size={15} /> Revisar e confirmar</button>
                        </div>
                    </div>
                </aside>
            </div>
        </>
    );
}

function ReviewPreview() {
    return (
        <>
            <ModuleHeader icon={FileSearch} title="Analisar projeto" description="Verifique os projetos submetidos e aprove a entrada na árvore principal somente depois da análise." action="Submeter projeto" />

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Metric label="Em análise" value="1" tone="blue" />
                <Metric label="Em aprovação" value="1" tone="amber" />
                <Metric label="Aprovados" value="18" tone="green" />
                <Metric label="Reprovados" value="1" tone="red" />
            </div>

            <section className="sig-card overflow-hidden">
                <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 md:grid-cols-3">
                    <SelectField label="Contrato" value="Todos os contratos" />
                    <SelectField label="Status" value="Todos os status" />
                    <TextField label="Busca" value="Buscar projeto" />
                </div>

                <article data-tour="project-review-project">
                    <div className="border-b-2 border-blue-500 px-5 py-4">
                        <div className="flex items-center justify-between gap-3">
                            <div className="flex flex-wrap items-center gap-2"><strong>Planta do pavimento tipo</strong><span className="sig-pill sig-pill-blue">R02</span><span className="sig-pill sig-pill-amber">Em aprovação</span></div>
                            <ChevronUp size={16} />
                        </div>
                        <p className="mt-1 text-xs text-[var(--ink-500)]">CT001-001-ARQ-EXE-PRJ-001-R02</p>
                        <ProjectMetadata />
                    </div>

                    <div className="bg-[var(--surface-muted)] px-5 py-4">
                        <div className="grid gap-3 border-b border-[var(--border)] pb-4 md:grid-cols-4">
                            <Info label="Submetido por" value="Marina Costa - 22/07/2026" />
                            <Info label="Arquivo" value="CT001-001-ARQ-EXE-PRJ-001-R02.dwg" />
                            <Info label="Tamanho" value="2,4 MB" />
                            <Info label="Status APS" value="Pronto para visualização" />
                        </div>
                        <div data-tour="project-review-checklist" className="mt-4 flex flex-wrap gap-2">
                            <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm"><Download size={14} /> Baixar</button>
                            <button type="button" className="sig-btn sig-btn-primary sig-btn-sm"><Eye size={14} /> Checklist</button>
                            <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm"><FileText size={14} /> Visualizar CAP</button>
                        </div>
                        <div data-tour="project-review-decision" className="mt-4 grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                            <DemoField label="Observação da aprovação" value="Projeto conferido e pronto para liberação." />
                            <div className="flex flex-wrap gap-2">
                                <button type="button" className="sig-btn sig-btn-danger"><X size={14} /> Reprovar</button>
                                <button type="button" className="sig-btn sig-btn-primary"><Check size={14} /> Aprovar para árvore</button>
                            </div>
                        </div>
                    </div>
                </article>
            </section>
        </>
    );
}

function RevisionsPreview() {
    return (
        <>
            <ModuleHeader icon={ClipboardList} title="Projetos revisados" description="Histórico das revisões que geraram CAP, com motivo, impactos e registros de análise e aprovação." metricLabel="CAPs registradas" metricValue="3" />

            <section className="sig-card overflow-hidden">
                <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 md:grid-cols-[minmax(220px,320px)_1fr]">
                    <SelectField label="Contrato" value="Todos os contratos" />
                    <TextField label="Busca" value="Buscar por CAP, EAP, obra ou disciplina" />
                </div>
                <RevisionRow title="Planta do pavimento tipo" cap="CT001-001-ARQ-EXE-CAP-001-R02" from="R01" to="R02" />
                <article data-tour="project-revisions">
                    <div className="border-b-2 border-blue-500 px-5 py-4">
                        <div className="flex items-center justify-between gap-3">
                            <div className="flex flex-wrap items-center gap-2"><strong>Projeto estrutural - Bloco A</strong><span className="sig-pill sig-pill-amber">CT001-001-EST-EXE-CAP-002-R03</span><span className="sig-pill sig-pill-green">Aprovado</span></div>
                            <ChevronUp size={16} />
                        </div>
                        <p className="mt-1 text-xs text-[var(--ink-500)]">CT001-001-EST-EXE-PRJ-002-R03</p>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><Info label="Contrato" value="CT-001 - Jardim Central" /><Info label="Obra" value="001 - Jardim Central" /><Info label="Disciplina" value="EST - Estruturas" /><Info label="Revisão" value="R02 → R03" /></div>
                    </div>
                    <div className="bg-[var(--surface-muted)] px-5 py-4">
                        <div className="grid gap-3 md:grid-cols-4"><Info label="Solicitante" value="Ederson Moreira" /><Info label="CAP registrada em" value="22/07/2026, 14:37" /><Info label="Projeto atual" value="R03" /><Info label="Comentários nesta revisão" value="2 comentário(s)" /></div>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <button type="button" className="sig-btn sig-btn-primary sig-btn-sm"><History size={14} /> Histórico</button>
                            <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm"><FileText size={14} /> CAP PDF</button>
                            <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm"><GitCompareArrows size={14} /> Comparar</button>
                        </div>
                    </div>
                </article>
                <footer className="border-t border-[var(--border)] px-5 py-4 text-sm text-[var(--ink-500)]">Exibindo 1 a 2 de 2 CAP(s).</footer>
            </section>
        </>
    );
}

function MasterListPreview() {
    return (
        <div data-tour="project-master-list" className="grid gap-5">
            <ModuleHeader icon={ClipboardCheck} title="Lista Mestra" description="Relação controlada dos projetos por contrato, obra, disciplina, fase, tipo e revisão." />

            <section className="sig-card overflow-visible">
                <header className="flex items-center gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4">
                    <span className="grid h-10 w-10 place-items-center rounded-lg bg-white text-[var(--primary)] shadow-sm"><Filter size={18} /></span>
                    <div><h2 className="text-sm font-semibold">Filtros da lista</h2><p className="text-sm text-[var(--ink-500)]">Refine a Lista Mestra antes de gerar PDF ou Excel.</p></div>
                </header>
                <div className="grid gap-3 px-5 py-4 md:grid-cols-2 xl:grid-cols-3">
                    <SelectField label="Contrato" value="CT-001, CT-002" />
                    <SelectField label="Obra" value="001, 101" />
                    <SelectField label="Disciplina" value="ARQ, URB" />
                    <SelectField label="Fase" value="EXE, AP" />
                    <SelectField label="Tipo" value="Projeto, Modelo BIM" />
                    <SelectField label="Status" value="Aprovado" />
                </div>
                <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--border)] px-5 py-4">
                    <div className="flex flex-wrap gap-2"><span className="sig-pill sig-pill-blue">ARQ</span><span className="sig-pill sig-pill-blue">URB</span><span className="sig-pill sig-pill-muted">EXE</span></div>
                    <button type="button" className="sig-btn sig-btn-primary"><ListFilter size={15} /> Gerar Lista</button>
                </footer>
            </section>

            <section className="sig-card overflow-hidden">
                <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-4 py-3">
                    <div><h2 className="text-base font-semibold">Projetos encontrados</h2><p className="text-sm text-[var(--ink-500)]">24 resultados filtrados.</p></div>
                    <div className="flex gap-2"><button type="button" className="sig-btn sig-btn-secondary sig-btn-sm"><FileText size={14} /> Baixar PDF</button><button type="button" className="sig-btn sig-btn-primary sig-btn-sm"><Download size={14} /> Baixar Excel</button></div>
                </header>
                <div className="grid gap-2 px-4 py-3 text-sm md:grid-cols-[1.4fr_1fr_1fr_0.7fr]"><strong>CT001-001-ARQ-EXE-PRJ-001-R02</strong><span>001 - Jardim Central</span><span>ARQ - Arquitetura</span><span className="sig-pill sig-pill-green justify-self-start">Aprovado</span></div>
            </section>
        </div>
    );
}

function ResponsiblesPreview() {
    return (
        <section data-tour="project-responsibles" className="grid gap-6 xl:grid-cols-[430px_minmax(0,1fr)]">
            <div data-tour="project-responsibles-form" className="sig-card p-5">
                <div className="flex items-center gap-2 text-[var(--ink-500)]"><UserRoundCog size={14} /><span className="eyebrow">Projetos</span></div>
                <h1 className="mt-2 text-xl font-semibold">Responsáveis</h1>
                <p className="mt-1 text-sm text-[var(--ink-500)]">Aloque os usuários responsáveis por analisar ou aprovar projetos de cada disciplina dentro do contrato.</p>
                <div className="mt-5 grid gap-4">
                    <SelectField label="Contrato" value="CT-001 - Jardim Central" />
                    <SelectField label="Tipo de responsabilidade" value="Análise da disciplina" />
                    <div>
                        <div className="mb-1 flex items-center justify-between"><span className="eyebrow">Disciplinas</span><span className="text-xs text-blue-600">Todas &nbsp; Limpar</span></div>
                        <div className="rounded-lg border border-[var(--border)] bg-white p-2">
                            <Discipline name="ARQ - Arquitetura" color="bg-purple-500" checked />
                            <Discipline name="DRE - Drenagem" color="bg-blue-600" />
                            <Discipline name="PAV - Pavimentação" color="bg-emerald-400" />
                        </div>
                        <p className="mt-1 text-xs text-[var(--ink-500)]">1 disciplina selecionada</p>
                    </div>
                    <TextField label="Usuário responsável" value="Pesquisar por nome ou e-mail" />
                    <button type="button" className="sig-btn sig-btn-primary"><Users size={15} /> Salvar responsável</button>
                </div>
            </div>

            <div data-tour="project-responsibles-list" className="sig-card overflow-hidden">
                <header className="border-b border-[var(--border)] px-5 py-4">
                    <div className="flex items-center gap-2 text-[var(--ink-500)]"><Users size={14} /><span className="eyebrow">Fluxo por disciplina</span></div>
                    <h2 className="mt-1 text-[15px] font-semibold">3 de 3 usuários · 18 vínculos</h2>
                </header>
                <div className="grid gap-3 border-b border-[var(--border)] bg-[var(--surface-muted)] px-5 py-4 md:grid-cols-2 xl:grid-cols-4">
                    <SelectField label="Contrato" value="Todos os contratos" />
                    <SelectField label="Disciplina" value="Todas as disciplinas" />
                    <SelectField label="Tipo" value="Todos os tipos" />
                    <TextField label="Usuário" value="Buscar por nome ou e-mail" />
                </div>
                <Responsible name="Admin Plataforma" email="admin@obras.test" initials="AP" />
                <Responsible name="Ederson Moreira" email="ederson@exemplo.com" initials="EM" />
                <Responsible name="Marina Costa" email="marina@exemplo.com" initials="MC" />
            </div>
        </section>
    );
}

function ModuleHeader({ icon: Icon, title, description, action = null, metricLabel = null, metricValue = null }) {
    return (
        <header className="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div className="flex items-center gap-2 text-[var(--ink-500)]"><Icon size={15} /><span className="eyebrow">Projetos</span></div>
                <h1 className="mt-1 text-xl font-semibold">{title}</h1>
                <p className="mt-1 text-sm text-[var(--ink-500)]">{description}</p>
            </div>
            {action && <button type="button" className="sig-btn sig-btn-secondary"><Send size={15} /> {action}</button>}
            {metricLabel && <div className="sig-card min-w-40 px-4 py-3"><div className="eyebrow">{metricLabel}</div><div className="mt-1 text-lg font-semibold">{metricValue}</div></div>}
        </header>
    );
}

function ProjectRow({ title, status, revision }) {
    return (
        <article className="border-b border-[var(--border)] px-5 py-4">
            <div className="flex items-center justify-between gap-3"><div className="flex flex-wrap items-center gap-2"><strong>{title}</strong><span className={status === 'Aprovado' ? 'sig-pill sig-pill-green' : 'sig-pill sig-pill-amber'}>{status}</span></div><ChevronDown size={16} /></div>
            <p className="mt-1 text-xs text-[var(--ink-500)]">CT001-001-ARQ-EXE-PRJ-001-{revision}</p>
            <ProjectMetadata />
        </article>
    );
}

function ProjectMetadata() {
    return <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><Info label="Contrato" value="CT-001 - Jardim Central" /><Info label="Obra" value="001 - Jardim Central" /><Info label="Disciplina" value="ARQ - Arquitetura" /><Info label="Fase" value="EXE - Projeto executivo" /></div>;
}

function RevisionRow({ title, cap, from, to }) {
    return (
        <article className="border-b border-[var(--border)] px-5 py-4">
            <div className="flex items-center justify-between gap-3"><div className="flex flex-wrap items-center gap-2"><strong>{title}</strong><span className="sig-pill sig-pill-amber">{cap}</span><span className="sig-pill sig-pill-green">Aprovado</span></div><ChevronDown size={16} /></div>
            <p className="mt-1 text-xs text-[var(--ink-500)]">CT001-001-ARQ-EXE-PRJ-001-{to}</p>
            <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><Info label="Contrato" value="CT-001 - Jardim Central" /><Info label="Obra" value="001 - Jardim Central" /><Info label="Disciplina" value="ARQ - Arquitetura" /><Info label="Revisão" value={`${from} → ${to}`} /></div>
        </article>
    );
}

function SelectField({ label, value }) {
    return <label><span className="eyebrow mb-1 block">{label}</span><span className="sig-input flex items-center justify-between gap-2 bg-white text-sm"><span className="min-w-0 truncate">{value}</span><ChevronDown className="shrink-0 text-[var(--ink-400)]" size={14} /></span></label>;
}

function TextField({ label, value }) {
    return <label><span className="eyebrow mb-1 flex items-center gap-1"><Search size={12} />{label}</span><span className="sig-input flex items-center gap-2 bg-white text-sm text-[var(--ink-400)]"><span className="truncate">{value}</span></span></label>;
}

function DemoField({ label, value }) {
    return <label><span className="eyebrow mb-1 block">{label}</span><span className="sig-input block truncate bg-white text-sm text-[var(--ink-700)]">{value}</span></label>;
}

function Info({ label, value }) {
    return <div className="min-w-0"><span className="eyebrow block">{label}</span><span className="mt-1 block truncate text-sm text-[var(--ink-700)]">{value}</span></div>;
}

function Metric({ label, value, tone }) {
    const colors = { blue: 'bg-blue-50 text-blue-700', amber: 'bg-amber-50 text-amber-700', green: 'bg-emerald-50 text-emerald-700', red: 'bg-red-50 text-red-700' };
    return <div className="sig-card p-4"><span className="eyebrow">{label}</span><strong className={`mt-3 grid h-10 w-10 place-items-center rounded-lg text-lg ${colors[tone]}`}>{value}</strong></div>;
}

function Discipline({ name, color, checked = false }) {
    return <div className={`flex items-center gap-3 rounded-md px-2 py-2 text-sm ${checked ? 'bg-blue-50 text-blue-700' : ''}`}><span className={`h-3 w-3 rounded-full ${color}`} /><strong className="flex-1">{name}</strong><span className={`grid h-5 w-5 place-items-center rounded border ${checked ? 'border-blue-600 bg-blue-600 text-white' : 'border-[var(--border)]'}`}>{checked && <Check size={13} />}</span></div>;
}

function Responsible({ name, email, initials }) {
    return (
        <article className="flex items-start gap-3 border-b border-[var(--border)] px-5 py-4">
            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-blue-100 text-sm font-semibold text-blue-700">{initials}</span>
            <div className="min-w-0 flex-1"><div className="flex items-center justify-between gap-3"><strong>{name}</strong><ChevronDown size={15} /></div><p className="text-sm text-[var(--ink-500)]">{email}</p><div className="mt-2 flex flex-wrap gap-2"><span className="sig-pill sig-pill-muted">ARQ - Arquitetura</span><span className="sig-pill sig-pill-muted">DRE - Drenagem</span><span className="sig-pill sig-pill-muted">PAV - Pavimentação</span></div><p className="mt-2 text-xs text-[var(--ink-500)]">6 vínculo(s)</p></div>
        </article>
    );
}
