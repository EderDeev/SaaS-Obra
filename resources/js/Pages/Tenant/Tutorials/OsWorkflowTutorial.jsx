import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { Check, CircleCheck, ClipboardList, FilePenLine, Flag, GitBranch, Info, Undo2, UserRoundCheck } from 'lucide-react';
import './os-workflow-tutorial.css';

const nodes = [
    { id: 'analista', title: 'Analista DNIT', action: 'Cria a demanda', icon: FilePenLine, position: 'analyst', description: 'Descreve a necessidade, identifica contrato, lote, rodovia e trecho e informa o prazo desejado. Os produtos são definidos pela supervisão.' },
    { id: 'supervisao', title: 'Supervisão', action: 'Define os produtos', icon: ClipboardList, position: 'supervision', description: 'Avalia a demanda, seleciona os produtos do contrato e informa quantidades, análise técnica e prazo estimado. Depois encaminha ao fiscal.' },
    { id: 'fiscal', title: 'Fiscal do contrato', action: 'Analisa a OS', icon: UserRoundCheck, position: 'inspector', description: 'Confere a demanda e a análise da supervisão. Aprova ou devolve com justificativa, escolhendo quem deve corrigir.' },
    { id: 'ajuste-analista', title: 'Ajustar demanda', action: 'Analista DNIT', icon: Undo2, position: 'correct-demand', description: 'O analista recebe o motivo da devolução, corrige a demanda e a reenvia para análise da supervisão.' },
    { id: 'ajuste-supervisao', title: 'Ajustar produtos', action: 'Supervisão', icon: Undo2, position: 'correct-products', description: 'A supervisão recebe a justificativa do fiscal, corrige os produtos ou as quantidades e encaminha a análise novamente ao fiscal.' },
    { id: 'decisao', title: 'Autoriza a OS?', action: null, icon: GitBranch, position: 'decision', description: 'Se estiver adequada, a OS é aprovada. Se precisar de ajustes, o fiscal informa o motivo e devolve à supervisão ou ao analista DNIT.' },
    { id: 'aprovada', title: 'Aprovada', action: 'Serviço em execução', icon: CircleCheck, position: 'approved', description: 'O serviço está autorizado, mas a OS ainda aguarda a conclusão da execução. Aprovação não é finalização.' },
    { id: 'finalizada', title: 'Finalizada', action: 'Fiscal conclui a OS', icon: Flag, position: 'finished', description: 'Após a conclusão do serviço, o fiscal do contrato aciona Finalizar OS. Essa é a última etapa do fluxo.' },
];

const cases = [
    { id: 'aprovar', title: 'Aprovar', description: 'Após a execução, o fiscal finaliza a OS.' },
    { id: 'produtos', title: 'Corrigir produtos', description: 'Justificativa → Supervisão corrige → Fiscal reanalisa.' },
    { id: 'demanda', title: 'Corrigir demanda', description: 'Justificativa → Analista corrige → Supervisão analisa.' },
];

