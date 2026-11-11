import { useEffect, useId, useMemo, useState } from 'react';
import { AlertTriangle, CheckCircle, ClipboardCheck, Flag, HardHat, Search, ShieldCheck } from 'lucide-react';
import { FlowCards } from './VisualTutorial';
import { getTutorialFlow } from './tutorialVisualData';
import TutorialStageModal from './TutorialStageModal';
import './quality-workflow-tutorial.css';

const overviewNodes = [
    { id: 'campo', x: 15, y: 20, title: 'Análise de campo', subtitle: 'Início', icon: Search, description: 'A supervisão verifica os serviços executados e identifica problemas na obra.' },
    { id: 'inconsistencia', x: 260, y: 20, title: 'Há inconsistência?', subtitle: 'Identifique o problema', icon: Search, description: 'Sem inconsistência, a verificação termina. Havendo problema, avalie a gravidade.' },
    { id: 'gravidade', x: 505, y: 20, title: 'É muito grave?', subtitle: 'Avalie a gravidade', icon: AlertTriangle, description: 'Problemas graves podem gerar uma RNC diretamente. Para uma instrução preventiva, registre uma IC.' },
    { id: 'rnc', x: 750, y: 20, title: 'Abrir uma RNC', subtitle: 'Não conformidade', icon: ShieldCheck, tone: 'danger', description: 'Registre a não conformidade e acompanhe proposta, análise, evidências e assinaturas. Uma IC anterior pode ser referenciada na abertura.' },
    { id: 'fim-campo', x: 260, y: 190, title: 'Fim da verificação', subtitle: 'Sem inconsistência', icon: CheckCircle, description: 'A verificação termina sem necessidade de registrar IC ou RNC.' },
    { id: 'ic', x: 505, y: 190, title: 'Abrir uma IC', subtitle: 'Instrução de Campo', icon: ClipboardCheck, tone: 'warning', description: 'Comunique o problema aos envolvidos e acompanhe a correção antes que seja necessário abrir uma RNC.' },
    { id: 'resolvido', x: 505, y: 360, title: 'Problema resolvido?', subtitle: 'Supervisão verifica', icon: Search, description: 'A supervisão registra evidências e responde Sim, Parcialmente ou Não. Se o problema persistir, avalie abrir uma RNC referenciando a IC.' },
    { id: 'fim-ic', x: 505, y: 530, title: 'Finalizar a IC', subtitle: 'Resultado registrado', icon: Flag, description: 'Finalize a IC com o resultado verificado. A abertura de uma RNC é uma ação separada, sem conversão automática.' },
];
const overviewEdges = [
    ['M225 80H260', '', 0, 0], ['M470 80H505', 'Sim', 487, 64], ['M715 80H750', 'Sim', 732, 64],
    ['M365 140V190', 'Não', 384, 169], ['M610 140V190', 'Não', 629, 169],
    ['M610 310V360', '', 0, 0], ['M610 480V530', 'Sim', 629, 509],
    ['M715 420H855V140', 'Não / parcialmente', 843, 397],
];
const proposalReturn = { from: 3, to: 2 };
const flowTabs = [
    { id: 'qualidade-geral', label: 'Fluxo geral' },
    { id: 'qualidade-fluxo-ic', label: 'IC' },
    { id: 'qualidade-fluxo-rnc', label: 'RNC' },
];
const flowFromHash = () => {
    const hash = typeof window === 'undefined' ? '' : window.location.hash.slice(1);
    if (['qualidade-ic', 'qualidade-fluxo-ic'].includes(hash)) return 'qualidade-fluxo-ic';
    if (['qualidade-rnc', 'qualidade-fluxo-rnc'].includes(hash)) return 'qualidade-fluxo-rnc';
    return 'qualidade-geral';
};

function QualityOverview() {
    const arrow = `quality-arrow-${useId().replace(/:/g, '')}`;
    const [active, setActive] = useState(null);
    return <div className="quality-overview-scroll"><div className="quality-overview" onKeyDown={event => { if (event.key === 'Escape') setActive(null); }}>
        <svg viewBox="0 0 980 660" className="quality-overview-wires" aria-hidden="true"><defs><marker id={arrow} viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto"><polygon points="0,0 8,4 0,8" className="os-tutorial-marker" /></marker></defs>{overviewEdges.map(([d, label, x, y]) => <g key={d}><path className="os-tutorial-edge" d={d} markerEnd={`url(#${arrow})`} />{label && <text x={x} y={y} textAnchor="middle">{label}</text>}</g>)}</svg>
        {overviewNodes.map(node => { const Icon = node.icon; const open = active === node.id; return <div key={node.id} tabIndex={0} data-open={open} data-quality-node={node.id} className={`os-tutorial-node quality-overview-node ${node.tone || ''}`} style={{ left: `${node.x / 9.8}%`, top: `${node.y / 6.6}%` }} onPointerEnter={() => setActive(node.id)} onPointerLeave={() => setActive(null)} onFocus={() => setActive(node.id)} onBlur={() => setActive(null)} aria-describedby={open ? `${arrow}-${node.id}` : undefined}>
            <Icon size={24} aria-hidden="true" /><strong>{node.title}</strong><small>{node.subtitle}</small>
            {open && <span className="os-tutorial-tooltip" role="tooltip" id={`${arrow}-${node.id}`}><b>{node.title}</b><span>{node.description}</span></span>}
        </div>; })}
    </div></div>;
}

