# 05 · Backend e regras de negócio

## Organização das responsabilidades

| Elemento | Responsabilidade real no projeto |
| --- | --- |
| Rotas | Selecionam controller/método e middleware |
| Middleware | Protegem entrada, resolvem contexto e compartilham dados |
| Controllers | Recebem requests, validam payloads, autorizam e coordenam persistência/resposta |
| Models | Relações, casts, campos preenchíveis e eventos Eloquent |
| Support | Permissões, definição de modelos/fluxos e utilitários de domínio |
| Services | Operações reutilizadas, integrações, PDFs e geração diária |
| Jobs | Operações que devem ser executadas por worker |
| Notifications | Conteúdo e canais de mensagens de eventos |

O projeto não adota uniformemente um repositório de dados ou camada de casos de uso para todas as operações. Há controllers extensos e validação inline. Ao evoluir, procure reaproveitar a regra existente; criar uma regra paralela em outro endpoint aumenta a chance de web e mobile divergirem.

## Exemplo: salvar um RDO

[RdoController](../../app/Http/Controllers/Tenant/RdoController.php) concentra carregamento, parametrização, seções, capacidades e mudanças de fluxo. `saveAllSections` valida a obra e parte responsável e grava as seções autorizadas. O salvamento de várias obras coordena as operações em transação; não deve finalizar um documento do modelo somente Gerenciadora antes de completar as obras necessárias.

[MobileRdoController](../../app/Http/Controllers/Api/Mobile/MobileRdoController.php) adiciona token/contexto, UUID, revisão, fotos multipart e recibo, e reaproveita o controller para salvar. O encaminhamento inicial mobile chama a mesma operação de fluxo, aplicada ao RDO inteiro.

## Exemplo: criar uma RNC

[RelatorioNaoConformidadeController](../../app/Http/Controllers/Tenant/Qualidade/RelatorioNaoConformidadeController.php) coordena o fluxo web. [MobileRncController](../../app/Http/Controllers/Api/Mobile/MobileRncController.php) valida a criação offline e as referências do contrato. O evento `creating` em [RelatorioNaoConformidade](../../app/Models/RelatorioNaoConformidade.php) prepara snapshots de documento/logos e valida valores dos campos parametrizados.

Isso significa que uma criação via model pode disparar trabalho adicional além do INSERT. Para importações e scripts de teste, confira eventos, permissões e efeitos colaterais antes de usar criação em massa. Não desative eventos indiscriminadamente para contornar validação.

## Serviços de maior impacto

| Arquivo | Responsabilidade |
| --- | --- |
| [RdoDailyGenerator](../../app/Services/RdoDailyGenerator.php) | Datas elegíveis, criação consolidada e cópia de dados configurados |
| [RdoPdfRenderer](../../app/Services/RdoPdfRenderer.php) | Montagem do PDF, filtragem por etapa/obra e suporte à assinatura |
| [RdoSignatureService](../../app/Services/RdoSignatureService.php) | Solicitações, signatários, provedor, sincronização e webhook |
| [RncSignatureService](../../app/Services/RncSignatureService.php) | Preparação do documento/partes da RNC para o subsistema de assinatura |
| [RncDocumentSnapshots](../../app/Services/RncDocumentSnapshots.php) / [RncPdfLogoSnapshots](../../app/Services/RncPdfLogoSnapshots.php) | Preservação documental por RNC |
| [OsDnitOrderNumber](../../app/Services/OsDnitOrderNumber.php) / [OsDnitOrderHistory](../../app/Services/OsDnitOrderHistory.php) | Numeração e histórico da OS |
| [OsDnitFlowNotifier](../../app/Services/OsDnitFlowNotifier.php) / [OsDnitFlowRecipients](../../app/Services/OsDnitFlowRecipients.php) | Disparo e escolha dos destinatários do fluxo de OS |
| [AutodeskApsService](../../app/Services/AutodeskApsService.php) | Comunicação e processamento relacionado ao Autodesk APS |
| [GedOcrService](../../app/Services/GedOcrService.php) | OCR de documentos e anexos do GED |
| [AssistantRetriever](../../app/Services/Assistant/AssistantRetriever.php) | Recuperação de contexto permitido para o assistente |

## Validação e autorização

Valide formato e também pertencimento: uma empresa existente pode não pertencer ao contrato escolhido. `Rule::exists` filtrada por tenant pode precisar de uma segunda condição de contrato/obra. A permissão de módulo não dispensa a responsabilidade exigida pela etapa.

Em estruturas JSON, preserve os campos esperados ao usar o retorno da validação. Regras excessivamente parciais podem retirar campos legítimos do array validado; isso é especialmente relevante para atividades com título, descrição e assunto. As implementações atuais validam certas subestruturas separadamente para evitar perda de conteúdo.

## Efeitos colaterais

Avançar um fluxo pode criar histórico, snapshot, job e notificações. E-mail e geração de assinatura são efeitos reais quando os serviços estão configurados. Nos testes, use fakes e bloqueie HTTP externo quando apropriado; não confunda simulação com envio de uma mensagem real.

O fluxo de OS enfileira mensagens na conexão dedicada junto da transação. Em outros módulos, verifique o momento de enfileiramento e o `after_commit` do canal/conexão. Não suponha atomicidade de banco e API externa: a integração precisa tratar timeout, retry e reconciliação.

## Respostas e erros

No web, falhas de validação alimentam erros de formulário por sessão/Inertia. Na API, use JSON e códigos coerentes: 401 para autenticação, 403 para acesso, 404 para recurso não encontrado no escopo, 409 para conflito de revisão/etapa e 422 para entrada/regra inválida. Nem todos os controllers têm o mesmo formato de payload; o inventário de rotas não é uma especificação OpenAPI automática.
