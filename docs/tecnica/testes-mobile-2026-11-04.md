# Validação mobile — RNC e RDO

Data: 04/11/2026. Validação automatizada local do cliente de sincronização e da API.

## Ambiente e resultado

- Backend: ambiente `testing`, driver `sqlite`, banco `:memory:`, host externo ausente. PostgreSQL e produção não foram usados.
- 77 testes existentes passaram, com 1.991 assertions.
- Dois testes novos passaram separadamente, com 51 assertions.
- Total: 79 testes, 2.042 assertions.
- Cliente mobile: `npm run test:sync` e `npx tsc --noEmit` passaram.
- Armazenamento de arquivos, notificações e integrações externas foram isolados nos testes.
- Não houve execução em aparelho físico ou emulador. SQLite nativo, câmera, permissões de Android/iOS e sobrevivência das fotos após fechar o app ainda precisam de validação no aparelho.

## Modelos de RDO

| Modelo | Preenchimento sincronizado pelo app | Etapa seguinte |
|---|---|---|
| Construtora + Gerenciadora + Cliente | Construtora, por obra | Gerenciadora registra inspeção na web; cliente analisa na web |
| Gerenciadora + Construtora | Construtora, por obra | Gerenciadora registra inspeção e decisão na web |
| Gerenciadora + Cliente | Gerenciadora, por obra | Cliente analisa na web |
| Somente Gerenciadora | Gerenciadora, por obra | Após completar todas as obras, salva/conclui sem aprovação |

A personalização PDF Concremat usa o fluxo Gerenciadora + Cliente; não constitui outro fluxo mobile. Os testes existentes validaram o vínculo por contrato e a preservação do documento e do fluxo.

## Construtora e gerenciadora: separação real dos registros

Nos modelos atuais com construtora, as duas partes não preenchem simultaneamente o mesmo formulário completo. A construtora preenche as seções do RDO; a gerenciadora, na análise, registra suas atividades, clima, comentário e evidências em registros de fiscalização separados.

- Preenchimentos: `rdo_secoes`, identificados também por obra e `filling_party`.
- Inspeção da gerenciadora: `rdo_fiscalizacao_registros`, por RDO, obra e versão interna, com usuário e evidências próprios.
- A API mobile atual só disponibiliza RDOs nas etapas de preenchimento. Não há endpoint mobile de inspeção da gerenciadora ou decisão do cliente.
- Portanto, a gerenciadora usa o app para preencher quando ela é a parte de preenchimento do modelo, mas ainda usa a web para analisar os modelos com construtora.

O novo teste usa usuários distintos atribuídos às duas partes nos dois modelos com construtora. Confirma que a gerenciadora não consegue sincronizar como construtora; a construtora sincroniza e encaminha; a gerenciadora registra imagem e comentário pela web; o comentário original da construtora permanece; um envio tardio da construtora é bloqueado após o encaminhamento.

Os testes existentes também cobrem documentos antigos com ambas as partes configuradas para preenchimento: as seções são independentes, o reenvio de uma parte não sobrescreve a outra, e revisões desatualizadas são recusadas. Isso não significa que os modelos atuais exponham inspeção mobile.

## Sincronização e proteção

- Rascunhos são vinculados ao usuário e à diretoria; o cache considera servidor, usuário e diretoria.
- A API valida token, permissões, obra, parte responsável, data e etapa atual.
- RDO: comparação de revisão evita sobrescrever alterações; recibo por UUID e hash evita duplicar o mesmo envio.
- RNC: UUID permite repetir o envio sem criar outra RNC; os dois modelos de documento foram testados com imagem e reenvio sem duplicar arquivos.
- RNC: campos personalizados e alteração da revisão do modelo são validados pelo servidor.
- Falhas ficam registradas no rascunho local para nova tentativa; sincronização é acionada pelo usuário. O retorno da conexão atualiza o bootstrap, sem chamar automaticamente o envio dos rascunhos.
- Sincronizar salva o preenchimento. Encaminhar o RDO é uma ação separada, que exige todas as obras preenchidas.
- Fotos opcionais não bloqueiam o envio; referências de fotos de outro contexto são recusadas.

## Comandos executados

Backend, depois de confirmar conexão SQLite em memória:

```powershell
php vendor/bin/phpunit tests/Feature/TenantRdoWorkflowTest.php tests/Feature/MobileTenantSelectionTest.php tests/Feature/TenantRncModelFieldsTest.php --testdox
php vendor/bin/phpunit tests/Feature/TenantRdoWorkflowTest.php --filter test_current_constructor_models --testdox
php vendor/bin/phpunit tests/Feature/TenantRncModelFieldsTest.php --filter test_mobile_syncs_both --testdox
```

Cliente, na pasta `sigworks-mobile`:

```powershell
npm run test:sync
npx tsc --noEmit
```

## Validação restante no aparelho

1. Fazer login e baixar os contratos de teste, modelos e obras.
2. Desativar a internet, salvar registros e fotos em duas obras, fechar e reabrir o aplicativo.
3. Confirmar a recuperação dos rascunhos e das imagens.
4. Voltar à internet e sincronizar; conferir confirmação e correspondência dos dados na web.
5. Alterar a mesma obra pela web antes de enviar um rascunho antigo e verificar a mensagem de conflito sem perda local.
6. Trocar usuário/diretoria e verificar que rascunhos de outro contexto não são enviados.
7. Completar todas as obras e encaminhar o RDO uma vez; realizar análise da gerenciadora/cliente na web conforme o modelo.

Para analisar e registrar a fiscalização da gerenciadora inteiramente no celular, será necessário implementar essa etapa na API e no aplicativo.
