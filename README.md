# Deming

SaaS multi-tenant para gerenciamento de obras.

- Laravel 13 + Inertia.js + React + Tailwind CSS
- Usuários globais com vínculos por tenant e por contrato
- Painel Super Admin
- Painel Tenant Admin
- Projetos com Autodesk APS
- Orçamentos, Medição, RNC e Atividades

## Rodando Localmente

Instalação inicial:

```bash
composer install
npm install
php artisan key:generate
php artisan migrate:fresh --seed
npm run build
```

Para subir a aplicação em desenvolvimento, use terminais separados.

Terminal 1, Laravel com limites corretos de upload:

```bash
npm run serve:php
```

Comando equivalente:

```bash
php -d upload_max_filesize=100M -d post_max_size=128M -d memory_limit=512M -d max_execution_time=0 -d max_input_time=0 -S 127.0.0.1:8000 server.php
```

Não use `php artisan serve` para testar upload de projetos. Ele pode carregar o `php.ini` local com `upload_max_filesize=2M` e `post_max_size=8M`, causando erro em arquivos pequenos ou médios.

Para conferir os limites carregados pelo servidor:

```text
http://127.0.0.1:8000/dev-php-limits
```

Terminal 2, Vite:

```bash
npm run dev -- --host 127.0.0.1 --port 5174 --strictPort
```

Terminal 3, fila de jobs:

```bash
php artisan queue:work database --queue=imports,default,maintenance --sleep=3 --tries=1 --timeout=3600
```

Terminal 4, fila do GED/OCR:

```bash
php artisan queue:work database --queue=ged --sleep=3 --tries=1 --timeout=3600
```

O worker é necessário quando `QUEUE_CONNECTION=database`. Ele processa tarefas em segundo plano, incluindo envio automático de projetos para o Autodesk APS depois da submissão.

Acesse:

```text
http://127.0.0.1:8000
```

Se o Vite ficar preso em outra porta, apague `public/hot` e suba o Terminal 2 novamente.

## Autodesk APS Local

Variáveis necessárias no `.env`:

```text
AUTODESK_APS_CLIENT_ID=...
AUTODESK_APS_CLIENT_SECRET=...
AUTODESK_APS_BUCKET_KEY=sua-chave-de-bucket
AUTODESK_APS_REGION=US
AUTODESK_APS_SCOPES="data:read data:write data:create bucket:create bucket:read viewables:read"
AUTODESK_APS_VERIFY_SSL=false
AUTODESK_APS_AUTO_PROCESS=true
```

Cuidados:

- Não deixe espaço depois do `=` em `AUTODESK_APS_CLIENT_SECRET`.
- Em Windows/local, `AUTODESK_APS_VERIFY_SSL=false` evita erro de certificado local.
- Em Railway/produção, use `AUTODESK_APS_VERIFY_SSL=true`.
- O processamento APS acontece nos servidores da Autodesk. O status `inprogress` com `99% complete` pode ficar parado por alguns minutos mesmo com a integração funcionando.

Diagnóstico local:

```bash
php scripts/check-aps-status.php
```

Para consultar o manifesto real na Autodesk:

```bash
php scripts/check-aps-status.php --manifest
```

Interpretação rápida:

- `jobs > 0` e nenhum `queue:work` rodando: a fila local está parada.
- `failed_jobs > 0`: algum job falhou e precisa ser inspecionado.
- `status=queued` sem `aps_object_id`/`aps_urn`: o arquivo ainda não foi enviado para APS.
- `status=processing` com `aps_object_id` e `aps_urn`: o arquivo já foi enviado e a conversão está na Autodesk.
- Manifesto `inprogress`/`99% complete`: APS está funcionando, mas a conversão ainda não terminou.
- Manifesto `success`: o viewer deve abrir o projeto.
- Manifesto `failed` ou `timeout`: a Autodesk recusou ou não conseguiu converter o arquivo.

## Acessos Demo

Todos usam a senha `Senha1!`.

- Super Admin: `admin@obras.test`
- Owner da empresa demo: `owner@demo.test`
- Engenheiro: `engenheiro@demo.test`

Em ambiente local também existe uma rota de apoio para entrar sem formulário:

```text
/dev-login/admin@obras.test
/dev-login/owner@demo.test
```

Essa rota só é registrada quando `APP_ENV=local`.

## Fluxos Implementados

