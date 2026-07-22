import { router } from '@inertiajs/react';
import { ACTIONS, EVENTS, Joyride, STATUS } from 'react-joyride';
import { useEffect, useMemo, useRef, useState } from 'react';

const storageKey = 'ged:tour-section';
const activeStorageKey = 'ged:tour-active';
const stepStorageKey = 'ged:tour-step';
const navigationStorageKey = 'ged:tour-navigating';
const startedAtStorageKey = 'ged:tour-started-at';
const maxTourAgeMs = 30 * 60 * 1000;
const pinnedDocumentTourTitles = new Set([
    'Submenus do documento',
    'Detalhes',
    'Conteudo',
    'Anexos',
    'Notas',
    'Permissoes',
]);

const sectionOrder = ['documents', 'triage', 'document', 'trash', 'email', 'settings'];

const sectionRoutes = {
    documents: 'tenant.ged.index',
    triage: 'tenant.ged.triage',
    trash: 'tenant.ged.trash',
    email: 'tenant.ged.email',
    settings: 'tenant.ged.settings',
};

const stepsBySection = {
    documents: [
        {
            target: '[data-tour="ged-documents-overview"]',
            title: 'Documentacao',
            content: 'Aqui fica o acervo do GED. A tela concentra envio, filtros, visualizacao, selecao em massa e acesso aos arquivos processados.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-upload"]',
            title: 'Enviar documento',
            content: 'Use este botao para cadastrar arquivos manualmente. O documento pode receber contrato, tipo, correspondente, etiquetas e depois entra no fluxo de OCR/indexacao.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-settings-link"]',
            title: 'Parametrizacao',
            content: 'A parametrizacao define os tipos documentais e etiquetas usados para classificar os documentos por contrato.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-triage-link"]',
            title: 'Triagem de e-mails',
            content: 'A triagem aparece quando a regra processa somente anexos e o e-mail chega com mais de um PDF. Assim voce escolhe qual PDF sera o documento principal.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-trash-link"]',
            title: 'Lixeira',
            content: 'Documentos excluidos vao para a lixeira antes da remocao definitiva. Ali voce pode restaurar itens ou excluir permanentemente.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-filters"]',
            title: 'Filtros e visualizacao',
            content: 'Pesquise por texto, filtre por contrato, etiqueta, correspondente, tipo e periodo. Tambem alterne entre tabela, grade e visao detalhada.',
            placement: 'top',
        },
        {
            target: '[data-tour="ged-document-list"]',
            title: 'Lista de documentos',
            content: 'Os documentos aparecem aqui. Voce pode abrir detalhes, visualizar previa, baixar arquivos e selecionar itens para acoes em massa.',
            placement: 'top',
        },
        {
            target: '[data-tour="ged-open-document"]',
            title: 'Abrir documento',
            content: 'Clique em Abrir para entrar no documento. A proxima parte do tour mostra detalhes, conteudo, notas e permissoes.',
            placement: 'top',
        },
    ],
    document: [
        {
            target: '[data-tour="ged-document-header"]',
            title: 'Documento aberto',
            content: 'Esta e a area do documento selecionado. Aqui voce consulta o arquivo e administra as informacoes do GED.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-document-tabs"]',
            title: 'Submenus do documento',
            content: 'Use essas abas para alternar entre detalhes, conteudo OCR, anexos, notas, historico e permissoes.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-document-tab-details"]',
            title: 'Detalhes',
            content: 'Em Detalhes ficam os metadados principais: contrato, tipo, correspondente, etiquetas, data e descricao.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-document-tab-content"]',
            title: 'Conteudo',
            content: 'Em Conteudo voce acompanha o OCR e consulta o texto extraido do documento.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-document-tab-attachments"]',
            title: 'Anexos',
            content: 'Em Anexos voce vincula arquivos de apoio ao documento principal, como ZIP, planilhas, videos, imagens ou PDFs complementares. PDFs anexados tambem podem passar por OCR e serem visualizados sem virar um novo documento do GED.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-attachments-upload"]',
            title: 'Enviar varios anexos',
            content: 'Selecione um ou varios arquivos de uma vez. Eles ficam ligados ao documento principal sem entrar na numeracao do GED. Quando o anexo for PDF, o sistema envia para OCR automaticamente.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-attachments-list"]',
            title: 'Lista de anexos',
            content: 'A lista mostra os arquivos vinculados. Cada anexo pode ser baixado, editado para ajustar titulo e observacao, ou excluido. Em PDFs, use Abrir para ver o arquivo e o texto extraido pelo OCR.',
            placement: 'top',
        },
        {
            target: '[data-tour="ged-document-tab-notes"]',
            title: 'Notas',
            content: 'Em Notas ficam observacoes internas, comentarios e registros de acompanhamento do documento.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-document-tab-permissions"]',
            title: 'Permissoes',
            content: 'Em Permissoes voce controla proprietario, usuarios e empresas que podem visualizar ou editar o documento.',
            placement: 'bottom',
        },
    ],
    triage: [
        {
            target: '[data-tour="ged-triage-overview"]',
            title: 'Triagem',
            content: 'A Triagem recebe e-mails com mais de um PDF quando a regra esta configurada para processar somente anexos. Se a regra processar e-mail e anexos, o e-mail convertido em PDF ja vira o documento principal e nao precisa de triagem.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-triage-list"]',
            title: 'Pendencias',
            content: 'Cada pendencia representa um e-mail aguardando escolha. Se nao houver pendencias, a tela fica vazia e pronta para o proximo processamento.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-triage-pdf-choice"]',
            title: 'PDF principal',
            content: 'Marque o PDF que deve entrar como documento principal do GED. Os demais PDFs e outros arquivos do mesmo e-mail ficam vinculados na aba Anexos desse documento.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-triage-attachments"]',
            title: 'Arquivos anexos',
            content: 'Aqui aparecem os arquivos que serao guardados na aba Anexos do documento principal depois da resolucao.',
            placement: 'left',
        },
        {
            target: '[data-tour="ged-triage-resolve"]',
            title: 'Resolver triagem',
            content: 'Ao resolver, o sistema importa o PDF escolhido, envia o documento principal para OCR e registra os outros arquivos como anexos. PDFs anexos tambem podem receber OCR na aba Anexos.',
            placement: 'top',
        },
    ],
    trash: [
        {
            target: '[data-tour="ged-trash-overview"]',
            title: 'Lixeira da Documentacao',
            content: 'Aqui ficam os documentos movidos para a lixeira. Eles continuam disponiveis para restauracao antes da exclusao definitiva.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-trash-actions"]',
            title: 'Acoes da lixeira',
            content: 'Selecione documentos para restaurar, excluir definitivamente ou esvaziar toda a lixeira quando necessario.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-trash-list"]',
            title: 'Itens excluidos',
            content: 'A tabela mostra o documento, o prazo restante e as acoes individuais para restaurar ou excluir de vez.',
            placement: 'top',
        },
        {
            target: '[data-tour="ged-trash-item"]',
            title: 'Documento na lixeira',
            content: 'Cada item conserva o titulo, o arquivo de origem, o contrato e o tipo documental. O prazo restante indica quanto tempo ainda ha para restaurar o documento antes da exclusao definitiva.',
            placement: 'top',
        },
    ],
    email: [
        {
            target: '[data-tour="ged-email-overview"]',
            title: 'E-mail da Documentacao',
            content: 'Este submenu importa documentos recebidos por e-mail. A ideia e transformar anexos e mensagens em registros do GED.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-email-accounts"]',
            title: 'Contas IMAP',
            content: 'Cadastre as contas que o sistema deve consultar. Cada conta fica vinculada ao contrato e pode ser testada antes de salvar.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-email-account-example"]',
            title: 'Conta IMAP do contrato',
            content: 'A conta mostra os dados usados na leitura: nome, servidor IMAP e usuario. Voce tambem define contrato, seguranca, porta, caixa de entrada e o que fazer apos processar a mensagem.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-email-rules"]',
            title: 'Regras de e-mail',
            content: 'As regras dizem o que consumir. Em "Processar somente anexos", um unico PDF vira documento principal; se houver mais de um PDF, entra em triagem. Em "Processar e-mail e anexos", o e-mail e convertido em PDF e vira o documento principal, enquanto todos os arquivos recebidos ficam como anexos.',
            placement: 'top',
        },
        {
            target: '[data-tour="ged-email-rule-example"]',
            title: 'Regra de recebimento',
            content: 'A regra mostra a vinculacao com a conta, a prioridade, o estado e o historico de mensagens processadas. Nela voce filtra remetente, destinatario, assunto, corpo e nome dos anexos, alem de escolher o escopo de consumo.',
            placement: 'top',
        },
    ],
    settings: [
        {
            target: '[data-tour="ged-settings-overview"]',
            title: 'Parametrizacao da Documentacao',
            content: 'Aqui voce prepara a base de classificacao do GED antes de operar: tipos documentais e etiquetas por contrato.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-types"]',
            title: 'Tipos documentais',
            content: 'Tipos documentais padronizam a categoria do arquivo, como contrato, nota fiscal, ART, projeto, relatorio ou termo.',
            placement: 'right',
        },
        {
            target: '[data-tour="ged-type-item"]',
            title: 'Tipo documental cadastrado',
            content: 'Cada tipo fica ligado a um contrato e mostra quantos documentos usam essa classificacao. Os botoes ao lado permitem editar ou excluir quando nao houver documentos vinculados.',
            placement: 'right',
        },
        {
            target: '[data-tour="ged-tags"]',
            title: 'Etiquetas',
            content: 'Etiquetas ajudam a marcar tema, prioridade, status ou etapa. Elas tambem podem ser aplicadas por regras de e-mail.',
            placement: 'left',
        },
        {
            target: '[data-tour="ged-tag-item"]',
            title: 'Etiqueta cadastrada',
            content: 'A etiqueta exibe sua cor, o contrato e a quantidade de documentos associados. Ela pode ser editada ou excluida quando nao estiver em uso.',
            placement: 'left',
        },
    ],
};

