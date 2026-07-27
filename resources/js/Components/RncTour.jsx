import { router } from '@inertiajs/react';
import { ACTIONS, EVENTS, Joyride, STATUS } from 'react-joyride';
import { useEffect, useMemo, useRef, useState } from 'react';

const storageKey = 'rnc:tour-section';
const activeStorageKey = 'rnc:tour-active';
const navigationStorageKey = 'rnc:tour-navigating';
const startedAtStorageKey = 'rnc:tour-started-at';
const maxTourAgeMs = 30 * 60 * 1000;
const sections = ['responsibles', 'create', 'notify', 'corrective-action', 'review', 'evidence', 'final-pdf', 'dashboard'];
const finalLabels = {
    responsibles: 'Continuar para abertura',
    create: 'Continuar para notificação',
    notify: 'Continuar para ação corretiva',
    'corrective-action': 'Continuar para análise',
    review: 'Continuar para evidências',
    evidence: 'Ver PDF final',
    'final-pdf': 'Ver dashboard',
    dashboard: 'Concluir tutorial',
};

const stepsBySection = {
    responsibles: [
        {
            target: '[data-tour="rnc-responsibles"]',
            title: 'Etapa 1: alocar responsáveis',
            content: 'Antes de abrir uma RNC, defina as pessoas que participarão do fluxo em cada contrato.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rnc-responsibility-types"]',
            title: 'Papéis no processo',
            content: 'O operacional abre, notifica, analisa e evidencia. A construtora envia a ação corretiva. O acompanhamento consulta e recebe alertas.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rnc-responsibles-form"]',
            title: 'Criar o vínculo',
            content: 'Escolha contrato, responsabilidade e usuário. Os vínculos ativos determinam as permissões, notificações e e-mails de cada pessoa.',
            placement: 'right',
        },
    ],
    create: [
        {
            target: '[data-tour="rnc-create-header"]',
            title: 'Etapa 2: abrir a RNC',
            content: 'A abertura registra a não conformidade dentro de uma obra e do contrato correspondente.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rnc-create-classification"]',
            title: 'Classificar e localizar',
            content: 'Informe projetos vinculados, disciplina, gravidade, local e coordenadas para contextualizar a ocorrência.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rnc-create-description"]',
            title: 'Documentar o problema',
            content: 'Descreva a ocorrência, indique a ação recomendada e anexe fotografias. Depois de salvar, confira os dados antes de notificar.',
            placement: 'top',
        },
    ],
    notify: [
        {
            target: '[data-tour="rnc-notify-row"]',
            title: 'Etapa 3: notificar',
            content: 'A RNC recém-aberta aparece na listagem com o botão Notificar.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rnc-notify-action"]',
            title: 'Enviar a comunicação',
            content: 'A notificação libera a resposta e avisa os responsáveis vinculados por notificação interna e e-mail.',
            placement: 'left',
        },
    ],
    'corrective-action': [
        {
            target: '[data-tour="rnc-corrective-summary"]',
            title: 'Etapa 4: ação corretiva',
            content: 'O responsável da construtora consulta o problema, as recomendações e as imagens antes de responder.',
            placement: 'right',
        },
        {
            target: '[data-tour="rnc-corrective-form"]',
            title: 'Enviar a proposta',
            content: 'A proposta informa como a correção será executada, o prazo previsto e o arquivo zipado de apoio.',
            placement: 'left',
        },
        {
            target: '[data-tour="rnc-corrective-history"]',
            title: 'Histórico preservado',
            content: 'Cada envio permanece no histórico. Se uma proposta for reprovada, a construtora pode enviar uma nova versão.',
            placement: 'left',
        },
    ],
    review: [
        {
            target: '[data-tour="rnc-review-proposal"]',
            title: 'Etapa 5: analisar',
            content: 'O responsável operacional confere a proposta, o prazo e o arquivo enviados pela construtora.',
            placement: 'right',
        },
        {
            target: '[data-tour="rnc-review-decision"]',
            title: 'Emitir o parecer',
            content: 'Aprovar libera a execução e as evidências. Reprovar exige justificativa e devolve o fluxo para uma nova proposta.',
            placement: 'left',
        },
    ],
    evidence: [
        {
            target: '[data-tour="rnc-evidence-approved"]',
            title: 'Etapa 6: comprovar a correção',
            content: 'Depois da aprovação, o operacional acompanha o que foi autorizado e o prazo assumido.',
            placement: 'right',
        },
        {
            target: '[data-tour="rnc-evidence-photos"]',
            title: 'Registrar evidências',
            content: 'Adicione fotografias, organize a ordem e descreva cada imagem para demonstrar a correção executada.',
            placement: 'left',
        },
        {
            target: '[data-tour="rnc-evidence-finish"]',
            title: 'Finalizar a RNC',
            content: 'O arquivo zipado complementa o registro. Ao enviar as evidências, a RNC é finalizada e a rastreabilidade fica completa.',
            placement: 'left',
        },
    ],
    'final-pdf': [
        {
            target: 'body',
            title: 'Etapa 7: PDF final',
            content: 'O PDF reúne identificação, projetos, descrição, ação corretiva, parecer, evidências e as datas do fluxo.',
            placement: 'center',
        },
        {
            target: '[data-tour="rnc-final-pdf-actions"]',
            title: 'Consultar e baixar',
            content: 'O documento final pode ser visualizado ou baixado pela listagem e pela tela de detalhes.',
            placement: 'bottom',
        },
    ],
    dashboard: [
        {
            target: '[data-tour="rnc-dashboard-metrics"]',
            title: 'Etapa 8: acompanhar',
            content: 'O dashboard resume o total, atrasos de resposta e execução, propostas em análise e RNCs finalizadas.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="rnc-dashboard-analysis"]',
            title: 'Visão gerencial',
            content: 'Use os gráficos, as RNCs recentes e as listas de atraso para priorizar o acompanhamento da qualidade.',
            placement: 'top',
        },
    ],
};