- Super Admin em `/admin`
- Criação e listagem de tenants em `/admin/tenants`
- Dashboard do tenant em `/t/{slug}`
- Gestão de usuários internos em `/t/{slug}/users`
- Criação e listagem de contratos em `/t/{slug}/contracts`
- Espaço do contrato em `/t/{slug}/contracts/{id}`
- Participantes por contrato
- Parametrização por contrato
- Projetos com submissão, análise, aprovação, revisões e APS
- RNC com fluxo de notificação, ação corretiva, análise e evidências
- Orçamentos com insumos, composições, analíticos, relatórios e itens
- Medição com itens por contrato, aditivos e índices de reajuste

## Postgres

O projeto usa PostgreSQL local e no Railway.

Variáveis locais esperadas:

```text
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=deming
DB_USERNAME=...
DB_PASSWORD=...
```

Se aparecer `could not find driver`, habilite/instale a extensão `pdo_pgsql` no PHP usado pelo terminal.

## Deploy no Railway

O projeto inclui `railway.toml` com:

- build: `npm run build`
- pre-deploy: `php artisan migrate --force`
- start: `sh ./railway/start-app.sh`
- healthcheck: `/up`

Variáveis obrigatórias no serviço da aplicação:

```text
APP_NAME="Deming"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_URL=https://seu-app.up.railway.app
APP_TIMEZONE=America/Sao_Paulo
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=pt_BR
APP_FAKER_LOCALE=pt_BR
LOG_CHANNEL=stderr
DB_CONNECTION=pgsql
DB_URL=${{Postgres.DATABASE_URL}}
DB_SSLMODE=require
SESSION_DRIVER=database
CACHE_STORE=database
FILESYSTEM_DISK=public
RAILPACK_PHP_VERSION=8.4
```

Para testes simples, pode usar:

```text
QUEUE_CONNECTION=sync
```

Nesse modo, o job APS é disparado após a resposta HTTP no próprio serviço web. Funciona para teste, mas pode deixar uploads mais lentos.

Para processamento APS realmente em segundo plano, use:

```text
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=900
```

E crie um segundo serviço no Railway, apontando para o mesmo repositório, com start command:

```bash
sh ./railway/start-worker.sh
```

Esse worker precisa usar as mesmas variáveis do serviço web, principalmente `DB_URL`, `APP_KEY`, `APP_URL`, `AUTODESK_APS_*` e `MAIL_*`.

O `DB_QUEUE_RETRY_AFTER` deve ser maior que o timeout do worker (`600` segundos em `railway/start-worker.sh`). Isso evita que um upload/conversão APS demorado seja colocado de volta na fila antes do job terminar.

## Railway: Uploads e Arquivos

Para uploads persistirem entre deploys, crie um Railway Volume no serviço da aplicação com mount path:

```text
/app/storage/app/public
```

O `railway/start-app.sh` já sobe o PHP com:

```text
upload_max_filesize=100M
post_max_size=128M
memory_limit=512M
max_execution_time=0
max_input_time=0
```

## Railway: Autodesk APS

Configure no serviço web e no serviço worker, se existir:

```text
AUTODESK_APS_CLIENT_ID=...
AUTODESK_APS_CLIENT_SECRET=...
AUTODESK_APS_BUCKET_KEY=...
AUTODESK_APS_REGION=US
AUTODESK_APS_STORAGE_LIMIT_BYTES=5368709120
AUTODESK_APS_SCOPES="data:read data:write data:create bucket:create bucket:read viewables:read"
AUTODESK_APS_VERIFY_SSL=true
AUTODESK_APS_AUTO_PROCESS=true
```

## GED: OCR de documentos

### Worker local do GED/OCR

Para testar o OCR localmente, mantenha o Laravel e o Vite rodando conforme a seção "Rodando Localmente" e abra um terminal separado para o worker:

```bash
php artisan queue:work database --queue=ged --sleep=3 --tries=1 --timeout=3600
```

Se esse worker não estiver ativo, documentos enviados ao GED podem ficar como "Na fila" ou "Processando OCR" sem extrair o texto.

O módulo de Documentação usa uma arquitetura inspirada no Paperless-ngx: ao enviar um documento, ele entra na fila `ged` e o OCR roda em segundo plano com `OCRmyPDF`/`Tesseract`.

Variáveis principais:

