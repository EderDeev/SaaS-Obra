import { router } from '@inertiajs/react';
import { ACTIONS, EVENTS, Joyride, STATUS } from 'react-joyride';
import { useEffect, useMemo, useRef, useState } from 'react';

const storageKey = 'contracts:tour-section';
const activeStorageKey = 'contracts:tour-active';
const stepStorageKey = 'contracts:tour-step';
const navigationStorageKey = 'contracts:tour-navigating';
const startedAtStorageKey = 'contracts:tour-started-at';
const maxTourAgeMs = 30 * 60 * 1000;

const stepsBySection = {
    contracts: [
        {
            target: '[data-tour="contracts-overview"]',
            title: 'Contratos',
            content: 'Aqui você acessa os contratos do tenant. Cada contrato concentra os dados principais, vínculos e os módulos de acompanhamento.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="contracts-filters"]',
            title: 'Filtros',
            content: 'Use os filtros de status, local, empresas e pendências para encontrar rapidamente o contrato que precisa acompanhar. A busca também localiza por código, obra ou empresa.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="contracts-list"]',
            title: 'Portfólio de contratos',
            content: 'Os cards mostram vigência, valor, empresas vinculadas e os principais pontos de atenção. Você pode alternar entre cards e tabela.',
            placement: 'top',
        },
        {
            target: '[data-tour="contracts-parametrize"]',
            title: 'Parametrização',
            content: 'Use Parametrizar para cadastrar e vincular empresas, obras e disciplinas. O vínculo final define a obra principal, cliente, construtora e gerenciadora do contrato.',
            placement: 'top',
        },
        {
            target: '[data-tour="contracts-additive"]',
            title: 'Aditivos',
            content: 'Em Aditivo você registra custo, prazo ou ambos, com título, motivação e documento de suporte. O histórico preserva o contrato base e cada alteração posterior.',
            placement: 'top',
        },
        {
            target: '[data-tour="contracts-open"]',
            title: 'Abrir contrato',
            content: 'Agora vamos abrir este contrato para ver os indicadores, dados detalhados e o histórico de aditivos.',
            placement: 'top',
        },
    ],
    detail: [
        {
            target: '[data-tour="contract-detail-header"]',
            title: 'Visão do contrato',
            content: 'O cabeçalho reúne o código, as empresas, o local e a vigência. A etiqueta de prazo muda de cor conforme os dias restantes para o encerramento.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="contract-detail-actions"]',
            title: 'Ações do contrato',
            content: 'Por aqui você parametriza os vínculos, registra um aditivo e acessa os fluxos de atividades, projetos e RNCs quando tiver permissão.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="contract-detail-metrics"]',
            title: 'Indicadores',
            content: 'Estes indicadores resumem atividades, atrasos, RNCs e projetos para facilitar a leitura das pendências do contrato.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="contract-detail-modules"]',
            title: 'Resumo dos módulos',
            content: 'Acompanhe os registros mais recentes de Atividades, Projetos e RNCs sem sair do contrato. Em Ver módulo você acessa a listagem completa de cada área.',
            placement: 'top',
        },
        {
            target: '[data-tour="contract-detail-data"]',
            title: 'Dados do contrato',
            content: 'Aqui ficam os dados cadastrais, empresas vinculadas, local, valor contratado e a vigência atualizada pelos aditivos de prazo.',
            placement: 'left',
        },
        {
            target: '[data-tour="contract-detail-additives"]',
            title: 'Histórico de aditivos',
            content: 'O card de aditivos mostra a última alteração. Use Histórico para consultar o contrato base, os valores e prazos iniciais, além de todos os aditivos registrados.',
            placement: 'left',
        },
        {
            target: '[data-tour="contract-detail-team"]',
            title: 'Equipe no contrato',
            content: 'Aqui você confere os usuários vinculados ao contrato, o lado que representam e a função de cada participante na equipe.',
            placement: 'left',
        },
    ],
};

export function startContractTour(tenantSlug) {
    const startedAt = Date.now();

    window.sessionStorage.setItem(activeStorageKey, '1');
    window.sessionStorage.setItem(storageKey, 'contracts');
    window.sessionStorage.setItem(stepStorageKey, '0');
    window.sessionStorage.setItem(navigationStorageKey, '1');
    window.sessionStorage.setItem(startedAtStorageKey, String(startedAt));

    router.visit(route('tenant.contracts.index', tenantSlug), {
        data: { tour: 'contracts', tour_started_at: startedAt },
        preserveScroll: false,
        preserveState: false,
        replace: true,
    });
}

