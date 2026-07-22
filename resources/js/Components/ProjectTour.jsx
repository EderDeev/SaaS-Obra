import { router } from '@inertiajs/react';
import { ACTIONS, EVENTS, Joyride, STATUS } from 'react-joyride';
import { useEffect, useMemo, useRef, useState } from 'react';

const storageKey = 'projects:tour-section';
const activeStorageKey = 'projects:tour-active';
const navigationStorageKey = 'projects:tour-navigating';
const startedAtStorageKey = 'projects:tour-started-at';
const maxTourAgeMs = 30 * 60 * 1000;

const stepsBySection = {
    tree: [
        {
            target: '[data-tour="projects-overview"]',
            title: 'Visualizar projetos',
            content: 'Esta e a arvore oficial dos projetos aprovados. Ela organiza os arquivos pela EAP do contrato e concentra o acesso as revisoes vigentes.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="projects-navigation"]',
            title: 'Fluxos do modulo',
            content: 'O menu de Projetos separa a consulta dos aprovados, as revisoes, a submissao de arquivos, a analise tecnica, a Lista Mestra e os responsaveis por disciplina.',
            placement: 'right',
        },
        {
            target: '[data-tour="projects-metrics"]',
            title: 'Indicadores do acervo',
            content: 'Os indicadores mostram rapidamente quantos projetos aprovados, contratos, obras e disciplinas compoem o acervo visivel.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="projects-filters"]',
            title: 'Filtros da arvore',
            content: 'Filtre por contrato, obra ou disciplina e use a busca para localizar codigo, titulo, arquivo, fase ou tipo documental.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="projects-tree"]',
            title: 'Estrutura EAP',
            content: 'A hierarquia segue contrato, obra, disciplina, fase, tipo e projeto. Os niveis podem ser expandidos ou recolhidos para facilitar a navegacao.',
            placement: 'top',
        },
        {
            target: '[data-tour="projects-tree-project"]',
            title: 'Projeto aprovado',
            content: 'O projeto exibe codigo, revisao oficial, data de aprovacao, situacao no APS e alertas como RNC aberta ou uma nova revisao em fluxo.',
            placement: 'top',
        },
        {
            target: '[data-tour="projects-open-viewer"]',
            title: 'Abrir visualizador',
            content: 'Visualizar abre o arquivo processado no APS. A proxima parte apresenta as ferramentas de consulta, comentarios e controle da revisao.',
            placement: 'top',
        },
    ],
    viewer: [
        {
            target: '[data-tour="project-viewer-header"]',
            title: 'Projeto aberto',
            content: 'O cabecalho identifica o projeto, a revisao vigente, o contrato, a obra, a disciplina e a fase do documento.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="project-viewer-toolbar"]',
            title: 'Ferramentas de navegacao',
            content: 'A barra do visualizador oferece zoom, enquadramento, movimentacao, orbitacao e acesso aos recursos do modelo processado pelo APS.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="project-viewer-canvas"]',
            title: 'Visualizacao do arquivo',
            content: 'Aqui voce consulta pranchas, modelos BIM e demais formatos processados. O arquivo permanece vinculado a revisao oficial do projeto.',
            placement: 'left',
        },
        {
            target: '[data-tour="project-viewer-comments"]',
            title: 'Comentarios e marcacoes',
            content: 'Comentarios tecnicos podem ser associados a pontos do arquivo, receber responsavel, prioridade e acompanhar a pendencia ate sua resolucao.',
            placement: 'left',
        },
        {
            target: '[data-tour="project-viewer-checklist"]',
            title: 'Checklist de revisao',
            content: 'Na analise, o checklist confirma EAP, carregamento do arquivo e pendencias tecnicas antes do envio para aprovacao.',
            placement: 'left',
        },
        {
            target: '[data-tour="project-viewer-flow"]',
            title: 'Fluxo da revisao',
            content: 'Cada submissao passa por analise e aprovacao. Quando aprovada, a revisao se torna oficial na arvore e permanece registrada no historico do projeto.',
            placement: 'top',
        },
    ],
};

export function startProjectTour(tenantSlug) {
    const startedAt = Date.now();

    window.sessionStorage.setItem(activeStorageKey, '1');
    window.sessionStorage.setItem(storageKey, 'tree');
    window.sessionStorage.setItem(navigationStorageKey, '1');
    window.sessionStorage.setItem(startedAtStorageKey, String(startedAt));

    router.visit(route('tenant.projects.visualizar.index', tenantSlug), {
        data: { tour: 'tree', tour_started_at: startedAt },
        preserveScroll: false,
        preserveState: false,
        replace: true,
    });
}