```text
GED_OCR_ENABLED=true
GED_OCR_QUEUE=ged
GED_DOCUMENT_DISK=public
GED_OCR_LANGUAGE=por+eng
GED_OCR_MODE=skip
GED_OCR_OUTPUT_TYPE=pdfa
GED_OCR_DESKEW=true
GED_OCR_ROTATE_PAGES=true
GED_OCR_MAX_PAGES=25
GED_OCR_TIMEOUT=300
GED_OCR_OCRMYPDF_BIN=ocrmypdf
GED_OCR_PDFTOTEXT_BIN=pdftotext
GED_OCR_PDFINFO_BIN=pdfinfo
```

Dependências esperadas no servidor:

```bash
ocrmypdf
tesseract
tesseract-ocr-por
poppler-utils
ghostscript
```

No Railway, essas dependências ficam no mesmo serviço da aplicação enquanto usamos volume local. O `railpack.json` instala os pacotes de OCR no container principal.

O worker OCR roda como processo separado dentro do mesmo serviço e consome apenas a fila `ged`:

```bash
--queue=ged
```

Checklist quando um projeto demora:

1. Verifique se `AUTODESK_APS_*` está configurado no serviço web e no worker.
2. Verifique se `QUEUE_CONNECTION=database` tem um worker rodando.
3. Abra o painel Super Admin de uso APS ou rode o diagnóstico local equivalente.
4. Se o manifesto estiver em `99% complete`, aguarde a Autodesk finalizar.
5. Se o manifesto retornar `failed`, o arquivo precisa ser reenviado ou convertido para outro formato suportado.

## Railway: Email Brevo por API HTTPS

Para email real:

```text
MAIL_MAILER=brevo
BREVO_API_KEY=xkeysib-sua-chave-api-v3
MAIL_FROM_ADDRESS=email-verificado-no-brevo@seudominio.com
MAIL_FROM_NAME=Deming
```

Use uma chave de API v3 da Brevo, não a chave SMTP. O `MAIL_FROM_ADDRESS`
precisa ser um remetente verificado no Brevo. A API usa HTTPS na porta 443 e
funciona sem liberar as portas SMTP 587, 465 ou 2525. Se `MAIL_MAILER=log`, a
aplicação registra os emails no log e não envia mensagens reais.

## Assinatura Digital do RDO

O RDO usa uma camada de assinatura desacoplada. A aplicação gera o PDF final,
cria uma solicitação em `rdo_signature_requests`, registra os signatários em
`rdo_signature_signers` e entrega o documento para o provedor configurado.

Para homologar o fluxo sem chamar API externa:

```text
SIGNATURE_DRIVER=local
```

Para usar OpenSign via API:

```text
SIGNATURE_DRIVER=opensign
OPENSIGN_BASE_URL=https://sandbox.opensignlabs.com/api/v1.2
OPENSIGN_API_KEY=sua-chave-api
OPENSIGN_CREATE_REQUEST_PATH=/createdocument
OPENSIGN_WEBHOOK_SECRET=um-segredo-forte
OPENSIGN_VERIFY_SSL=true
OPENSIGN_TIME_TO_COMPLETE_DAYS=15
```

Webhook que deve ser configurado no OpenSign:

```text
https://deming.com.br/api/webhooks/opensign?secret=um-segredo-forte
```

Em homologação, use a URL da aplicação de homologação. Em produção, use
`https://deming.com.br`. O `OPENSIGN_CREATE_REQUEST_PATH` ficou em variável
porque a API do provedor pode mudar entre OpenSign self-hosted/cloud. Se no
futuro trocarmos para DocuSign, Dropbox Sign ou outro serviço, crie um novo
provider implementando `App\Services\Signatures\SignatureProviderInterface` e
altere apenas o driver/configuração, mantendo o fluxo do RDO intacto.

## Variáveis Opcionais

```text
VITE_MAPBOX_ACCESS_TOKEN=...
RAILWAY_RUN_SEEDER=false
```

## Railway: OCR no mesmo serviço com processo dedicado

Enquanto o GED usa volume local (`GED_DOCUMENT_DISK=public`), o OCR deve rodar no mesmo serviço Railway da aplicação para enxergar os arquivos enviados. O `railway/start-app.sh` inicia o servidor Laravel, o agendador, o worker geral (`imports,default,maintenance`) e o worker OCR dedicado (`ged`).

O `railpack.json` continua instalando os pacotes de OCR no container principal enquanto usarmos volume local.

Esse desenho separa a fila pesada do OCR sem exigir S3/R2 agora.

Quando migrarmos os documentos para storage compartilhado, como S3/R2, o worker OCR poderá virar um serviço Railway independente e o build do web app poderá voltar a ser mais leve.
