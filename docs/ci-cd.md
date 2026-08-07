# CI/CD e homologacao

## Objetivo

Separar validacao tecnica, homologacao e producao sem manter copias diferentes do codigo.

## Ambientes e branches

| Branch | Ambiente | Deploy |
| --- | --- | --- |
| `feature/*` ou `fix/*` | Local | Sem deploy automatico |
| `staging` | Homologacao | Automatico depois do CI |
| `main` | Producao | Automatico depois do CI e da aprovacao |

Fluxo esperado:

1. Criar uma branch curta a partir de `staging`.
2. Abrir Pull Request para `staging`.
3. Aguardar os checks `Application tests and build` e `PostgreSQL migration check`.
4. Validar a alteracao em homologacao.
5. Abrir Pull Request de `staging` para `main`.
6. Publicar somente depois da aprovacao explicita.

## O que o CI valida

O workflow `.github/workflows/ci.yml` executa:

- validacao do `composer.json`;
- instalacao limpa com `composer install` e `npm ci`;
- suite completa do Laravel usando SQLite em memoria;
- build de producao do Vite;
- migrations em um PostgreSQL temporario e descartavel do GitHub Actions.

Nenhum job de CI acessa PostgreSQL, storage ou variaveis do Railway.

## Configuracao do Railway

### 1. Criar a homologacao

No projeto `SaaS Obra`, use `Settings > Environments > New Environment` e duplique `production` com o nome `staging`.

Antes do primeiro deploy, confirme no ambiente `staging`:

- o PostgreSQL pertence ao proprio ambiente `staging`;
- `DB_URL` referencia `${{Postgres.DATABASE_URL}}` do ambiente atual;
- o volume da aplicacao e separado e inicia vazio;
- nenhum dominio de producao esta associado;
- o servico da aplicacao acompanha a branch `staging`.

Nunca cole a URL literal do PostgreSQL de producao em homologacao.

### 2. Variaveis da homologacao

Use os mesmos nomes da producao, mas valores isolados:

```env
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://homolog.deming.com.br
DB_PROTECT_DESTRUCTIVE=true
RAILWAY_RUN_SEEDER=false
MAIL_MAILER=log
AUTODESK_APS_BUCKET_KEY=deming-homologacao
```

Mantenha `MAIL_MAILER=log` no primeiro deploy. Brevo, OpenAI e Autodesk APS so devem ser habilitados depois da validacao, com credenciais e limites proprios quando possivel.

### 3. Autodeploy condicionado ao CI

No servico da aplicacao de cada ambiente:

- `staging`: branch `staging`, autodeploy ativo e `Wait for CI` ativo;
- `production`: branch `main`, autodeploy ativo e `Wait for CI` ativo.

O Railway somente inicia o deploy quando os checks do GitHub terminarem com sucesso.

### 4. Dominios

- Homologacao: `homolog.deming.com.br`.
- Producao: `deming.com.br` e `www.deming.com.br`.

O DNS da homologacao deve apontar exclusivamente para o servico no ambiente `staging`.

## Protecao da branch main

Em `GitHub > Settings > Branches`, proteja `main`:

- exigir Pull Request;
- exigir os dois checks do workflow `CI`;
- bloquear force push;
- bloquear exclusao da branch;
- exigir branch atualizada antes do merge;
- manter aprovacao manual antes de publicar.

Proteja `staging` exigindo os mesmos checks, mas permita o deploy automatico apos o merge.

## Smoke check

O workflow `.github/workflows/post-deploy-smoke.yml` consulta `/up` quando o Railway informa um deploy bem-sucedido. Ele tambem pode ser executado manualmente informando a URL de homologacao ou producao.

O smoke check confirma que a aplicacao iniciou e responde por HTTPS. Testes autenticados e verificacao de banco devem ser adicionados depois que a homologacao permanente estiver operacional.

## Dados de homologacao

- usar tenants, contratos e arquivos sinteticos;
- nao copiar documentos, usuarios ou anexos reais;
- nao usar o bucket APS de producao;
- nao enviar e-mails para destinatarios reais;
- manter backups e volumes completamente separados.

## Rollback

Se o deploy falhar antes do healthcheck, o Railway nao troca a versao ativa. Se uma regressao for encontrada depois:

1. interromper novos merges;
2. usar `Rollback` no deployment anterior do Railway;
3. nao executar rollback de migration automaticamente;
4. criar uma migration corretiva aditiva;
5. registrar o incidente e o commit afetado.
