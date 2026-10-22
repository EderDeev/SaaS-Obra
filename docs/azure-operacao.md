# Operação da homologação Azure

Atualização: 19/10/2026. Modelo escolhido: sob demanda, até 35 horas mensais
reservadas. Produção continua no Railway. Não foi disparado deploy de produção
nem comando de migration, seed ou teste contra PostgreSQL. A revisão criada pelo
controlador desabilita migrations no script de inicialização.

## Aplicado e verificado

- Log Analytics: teto diário de 0,05 GB, retenção de 30 dias.
- PostgreSQL: `Ready`, versão 16, `Standard_B1ms`, 32 GB provisionados;
  crescimento automático, HA e backup geográfico desativados.
- Blob: `deming-private` e `deming-temporary`, sem acesso público.
- Nova revisão de teste `ca-deming-dev--0000001`: `Healthy`, 1 vCPU e 2 GiB,
  escala entre zero e uma réplica, somente trigger cron da sessão.
- `AZURE_RUN_MIGRATIONS=false` na revisão criada pelo controlador.
- Durante a sessão, `/up` e `/login` da homologação responderam HTTP 200.
- Sessão de três minutos encerrou pelo próprio controlador. A listagem de
  revisões ativas retornou `[]` após o encerramento.
- Produção: `https://deming.com.br/up` respondeu HTTP 200.
- Onze testes Python de política aprovados, sem Azure ou banco de dados.
- Template Bicep compilado e `git diff --check` sem erros.

O campo geral `runningStatus=Running` pode continuar aparecendo no recurso
Container App mesmo quando nenhuma revisão está ativa. A verificação de pausa
usa a lista de revisões ativas, não esse campo isolado.

## Abrir e encerrar sessões

Com Python 3 e Azure CLI autenticado, dentro de `obras-saas`:

```powershell
python scripts/azure-dev-session.py status
python scripts/azure-dev-session.py start --minutes 60
python scripts/azure-dev-session.py stop
```

O comando `start` precisa permanecer aberto. Aceita 1 a 60 minutos, reserva a
duração integral mais 15 minutos de margem e recusa ultrapassar 2.100 minutos no
mês UTC. Sessões falhas e mais curtas não devolvem saldo. O histórico fica no
container privado em `_cost-control/sessions-AAAA-MM.json`. Um lease serializa
reservas entre máquinas. O uso observado pelas métricas pode aumentar o saldo
consumido. As reservas são controle operacional, não consumo faturado.

Ao fechar a janela, o processo desativa revisões e verifica a pausa. A regra cron
no Azure é um segundo mecanismo de escala para zero, mesmo se o terminal cair.
Cron não tem campo de ano: se a limpeza falhar, a janela pode se repetir no ano
seguinte; execute `stop` para desativar as revisões após interrupções. A transição
de revisões Single pode manter réplicas antigas e novas brevemente sobrepostas.

O controlador bloqueia alocações diferentes de 1 vCPU e 2 GiB, outros apps/jobs
que compartilhem a franquia, ambiente diferente de `staging` no banco Azure
esperado, métricas desconhecidas, sessões simultâneas e cruzamento mensal.
Também bloqueia novas sessões a partir de 25/09/2027 para revalidar benefícios.
Esse bloqueio não elimina banco nem blobs, cujos custos devem ser revistos antes
da expiração. Não exclua dados ou backups para reduzir consumo sem autorização.

## Preparado localmente

- `scripts/azure-dev-session.py`: controlador de sessões.
- `scripts/test_azure_dev_session.py`: testes de política com mocks.
- `.github/workflows/azure-development.yml`: despacho manual somente em
  `staging`, com pausa no final após login Azure, inclusive em falhas posteriores.
- `infra/azure/modules/core.bicep`: mesmo teto de logs aplicado ao recurso.
- Descrição do orçamento corrigida: moeda da assinatura, atualmente BRL.

Nenhuma dessas alterações de repositório foi enviada ao GitHub nesta operação.
O fluxo Azure precisa estar registrado na branch padrão para aparecer no GitHub;
se publicado futuramente, selecionar `staging` no despacho e validar o CI antes.
O controlador local já pode ser usado com as permissões atuais do Azure CLI.

## Limites que não são bloqueados automaticamente

As metas de Blob (3,5 GB-mês), leituras (14 mil), gravações (7 mil), tráfego
(10,5 GB), backups e requisições continuam exigindo acompanhamento. O controlador
é de admissão das suas próprias sessões de computação e não é um teto geral de
faturamento. Não usar ativações manuais de revisão ou deploys externos para
contorná-lo. O orçamento de R$ 1 apenas alerta, e os dados de custo têm atraso.

O período gratuito exibido no portal termina em 25/09/2027. Serviços externos
como Autodesk, Brevo, Mapbox e OpenAI possuem cobranças e limites separados.
A migração de produção e a persistência de arquivos de todos os módulos ainda
precisam de validação. O guia PDF entregue contém links e funções dos serviços.
