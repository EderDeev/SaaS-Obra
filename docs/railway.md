# Deploy de teste no Railway

Este projeto pode subir no Railway como um unico servico:

- Laravel serve o backend.
- React/Inertia e compilado pelo Vite em `public/build`.
- PostgreSQL do Railway fica como banco remoto.
- Queue fica em `sync` para simplificar o teste.

## Arquivos de apoio

- `railway.toml`: configura build, pre-deploy e healthcheck.
- `railway/init-app.sh`: roda migrations e, opcionalmente, seed.
- `.env.railway.example`: lista das variaveis para copiar no Railway.

## Passo a passo

1. Crie um projeto no Railway e conecte este repositorio.
2. Adicione um banco PostgreSQL no mesmo projeto.
3. No servico da aplicacao, copie as variaveis de `.env.railway.example`.
4. Gere um `APP_KEY` localmente e cole no Railway:

```bash
php artisan key:generate --show
```

5. Ajuste `APP_URL` para a URL publica do Railway.
6. Configure `DB_URL=${{Postgres.DATABASE_URL}}`.
7. Configure `RAILPACK_PHP_ROOT_DIR=/app/public`.
8. No primeiro deploy de teste, se quiser criar usuarios demo, use:

```env
RAILWAY_RUN_SEEDER=true
```

Depois do primeiro deploy, volte para:

```env
RAILWAY_RUN_SEEDER=false
```

## Observacoes

Uploads locais funcionam para teste, mas o armazenamento do Railway e efemero. Para nao perder arquivos em redeploy/restart, use um Volume do Railway ou S3/Spaces antes de uso real.

Se alterar variaveis `VITE_*`, faca redeploy porque elas entram no build do frontend.

Para emails reais, troque `MAIL_MAILER=log` pelas credenciais SMTP da Brevo.
