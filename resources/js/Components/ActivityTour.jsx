import { router } from '@inertiajs/react';
import { ACTIONS, EVENTS, Joyride, STATUS } from 'react-joyride';
import { useEffect, useMemo, useRef, useState } from 'react';

const storageKey = 'activities:tour-section';
const activeStorageKey = 'activities:tour-active';
const navigationStorageKey = 'activities:tour-navigating';
const startedAtStorageKey = 'activities:tour-started-at';
const maxTourAgeMs = 30 * 60 * 1000;
const sections = ['create', 'board', 'detail', 'flow', 'metrics'];
const finalLabels = {
    create: 'Ver card criado',
    board: 'Abrir atividade',
    detail: 'Continuar para o fluxo',
    flow: 'Ver métricas',
    metrics: 'Concluir tutorial',
};

const stepsBySection = {
    create: [
        {
            target: '[data-tour="activities-create-header"]',
            title: 'Etapa 1: abrir uma atividade',
            content: 'O ciclo começa em Nova atividade. O cadastro reúne tudo que será necessário para executar e acompanhar o trabalho.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="activities-create-type"]',
            title: 'Atividade ou checklist',
            content: 'Use Atividade para uma entrega direta. Escolha Checklist quando o trabalho tiver várias etapas que precisam ser acompanhadas individualmente.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="activities-create-fields"]',
            title: 'Identificar e planejar',
            content: 'Defina título, contrato, categoria, prioridade e prazo. Esses dados organizam o card e alimentam os filtros e indicadores.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="activities-create-visibility"]',
            title: 'Definir a visibilidade',
            content: 'Atividades públicas ficam disponíveis aos usuários do contrato. Nas restritas, somente o criador e os responsáveis vinculados visualizam o card.',
            placement: 'top',
        },
        {
            target: '[data-tour="activities-create-checklist"]',
            title: 'Organizar as etapas',
            content: 'No Checklist, cadastre as etapas na ordem de execução. Depois, novas etapas ainda podem ser acrescentadas pela edição do card.',
            placement: 'top',
        },
        {
            target: '[data-tour="activities-create-assignees"]',
            title: 'Vincular responsáveis',
            content: 'Selecione uma ou mais pessoas do contrato. Elas recebem a atribuição e passam a acompanhar comentários, arquivos e mudanças de status.',
            placement: 'top',
        },
        {
            target: '[data-tour="activities-create-action"]',
            title: 'Criar a atividade',
            content: 'Revise a descrição e confirme. O card será aberto na coluna A fazer e os responsáveis serão notificados.',
            placement: 'top',
        },
    ],
    board: [
        {
            target: '[data-tour="activities-overview"]',
            title: 'Etapa 2: localizar a atividade',
            content: 'A tela reúne busca, filtros por contrato e categoria, acesso às métricas e o quadro operacional.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="activities-filters"]',
            title: 'Filtrar o quadro',
            content: 'Use os filtros para localizar rapidamente uma atividade pelo título, contrato, categoria ou responsável.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="activities-board"]',
            title: 'Quadro de acompanhamento',
            content: 'As quatro colunas representam o ciclo da atividade: A fazer, Em andamento, Em revisão e Concluídas.',
            placement: 'top',
        },
        {
            target: '[data-tour="activities-card"]',
            title: 'Ler o card',
            content: 'O card mostra categoria, prioridade, visibilidade, prazo, comentários, anexos e, nos checklists, o progresso das etapas sem precisar abri-lo.',
            placement: 'right',
        },
    ],
    detail: [
        {
            target: '[data-tour="activities-detail-header"]',
            title: 'Etapa 3: acompanhar a execução',
            content: 'Ao abrir o card, você encontra todo o contexto da atividade em uma única tela.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="activities-detail-status"]',
            title: 'Situação e identificação',
            content: 'O cabeçalho mantém visíveis categoria, prioridade, visibilidade, prazo, contrato e as opções de edição.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="activities-detail-checklist"]',
            title: 'Executar o checklist',
            content: 'Marque cada etapa concluída. O sistema registra o responsável, atualiza o progresso e mantém as etapas finalizadas riscadas em verde.',
            placement: 'right',
        },
        {
            target: '[data-tour="activities-detail-comments"]',
            title: 'Registrar decisões',
            content: 'Use os comentários para atualizar o andamento, responder pendências e preservar o histórico do que foi definido.',
            placement: 'right',
        },
        {
            target: '[data-tour="activities-detail-responsibles"]',
            title: 'Quem acompanha',
            content: 'Os responsáveis vinculados aparecem aqui e recebem as notificações das principais movimentações da atividade.',
            placement: 'left',
        },
        {
            target: '[data-tour="activities-detail-files"]',
            title: 'Anexar evidências',
            content: 'Planilhas, documentos e outros arquivos de apoio podem ser anexados ao card e consultados durante toda a execução.',
            placement: 'left',
        },
    ],
    flow: [
        {
            target: '[data-tour="activities-board"]',
            title: 'Etapa 4: conduzir o fluxo',
            content: 'A atividade avança pelo quadro conforme o trabalho evolui. Arraste o card para registrar cada mudança de estado.',
            placement: 'top',
        },
        {
            target: '[data-tour="activities-column-todo"]',
            title: '1. A fazer',
            content: 'O card nasce em A fazer, aguardando o início da execução pelos responsáveis.',
            placement: 'right',
        },
        {
            target: '[data-tour="activities-column-in_progress"]',
            title: '2. Em andamento',
            content: 'Mova para Em andamento quando a execução começar. Os responsáveis são informados da alteração.',
            placement: 'right',
        },
        {
            target: '[data-tour="activities-column-review"]',
            title: '3. Em revisão',
            content: 'Use Em revisão quando o resultado estiver pronto e depender de conferência ou validação.',
            placement: 'left',
        },
        {
            target: '[data-tour="activities-column-done"]',
            title: '4. Concluída',
            content: 'Ao concluir, o sistema registra a data de resolução. Ela será comparada ao prazo para medir o desempenho.',
            placement: 'left',
        },
    ],
    metrics: [
        {
            target: '[data-tour="activities-metrics-header"]',
            title: 'Etapa 5: acompanhar os resultados',
            content: 'O dashboard transforma o histórico das atividades em indicadores de produtividade e cumprimento de prazo.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="activities-metrics-filters"]',
            title: 'Definir o recorte',
            content: 'Combine período, contrato, categoria e responsável para analisar uma equipe ou operação específica.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="activities-metrics-summary"]',
            title: 'Indicadores principais',
            content: 'Acompanhe volume criado, taxa de conclusão, pontualidade, tempo médio de resolução e pendências atrasadas.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="activities-metrics-charts"]',
            title: 'Entender o desempenho',
            content: 'Compare a evolução mensal das atividades com o cumprimento dos prazos de execução.',
            placement: 'top',
        },
        {
            target: '[data-tour="activities-metrics-distribution"]',
            title: 'Demanda e situação',
            content: 'Veja quais categorias geram mais atividades e como os cards estão distribuídos entre as etapas do fluxo.',
            placement: 'top',
        },
        {
            target: '[data-tour="activities-metrics-responsibles"]',
            title: 'Produtividade da equipe',
            content: 'Compare atribuições, conclusões, atividades no prazo, atrasos e taxa de resolução por responsável.',
            placement: 'top',
        },
        {
            target: '[data-tour="activities-metrics-results"]',
            title: 'Fechar o acompanhamento',
            content: 'Consulte quais atividades foram concluídas no prazo e quais pendências ainda exigem atenção.',
            placement: 'top',
        },
    ],
};

