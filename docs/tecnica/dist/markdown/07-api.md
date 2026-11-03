# 07 · APIs e contratos HTTP

## Superfícies de acesso

As rotas web em [routes/web.php](../../routes/web.php) usam sessão, CSRF, Inertia e middleware de tenant/módulo. A API específica em [routes/api.php](../../routes/api.php) atende mobile e webhook. Não exponha um controller web ao app apenas removendo autenticação ou copiando a URL de um formulário.

O [inventário de rotas](inventario-rotas.md) contém o registro completo da revisão, incluindo método, URI, nome, ação e middleware. As referências abaixo explicam os endpoints de integração mais importantes.

## Autenticação mobile

### POST `/api/mobile/login`

Entrada JSON:

```json
{
  "email": "usuario@example.test",
  "password": "<senha informada pelo usuário>",
  "device_name": "SigWorks Mobile"
}
```

Resposta 200: `token`, `token_type`, `expires_at`, `user`, `tenant` e `tenants`. Os valores de exemplo são fictícios. Não registre senha ou token em logs. O login exige credenciais válidas, ausência de troca obrigatória de senha e acesso a pelo menos um tenant ativo.

Endpoints autenticados usam `Authorization: Bearer <token>` e `Accept: application/json`. O token fica associado ao tenant; selecionar um contrato não muda seu tenant.

### POST `/api/mobile/tenant`

Recebe `{"tenant_id": 123}`. O servidor verifica se o usuário pode acessar esse tenant ativo e emite nova sessão. O token anterior permanece associado ao ambiente anterior. Essa escolha não concede novos vínculos ou permissões.

### GET `/api/mobile/bootstrap`

Retorna dados autorizados para preparar o aparelho:

| Chave | Significado |
| --- | --- |
| `tenant`, `tenants` | Diretoria atual e diretorias autorizadas para seleção |
| `contracts` | Contratos liberados para criação de RNC |
| `document_models` | Layout, definições de campos e revisão do modelo RNC por contrato |
| `obras`, `empresas`, `projects`, `disciplinas`, `gravidades` | Opções da criação de RNC no escopo permitido |
| `ics` | ICs já comunicadas e visíveis, com vínculo de obra/contrato |
| `rdo_contracts` | Contratos com RDOs retornados para preenchimento |
| `rdo_diarios` | Entradas por RDO, obra e parte autorizada, incluindo revisão, seções, fotos, fluxo, feriado, assuntos e capacidade de envio |
| `rdo_mao_obra`, `rdo_equipamentos`, `rdo_subcontratadas` | Recursos ativos dos contratos dos RDOs retornados |

No cliente, a lista geral de contratos é a união das listas de RNC e RDO. Isso não concede permissão de RNC nos contratos disponíveis apenas para RDO. O bootstrap de RDO filtra estados de preenchimento e uma janela de 15 dias anteriores/posteriores à data atual; não é um endpoint de todo o histórico.

## Criar RNC offline

### POST `/api/mobile/rnc/offline`

Usa multipart para campos e fotos. Campos comuns: `local_uuid`, `obra_id`, `assunto`, empresas, disciplina, gravidade, data de abertura, descrição, observação, ações recomendadas e prazo de resposta. Coordenadas são opcionais.

| Campo adicional | Regra |
| --- | --- |
| `instrucao_campo_id` | Opcional; IC comunicada, não excluída, da mesma obra/contrato e com acesso autorizado |
| `project_document_ids[]` | Projetos relacionados; o endpoint mantém também compatibilidade com `project_document_id` |
| `document_model_revision` | Revisão retornada no bootstrap; mudança do modelo resulta em 409 para revisão do rascunho |
| `model_field_values[chave]` | Valores validados com as definições do modelo do contrato |
| `photos[]` | Até 12 imagens; JPG/JPEG/PNG/WebP, até 5 MB por arquivo na validação |
| `photo_comments[]`, `photo_positions[]` | Legenda e ordenação correspondentes |

A primeira criação retorna 201 com `rnc`. Reenvio de UUID já criado pelo mesmo usuário autorizado retorna o registro existente, sem editar seu conteúdo. O app não deve apresentar esse retry como edição de uma RNC já sincronizada.

## Sincronizar RDO offline

### POST `/api/mobile/rdo/offline`

Requer RDO já gerado, obra, parte, data, UUID e revisão. Não cria arbitrariamente um novo diário a partir da data do aparelho.

```json
{
  "local_uuid": "identificador-unico-do-envio",
  "rdo_diario_id": 100,
  "obra_id": 200,
  "reference_date": "2026-11-03",
  "filling_party": "gerenciadora",
  "revision": "<hash de 64 caracteres retornado no bootstrap>",
  "is_holiday": false,
  "secoes": {
    "clima": {},
    "mao_obra": {},
    "equipamentos": {},
    "atividades": {},
    "comentarios": {"gerenciadora": "Registro diário conferido."}
  }
}
```

Com arquivos, o app usa multipart e `sections_json` em vez de enviar `secoes` como objeto JSON. Fotos novas vão em `fotos[]`; referências existentes ficam na estrutura da seção `fotos`. Até 20 arquivos de imagem, com 12 MB por arquivo na validação. Limites HTTP/PHP ou do provedor podem ser menores e também precisam ser conferidos.

Retorna 201, `message` e `rdo` com revisão, seções e fotos atualizadas. Repetir exatamente o mesmo envio retorna o recibo; reutilizar UUID com conteúdo diferente ou tentar salvar revisão antiga gera conflito. Estado travado, obra fora do RDO ou parte não autorizada impedem a gravação.

### POST `/api/mobile/rdo/{rdoId}/submit`

Encaminha o documento completo para análise, reutilizando a regra web. O servidor força a ação `submit`, verifica permissão/etapa e exige que as obras estejam prontas. O cliente pede confirmação e bloqueia a ação se ainda houver rascunhos locais pendentes desse RDO. No modelo somente Gerenciadora, a conclusão ocorre pelo salvamento completo, sem encaminhamento para aprovação.

### RDA

`POST /api/mobile/rda/offline` retorna 410: funcionalidade descontinuada. Classes e tabelas históricas podem continuar no projeto para compatibilidade/preservação; não devem ser usadas para novos preenchimentos.

## Webhook OpenSign

`POST /api/webhooks/opensign` chama [OpenSignWebhookController](../../app/Http/Controllers/Api/OpenSignWebhookController.php) e o serviço de assinatura. Quando `OPENSIGN_WEBHOOK_SECRET` está configurado, compara o valor recebido em cabeçalho aceito ou query string. O retorno inclui `ok` e `matched`; `matched: false` informa que o evento não foi associado a uma solicitação, não que um documento foi assinado.

Configure o segredo e a URL externamente e confira assinatura/estado do documento. Não use uma resposta HTTP 200 isolada como prova de conclusão do fluxo de assinatura ou entrega de e-mail.

## Notificações web

`GET /notificacoes` retorna o feed do usuário, com cache privado desabilitado. As rotas de leitura e marcação de notificações ficam em `routes/web.php` e exigem sessão. O polling da interface é um consumidor desse feed, não uma conexão WebSocket implicitamente fornecida pelo Laravel.
