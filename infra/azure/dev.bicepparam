using './main.bicep'

param location = 'brazilsouth'
param environmentName = 'dev'
param resourceGroupName = 'rg-deming-dev'
param monthlyBudgetAmount = 1
param budgetContactEmail = 'eder.dev1@outlook.com'

// Azure Free Account: one Burstable B1ms server with 32 GB for development.
param deployPostgres = true
param deployApplication = false
