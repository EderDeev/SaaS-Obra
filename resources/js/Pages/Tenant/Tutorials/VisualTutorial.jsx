import { useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import OsWorkflowTutorial from './OsWorkflowTutorial';
import TutorialStageModal from './TutorialStageModal';
import { getTutorialFlow, osExamples, rdoModels } from './tutorialVisualData';
import './visual-tutorial.css';

function FlowCards({ steps, onOpen }) {
    const map = useRef(null);
    const [edges, setEdges] = useState([]);
    const [hover, setHover] = useState(null);
    const [focus, setFocus] = useState(null);
    const prefix = `tutorial-flow-${useId().replace(/:/g, '')}`;
    useLayoutEffect(() => {
        const element = map.current;
        const draw = () => {
            const box = element.getBoundingClientRect();
            if (!box.width) return;
            const next = steps.slice(0, -1).map((step, index) => {
                const from = element.querySelector(`[data-flow-index="${index}"]`).getBoundingClientRect();
                const to = element.querySelector(`[data-flow-index="${index + 1}"]`).getBoundingClientRect();
                const row = Math.floor(index / 3), nextRow = Math.floor((index + 1) / 3);
                if (row !== nextRow) return { optional: steps[index + 1].optional, d: `M ${from.left + from.width / 2 - box.left} ${from.bottom - box.top} V ${to.top - box.top}` };
                const right = row % 2 === 0;
                return { optional: steps[index + 1].optional, d: `M ${(right ? from.right : from.left) - box.left} ${from.top + from.height / 2 - box.top} H ${(right ? to.left : to.right) - box.left}` };
            });
            setEdges(next);
        };
        draw();
        const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(draw) : null;
        observer?.observe(element); window.addEventListener('resize', draw);
        return () => { observer?.disconnect(); window.removeEventListener('resize', draw); };
    }, [steps]);
    return <div className="tutorial-flow-cards" ref={map} aria-label="Diagrama de etapas do tutorial" onKeyDown={event => { if (event.key === 'Escape') { setHover(null); setFocus(null); } }}>
        <svg className="os-tutorial-wires" aria-hidden="true"><defs><marker id={`${prefix}-arrow`} viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto"><polygon points="0,0 8,4 0,8" className="os-tutorial-marker" /></marker></defs>{edges.map((edge, index) => <path key={index} d={edge.d} className={`os-tutorial-edge ${edge.optional ? 'optional' : ''}`} markerEnd={`url(#${prefix}-arrow)`} />)}</svg>
        {steps.map((step, index) => {
            const Icon = step.icon;
            const NodeElement = step.informational ? 'div' : 'button';
            const row = Math.floor(index / 3), column = row % 2 === 0 ? index % 3 + 1 : 3 - index % 3;
            const open = (hover || focus) === step.id;
            return <NodeElement key={step.id} type={step.informational ? undefined : "button"} tabIndex={step.informational ? 0 : undefined} data-informational={step.informational || undefined} className={`tutorial-flow-node os-tutorial-node ${row === 0 ? 'first-row' : ''} column-${column}`} data-flow-index={index} style={{gridRow: row + 1, gridColumn: column, '--step-order': index}} data-open={open} data-optional={step.optional || undefined}
                aria-describedby={open ? `${prefix}-${index}-tip` : undefined} aria-haspopup={step.informational ? undefined : "dialog"} onPointerEnter={event => { if (event.pointerType !== 'touch') setHover(step.id); }} onPointerLeave={() => setHover(null)} onFocus={() => { setHover(null); setFocus(step.id); }} onBlur={() => setFocus(null)} onClick={step.informational ? undefined : () => { setHover(null); setFocus(null); onOpen(step); }}>
                <span className="tutorial-flow-number">{String(index + 1).padStart(2, '0')}{step.optional ? ' · Opcional' : ''}</span><Icon size={24} aria-hidden="true" /><strong>{step.title}</strong><small>{step.role}</small>
                {open && <span className="os-tutorial-tooltip" id={`${prefix}-${index}-tip`} role="tooltip"><b>{step.title}</b><span>{step.description}</span>{!step.informational && <em>Clique para ver a etapa.</em>}</span>}
            </NodeElement>;
        })}
    </div>;
}

export default function VisualTutorial({ tutorial }) {
    const [model, setModel] = useState(rdoModels[0].id);
    const [selected, setSelected] = useState(null);
    const steps = useMemo(() => getTutorialFlow(tutorial, model), [tutorial, model]);
    return <>
        {tutorial.id === 'os-dnit' ? <OsWorkflowTutorial onOpenStep={node => setSelected({ ...node, ...osExamples[node.id] })} /> : <section className="os-tutorial visual-tutorial" aria-label={`Tutorial visual: ${tutorial.title}`}>
            <header className="os-tutorial-heading"><div><span className="eyebrow">{tutorial.path}</span><h2>{tutorial.title}</h2><p>{tutorial.summary}</p></div><span className="os-tutorial-hint">Passe o mouse para conhecer. Clique para ver a etapa.</span></header>
            {tutorial.id === 'diario-obra' && <div className="tutorial-model-picker"><label><span className="eyebrow">Modelo de RDO para conhecer</span><select className="sig-input" value={model} onChange={event => { setModel(event.target.value); setSelected(null); }}>{rdoModels.map(item => <option key={item.id} value={item.id}>{item.label}</option>)}</select></label><button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" onClick={() => setSelected({id:'rdo-settings',modelLabel:rdoModels.find(item => item.id === model)?.label,title:'Parametrização e geração',role:'Administrador autorizado',description:tutorial.steps[0][1]+' '+tutorial.steps[1][1],fields:['Contrato','Obras','Modelo','Período e dias de geração','Cópia de dados','Logos e PDF'],action:'Salvar a parametrização do contrato'})}>Parametrização e geração</button></div>}
            <section className="os-tutorial-panel"><div className="os-tutorial-toolbar"><h3>Como funciona</h3><span className="os-tutorial-hint">{steps.length} etapas</span></div><FlowCards key={`${tutorial.id}-${model}`} steps={steps} onOpen={setSelected} /></section>
        </section>}
        {selected && <TutorialStageModal key={selected.id} tutorial={tutorial} step={selected} onClose={() => setSelected(null)} />}
    </>;
}
