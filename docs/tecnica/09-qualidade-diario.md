# 09 · RDO, RNC e IC

## RDO: quatro modelos de fluxo

A definição está em [RdoWorkflow](../../app/Support/RdoWorkflow.php). Os identificadores internos são estáveis; os rótulos descrevem as partes envolvidas.

| Identificador | Preenchimento principal | Análise/aprovação |
| --- | --- | --- |
| `construtora_gerenciadora_cliente` | Construtora | Gerenciadora analisa; cliente manifesta a decisão final |
| `gerenciadora_construtora` | Construtora | Gerenciadora controla aprovação, ressalva ou devolução |
| `gerenciadora_cliente` | Gerenciadora | Cliente analisa/aprova |
| `gerenciadora` | Gerenciadora | Salva sem fluxo de aprovação |

O cliente não preenche clima, mão de obra ou equipamentos. Sua manifestação pertence à análise. Quando a gerenciadora analisa os registros da construtora, pode adicionar atividades, comentário, clima e evidências por obra em `RdoFiscalizacaoRegistro`, sem sobrescrever o preenchimento da construtora.

## Obras como conjunto

Um RDO consolidado tem uma data e várias obras/frentes. A tela permite trocar a obra de preenchimento, mas o encaminhamento e a devolução movimentam o conjunto. A regra evita que obras do mesmo diário avancem independentemente e acabem em versões/datas incompatíveis.

O servidor só libera o avanço quando os requisitos de todas as obras são atendidos. Decisões e ressalvas podem ter detalhamento por obra, mesmo quando o status do RDO representa o conjunto. Uma ressalva deve ficar visível também no calendário/lista, não apenas em um texto interno da análise.

## Parametrização e geração

[RdoConfiguracao](../../app/Models/RdoConfiguracao.php) associa contrato, obras, responsável, datas, fuso, dias da semana, prazo e opções de cópia. [RdoDailyGenerator](../../app/Services/RdoDailyGenerator.php) avalia configurações ativas; a geração agendada usa **00:05 no fuso da configuração**. O scheduler chama `rdo:generate-daily` a cada minuto, mas o gerador verifica elegibilidade e existência para não criar um diário repetido.

O período de geração não é automaticamente igual à duração do contrato. Um botão de criação ausente pode decorrer de data fora do período, dia da semana desabilitado, configuração inativa, RDO já existente ou ausência de permissão. Os dias são normalizados como números no salvamento e aceitos pelo gerador mesmo quando dados antigos os contêm como texto.

`is_holiday` é informado manualmente. O antigo indicador de gerar em feriados não deve ser interpretado como um calendário automático de feriados municipais/estaduais. As opções de cópia controlam quais seções e recursos passam do documento anterior; não são cópia irrestrita de todo o histórico.

## Campos e evidências do RDO

Preenchimento principal: condições do tempo por período, chuva, recursos humanos, equipamentos, atividades, comentários e fotos. As fotos são opcionais e não bloqueiam o envio. A opção de parametrização que controla a seção fotográfica no PDF ainda usa um identificador histórico de exigência; a nomenclatura da coluna não deve ser tomada como comportamento atual de validação.

Os cadastros de mão de obra, equipamentos e subcontratadas pertencem ao contrato, com escopo adicional de tenant. Um ID de recurso de outro contrato não deve ser aceito porque apareceu em um cache antigo.

## PDF do RDO

[RdoPdfTemplates](../../app/Support/RdoPdfTemplates.php) separa fluxo e apresentação. Há `standard` e `concremat_diary`; o layout Concremat é aceito no fluxo Gerenciadora + Cliente. Ele utiliza os mesmos dados comuns e categoriza atividades em Engenharia, Segurança do trabalho e Meio ambiente. Não cria um módulo independente.

A configuração por contrato passa ao snapshot do RDO. Cores, logos e empresa do relatório são parametrizáveis. O PDF Concremat possui composição própria e não imprime o comentário geral da gerenciadora nem o histórico/fluxo de análise como o padrão. Os dados permanecem no sistema.

[PdfDownloadModal](../../resources/js/Pages/Tenant/Rdo/PdfDownloadModal.jsx) permite exportar etapas cumulativas e selecionar obras. Exportações por etapa excluem histórico e campos de assinatura que não pertencem ao conteúdo solicitado. O PDF completo assinado é o arquivo do processo de assinatura, não uma regeneração editável de um subconjunto de páginas/obras.

## RNC: dados e fluxo

O relatório tem assunto, obra/contrato, empresas, disciplina, gravidade, localização, descrição, ações recomendadas, prazo e fotos. O assunto é a identificação textual usada nas listagens. Uma IC pode ser referenciada opcionalmente para demonstrar comunicação anterior; precisa ser da mesma obra, comunicada e acessível.

O fluxo inclui abertura/comunicação, proposta da construtora, análise, evidências e assinatura. A evidência informa resultado **resolvido**, **parcialmente** ou **não resolvido**; os dois últimos exigem motivação. Registrar evidência não conclui automaticamente o processo: a assinatura das partes selecionadas integra a conclusão.

Controllers estão em [Tenant/Qualidade](../../app/Http/Controllers/Tenant/Qualidade). As telas de proposta, análise e evidência utilizam modais; isso não muda as verificações de etapa e permissão. O mapa exibido ao acessar uma RNC é um recurso da tela, com expansão/minimização; não integra o PDF.

## Modelos de RNC

| Componente | Função |
| --- | --- |
| [RncPdfTemplates](../../app/Support/RncPdfTemplates.php) | Seleção de layout e configuração |
| [RncModelFields](../../app/Support/RncModelFields.php) | Definição, validação e apresentação de campos adicionais |
| [RncPdfHeaderFields](../../app/Support/RncPdfHeaderFields.php) | Posicionamento das informações no cabeçalho |
| [RncDashboardFields](../../app/Support/RncDashboardFields.php) | Filtros estruturados a partir de campos compatíveis |
| [RncPdfSettingController](../../app/Http/Controllers/Tenant/Qualidade/RncPdfSettingController.php) | Parametrização por contrato e preview |
| [RncPdfRenderer](../../app/Services/RncPdfRenderer.php) | Renderização dos templates e etapas |

O padrão permite campos manuais parametrizados. O formulário de inspeção tem campos definidos conforme o documento: tipo de obra, pavimento, contrato do documento, KM e localização. “Contrato do documento” é uma opção cadastrada para o formulário e não a entidade `contracts`. KM e localização são texto. A interface não deve convertê-los automaticamente em coordenadas ou distância numérica.

Logos são configuradas no contrato, não na abertura de cada RNC. Snapshots preservam as logos e definições dos documentos anteriores mesmo quando ainda não foram enviados para assinatura. Novos layouts devem ser adicionados com seus campos e renderização próprios, preservando documentos já criados.

## IC: comunicação anterior à RNC

[InstrucaoCampoController](../../app/Http/Controllers/Tenant/Qualidade/InstrucaoCampoController.php) implementa a IC. O fluxo atual é instrução → comunicação aos envolvidos → registro de evidências e resposta pela supervisão → finalização. A resposta é Sim, Parcialmente ou Não.

Não existe a etapa atual de proposta de ação corretiva e análise dessa proposta na IC. A criação não exige latitude/longitude, projeto ou prazo de ação corretiva como a RNC. Imagens têm prévia e legenda; documentos não são tratados como se fossem imagens. O resultado aparece junto ao número na listagem, e o dashboard distribui os resultados com filtros.

Uma IC não resolvida não deve ser silenciosamente convertida em RNC pela interface: a referência explícita na abertura da RNC mantém os dois documentos e o histórico de comunicação.
