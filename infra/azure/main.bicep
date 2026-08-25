targetScope = 'subscription'

@description('Azure region used by the Deming development environment.')
param location string = 'brazilsouth'

@description('Short environment name used in resource names and tags.')
@allowed([
  'dev'
])
param environmentName string = 'dev'

@description('Resource group that contains the development environment.')
param resourceGroupName string = 'rg-deming-${environmentName}'

@description('Monthly cost budget for the development resource group, in USD.')
param monthlyBudgetAmount int = 1

@description('Email that receives development cost alerts.')
param budgetContactEmail string

@description('Create the PostgreSQL server using the Azure Free Account development limits.')
param deployPostgres bool = false

@description('PostgreSQL administrator username.')
param postgresAdminLogin string = 'demingadmin'

@secure()
@description('PostgreSQL administrator password. Required only when deployPostgres is true.')
param postgresAdminPassword string = ''

@description('Deploy the Laravel application as an Azure Container App.')
param deployApplication bool = false

@description('OCI image used by the Azure development application.')
param containerImage string = ''

@secure()
@description('Laravel APP_KEY used by the Azure development application.')
param appKey string = ''

@secure()
@description('Azure Blob Storage connection string used by Laravel.')
param storageConnectionString string = ''

var commonTags = {
  project: 'deming'
  environment: 'development'
  'cost-control': 'free-tier'
  'managed-by': 'bicep'
}

resource resourceGroup 'Microsoft.Resources/resourceGroups@2024-03-01' = {
  name: resourceGroupName
  location: location
  tags: commonTags
}

resource developmentBudget 'Microsoft.Consumption/budgets@2023-11-01' = {
  name: 'deming-${environmentName}-monthly-cost'
  properties: {
    amount: monthlyBudgetAmount
    category: 'Cost'
    timeGrain: 'Monthly'
    timePeriod: {
      startDate: '2026-08-01T00:00:00Z'
      endDate: '2036-08-01T00:00:00Z'
    }
    filter: {
      dimensions: {
        name: 'ResourceGroupName'
        operator: 'In'
        values: [
          resourceGroupName
        ]
      }
    }
    notifications: {
      Actual_GreaterThan_50_Percent: {
        enabled: true
        operator: 'GreaterThan'
        threshold: 50
        thresholdType: 'Actual'
        contactEmails: [
          budgetContactEmail
        ]
        contactGroups: []
        contactRoles: []
      }
      Actual_GreaterThan_80_Percent: {
        enabled: true
        operator: 'GreaterThan'
        threshold: 80
        thresholdType: 'Actual'
        contactEmails: [
          budgetContactEmail
        ]
        contactGroups: []
        contactRoles: []
      }
      Actual_GreaterThan_100_Percent: {
        enabled: true
        operator: 'GreaterThan'
        threshold: 100
        thresholdType: 'Actual'
        contactEmails: [
          budgetContactEmail
        ]
        contactGroups: []
        contactRoles: []
      }
    }
  }
}

module core './modules/core.bicep' = {
  name: 'deming-${environmentName}-core'
  scope: resourceGroup
  params: {
    location: location
    environmentName: environmentName
    tags: commonTags
    deployPostgres: deployPostgres
    postgresAdminLogin: postgresAdminLogin
    postgresAdminPassword: postgresAdminPassword
  }
}

module application './modules/app.bicep' = if (deployApplication) {
  name: 'deming-${environmentName}-application'
  scope: resourceGroup
  params: {
    location: location
    environmentName: environmentName
    tags: commonTags
    managedEnvironmentName: core.outputs.containerAppsEnvironmentName
    containerImage: containerImage
    postgresHost: core.outputs.postgresHost
    postgresDatabaseName: core.outputs.postgresDatabaseName
    postgresAdminLogin: postgresAdminLogin
    postgresAdminPassword: postgresAdminPassword
    appKey: appKey
    storageConnectionString: storageConnectionString
  }
}

output resourceGroupName string = resourceGroup.name
output storageAccountName string = core.outputs.storageAccountName
output containerAppsEnvironmentName string = core.outputs.containerAppsEnvironmentName
output postgresServerName string = core.outputs.postgresServerName
output postgresHost string = core.outputs.postgresHost
output postgresDatabaseName string = core.outputs.postgresDatabaseName
output applicationName string = deployApplication ? application!.outputs.name : ''
output applicationUrl string = deployApplication ? application!.outputs.url : ''