export function startGedTour(tenantSlug, section = 'documents') {
    window.sessionStorage.setItem(activeStorageKey, '1');
    window.sessionStorage.setItem(storageKey, section);
    window.sessionStorage.setItem(stepStorageKey, '0');
    window.sessionStorage.setItem(navigationStorageKey, '1');
    window.sessionStorage.setItem(startedAtStorageKey, String(Date.now()));

    router.visit(route(sectionRoutes[section], tenantSlug), {
        data: { tour: section, tour_started_at: Date.now() },
        preserveScroll: false,
        preserveState: false,
        replace: true,
    });
}

const documentStepSections = {
    2: 'details',
    3: 'content',
    4: 'attachments',
    7: 'notes',
    8: 'permissions',
};

export default function GedTour({ tenant, section, documentTourUrls = {}, activeDocumentSection = null, onDocumentSectionChange = null, onExit = null }) {
    const initialTourStep = typeof window === 'undefined'
        ? 0
        : Number(new URLSearchParams(window.location.search).get('tour_step') || 0);
    const [run, setRun] = useState(false);
    const navigatingRef = useRef(false);
    const steps = useMemo(
        () => (stepsBySection[section] || []).map((step) => ({ ...step, skipBeacon: true, spotlightClicks: true })),
        [section]
    );
    const tourStepOffset = section === 'document'
        ? Math.max(0, Math.min(initialTourStep, Math.max(steps.length - 1, 0)))
        : 0;
    const joyrideSteps = useMemo(
        () => (tourStepOffset > 0 ? steps.slice(tourStepOffset) : steps),
        [steps, tourStepOffset]
    );

    function showTourStep() {
        setRun(true);
    }

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const params = new URLSearchParams(window.location.search);
        const tourSection = params.get('tour');
        const activeSection = tourSection || window.sessionStorage.getItem(storageKey);
        const startedAtFromUrl = Number(params.get('tour_started_at'));

        if (tourSection && Number.isFinite(startedAtFromUrl) && startedAtFromUrl > 0) {
            window.sessionStorage.setItem(startedAtStorageKey, String(startedAtFromUrl));
        }

        const startedAt = Number(window.sessionStorage.getItem(startedAtStorageKey));
        const isFreshTour = Number.isFinite(startedAt) && startedAt > 0 && Date.now() - startedAt < maxTourAgeMs;
        const isInternalNavigation = window.sessionStorage.getItem(navigationStorageKey) === '1';
        const shouldRun = activeSection === section && isFreshTour && (Boolean(tourSection) || isInternalNavigation);

        if (shouldRun) {
            navigatingRef.current = false;
            window.sessionStorage.removeItem(navigationStorageKey);
            const storedStep = Number(params.get('tour_step') || window.sessionStorage.getItem(stepStorageKey) || 0);
            window.sessionStorage.setItem(activeStorageKey, '1');
            window.sessionStorage.setItem(storageKey, section);
            window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
            window.setTimeout(() => window.scrollTo({ top: 0, left: 0, behavior: 'auto' }), 120);
            showTourStep();
            return;
        }

        if (window.sessionStorage.getItem(activeStorageKey) === '1' && activeSection === section) {
            clearTour();
        }

        setRun(false);
    }, [section, steps]);

    useEffect(() => {
        if (!run || section !== 'documents') {
            return undefined;
        }

        const openDocumentFromTour = (event) => {
            const nextButton = event.target.closest('[aria-label="Avançar"], [aria-label="Continuar"]');
            const title = document.querySelector('[role="alertdialog"] h4')?.textContent?.trim();

            if (!nextButton || title !== 'Abrir documento') {
                return;
            }

            const documentLink = document.querySelector('[data-tour="ged-open-document"]');

            if (!documentLink?.href) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            window.sessionStorage.setItem(activeStorageKey, '1');
            window.sessionStorage.setItem(storageKey, 'document');
            window.sessionStorage.setItem(stepStorageKey, '0');
            window.sessionStorage.setItem(navigationStorageKey, '1');
            window.location.assign(documentLink.href);
        };

        document.addEventListener('click', openDocumentFromTour, true);

        return () => document.removeEventListener('click', openDocumentFromTour, true);
    }, [run, section]);

    useEffect(() => {
        if (!run || section === 'documents' || section === 'document') {
            return undefined;
        }

        const continueSectionTour = (event) => {
            const primaryButton = event.target.closest('[data-action="primary"]');
            const title = document.querySelector('[role="alertdialog"] h4')?.textContent?.trim();
            const lastStepTitle = steps[steps.length - 1]?.title;

            if (!primaryButton || title !== lastStepTitle) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            goToNextSection();
        };

        document.addEventListener('click', continueSectionTour, true);

        return () => document.removeEventListener('click', continueSectionTour, true);
    }, [run, section, steps]);

    useEffect(() => {
        if (!run) {
            return undefined;
        }

        const cancelOnClose = (event) => {
            if (!event.target.closest('[aria-label="Fechar tour"]')) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            clearTour();
        };

        document.addEventListener('click', cancelOnClose, true);

        return () => document.removeEventListener('click', cancelOnClose, true);
    }, [run]);

    useEffect(() => {
        if (!run || section !== 'document') {
            return undefined;
        }

        const keepTabsVisible = () => {
            const title = document.querySelector('[role="alertdialog"] h4')?.textContent?.trim();

            if (pinnedDocumentTourTitles.has(title) && window.scrollY !== 0) {
                window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
            }
        };

        const timer = window.setInterval(keepTabsVisible, 80);
        window.addEventListener('scroll', keepTabsVisible, { passive: true });

        return () => {
            window.clearInterval(timer);
            window.removeEventListener('scroll', keepTabsVisible);
        };
    }, [run, section]);

    useEffect(() => {
        if (!run || section !== 'document') {
            return undefined;
        }

        const documentTransitions = {
            Detalhes: 3,
            Conteudo: 4,
            'Lista de anexos': 7,
            Notas: 8,
        };

        const changeDocumentTabFromTour = (event) => {
            const nextButton = event.target.closest('[data-action="primary"]');
            const title = document.querySelector('[role="alertdialog"] h4')?.textContent?.trim();
            const nextStepIndex = documentTransitions[title];

            if (nextButton && title === 'Permissoes') {
                event.preventDefault();
                event.stopImmediatePropagation();
                goToNextSection();
                return;
            }

            if (!nextButton || !nextStepIndex) {
                return;
            }

            if (onDocumentSectionChange) {
                const nextDocumentSection = documentStepSections[nextStepIndex];

                if (nextDocumentSection) {
                    window.sessionStorage.setItem(stepStorageKey, String(nextStepIndex));
                    onDocumentSectionChange(nextDocumentSection);
                }

                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            maybeNavigateDocumentStep(nextStepIndex);
        };

        document.addEventListener('click', changeDocumentTabFromTour, true);

        return () => document.removeEventListener('click', changeDocumentTabFromTour, true);
    }, [run, section, activeDocumentSection, documentTourUrls, onDocumentSectionChange]);

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
        url.searchParams.delete('tour_step');
        window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
        setRun(false);
        onExit?.();
    }

    function setStoredStep(nextStepIndex) {
        const normalized = Math.max(0, Math.min(nextStepIndex, steps.length - 1));

        window.sessionStorage.setItem(stepStorageKey, String(normalized));
        setRun(true);
    }

    function goToNextSection() {
        if (navigatingRef.current) {
            return;
        }

        navigatingRef.current = true;
        const currentIndex = sectionOrder.indexOf(section);
        const nextSection = sectionOrder.slice(currentIndex + 1).find((candidate) => sectionRoutes[candidate]);

        if (!nextSection) {
            clearTour();
            return;
        }

        window.sessionStorage.setItem(activeStorageKey, '1');
        window.sessionStorage.setItem(storageKey, nextSection);
        window.sessionStorage.setItem(stepStorageKey, '0');
        window.sessionStorage.setItem(navigationStorageKey, '1');
        window.location.assign(route(sectionRoutes[nextSection], tenant.slug));
    }

    function maybeNavigateDocumentStep(nextStepIndex) {
        if (section !== 'document') {
            return false;
        }

        const nextDocumentSection = documentStepSections[nextStepIndex];

        if (!nextDocumentSection) {
            setStoredStep(nextStepIndex);
            return false;
        }

        if (onDocumentSectionChange) {
            onDocumentSectionChange(nextDocumentSection);
            return false;
        }

        const nextUrl = documentTourUrls[nextDocumentSection];

        if (!nextUrl || nextDocumentSection === activeDocumentSection) {
            setStoredStep(nextStepIndex);
            return false;
        }

        navigatingRef.current = true;
        window.sessionStorage.setItem(activeStorageKey, '1');
        window.sessionStorage.setItem(storageKey, 'document');
        window.sessionStorage.setItem(stepStorageKey, String(nextStepIndex));
        window.sessionStorage.setItem(navigationStorageKey, '1');
        const tourUrl = new URL(nextUrl, window.location.origin);
        const currentUrl = new URL(window.location.href);
        const startedAt = currentUrl.searchParams.get('tour_started_at') || window.sessionStorage.getItem(startedAtStorageKey) || String(Date.now());

        tourUrl.searchParams.set('tour', 'document');
        tourUrl.searchParams.set('tour_started_at', startedAt);
        tourUrl.searchParams.set('tour_step', String(nextStepIndex));
        window.location.assign(tourUrl.href);

        return true;
    }

    function handleCallback(data) {
        const currentStepIndex = section === 'document'
            ? data.index + tourStepOffset
            : data.index;

        if (data.status === STATUS.SKIPPED || data.action === ACTIONS.CLOSE) {
            clearTour();
            return;
        }

        if (data.type === EVENTS.TARGET_NOT_FOUND) {
            // During a document-tab transition, the next panel has not rendered yet.
            // Ignoring this transient event prevents Joyride from skipping the remaining steps.
            if (navigatingRef.current) {
                return;
            }

            // Joyride can report the missing next target without first emitting
            // STEP_AFTER. In a document, use that index to open the matching tab
            // instead of advancing again and skipping its explanation.
            if (section === 'document' && documentStepSections[currentStepIndex] && maybeNavigateDocumentStep(currentStepIndex)) {
                return;
            }

            const nextStepIndex = currentStepIndex + 1;

            if (nextStepIndex >= steps.length) {
                goToNextSection();
                return;
            }

            if (maybeNavigateDocumentStep(nextStepIndex)) {
                return;
            }

            setStoredStep(nextStepIndex);
            return;
        }

        if (data.type === EVENTS.STEP_AFTER) {
            const direction = data.action === ACTIONS.PREV ? -1 : 1;
            const nextStepIndex = currentStepIndex + direction;

            if (nextStepIndex >= steps.length) {
                goToNextSection();
                return;
            }

            if (nextStepIndex >= 0 && maybeNavigateDocumentStep(nextStepIndex)) {
                return;
            }

            setStoredStep(nextStepIndex);
            return;
        }

        // Joyride emits TOUR_END while Inertia replaces the page for a document tab.
        // STATUS.FINISHED is the reliable signal that the user reached the real last step.
        if (data.status === STATUS.FINISHED && !navigatingRef.current) {
            goToNextSection();
        }
    }

    if (!steps.length) {
        return null;
    }

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
            steps={joyrideSteps}
            styles={{
                options: {
                    arrowColor: '#ffffff',
                    backgroundColor: '#ffffff',
                    overlayColor: 'rgba(15, 23, 42, 0.58)',
                    primaryColor: '#047857',
                    textColor: '#1f2937',
                    zIndex: 10000,
                },
                buttonNext: {
                    borderRadius: 6,
                    fontWeight: 700,
                    padding: '8px 14px',
                },
                buttonBack: {
                    color: '#475569',
                    marginRight: 8,
                },
                buttonSkip: {
                    color: '#64748b',
                },
                beacon: {
                    display: 'none',
                },
                beaconInner: {
                    display: 'none',
                },
                beaconOuter: {
                    display: 'none',
                },
                tooltip: {
                    borderRadius: 10,
                    boxShadow: '0 18px 60px rgba(15, 23, 42, 0.22)',
                },
                tooltipTitle: {
                    fontSize: 16,
                    fontWeight: 800,
                },
            }}
            locale={{
                back: 'Voltar',
                close: 'Fechar tour',
                last: section === 'settings' ? 'Terminar tour' : 'Continuar',
                next: 'Avan\u00e7ar',
                skip: 'Fechar tour',
            }}
        />
    );
}