export default function ContractTour({ section, detailUrl = null, onExit = null }) {
    const [run, setRun] = useState(false);
    const navigatingRef = useRef(false);
    const steps = useMemo(
        () => (stepsBySection[section] || []).map((step) => ({ ...step, skipBeacon: true, spotlightClicks: true })),
        [section],
    );

    function showStep() {
        setRun(true);
    }

    function clearTour() {
        navigatingRef.current = false;
        window.sessionStorage.removeItem(activeStorageKey);
        window.sessionStorage.removeItem(storageKey);
        window.sessionStorage.removeItem(stepStorageKey);
        window.sessionStorage.removeItem(navigationStorageKey);
        window.sessionStorage.removeItem(startedAtStorageKey);

        const url = new URL(window.location.href);
        url.searchParams.delete('tour');
        url.searchParams.delete('tour_started_at');
        window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
        setRun(false);
        onExit?.();
    }

    useEffect(() => {
        if (typeof window === 'undefined') return undefined;

        const params = new URLSearchParams(window.location.search);
        const tourFromUrl = params.get('tour');
        const activeSection = tourFromUrl || window.sessionStorage.getItem(storageKey);
        const startedAtFromUrl = Number(params.get('tour_started_at'));

        if (tourFromUrl === 'contracts' && Number.isFinite(startedAtFromUrl) && startedAtFromUrl > 0) {
            window.sessionStorage.setItem(startedAtStorageKey, String(startedAtFromUrl));

            const url = new URL(window.location.href);
            url.searchParams.delete('tour');
            url.searchParams.delete('tour_started_at');
            window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
        }

        const startedAt = Number(window.sessionStorage.getItem(startedAtStorageKey));
        const isFresh = Number.isFinite(startedAt) && startedAt > 0 && Date.now() - startedAt < maxTourAgeMs;
        const isInternalNavigation = window.sessionStorage.getItem(navigationStorageKey) === '1';
        const shouldRun = activeSection === section && isFresh && (tourFromUrl === 'contracts' || isInternalNavigation);

        if (shouldRun) {
            navigatingRef.current = false;
            window.sessionStorage.removeItem(navigationStorageKey);
            const storedStep = Number(window.sessionStorage.getItem(stepStorageKey) || 0);
            window.sessionStorage.setItem(activeStorageKey, '1');
            window.sessionStorage.setItem(storageKey, section);
            showStep();
        } else {
            if (window.sessionStorage.getItem(activeStorageKey) === '1' && activeSection === section) clearTour();
            setRun(false);
        }

        return undefined;
    }, [section, steps]);

    function setStoredStep(nextStep) {
        const normalized = Math.max(0, Math.min(nextStep, steps.length - 1));
        window.sessionStorage.setItem(stepStorageKey, String(normalized));
        showStep();
    }

    function finishSection() {
        const contractDetailUrl = detailUrl || document.querySelector('[data-tour="contracts-open"]')?.getAttribute('href');

        if (section === 'contracts' && contractDetailUrl) {
            navigatingRef.current = true;
            window.sessionStorage.setItem(activeStorageKey, '1');
            window.sessionStorage.setItem(storageKey, 'detail');
            window.sessionStorage.setItem(stepStorageKey, '0');
            window.sessionStorage.setItem(navigationStorageKey, '1');
            window.location.assign(contractDetailUrl);
            return;
        }

        clearTour();
    }

    function handleCallback(data) {
        if (data.status === STATUS.SKIPPED || data.action === ACTIONS.CLOSE) {
            clearTour();
            return;
        }

        if (data.status === STATUS.FINISHED || data.type === EVENTS.TOUR_END) {
            finishSection();
            return;
        }

        if (data.type === EVENTS.TARGET_NOT_FOUND) {
            const nextStep = data.index + 1;
            if (nextStep >= steps.length) finishSection();
            else setStoredStep(nextStep);
            return;
        }

        if (data.type === EVENTS.STEP_AFTER) {
            const nextStep = data.index + (data.action === ACTIONS.PREV ? -1 : 1);

            if (nextStep >= steps.length) finishSection();
            else if (nextStep >= 0) setStoredStep(nextStep);
            return;
        }
    }

    useEffect(() => {
        if (!run) return undefined;

        const cancelOnClose = (event) => {
            if (event.target.closest('[aria-label="Fechar tour"]')) clearTour();
        };
        const openContractOnAdvance = (event) => {
            const button = event.target.closest('button');
            const dialogTitle = button?.closest('[role="alertdialog"]')?.querySelector('h4')?.textContent?.trim();
            if (section !== 'contracts' || button?.textContent?.trim() !== 'Avançar' || dialogTitle !== 'Abrir contrato') return;

            event.preventDefault();
            event.stopImmediatePropagation();
            finishSection();
        };
        document.addEventListener('click', cancelOnClose, true);
        document.addEventListener('click', openContractOnAdvance, true);

        return () => {
            document.removeEventListener('click', cancelOnClose, true);
            document.removeEventListener('click', openContractOnAdvance, true);
        };
    }, [detailUrl, run, section]);

    if (!steps.length) return null;

    return (
        <Joyride
            continuous
            disableOverlayClose
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
                last: section === 'detail' ? 'Terminar tour' : 'Avançar',
                next: 'Avançar',
                skip: 'Fechar tour',
            }}
        />
    );
}
