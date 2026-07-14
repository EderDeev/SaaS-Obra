import { router } from '@inertiajs/react';
import { ACTIONS, EVENTS, Joyride, STATUS } from 'react-joyride';
import { useEffect, useMemo, useRef, useState } from 'react';

const storageKey = 'ged:tour-section';
const activeStorageKey = 'ged:tour-active';
const stepStorageKey = 'ged:tour-step';
const navigationStorageKey = 'ged:tour-navigating';
const startedAtStorageKey = 'ged:tour-started-at';
const maxTourAgeMs = 30 * 60 * 1000;

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
            target: '[data-tour="ged-document-section-details"]',
            title: 'Detalhes',
            content: 'Em Detalhes ficam os metadados principais: contrato, tipo, correspondente, etiquetas, data e descricao.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-document-section-content"]',
            title: 'Conteudo',
            content: 'Em Conteudo voce acompanha o OCR e consulta o texto extraido do documento.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-document-section-attachments"]',
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
            target: '[data-tour="ged-document-section-notes"]',
            title: 'Notas',
            content: 'Em Notas ficam observacoes internas, comentarios e registros de acompanhamento do documento.',
            placement: 'bottom',
        },
        {
            target: '[data-tour="ged-document-section-permissions"]',
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
            target: '[data-tour="ged-email-rules"]',
            title: 'Regras de e-mail',
            content: 'As regras dizem o que consumir. Em "Processar somente anexos", um unico PDF vira documento principal; se houver mais de um PDF, entra em triagem. Em "Processar e-mail e anexos", o e-mail e convertido em PDF e vira o documento principal, enquanto todos os arquivos recebidos ficam como anexos.',
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
            target: '[data-tour="ged-tags"]',
            title: 'Etiquetas',
            content: 'Etiquetas ajudam a marcar tema, prioridade, status ou etapa. Elas tambem podem ser aplicadas por regras de e-mail.',
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

export default function GedTour({ tenant, section, documentTourUrls = {}, activeDocumentSection = null }) {
    const [run, setRun] = useState(false);
    const [stepIndex, setStepIndex] = useState(0);
    const navigatingRef = useRef(false);
    const steps = useMemo(
        () => (stepsBySection[section] || []).map((step) => ({ ...step, skipBeacon: true, spotlightClicks: true })),
        [section]
    );

    function showTourStep(index) {
        setStepIndex(index);
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
            const storedStep = Number(window.sessionStorage.getItem(stepStorageKey) || 0);
            window.sessionStorage.setItem(activeStorageKey, '1');
            window.sessionStorage.setItem(storageKey, section);
            showTourStep(storedStep);
            return;
        }

        if (window.sessionStorage.getItem(activeStorageKey) === '1' && activeSection === section) {
            clearTour();
        }

        setRun(false);
    }, [section, steps]);

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
    }

    function setStoredStep(nextStepIndex) {
        const normalized = Math.max(0, Math.min(nextStepIndex, steps.length - 1));

        window.sessionStorage.setItem(stepStorageKey, String(normalized));
        showTourStep(normalized);
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
        router.visit(route(sectionRoutes[nextSection], tenant.slug));
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
        router.visit(nextUrl, {
            preserveScroll: false,
            preserveState: false,
        });

        return true;
    }

    function handleCallback(data) {
        if (data.status === STATUS.SKIPPED || data.action === ACTIONS.CLOSE) {
            clearTour();
            return;
        }

        if (data.type === EVENTS.TARGET_NOT_FOUND) {
            const nextStepIndex = data.index + 1;

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
            const nextStepIndex = data.index + direction;

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

        if (data.status === STATUS.FINISHED || data.type === EVENTS.TOUR_END) {
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
                next: 'Avancar',
                skip: 'Fechar tour',
            }}
        />
    );
}
