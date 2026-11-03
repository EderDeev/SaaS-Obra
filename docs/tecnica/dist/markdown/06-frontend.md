# 06 · Frontend web

## Inertia e React

[resources/js/app.jsx](../../resources/js/app.jsx) inicializa `createInertiaApp` e resolve nomes como `Tenant/Rdo/Calendar` para `resources/js/Pages/Tenant/Rdo/Calendar.jsx`. [resources/js/bootstrap.js](../../resources/js/bootstrap.js) contém a preparação do cliente HTTP. O template [resources/views/app.blade.php](../../resources/views/app.blade.php) fornece a estrutura HTML inicial.

O servidor monta os dados de cada página. React cuida da interação e do estado temporário, mas não calcula autorizações definitivas. Por padrão, use os helpers e capacidades enviados pelo backend para exibir controles; ao salvar, o servidor revalida a operação.

## Navegação global

[AuthenticatedLayout.jsx](../../resources/js/Layouts/AuthenticatedLayout.jsx) organiza o menu, o contexto atual e as notificações. [HandleInertiaRequests.php](../../app/Http/Middleware/HandleInertiaRequests.php) compartilha dados como usuário, tenant, navegação e permissões de módulos. Se um menu desaparecer, confira essas props e a regra de permissão antes de concluir que a página foi removida.

Rótulos de menu não precisam ser iguais aos namespaces de rota. “Ordem de Serviço” aponta para `tenant.os-dnit.*`; “Liberação de Serviço” aponta para `tenant.ordem-servico.*`. As URLs são construídas com Ziggy e os nomes registrados no Laravel.

## Mapa de páginas

| Caminho | Função |
| --- | --- |
| [Pages/Tenant/Rdo](../../resources/js/Pages/Tenant/Rdo) | Calendário/lista, preenchimento, leitura, inspeção, aprovação e parametrização |
| [Pages/Tenant/Qualidade](../../resources/js/Pages/Tenant/Qualidade) | RNC, IC, responsáveis, dashboards e modelos |
| [Pages/Tenant](../../resources/js/Pages/Tenant) | Demais telas tenant, organizadas por domínio |
| [Pages/Platform](../../resources/js/Pages/Platform) | Administração da plataforma |
| [Pages/Auth](../../resources/js/Pages/Auth) | Autenticação e recuperação de acesso |
| [Components](../../resources/js/Components) | Controles compartilhados e tours |

O inventário gerado lista todas as páginas presentes, inclusive telas auxiliares e prévias. Uma página existir no diretório não implica que tenha rota pública ou esteja ativa no menu.

## Formulários e dados temporários

Há uso de `useForm`, `router` e estado React. Ao alternar obras, o formulário precisa preservar o conteúdo das demais frentes. No RDO, o workspace e os drafts por obra evitam que uma seleção substitua silenciosamente os dados em edição.

Erros de validação devem aparecer associados ao campo ou à obra correta. Uploads exigem `FormData`/multipart; uma referência de foto existente deve ser preservada sem reenviar o binário. URLs temporárias de imagens precisam ser liberadas apenas quando não são mais usadas.

O formulário de RNC recebe a definição dos campos do modelo do contrato; a renderização decide entre texto, seleção, data e outros tipos. O servidor valida novamente essa definição. Separar o contrato-entidade do “Contrato do documento” do formulário de inspeção evita salvar a referência errada.

## Componentes relevantes do RDO