const flowStatuses = ['todo', 'todo', 'in_progress', 'review', 'done'];

export function startActivityTour(tenantSlug) {
    const startedAt = Date.now();

    window.sessionStorage.setItem(activeStorageKey, '1');
    window.sessionStorage.setItem(storageKey, 'create');
    window.sessionStorage.setItem(navigationStorageKey, '1');
    window.sessionStorage.setItem(startedAtStorageKey, String(startedAt));

    router.visit(route('tenant.activities.tour-preview', tenantSlug), {
        data: { screen: 'create' },
        preserveScroll: false,
        preserveState: false,
        replace: true,
    });
}

export default function ActivityTour({ section, tenantSlug }) {
    const [run, setRun] = useState(false);
    const [dismissed, setDismissed] = useState(false);
    const navigatingRef = useRef(false);
    const flowStepRef = useRef(0);
    const steps = useMemo(
        () => (stepsBySection[section] || []).map((step) => ({
            ...step,
            skipBeacon: true,
            spotlightClicks: true,
        })),
        [section],
    );

    function clearTour() {
        navigatingRef.current = false;
        setDismissed(true);
        window.sessionStorage.removeItem(activeStorageKey);
        window.sessionStorage.removeItem(storageKey);
        window.sessionStorage.removeItem(navigationStorageKey);
        window.sessionStorage.removeItem(startedAtStorageKey);
        setRun(false);
    }

    function exitTour() {
        clearTour();
        router.visit(route('tenant.activities.index', tenantSlug), { replace: true });
    }

    function finishSection() {
        if (navigatingRef.current) {
            return;
        }

        const currentIndex = sections.indexOf(section);
        const nextSection = currentIndex >= 0 ? sections[currentIndex + 1] : null;

        if (!nextSection) {
            exitTour();
            return;
        }

        navigatingRef.current = true;
        window.sessionStorage.setItem(activeStorageKey, '1');
        window.sessionStorage.setItem(storageKey, nextSection);
        window.sessionStorage.setItem(navigationStorageKey, '1');

        const nextUrl = new URL(route('tenant.activities.tour-preview', tenantSlug), window.location.origin);
        nextUrl.searchParams.set('screen', nextSection);
        window.location.assign(nextUrl.toString());
    }

    useEffect(() => {
        navigatingRef.current = false;
        flowStepRef.current = 0;
    }, [section]);

    useEffect(() => {
        const activeSection = window.sessionStorage.getItem(storageKey);
        const startedAt = Number(window.sessionStorage.getItem(startedAtStorageKey));
        const isFresh = Number.isFinite(startedAt)
            && startedAt > 0
            && Date.now() - startedAt < maxTourAgeMs;
        const internalNavigation = window.sessionStorage.getItem(navigationStorageKey) === '1';

        if (activeSection !== section || !isFresh || !internalNavigation) {
            setRun(false);
            return undefined;
        }

        window.sessionStorage.removeItem(navigationStorageKey);
        window.scrollTo({ top: 0, left: 0, behavior: 'auto' });

        if (section === 'flow') {
            window.dispatchEvent(new CustomEvent('activities:tour-flow-status', { detail: 'todo' }));
        }

        const timer = window.setTimeout(() => setRun(true), 220);

        return () => window.clearTimeout(timer);
    }, [section, steps]);

    useEffect(() => {
        if (!run) {
            return undefined;
        }

        function handleTourButton(event) {
            const button = event.target.closest('button');

            if (!button) {
                return;
            }

            const label = button.getAttribute('aria-label') || button.textContent?.trim();

            if (section === 'flow' && (label === 'Avançar' || label === 'Voltar')) {
                flowStepRef.current = label === 'Voltar'
                    ? Math.max(0, flowStepRef.current - 1)
                    : Math.min(flowStatuses.length - 1, flowStepRef.current + 1);

                window.dispatchEvent(new CustomEvent('activities:tour-flow-status', {
                    detail: flowStatuses[flowStepRef.current],
                }));
            }

            if (label === finalLabels[section]) {
                event.preventDefault();
                event.stopImmediatePropagation();
                finishSection();
                return;
            }

            if (label === 'Fechar tour') {
                event.preventDefault();
                event.stopImmediatePropagation();
                exitTour();
            }
        }

        document.addEventListener('click', handleTourButton, true);

        return () => document.removeEventListener('click', handleTourButton, true);
    }, [run, section, tenantSlug]);

    function handleCallback(data) {
        if (data.status === STATUS.SKIPPED || data.action === ACTIONS.CLOSE) {
            exitTour();
            return;
        }

        if (data.type === EVENTS.STEP_AFTER
            && data.action !== ACTIONS.PREV
            && data.index >= steps.length - 1) {
            finishSection();
            return;
        }

        if (data.type === EVENTS.TARGET_NOT_FOUND && data.index >= steps.length - 1) {
            finishSection();
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
                last: finalLabels[section] || 'Continuar',
                next: 'Avançar',
                skip: 'Fechar tour',
            }}
        />
    );
}
