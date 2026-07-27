import { router } from '@inertiajs/react';
import { ACTIONS, EVENTS, Joyride, STATUS } from 'react-joyride';
import { useEffect, useMemo, useRef, useState } from 'react';

const storageKey = 'projects:tour-section';
const activeStorageKey = 'projects:tour-active';
const navigationStorageKey = 'projects:tour-navigating';
const startedAtStorageKey = 'projects:tour-started-at';
const maxTourAgeMs = 30 * 60 * 1000;
const sectionSequence = ['responsibles', 'submit', 'review', 'tree', 'viewer', 'revisions', 'master-list'];
const sectionActionLabels = {
    responsibles: 'Vincular responsável',
    submit: 'Confirmar submissão',
    review: 'Aprovar projeto',
    tree: 'Abrir visualizador',
    viewer: 'Continuar',
    revisions: 'Continuar',
    'master-list': 'Concluir tutorial',
};

const stepsBySection = {
    responsibles: [
        {
            target: '[data-tour="project-responsibles"]',
            title: 'Etapa 1 de 6: preparar o fluxo',
            content: 'O ciclo começa pela definição de quem analisa e quem aprova os projetos de cada disciplina do contrato.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="project-responsibles-form"]',
            title: 'Vincular responsáveis',
            content: 'Escolha o contrato, o tipo de responsabilidade, uma ou mais disciplinas e o usuário que participará desse fluxo.',
            placement: 'right',
        },
        {
            target: '[data-tour="project-responsibles-list"]',
            title: 'Conferir a equipe',
            content: 'A listagem agrupa os vínculos por usuário e mostra em quais disciplinas cada pessoa atua antes de qualquer submissão.',
            placement: 'left',
        },
    ],
    submit: [
        {
            target: '[data-tour="project-submit-form"]',
            title: 'Etapa 2 de 6: submeter o projeto',
            content: 'Com os responsáveis definidos, abra a submissão e registre o projeto no contrato correto.',
            placement: 'left',
        },
        {
            target: '[data-tour="project-submit-fields"]',
            title: 'Classificar o arquivo',
            content: 'Informe contrato, obra, disciplina, fase, tipo e título. Esses dados formam a EAP e determinam o fluxo de análise.',
            placement: 'right',
        },
        {
            target: '[data-tour="project-submit-confirm"]',
            title: 'Revisar a submissão',
            content: 'Confira a EAP, a revisão e o arquivo selecionado. Ao confirmar, o projeto seguirá para a fila de análise.',
            placement: 'left',
        },
    ],
    review: [
        {
            target: '[data-tour="project-review-project"]',
            title: 'Etapa 3 de 6: analisar o projeto',
            content: 'O projeto submetido aparece com a revisão, a EAP, o responsável pelo envio e o estado do processamento APS.',
            placement: 'top',
        },
        {
            target: '[data-tour="project-review-checklist"]',
            title: 'Verificar',
            content: 'Abra o Viewer e o checklist para conferir o arquivo, registrar comentários técnicos e enviar o projeto para aprovação.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="project-review-decision"]',
            title: 'Aprovar ou reprovar',
            content: 'Na aprovação, o projeto é liberado para a árvore oficial. Na reprovação, o motivo é obrigatório e o responsável pelo envio é notificado.',
            placement: 'top',
        },
    ],
    tree: [
        {
            target: '[data-tour="projects-overview"]',
            title: 'Etapa 4 de 6: projeto liberado',
            content: 'Depois da aprovação, o projeto passa a integrar a árvore oficial, organizada pela estrutura EAP do contrato.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="projects-tree-project"]',
            title: 'Localizar na árvore',
            content: 'O registro aprovado mostra código, revisão oficial, data de aprovação, situação APS e eventuais alertas vinculados.',
            placement: 'top',
        },
        {
            target: '[data-tour="projects-open-viewer"]',
            title: 'Abrir o projeto',
            content: 'A partir da árvore, abra o arquivo processado para visualizar o projeto e acompanhar os comentários técnicos.',
            placement: 'top',
        },
    ],
    viewer: [
        {
            target: '[data-tour="project-viewer-header"]',
            title: 'Etapa 5 de 6: visualizar',
            content: 'O cabeçalho confirma o projeto, a revisão vigente e o arquivo oficial que está sendo consultado.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="project-viewer-canvas"]',
            title: 'Consultar o arquivo',
            content: 'Use as ferramentas do Viewer para navegar, medir, consultar camadas e inspecionar o conteúdo processado pela APS.',
            placement: 'right',
        },
        {
            target: '[data-tour="project-viewer-comments"]',
            title: 'Etapa 6 de 6: comentar',
            content: 'O painel lateral reúne comentários técnicos, responsáveis, prioridades, respostas e o acompanhamento das pendências.',
            placement: 'left',
        },
        {
            target: '[data-tour="project-viewer-rnc-alert"]',
            title: 'RNC vinculada',
            content: 'Quando o projeto possui uma RNC aberta, o aviso permanece visível no painel para acompanhar a pendência até sua conclusão.',
            placement: 'left',
        },
        {
            target: '[data-tour="project-viewer-comment-form"]',
            title: 'Registrar comentário',
            content: 'Selecione um ponto ou objeto, registre o comentário e atribua um responsável e uma prioridade.',
            placement: 'left',
        },
        {
            target: '[data-tour="project-viewer-comment-list"]',
            title: 'Responder e resolver',
            content: 'As respostas preservam o histórico da decisão até que a pendência seja definida como resolvida.',
            placement: 'left',
        },
    ],
    revisions: [
        {
            target: '[data-tour="project-revisions"]',
            title: 'Recurso complementar: revisões',
            content: 'Quando um projeto aprovado recebe uma nova revisão, acompanhe a CAP, consulte o histórico e compare a versão anterior com a atual.',
            placement: 'top',
        },
    ],
    'master-list': [
        {
            target: '[data-tour="project-master-list"]',
            title: 'Fechamento: Lista Mestra',
            content: 'Combine contratos, obras, disciplinas, fases e tipos para gerar a relação controlada e exportá-la em PDF ou Excel.',
            placement: 'bottom',
        },
    ],
};