| Arquivo | Uso |
| --- | --- |
| [Calendar.jsx](../../resources/js/Pages/Tenant/Rdo/Calendar.jsx) | Calendário/lista, filtros, criação e indicação discreta do modelo |
| [Show.jsx](../../resources/js/Pages/Tenant/Rdo/Show.jsx) | Estado da tela e coordenação de formulários e modais |
| [DocumentWorkspace.jsx](../../resources/js/Pages/Tenant/Rdo/DocumentWorkspace.jsx) | Seções e visualização completa do documento |
| [ClientReviewPanel.jsx](../../resources/js/Pages/Tenant/Rdo/ClientReviewPanel.jsx) | Comparação, comentários e decisão do cliente |
| [ManagerFlowActions.jsx](../../resources/js/Pages/Tenant/Rdo/ManagerFlowActions.jsx) | Encaminhamento e devolução da gerenciadora |
| [InspectionEvidenceEditor.jsx](../../resources/js/Pages/Tenant/Rdo/InspectionEvidenceEditor.jsx) | Edição das evidências da gerenciadora |
| [PdfDownloadModal.jsx](../../resources/js/Pages/Tenant/Rdo/PdfDownloadModal.jsx) | Etapas e obras que compõem a exportação |
| [Settings.jsx](../../resources/js/Pages/Tenant/Rdo/Settings.jsx) / [WorkflowEditor.jsx](../../resources/js/Pages/Tenant/Rdo/WorkflowEditor.jsx) | Configuração por contrato e fluxo |
| [RdoCatalogPicker.jsx](../../resources/js/Components/RdoCatalogPicker.jsx) | Seleção e quantidades de recursos |
| [partyAppearance.js](../../resources/js/Pages/Tenant/Rdo/partyAppearance.js) / [calendarStageAppearance.js](../../resources/js/Pages/Tenant/Rdo/calendarStageAppearance.js) | Cores e identificação das etapas/partes |

## Bibliotecas de interface

FullCalendar representa o calendário do RDO; ECharts é usado para gráficos; Mapbox GL fornece mapas; PDF.js participa das prévias de documento; Lucide fornece ícones. Os componentes encapsulam necessidades específicas; alterar a versão da biblioteca pode exigir rever CSS, eventos, workers e tamanhos.

## Estilos e build

[resources/css/app.css](../../resources/css/app.css) concentra estilos/tokens, com Tailwind e classes utilitárias. Confira também estilos locais do módulo. Use tokens do sistema e sinais além da cor para estados relevantes, como texto da etapa, ícone e rótulo acessível.

`npm run dev` inicia o Vite de desenvolvimento; `npm run build` gera `public/build/manifest.json` e assets versionados. O PHP e os assets precisam pertencer à mesma entrega. Um contêiner iniciado a partir de uma imagem antiga pode manter a tela antiga mesmo com o código do host atualizado.

Para modais, valide foco, fechamento, scroll, cancelamento, responsividade e preservação do formulário. As verificações de RDO em [tests/Frontend](../../tests/Frontend) executam efeitos reais dos componentes em JSDOM; não substituem inspeção visual em navegador ou aparelho.

## Tutoriais visuais

[TutorialWorkspace.jsx](../../resources/js/Pages/Tenant/Tutorials/TutorialWorkspace.jsx) organiza busca, seleção do guia e menu recolhível. A preferência do menu é separada por usuário e diretoria. [VisualTutorial.jsx](../../resources/js/Pages/Tenant/Tutorials/VisualTutorial.jsx) monta os diagramas a partir do catálogo; [tutorialVisualData.js](../../resources/js/Pages/Tenant/Tutorials/tutorialVisualData.js) contém rótulos curtos, exemplos e a variação entre os quatro modelos de RDO.

Na Ordem de Serviço, [OsWorkflowTutorial.jsx](../../resources/js/Pages/Tenant/Tutorials/OsWorkflowTutorial.jsx) mantém o diagrama de aprovação e devoluções. O hover/foco apresenta uma explicação curta; o clique/toque abre [TutorialStageModal.jsx](../../resources/js/Pages/Tenant/Tutorials/TutorialStageModal.jsx), com descrição e prévia ilustrativa da etapa. Essas prévias não executam operações nos documentos reais.

O conteúdo vem de [TutorialCatalog.php](../../app/Support/TutorialCatalog.php), com guias próprios para OS, IC e RNC. As capturas/vídeos anteriores permanecem identificados como referências visuais. A suíte `npm run test:tutorial-ui` verifica os guias, os modais, o menu e os modelos sem escrever no banco.

Os modais usam [tutorialScenes.js](../../resources/js/Pages/Tenant/Tutorials/tutorialScenes.js) para escolher as telas conforme a ação. Consultas e seleções simples ficam em uma tela; criação e preenchimento usam as seções reais. O orçamento percorre dados gerais, cálculo e bases antes da estrutura de etapas e itens. Os cenários de RDO distinguem preenchimento, revisão da gerenciadora, decisão do cliente e assinatura conforme o modelo do contrato. Exemplos não submetem dados.
