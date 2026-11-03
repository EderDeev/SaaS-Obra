import { useState } from 'react';
import { ArrowLeft, ArrowRight, CheckCircle } from 'lucide-react';
import { ExampleField } from './OsDemandPreview';
import { getTutorialScenes } from './tutorialScenes';

function ExamplePicker({ kind }) {
    const [selected, setSelected] = useState(['0']);
    const items = kind === 'insumo'
        ? [['Cimento Portland', 'kg', 'Material'], ['Areia média', 'm³', 'Material'], ['Pedreiro', 'h', 'Mão de obra']]
        : [['Execução de sarjeta de concreto', 'm', 'Composição'], ['Escavação manual de vala', 'm³', 'Composição'], ['Compactação de aterro', 'm³', 'Composição']];
    return <div className="tutorial-example-picker wide" aria-label={kind === 'insumo' ? 'Seleção demonstrativa de insumos' : 'Seleção demonstrativa de composições'}>
        {items.map(([name, unit, type], index) => <label key={name}><input type="checkbox" checked={selected.includes(String(index))} onChange={() => setSelected(values => values.includes(String(index)) ? values.filter(value => value !== String(index)) : [...values, String(index)])} /><span><strong>{name}</strong><small>{type} · Unidade: {unit}</small></span></label>)}
        <p aria-live="polite">{selected.length} item(ns) selecionado(s)</p>
    </div>;
}

export default function TutorialWalkthrough({ tutorial, step }) {
    const [active, setActive] = useState(0);
    const [result, setResult] = useState('Sim');
    const [decision, setDecision] = useState('Aprovar');
    const [returnTo, setReturnTo] = useState('Gerenciadora');
    const phases = getTutorialScenes(tutorial, step);
    const phase = phases[active];
    const multiple = phases.length > 1;
    const evidence = phase.mode === 'evidence';
    const decisionStage = phase.mode === 'decision';
    const forwardReview = decisionStage && /encaminhar/i.test(phase.action || '');
    const approveLabel = forwardReview ? 'Encaminhar' : 'Aprovar';
    const returnTargets = tutorial.id === 'diario-obra' && decisionStage
        ? step.role === 'Cliente' ? step.modelLabel?.includes('Construtora') ? ['Gerenciadora', 'Construtora'] : ['Gerenciadora'] : ['Construtora']
        : [];
    return <section className="tutorial-guided-walkthrough" data-tutorial-walkthrough={tutorial.id} aria-label={`Passo a passo: ${step.title}`}>
        {multiple && <nav className="tutorial-walkthrough-tabs" aria-label="Passos da etapa">{phases.map((item, index) => <button type="button" key={item.label} aria-pressed={active === index} onClick={() => setActive(index)}>{item.label}</button>)}</nav>}
        <div className="tutorial-walkthrough-heading" aria-live="polite">{multiple && <span>{active + 1}</span>}<div><h3>{phase.title}</h3>{phase.description && <p>{phase.description}</p>}</div></div>
        <div className="tutorial-example-fields" data-guided-phase={active}>
            {phase.fields.filter(([label]) => !(evidence && ['Resultado', 'Motivo'].includes(label))).map(([label, value]) => <ExampleField key={label} label={label} value={value} wide={value.length > 42} multiline={value.length > 42} />)}
            {evidence && <>
                <div className="tutorial-example-decision wide" role="group" aria-label="O problema foi resolvido?">{['Sim', 'Parcialmente', 'Não'].map(value => <button key={value} type="button" aria-pressed={result === value} onClick={() => setResult(value)}>{value}</button>)}</div>
                {result !== 'Sim' && <ExampleField label="Motivo" value={result === 'Parcialmente' ? 'A saída de drenagem foi limpa, mas a recomposição da proteção do talude permanece pendente.' : 'A obstrução permanece no local. A equipe aguarda a mobilização para executar a correção.'} multiline wide />}
            </>}
            {decisionStage && <>
                <div className="tutorial-example-decision wide" role="group" aria-label="Exemplo de avaliação">{['Aprovar', 'Devolver'].map(value => <button key={value} type="button" aria-pressed={decision === value} onClick={() => setDecision(value)}>{value === 'Aprovar' ? approveLabel : value}</button>)}</div>
                <ExampleField label={decision === 'Devolver' ? 'Motivo da devolução' : 'Comentário da análise'} value={decision === 'Devolver' ? 'Complementar os registros e anexar os documentos de conferência do trecho TR-01.' : 'Registros conferidos e compatíveis com as informações verificadas em campo.'} multiline wide />
                {decision === 'Devolver' && returnTargets.length > 0 && <div className="tutorial-return-target wide"><span>Destino da devolução</span><div className="tutorial-example-decision" role="group" aria-label="Destino da devolução">{returnTargets.map(value => <button type="button" key={value} aria-pressed={(returnTargets.includes(returnTo) ? returnTo : returnTargets[0]) === value} onClick={() => setReturnTo(value)}>{value}</button>)}</div></div>}
            </>}
            {phase.picker && <ExamplePicker key={`${step.id}-${active}`} kind={phase.picker} />}
            {phase.action && <div className="tutorial-example-actions wide"><button type="button" disabled className="sig-btn sig-btn-primary sig-btn-sm"><CheckCircle size={16} />{decisionStage ? decision === 'Devolver' ? 'Confirmar devolução' : forwardReview ? 'Confirmar envio' : 'Confirmar aprovação' : phase.action}</button></div>}

        </div>
        {multiple && <div className="tutorial-walkthrough-navigation"><span>Passo {active + 1} de {phases.length}</span><div><button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" disabled={active === 0} onClick={() => setActive(value => value - 1)}><ArrowLeft size={15} />Voltar</button><button type="button" className="sig-btn sig-btn-primary sig-btn-sm" disabled={active === phases.length - 1} onClick={() => setActive(value => value + 1)}>Próximo<ArrowRight size={15} /></button></div></div>}
    </section>;
}