export default function QualityWorkflowTutorial({ tutorials }) {
    const [selected, setSelected] = useState(null);
    const [returnProposal, setReturnProposal] = useState(false);
    const [activeFlow, setActiveFlow] = useState(flowFromHash);
    useEffect(() => {
        const update = () => { setActiveFlow(flowFromHash()); setSelected(null); };
        window.addEventListener('hashchange', update);
        return () => window.removeEventListener('hashchange', update);
    }, []);
    const chooseFlow = id => {
        setActiveFlow(id);
        setSelected(null);
        window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}#${id}`);
    };
    const flows = useMemo(() => ['qualidade-ic', 'qualidade-rnc'].map(id => {
        const tutorial = tutorials.find(item => item.id === id);
        if (!tutorial) return null;
        const steps = getTutorialFlow(tutorial).map(step => ({ ...step,
            role: id === 'qualidade-rnc' && step.id.endsWith('-3') ? 'Supervisão' : step.role,
        }));
        if (id === 'qualidade-ic') steps.splice(2, 0, {
            id: 'ic-correcao', title: 'Construtora corrige', role: 'Construtora', icon: HardHat,
            description: 'A construtora providencia a correção comunicada na instrução. Na IC não há etapa de proposta nem de análise de proposta. A supervisão verifica o resultado e registra as evidências.', informational: true,
        });
        if (id === 'qualidade-rnc') steps.splice(4, 0, {
            id: 'rnc-correcao', title: 'Executar a correção', role: 'Construtora · após aprovação', icon: HardHat,
            description: 'Com a proposta aprovada e os envolvidos comunicados, a construtora executa a ação corretiva. Se a proposta for devolvida, deve revisá-la e submetê-la novamente à supervisão.', informational: true,
        });
        return { tutorial, steps };
    }).filter(Boolean), [tutorials]);

    return <section className="os-tutorial visual-tutorial quality-tutorial" aria-label="Tutorial visual: Qualidade">
        <header className="os-tutorial-heading"><div><span className="eyebrow">Qualidade</span><h2>IC e RNC</h2><p>Da identificação do problema à comprovação da correção.</p></div><span className="os-tutorial-hint">Passe o mouse para conhecer cada etapa.</span></header>
        <nav className="quality-tutorial-nav" aria-label="Fluxos de Qualidade">{flowTabs.map(tab => <button key={tab.id} type="button" aria-pressed={activeFlow === tab.id} onClick={() => chooseFlow(tab.id)}>{tab.label}</button>)}</nav>
        {activeFlow === 'qualidade-geral' && <section id="qualidade-geral" className="os-tutorial-panel"><div className="os-tutorial-toolbar"><h3>Fluxo geral da Qualidade</h3><span className="os-tutorial-hint">Quando abrir uma IC ou uma RNC</span></div><QualityOverview /></section>}
        {flows.filter(({ tutorial }) => activeFlow === `qualidade-fluxo-${tutorial.id === 'qualidade-ic' ? 'ic' : 'rnc'}`).map(({ tutorial, steps }) => <section id={activeFlow} className="os-tutorial-panel" key={tutorial.id} aria-label={tutorial.title}>
            <div className="os-tutorial-toolbar"><h3>{tutorial.id === 'qualidade-ic' ? 'IC — Instrução de Campo' : 'RNC — Relatório de Não Conformidade'}</h3>{tutorial.id === 'qualidade-rnc' ? <div className="os-tutorial-choices" aria-label="Decisão da supervisão sobre a proposta"><button type="button" className="os-tutorial-choice" aria-pressed={!returnProposal} onClick={() => setReturnProposal(false)}>Aprovar proposta</button><button type="button" className="os-tutorial-choice" aria-pressed={returnProposal} onClick={() => setReturnProposal(true)}>Devolver proposta</button></div> : <span className="os-tutorial-hint">Clique nas etapas de atuação para ver como fazer.</span>}</div>
            <FlowCards steps={steps} returnPath={tutorial.id === 'qualidade-rnc' && returnProposal ? proposalReturn : null} onOpen={step => setSelected({ tutorial, step })} />
        </section>)}
        {selected && <TutorialStageModal key={selected.step.id} tutorial={selected.tutorial} step={selected.step} onClose={() => setSelected(null)} />}
    </section>;
}