export default function OsWorkflowTutorial({ onOpenStep }) {
    const [path, setPath] = useState('aprovar');
    const [hovered, setHovered] = useState(null);
    const [focused, setFocused] = useState(null);
    const [edges, setEdges] = useState([]);
    const map = useRef(null);
    const prefix = `os-tutorial-${useId().replace(/:/g, '')}`;
    const active = hovered || focused;

    useLayoutEffect(() => {
        const element = map.current;
        const draw = () => {
            const box = element.getBoundingClientRect();
            if (!box.width) return;
            const point = (id, side) => {
                const rect = element.querySelector(`[data-os-node="${id}"]`).getBoundingClientRect();
                return { x: (side === 'left' ? rect.left : side === 'right' ? rect.right : rect.left + rect.width / 2) - box.left,
                    y: (side === 'top' ? rect.top : side === 'bottom' ? rect.bottom : rect.top + rect.height / 2) - box.top };
            };
            const next = [];
            const connect = (from, fromSide, to, toSide, returning = false, bend = false) => {
                const a = point(from, fromSide), b = point(to, toSide);
                const y = fromSide === 'bottom' ? a.y + 22 : b.y - 22;
                next.push({ returning, d: bend ? `M ${a.x} ${a.y} V ${y} H ${b.x} V ${b.y}` : `M ${a.x} ${a.y} L ${b.x} ${b.y}` });
            };
            connect('analista', 'right', 'supervisao', 'left');
            connect('supervisao', 'right', 'fiscal', 'left');
            connect('fiscal', 'bottom', 'decisao', 'top');
            if (path === 'aprovar') {
                connect('decisao', 'bottom', 'aprovada', 'top', false, true);
                connect('aprovada', 'right', 'finalizada', 'left');
            } else if (path === 'produtos') {
                connect('decisao', 'left', 'ajuste-supervisao', 'right', true);
                connect('ajuste-supervisao', 'top', 'supervisao', 'bottom', true);
            } else {
                connect('decisao', 'bottom', 'ajuste-analista', 'bottom', true, true);
                connect('ajuste-analista', 'top', 'analista', 'bottom', true);
            }
            setEdges(next);
        };
        draw();
        const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(draw) : null;
        observer?.observe(element);
        window.addEventListener('resize', draw);
        return () => { observer?.disconnect(); window.removeEventListener('resize', draw); };
    }, [path]);

    useEffect(() => {
        const close = event => { if (event.key === 'Escape') { setFocused(null); setHovered(null); } };
        const outside = event => { if (!map.current?.contains(event.target)) { setFocused(null); setHovered(null); } };
        document.addEventListener('keydown', close);
        document.addEventListener('pointerdown', outside);
        return () => { document.removeEventListener('keydown', close); document.removeEventListener('pointerdown', outside); };
    }, []);

    return <section className="os-tutorial" aria-label="Tutorial visual de Ordem de Serviço">
        <header className="os-tutorial-heading"><div><span className="eyebrow">Ordem de Serviço</span><h2>O caminho de uma OS</h2><p>Da demanda ao serviço concluído.</p></div><span className="os-tutorial-hint">Passe o mouse para conhecer. Clique para ver a etapa.</span></header>
        <div className="os-tutorial-panel">
            <div className="os-tutorial-toolbar"><h3>Como funciona</h3><div className="os-tutorial-choices" role="group" aria-label="Caminho da decisão do fiscal">{cases.map(item => <button key={item.id} type="button" aria-pressed={path === item.id} className="os-tutorial-choice" onClick={() => { setPath(item.id); setFocused(null); setHovered(null); }}>{item.id === 'aprovar' && <Check size={14} />}{item.title}</button>)}</div></div>
            <div className="os-tutorial-map" data-case={path} ref={map}>
                <svg className="os-tutorial-wires" aria-hidden="true"><defs>{['main', 'return'].map(type => <marker key={type} id={`${prefix}-${type}`} viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto"><polygon points="0,0 8,4 0,8" className={`os-tutorial-marker ${type}`} /></marker>)}</defs>{edges.map((edge, index) => <path key={index} d={edge.d} className={`os-tutorial-edge ${edge.returning ? 'return' : ''}`} markerEnd={`url(#${prefix}-${edge.returning ? 'return' : 'main'})`} />)}</svg>
                {nodes.map(node => {
                    const Icon = node.icon;
                    const opensModal = ['analista', 'supervisao', 'fiscal'].includes(node.id);
                    const NodeElement = opensModal ? 'button' : 'div';
                    const returning = path === 'produtos' && node.id === 'ajuste-supervisao' || path === 'demanda' && node.id === 'ajuste-analista';
                    const dim = node.id.startsWith('ajuste-') ? !returning : ['aprovada', 'finalizada'].includes(node.id) && path !== 'aprovar';
                    return <NodeElement key={node.id} type={opensModal ? 'button' : undefined} tabIndex={opensModal ? undefined : 0} className={`os-tutorial-node ${node.position}`} data-os-node={node.id} data-informational={!opensModal || undefined} data-return={returning} data-dim={dim} data-open={active === node.id}
                        aria-describedby={active === node.id ? `${prefix}-tip-${node.id}` : undefined} aria-haspopup={opensModal ? 'dialog' : undefined}
                        onPointerEnter={event => { if (event.pointerType !== 'touch') setHovered(node.id); }} onPointerLeave={() => setHovered(null)}
                        onFocus={() => { setHovered(null); setFocused(node.id); }} onBlur={() => setFocused(null)}
                        onClick={opensModal ? () => { setHovered(null); setFocused(null); onOpenStep?.(node); } : undefined}>
                        <Icon size={23} aria-hidden="true" /><strong>{node.title}</strong><small>{node.action || (path === 'aprovar' ? 'Sim' : 'Não')}</small>
                        {active === node.id && <span className="os-tutorial-tooltip" role="tooltip" id={`${prefix}-tip-${node.id}`}><b>{node.title}</b><span>{node.description}</span>{opensModal && <em>Clique para ver a etapa.</em>}</span>}
                    </NodeElement>;
                })}
                <div className="os-tutorial-legend"><Info size={17} aria-hidden="true" /><span>Aprovada ≠ Finalizada</span></div>
            </div>
        </div>
    </section>;
}
