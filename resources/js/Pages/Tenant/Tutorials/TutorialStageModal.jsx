import { useEffect, useRef } from 'react';
import { FileText, X } from 'lucide-react';
import { createPortal } from 'react-dom';
import OsDemandPreview from './OsDemandPreview';
import OsStagePreview from './OsStagePreview';
import TutorialWalkthrough from './TutorialWalkthrough';
import SigWorksOverview from './SigWorksOverview';

function StagePreview({ tutorial, step }) {
    if (step.preview === 'sigworks') return <SigWorksOverview step={step} />;
    if (step.preview === 'os-stage') return <OsStagePreview stage={step.id} />;
    if (step.preview === 'os-demand') return <OsDemandPreview editing={step.id === 'ajuste-analista'} />;
    return <TutorialWalkthrough tutorial={tutorial} step={step} />;
}

export default function TutorialStageModal({ tutorial, step, onClose }) {
    const dialog = useRef(null);
    const Icon = step.icon || FileText;
    useEffect(() => {
        const element = dialog.current;
        const launcher = document.activeElement;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        element.showModal();
        return () => { if (element.open) element.close(); document.body.style.overflow = previousOverflow; if (launcher?.isConnected && typeof launcher.focus === 'function') launcher.focus(); };
    }, []);

    return createPortal(<dialog ref={dialog} className="tutorial-stage-modal tutorial-stage-modal-walkthrough" aria-labelledby="tutorial-stage-title" onCancel={event => { event.preventDefault(); onClose(); }} onClick={event => { if (event.target === dialog.current) { const rect = dialog.current.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) onClose(); } }}>
        <header className="tutorial-stage-header"><span className="tutorial-stage-icon"><Icon size={25} /></span><div><div className="eyebrow">{tutorial.title} · {step.role}</div><h2 id="tutorial-stage-title">{step.title}</h2></div><button type="button" className="sig-btn sig-btn-ghost sig-btn-sm" aria-label="Fechar detalhes da etapa" onClick={onClose}><X size={19} /></button></header>
        <div className="tutorial-stage-body">
            <div className="tutorial-stage-content"><StagePreview tutorial={tutorial} step={step} /></div>
        </div>
        <footer className="tutorial-stage-footer"><button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" onClick={onClose}>Fechar</button></footer>
    </dialog>, document.body);
}
