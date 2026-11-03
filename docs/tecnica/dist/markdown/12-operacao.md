# 12 · Ambiente, entrega e diagnóstico

## Preparar um ambiente local

Requisitos declarados: PHP 8.4 ou superior compatível, Composer, Node/npm e banco PostgreSQL para operação; extensões PHP de acordo com `composer.json`. OCR exige também as ferramentas do sistema instaladas na imagem. O CI usa Node 22 e PHP 8.4 como referências, não como prova das versões instaladas em cada estação.

Em um checkout novo, instale dependências sem substituir os lockfiles:

```powershell
composer install
npm ci
```

Prepare um `.env` local a partir do exemplo, com banco explicitamente escolhido, e gere a chave apenas na instalação nova. Não substitua `APP_KEY` de um ambiente existente: dados criptografados podem deixar de ser legíveis. Não copie um `.env` de produção para fazer testes.

O script `composer run setup` também executa migration; não o use como comando neutro de instalação sobre qualquer ambiente. Leia o script e confirme a conexão antes.

Antes de executar uma migration ou comando que escreve, confira ambiente, driver, host e banco resolvidos. A URL de conexão pode prevalecer sobre variáveis individuais; olhar só `DB_HOST` não basta. Com a conexão confirmada e autorizada, use a migration normal para frente. Não use reset, rollback, wipe ou seeder como “correção” de uma base existente.

## Executar a aplicação

```powershell
npm run serve:php
npm run dev
```

Os comandos rodam em terminais separados. O primeiro aplica limites de upload previstos pelo projeto. Para acesso por aparelho na rede, existe `npm run serve:mobile`, que escuta em `0.0.0.0`; confira firewall e endereço da máquina. `127.0.0.1` no aparelho significa o próprio aparelho.

Filas e scheduler são necessários para o fluxo completo. Consulte os scripts de `composer.json` e `railway/start-app.sh`: eles não têm exatamente o mesmo conjunto de processos. Não inicie workers duplicados ou tarefas de geração sobre uma base compartilhada sem saber quais processos já estão ativos.

## Docker local

[docker-compose.local.yml](../../docker-compose.local.yml) usa um PostgreSQL existente acessível pelo host; não deve ser tratado como ambiente descartável. O código e os assets são copiados para a imagem no build. Reiniciar um contêiner não atualiza automaticamente esses arquivos.

Para uma atualização normal do código da imagem local:

```powershell
docker compose -f docker-compose.local.yml up -d --build app
```

O script de inicialização também executa rotinas de preparação e congelamento de snapshots. Embora o rebuild não seja um reset, ele inicia processos com acesso ao banco; confirme o ambiente antes. Preserve volumes e uploads.

## Entrega declarada para Railway

[railway.toml](../../railway.toml) define build, pre-deploy, startup e `/up`. [railway/init-app.sh](../../railway/init-app.sh) resolve a conexão e executa migrations normais; [railway/start-app.sh](../../railway/start-app.sh) prepara storage, congela snapshots legados de RNC, inicia scheduler/workers e atende HTTP.

Antes de produção, o projeto exige pedido explícito para a operação e backup recuperável verificado. A documentação não executa esse procedimento nem afirma que um backup atual existe. Para liberar uma mudança, revise diff e destino, confirme validações, arquivos persistentes, migrations necessárias e efeitos externos. Após a entrega, confira versão, saúde HTTP e as operações afetadas.

`/up` é uma verificação de saúde HTTP do Laravel; não é teste funcional de fila, e-mail, APS, OCR ou OpenSign. Um build aprovado também não prova que todas as migrations do ambiente foram aplicadas.

## Configurações por finalidade

| Grupo | Onde consultar | Observação |
| --- | --- | --- |
| Aplicação/URL | [config/app.php](../../config/app.php) | Nome, URL, ambiente e contexto de links |
| Banco | [config/database.php](../../config/database.php) | Driver, URL, conexões e proteção destrutiva |
| Sessão/cache | [config/session.php](../../config/session.php), [config/cache.php](../../config/cache.php) | Backends e persistência |
| Filas | [config/queue.php](../../config/queue.php) | Conexões, retry e filas específicas |
| Logs | [config/logging.php](../../config/logging.php) | Canais e retenção configurada |
| Mail | [config/mail.php](../../config/mail.php) | Transporte e remetente |
| Assinaturas | [config/signatures.php](../../config/signatures.php) | Provedor, endpoints e segredo do webhook |
| Arquivos | [config/filesystems.php](../../config/filesystems.php) | Raiz privada, pública e storage remoto |
| Integrações | [config/services.php](../../config/services.php), [config/ged.php](../../config/ged.php), [config/projects.php](../../config/projects.php) | Credenciais por nome, timeouts e limites |

## Diagnóstico rápido

| Sintoma | Conferir primeiro |
| --- | --- |
| Menu não aparece | Tenant atual, props Inertia, permissão do módulo e vínculo/exceção aplicável |
| Tela antiga após alteração | Versão do PHP e assets; imagem do contêiner; manifest Vite; cache do navegador |
| Não consegue criar RDO | Permissão, configuração ativa, período, dias habilitados e documento já existente |
| Não consegue encaminhar RDO | Obras incompletas, responsável da etapa, pendências e estado atual |
| Mobile não encontra documentos | API selecionada, tenant/contrato, janela do bootstrap, parte responsável e estados editáveis |
| Conflito ao sincronizar | Revisão/base alterada; preservar rascunho e comparar antes de tentar novamente |
| OCR parado | Worker `ged`, jobs/falhas, binários, acesso ao arquivo e limites de execução |
| Projeto não abre no viewer | Status da versão, tradução APS, fila, token/escopos e tamanho do contêiner de visualização |
| E-mail não chega | Job/canal, falhas, transporte, remetente e entrega no provedor; não só o status da aplicação |
| PDF com logo/campos antigos | Snapshot do documento; pode ser preservação esperada, não erro da configuração atual |
| Assinatura não conclui | Solicitação externa, partes pendentes, webhook/segredo, sincronização e PDF assinado disponível |

## Logs e dados sensíveis

Use logs para identificar operação, horário e tipo de falha. Evite compartilhar dumps completos, tokens Bearer, URLs de conexão ou payloads com documentos e dados pessoais. Os arquivos `tmp` e relatórios de homologação podem conter informações de cenário; não os publique automaticamente junto do portal.

Documentos anteriores de implantação permanecem no repositório, mas podem mencionar nomes e processos antigos. Para comportamento atual, confronte os scripts efetivos e o estado do ambiente. Este manual não é confirmação de infraestrutura em execução.
