# 08 · Aplicativo mobile SigWorks

## Projeto e responsabilidade

O app está em [sigworks-mobile](../../../sigworks-mobile/README.md). O nome público é **SigWorks**; o slug Expo, identificadores nativos e alguns nomes de armazenamento permanecem históricos para preservar continuidade. Não troque bundle ID, package ou nome do SQLite apenas para renomear a interface: isso pode criar outro aplicativo ou ocultar rascunhos existentes.

O escopo implementado é criação de RNC offline, preenchimento de RDO offline, sincronização e encaminhamento inicial do RDO. Análise, aprovação e assinatura continuam no sistema web. Exportar um bundle não significa ter gerado um APK/IPA ou testado um aparelho físico.

## Arquivos centrais

| Arquivo | Responsabilidade |
| --- | --- |
| [App.tsx](../../../sigworks-mobile/App.tsx) | Entrada do aplicativo; exporta o navegador |
| [src/navigation/AppNavigator.tsx](../../../sigworks-mobile/src/navigation/AppNavigator.tsx) | Seleção das telas e ligação de dados/callbacks |
| [src/screens](../../../sigworks-mobile/src/screens) | Telas de login, início, listagem e preenchimento de RNC/RDO |
| [src/components](../../../sigworks-mobile/src/components) | Controles compartilhados, calendário, recursos, atividades e fotos |
| [src/hooks/useMobileApp.ts](../../../sigworks-mobile/src/hooks/useMobileApp.ts) | Sessão, seleção de diretoria/contrato, estado dos formulários e coordenação do salvamento/sincronização |
| [src/styles/appStyles.ts](../../../sigworks-mobile/src/styles/appStyles.ts) | Estilos compartilhados das telas e componentes |
| [src/utils/mobile.ts](../../../sigworks-mobile/src/utils/mobile.ts) | Datas, valores iniciais, validação local e auxiliares de contexto |
| [src/types.ts](../../../sigworks-mobile/src/types.ts) | Tipos de sessão, bootstrap, formulários e rascunhos |
| [src/config.ts](../../../sigworks-mobile/src/config.ts) | Seleção da URL da API por plataforma/ambiente |
| [src/services/sync.ts](../../../sigworks-mobile/src/services/sync.ts) | Login, troca de tenant, bootstrap, payloads e envio |
| [src/services/context.ts](../../../sigworks-mobile/src/services/context.ts) | Chave de cache contextual e bloqueio de rascunho estrangeiro |
| [src/storage/database.ts](../../../sigworks-mobile/src/storage/database.ts) | SQLite local, configurações e estados de sincronização |
| [app.json](../../../sigworks-mobile/app.json) | Nome, ícones, splash, identificadores e projeto Expo |
| [eas.json](../../../sigworks-mobile/eas.json) | Perfis de build e ambiente da API |
| [assets](../../../sigworks-mobile/assets) | Ícones e splash SigWorks |
| [scripts/test-mobile-sync.cjs](../../../sigworks-mobile/scripts/test-mobile-sync.cjs) | Verificação dos payloads e isolamento sem API externa |

## Servidor, diretoria (tenant) e contrato

A URL da API é definida no build/configuração, não em um campo aberto para o usuário. Android em emulador pode usar `10.0.2.2` para o host; um aparelho físico precisa de um endereço alcançável na rede. iOS, Android e produção podem resolver URLs diferentes por configuração.

Após login, o perfil permite trocar entre diretorias autorizadas (`tenants`) quando existe mais de uma. A troca exige internet, carrega outro bootstrap e preserva os rascunhos da diretoria anterior. O cache diferencia servidor, usuário e diretoria. O contrato selecionado filtra opções e documentos; RNC e RDO podem oferecer conjuntos de contratos diferentes para o mesmo usuário. Empresas participantes do contrato não são opções de troca de diretoria.

Sessão fica em SecureStore. Rascunhos ficam em tabelas SQLite com `tenant_id` e `user_id`. O arquivo mantém o nome `rnc-offline.db`, mas também guarda RDO. Esses campos e o bloqueio contextual de sincronização impedem que a simples seleção de outro tenant reutilize o rascunho anterior.

## Rascunhos e sincronização

Estados: `pending`, `syncing`, `synced` e `error`. O rascunho contém o formulário, fotos, erro e data de atualização. O app não apaga registros pendentes ao sair ou trocar ambiente.

No RDO, a chave lógica do preenchimento é diário + obra + parte, além de usuário/tenant. Ao alternar obras, o conteúdo atual é guardado no aparelho. O feriado é uma propriedade do dia/RDO e deve permanecer consistente entre as obras. As quantidades e referências dos recursos pertencem ao contrato do documento.

Antes de sincronizar, o cliente carrega dados atualizados. Revisões só são ajustadas automaticamente quando as seções de base continuam equivalentes; mudanças reais devem produzir conflito, preservando o rascunho. Um sucesso retorna novas referências de fotos e revisão para os próximos envios. Fotos remotas não são reenviadas como arquivos novos.

O retry de RDO usa o mesmo identificador de envio enquanto o conteúdo é o mesmo. Salvar nova edição gera novo identificador. O servidor verifica o hash e o recibo; desabilitar botões não é a proteção principal contra duplicação.

## Modelos

RDO recebe o fluxo congelado no documento, a parte autorizada e assuntos de atividade quando o layout exige. O Concremat usa Engenharia, Segurança do trabalho e Meio ambiente. Os demais modelos mantêm os campos comuns. O cliente registra apenas o que o servidor retornou como editável; não oferece preenchimento principal ao cliente aprovador.

RNC recebe modelo e campos por contrato. A tela inclui assunto, projetos, IC comunicada da mesma obra e campos adicionais de texto, seleção, número, data, booleano ou área de texto. A revisão do modelo detecta parametrização alterada durante o trabalho offline. As logos são obtidas da configuração do servidor ao criar o documento; não há upload de logo na criação mobile.

## Validação e entrega

```powershell
npx tsc --noEmit
npm run test:sync
npx expo export --platform android --platform ios --output-dir dist/verificacao
```

TypeScript verifica os tipos; o teste de sincronização simula chamadas e inspeciona payloads; o export valida empacotamento JavaScript/assets. Para verificar câmera, permissões de arquivos/localização, teclado, rede instável e comportamento do SQLite, use aparelho/emulador e backend de homologação. Não faça esses testes enviando e-mails ou assinaturas reais inadvertidamente.

Nome e ícone nativos exigem uma nova instalação/build para atualizar um aplicativo já instalado. O projeto mantém suas dependências atuais; uma atualização do SDK Expo é uma mudança separada da personalização visual.
