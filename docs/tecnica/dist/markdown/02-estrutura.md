# 02 · Pastas e arquivos importantes

## Workspace

| Pasta | Responsabilidade |
| --- | --- |
| `obras-saas/` | Backend Laravel, frontend web, migrations, testes e infraestrutura principal |
| `sigworks-mobile/` | Aplicativo SigWorks em Expo/React Native |
| `paperless-ngx-reference/` | Material de referência separado; não é o ponto de entrada do SaaS |
| `entregas/` | Artefatos de entrega do workspace; não é fonte de regras da aplicação |

## Projeto web/backend

| Caminho | O que contém e quando consultar |
| --- | --- |
| [app/Http/Controllers](../../app/Http/Controllers) | Endpoints web/API: validação, autorização e coordenação da operação |
| [app/Http/Middleware](../../app/Http/Middleware) | Acesso à plataforma, tenant, módulos, tokens mobile e dados compartilhados Inertia |
| [app/Models](../../app/Models) | Entidades Eloquent, relações, casts, eventos de criação e soft delete |
| [app/Support](../../app/Support) | Permissões, escopos, definição de fluxos, campos de modelos e cálculos de domínio |
| [app/Services](../../app/Services) | Geração diária/PDF, assinaturas, armazenamento, OCR, APS e assistente |
| [app/Jobs](../../app/Jobs) | Trabalho assíncrono processado pelos workers |
| [app/Notifications](../../app/Notifications) | Mensagens de fluxo, acesso e assinatura; canais e enfileiramento |
| [app/Console/Commands](../../app/Console/Commands) | Comandos operacionais e utilitários, incluindo simulações locais |
| [app/Providers/AppServiceProvider.php](../../app/Providers/AppServiceProvider.php) | Proteção de comandos destrutivos, transporte Brevo, HTTPS e configuração global |
| [bootstrap/app.php](../../bootstrap/app.php) | Bootstrap da aplicação e aliases de middleware |
| [routes/web.php](../../routes/web.php) | Rotas da plataforma, tenants e módulos web |
| [routes/api.php](../../routes/api.php) | API mobile e webhook de assinatura |
| [routes/auth.php](../../routes/auth.php) | Fluxos de autenticação web |
| [routes/console.php](../../routes/console.php) | Agendamentos e comandos de console registrados por closure |
| [config](../../config) | Configuração declarativa; nomes das variáveis e defaults |
| [database/migrations](../../database/migrations) | Histórico de criação/alteração do esquema e eventuais ajustes de dados |
| [database/factories](../../database/factories) | Construção de entidades para testes |
| [database/seeders](../../database/seeders) | Carga de dados; nunca presumir que pode ser executada em uma base existente |
| [resources/js/Pages](../../resources/js/Pages) | Páginas Inertia por área e módulo |
| [resources/js/Components](../../resources/js/Components) | Componentes compartilhados, tours e controles |
| [resources/js/Layouts](../../resources/js/Layouts) | Estrutura global de navegação e autenticação |
| [resources/css](../../resources/css) | Estilos e tokens globais |
| [resources/views/pdf](../../resources/views/pdf) | Templates Blade dos PDFs e visualizador HTML |
| [resources/fixtures](../../resources/fixtures) | Dados usados por prévias; não substituir dados reais por fixtures |
| [public/brand](../../public/brand) | Marcas e ícones usados pela aplicação |
| `public/build/` | Saída compilada do Vite; deve corresponder ao código da versão entregue |
| `storage/` | Logs, cache, sessões e arquivos; separar dados persistentes de conteúdo regenerável |
| [tests](../../tests) | Testes do backend e da interface |
| [railway](../../railway) | Inicialização e migração usadas na configuração Railway |
| [infra](../../infra) e [azure](../../azure) | Arquivos de infraestrutura e alternativas de hospedagem |
| [.github/workflows](../../.github/workflows) | CI e verificações relacionadas à entrega |
| [docs](../../docs) | Manuais, procedimentos e este portal técnico |
| `tmp/`, `output/`, `tools/`, `scripts/` | Apoio, diagnósticos, artefatos e automações; verificar cada arquivo antes de executar |
| `vendor/`, `node_modules/` | Dependências instaladas; mudanças funcionais devem ir ao código ou configuração do projeto |

## Arquivos de entrada e configuração

| Arquivo | Importância |
| --- | --- |
| [composer.json](../../composer.json) / [composer.lock](../../composer.lock) | Requisitos PHP, scripts e versões resolvidas |
| [package.json](../../package.json) / [package-lock.json](../../package-lock.json) | Dependências e scripts da interface |
| [resources/js/app.jsx](../../resources/js/app.jsx) | Inicialização Inertia/React e resolução de páginas |
| [resources/js/Layouts/AuthenticatedLayout.jsx](../../resources/js/Layouts/AuthenticatedLayout.jsx) | Menu, contexto de navegação e integração com notificações |
| [app/Http/Middleware/HandleInertiaRequests.php](../../app/Http/Middleware/HandleInertiaRequests.php) | Props compartilhadas, usuário, tenant e capacidades do menu |
| [phpunit.xml](../../phpunit.xml) / [tests/bootstrap.php](../../tests/bootstrap.php) | Ambiente isolado dos testes |
| [docker-compose.local.yml](../../docker-compose.local.yml) | Contêiner local e conexão com Postgres existente |
| [Dockerfile](../../Dockerfile) | Build da imagem e ferramentas do sistema |
| [railway.toml](../../railway.toml) / [railpack.json](../../railpack.json) | Build e inicialização declarados para Railway |
| [AGENTS.md](../../AGENTS.md) | Regras obrigatórias de segurança do banco para automações |

## Encontrar a origem de uma tela

Procure o nome da rota em `routes/web.php`; siga o controller e o `Inertia::render`; abra a página React indicada. Para um campo persistido, acompanhe o payload do formulário, a validação, a atribuição ao model e a migration. Um campo visível pode ser calculado, pertencer a JSON ou estar em outra tabela: não conclua que ele tem coluna própria apenas pelo nome na interface.

Use `rg` para pesquisa textual e `rg --files` para localizar arquivos. Exemplo:

```powershell
rg -n 'Tenant/Rdo/Calendar|function calendar' app resources/js
rg -n 'workflow_snapshot|pdf_configuration_snapshot' app database/migrations
```

O [inventário de arquivos](inventario-arquivos.md) complementa este mapa com a lista das classes e páginas encontradas na revisão.
