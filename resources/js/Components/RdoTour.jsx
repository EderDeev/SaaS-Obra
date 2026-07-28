import { router } from '@inertiajs/react';
import { ACTIONS, EVENTS, Joyride, STATUS } from 'react-joyride';
import { useEffect, useMemo, useRef, useState } from 'react';

const storageKey = 'diario-obra:tour-section';
const activeStorageKey = 'diario-obra:tour-active';
const navigationStorageKey = 'diario-obra:tour-navigating';
const startedAtStorageKey = 'diario-obra:tour-started-at';
const maxTourAgeMs = 30 * 60 * 1000;

const sections = [
    'settings',
    'catalogs',
    'responsibles',
    'calendar',
    'rda',
    'consolidation',
    'approval',
    'signature',
    'dashboard',
];

const finalLabels = {
    settings: 'Continuar para cadastros',
    catalogs: 'Continuar para responsáveis',
    responsibles: 'Continuar para calendário',
    calendar: 'Continuar para RDA',
    rda: 'Continuar para consolidação',
    consolidation: 'Continuar para aprovação',
    approval: 'Continuar para assinatura',
    signature: 'Continuar para dashboard',
    dashboard: 'Concluir tutorial',
};

const stepsBySection = {
    settings: [
        {
            target: '[data-tour="rdo-settings-scope"]',
            title: 'Etapa 1: parametrizar o RDO',
            content: 'Escolha o contrato, as obras ou frentes, os dias de geração e o prazo disponível para cada diário.',
            placement: 'center',
        },
        {
            target: '[data-tour="rdo-settings-rules"]',
            title: 'Regras do processo',
            content: 'Defina continuidade, geração automática e assinatura digital. Essa configuração também orienta o calendário do RDA.',
            placement: 'top',
        },
    ],
    catalogs: [
        {
            target: '[data-tour="rdo-catalogs-tabs"]',
            title: 'Etapa 2: preparar os cadastros',
            content: 'Cadastre previamente mão de obra, equipamentos e subcontratadas para acelerar os apontamentos diários.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rdo-catalogs-list"]',
            title: 'Catálogos reutilizáveis',
            content: 'Os itens ficam disponíveis no RDA e no RDO, evitando digitação repetida e mantendo a nomenclatura padronizada.',
            placement: 'top',
        },
    ],
    responsibles: [
        {
            target: '[data-tour="rdo-responsibles-form"]',
            title: 'Etapa 3: definir responsáveis',
            content: 'Vincule quem preenche pela construtora, quem aprova pela gerenciadora e pelo cliente e quem assina o documento.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rdo-responsibles-flow"]',
            title: 'Responsável pelo RDA',
            content: 'Defina também quem pode registrar e publicar o RDA de campo que será usado como apoio ao RDO oficial.',
            placement: 'top',
        },
    ],
    calendar: [
        {
            target: '[data-tour="rdo-calendar-overview"]',
            title: 'Etapa 4: gerar o diário',
            content: 'O calendário mostra os RDOs previstos e seus estados. A geração pode ser automática ou iniciada no dia habilitado.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rdo-calendar-day"]',
            title: 'Abrir o RDO do dia',
            content: 'Selecione a data para criar um RDO vazio ou copiar o anterior. Depois, abra o registro para iniciar o preenchimento.',
            placement: 'left',
        },
    ],
    rda: [
        {
            target: '[data-tour="rda-mobile-offline"]',
            title: 'Etapa 5: apontar pelo aplicativo',
            content: 'No aplicativo mobile, o responsável pode preencher o RDA offline no campo. Quando a conexão voltar, os dados são sincronizados.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rda-fields"]',
            title: 'Registrar o trabalho de campo',
            content: 'Informe clima, atividades, ocorrências, mão de obra, equipamentos e fotografias da obra ou frente.',
            placement: 'center',
        },
        {
            target: '[data-tour="rda-publish"]',
            title: 'Publicar o RDA',
            content: 'Salve durante o preenchimento e publique ao concluir. Somente RDAs publicados ficam disponíveis para consolidação no RDO.',
            placement: 'left',
        },
    ],
    consolidation: [
        {
            target: '[data-tour="rdo-rda-import"]',
            title: 'Etapa 6: consolidar os apontamentos',
            content: 'Abra o RDO e importe os dados dos RDAs publicados da mesma data e frente de serviço.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rdo-sections"]',
            title: 'Conferir e completar o RDO',
            content: 'Revise as seis seções do documento, complemente o que for necessário e confira todas as frentes antes do envio.',
            placement: 'top',
        },
    ],
    approval: [
        {
            target: '[data-tour="rdo-workflow"]',
            title: 'Etapa 7: análise e aprovação',
            content: 'O RDO segue da construtora para a aprovação conjunta da gerenciadora e do cliente, com histórico por frente.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rdo-workflow-actions"]',
            title: 'Registrar a decisão',
            content: 'Aprovar faz o processo avançar. Devolver ou aprovar com ressalvas exige o parecer para manter a rastreabilidade.',
            placement: 'top',
        },
    ],
    signature: [
        {
            target: '[data-tour="rdo-pdf"]',
            title: 'Etapa 8: gerar o PDF',
            content: 'Após a aprovação, o sistema reúne identificação, condições de campo, recursos, atividades, fotos e pareceres no PDF do RDO.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rdo-digital-signature"]',
            title: 'Assinatura digital',
            content: 'Envie o PDF para assinatura digital da construtora, gerenciadora e cliente. O sistema acompanha cada assinatura e guarda o arquivo final assinado.',
            placement: 'top',
        },
    ],
    dashboard: [
        {
            target: '[data-tour="rdo-dashboard-metrics"]',
            title: 'Etapa 9: acompanhar os RDOs',
            content: 'O dashboard resume RDOs gerados, enviados, aprovados, retornados e o preenchimento médio do período.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rdo-dashboard-charts"]',
            title: 'Visão gerencial',
            content: 'Use os gráficos e registros recentes para acompanhar a evolução diária, os status e o andamento por frente de serviço.',
            placement: 'top',
        },
    ],
};

