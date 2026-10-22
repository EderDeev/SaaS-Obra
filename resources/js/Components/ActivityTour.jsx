import GuidedModuleTour from '@/Components/GuidedModuleTour';
import { activityTourSections, activityTourSteps, resolveActivityTourIndex } from '@/Support/activityTour';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

function clearLegacyTour() {
    ['section', 'active', 'navigating', 'started-at'].forEach(key => window.sessionStorage.removeItem(`activities:tour-${key}`));
}

export function startActivityTour(tenantSlug) {
    clearLegacyTour();
    router.get(route('tenant.activities.tour-preview', tenantSlug), { screen: 'create', step: 0 }, {
        preserveScroll: false, preserveState: false,
    });
}

export function ActivityTourProgress({ section, onExit }) {
    const current = activityTourSections.findIndex(([id]) => id === section);
    return <div className="mb-5 space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3"><p className="text-xs font-semibold text-blue-900">Tour Atividades · Demonstração com dados fictícios</p>{onExit && <button type="button" onClick={onExit} className="text-xs font-semibold text-blue-700 underline">Sair do tour</button>}</div>
        <ol aria-label="Etapas do tour de Atividades" className="flex flex-wrap gap-2">{activityTourSections.map(([id,label], index) => <li key={id} aria-current={id === section ? 'step' : undefined} className={`rounded-lg border px-3 py-2 text-xs ${current === index ? 'border-blue-500 bg-blue-600 font-semibold text-white' : 'border-slate-200 bg-white text-slate-500'}`}>{index + 1}. {label}</li>)}</ol>
    </div>;
}

export default function ActivityTour({ section, tenantSlug, onStatusChange }) {
    const { url } = usePage();
    const requestedIndex = new URL(url, window.location.origin).searchParams.get('step');
    const [index, setIndex] = useState(() => resolveActivityTourIndex(section, requestedIndex));
    const navigating = useRef(false);
    const step = activityTourSteps[index];

    useEffect(() => {
        navigating.current = false;
        setIndex(resolveActivityTourIndex(section, requestedIndex));
    }, [section, requestedIndex]);

    useEffect(() => {
        if (section === 'flow' && step.status) {
            onStatusChange?.(step.status);
        }
    }, [section, step.status, onStatusChange]);

    function exit() {
        if (navigating.current) return;
        navigating.current = true;
        clearLegacyTour();
        router.visit(route('tenant.activities.index', tenantSlug), { replace: true });
    }

    function go(nextIndex) {
        if (navigating.current) return;
        if (nextIndex >= activityTourSteps.length) { exit(); return; }
        const next = activityTourSteps[nextIndex];
        if (!next) return;
        if (next.section === section) { setIndex(nextIndex); return; }
        navigating.current = true;
        router.get(route('tenant.activities.tour-preview', tenantSlug), { screen: next.section, step: nextIndex }, {
            preserveScroll: false, preserveState: false, replace: true,
            onFinish: () => { navigating.current = false; },
        });
    }

    return <GuidedModuleTour step={step} index={index} total={activityTourSteps.length}
        moduleName="Atividades" idPrefix="activities" section={activityTourSections.find(([id]) => id === section)?.[1] || 'Atividades'}
        onPrevious={() => go(index - 1)} onNext={() => go(index + 1)} onExit={exit} />;
}
