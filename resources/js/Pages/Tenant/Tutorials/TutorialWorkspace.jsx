import { useEffect, useMemo, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { BookOpen, ChevronRight, PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import VisualTutorial from './VisualTutorial';
import { tutorialIcons } from './tutorialVisualData';

const groupOrder = ['Comece por aqui', 'Plataforma', 'Gestão', 'Programação', 'Acompanhamento', 'Campo', 'Controle', 'Ajuda', 'Administração'];
const tutorialOrder = ['primeiros-passos', 'administracao-plataforma', 'visao-geral', 'contratos', 'atividades', 'orcamentos', 'medicao', 'ordem-servico', 'os-dnit', 'diario-obra', 'qualidade-ic', 'qualidade-rnc', 'documentacao', 'projetos', 'notificacoes-perfil', 'assistente-deming', 'usuarios-permissoes', 'parametrizacao'];

export default function TutorialWorkspace({ tenant, tutorials = [] }) {
    const { auth } = usePage().props;
    const guides = useMemo(() => tutorials.filter(guide => guide.enabled !== false).map(guide => ({...guide, videos: guide.videos || [], screenshots: guide.screenshots || []})).sort((a, b) => groupOrder.indexOf(a.group) - groupOrder.indexOf(b.group) || tutorialOrder.indexOf(a.id) - tutorialOrder.indexOf(b.id)), [tutorials]);
    const [activeId, setActiveId] = useState(() => typeof window !== 'undefined' && window.location.hash ? window.location.hash.slice(1) : 'primeiros-passos');
    const [collapsed, setCollapsed] = useState(false);
    const preference = `sigworks:tutorials:menu:${tenant.id || tenant.slug}:${auth?.user?.id || 'user'}`;
    useEffect(() => { try { const saved = localStorage.getItem(preference); setCollapsed(saved === null ? window.matchMedia('(max-width: 1000px)').matches : saved === 'collapsed'); } catch {} }, [preference]);
    useEffect(() => { const update = () => setActiveId(window.location.hash.slice(1) || 'primeiros-passos'); window.addEventListener('hashchange', update); return () => window.removeEventListener('hashchange', update); }, []);
    const active = guides.find(guide => guide.id === activeId) || guides[0];
    const groups = guides.reduce((result, guide) => { (result[guide.group] ||= []).push(guide); return result; }, {});
    const choose = id => { setActiveId(id); history.replaceState(null, '', `${window.location.pathname}${window.location.search}#${id}`); };
    const toggleMenu = () => { const next = !collapsed; setCollapsed(next); try { localStorage.setItem(preference, next ? 'collapsed' : 'expanded'); } catch {} };

    return <AuthenticatedLayout><Head title="Tutoriais" /><section className="sig-content tutorial-center">
        <header className="tutorial-center-header"><div><div className="eyebrow"><BookOpen size={15} />Central de tutoriais</div><h1>Conheça os módulos do SigWorks</h1><p>Explore o fluxo. Clique em uma etapa para ver como funciona.</p></div></header>
        <section data-testid="tutorial-layout" className={`tutorials-layout tutorials-workspace ${collapsed ? 'tutorials-menu-collapsed' : ''}`}>
            <aside id="tutorial-menu" className="tutorial-menu" aria-label="Tutoriais dos módulos">
                <button type="button" className="sig-btn sig-btn-secondary sig-btn-sm tutorial-menu-toggle" aria-label={collapsed ? 'Expandir tutoriais' : 'Recolher tutoriais'} aria-expanded={!collapsed} aria-controls="tutorial-menu" onClick={toggleMenu}>{collapsed ? <PanelLeftOpen size={16} /> : <PanelLeftClose size={16} />}<span className="tutorial-menu-toggle-label">{collapsed ? 'Expandir tutoriais' : 'Recolher tutoriais'}</span></button>
                <nav>{Object.entries(groups).map(([group, items]) => <section key={group} className="tutorial-menu-group"><h2>{group}</h2>{items.map(guide => { const Icon = tutorialIcons[guide.icon] || BookOpen; return <button key={guide.id} type="button" className="tutorial-menu-item" aria-label={guide.title} title={collapsed ? guide.title : undefined} aria-current={active?.id === guide.id ? 'page' : undefined} onClick={() => choose(guide.id)}><Icon size={17} /><span className="tutorial-menu-label">{guide.title}</span><ChevronRight className="tutorial-menu-arrow" size={13} /></button>; })}</section>)}</nav>
                {!guides.length && <p className="tutorial-menu-empty">Nenhum tutorial encontrado.</p>}
            </aside>
            <article className="tutorial-reading-area">{active ? <VisualTutorial key={active.id} tutorial={active} /> : <p className="tutorial-menu-empty">Nenhum tutorial disponível.</p>}</article>
        </section>
    </section></AuthenticatedLayout>;
}
