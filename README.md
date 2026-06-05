# Deming

SaaS multi-tenant para gerenciamento de obras, baseado no escopo do projeto:

- Laravel 13 + Inertia.js + React + Tailwind CSS
- Usuários globais com vínculos por empresa e por contrato
- Painel Super Admin
- Painel Tenant Admin
- Espaço do contrato com participantes da gerenciadora, cliente e construtora

## Rodando localmente

Instalação inicial:

```bash
composer install
npm install
php artisan key:generate
php artisan migrate:fresh --seed
npm run build
```

Para subir a aplicação em desenvolvimento, use dois terminais.

Terminal 1, Laravel:

```bash
php -d upload_max_filesize=100M -d post_max_size=128M -d memory_limit=512M -d max_execution_time=0 -d max_input_time=0 -S 127.0.0.1:8000 server.php
```

Terminal 2, Vite:

```bash
npm run dev -- --host 127.0.0.1 --port 5174 --strictPort
```

Terminal 3, fila de jobs:

```bash
php artisan queue:work --sleep=3 --tries=1 --timeout=600
```

Esse worker e necessario quando `QUEUE_CONNECTION=database`. Ele processa tarefas em segundo plano, incluindo o envio automatico de projetos para o Autodesk APS apos a submissao.

Acesse `http://127.0.0.1:8000`.

Se o Vite ficar preso em outra porta, apague `public/hot` e suba novamente o comando do Terminal 2.

## Acessos demo

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

## Fluxos já implementados

- Super Admin em `/admin`
- Criação e listagem de empresas em `/admin/tenants`
- Dashboard da empresa em `/t/{slug}`
- Gestão de usuários internos em `/t/{slug}/users`
- Criação e listagem de contratos em `/t/{slug}/contracts`
- Espaço do contrato em `/t/{slug}/contracts/{id}`
- Participantes por contrato: gerenciadora, cliente e construtora
- Middleware de resolução de tenant por rota local e preparado para subdomínio via `APP_ROOT_DOMAIN`
- Isolamento básico por tenant e por contrato no backend
- Testes de isolamento para participante externo

## Observação sobre Postgres e RLS

Os testes automatizados usam SQLite em memoria para rapidez, mas o deploy do Railway deve usar PostgreSQL. O middleware ja seta `app.current_tenant` quando a conexao for PostgreSQL. A proxima etapa de hardening e adicionar as policies RLS completas para as tabelas de dominio antes de producao real.

## Deploy no Railway

O projeto ja inclui `railway.toml` com:

- build: `npm run build`
- pre-deploy: `php artisan migrate --force`
- start: `sh ./railway/start-app.sh`
- healthcheck: `/up`

Variaveis obrigatorias no servico da aplicacao:

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
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=public
RAILPACK_PHP_VERSION=8.4
```

Para uploads persistirem entre deploys, crie um Railway Volume no servico da aplicacao com mount path:

```text
/app/storage/app/public
```

Para email real via Brevo, configure no Railway:

```text
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=seu-login-smtp-brevo
MAIL_PASSWORD=sua-chave-smtp-brevo
MAIL_FROM_ADDRESS=email-verificado-no-brevo@seudominio.com
MAIL_FROM_NAME="${APP_NAME}"
```

O `MAIL_FROM_ADDRESS` precisa ser um remetente verificado no Brevo. Se `MAIL_MAILER=log`, a aplicacao registra os emails no log e nao envia mensagens reais.

Servicos opcionais:

```text
VITE_MAPBOX_ACCESS_TOKEN=...
AUTODESK_APS_CLIENT_ID=...
AUTODESK_APS_CLIENT_SECRET=...
AUTODESK_APS_BUCKET_KEY=...
AUTODESK_APS_REGION=US
AUTODESK_APS_AUTO_PROCESS=true
```

Para processamento APS realmente em segundo plano, use `QUEUE_CONNECTION=database` e crie um segundo servico no Railway com start command:

```bash
sh ./railway/start-worker.sh
```
