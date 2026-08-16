import { router } from '@inertiajs/react';
import { ACTIONS, EVENTS, Joyride, STATUS } from 'react-joyride';
import { useEffect, useMemo, useRef, useState } from 'react';

const storageKey = 'budgets:tour-section';
const activeStorageKey = 'budgets:tour-active';
const navigationStorageKey = 'budgets:tour-navigating';
const startedAtStorageKey = 'budgets:tour-started-at';
const maxTourAgeMs = 30 * 60 * 1000;
const sections = [
    'insumos',
    'composicoes',
    'composicao-sinapi',
    'composicao-sicro',
    'orcamento-criacao',
    'orcamento-regras',
    'orcamento',
];
const sectionActionLabels = {
    insumos: 'Ir para composições',
    composicoes: 'Ver composição SINAPI',
    'composicao-sinapi': 'Ver composição SICRO',
    'composicao-sicro': 'Criar orçamento',
    'orcamento-criacao': 'Configurar cálculos',
    'orcamento-regras': 'Montar orçamento',
    orcamento: 'Concluir tutorial',
};

const stepsBySection = {
    insumos: [
        {
            target: '[data-tour="budget-inputs-header"]',
            title: 'Etapa 1 de 3: insumos',
            content: 'Comece pela base de preços. Aqui ficam materiais, mão de obra e equipamentos que serão usados nas composições.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="budget-inputs-actions"]',
            title: 'Cadastrar ou importar',
            content: 'Cadastre um insumo próprio ou importe uma base. Cada registro precisa de código, unidade, referência e preço.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="budget-inputs-list"]',
            title: 'Conferir os preços',
            content: 'Use os filtros para localizar o insumo e confira os valores desonerado e não desonerado antes de avançar.',
            placement: 'top',
        },
    ],
    composicoes: [
        {
            target: '[data-tour="budget-compositions-header"]',
            title: 'Etapa 2 de 3: composições',
            content: 'A composição transforma vários insumos em um serviço com unidade e custo calculado.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="budget-compositions-actions"]',
            title: 'Criar a composição',
            content: 'Crie uma composição própria ou importe uma base oficial. Depois, vincule os insumos e informe seus coeficientes.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="budget-compositions-detail"]',
            title: 'Localizar uma composição',
            content: 'Filtre por banco, estado e tipo. Abra uma composição para conferir seus itens e a memória de cálculo.',
            placement: 'top',
        },
    ],
    'composicao-sinapi': [
        {
            target: '[data-tour="budget-sinapi-header"]',
            title: 'Composição SINAPI',
            content: 'No SINAPI, a composição apresenta unidade, referência e custos desonerado e não desonerado por estado.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="budget-sinapi-items"]',
            title: 'Itens analíticos do SINAPI',
            content: 'Cada insumo ou composição auxiliar possui coeficiente e preço unitário. A soma dos totais forma o custo da composição.',
            placement: 'top',
        },
    ],
    'composicao-sicro': [
        {
            target: '[data-tour="budget-sicro-header"]',
            title: 'Composição SICRO',
            content: 'O SICRO organiza a composição por categorias e considera produção da equipe e fator de influência da chuva.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="budget-sicro-categories"]',
            title: 'Categorias do SICRO',
            content: 'Equipamentos, mão de obra, materiais, atividades auxiliares e transportes ficam separados para facilitar a conferência do custo.',
            placement: 'top',
        },
    ],
    'orcamento-criacao': [
        {
            target: '[data-tour="budget-create-header"]',
            title: 'Criar um orçamento',
            content: 'Comece informando código, descrição, cliente, categoria e prazo de entrega.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="budget-create-general"]',
            title: 'Informações gerais',
            content: 'Esses dados identificam o orçamento. Permita preço zerado somente quando o valor for preenchido manualmente depois.',
            placement: 'top',
        },
    ],
    'orcamento-regras': [
        {
            target: '[data-tour="budget-create-calculation"]',
            title: 'Regras de cálculo',
            content: 'Defina arredondamento, encargos sociais, incidência do BDI e percentual antes de montar os itens.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="budget-create-bases"]',
            title: 'Bases de referência',
            content: 'Selecione SINAPI, SICRO ou ambas e escolha a UF e a versão que serão usadas nos preços do orçamento.',
            placement: 'top',
        },
    ],
    orcamento: [
        {
            target: '[data-tour="budget-sheet-header"]',
            title: 'Montagem do orçamento',
            content: 'Com as regras salvas, o orçamento fica pronto para receber etapas, composições e insumos.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="budget-sheet-summary"]',
            title: 'Regras do orçamento',
            content: 'Mantenha visíveis as referências e os parâmetros que serão usados em todos os cálculos.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="budget-sheet-structure"]',
            title: 'Montar a estrutura',
            content: 'Crie etapas e adicione composições ou insumos. Informe as quantidades para formar os valores de cada item.',
            placement: 'top',
        },
        {
            target: '[data-tour="budget-sheet-total"]',
            title: 'Revisar e finalizar',
            content: 'Confira o total sem BDI, o valor do BDI e o total final. Depois, finalize o orçamento e gere os relatórios.',
            placement: 'top',
        },
    ],
};

