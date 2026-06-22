$ErrorActionPreference = 'Stop'

$projectRoot = 'C:\Users\Rog_Flow_x13\Documents\SAAS\obras-saas'
$php = 'C:\php-8.5.2\php.exe'
$logPath = Join-Path $projectRoot 'storage\logs\local-server.log'

Set-Location $projectRoot

if (-not (Test-Path $php)) {
    throw "PHP nao encontrado em $php"
}

if (-not (Test-Path (Split-Path $logPath))) {
    New-Item -ItemType Directory -Path (Split-Path $logPath) -Force | Out-Null
}

"[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Iniciando servidor local Deming em http://127.0.0.1:8000" | Out-File -FilePath $logPath -Encoding UTF8 -Append

# O servidor embutido do PHP escreve mensagens normais no stderr. Com
# ErrorActionPreference=Stop, o PowerShell encerraria o processo ao iniciar.
$ErrorActionPreference = 'Continue'
if (Get-Variable -Name PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue) {
    $PSNativeCommandUseErrorActionPreference = $false
}
& $php -d upload_max_filesize=100M -d post_max_size=128M -d memory_limit=512M -d max_execution_time=0 -d max_input_time=0 -S 127.0.0.1:8000 server.php *>> $logPath
