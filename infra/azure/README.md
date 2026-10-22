# Deming on Azure

This directory defines the Azure development environment. It is intentionally
conservative: resources use low-cost SKUs, Container Apps use the Consumption
plan, and PostgreSQL stays within the Azure Free Account limits.

## Initial validation

```powershell
az deployment sub what-if `
  --location brazilsouth `
  --template-file infra/azure/main.bicep `
  --parameters infra/azure/dev.bicepparam
```

## First deployment

The first deployment creates only:

- the tagged development resource group;
- a Standard LRS storage account with private containers;
- a Log Analytics workspace with a 0.05 GB/day ingestion safeguard;
- a Consumption Azure Container Apps environment.
- a monthly budget of 1 in the subscription billing currency, with email alerts
  (BRL for the current subscription). This is an alert, not a spending cap.

It does not create a running container application until `deployApplication`
is explicitly enabled by the deployment workflow.

```powershell
az deployment sub create `
  --name deming-dev-core `
  --location brazilsouth `
  --template-file infra/azure/main.bicep `
  --parameters infra/azure/dev.bicepparam
```

## PostgreSQL

The development parameters enable one `Standard_B1ms` PostgreSQL instance with
32 GB of storage. The password is passed as a secure deployment parameter and
must never be stored in this folder. Disable `deployPostgres` before deploying
into a subscription without the corresponding free-service allowance.

## Application deployment

`.github/workflows/azure-development.yml` is prepared for manual dispatch only.
It builds the application image in GHCR, authenticates to Azure with OIDC,
deploys the Container App, checks `/up`, and deactivates active development
revisions on completion (including failure after Azure login). Publishing this
workflow is separate from local preparation. It must be registered in the
default branch before GitHub can offer manual dispatch; do not deploy production
or merge unrelated work just to register it.

The OIDC credential is restricted to:

- repository `EderDeev/SaaS-Obra`;
- branch `staging`;
- resource group `rg-deming-dev`.

Required GitHub Actions variables:

- `AZURE_CLIENT_ID`;
- `AZURE_TENANT_ID`;
- `AZURE_SUBSCRIPTION_ID`;
- `AZURE_RESOURCE_GROUP`;
- `AZURE_CONTAINER_APP`.

Required GitHub Actions secrets:

- `AZURE_APP_KEY`;
- `AZURE_POSTGRES_ADMIN_PASSWORD`;
- `AZURE_STORAGE_CONNECTION_STRING`;
- `VITE_MAPBOX_ACCESS_TOKEN` only when maps must be enabled in development.

The development application uses Blob Storage for GED and project documents,
keeps external e-mail and Autodesk APS processing disabled initially, and runs
with zero minimum and one maximum replica.

## Cost controls

- Keep the monthly budget alerts at 50%, 80%, and 100% enabled.
- Keep Container Apps minimum replicas at zero and maximum replicas at one.
- Keep PostgreSQL storage auto-growth disabled in development.
- Keep geo-redundant backup and high availability disabled.
- Keep Log Analytics below its configured daily cap.
- The daily log cap can overshoot and interrupts collection when reached; it is
  a backup safeguard, not an exact billing limit.
- Do not deploy Redis, Front Door, Key Vault, or Container Registry in dev.
- Use GitHub Container Registry for development images.

## Free allowances verified on 2026-10-19

The subscription portal lists 12-month benefits expiring on **2027-09-25**.
The October usage table currently reports zero, but warns that the most recent
24 hours may be missing. Zero reported usage is not proof of zero consumption.

After reactivation propagated, the workspace update succeeded. Read-back confirms
**0.05 GB/day** and 30 days of retention. PostgreSQL returned to `Ready`. Container
Apps initially returned `ManagedClusterSuspended`; a bounded session was later
provisioned and its new revision reached `Healthy`.

| Meter | Monthly allowance shown in the subscription |
| --- | --- |
| PostgreSQL Flexible Server B1ms compute | 750 hours |
| PostgreSQL provisioned storage | 32 GB-month |
| PostgreSQL LRS backup storage | 32 GB-month |
| Blob Hot LRS storage | 5 GB-month |
| Blob Hot reads | 20,000 operations |
| Blob Hot LRS writes | 10,000 operations |
| Blob LRS list/create-container operations | 20,000 operations |
| Networking data transfer out | 15 GB |

These are subscription allowances, not per-resource quotas. Include retained
soft-deleted blobs, temporary files and other resources consuming the same
allowance when planning capacity. Do not delete files or backups to reduce usage
without following the repository's data safety rules.

Container Apps Consumption has separate monthly grants of 180,000 vCPU-seconds,
360,000 GiB-seconds and 2 million HTTP requests per subscription. At the current
1 vCPU / 2 GiB allocation, the compute grants correspond to **50 replica-hours**
per month. This is aggregate running replica time, including startup, background
processing and scale-down delay, not time spent by a person using the website.
Zero minimum and one maximum replica do not enforce a monthly usage ceiling.

For development planning, reserve at least 30% of each grant. A target of at most
35 reserved replica-hours, 3.5 GB-month of Blob storage, 14,000 reads and 7,000
writes leaves room below the grants. Blob, network, request and backup targets
remain planning margins, **not automatically enforced application quotas**.

## On-demand development sessions

From the repository directory, with Python 3 and an authenticated Azure CLI:

```powershell
python scripts/azure-dev-session.py status
python scripts/azure-dev-session.py start --minutes 60
python scripts/azure-dev-session.py stop
```

`start` accepts 1 to 60 minutes. It checks the hardcoded development environment,
1 vCPU / 2 GiB allocation, single revision mode, absence of other apps/jobs sharing
the grant, and conservative monthly replica metrics. Missing metrics block the
session; only explicit zero aggregate metrics can substitute absent dimensional
series. It reports the resolved environment, driver, host and database without
credentials before changing the runtime.

Reservations are serialized with a 60-second Blob lease and stored in the
existing private container at `_cost-control/sessions-YYYY-MM.json`. Every session
reserves its full duration plus 15 minutes before activation, with a total limit
of 2,100 minutes. Failed or shortened sessions do not refund time. Observed usage
can raise the ledger balance. An active reservation blocks concurrent sessions.

The session replaces HTTP scaling with a cron window for the selected UTC date.
Normal completion deactivates all active revisions and checks the result; the
cloud cron window is a backup if the local process terminates. Cron has no year
field and can recur annually if cleanup fails. Run `stop` to deactivate revisions
after any interrupted session. Do not activate revisions or deploy outside this
controller: those operations can bypass the reservation. Session transitions may
briefly run overlapping replicas during Azure's single-revision rollout.

The controller disables startup migrations for its new revision. It never runs
migrations, seeders or data cleanup. Normal operations can still write application
data through workers and the scheduler. Files remaining on local container disks
are not made persistent by this controller.

New sessions are blocked from 2027-09-25 pending benefit revalidation. That block
does **not** remove PostgreSQL or Blob storage or stop their possible billing
after benefits expire. Review all retained resources before expiration. Stopping
PostgreSQL alone does not eliminate storage charges.

The scripts and workflow changes are local until committed/published. A GitHub
cron or a Codex monitor is not installed by these changes. The controller enforces
admission of its own sessions, not an Azure-wide hard billing cap.

Policy tests use Python mocks only, without Azure calls or application databases:

```powershell
python scripts/test_azure_dev_session.py
```

The existing alert budget is BRL 1, with notifications above BRL 0.50, 0.80 and
1.00. Budget data is delayed and alerts do not stop resources. Pay-as-you-go does
not provide a configurable zero-cost spending limit. Do not describe this
environment as guaranteed free, or migrate production on that assumption.

References:

- https://learn.microsoft.com/azure/cost-management-billing/manage/check-free-service-usage
- https://learn.microsoft.com/azure/container-apps/billing
- https://learn.microsoft.com/azure/cost-management-billing/costs/tutorial-acm-create-budgets
- https://learn.microsoft.com/azure/azure-monitor/logs/daily-cap
- https://learn.microsoft.com/azure/cost-management-billing/manage/spending-limit
