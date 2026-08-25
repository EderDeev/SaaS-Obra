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
- a Log Analytics workspace capped at 0.15 GB/day;
- a Consumption Azure Container Apps environment.
- a USD 1 monthly budget for the development resource group, with email alerts.

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

`.github/workflows/azure-development.yml` runs only after a successful CI run
on `staging`, or through an explicit manual dispatch. It builds the application
image in GHCR, authenticates to Azure with OIDC, deploys the Container App, and
checks `/up` before reporting success.

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
- Do not deploy Redis, Front Door, Key Vault, or Container Registry in dev.
- Use GitHub Container Registry for development images.
