import ProjectTour from '@/Components/ProjectTour';
import ProjectModuleTourPreview from '@/Components/ProjectModuleTourPreview';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ChevronDown,
    Download,
    Eye,
    Hand,
    Home,
    Layers,
    Maximize2,
    MessageSquare,
    MessageSquarePlus,
    MoveVertical,
    PanelRightClose,
    Ruler,
    Settings,
    TriangleAlert,
    Video,
} from 'lucide-react';
import { useEffect } from 'react';

export default function ProjectTourPreview({ tenant, screen = 'viewer' }) {
    if (screen !== 'viewer') {
        return <ProjectModuleTourPreview tenant={tenant} section={screen} />;
    }

    return <ProjectViewerTourPreview tenant={tenant} />;
}

function ProjectViewerTourPreview({ tenant }) {
    useEffect(() => {
        document.body.classList.add('sig-viewer-body');

        return () => document.body.classList.remove('sig-viewer-body');
    }, []);

    return (
        <AuthenticatedLayout>
            <Head title="Visualizar Planta do pavimento tipo" />

            <section className="sig-content sig-viewer-content">
                <header data-tour="project-viewer-header" className="sig-viewer-header flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 text-[var(--ink-500)]">
                            <Eye size={15} />
                            <span className="eyebrow">Projetos</span>
                        </div>
                        <h1 className="mono mt-1 break-all text-xl font-bold text-[var(--primary)]">CT001-001-GER-ARQ-EXE-PRJ-001-R02</h1>
                        <p className="mt-1 break-all text-sm font-medium text-[var(--ink-700)]">planta-pavimento-tipo-r02.dwg</p>
                        <p className="mt-1 text-xs text-[var(--ink-500)]">Planta do pavimento tipo - Pronto para visualizacao</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Link href={route('tenant.projects.visualizar.index', tenant.slug)} className="sig-btn sig-btn-secondary">
                            <ArrowLeft size={15} />
                            Visualizar projetos
                        </Link>
                        <button type="button" className="sig-btn sig-btn-secondary">
                            <PanelRightClose size={15} />
                            Recolher painel
                        </button>
                        <button type="button" className="sig-btn sig-btn-secondary">
                            <Download size={15} />
                            Baixar
                        </button>
                    </div>
                </header>

                <div className="sig-viewer-workspace">
                    <div data-tour="project-viewer-canvas" className="sig-viewer-stage bg-white">
                        <div className="absolute right-3 top-3 z-10 flex h-8 w-8 items-center justify-center rounded-md border border-[var(--border)] bg-white text-[var(--ink-400)] shadow-sm">
                            <Home size={15} />
                        </div>

                        <div className="absolute inset-0 flex items-center justify-center overflow-hidden bg-[#f7f8fa] p-10">
                            <div className="relative aspect-[1.46] w-[min(72%,820px)] border-[6px] border-slate-600 bg-white p-5 shadow-lg">
                                <div className="grid h-full grid-cols-[1.15fr_0.85fr] gap-3 border-[3px] border-slate-500 p-3">
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
                                <span className="absolute bottom-1 right-2 text-[8px] font-semibold text-slate-500">PLANTA PAVIMENTO TIPO - R02</span>
                            </div>
                        </div>

                        <div data-tour="project-viewer-toolbar" className="absolute bottom-4 left-1/2 z-20 flex -translate-x-1/2 items-center gap-1 rounded-md bg-[#23262d] p-1.5 text-white shadow-xl">
                            <ViewerTool icon={Hand} label="Mover" active />
                            <ViewerTool icon={MoveVertical} label="Navegar" />
                            <ViewerTool icon={Video} label="Cameras" />
                            <span className="mx-1 h-7 w-px bg-white/20" />
                            <ViewerTool icon={Ruler} label="Medir" />
                            <ViewerTool icon={Settings} label="Configuracoes" />
                            <ViewerTool icon={Layers} label="Camadas" />
                            <ViewerTool icon={Maximize2} label="Tela cheia" />
                        </div>
                    </div>

                    <aside data-tour="project-viewer-comments" className="sig-review-panel">
                        <div className="border-b border-[var(--border)] p-4">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <span className="eyebrow">Revisao do projeto</span>
                                    <h2 className="mt-1 text-base font-semibold text-[var(--ink-900)]">Comentarios visuais</h2>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="sig-pill sig-pill-blue">R02</span>
                                    <button type="button" className="sig-btn sig-btn-ghost !min-h-8 !px-2" title="Recolher painel">
                                        <PanelRightClose size={15} />
                                    </button>
                                </div>
                            </div>

                            <div className="mt-4 grid grid-cols-2 gap-2">
                                <Metric label="Comentarios abertos" value="1" />
                                <Metric label="Modo" value="Comentarios" />
                            </div>
                        </div>

                        <section
                            data-tour="project-viewer-rnc-alert"
                            className="border-b border-[var(--border)] bg-blue-50 p-4 text-blue-800"
                        >
                            <div className="flex items-start gap-2">
                                <TriangleAlert className="mt-0.5 shrink-0" size={16} />
                                <div>
                                    <strong className="text-xs font-semibold">RNC vinculada</strong>
                                    <p className="mt-1 text-xs leading-5">
                                        O projeto possui uma RNC aberta, que permanece sinalizada ate sua conclusao.
                                    </p>
                                </div>
                            </div>
                        </section>

                        <section data-tour="project-viewer-comment-form" className="border-b border-[var(--border)] p-4">
                            <button type="button" className="sig-btn sig-btn-secondary w-full justify-between">
                                <span className="flex items-center gap-2">
                                    <MessageSquarePlus size={15} />
                                    Comentario visual
                                </span>
                                <ChevronDown size={15} />
                            </button>
                            <p className="mt-3 text-xs leading-5 text-[var(--ink-500)]">
                                Abra o formulário para criar uma marcação, definir vários responsáveis e registrar a prioridade.
                            </p>
                            <div className="mt-3 flex flex-wrap gap-1.5">
                                <span className="sig-pill sig-pill-blue">Marina Costa</span>
                                <span className="sig-pill sig-pill-blue">Ederson Moreira</span>
                            </div>
                        </section>

                        <section data-tour="project-viewer-comment-list" className="border-b border-[var(--border)] p-4">
                            <div className="mb-3 flex items-center gap-2 text-[var(--ink-500)]">
                                <MessageSquare size={15} />
                                <span className="eyebrow">Comentarios no projeto</span>
                            </div>
                            <article className="rounded-lg border border-[var(--border)] bg-white p-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="sig-pill sig-pill-muted">#1</span>
                                    <span className="sig-pill sig-pill-amber">Alta</span>
                                    <span className="sig-pill sig-pill-blue">Em andamento</span>
                                </div>
                                <h3 className="mt-2 text-sm font-semibold text-[var(--ink-900)]">Compatibilizar passagem de instalacoes</h3>
                                <p className="mt-2 text-xs leading-5 text-[var(--ink-500)]">Revisar a interferencia junto ao shaft antes da liberacao para execucao.</p>
                            </article>
                        </section>

                    </aside>
                </div>
            </section>

            <ProjectTour key="viewer" section="viewer" tenantSlug={tenant.slug} />
        </AuthenticatedLayout>
    );
}

function ViewerTool({ icon: Icon, label, active = false }) {
    return (
        <button type="button" title={label} className={`flex h-9 w-9 items-center justify-center rounded ${active ? 'bg-[#087ce8]' : 'hover:bg-white/10'}`}>
            <Icon size={16} />
        </button>
    );
}

function BlueprintRoom({ label, compact = false }) {
    return (
        <div className="relative flex min-h-0 items-center justify-center border-2 border-slate-500 bg-slate-50">
            <span className={`font-semibold tracking-[0.08em] text-slate-500 ${compact ? 'text-[7px]' : 'text-[9px]'}`}>{label}</span>
            <span className="absolute -bottom-0.5 left-1/2 h-2.5 w-7 -translate-x-1/2 border-x border-t border-slate-400 bg-white" />
        </div>
    );
}

function Metric({ label, value }) {
    return (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface-muted)] p-3">
            <div className="eyebrow">{label}</div>
            <div className="mt-1 text-lg font-semibold text-[var(--ink-900)]">{value}</div>
        </div>
    );
}
