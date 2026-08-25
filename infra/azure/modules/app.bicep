targetScope = 'resourceGroup'

param location string
param environmentName string
param tags object
param managedEnvironmentName string
param containerImage string
param postgresHost string
param postgresDatabaseName string
param postgresAdminLogin string

@secure()
param postgresAdminPassword string

@secure()
param appKey string

@secure()
param storageConnectionString string

resource managedEnvironment 'Microsoft.App/managedEnvironments@2024-03-01' existing = {
  name: managedEnvironmentName
}

resource application 'Microsoft.App/containerApps@2024-03-01' = {
  name: 'ca-deming-${environmentName}'
  location: location
  tags: tags
  identity: {
    type: 'SystemAssigned'
  }
  properties: {
    managedEnvironmentId: managedEnvironment.id
    configuration: {
      activeRevisionsMode: 'Single'
      ingress: {
        external: true
        allowInsecure: false
        targetPort: 8080
        transport: 'auto'
      }
      secrets: [
        {
          name: 'app-key'
          value: appKey
        }
        {
          name: 'postgres-password'
          value: postgresAdminPassword
        }
        {
          name: 'storage-connection'
          value: storageConnectionString
        }
      ]
    }
    template: {
      containers: [
        {
          name: 'deming'
          image: containerImage
          command: [
            '/bin/sh'
          ]
          args: [
            '/app/azure/start-app.sh'
          ]
          env: [
            { name: 'APP_NAME', value: 'Deming' }
            { name: 'APP_ENV', value: 'staging' }
            { name: 'APP_DEBUG', value: 'false' }
            { name: 'APP_KEY', secretRef: 'app-key' }
            { name: 'APP_URL', value: 'https://ca-deming-${environmentName}.${managedEnvironment.properties.defaultDomain}' }
            { name: 'APP_TIMEZONE', value: 'America/Sao_Paulo' }
            { name: 'APP_LOCALE', value: 'pt_BR' }
            { name: 'APP_FALLBACK_LOCALE', value: 'pt_BR' }
            { name: 'LOG_CHANNEL', value: 'stderr' }
            { name: 'LOG_LEVEL', value: 'info' }
            { name: 'DB_CONNECTION', value: 'pgsql' }
            { name: 'DB_HOST', value: postgresHost }
            { name: 'DB_PORT', value: '5432' }
            { name: 'DB_DATABASE', value: postgresDatabaseName }
            { name: 'DB_USERNAME', value: postgresAdminLogin }
            { name: 'DB_PASSWORD', secretRef: 'postgres-password' }
            { name: 'DB_SSLMODE', value: 'require' }
            { name: 'DB_PROTECT_DESTRUCTIVE', value: 'true' }
            { name: 'SESSION_DRIVER', value: 'database' }
            { name: 'CACHE_STORE', value: 'database' }
            { name: 'QUEUE_CONNECTION', value: 'database' }
            { name: 'DB_QUEUE_RETRY_AFTER', value: '3900' }
            { name: 'FILESYSTEM_DISK', value: 'local' }
            { name: 'GED_DOCUMENT_DISK', value: 'azure-private' }
            { name: 'PROJECT_DOCUMENT_DISK', value: 'azure-private' }
            { name: 'AZURE_STORAGE_CONNECTION_STRING', secretRef: 'storage-connection' }
            { name: 'AZURE_STORAGE_CONTAINER', value: 'deming-private' }
            { name: 'AZURE_STORAGE_TEMPORARY_CONTAINER', value: 'deming-temporary' }
            { name: 'MAIL_MAILER', value: 'log' }
            { name: 'AUTODESK_APS_AUTO_PROCESS', value: 'false' }
            { name: 'GED_OCR_ENABLED', value: 'true' }
            { name: 'GED_OCR_QUEUE', value: 'ged' }
            { name: 'GED_OCR_LANGUAGE', value: 'por+eng' }
            { name: 'GED_OCR_TIMEOUT', value: '900' }
            { name: 'AZURE_RUN_MIGRATIONS', value: 'true' }
            { name: 'PORT', value: '8080' }
          ]
          resources: {
            cpu: json('1.0')
            memory: '2Gi'
          }
          probes: [
            {
              type: 'Startup'
              httpGet: {
                path: '/up'
                port: 8080
                scheme: 'HTTP'
              }
              initialDelaySeconds: 5
              periodSeconds: 5
              timeoutSeconds: 5
              failureThreshold: 30
            }
            {
              type: 'Liveness'
              httpGet: {
                path: '/up'
                port: 8080
                scheme: 'HTTP'
              }
              initialDelaySeconds: 30
              periodSeconds: 30
              timeoutSeconds: 5
              failureThreshold: 3
            }
          ]
        }
      ]
      scale: {
        minReplicas: 0
        maxReplicas: 1
        rules: [
          {
            name: 'http-requests'
            http: {
              metadata: {
                concurrentRequests: '20'
              }
            }
          }
        ]
      }
    }
  }
}

output name string = application.name
output fqdn string = application.properties.configuration.ingress.fqdn
output url string = 'https://${application.properties.configuration.ingress.fqdn}'
output principalId string = application.identity.principalId
