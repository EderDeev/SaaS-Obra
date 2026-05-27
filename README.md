# Obras SaaS

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
php -d upload_max_filesize=64M -d post_max_size=64M -d memory_limit=256M -S 127.0.0.1:8000 server.php
```

Terminal 2, Vite:

```bash
npm run dev -- --host 127.0.0.1 --port 5174 --strictPort
```

Acesse `http://127.0.0.1:8000`.

Se o Vite ficar preso em outra porta, apague `public/hot` e suba novamente o comando do Terminal 2.

## Acessos demo

Todos usam a senha `password`.

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

O desenvolvimento local está em SQLite para facilitar a execução imediata. O middleware já seta `app.current_tenant` quando a conexão for PostgreSQL. A próxima etapa de hardening é adicionar as policies RLS completas para as tabelas de domínio antes do deploy em Postgres.
