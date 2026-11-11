import { useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import OsWorkflowTutorial from './OsWorkflowTutorial';
import TutorialStageModal from './TutorialStageModal';
import { getTutorialFlow, osExamples, rdoModels } from './tutorialVisualData';
import './visual-tutorial.css';

export function FlowCards({ steps, onOpen, returnPath = null, columns = 3 }) {
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
            const next = steps.slice(0, -1).flatMap((step, index) => {
                if (returnPath?.from === index) return [];
                const from = element.querySelector(`[data-flow-index="${index}"]`).getBoundingClientRect();
                const to = element.querySelector(`[data-flow-index="${index + 1}"]`).getBoundingClientRect();
                const row = Math.floor(index / columns), nextRow = Math.floor((index + 1) / columns);
                if (row !== nextRow) return { optional: steps[index + 1].optional, d: `M ${from.left + from.width / 2 - box.left} ${from.bottom - box.top} V ${to.top - box.top}` };
                const right = row % 2 === 0;
                return { optional: steps[index + 1].optional, d: `M ${(right ? from.right : from.left) - box.left} ${from.top + from.height / 2 - box.top} H ${(right ? to.left : to.right) - box.left}` };
            });
            if (returnPath) {
                const from = element.querySelector(`[data-flow-index="${returnPath.from}"]`).getBoundingClientRect();
                const to = element.querySelector(`[data-flow-index="${returnPath.to}"]`).getBoundingClientRect();
                const side = Math.max(from.right, to.right) - box.left + 20;
                next.push({ returning: true, d: `M ${from.right - box.left} ${from.top + from.height / 2 - box.top} H ${side} V ${to.top + to.height / 2 - box.top} H ${to.right - box.left}` });
            }
            setEdges(next);
        };
        draw();
        const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(draw) : null;
        observer?.observe(element); window.addEventListener('resize', draw);
        return () => { observer?.disconnect(); window.removeEventListener('resize', draw); };
    }, [steps, returnPath, columns]);
    return <div className={`tutorial-flow-cards ${columns >= 4 ? "tutorial-flow-wide" : ""}`} style={columns >= 4 ? { gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` } : undefined} ref={map} aria-label="Diagrama de etapas do tutorial" onKeyDown={event => { if (event.key === 'Escape') { setHover(null); setFocus(null); } }}>
        <svg className="os-tutorial-wires" aria-hidden="true"><defs><marker id={`${prefix}-arrow`} viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto"><polygon points="0,0 8,4 0,8" className="os-tutorial-marker" /></marker><marker id={`${prefix}-return-arrow`} viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto"><polygon points="0,0 8,4 0,8" className="os-tutorial-marker return" /></marker></defs>{edges.map((edge, index) => <path key={index} d={edge.d} className={`os-tutorial-edge ${edge.optional ? 'optional' : ''} ${edge.returning ? 'return' : ''}`} markerEnd={`url(#${prefix}-${edge.returning ? 'return-arrow' : 'arrow'})`} />)}</svg>
        {steps.map((step, index) => {
            const Icon = step.icon;
            const NodeElement = step.informational ? 'div' : 'button';
            const row = Math.floor(index / columns), column = row % 2 === 0 ? index % columns + 1 : columns - index % columns;
            const open = (hover || focus) === step.id;
            return <NodeElement key={step.id} type={step.informational ? undefined : "button"} tabIndex={step.informational ? 0 : undefined} data-informational={step.informational || undefined} className={`tutorial-flow-node os-tutorial-node ${row === 0 ? 'first-row' : ''} column-${column}`} data-flow-index={index} style={{gridRow: row + 1, gridColumn: column, '--step-order': index}} data-open={open} data-optional={step.optional || undefined} data-dim={returnPath && index > returnPath.from || undefined} data-return={returnPath?.to === index || undefined}
                aria-describedby={open ? `${prefix}-${index}-tip` : undefined} aria-haspopup={step.informational ? undefined : "dialog"} onPointerEnter={event => { if (event.pointerType !== 'touch') setHover(step.id); }} onPointerLeave={() => setHover(null)} onFocus={() => { setHover(null); setFocus(step.id); }} onBlur={() => setFocus(null)} onClick={step.informational ? undefined : () => { setHover(null); setFocus(null); onOpen(step); }}>
                <span className="tutorial-flow-number">{String(index + 1).padStart(2, '0')}{step.optional ? ' · Opcional' : ''}</span><Icon size={24} aria-hidden="true" /><strong>{step.title}</strong><small>{step.role}</small>
                {step.statuses && <span className="tutorial-flow-statuses">{step.statuses.map(status => <span key={status}>{status}</span>)}</span>}
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
            {tutorial.id === 'diario-obra' && <nav className="tutorial-model-picker" aria-label="Modelos de RDO">{rdoModels.map(item => <button key={item.id} type="button" data-rdo-model={item.id} aria-pressed={model === item.id} onClick={() => { setModel(item.id); setSelected(null); }}>{item.label}</button>)}</nav>}
            <section className="os-tutorial-panel"><div className="os-tutorial-toolbar"><h3>Como funciona</h3><span className="os-tutorial-hint">{steps.length} etapas</span></div><FlowCards key={`${tutorial.id}-${model}`} steps={steps} onOpen={setSelected} /></section>
        </section>}
        {selected && <TutorialStageModal key={selected.id} tutorial={tutorial} step={selected} onClose={() => setSelected(null)} />}
    </>;
}