export function startProjectTour(tenantSlug) {
    const startedAt = Date.now();

    window.sessionStorage.setItem(activeStorageKey, '1');
    window.sessionStorage.setItem(storageKey, 'responsibles');
    window.sessionStorage.setItem(navigationStorageKey, '1');
    window.sessionStorage.setItem(startedAtStorageKey, String(startedAt));

    router.visit(route('tenant.projects.tour-preview', tenantSlug), {
        data: { screen: 'responsibles' },
        preserveScroll: false,
        preserveState: false,
        replace: true,
    });
}

export default function ProjectTour({ section, tenantSlug = null, detailUrl = null, onExit = null }) {
    const [run, setRun] = useState(false);
    const [dismissed, setDismissed] = useState(false);
    const navigatingRef = useRef(false);
    const navigatingSectionRef = useRef(null);
    const steps = useMemo(
        () => (stepsBySection[section] || []).map((step) => ({
            ...step,
            skipBeacon: true,
            spotlightClicks: true,
        })),
        [section],
    );

    useEffect(() => {
        navigatingRef.current = false;
        navigatingSectionRef.current = null;
    }, [section]);

    function clearTour() {
        navigatingRef.current = false;
        navigatingSectionRef.current = null;
        setDismissed(true);
        window.sessionStorage.removeItem(activeStorageKey);
        window.sessionStorage.removeItem(storageKey);
        window.sessionStorage.removeItem(navigationStorageKey);
        window.sessionStorage.removeItem(startedAtStorageKey);

        const url = new URL(window.location.href);
        url.searchParams.delete('tour');
        url.searchParams.delete('tour_started_at');
        window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
        setRun(false);
        onExit?.();
    }

    function finishSection() {
        if (navigatingRef.current && navigatingSectionRef.current === section) {
            return;
        }

        if (section === 'tree' && detailUrl) {
            navigatingRef.current = true;
            navigatingSectionRef.current = section;
            window.sessionStorage.setItem(activeStorageKey, '1');
            window.sessionStorage.setItem(storageKey, 'viewer');
            window.sessionStorage.setItem(navigationStorageKey, '1');
            window.location.assign(detailUrl);
            return;
        }

        const currentIndex = sectionSequence.indexOf(section);
        const nextSection = currentIndex >= 0 ? sectionSequence[currentIndex + 1] : null;

        if (nextSection && tenantSlug) {
            navigatingRef.current = true;
            navigatingSectionRef.current = section;
            window.sessionStorage.setItem(activeStorageKey, '1');
            window.sessionStorage.setItem(storageKey, nextSection);
            window.sessionStorage.setItem(navigationStorageKey, '1');

            if (nextSection === 'tree') {
                const treeUrl = new URL(route('tenant.projects.visualizar.index', tenantSlug), window.location.origin);
                window.location.assign(treeUrl.toString());
                return;
            }

            const nextUrl = new URL(window.location.href);
            nextUrl.search = '';
            nextUrl.searchParams.set('screen', nextSection);
            window.location.assign(nextUrl.toString());
            return;
        }

        clearTour();
    }

    useEffect(() => {
        if (typeof window === 'undefined') {
            return undefined;
        }

        const params = new URLSearchParams(window.location.search);
        const tourFromUrl = params.get('tour');
        const activeSection = tourFromUrl || window.sessionStorage.getItem(storageKey);
        const startedAtFromUrl = Number(params.get('tour_started_at'));

        if (tourFromUrl && Number.isFinite(startedAtFromUrl) && startedAtFromUrl > 0) {
            window.sessionStorage.setItem(startedAtStorageKey, String(startedAtFromUrl));

            const cleanUrl = new URL(window.location.href);
            cleanUrl.searchParams.delete('tour');
            cleanUrl.searchParams.delete('tour_started_at');
            window.history.replaceState({}, '', `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`);
        }

        const startedAt = Number(window.sessionStorage.getItem(startedAtStorageKey));
        const isFresh = Number.isFinite(startedAt)
            && startedAt > 0
            && Date.now() - startedAt < maxTourAgeMs;
        const internalNavigation = window.sessionStorage.getItem(navigationStorageKey) === '1';
        const shouldRun = activeSection === section
            && isFresh
            && (Boolean(tourFromUrl) || internalNavigation);

        if (shouldRun) {
            navigatingRef.current = false;
            setDismissed(false);
            window.sessionStorage.removeItem(navigationStorageKey);
            window.sessionStorage.setItem(activeStorageKey, '1');
            window.sessionStorage.setItem(storageKey, section);
            window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
            const timer = window.setTimeout(() => setRun(true), 180);

            return () => window.clearTimeout(timer);
        }

        if (window.sessionStorage.getItem(activeStorageKey) === '1' && activeSection === section) {
            clearTour();
        }
        setRun(false);

        return undefined;
    }, [section, steps]);

    useEffect(() => {
        if (!run) {
            return undefined;
        }

        const handleTourButton = (event) => {
            const button = event.target.closest('button');
            if (!button) {
                return;
            }

            const label = button.getAttribute('aria-label') || button.textContent?.trim();

            if (label === sectionActionLabels[section]) {
                event.preventDefault();
                event.stopImmediatePropagation();
                finishSection();
                return;
            }

            if (label !== 'Fechar tour') {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            clearTour();
        };

        document.addEventListener('click', handleTourButton, true);

        return () => document.removeEventListener('click', handleTourButton, true);
    }, [run, section]);

    function handleCallback(data) {
        if (data.status === STATUS.SKIPPED || data.action === ACTIONS.CLOSE) {
            clearTour();
            return;
        }

        if (data.type === EVENTS.STEP_AFTER
            && data.action !== ACTIONS.PREV
            && data.index >= steps.length - 1) {
            finishSection();
            return;
        }

        if (data.type === EVENTS.TARGET_NOT_FOUND) {
            if (data.index >= steps.length - 1) {
                finishSection();
            }
            return;
        }

        if ((data.status === STATUS.FINISHED || data.type === EVENTS.TOUR_END)
            && !navigatingRef.current) {
            finishSection();
        }
    }

    if (!steps.length || dismissed) {
        return null;
    }

    return (
        <Joyride
            continuous
            disableOverlayClose
            disableScrolling
            callback={handleCallback}
            run={run}
            scrollOffset={90}
            showProgress
            showSkipButton
            steps={steps}
            styles={{
                options: {
                    arrowColor: '#ffffff',
                    backgroundColor: '#ffffff',
                    overlayColor: 'rgba(15, 23, 42, 0.58)',
                    primaryColor: '#047857',
                    textColor: '#1f2937',
                    zIndex: 10000,
                },
                buttonNext: { borderRadius: 6, fontWeight: 700, padding: '8px 14px' },
                buttonBack: { color: '#475569', marginRight: 8 },
                buttonSkip: { color: '#64748b' },
                beacon: { display: 'none' },
                beaconInner: { display: 'none' },
                beaconOuter: { display: 'none' },
                tooltip: { borderRadius: 10, boxShadow: '0 18px 60px rgba(15, 23, 42, 0.22)' },
                tooltipTitle: { fontSize: 16, fontWeight: 800 },
            }}
            locale={{
                back: 'Voltar',
                close: 'Fechar tour',
                last: sectionActionLabels[section] || 'Continuar',
                next: 'Avançar',
                skip: 'Fechar tour',
            }}
        />
    );
}