export function startRncTour(tenantSlug) {
    const startedAt = Date.now();

    window.sessionStorage.setItem(activeStorageKey, '1');
    window.sessionStorage.setItem(storageKey, 'responsibles');
    window.sessionStorage.setItem(navigationStorageKey, '1');
    window.sessionStorage.setItem(startedAtStorageKey, String(startedAt));

    router.visit(route('tenant.qualidade.rnc.tour-preview', tenantSlug), {
        data: { screen: 'responsibles' },
        preserveScroll: false,
        preserveState: false,
        replace: true,
    });
}

export default function RncTour({ section, tenantSlug }) {
    const [run, setRun] = useState(false);
    const [dismissed, setDismissed] = useState(false);
    const navigatingRef = useRef(false);
    const steps = useMemo(
        () => (stepsBySection[section] || []).map((step) => ({ ...step, skipBeacon: true, spotlightClicks: true })),
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
            router.visit(route('tenant.qualidade.rnc.index', tenantSlug), { replace: true });
            return;
        }

        navigatingRef.current = true;
        window.sessionStorage.setItem(activeStorageKey, '1');
        window.sessionStorage.setItem(storageKey, nextSection);
        window.sessionStorage.setItem(navigationStorageKey, '1');

        const nextUrl = new URL(route('tenant.qualidade.rnc.tour-preview', tenantSlug), window.location.origin);
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
                router.visit(route('tenant.qualidade.rnc.index', tenantSlug), { replace: true });
            }
        }

        document.addEventListener('click', handleTourButton, true);

        return () => document.removeEventListener('click', handleTourButton, true);
    }, [run, section, tenantSlug]);

    function handleCallback(data) {
        if (data.status === STATUS.SKIPPED || data.action === ACTIONS.CLOSE) {
            clearTour();
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