export default function ProjectTour({ section, detailUrl = null, onExit = null }) {
    const [run, setRun] = useState(false);
    const navigatingRef = useRef(false);
    const steps = useMemo(
        () => (stepsBySection[section] || []).map((step) => ({ ...step, skipBeacon: true, spotlightClicks: true })),
        [section],
    );

    function clearTour() {
        navigatingRef.current = false;
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
        if (navigatingRef.current) return;

        if (section === 'tree' && detailUrl) {
            navigatingRef.current = true;
            window.sessionStorage.setItem(activeStorageKey, '1');
            window.sessionStorage.setItem(storageKey, 'viewer');
            window.sessionStorage.setItem(navigationStorageKey, '1');
            window.location.assign(detailUrl);
            return;
        }

        clearTour();
    }

    useEffect(() => {
        if (typeof window === 'undefined') return undefined;

        const params = new URLSearchParams(window.location.search);
        const tourFromUrl = params.get('tour');
        const activeSection = tourFromUrl || window.sessionStorage.getItem(storageKey);
        const startedAtFromUrl = Number(params.get('tour_started_at'));

        if (tourFromUrl === 'tree' && Number.isFinite(startedAtFromUrl) && startedAtFromUrl > 0) {
            window.sessionStorage.setItem(startedAtStorageKey, String(startedAtFromUrl));

            const cleanUrl = new URL(window.location.href);
            cleanUrl.searchParams.delete('tour');
            cleanUrl.searchParams.delete('tour_started_at');
            window.history.replaceState({}, '', `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`);
        }

        const startedAt = Number(window.sessionStorage.getItem(startedAtStorageKey));
        const isFresh = Number.isFinite(startedAt) && startedAt > 0 && Date.now() - startedAt < maxTourAgeMs;
        const internalNavigation = window.sessionStorage.getItem(navigationStorageKey) === '1';
        const shouldRun = activeSection === section && isFresh && (Boolean(tourFromUrl) || internalNavigation);

        if (shouldRun) {
            navigatingRef.current = false;
            window.sessionStorage.removeItem(navigationStorageKey);
            window.sessionStorage.setItem(activeStorageKey, '1');
            window.sessionStorage.setItem(storageKey, section);
            window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
            const timer = window.setTimeout(() => setRun(true), 180);

            return () => window.clearTimeout(timer);
        }

        if (window.sessionStorage.getItem(activeStorageKey) === '1' && activeSection === section) clearTour();
        setRun(false);

        return undefined;
    }, [section, steps]);

    useEffect(() => {
        if (!run) return undefined;

        const handleTourButton = (event) => {
            const button = event.target.closest('button');
            if (!button) return;

            const label = button.getAttribute('aria-label') || button.textContent?.trim();

            if (section === 'tree' && label === 'Continuar') {
                event.preventDefault();
                event.stopImmediatePropagation();
                finishSection();
                return;
            }

            if (section === 'viewer' && label === 'Terminar tour') {
                event.preventDefault();
                event.stopImmediatePropagation();
                clearTour();
                return;
            }

            if (label !== 'Fechar tour') return;

            event.preventDefault();
            event.stopImmediatePropagation();
            clearTour();
        };

        document.addEventListener('click', handleTourButton, true);

        return () => document.removeEventListener('click', handleTourButton, true);
    }, [run]);

    function handleCallback(data) {
        if (data.status === STATUS.SKIPPED || data.action === ACTIONS.CLOSE) {
            clearTour();
            return;
        }

        if (data.type === EVENTS.STEP_AFTER && data.action !== ACTIONS.PREV && data.index >= steps.length - 1) {
            finishSection();
            return;
        }

        if (data.type === EVENTS.TARGET_NOT_FOUND) {
            if (data.index >= steps.length - 1) finishSection();
            return;
        }

        if ((data.status === STATUS.FINISHED || data.type === EVENTS.TOUR_END) && !navigatingRef.current) {
            finishSection();
        }
    }

    if (!steps.length) return null;

    return (
        <Joyride
            continuous
            disableOverlayClose
            disableScrolling
            hideCloseButton
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
                last: section === 'viewer' ? 'Terminar tour' : 'Continuar',
                next: 'Avan\u00e7ar',
                skip: 'Fechar tour',
            }}
        />
    );
}
