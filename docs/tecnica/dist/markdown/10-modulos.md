# 10 · Módulos e caminhos de manutenção

## Contratos e parametrizações

[ContractController](../../app/Http/Controllers/Tenant/ContractController.php) e [ContractParticipantController](../../app/Http/Controllers/Tenant/ContractParticipantController.php) cuidam de contratos e participações. `Contract` relaciona obras, empresas, datas, valor e módulos associados. O valor pode ser omitido e editado depois; ausência de valor não significa necessariamente valor zero.

[Tenant/Parametrizacao](../../app/Http/Controllers/Tenant/Parametrizacao) agrupa obras, empresas, disciplinas, trechos e usuários/contratos. Configurações específicas de RDO/RNC/OS permanecem nos próprios módulos. O tenant é a diretoria responsável pelo contexto dos contratos. O cadastro de empresa registra uma organização participante do contrato; o nome da empresa no relatório é uma parametrização documental e não redefine a diretoria.

## Ordem de Serviço — namespace `OsDnit`

| Ponto de entrada | Responsabilidade |
| --- | --- |
| [OsDnitOrderController](../../app/Http/Controllers/Tenant/OsDnitOrderController.php) | Listagem, abertura, edição, leitura, PDF e exclusão |
| [OsDnitWorkflowController](../../app/Http/Controllers/Tenant/OsDnitWorkflowController.php) | Envio, análise, decisão e finalização |
| [OsDnitSettingsController](../../app/Http/Controllers/Tenant/OsDnitSettingsController.php) | Rodovias, lotes e referências parametrizadas |
| [OsDnitProductController](../../app/Http/Controllers/Tenant/OsDnitProductController.php) | Catálogo de produtos por contrato |
| [OsDnitResponsibleController](../../app/Http/Controllers/Tenant/OsDnitResponsibleController.php) | Responsáveis do fluxo |
| [OsDnitDashboardController](../../app/Http/Controllers/Tenant/OsDnitDashboardController.php) | Indicadores e filtros |
| [OsDnitPdfRenderer](../../app/Services/OsDnitPdfRenderer.php) | Exportação documental |

A URL base é `/t/{tenant}/os-dnit`. O rótulo atual é Ordem de Serviço, com submenu Ordens de Serviço. O estado interno `draft` aparece como **Demanda**; `awaiting_concremat` e `returned_concremat` ainda carregam o nome histórico, mas se referem à Supervisão.

O analista descreve a demanda; os produtos são definidos na análise da supervisão. O fluxo segue para fiscal do contrato e pode chegar a Aprovada; finalizar a OS é um passo posterior que representa serviço concluído. `approved` e `completed` não são sinônimos.

Lotes de supervisão podem vincular Referência SEI, aproveitada na criação. Produtos, rodovias e lotes devem respeitar o contrato. Exclusão é soft delete e só é permitida na demanda ainda não enviada. Eventos e mensagens do fluxo ficam em estruturas próprias; o catálogo não deve ser modificado para reescrever retroativamente documentos históricos.

## Liberação de Serviço — namespace `OrdemServico`

A URL `/t/{tenant}/ordem-servico/os` pertence a outro módulo, operado por [OrdemServicoController](../../app/Http/Controllers/Tenant/OrdemServicoController.php). Models `OrdemServico`, itens, análises, comentários, documentos e responsáveis mantêm o nome histórico. [OrdemServicoMetricsController](../../app/Http/Controllers/Tenant/OrdemServicoMetricsController.php) fornece métricas.

Não aplique uma alteração pedida para OS/DNIT nesse módulo apenas porque a classe se chama `OrdemServico`. Confira namespace da rota, tabela, permissão e tela antes de alterar.

## Projetos e Autodesk

[ProjectController](../../app/Http/Controllers/Tenant/ProjectController.php) e [ProjectSubmissionBatchController](../../app/Http/Controllers/Tenant/ProjectSubmissionBatchController.php) coordenam documentos, versões e lotes. [ProjectReviewController](../../app/Http/Controllers/Tenant/ProjectReviewController.php) e [ProjectReviewWorkspaceController](../../app/Http/Controllers/Tenant/ProjectReviewWorkspaceController.php) tratam revisão, checklist e marcações. [ProjectViewerController](../../app/Http/Controllers/Tenant/ProjectViewerController.php) atende a visualização.

