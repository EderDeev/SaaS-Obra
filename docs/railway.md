# Deploy de teste no Railway

Este projeto pode subir no Railway como um unico servico:

- Laravel serve o backend.
- React/Inertia e compilado pelo Vite em `public/build`.
- PostgreSQL do Railway fica como banco remoto.
- Queue fica em `sync` para simplificar o teste.

## Arquivos de apoio

- `railway.toml`: configura build, pre-deploy e healthcheck.
- `startCommand`: inicia o Laravel em `0.0.0.0` usando a variavel `${PORT}` do Railway.
- `healthcheckPath`: usa `/up`, uma rota leve do Laravel que nao depende de login ou sessao.
- `railway/init-app.sh`: roda migrations e, opcionalmente, seed.
- `railway/start-app.sh`: prepara o storage persistente e inicia o servidor web.
- `.env.railway.example`: lista das variaveis para copiar no Railway.

## Passo a passo

1. Crie um projeto no Railway e conecte este repositorio.
2. Adicione um banco PostgreSQL no mesmo projeto.
3. No servico da aplicacao, copie as variaveis de `.env.railway.example`.
4. Gere um `APP_KEY` localmente e cole no Railway:

```bash
php artisan key:generate --show
```

5. Ajuste `APP_URL` para a URL publica do Railway, sempre com `https://`.
   - Exemplo: `APP_URL=https://saas-obra-production.up.railway.app`.
   - Tambem ajuste `APP_NAME=Deming` para o titulo da aba nao aparecer como Laravel.
6. Configure `DB_URL=${{Postgres.DATABASE_URL}}`.
   - Esta variavel precisa ficar no servico da aplicacao, nao apenas no servico do banco.
   - Se o banco tiver outro nome no Railway, use a referencia do proprio painel, por exemplo `${{NomeDoBanco.DATABASE_URL}}`.
   - Se preferir usar `DATABASE_URL` direto, o app tambem faz fallback para ela.
   - Mantenha `DB_PROTECT_DESTRUCTIVE=true`. Essa trava bloqueia comandos que
     apagam ou revertem o banco, mesmo quando executados com `--force`.
   - Em producao, tambem ficam bloqueados `artisan test`, `artisan db` e
     `artisan tinker`, evitando que testes ou sessoes interativas alcancem o banco.
7. Configure `RAILPACK_PHP_ROOT_DIR=/app/public`.
8. Garanta PHP 8.4 no build. O projeto ja exige `php: ^8.4` no `composer.json`; se precisar, adicione tambem:

```env
RAILPACK_PHP_VERSION=8.4
```
9. Mantenha o seeder desativado. A aplicacao bloqueia `db:seed` em producao,
   inclusive com `--force`:

```env
RAILWAY_RUN_SEEDER=false
```

## Volume persistente para uploads

Use volume para persistir arquivos enviados pelo sistema, como fotos de perfil, logos de empresas, anexos de RNC e projetos.

1. No canvas do projeto Railway, crie um novo Volume pelo menu do projeto ou Command Palette.
2. Conecte o volume ao servico da aplicacao, nao ao PostgreSQL.
3. Configure o mount path como:

```text
/app/storage/app/public
```

4. Mantenha no servico da aplicacao:

```env
FILESYSTEM_DISK=public
```

O Railway injeta automaticamente `RAILWAY_VOLUME_MOUNT_PATH` em runtime. O script `railway/start-app.sh` cria os diretorios necessarios e roda `php artisan storage:link` quando o container inicia.

Evite montar o volume em `/app/storage` neste primeiro momento, porque isso cobre tambem diretorios internos do Laravel, como cache, sessoes, views compiladas e logs. O caminho `/app/storage/app/public` persiste apenas os uploads publicos do sistema.

## Observacoes

Sem volume ou S3, uploads locais funcionam para teste, mas o armazenamento do Railway e efemero e pode sumir em redeploy/restart.

Se alterar variaveis `VITE_*`, faca redeploy porque elas entram no build do frontend.

Para emails reais, use a API HTTPS da Brevo:

```text
MAIL_MAILER=brevo
BREVO_API_KEY=xkeysib-sua-chave-api-v3
MAIL_FROM_ADDRESS=email-verificado-no-brevo@seudominio.com
MAIL_FROM_NAME=Deming
```

A chave deve ser uma chave de API v3, não uma chave SMTP. O remetente precisa
estar verificado na Brevo.
