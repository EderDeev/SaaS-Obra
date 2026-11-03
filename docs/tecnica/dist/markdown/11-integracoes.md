# 11 · Integrações e processamento externo

## Matriz de referências

| Integração | Código/configuração | Dependência operacional |
| --- | --- | --- |
| Autodesk APS | [AutodeskApsService](../../app/Services/AutodeskApsService.php), [services.php](../../config/services.php), [projects.php](../../config/projects.php) | Credenciais, bucket, rede e workers APS |
| Assinatura | [Services/Signatures](../../app/Services/Signatures), [signatures.php](../../config/signatures.php) | Provedor configurado, e-mail validado e webhook/sincronização |
| OCR | [GedOcrService](../../app/Services/GedOcrService.php), [ged.php](../../config/ged.php) | Binários do sistema e worker `ged` |
| E-mail | [mail.php](../../config/mail.php), [AppServiceProvider](../../app/Providers/AppServiceProvider.php) | Transporte, credenciais, remetente e política do provedor |
| Armazenamento | [filesystems.php](../../config/filesystems.php), [Services/Storage](../../app/Services/Storage) | Volume persistente ou blob storage e autorização de download |
| Mapas | [package.json](../../package.json) e componentes que usam Mapbox GL | Token/configuração e acesso aos estilos/tiles |
| Assistente | [Services/Assistant](../../app/Services/Assistant), [services.php](../../config/services.php) | Credencial, modelo configurado, cotas e escopo de acesso |

## OpenSign

`SIGNATURE_DRIVER` seleciona o provedor; `local` é o default declarado. `OPENSIGN_BASE_URL`, `OPENSIGN_API_KEY`, `OPENSIGN_CREATE_REQUEST_PATH`, `OPENSIGN_WEBHOOK_SECRET`, `OPENSIGN_VERIFY_SSL` e `OPENSIGN_TIME_TO_COMPLETE_DAYS` controlam a integração. A existência de valores no ambiente não prova que uma solicitação foi entregue ou assinada.

[OpenSignSignatureProvider](../../app/Services/Signatures/OpenSignSignatureProvider.php) prepara arquivo, partes e solicitação. [RdoSignatureService](../../app/Services/RdoSignatureService.php) coordena estado e recebe eventos; [RncSignatureService](../../app/Services/RncSignatureService.php) prepara o caso RNC. A implementação compartilha entidades de solicitação/signatários entre os tipos de documento.

Sequência lógica: preparar PDF e signatários → criar solicitação → registrar identificador externo → acompanhar estado → recuperar PDF assinado → concluir conforme regras do documento. Timeout e resposta do provedor devem ser tratados sem assumir que repetir a chamada é sempre seguro.

E-mail aceito no OpenSign pode não ter chegado à caixa de entrada: confira remetente verificado, logs do provedor e spam. Não troque chaves ou reenvie solicitações reais automaticamente apenas porque a tela local não recebeu confirmação.

## Arquivos e storage

O disco `local` usa área privada. Em Railway com volume e ambiente production/staging, essa área é configurada sob o volume persistente. O disco `public` usa `storage/app/public` e URLs com `APP_URL`; o link público é preparado por `storage:link`. Azure tem discos separados para conteúdo privado e temporário; há também configuração S3.

Nem todo módulo usa o mesmo disco. Referências a `storage_disk`, serviços de materialização e rotas de download precisam ser preservadas. Um renderer que exige caminho local pode materializar temporariamente um arquivo remoto, sem mudar sua propriedade no banco.

Backups devem cobrir banco e arquivos persistentes. Verificar apenas um backup do PostgreSQL não comprova recuperação de fotos, PDFs e originais de projeto.

## Filas, notificações e polling

O projeto usa filas com finalidades diferentes:

| Fila/conexão | Finalidade |
| --- | --- |
| `imports` | Importações, como composições de orçamento |
| `default`, `maintenance` | Trabalho geral e manutenção |
| `ged` | OCR de documentos/anexos |
| `aps` ou `AUTODESK_APS_QUEUE` | Processamento Autodesk |
| conexão `os_dnit_mail`, fila `os-dnit-mail` | Mensagens da Ordem de Serviço, com retry próprio |

Consulte [railway/start-app.sh](../../railway/start-app.sh) para os processos realmente declarados. O worker geral não consome uma fila omitida de seu argumento `--queue`. A tabela `jobs` vazia também não comprova entrega de e-mail: uma tarefa pode ter falhado, sido consumida ou ter usado outro transporte.

[NotificationFeed](../../app/Support/NotificationFeed.php), [NotificationController](../../app/Http/Controllers/NotificationController.php) e os componentes de navegação coordenam alertas internos. O som é um comportamento do cliente e pode depender das permissões/interações do navegador. A entrega interna e a entrega de e-mail são canais distintos.

## Assistente e recuperação de contexto

[AssistantAccessScope](../../app/Services/Assistant/AssistantAccessScope.php) define acesso; [AssistantRetriever](../../app/Services/Assistant/AssistantRetriever.php) busca informações; [AssistantContextBuilder](../../app/Services/Assistant/AssistantContextBuilder.php) monta contexto; [AssistantQuotaService](../../app/Services/Assistant/AssistantQuotaService.php) acompanha cotas; [OpenAiAssistant](../../app/Services/Assistant/OpenAiAssistant.php) chama o provedor configurado.

O código revisado recupera dados dos módulos por consultas e escopos; não presuma uma base vetorial independente ou treinamento sobre todo o banco. Os nomes das variáveis em `services.php` descrevem modelo, limites e timeouts configuráveis. Nenhum valor secreto é necessário para entender a arquitetura.

Ao acrescentar um módulo ao assistente, preserve os mesmos limites de tenant, contrato, permissão e informação financeira. Não faça uma consulta global para preencher contexto que o usuário não poderia ver na interface.