export function startBudgetTour(tenantSlug) {
    const startedAt = Date.now();

    window.sessionStorage.setItem(activeStorageKey, '1');
    window.sessionStorage.setItem(storageKey, 'insumos');
    window.sessionStorage.setItem(navigationStorageKey, '1');
    window.sessionStorage.setItem(startedAtStorageKey, String(startedAt));

    router.visit(route('tenant.orcamentos.tour-preview', tenantSlug), {
        data: { screen: 'insumos' },
        preserveScroll: false,
        preserveState: false,
        replace: true,
    });
}

export default function BudgetTour({ section, tenantSlug }) {
    const [run, setRun] = useState(false);
    const [dismissed, setDismissed] = useState(false);
    const navigatingRef = useRef(false);
    const steps = useMemo(
        () => (stepsBySection[section] || []).map((step) => ({
            ...step,
            skipBeacon: true,
            spotlightClicks: true,
        })),
        [section],
    );

    function clearStorage() {
        window.sessionStorage.removeItem(activeStorageKey);
        window.sessionStorage.removeItem(storageKey);
        window.sessionStorage.removeItem(navigationStorageKey);
        window.sessionStorage.removeItem(startedAtStorageKey);
    }

    function exitTour() {
        navigatingRef.current = false;
        setDismissed(true);
        setRun(false);
        clearStorage();
        router.visit(route('tenant.orcamentos.index', tenantSlug), { replace: true });
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

        const nextUrl = new URL(route('tenant.orcamentos.tour-preview', tenantSlug), window.location.origin);
        nextUrl.searchParams.set('screen', nextSection);
        window.location.assign(nextUrl.toString());
    }

    useEffect(() => {
        navigatingRef.current = false;

        const activeSection = window.sessionStorage.getItem(storageKey);
        const startedAt = Number(window.sessionStorage.getItem(startedAtStorageKey));
        const isFresh = Number.isFinite(startedAt)
            && startedAt > 0
            && Date.now() - startedAt < maxTourAgeMs;
        const internalNavigation = window.sessionStorage.getItem(navigationStorageKey) === '1';
        const shouldRun = activeSection === section && isFresh && internalNavigation;

        if (shouldRun) {
            setDismissed(false);
            window.sessionStorage.removeItem(navigationStorageKey);
            window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
            const timer = window.setTimeout(() => setRun(true), 180);

            return () => window.clearTimeout(timer);
        }

        if (window.sessionStorage.getItem(activeStorageKey) === '1' && activeSection === section) {
            clearStorage();
        }

        setRun(false);
        return undefined;
    }, [section]);

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

            if (label === 'Fechar tour') {
                event.preventDefault();
                event.stopImmediatePropagation();
                exitTour();
            }
        };

        document.addEventListener('click', handleTourButton, true);
        return () => document.removeEventListener('click', handleTourButton, true);
    }, [run, section]);

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
