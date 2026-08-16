import { ACTIONS, Joyride, STATUS } from 'react-joyride';
import { Plane } from 'lucide-react';
import { useEffect, useState } from 'react';

const pageSteps = [
    {
        target: '[data-tour="overview-header"]',
        title: 'Visão geral',
        content: 'Esta tela reúne os principais números, pendências e movimentações do tenant em um único lugar.',
        placement: 'bottom',
    },
    {
        target: '[data-tour="overview-metrics"]',
        title: 'Resumo das pendências',
        content: 'Veja primeiro atividades vencidas ou próximas do prazo, projetos em fluxo e RNCs abertas.',
        placement: 'bottom',
    },
    {
        target: '[data-tour="overview-operation"]',
        title: 'Operação do contrato',
        content: 'Estes atalhos mostram pendências de Documentação, Diário de Obra, Medição e Ordem de Serviço.',
        placement: 'top',
    },
    {
        target: '[data-tour="overview-monitoring"]',
        title: 'Acompanhamento',
        content: 'Filtre pontos de atenção, acompanhe suas atividades e consulte o histórico recente do workspace.',
        placement: 'top',
    },
    {
        target: '[data-tour="overview-indicators"]',
        title: 'Indicadores do workspace',
        content: 'Os gráficos mostram como atividades, projetos e RNCs estão distribuídos por etapa e categoria.',
        placement: 'top',
    },
];

const desktopMenuSteps = [
    {
        target: '[data-tour="overview-nav-management"]',
        title: 'Gestão',
        content: 'Visão geral consolida o tenant, Contratos organiza vínculos e aditivos, e Atividades acompanha tarefas e prazos.',
        placement: 'right',
    },
    {
        target: '[data-tour="overview-nav-planning"]',
        title: 'Programação',
        content: 'Planejamento organiza a programação da execução, enquanto Orçamentos estrutura custos, insumos e composições.',
        placement: 'right',
    },
    {
        target: '[data-tour="overview-nav-execution"]',
        title: 'Acompanhamento',
        content: 'Medição controla boletins e pleitos; Ordem de Serviço formaliza itens, solicitações, análises e aprovações.',
        placement: 'right',
    },
    {
        target: '[data-tour="overview-nav-field"]',
        title: 'Campo',
        content: 'Diário de Obra registra RDA e RDO da execução; Qualidade concentra RNCs, evidências e ações corretivas.',
        placement: 'right',
    },
    {
        target: '[data-tour="overview-nav-control"]',
        title: 'Controle',
        content: 'Documentação gerencia o acervo e e-mails, enquanto Projetos controla submissões, revisões, análises e visualizações.',
        placement: 'right',
    },
    {
        target: '[data-tour="overview-nav-help"]',
        title: 'Ajuda',
        content: 'Tutoriais reúne orientações e materiais de apoio para o uso do sistema.',
        placement: 'right',
    },
    {
        target: '[data-tour="overview-nav-administration"]',
        title: 'Administração',
        content: 'Usuários define quem participa do tenant e Permissões controla o acesso de cada perfil aos módulos.',
        placement: 'right',
    },
    {
        target: '[data-tour="overview-nav-settings"]',
        title: 'Parametrização',
        content: 'Cadastre empresas, obras, disciplinas, categorias e responsáveis usados pelos demais módulos.',
        placement: 'right',
    },
];

const mobileMenuStep = {
    target: '[data-tour="overview-mobile-menu"]',
    title: 'Módulos do sistema',
    content: 'No Menu ficam Gestão, Programação, Acompanhamento, Campo, Controle, Ajuda, Administração e Parametrização. Cada grupo reúne os módulos relacionados à sua operação.',
    placement: 'bottom',
};

export default function OverviewTour() {
    const [run, setRun] = useState(false);
    const [steps, setSteps] = useState([]);

    function startTour() {
        const collapsedToggle = document.querySelector('[aria-label="Mostrar menu lateral"]');
        collapsedToggle?.click();

        window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
        setRun(false);

        window.setTimeout(() => {
            const menuSteps = window.matchMedia('(min-width: 1024px)').matches
                ? desktopMenuSteps
                : [mobileMenuStep];
            const availableSteps = [...pageSteps, ...menuSteps]
                .filter((step) => {
                    const target = document.querySelector(step.target);
                    if (!target) return false;

                    const rect = target.getBoundingClientRect();
                    return rect.width > 0 && rect.height > 0;
                })
                .map((step) => ({ ...step, skipBeacon: true }));

            setSteps(availableSteps);
            setRun(availableSteps.length > 0);
        }, 120);
    }

    function stopTour() {
        const resetScroll = () => {
            window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
            document.documentElement.scrollTop = 0;
            document.body.scrollTop = 0;
            document.querySelector('.sig-side nav')?.scrollTo({ top: 0, left: 0, behavior: 'auto' });
        };

        resetScroll();
        setRun(false);
        [50, 150, 300].forEach((delay) => window.setTimeout(resetScroll, delay));
    }

    function handleCallback(data) {
        if ([STATUS.FINISHED, STATUS.SKIPPED].includes(data.status) || data.action === ACTIONS.CLOSE) {
            stopTour();
        }
    }

    useEffect(() => {
        if (!run) return undefined;

        const stopOnExit = (event) => {
            const button = event.target.closest('button');
            const isCloseButton = button?.matches('[aria-label="Fechar tour"]');
            const isFinishButton = button?.textContent.trim() === 'Terminar tour';
            if (!isCloseButton && !isFinishButton) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            stopTour();
        };

        document.addEventListener('click', stopOnExit, true);

        return () => document.removeEventListener('click', stopOnExit, true);
    }, [run]);

    return (
        <>
            <button type="button" className="sig-btn sig-btn-secondary" onClick={startTour}>
                <Plane size={15} />
                Iniciar tour
            </button>
            <Joyride
                continuous
                disableOverlayClose
                callback={handleCallback}
                run={run}
                scrollOffset={20}
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
                    last: 'Terminar tour',
                    next: 'Avançar',
                    skip: 'Fechar tour',
                }}
            />
        </>
    );
}