export function startRdoTour(tenantSlug) {
    const startedAt = Date.now();

    window.sessionStorage.setItem(activeStorageKey, '1');
    window.sessionStorage.setItem(storageKey, 'settings');
    window.sessionStorage.setItem(navigationStorageKey, '1');
    window.sessionStorage.setItem(startedAtStorageKey, String(startedAt));

    router.visit(route('tenant.diario-obra.tour-preview', tenantSlug), {
        data: { screen: 'settings' },
        preserveScroll: false,
        preserveState: false,
        replace: true,
    });
}

export default function RdoTour({ section, tenantSlug }) {
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

    function clearTour() {
        navigatingRef.current = false;
        setDismissed(true);
        window.sessionStorage.removeItem(activeStorageKey);
        window.sessionStorage.removeItem(storageKey);
        window.sessionStorage.removeItem(navigationStorageKey);
        window.sessionStorage.removeItem(startedAtStorageKey);
        setRun(false);
    }

    function finishSection() {
        if (navigatingRef.current) return;

        const currentIndex = sections.indexOf(section);
        const nextSection = currentIndex >= 0 ? sections[currentIndex + 1] : null;

        if (!nextSection) {
            clearTour();
            router.visit(route('tenant.diario-obra.rdo.calendar', tenantSlug), { replace: true });
            return;
        }

        navigatingRef.current = true;
        window.sessionStorage.setItem(activeStorageKey, '1');
        window.sessionStorage.setItem(storageKey, nextSection);
        window.sessionStorage.setItem(navigationStorageKey, '1');

        const nextUrl = new URL(route('tenant.diario-obra.tour-preview', tenantSlug), window.location.origin);
        nextUrl.searchParams.set('screen', nextSection);
        window.location.assign(nextUrl.toString());
    }

    useEffect(() => {
        navigatingRef.current = false;
    }, [section]);

    useEffect(() => {
        const activeSection = window.sessionStorage.getItem(storageKey);
        const startedAt = Number(window.sessionStorage.getItem(startedAtStorageKey));
        const isFresh = Number.isFinite(startedAt) && startedAt > 0 && Date.now() - startedAt < maxTourAgeMs;
        const internalNavigation = window.sessionStorage.getItem(navigationStorageKey) === '1';

        if (activeSection !== section || !isFresh || !internalNavigation) {
            setRun(false);
            return undefined;
        }

        window.sessionStorage.removeItem(navigationStorageKey);
        window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
        const timer = window.setTimeout(() => setRun(true), 220);

        return () => window.clearTimeout(timer);
    }, [section, steps]);

    useEffect(() => {
        if (!run) return undefined;

        function handleTourButton(event) {
            const button = event.target.closest('button');
            if (!button) return;

            const label = button.getAttribute('aria-label') || button.textContent?.trim();

            if (label === finalLabels[section]) {
                event.preventDefault();
                event.stopImmediatePropagation();
                finishSection();
                return;
            }

            if (label === 'Fechar tour') {
                event.preventDefault();
                event.stopImmediatePropagation();
                clearTour();
                router.visit(route('tenant.diario-obra.rdo.calendar', tenantSlug), { replace: true });
            }
        }

        document.addEventListener('click', handleTourButton, true);
        return () => document.removeEventListener('click', handleTourButton, true);
    }, [run, section, tenantSlug]);

    function handleCallback(data) {
        if (data.status === STATUS.SKIPPED || data.action === ACTIONS.CLOSE) {
            clearTour();
            router.visit(route('tenant.diario-obra.rdo.calendar', tenantSlug), { replace: true });
            return;
        }

        if (data.type === EVENTS.STEP_AFTER && data.action !== ACTIONS.PREV && data.index >= steps.length - 1) {
            finishSection();
            return;
        }

        if (data.type === EVENTS.TARGET_NOT_FOUND && data.index >= steps.length - 1) {
            finishSection();
            return;
        }

        if ((data.status === STATUS.FINISHED || data.type === EVENTS.TOUR_END) && !navigatingRef.current) {
            finishSection();
        }
    }

    if (!steps.length || dismissed) return null;

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