Documento e versão são entidades diferentes. Aprovar uma versão, importar para APS e conseguir visualizar o modelo são operações relacionadas, mas não equivalentes. O job [ProcessProjectVersionApsJob](../../app/Jobs/ProcessProjectVersionApsJob.php) depende do worker apropriado e das credenciais do provedor.

Arquivos podem usar disco indicado na versão. Preserve o arquivo e os metadados de versões anteriores ao corrigir um envio. Tokens do viewer precisam das permissões/escopos corretos; não envie credenciais de aplicação ao browser.

## GED / Documentação

[GedController](../../app/Http/Controllers/Tenant/GedController.php) atende documentos, pesquisa, versões, anexos, notas, tags e outros recursos. [ProcessGedDocumentOcrJob](../../app/Jobs/ProcessGedDocumentOcrJob.php) e [ProcessGedDocumentAttachmentOcrJob](../../app/Jobs/ProcessGedDocumentAttachmentOcrJob.php) processam OCR.

O preview não substitui o armazenamento do original. Um documento pode estar cadastrado enquanto o OCR está pendente/falhou. Verifique caminho/disco, estado do job, ferramentas do contêiner e fila antes de reenviar ou apagar o documento.

## Orçamentos

[OrcamentoController](../../app/Http/Controllers/Tenant/OrcamentoController.php) trabalha com orçamento, etapas, itens, composições e insumos. A importação de composições usa [ImportOrcamentoComposicoesJob](../../app/Jobs/ImportOrcamentoComposicoesJob.php). Modelos de composições e analíticos representam estruturas distintas, não apenas colunas de uma planilha.

Para mudar cálculo ou importação, siga o mapeamento do arquivo, as unidades e os critérios de composição, com testes que verifiquem valores esperados independentemente do algoritmo. Alterar só a formatação do frontend não corrige um total persistido incorretamente.

## Medição

[BoletimMedicaoController](../../app/Http/Controllers/Tenant/BoletimMedicaoController.php), [MedicaoController](../../app/Http/Controllers/Tenant/MedicaoController.php), [MedicaoBiController](../../app/Http/Controllers/Tenant/MedicaoBiController.php) e [MedicaoRelatorioController](../../app/Http/Controllers/Tenant/MedicaoRelatorioController.php) cobrem boletins, itens, indicadores e relatórios. Reajustes têm helpers próprios: [MedicaoReajusteCalculator](../../app/Support/MedicaoReajusteCalculator.php) e [MedicaoItemValueResolver](../../app/Support/MedicaoItemValueResolver.php).

Há versões, aditivos e competências/índices. Preserve a distinção entre quantidade executada, preço, reajuste e referência temporal. Confira também as permissões financeiras, que não precisam coincidir com o acesso operacional ao módulo.

## Atividades e planejamento

[ActivityController](../../app/Http/Controllers/Tenant/ActivityController.php) coordena atividades, responsáveis, comentários, arquivos e checklist. Algumas atividades podem estar ligadas à OS. O dashboard principal agrega indicadores conforme contratos e permissões, por meio de [DashboardController](../../app/Http/Controllers/Tenant/DashboardController.php); ele não é uma nova fonte independente de dados.

## Usuários, permissões e tutoriais

[UserController](../../app/Http/Controllers/Tenant/UserController.php) e [PermissionController](../../app/Http/Controllers/Tenant/PermissionController.php) administram acesso tenant/contrato. [TutorialCatalog](../../app/Support/TutorialCatalog.php) e [TutorialController](../../app/Http/Controllers/Tenant/TutorialController.php) compõem os guias e tours.

Ao acrescentar uma capacidade, confira menu, edição de usuário, tela de permissões, helper do módulo, middleware/controller e testes de leitura/escrita. Atualizar apenas o checkbox não garante que o recurso esteja protegido.
