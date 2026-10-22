import { createContext, useContext } from 'react';
import { ACTIONS, Joyride } from 'react-joyride';
import { ArrowLeft, ArrowRight, X } from 'lucide-react';

const Navigation = createContext(null);

function Tooltip({ step, tooltipProps }) {
    const { index, total, section, moduleName, idPrefix, onPrevious, onNext, onExit } = useContext(Navigation);
    return <div {...tooltipProps} aria-labelledby={`${idPrefix}-tour-title`} aria-describedby={`${idPrefix}-tour-content`} className="flex max-h-[calc(100dvh-32px)] w-[min(360px,calc(100vw-32px))] flex-col overflow-hidden rounded-xl bg-white text-left text-slate-900 shadow-2xl">
        <header className="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
            <p className="text-xs font-semibold text-blue-700">{moduleName} · {section}</p>
            <button type="button" onClick={onExit} aria-label="Fechar tour" className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100"><X size={17} /></button>
        </header>
        <div className="min-h-0 flex-1 overflow-y-auto p-5"><h2 id={`${idPrefix}-tour-title`} className="text-base font-bold leading-snug">{step.title}</h2><p id={`${idPrefix}-tour-content`} className="mt-3 text-sm leading-relaxed text-slate-600">{step.content}</p></div>
        <footer className="shrink-0 space-y-3 border-t border-slate-100 px-5 py-4">
            <div className="flex items-center justify-between text-xs text-slate-500"><span>Passo {index + 1} de {total}</span><span>{Math.round((index + 1) / total * 100)}%</span></div>
            <div className="h-1 overflow-hidden rounded bg-slate-100"><div className="h-full bg-blue-600" style={{ width: `${(index + 1) / total * 100}%` }} /></div>
            <div className="flex justify-between gap-3"><button type="button" onClick={onPrevious} disabled={index === 0} className="sig-btn sig-btn-secondary disabled:opacity-40"><ArrowLeft size={14} />Voltar</button><button type="button" onClick={onNext} className="sig-btn sig-btn-primary">{index === total - 1 ? 'Concluir tour' : 'Avançar'}<ArrowRight size={14} /></button></div>
        </footer>
    </div>;
}

export default function GuidedModuleTour({ step, index, total, section, moduleName, idPrefix, onPrevious, onNext, onExit }) {
    return <Navigation.Provider value={{ index, total, section, moduleName, idPrefix, onPrevious, onNext, onExit }}>
        <Joyride key={index} run continuous scrollToFirstStep
            steps={[{ target: step.target, title: step.title, content: step.content, placement: 'bottom', skipBeacon: true }]}
            tooltipComponent={Tooltip}
            floatingOptions={{ strategy: 'fixed', shiftOptions: { mainAxis: true, crossAxis: true, padding: 16 } }}
            onEvent={(event) => { if ([ACTIONS.CLOSE, ACTIONS.SKIP].includes(event.action)) onExit(); }}
            options={{ overlayClickAction: false, dismissKeyAction: 'close', blockTargetInteraction: true, overlayColor: 'rgba(15, 23, 42, 0.58)', primaryColor: '#2563eb', scrollOffset: 100, scrollDuration: 180, spotlightPadding: 6, spotlightRadius: 12, zIndex: 10000 }}
        />
    </Navigation.Provider>;
}
