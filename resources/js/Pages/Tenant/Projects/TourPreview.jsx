import ProjectTour from '@/Components/ProjectTour';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Box,
    Check,
    CheckCircle2,
    Circle,
    ClipboardCheck,
    FileText,
    Focus,
    Maximize2,
    MessageSquare,
    Move,
    Orbit,
    RotateCcw,
    Search,
    TriangleAlert,
    UserRound,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';

const checklist = [
    { label: 'Conferir a estrutura EAP e a revisao do arquivo', checked: true },
    { label: 'Verificar o carregamento correto no visualizador APS', checked: true },
    { label: 'Conferir marcacoes e pendencias tecnicas', checked: false },
];

const flow = [
    { label: 'Submetido', detail: '08 jul. 2026', done: true },
    { label: 'Em analise', detail: '09 jul. 2026', done: true },
    { label: 'Aprovado', detail: '10 jul. 2026', done: true },
    { label: 'Revisao oficial', detail: 'R02', done: true },
];

export default function ProjectTourPreview({ tenant }) {
    return (
        <AuthenticatedLayout>
            <Head title="Projeto CT001-001-ARQ-EXE-PRJ-001" />

            <section className="sig-content grid gap-4">
                <header data-tour="project-viewer-header" className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 items-start gap-3">
                        <Link href={route('tenant.projects.visualizar.index', tenant.slug)} className="sig-btn sig-btn-secondary !min-h-9 !px-2" aria-label="Voltar aos projetos">
                            <ArrowLeft size={16} />
                        </Link>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="mono text-xs text-[var(--ink-500)]">CT001-001-ARQ-EXE-PRJ-001</span>
                                <span className="sig-pill sig-pill-green">Aprovado</span>
                                <span className="sig-pill sig-pill-blue">R02</span>
                            </div>
                            <h1 className="mt-1 text-xl font-semibold text-[var(--ink-900)]">Planta do pavimento tipo</h1>
                            <p className="mt-1 text-sm text-[var(--ink-500)]">
                                CT-001 - Obra Jardim Central · 001 - Jardim Central · ARQ - Arquitetura · EXE - Projeto executivo
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <span className="sig-btn sig-btn-secondary"><FileText size={14} /> PDF · 2,4 MB</span>
                        <span className="sig-btn sig-btn-secondary"><UserRound size={14} /> Admin Plataforma</span>
                    </div>
                </header>

                <div className="grid min-h-[680px] gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <section className="flex min-h-[620px] flex-col overflow-hidden rounded-lg border border-slate-300 bg-slate-900 shadow-sm">
                        <div data-tour="project-viewer-toolbar" className="flex flex-wrap items-center gap-1 border-b border-slate-700 bg-slate-800 px-3 py-2 text-slate-100">
                            <ViewerTool icon={Orbit} label="Orbitar" />
                            <ViewerTool icon={Move} label="Mover" />
                            <ViewerTool icon={ZoomIn} label="Aproximar" />
                            <ViewerTool icon={ZoomOut} label="Afastar" />
                            <ViewerTool icon={Focus} label="Enquadrar" />
                            <ViewerTool icon={RotateCcw} label="Restaurar vista" />
                            <span className="mx-1 h-6 w-px bg-slate-600" />
                            <ViewerTool icon={Search} label="Localizar objeto" />
                            <ViewerTool icon={Box} label="Arvore do modelo" />
                            <ViewerTool icon={Maximize2} label="Tela cheia" className="ml-auto" />
                        </div>

                        <div data-tour="project-viewer-canvas" className="relative flex flex-1 items-center justify-center overflow-hidden bg-[#dfe8eb] p-5 sm:p-8">
                            <div className="absolute inset-0 opacity-35" style={{ backgroundImage: 'linear-gradient(#91a4aa 1px, transparent 1px), linear-gradient(90deg, #91a4aa 1px, transparent 1px)', backgroundSize: '24px 24px' }} />
                            <div className="relative aspect-[1.42] w-full max-w-[900px] border-8 border-slate-700 bg-white p-4 shadow-2xl sm:p-7">
                                <div className="grid h-full grid-cols-[1.1fr_0.9fr] gap-3 border-4 border-slate-700 p-3">
                                    <div className="grid grid-rows-[1fr_0.7fr] gap-3">
                                        <BlueprintRoom label="SALA DE REUNIAO" />
                                        <div className="grid grid-cols-2 gap-3">
                                            <BlueprintRoom label="APOIO" />
                                            <BlueprintRoom label="COPA" />
                                        </div>
                                    </div>
                                    <div className="grid grid-rows-[0.72fr_1fr] gap-3">
                                        <div className="grid grid-cols-2 gap-3">
                                            <BlueprintRoom label="SANITARIO" compact />
                                            <BlueprintRoom label="SANITARIO" compact />
                                        </div>
                                        <BlueprintRoom label="AREA TECNICA" />
                                    </div>
                                </div>
                                <div className="absolute bottom-1 right-2 text-[9px] font-bold tracking-[0.16em] text-slate-500">PLANTA PAVIMENTO TIPO · R02</div>
                            </div>
                            <div className="absolute bottom-4 left-4 rounded bg-slate-900/85 px-3 py-1.5 text-xs font-semibold text-white">Vista: Planta baixa · Escala 1:100</div>
                        </div>
                    </section>

                    <aside className="grid content-start gap-4">
                        <section data-tour="project-viewer-comments" className="sig-card overflow-hidden">
                            <div className="flex items-center justify-between border-b border-[var(--border)] px-4 py-3">
                                <div className="flex items-center gap-2"><MessageSquare size={16} className="text-blue-700" /><h2 className="text-sm font-semibold">Comentarios tecnicos</h2></div>
                                <span className="sig-pill sig-pill-amber">1 aberta</span>
                            </div>
                            <div className="p-4">
                                <div className="rounded-lg border border-amber-200 bg-amber-50/60 p-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <strong className="text-sm text-[var(--ink-900)]">Compatibilizar passagem de instalacoes</strong>
                                        <span className="sig-pill sig-pill-amber">Alta</span>
                                    </div>
                                    <p className="mt-2 text-xs leading-5 text-[var(--ink-600)]">Revisar a interferencia junto ao shaft antes da liberacao para execucao.</p>
                                    <div className="mt-3 flex items-center justify-between gap-2 text-xs text-[var(--ink-500)]">
                                        <span>Responsavel: Marina Costa</span>
                                        <span>Em andamento</span>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section data-tour="project-viewer-checklist" className="sig-card overflow-hidden">
                            <div className="flex items-center gap-2 border-b border-[var(--border)] px-4 py-3"><ClipboardCheck size={16} className="text-emerald-700" /><h2 className="text-sm font-semibold">Checklist de revisao</h2></div>
                            <div className="grid gap-2 p-4">
                                {checklist.map((item) => (
                                    <div key={item.label} className="flex items-start gap-2 rounded-lg border border-[var(--border)] p-2.5 text-xs text-[var(--ink-700)]">
                                        <span className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded ${item.checked ? 'bg-emerald-700 text-white' : 'border border-slate-300 bg-white text-slate-300'}`}>
                                            {item.checked ? <Check size={13} /> : <Circle size={10} />}
                                        </span>
                                        <span className="leading-5">{item.label}</span>
                                    </div>
                                ))}
                            </div>
                        </section>

                        <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs leading-5 text-blue-900">
                            <div className="flex items-center gap-2 font-semibold"><TriangleAlert size={14} /> RNC vinculada</div>
                            <p className="mt-1">O projeto possui uma RNC aberta que permanece visivel na arvore ate sua conclusao.</p>
                        </div>
                    </aside>
                </div>

                <section data-tour="project-viewer-flow" className="sig-card p-4">
                    <div className="mb-4 flex items-center gap-2"><CheckCircle2 size={16} className="text-emerald-700" /><h2 className="text-sm font-semibold">Fluxo da revisao R02</h2></div>
                    <div className="grid gap-3 sm:grid-cols-4">
                        {flow.map((item, index) => (
                            <div key={item.label} className="relative rounded-lg border border-emerald-200 bg-emerald-50/50 p-3">
                                <div className="flex items-center gap-2"><span className="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-700 text-white"><Check size={13} /></span><strong className="text-xs text-[var(--ink-900)]">{item.label}</strong></div>
                                <div className="mt-2 text-xs text-[var(--ink-500)]">{item.detail}</div>
                                {index < flow.length - 1 && <span className="absolute -right-2 top-1/2 hidden h-px w-4 bg-emerald-400 sm:block" />}
                            </div>
                        ))}
                    </div>
                </section>
            </section>

            <ProjectTour section="viewer" />
        </AuthenticatedLayout>
    );
}

function ViewerTool({ icon: Icon, label, className = '' }) {
    return (
        <button type="button" title={label} className={`inline-flex h-9 w-9 items-center justify-center rounded text-slate-200 hover:bg-slate-700 hover:text-white ${className}`}>
            <Icon size={17} />
        </button>
    );
}

function BlueprintRoom({ label, compact = false }) {
    return (
        <div className="relative flex min-h-0 items-center justify-center border-[3px] border-slate-600 bg-slate-50">
            <span className={`font-semibold tracking-[0.08em] text-slate-500 ${compact ? 'text-[7px] sm:text-[9px]' : 'text-[8px] sm:text-[11px]'}`}>{label}</span>
            <span className="absolute -bottom-[3px] left-1/2 h-3 w-8 -translate-x-1/2 border-x-2 border-t-2 border-slate-400 bg-white" />
        </div>
    );
}
