import { useEffect, useRef, useState } from 'react';
import { FileText, X } from 'lucide-react';
import { createPortal } from 'react-dom';
import OsDemandPreview from './OsDemandPreview';
import OsStagePreview from './OsStagePreview';
import TutorialWalkthrough from './TutorialWalkthrough';

function StagePreview({ tutorial, step }) {
    if (step.preview === 'os-stage') return <OsStagePreview stage={step.id} />;
    if (step.preview === 'os-demand') return <OsDemandPreview editing={step.id === 'ajuste-analista'} />;
    return <TutorialWalkthrough tutorial={tutorial} step={step} />;
}

export default function TutorialStageModal({ tutorial, step, onClose }) {
    const dialog = useRef(null);
    const [tab, setTab] = useState('stage');
    const Icon = step.icon || FileText;
    const hasMedia = tutorial.screenshots?.length > 0 || tutorial.videos?.length > 0;
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
        {hasMedia && <div className="tutorial-stage-tabs" role="tablist" aria-label="Conteúdo da etapa"><button type="button" role="tab" id="tutorial-tab-stage" aria-controls="tutorial-panel-stage" aria-selected={tab === 'stage'} onClick={() => setTab('stage')}>Como fazer</button><button type="button" role="tab" id="tutorial-tab-media" aria-controls="tutorial-panel-media" aria-selected={tab === 'media'} onClick={() => setTab('media')}>Referências visuais</button></div>}
        <div className="tutorial-stage-body">
            <div id="tutorial-panel-stage" role={hasMedia ? 'tabpanel' : undefined} aria-labelledby={hasMedia ? 'tutorial-tab-stage' : undefined} hidden={tab !== 'stage'}>
                <div className="tutorial-stage-content"><StagePreview tutorial={tutorial} step={step} /></div>
            </div>
            {hasMedia && <div id="tutorial-panel-media" role="tabpanel" aria-labelledby="tutorial-tab-media" hidden={tab !== 'media'} className="tutorial-stage-media"><p>Capturas e vídeos de versões anteriores, mantidos como referência do módulo. Os campos e fluxos atuais estão descritos em Como fazer.</p>{tutorial.videos?.map(video => <figure key={video.src}><video controls preload="metadata" poster={video.poster}><source src={video.src} type="video/webm" /></video><figcaption>{video.title}</figcaption></figure>)}{tutorial.screenshots?.map(image => <figure key={image.src}><img src={image.src} alt={image.title} loading="lazy" /><figcaption>{image.title}</figcaption></figure>)}</div>}
        </div>
        <footer className="tutorial-stage-footer"><button type="button" className="sig-btn sig-btn-secondary sig-btn-sm" onClick={onClose}>Fechar</button></footer>
    </dialog>, document.body);
}
